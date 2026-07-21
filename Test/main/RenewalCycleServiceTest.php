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

use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\Cliente;
use FacturaScripts\Dinamic\Model\Producto;
use FacturaScripts\Plugins\ServiceRenewals\Lib\RenewalCycleService;
use FacturaScripts\Plugins\ServiceRenewals\Lib\RenewalDateCalculator;
use FacturaScripts\Plugins\ServiceRenewals\Model\ServiceRenewal;
use FacturaScripts\Plugins\ServiceRenewals\Model\ServiceRenewalCycle;
use PHPUnit\Framework\TestCase;

/**
 * Tests de la gestión idempotente de ciclos y de la renovación.
 *
 * @author Ernesto Serrano <info@ernesto.es>
 */
final class RenewalCycleServiceTest extends TestCase
{
    /** @var object[] */
    private $cleanup = [];

    protected function tearDown(): void
    {
        // borramos en orden inverso de creación para respetar las claves foráneas
        foreach (array_reverse($this->cleanup) as $model) {
            $model->delete();
        }
        $this->cleanup = [];
    }

    public function testCreatesSingleCycleAndReturnsExistingOnRepeat(): void
    {
        $renewal = $this->makeRenewal('2026-09-15', 12);
        $service = new RenewalCycleService();

        $first = $service->getOrCreate($renewal);
        $this->assertNotNull($first);
        $this->cleanup[] = $first;
        $this->assertSame('2026-09-15', RenewalDateCalculator::toIso($first->previous_expiration_date));
        $this->assertSame('2027-09-15', RenewalDateCalculator::toIso($first->next_expiration_date));
        $this->assertSame(ServiceRenewalCycle::STATUS_PENDING, $first->status);

        // una segunda ejecución devuelve el mismo ciclo
        $second = $service->getOrCreate($renewal);
        $this->assertNotNull($second);
        $this->assertSame((int)$first->id, (int)$second->id);

        $count = ServiceRenewalCycle::count([Where::eq('service_renewal_id', $renewal->id)]);
        $this->assertSame(1, $count, 'Repeated executions must not create duplicated cycles');
    }

    public function testUniqueConstraintRejectsDuplicatedCycle(): void
    {
        $renewal = $this->makeRenewal('2026-09-15', 12);
        $service = new RenewalCycleService();

        $cycle = $service->getOrCreate($renewal);
        $this->assertNotNull($cycle);
        $this->cleanup[] = $cycle;

        $duplicate = new ServiceRenewalCycle();
        $duplicate->service_renewal_id = $renewal->id;
        $duplicate->previous_expiration_date = '2026-09-15';
        $duplicate->next_expiration_date = '2027-09-15';

        $this->assertFalse($duplicate->save(), 'The unique constraint must reject the duplicate');
    }

    public function testApplyRenewalAdvancesDateOnlyOnce(): void
    {
        $renewal = $this->makeRenewal('2026-09-15', 12);
        $service = new RenewalCycleService();

        $cycle = $service->getOrCreate($renewal);
        $this->assertNotNull($cycle);
        $this->cleanup[] = $cycle;
        $cycle->status = ServiceRenewalCycle::STATUS_INVOICED;
        $this->assertTrue($cycle->save());

        $this->assertTrue($service->applyRenewal($cycle));
        $renewal->reload();
        $this->assertSame('2027-09-15', RenewalDateCalculator::toIso($renewal->expiration_date));
        $this->assertSame(ServiceRenewalCycle::STATUS_RENEWED, $cycle->status);
        $this->assertNotEmpty($cycle->renewed_at);

        // repetir no vuelve a sumar el periodo
        $this->assertTrue($service->applyRenewal($cycle));
        $renewal->reload();
        $this->assertSame('2027-09-15', RenewalDateCalculator::toIso($renewal->expiration_date));
    }

    public function testConfirmManualRenewalOnlyFromPendingStatus(): void
    {
        $renewal = $this->makeRenewal('2026-09-15', 6);
        $service = new RenewalCycleService();

        $cycle = $service->getOrCreate($renewal);
        $this->assertNotNull($cycle);
        $this->cleanup[] = $cycle;

        // en estado pending no se puede confirmar
        $this->assertFalse($service->confirmManualRenewal($cycle));
        $renewal->reload();
        $this->assertSame('2026-09-15', RenewalDateCalculator::toIso($renewal->expiration_date));

        // en renewal_pending sí, y una sola vez
        $cycle->status = ServiceRenewalCycle::STATUS_RENEWAL_PENDING;
        $this->assertTrue($cycle->save());
        $this->assertTrue($service->confirmManualRenewal($cycle));
        $renewal->reload();
        $this->assertSame('2027-03-15', RenewalDateCalculator::toIso($renewal->expiration_date));

        $this->assertFalse($service->confirmManualRenewal($cycle), 'A renewed cycle cannot be confirmed again');
        $renewal->reload();
        $this->assertSame('2027-03-15', RenewalDateCalculator::toIso($renewal->expiration_date));
    }

    private function makeRenewal(string $expiration, int $months): ServiceRenewal
    {
        $customer = new Cliente();
        $customer->nombre = 'Cycle Test Customer';
        $customer->cifnif = substr(uniqid('', true), -9);
        $this->assertTrue($customer->save());
        $this->cleanup[] = $customer;

        $product = new Producto();
        $product->referencia = 'SRV-' . substr(uniqid('', true), -8);
        $product->descripcion = 'Cycle test product';
        $product->precio = 10.0;
        $this->assertTrue($product->save());
        $this->cleanup[] = $product;

        $renewal = new ServiceRenewal();
        $renewal->codcustomer = $customer->codcliente;
        $renewal->idproduct = $product->idproducto;
        $renewal->service_identifier = 'cycle-' . substr(uniqid('', true), -8);
        $renewal->expiration_date = $expiration;
        $renewal->period_months = $months;
        $this->assertTrue($renewal->save());
        $this->cleanup[] = $renewal;

        return $renewal;
    }
}
