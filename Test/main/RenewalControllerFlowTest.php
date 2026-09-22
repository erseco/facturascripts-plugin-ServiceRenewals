<?php

/**
 * Copyright (C) 2026 Ernesto Serrano <info@ernesto.es>
 * SPDX-License-Identifier: LGPL-3.0-or-later
 */

namespace FacturaScripts\Test\Plugins;

use FacturaScripts\Core\Base\ControllerPermissions;
use FacturaScripts\Core\Model\WorkEvent;
use FacturaScripts\Core\Request;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Plugins\ServiceRenewals\Controller\EditServiceRenewal;
use FacturaScripts\Plugins\ServiceRenewals\Controller\ListServiceRenewal;
use FacturaScripts\Plugins\ServiceRenewals\Controller\ServiceRenewalsConfig;
use FacturaScripts\Plugins\ServiceRenewals\Init;
use FacturaScripts\Plugins\ServiceRenewals\Lib\QuoteGenerator;
use FacturaScripts\Plugins\ServiceRenewals\Lib\QuoteNotificationSender;
use FacturaScripts\Plugins\ServiceRenewals\Lib\RenewalCycleService;
use FacturaScripts\Plugins\ServiceRenewals\Lib\RenewalDateCalculator;
use FacturaScripts\Plugins\ServiceRenewals\Lib\RenewalProcessor;
use FacturaScripts\Plugins\ServiceRenewals\Model\ServiceRenewal;
use FacturaScripts\Plugins\ServiceRenewals\Model\ServiceRenewalCycle;
use FacturaScripts\Plugins\ServiceRenewals\Model\ServiceRenewalNotification;
use FacturaScripts\Plugins\ServiceRenewals\Model\ServiceRenewalProfile;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Flujos de las vistas y acciones con modelos y persistencia reales.
 *
 * @author Ernesto Serrano <info@ernesto.es>
 */
final class RenewalControllerFlowTest extends TestCase
{
    use ServiceRenewalsFixtures;

    private array $cleanup = [];

    public function testModelValidationRejectsInvalidValues(): void
    {
        $renewal = $this->makeRenewal();
        foreach (
            [
            'quote_lead_days' => -1, 'reminder_days' => 'bad', 'status' => 'bad',
            'service_type' => 'bad', 'renewal_trigger' => 'bad',
            ] as $field => $value
        ) {
            $invalid = clone $renewal;
            $invalid->{$field} = $value;
            self::assertFalse($invalid->test(), $field);
        }
        $renewal->renewal_trigger = '';
        $renewal->service_type = '';
        self::assertTrue($renewal->test());
        self::assertNull($renewal->renewal_trigger);
        self::assertNull($renewal->service_type);
        $renewal->auto_send_quote = null;
        self::assertIsBool($renewal->effectiveAutoSendQuote());
        self::assertSame('invoice', $renewal->effectiveRenewalTrigger());
        $renewal->service_type = 'domain';
        self::assertSame('domain', $renewal->effectiveServiceType());
        self::assertSame(PHP_INT_MAX, $renewal->daysToExpiration('bad'));

        $profile = new ServiceRenewalProfile();
        self::assertFalse($profile->test());
        $profile->idproduct = $renewal->idproduct;
        $profile->renewal_trigger = 'bad';
        self::assertFalse($profile->test());
        $profile->renewal_trigger = '';
        self::assertTrue($profile->test());
        self::assertNull($profile->renewal_trigger);
        self::assertEquals($renewal->idproduct, $profile->getProduct()->idproducto);
        self::assertSame('EditProducto?code=' . $renewal->idproduct, $profile->url());

        $cycle = new ServiceRenewalCycle();
        self::assertFalse($cycle->test());
        self::assertSame('ListServiceRenewal', $cycle->url());
        $cycle->service_renewal_id = $renewal->id;
        self::assertFalse($cycle->test());
        $cycle->previous_expiration_date = '2026-08-01';
        $cycle->next_expiration_date = '2027-08-01';
        $cycle->status = 'bad';
        self::assertFalse($cycle->test());
        self::assertSame('EditServiceRenewal?code=' . $renewal->id, $cycle->url());

        $notification = new ServiceRenewalNotification();
        self::assertFalse($notification->test());
        self::assertSame('ListServiceRenewal', $notification->url());
        $notification->cycle_id = 1;
        foreach (
            [
            'notification_type' => 'bad', 'status' => 'bad', 'attempts' => -1,
            'reminder_day' => -1, 'recipient' => 'bad', 'cc' => 'bad', 'bcc' => 'bad',
            ] as $field => $value
        ) {
            $invalid = clone $notification;
            $invalid->{$field} = $value;
            self::assertFalse($invalid->test(), $field);
        }
        $empty = new ServiceRenewal();
        self::assertNull($empty->getLastQuote());
        self::assertNull($empty->getLastInvoice());
        self::assertNull($empty->getLastCycle());
        self::assertNull($empty->getLastCycleWithQuote());
    }

    public function testProcessorQueuesQuoteAndReminderAndRetriesFailures(): void
    {
        $renewal = $this->makeRenewal();
        $renewal->auto_send_quote = true;
        $renewal->email_override = 'coverage@example.com';
        $renewal->reminder_days = '7';
        self::assertTrue($renewal->save());
        (new Init())->init();
        $processor = new RenewalProcessor();
        self::assertSame(1, $processor->process('bad')['errors']);
        $stats = $processor->process('2026-07-25');
        $cycle = $renewal->getOpenCycle();
        self::assertNotNull($cycle);
        $this->cleanup[] = $cycle->getQuote();
        $this->cleanup[] = $cycle;
        $notifications = ServiceRenewalNotification::all([Where::eq('cycle_id', $cycle->id)]);
        foreach ($notifications as $notification) {
            $this->cleanup[] = $notification;
        }
        self::assertSame(1, $stats['quote_notifications']);
        self::assertSame(1, $stats['reminders']);
        self::assertCount(2, $notifications);
        foreach ($notifications as $index => $notification) {
            $notification->status = 'failed';
            $notification->recipient = $index === 0 ? '' : 'coverage@example.com';
            self::assertTrue($notification->save());
            self::assertSame('EditServiceRenewal?code=' . $renewal->id, $notification->url());
        }
        $stats = $processor->process('1900-01-01');
        self::assertSame(1, $stats['retried_notifications']);
        $page = $this->page($renewal);
        $this->invoke($page, 'execPreviousAction', 'send-quote-email');
        $this->invoke($page, 'createViews');
        foreach ($page->views as $name => $view) {
            $this->invoke($page, 'loadData', $name, $view);
        }
        self::assertCount(2, $page->views['ListServiceRenewalNotification']->cursor);
        foreach ($notifications as $notification) {
            foreach (
                WorkEvent::all([
                Where::eq('name', Init::MAIL_EVENT), Where::eq('value', (string)$notification->id),
                ]) as $event
            ) {
                $this->cleanup[] = $event;
            }
        }
        $renewal->status = 'cancelled';
        self::assertFalse((new QuoteNotificationSender())->send($renewal));
    }

    public function testMissingCycleRollsBackQuoteCreation(): void
    {
        $renewal = $this->makeRenewal();
        $cycle = new ServiceRenewalCycle();
        $cycle->id = -1;
        self::assertNull((new QuoteGenerator())->generate($renewal, $cycle));
        self::assertSame('failed', $cycle->status);
        self::assertStringContainsString('Cycle not found', $cycle->last_error);
        self::assertNull((new RenewalCycleService())->getOrCreate(new ServiceRenewal()));
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->cleanup) as $model) {
            $model->delete();
        }
        Tools::settingsClear();
        Tools::log()->clear();
    }

    public function testConfigAndListLoadActualViews(): void
    {
        $config = new ServiceRenewalsConfig('ServiceRenewalsConfig');
        self::assertSame('admin', $config->getPageData()['menu']);
        $this->invoke($config, 'createViews');
        $this->invoke($config, 'loadData', 'ServiceRenewalsConfig', $config->views['ServiceRenewalsConfig']);
        self::assertSame('ServiceRenewals', $config->views['ServiceRenewalsConfig']->model->name);

        $renewal = $this->makeRenewal();
        $list = new ListServiceRenewal('ListServiceRenewal');
        $list->permissions = new ControllerPermissions();
        self::assertSame('sales', $list->getPageData()['menu']);
        $this->invoke($list, 'createViews');
        $this->invoke($list, 'loadData', 'ListServiceRenewal', $list->views['ListServiceRenewal']);
        $ids = array_map(static fn ($row) => (int)$row->id, $list->views['ListServiceRenewal']->cursor);
        self::assertContains((int)$renewal->id, $ids);
    }

    public function testActionsChangeStatusAndConfirmExactlyOnce(): void
    {
        $renewal = $this->makeRenewal();
        $page = $this->page($renewal);
        foreach (['suspend' => 'suspended', 'reactivate' => 'active', 'cancel' => 'cancelled'] as $action => $status) {
            self::assertTrue($this->invoke($page, 'execPreviousAction', $action . '-renewal'));
            $renewal->reload();
            self::assertSame($status, $renewal->status);
        }
        $this->invoke($page, 'execPreviousAction', 'generate-quote');
        self::assertNull($renewal->getOpenCycle());
        $this->invoke($page, 'execPreviousAction', 'reactivate-renewal');
        $renewal->reload();
        $this->invoke($page, 'execPreviousAction', 'confirm-renewal');
        self::assertSame('2026-08-01', RenewalDateCalculator::toIso($renewal->expiration_date));

        $cycle = (new RenewalCycleService())->getOrCreate($renewal);
        self::assertNotNull($cycle);
        $this->cleanup[] = $cycle;
        $cycle->status = ServiceRenewalCycle::STATUS_INVOICED;
        self::assertTrue($cycle->save());
        $this->invoke($page, 'execPreviousAction', 'confirm-renewal');
        $renewal->reload();
        self::assertSame('2027-08-01', RenewalDateCalculator::toIso($renewal->expiration_date));
        $this->invoke($page, 'execPreviousAction', 'confirm-renewal');
        $renewal->reload();
        self::assertSame('2027-08-01', RenewalDateCalculator::toIso($renewal->expiration_date));
    }

    public function testGenerateQuoteLoadsHistoryWithoutDuplicates(): void
    {
        $renewal = $this->makeRenewal();
        $page = $this->page($renewal);
        self::assertSame('ServiceRenewal', $page->getModelClassName());
        self::assertSame('sales', $page->getPageData()['menu']);
        $this->invoke($page, 'execPreviousAction', 'generate-quote');
        $cycle = $renewal->getOpenCycle();
        self::assertNotNull($cycle);
        $quote = $cycle->getQuote();
        self::assertNotNull($quote);
        $this->cleanup[] = $quote;
        $this->cleanup[] = $cycle;
        $this->invoke($page, 'execPreviousAction', 'generate-quote');
        $cycle->reload();
        self::assertEquals($quote->idpresupuesto, $cycle->quote_id);
        $this->invoke($page, 'createViews');
        foreach ($page->views as $name => $view) {
            $this->invoke($page, 'loadData', $name, $view);
        }
        self::assertCount(1, $page->views['ListServiceRenewalCycle']->cursor);
        self::assertSame($quote->codigo, $page->views['ListServiceRenewalCycle']->cursor[0]->quote_code);
        self::assertCount(0, $page->views['ListServiceRenewalNotification']->cursor);
    }

    public function testMissingRecordAndEmptyHistoryAreSafe(): void
    {
        $renewal = $this->makeRenewal();
        $page = $this->page($renewal);
        $this->invoke($page, 'createViews');
        foreach ($page->views as $name => $view) {
            $this->invoke($page, 'loadData', $name, $view);
        }
        self::assertCount(0, $page->views['ListServiceRenewalCycle']->cursor);
        $page->request = new Request(['request' => ['code' => '-1']]);
        self::assertTrue($this->invoke($page, 'execPreviousAction', 'cancel-renewal'));
        $renewal->reload();
        self::assertSame('active', $renewal->status);
    }

    private function page(ServiceRenewal $renewal): EditServiceRenewal
    {
        // El token se prueba por separado en ControllerPermissionsTest.
        $page = new class ('EditServiceRenewal') extends EditServiceRenewal {
            protected function validateFormToken(): bool
            {
                return true;
            }
        };
        $page->permissions = new ControllerPermissions();
        $page->permissions->allowUpdate = true;
        $page->request = new Request(['query' => ['code' => (string)$renewal->id]]);
        return $page;
    }

    private function makeRenewal(): ServiceRenewal
    {
        $customer = $this->makeCustomer('Coverage');
        $product = $this->makeServiceProduct();
        $renewal = new ServiceRenewal();
        $renewal->codcustomer = $customer->codcliente;
        $renewal->idproduct = $product->idproducto;
        $renewal->service_identifier = uniqid('coverage-');
        $renewal->expiration_date = '2026-08-01';
        $renewal->renewal_trigger = 'manual';
        self::assertTrue($renewal->save());
        $this->cleanup[] = $renewal;
        return $renewal;
    }

    private function invoke($object, string $method, ...$args)
    {
        $reflection = new ReflectionMethod($object, $method);
        $reflection->setAccessible(true);
        return $reflection->invoke($object, ...$args);
    }
}
