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

use FacturaScripts\Core\DataSrc\Almacenes;
use FacturaScripts\Core\DataSrc\Empresas;
use FacturaScripts\Core\Where;
use FacturaScripts\Core\WorkQueue;
use FacturaScripts\Dinamic\Lib\BusinessDocumentGenerator;
use FacturaScripts\Dinamic\Model\FacturaCliente;
use FacturaScripts\Dinamic\Model\PresupuestoCliente;
use FacturaScripts\Plugins\ServiceRenewals\Lib\DocumentTransformationFinder;
use FacturaScripts\Plugins\ServiceRenewals\Lib\RenewalCycleService;
use FacturaScripts\Plugins\ServiceRenewals\Lib\RenewalDateCalculator;
use FacturaScripts\Plugins\ServiceRenewals\Lib\RenewalProcessor;
use FacturaScripts\Plugins\ServiceRenewals\Model\ServiceRenewal;
use FacturaScripts\Plugins\ServiceRenewals\Model\ServiceRenewalCycle;
use PHPUnit\Framework\TestCase;

/**
 * Tests del flujo completo: detección, presupuesto, factura y renovación.
 *
 * @author Ernesto Serrano <info@ernesto.es>
 */
final class RenewalFlowTest extends TestCase
{
    use ServiceRenewalsFixtures;

    /** @var object[] */
    private $cleanup = [];

    protected function setUp(): void
    {
        if (empty(Empresas::default()->idempresa) || empty(Almacenes::all())) {
            $this->markTestSkipped('Core default data is not installed');
        }

        // registramos los workers igual que hace Init::init()
        WorkQueue::addWorker('ProcessServiceRenewalsWorker', 'ServiceRenewals.Process');
        WorkQueue::addWorker('SendServiceRenewalMailWorker', 'ServiceRenewals.SendNotification');
    }

    protected function tearDown(): void
    {
        // borramos en orden inverso de creación para respetar las claves foráneas
        foreach (array_reverse($this->cleanup) as $model) {
            $model->delete();
        }
        $this->cleanup = [];
    }

    public function testProcessorCreatesCycleAndQuoteIdempotently(): void
    {
        $renewal = $this->makeRenewal('2026-08-01', 12, false);
        $processor = new RenewalProcessor();

        // dentro del umbral de 30 días
        $processor->process('2026-07-15');

        $cycles = ServiceRenewalCycle::all([Where::eq('service_renewal_id', $renewal->id)]);
        $this->assertCount(1, $cycles);
        $this->registerCycleCleanup($cycles[0]);
        $this->assertNotEmpty($cycles[0]->quote_id, 'The quote must be generated');

        // segunda ejecución: sin duplicados
        $processor->process('2026-07-15');

        $cycles = ServiceRenewalCycle::all([Where::eq('service_renewal_id', $renewal->id)]);
        $this->assertCount(1, $cycles, 'No duplicated cycles');
        $count = PresupuestoCliente::count([Where::eq('codcliente', $renewal->codcustomer)]);
        $this->assertSame(1, $count, 'No duplicated quotes');
    }

    public function testInvoiceTriggerRenewsOnlyOnce(): void
    {
        $renewal = $this->makeRenewal('2026-08-01', 12, false);
        $processor = new RenewalProcessor();
        $processor->process('2026-07-15');

        $cycle = ServiceRenewalCycle::findWhere([Where::eq('service_renewal_id', $renewal->id)]);
        $this->assertNotNull($cycle);
        $this->registerCycleCleanup($cycle);

        // transformamos el presupuesto en factura como lo haría el núcleo
        $this->transformQuoteToInvoice($cycle);

        // el procesador detecta la factura y aplica la renovación
        $processor->process('2026-07-16');
        $cycle->reload();
        $renewal->reload();

        $this->assertSame(ServiceRenewalCycle::STATUS_RENEWED, $cycle->status);
        $this->assertNotEmpty($cycle->invoice_id);
        $this->assertNotEmpty($cycle->invoice_detected_at);
        $this->assertSame(
            '2027-08-01',
            RenewalDateCalculator::toIso($renewal->expiration_date),
            'The date advances 12 natural months'
        );

        // otra ejecución no vuelve a avanzar la fecha
        $processor->process('2026-07-17');
        $renewal->reload();
        $this->assertSame('2027-08-01', RenewalDateCalculator::toIso($renewal->expiration_date));
    }

    public function testManualTriggerWaitsForConfirmation(): void
    {
        $renewal = $this->makeRenewal('2026-08-01', 12, true);
        $processor = new RenewalProcessor();
        $processor->process('2026-07-15');

        $cycle = ServiceRenewalCycle::findWhere([Where::eq('service_renewal_id', $renewal->id)]);
        $this->assertNotNull($cycle);
        $this->registerCycleCleanup($cycle);

        $this->transformQuoteToInvoice($cycle);

        // la detección deja el ciclo pendiente de confirmación, sin avanzar la fecha
        $processor->process('2026-07-16');
        $cycle->reload();
        $renewal->reload();

        $this->assertSame(ServiceRenewalCycle::STATUS_RENEWAL_PENDING, $cycle->status);
        $this->assertSame(
            '2026-08-01',
            RenewalDateCalculator::toIso($renewal->expiration_date),
            'Manual policy must not advance the date'
        );

        // la confirmación manual avanza la fecha una sola vez
        $service = new RenewalCycleService();
        $this->assertTrue($service->confirmManualRenewal($cycle));
        $renewal->reload();
        $this->assertSame('2027-08-01', RenewalDateCalculator::toIso($renewal->expiration_date));

        $this->assertFalse($service->confirmManualRenewal($cycle));
        $renewal->reload();
        $this->assertSame('2027-08-01', RenewalDateCalculator::toIso($renewal->expiration_date));
    }

    public function testTransformationFinderIgnoresQuotesWithoutInvoice(): void
    {
        $renewal = $this->makeRenewal('2026-08-01', 12, false);
        $processor = new RenewalProcessor();
        $processor->process('2026-07-15');

        $cycle = ServiceRenewalCycle::findWhere([Where::eq('service_renewal_id', $renewal->id)]);
        $this->assertNotNull($cycle);
        $this->registerCycleCleanup($cycle);

        $this->assertNull(DocumentTransformationFinder::findInvoiceForQuote((int)$cycle->quote_id));
    }

    public function testRenewalExposesTheGeneratedQuote(): void
    {
        $renewal = $this->makeRenewal('2026-08-01', 12, false);
        $this->assertNull($renewal->getLastQuote(), 'Without cycles there is no quote to open');

        $processor = new RenewalProcessor();
        $processor->process('2026-07-15');

        $cycle = ServiceRenewalCycle::findWhere([Where::eq('service_renewal_id', $renewal->id)]);
        $this->assertNotNull($cycle);
        $this->registerCycleCleanup($cycle);

        $quote = $renewal->getLastQuote();
        $this->assertNotNull($quote, 'The subscription must expose the quote of the open cycle');
        $this->assertSame((int)$cycle->quote_id, (int)$quote->idpresupuesto);
        $this->assertSame('EditPresupuestoCliente?code=' . $quote->idpresupuesto, $quote->url());
    }

    public function testQuoteRemainsAccessibleAfterRenewal(): void
    {
        $renewal = $this->makeRenewal('2026-08-01', 12, false);
        $processor = new RenewalProcessor();
        $processor->process('2026-07-15');

        $cycle = ServiceRenewalCycle::findWhere([Where::eq('service_renewal_id', $renewal->id)]);
        $this->assertNotNull($cycle);
        $this->registerCycleCleanup($cycle);
        $quoteId = (int)$cycle->quote_id;

        // al renovar, el ciclo se cierra y deja de ser el ciclo abierto
        $this->transformQuoteToInvoice($cycle);
        $processor->process('2026-07-16');
        $cycle->reload();
        $renewal->reload();
        $this->assertSame(ServiceRenewalCycle::STATUS_RENEWED, $cycle->status);
        $this->assertNull($renewal->getOpenCycle(), 'The renewed cycle is no longer open');

        $quote = $renewal->getLastQuote();
        $this->assertNotNull($quote, 'The quote of the last cycle must still be reachable');
        $this->assertSame($quoteId, (int)$quote->idpresupuesto);
    }

    /** Transforma el presupuesto del ciclo en factura usando el generador del núcleo. */
    private function transformQuoteToInvoice(ServiceRenewalCycle $cycle): void
    {
        $quote = $cycle->getQuote();
        $this->assertNotNull($quote);

        $lines = $quote->getLines();
        $quantities = [];
        foreach ($lines as $line) {
            $quantities[$line->idlinea] = (float)$line->cantidad;
        }

        $generator = new BusinessDocumentGenerator();
        $this->assertTrue(
            $generator->generate($quote, 'FacturaCliente', $lines, $quantities),
            'Could not transform the quote into an invoice: ' . $this->recentCoreLogMessage()
        );

        $invoiceId = DocumentTransformationFinder::findInvoiceForQuote((int)$quote->idpresupuesto);
        $this->assertNotNull($invoiceId, 'The transformation must be recorded');

        $invoice = new FacturaCliente();
        if ($invoice->load((string)$invoiceId)) {
            $this->cleanup[] = $invoice;
        }
    }

    private function registerCycleCleanup(ServiceRenewalCycle $cycle): void
    {
        foreach ($cycle->getRenewal()->getCycles() as $item) {
            $this->cleanup[] = $item;
            $quote = $item->getQuote();
            if (null !== $quote) {
                $this->cleanup[] = $quote;
            }
        }
    }

    private function makeRenewal(string $expiration, int $months, bool $manual): ServiceRenewal
    {
        $customer = $this->makeCustomer('Flow Test Customer', 'flow@example.com');
        $product = $this->makeServiceProduct('Flow test product', 40.0);

        $renewal = new ServiceRenewal();
        $renewal->codcustomer = $customer->codcliente;
        $renewal->idproduct = $product->idproducto;
        $renewal->service_identifier = 'flow-' . substr(uniqid('', true), -8) . '.example.com';
        $renewal->expiration_date = $expiration;
        $renewal->period_months = $months;
        $renewal->quote_lead_days = 30;
        // en los tests evitamos el envío automático para no depender del SMTP
        $renewal->auto_send_quote = 0;
        $renewal->renewal_trigger = $manual ? 'manual' : 'invoice';
        $this->assertTrue($renewal->save());
        $this->cleanup[] = $renewal;

        return $renewal;
    }
}
