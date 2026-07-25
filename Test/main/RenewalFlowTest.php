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
use FacturaScripts\Dinamic\Model\DocTransformation;
use FacturaScripts\Dinamic\Model\FacturaCliente;
use FacturaScripts\Dinamic\Model\PresupuestoCliente;
use FacturaScripts\Plugins\ServiceRenewals\Extension\Model\DocTransformation as DocTransformationExtension;
use FacturaScripts\Plugins\ServiceRenewals\Lib\DocumentTransformationFinder;
use FacturaScripts\Plugins\ServiceRenewals\Lib\RenewalCycleFilter;
use FacturaScripts\Plugins\ServiceRenewals\Lib\RenewalCycleService;
use FacturaScripts\Plugins\ServiceRenewals\Lib\RenewalDateCalculator;
use FacturaScripts\Plugins\ServiceRenewals\Lib\RenewalListDecorator;
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

        // y la extensión que renueva al enlazar el presupuesto con la factura
        DocTransformation::addExtension(new DocTransformationExtension());
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

    public function testRenewalExposesTheDetectedInvoice(): void
    {
        $renewal = $this->makeRenewal('2026-08-01', 12, true);
        $this->assertNull($renewal->getLastInvoice(), 'Without cycles there is no invoice to open');

        $processor = new RenewalProcessor();
        $processor->process('2026-07-15');

        $cycle = ServiceRenewalCycle::findWhere([Where::eq('service_renewal_id', $renewal->id)]);
        $this->assertNotNull($cycle);
        $this->registerCycleCleanup($cycle);

        // con el presupuesto creado pero sin factura no hay nada que abrir
        $this->assertNull($renewal->getLastInvoice(), 'Without an invoice there is nothing to open');

        // al emitir la factura, la extensión la vincula y la deja pendiente de
        // confirmar, sin esperar al cron ni saltarse la política manual
        $this->transformQuoteToInvoice($cycle);
        $cycle->reload();
        $this->assertNotEmpty($cycle->invoice_id, 'The invoice is linked as soon as it is issued');
        $this->assertSame(ServiceRenewalCycle::STATUS_RENEWAL_PENDING, $cycle->status);
        $this->assertNotNull($cycle->resolveInvoice());

        $invoice = $renewal->getLastInvoice();
        $this->assertNotNull($invoice, 'The subscription must expose the invoice of the open cycle');
        $this->assertSame((int)$cycle->invoice_id, (int)$invoice->idfactura);
        $this->assertSame('EditFacturaCliente?code=' . $invoice->idfactura, $invoice->url());
    }

    public function testInvoiceRemainsAccessibleAfterRenewal(): void
    {
        $renewal = $this->makeRenewal('2026-08-01', 12, false);
        $processor = new RenewalProcessor();
        $processor->process('2026-07-15');

        $cycle = ServiceRenewalCycle::findWhere([Where::eq('service_renewal_id', $renewal->id)]);
        $this->assertNotNull($cycle);
        $this->registerCycleCleanup($cycle);

        // con política invoice el ciclo pasa a renewed en la misma pasada
        $this->transformQuoteToInvoice($cycle);
        $processor->process('2026-07-16');
        $cycle->reload();
        $renewal->reload();
        $this->assertSame(ServiceRenewalCycle::STATUS_RENEWED, $cycle->status);
        $this->assertNull($renewal->getOpenCycle(), 'The renewed cycle is no longer open');

        $invoice = $renewal->getLastInvoice();
        $this->assertNotNull($invoice, 'The invoice of the last cycle must still be reachable');
        $this->assertSame((int)$cycle->invoice_id, (int)$invoice->idfactura);
    }

    public function testListKeepsDocumentsAfterRenewal(): void
    {
        $renewal = $this->makeRenewal('2026-08-01', 12, false);
        $processor = new RenewalProcessor();
        $processor->process('2026-07-15');

        $cycle = ServiceRenewalCycle::findWhere([Where::eq('service_renewal_id', $renewal->id)]);
        $this->assertNotNull($cycle);
        $this->registerCycleCleanup($cycle);

        $this->transformQuoteToInvoice($cycle);
        $processor->process('2026-07-16');
        $cycle->reload();
        $renewal->reload();
        $this->assertSame(ServiceRenewalCycle::STATUS_RENEWED, $cycle->status);

        // regresión: el ciclo renovado ya no es el abierto y el listado se vaciaba
        $rows = [$renewal];
        RenewalListDecorator::decorateFull($rows, '2026-07-16');

        $this->assertNotNull($rows[0]->last_quote_code, 'The quote column must survive the renewal');
        $this->assertSame((int)$cycle->quote_id, (int)$rows[0]->last_quote_id);
        $this->assertNotNull($rows[0]->last_invoice_code, 'The invoice column must survive the renewal');
        $this->assertSame((int)$cycle->invoice_id, (int)$rows[0]->last_invoice_id);
        $this->assertNotNull($rows[0]->cycle_status, 'The cycle status must survive the renewal');

        // el estado de cobro alimenta el color de la fila
        $this->assertSame('issued', $rows[0]->invoice_status, 'A fresh invoice is issued, not paid');
        $this->assertNotEmpty($rows[0]->invoice_status_label);
    }

    public function testListShowsTheInvoiceBeforeTheCronLinksIt(): void
    {
        $renewal = $this->makeRenewal('2026-08-01', 12, true);
        $processor = new RenewalProcessor();
        $processor->process('2026-07-15');

        $cycle = ServiceRenewalCycle::findWhere([Where::eq('service_renewal_id', $renewal->id)]);
        $this->assertNotNull($cycle);
        $this->registerCycleCleanup($cycle);

        $rows = [$renewal];
        RenewalListDecorator::decorateFull($rows, '2026-07-15');
        $this->assertNull($rows[0]->last_invoice_code, 'Without an invoice the column stays empty');
        $this->assertNull($rows[0]->invoice_status);

        $this->transformQuoteToInvoice($cycle);

        // simulamos que el vínculo no llegó a anotarse en el ciclo: pasa cuando la
        // factura entra por otra vía (importación, API) y solo la recoge el cron.
        // aun así el listado debe encontrarla siguiendo la cadena de documentos
        $cycle->reload();
        $cycle->invoice_id = null;
        $cycle->invoice_detected_at = null;
        $cycle->status = ServiceRenewalCycle::STATUS_QUOTE_CREATED;
        $this->assertTrue($cycle->save());

        $rows = [$renewal];
        RenewalListDecorator::decorateFull($rows, '2026-07-15');
        $this->assertNotNull($rows[0]->last_invoice_code, 'The issued invoice must show up right away');
        $this->assertNotNull($rows[0]->last_invoice_id, 'And it must be linkable');
        $this->assertSame('issued', $rows[0]->invoice_status);
    }

    public function testRenewsAsSoonAsTheInvoiceIsIssued(): void
    {
        $renewal = $this->makeRenewal('2026-08-01', 12, false);
        $processor = new RenewalProcessor();
        $processor->process('2026-07-15');

        $cycle = ServiceRenewalCycle::findWhere([Where::eq('service_renewal_id', $renewal->id)]);
        $this->assertNotNull($cycle);
        $this->registerCycleCleanup($cycle);
        $this->assertSame(ServiceRenewalCycle::STATUS_QUOTE_CREATED, $cycle->status);

        // emitimos la factura y NO volvemos a llamar al procesador: la extensión
        // del núcleo debe haber renovado ya al escribirse el vínculo
        $this->transformQuoteToInvoice($cycle);

        $cycle->reload();
        $renewal->reload();
        $this->assertSame(ServiceRenewalCycle::STATUS_RENEWED, $cycle->status, 'Renewed without waiting for the cron');
        $this->assertNotEmpty($cycle->invoice_id);
        $this->assertSame(
            '2027-08-01',
            RenewalDateCalculator::toIso($renewal->expiration_date),
            'The expiration date advances right away'
        );

        // y el cron posterior no vuelve a avanzar la fecha
        $processor->process('2026-07-17');
        $renewal->reload();
        $this->assertSame('2027-08-01', RenewalDateCalculator::toIso($renewal->expiration_date));
    }

    public function testManualPolicyStillWaitsWhenTheInvoiceIsIssued(): void
    {
        $renewal = $this->makeRenewal('2026-08-01', 12, true);
        $processor = new RenewalProcessor();
        $processor->process('2026-07-15');

        $cycle = ServiceRenewalCycle::findWhere([Where::eq('service_renewal_id', $renewal->id)]);
        $this->assertNotNull($cycle);
        $this->registerCycleCleanup($cycle);

        $this->transformQuoteToInvoice($cycle);

        // la inmediatez no se salta la política: sigue esperando confirmación
        $cycle->reload();
        $renewal->reload();
        $this->assertSame(ServiceRenewalCycle::STATUS_RENEWAL_PENDING, $cycle->status);
        $this->assertSame(
            '2026-08-01',
            RenewalDateCalculator::toIso($renewal->expiration_date),
            'The manual policy does not advance the date on its own'
        );
    }

    public function testInvoiceStatusReflectsPayment(): void
    {
        $renewal = $this->makeRenewal('2026-08-01', 12, true);
        $processor = new RenewalProcessor();
        $processor->process('2026-07-15');

        $cycle = ServiceRenewalCycle::findWhere([Where::eq('service_renewal_id', $renewal->id)]);
        $this->assertNotNull($cycle);
        $this->registerCycleCleanup($cycle);

        $this->transformQuoteToInvoice($cycle);
        $processor->process('2026-07-16');
        $cycle->reload();

        $invoice = $cycle->resolveInvoice();
        $this->assertNotNull($invoice);

        // el núcleo marca la factura como pagada al cobrar todos sus recibos
        foreach ($invoice->getReceipts() as $receipt) {
            $receipt->pagado = true;
            $this->assertTrue($receipt->save());
        }
        $invoice->reload();
        $this->assertTrue((bool)$invoice->pagada, 'The core marks the invoice as paid');

        $rows = [$renewal];
        RenewalListDecorator::decorateFull($rows, '2026-07-16');
        $this->assertSame('paid', $rows[0]->invoice_status, 'A paid invoice colours the row as paid');
    }

    public function testInvoicedFilterFindsRenewedSubscriptions(): void
    {
        $renewal = $this->makeRenewal('2026-08-01', 12, false);
        $processor = new RenewalProcessor();
        $processor->process('2026-07-15');

        $cycle = ServiceRenewalCycle::findWhere([Where::eq('service_renewal_id', $renewal->id)]);
        $this->assertNotNull($cycle);
        $this->registerCycleCleanup($cycle);

        $this->transformQuoteToInvoice($cycle);
        $processor->process('2026-07-16');
        $cycle->reload();
        $renewal->reload();
        $this->assertSame(ServiceRenewalCycle::STATUS_RENEWED, $cycle->status);

        // al renovar, expiration_date avanza y deja de coincidir con
        // previous_expiration_date: el filtro debe seguir encontrando la suscripción
        $found = false;
        foreach (ServiceRenewal::all(RenewalCycleFilter::invoicedWhere(), [], 0, 0) as $row) {
            if ((int)$row->id === (int)$renewal->id) {
                $found = true;
            }
        }
        $this->assertTrue($found, 'An already renewed invoiced subscription must match the filter');
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
