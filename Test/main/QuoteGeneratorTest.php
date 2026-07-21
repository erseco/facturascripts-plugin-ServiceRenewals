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

use FacturaScripts\Core\DataSrc\Empresas;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\Cliente;
use FacturaScripts\Dinamic\Model\PresupuestoCliente;
use FacturaScripts\Dinamic\Model\Producto;
use FacturaScripts\Plugins\ServiceRenewals\Lib\QuoteGenerator;
use FacturaScripts\Plugins\ServiceRenewals\Lib\RenewalCycleService;
use FacturaScripts\Plugins\ServiceRenewals\Model\ServiceRenewal;
use FacturaScripts\Plugins\ServiceRenewals\Model\ServiceRenewalCycle;
use PHPUnit\Framework\TestCase;

/**
 * Tests de la generación de presupuestos de renovación.
 *
 * @author Ernesto Serrano <info@ernesto.es>
 */
final class QuoteGeneratorTest extends TestCase
{
    /** @var object[] */
    private $cleanup = [];

    protected function setUp(): void
    {
        // estos tests necesitan los datos básicos de la instalación (empresa, almacén, serie)
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
    }

    public function testGeneratesQuoteForTheRightCustomerWithProductLine(): void
    {
        [$renewal, $cycle] = $this->makeRenewalWithCycle();

        $quote = (new QuoteGenerator())->generate($renewal, $cycle);
        $this->assertNotNull($quote);
        $this->cleanup[] = $quote;

        $this->assertSame($renewal->codcustomer, $quote->codcliente);

        $lines = $quote->getLines();
        $this->assertCount(1, $lines);
        $this->assertSame($renewal->getProduct()->referencia, $lines[0]->referencia);
        $this->assertEqualsWithDelta(25.0, $lines[0]->pvpunitario, 0.001, 'Must use the product price');

        // la descripción incluye identificador y periodo
        $this->assertStringContainsString($renewal->service_identifier, $lines[0]->descripcion);
        $this->assertStringContainsString('15-09-2026', $lines[0]->descripcion);

        // el ciclo queda vinculado y en estado quote_created
        $cycle->reload();
        $this->assertSame((int)$quote->idpresupuesto, (int)$cycle->quote_id);
        $this->assertSame(ServiceRenewalCycle::STATUS_QUOTE_CREATED, $cycle->status);
        $this->assertNotEmpty($cycle->quote_created_at);
    }

    public function testUsesPriceOverrideWhenDefined(): void
    {
        [$renewal, $cycle] = $this->makeRenewalWithCycle();
        $renewal->price_override = 12.34;
        $this->assertTrue($renewal->save());

        $quote = (new QuoteGenerator())->generate($renewal, $cycle);
        $this->assertNotNull($quote);
        $this->cleanup[] = $quote;

        $lines = $quote->getLines();
        $this->assertEqualsWithDelta(12.34, $lines[0]->pvpunitario, 0.001);
    }

    public function testDoesNotGenerateTwoQuotesForTheSameCycle(): void
    {
        [$renewal, $cycle] = $this->makeRenewalWithCycle();
        $generator = new QuoteGenerator();

        $first = $generator->generate($renewal, $cycle);
        $this->assertNotNull($first);
        $this->cleanup[] = $first;

        $cycle->reload();
        $second = $generator->generate($renewal, $cycle);
        $this->assertNotNull($second);
        $this->assertSame((int)$first->idpresupuesto, (int)$second->idpresupuesto);

        $count = PresupuestoCliente::count([Where::eq('codcliente', $renewal->codcustomer)]);
        $this->assertSame(1, $count, 'A repeated execution must not create another quote');
    }

    /** @return array{0: ServiceRenewal, 1: ServiceRenewalCycle} */
    private function makeRenewalWithCycle(): array
    {
        $customer = new Cliente();
        $customer->nombre = 'Quote Test Customer ' . substr(uniqid('', true), -4);
        $customer->cifnif = substr(uniqid('', true), -9);
        $this->assertTrue($customer->save());
        $this->cleanup[] = $customer;

        $product = new Producto();
        $product->referencia = 'SRV-' . substr(uniqid('', true), -8);
        $product->descripcion = 'Quote test product';
        $product->precio = 25.0;
        $this->assertTrue($product->save());
        $this->cleanup[] = $product;

        $renewal = new ServiceRenewal();
        $renewal->codcustomer = $customer->codcliente;
        $renewal->idproduct = $product->idproducto;
        $renewal->service_identifier = 'quote-' . substr(uniqid('', true), -8) . '.example.com';
        $renewal->provider_name = 'Test Provider';
        $renewal->expiration_date = '2026-09-15';
        $renewal->period_months = 12;
        $this->assertTrue($renewal->save());
        $this->cleanup[] = $renewal;

        $cycle = (new RenewalCycleService())->getOrCreate($renewal);
        $this->assertNotNull($cycle);
        $this->cleanup[] = $cycle;

        return [$renewal, $cycle];
    }
}
