<?php

/**
 * This file is part of ServiceRenewals plugin for FacturaScripts.
 * Copyright (C) 2026 Ernesto Serrano <info@ernesto.es>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace FacturaScripts\Test\Plugins;

use FacturaScripts\Core\Base\ControllerPermissions;
use FacturaScripts\Core\DataSrc\Empresas;
use FacturaScripts\Dinamic\Model\Cliente;
use FacturaScripts\Dinamic\Model\Producto;
use FacturaScripts\Plugins\ServiceRenewals\Controller\EditServiceRenewal;
use FacturaScripts\Plugins\ServiceRenewals\Lib\RenewalCycleService;
use FacturaScripts\Plugins\ServiceRenewals\Lib\RenewalDateCalculator;
use FacturaScripts\Plugins\ServiceRenewals\Model\ServiceRenewal;
use FacturaScripts\Plugins\ServiceRenewals\Model\ServiceRenewalCycle;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Tests de permisos y token en las acciones del controlador.
 *
 * @author Ernesto Serrano <info@ernesto.es>
 */
final class ControllerPermissionsTest extends TestCase
{
    /** @var object[] */
    private $cleanup = [];

    protected function setUp(): void
    {
        if (empty(Empresas::default()->idempresa)) {
            $this->markTestSkipped('Core default data is not installed');
        }
    }

    protected function tearDown(): void
    {
        // borramos en orden inverso de creación para respetar las claves foráneas
        foreach (array_reverse($this->cleanup) as $model) {
            $model->delete();
        }
        $this->cleanup = [];
        unset($_POST['code'], $_REQUEST['code']);
    }

    public function testUserWithoutPermissionCannotConfirmRenewal(): void
    {
        [$renewal, $cycle] = $this->makePendingRenewal();

        $_POST['code'] = (string)$renewal->id;
        $_REQUEST['code'] = (string)$renewal->id;

        $controller = new EditServiceRenewal('EditServiceRenewal');
        $controller->permissions = new ControllerPermissions();
        $controller->permissions->allowUpdate = false;

        $method = new ReflectionMethod($controller, 'execPreviousAction');
        $method->setAccessible(true);
        $method->invoke($controller, 'confirm-renewal');

        $renewal->reload();
        $cycle->reload();
        $this->assertSame(
            '2026-08-01',
            RenewalDateCalculator::toIso($renewal->expiration_date),
            'Without permission the date must not advance'
        );
        $this->assertSame(ServiceRenewalCycle::STATUS_RENEWAL_PENDING, $cycle->status);
    }

    public function testUserWithoutPermissionCannotGenerateQuotes(): void
    {
        [$renewal, $cycle] = $this->makePendingRenewal();
        $cycle->status = ServiceRenewalCycle::STATUS_PENDING;
        $this->assertTrue($cycle->save());

        $_POST['code'] = (string)$renewal->id;
        $_REQUEST['code'] = (string)$renewal->id;

        $controller = new EditServiceRenewal('EditServiceRenewal');
        $controller->permissions = new ControllerPermissions();
        $controller->permissions->allowUpdate = false;

        $method = new ReflectionMethod($controller, 'execPreviousAction');
        $method->setAccessible(true);
        $method->invoke($controller, 'generate-quote');

        $cycle->reload();
        $this->assertEmpty($cycle->quote_id, 'Without permission no quote may be generated');
    }

    public function testActionWithoutFormTokenIsRejected(): void
    {
        [$renewal, $cycle] = $this->makePendingRenewal();

        $_POST['code'] = (string)$renewal->id;
        $_REQUEST['code'] = (string)$renewal->id;
        // sin token multiRequestProtection en la petición

        $controller = new EditServiceRenewal('EditServiceRenewal');
        $controller->permissions = new ControllerPermissions();
        $controller->permissions->allowUpdate = true;

        $method = new ReflectionMethod($controller, 'execPreviousAction');
        $method->setAccessible(true);
        $method->invoke($controller, 'confirm-renewal');

        $renewal->reload();
        $this->assertSame(
            '2026-08-01',
            RenewalDateCalculator::toIso($renewal->expiration_date),
            'Without a valid token the date must not advance'
        );
    }

    /** @return array{0: ServiceRenewal, 1: ServiceRenewalCycle} */
    private function makePendingRenewal(): array
    {
        $customer = new Cliente();
        $customer->nombre = 'Perm Test Customer ' . substr(uniqid('', true), -4);
        $customer->cifnif = substr(uniqid('', true), -9);
        $this->assertTrue($customer->save());
        $this->cleanup[] = $customer;

        $product = new Producto();
        $product->referencia = 'SRV-' . substr(uniqid('', true), -8);
        $product->descripcion = 'Perm test product';
        $product->precio = 15.0;
        $this->assertTrue($product->save());
        $this->cleanup[] = $product;

        $renewal = new ServiceRenewal();
        $renewal->codcustomer = $customer->codcliente;
        $renewal->idproduct = $product->idproducto;
        $renewal->service_identifier = 'perm-' . substr(uniqid('', true), -8);
        $renewal->expiration_date = '2026-08-01';
        $renewal->period_months = 12;
        $renewal->renewal_trigger = 'manual';
        $this->assertTrue($renewal->save());
        $this->cleanup[] = $renewal;

        $cycle = (new RenewalCycleService())->getOrCreate($renewal);
        $this->assertNotNull($cycle);
        $this->cleanup[] = $cycle;
        $cycle->status = ServiceRenewalCycle::STATUS_RENEWAL_PENDING;
        $this->assertTrue($cycle->save());

        return [$renewal, $cycle];
    }
}
