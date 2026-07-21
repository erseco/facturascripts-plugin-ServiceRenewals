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

use FacturaScripts\Dinamic\Model\Producto;
use FacturaScripts\Plugins\ServiceRenewals\Model\ServiceRenewalProfile;
use PHPUnit\Framework\TestCase;

/**
 * Tests del perfil de renovación de producto (requieren base de datos).
 *
 * @author Ernesto Serrano <info@ernesto.es>
 */
final class ServiceRenewalProfileTest extends TestCase
{
    /** @var Producto[] */
    private $products = [];

    /** @var ServiceRenewalProfile[] */
    private $profiles = [];

    protected function tearDown(): void
    {
        foreach ($this->profiles as $profile) {
            $profile->delete();
        }
        foreach ($this->products as $product) {
            $product->delete();
        }
        $this->profiles = [];
        $this->products = [];
    }

    public function testCreateValidProfile(): void
    {
        $product = $this->makeProduct();
        $profile = new ServiceRenewalProfile();
        $profile->idproduct = $product->idproducto;
        $profile->service_type = 'domain';
        $profile->default_period_months = 12;
        $profile->quote_lead_days = 30;
        $profile->reminder_days = '15,7,3';

        $this->assertTrue($profile->save());
        $this->profiles[] = $profile;
        $this->assertNotEmpty($profile->id);
    }

    public function testDefaultsAppliedOnClear(): void
    {
        $profile = new ServiceRenewalProfile();

        $this->assertTrue($profile->enabled);
        $this->assertSame(12, $profile->default_period_months);
        $this->assertTrue($profile->auto_generate_quote);
        $this->assertSame('other', $profile->service_type);
    }

    public function testRejectsNonPositivePeriod(): void
    {
        $product = $this->makeProduct();
        $profile = new ServiceRenewalProfile();
        $profile->idproduct = $product->idproducto;
        $profile->default_period_months = 0;

        $this->assertFalse($profile->save());

        $profile->default_period_months = -3;
        $this->assertFalse($profile->save());
    }

    public function testRejectsNegativeLeadDays(): void
    {
        $product = $this->makeProduct();
        $profile = new ServiceRenewalProfile();
        $profile->idproduct = $product->idproducto;
        $profile->quote_lead_days = -1;

        $this->assertFalse($profile->save());
    }

    public function testRejectsInvalidServiceType(): void
    {
        $product = $this->makeProduct();
        $profile = new ServiceRenewalProfile();
        $profile->idproduct = $product->idproducto;
        $profile->service_type = 'starship';

        $this->assertFalse($profile->save());
    }

    public function testNormalizesReminderDays(): void
    {
        $product = $this->makeProduct();
        $profile = new ServiceRenewalProfile();
        $profile->idproduct = $product->idproducto;
        $profile->reminder_days = ' 7, 30 ,7 ';

        $this->assertTrue($profile->save());
        $this->profiles[] = $profile;
        $this->assertSame('30,7', $profile->reminder_days);
    }

    public function testRejectsInvalidReminderDays(): void
    {
        $product = $this->makeProduct();
        $profile = new ServiceRenewalProfile();
        $profile->idproduct = $product->idproducto;
        $profile->reminder_days = '7,-3';

        $this->assertFalse($profile->save());
    }

    public function testOnlyOneProfilePerProduct(): void
    {
        $product = $this->makeProduct();

        $first = new ServiceRenewalProfile();
        $first->idproduct = $product->idproducto;
        $this->assertTrue($first->save());
        $this->profiles[] = $first;

        $second = new ServiceRenewalProfile();
        $second->idproduct = $product->idproducto;
        $this->assertFalse($second->save(), 'The unique constraint must reject a second profile');
    }

    private function makeProduct(): Producto
    {
        $product = new Producto();
        $product->referencia = 'SRV-' . substr(uniqid('', true), -8);
        $product->descripcion = 'Test renewal product';
        $product->precio = 50.0;
        $this->assertTrue($product->save(), 'Could not create the test product');
        $this->products[] = $product;

        return $product;
    }
}
