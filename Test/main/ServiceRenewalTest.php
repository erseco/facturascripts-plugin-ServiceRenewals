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

use FacturaScripts\Dinamic\Model\Cliente;
use FacturaScripts\Dinamic\Model\Producto;
use FacturaScripts\Plugins\ServiceRenewals\Lib\RenewalScanner;
use FacturaScripts\Plugins\ServiceRenewals\Model\ServiceRenewal;
use FacturaScripts\Plugins\ServiceRenewals\Model\ServiceRenewalProfile;
use PHPUnit\Framework\TestCase;

/**
 * Tests de la suscripción y de la detección de vencimientos.
 *
 * @author Ernesto Serrano <info@ernesto.es>
 */
final class ServiceRenewalTest extends TestCase
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

    public function testRequiresCustomerProductIdentifierExpirationAndPeriod(): void
    {
        [$customer, $product] = $this->makeFixtures();

        $renewal = $this->validRenewal($customer, $product);
        $renewal->codcustomer = null;
        $this->assertFalse($renewal->save(), 'customer is required');

        $renewal = $this->validRenewal($customer, $product);
        $renewal->idproduct = null;
        $this->assertFalse($renewal->save(), 'product is required');

        $renewal = $this->validRenewal($customer, $product);
        $renewal->service_identifier = '';
        $this->assertFalse($renewal->save(), 'identifier is required');

        $renewal = $this->validRenewal($customer, $product);
        $renewal->expiration_date = null;
        $this->assertFalse($renewal->save(), 'expiration date is required');

        $renewal = $this->validRenewal($customer, $product);
        $renewal->expiration_date = 'not-a-date';
        $this->assertFalse($renewal->save(), 'expiration date must be valid');

        $renewal = $this->validRenewal($customer, $product);
        $renewal->period_months = 0;
        $this->assertFalse($renewal->save(), 'period must be positive');

        $renewal = $this->validRenewal($customer, $product);
        $renewal->codcustomer = 'NOPE';
        $this->assertFalse($renewal->save(), 'customer must exist');
    }

    public function testValidSubscriptionSaves(): void
    {
        [$customer, $product] = $this->makeFixtures();
        $renewal = $this->validRenewal($customer, $product);

        $this->assertTrue($renewal->save());
        $this->cleanup[] = $renewal;
        $this->assertSame('active', $renewal->status);
    }

    public function testRejectsInvalidEmailOverride(): void
    {
        [$customer, $product] = $this->makeFixtures();
        $renewal = $this->validRenewal($customer, $product);
        $renewal->email_override = 'not-an-email';

        $this->assertFalse($renewal->save());
    }

    public function testSubscriptionOverridesProfileValues(): void
    {
        [$customer, $product] = $this->makeFixtures();

        $profile = new ServiceRenewalProfile();
        $profile->idproduct = $product->idproducto;
        $profile->quote_lead_days = 20;
        $profile->reminder_days = '9,4';
        $profile->auto_generate_quote = true;
        $profile->auto_send_quote = true;
        $profile->renewal_trigger = 'invoice';
        $profile->service_type = 'hosting';
        $this->assertTrue($profile->save());
        $this->cleanup[] = $profile;

        $renewal = $this->validRenewal($customer, $product);
        $this->assertTrue($renewal->save());
        $this->cleanup[] = $renewal;

        // sin valores propios hereda del perfil
        $this->assertSame(20, $renewal->effectiveQuoteLeadDays());
        $this->assertSame([9, 4], $renewal->effectiveReminderDays());
        $this->assertTrue($renewal->effectiveAutoGenerateQuote());
        $this->assertSame('invoice', $renewal->effectiveRenewalTrigger());
        $this->assertSame('hosting', $renewal->effectiveServiceType());

        // los valores propios prevalecen sobre el perfil
        $renewal->quote_lead_days = 5;
        $renewal->reminder_days = '2';
        $renewal->auto_generate_quote = 0;
        $renewal->auto_send_quote = 0;
        $renewal->renewal_trigger = 'manual';
        $this->assertTrue($renewal->save());

        $this->assertSame(5, $renewal->effectiveQuoteLeadDays());
        $this->assertSame([2], $renewal->effectiveReminderDays());
        $this->assertFalse($renewal->effectiveAutoGenerateQuote());
        $this->assertFalse($renewal->effectiveAutoSendQuote());
        $this->assertSame('manual', $renewal->effectiveRenewalTrigger());
    }

    public function testScannerSelectsOnlyDueActiveSubscriptions(): void
    {
        [$customer, $product] = $this->makeFixtures();
        $today = '2026-06-01';

        // fuera del umbral (30 días por defecto)
        $far = $this->validRenewal($customer, $product);
        $far->expiration_date = '2026-12-01';
        $this->assertTrue($far->save());
        $this->cleanup[] = $far;
        $this->assertFalse(RenewalScanner::isDue($far, $today));

        // día exacto del umbral
        $exact = $this->validRenewal($customer, $product);
        $exact->quote_lead_days = 30;
        $exact->expiration_date = '2026-07-01';
        $this->assertTrue($exact->save());
        $this->cleanup[] = $exact;
        $this->assertTrue(RenewalScanner::isDue($exact, $today));

        // vencida
        $expired = $this->validRenewal($customer, $product);
        $expired->expiration_date = '2026-05-01';
        $this->assertTrue($expired->save());
        $this->cleanup[] = $expired;
        $this->assertTrue(RenewalScanner::isDue($expired, $today));

        // suspendida y cancelada no se procesan
        foreach (['suspended', 'cancelled'] as $status) {
            $inactive = $this->validRenewal($customer, $product);
            $inactive->expiration_date = '2026-06-05';
            $inactive->status = $status;
            $this->assertTrue($inactive->save());
            $this->cleanup[] = $inactive;
            $this->assertFalse(RenewalScanner::isDue($inactive, $today));
        }

        // sobrescritura de días de antelación
        $shortLead = $this->validRenewal($customer, $product);
        $shortLead->quote_lead_days = 3;
        $shortLead->expiration_date = '2026-06-10';
        $this->assertTrue($shortLead->save());
        $this->cleanup[] = $shortLead;
        $this->assertFalse(RenewalScanner::isDue($shortLead, $today), '9 days left with 3 lead days is not due');
    }

    /** @return array{0: Cliente, 1: Producto} */
    private function makeFixtures(): array
    {
        $customer = new Cliente();
        $customer->nombre = 'Test Renewal Customer';
        $customer->cifnif = substr(uniqid('', true), -9);
        $customer->email = 'customer@example.com';
        $this->assertTrue($customer->save(), 'Could not create the test customer');
        $this->cleanup[] = $customer;

        $product = new Producto();
        $product->referencia = 'SRV-' . substr(uniqid('', true), -8);
        $product->descripcion = 'Test renewal product';
        $product->precio = 99.0;
        $this->assertTrue($product->save(), 'Could not create the test product');
        $this->cleanup[] = $product;

        return [$customer, $product];
    }

    private function validRenewal(Cliente $customer, Producto $product): ServiceRenewal
    {
        $renewal = new ServiceRenewal();
        $renewal->codcustomer = $customer->codcliente;
        $renewal->idproduct = $product->idproducto;
        $renewal->service_identifier = 'test-' . substr(uniqid('', true), -8) . '.example.com';
        $renewal->expiration_date = '2026-09-15';
        $renewal->period_months = 12;

        return $renewal;
    }
}
