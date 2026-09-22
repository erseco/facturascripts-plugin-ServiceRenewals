<?php

/**
 * Copyright (C) 2026 Ernesto Serrano <info@ernesto.es>
 * SPDX-License-Identifier: LGPL-3.0-or-later
 */

namespace FacturaScripts\Test\Plugins;

use FacturaScripts\Core\Model\WorkEvent;
use FacturaScripts\Core\Request;
use FacturaScripts\Core\Session;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\CronJob;
use FacturaScripts\Dinamic\Model\Settings;
use FacturaScripts\Dinamic\Model\User;
use FacturaScripts\Plugins\ServiceRenewals\Controller\EditServiceRenewal;
use FacturaScripts\Plugins\ServiceRenewals\Controller\ServiceRenewalsDashboard;
use FacturaScripts\Plugins\ServiceRenewals\Cron;
use FacturaScripts\Plugins\ServiceRenewals\Extension\Controller\EditCliente;
use FacturaScripts\Plugins\ServiceRenewals\Extension\Controller\EditProducto;
use FacturaScripts\Plugins\ServiceRenewals\Init;
use FacturaScripts\Plugins\ServiceRenewals\Lib\ServiceRenewalsSettings;
use FacturaScripts\Plugins\ServiceRenewals\Worker\ProcessServiceRenewalsWorker;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Registro, cron y vistas embebidas usando la infraestructura del núcleo.
 *
 * @author Ernesto Serrano <info@ernesto.es>
 */
final class RenewalRuntimeTest extends TestCase
{
    public function testSettingsUpgradeKeepsUserValues(): void
    {
        $settings = new Settings();
        $existed = $settings->load('ServiceRenewals');
        $original = clone $settings;
        try {
            $settings->name = 'ServiceRenewals';
            $settings->max_attempts = '7';
            self::assertTrue($settings->save());
            $init = new Init();
            $init->init();
            $init->update();
            $init->uninstall();
            $settings->reload();
            self::assertSame('7', (string)$settings->max_attempts);
            self::assertNotNull($settings->quote_email_subject);
            Tools::settingsClear();
            self::assertSame(7, ServiceRenewalsSettings::maxAttempts());
            Tools::settingsSet('ServiceRenewals', 'default_renewal_trigger', 'invalid');
            self::assertSame('invoice', ServiceRenewalsSettings::defaultRenewalTrigger());
            Tools::settingsSet('ServiceRenewals', 'default_quote_lead_days', -3);
            self::assertSame(0, ServiceRenewalsSettings::defaultQuoteLeadDays());
            self::assertIsBool(ServiceRenewalsSettings::defaultAutoSendQuote());
            self::assertIsString(ServiceRenewalsSettings::defaultReminderDays());
        } finally {
            $existed ? $original->save() : $settings->delete();
            Tools::settingsClear();
        }
    }

    public function testCronQueuesOnlyOnePendingEvent(): void
    {
        $plugin = uniqid('ServiceRenewalsTest');
        $where = [Where::eq('name', Init::PROCESS_EVENT), Where::eq('done', false)];
        $before = array_map(static fn ($e) => $e->id, WorkEvent::all($where));
        $cron = new Cron($plugin);
        try {
            Tools::settingsSet('ServiceRenewals', 'enabled', false);
            $cron->run();
            self::assertSame(count($before), WorkEvent::count($where));
            $this->resetJob($plugin);
            Tools::settingsSet('ServiceRenewals', 'enabled', true);
            $cron->run();
            self::assertSame(max(1, count($before)), WorkEvent::count($where));
            $this->resetJob($plugin);
            $cron->run();
            self::assertSame(max(1, count($before)), WorkEvent::count($where));
        } finally {
            $this->resetJob($plugin);
            foreach (WorkEvent::all($where) as $event) {
                if (!in_array($event->id, $before, true)) {
                    $event->delete();
                }
            }
            Tools::settingsClear();
        }
    }

    public function testProcessWorkerHonorsDisabledFlagAndExplicitDate(): void
    {
        $worker = new ProcessServiceRenewalsWorker();
        $event = new WorkEvent();
        $event->value = '1900-01-01';
        try {
            Tools::settingsSet('ServiceRenewals', 'enabled', false);
            self::assertTrue($worker->run($event));
            Tools::settingsSet('ServiceRenewals', 'enabled', true);
            self::assertTrue($worker->run($event));
        } finally {
            Tools::settingsClear();
        }
    }

    public function testDashboardLoadsCountersAndTemplate(): void
    {
        $original = Session::get('user');
        $page = new class ('ServiceRenewalsDashboard') extends ServiceRenewalsDashboard {
            public string $template = '';

            public function request(): Request
            {
                return new Request();
            }

            protected function auth(): bool
            {
                $user = new User();
                $user->nick = 'admin';
                $user->admin = true;
                Session::set('user', $user);
                return true;
            }

            protected function view(string $view, array $data = []): void
            {
                $this->template = $view;
            }
        };
        try {
            self::assertSame('sales', $page->getPageData()['menu']);
            $page->run();
            self::assertSame('ServiceRenewalsDashboard.html.twig', $page->template);
            self::assertCount(8, $page->cards);
            self::assertLessThanOrEqual(10, count($page->upcoming));
            self::assertSame(date('Y-m-d'), $page->today);
        } finally {
            Session::set('user', $original);
        }
    }

    public function testExtensionsCreateAndLoadTheirViews(): void
    {
        foreach ([new EditCliente(), new EditProducto()] as $extension) {
            $page = new EditServiceRenewal('EditServiceRenewal');
            $create = new ReflectionMethod($page, 'createViews');
            $create->setAccessible(true);
            $create->invoke($page);
            $extension->createViews()->call($page);
            self::assertArrayHasKey('ListServiceRenewalSub', $page->views);
            foreach ($page->views as $name => $view) {
                $extension->loadData()->call($page, $name, $view);
            }
            self::assertCount(0, $page->views['ListServiceRenewalSub']->cursor);
            if ($extension instanceof EditProducto) {
                self::assertArrayHasKey('EditServiceRenewalProfile', $page->views);
                self::assertEmpty($page->views['EditServiceRenewalProfile']->model->idproduct);
            }
        }
    }

    private function resetJob(string $plugin): void
    {
        foreach (CronJob::all([Where::eq('pluginname', $plugin)]) as $job) {
            $job->delete();
        }
    }
}
