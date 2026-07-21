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
use FacturaScripts\Core\Model\WorkEvent;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Core\WorkQueue;
use FacturaScripts\Dinamic\Model\Cliente;
use FacturaScripts\Dinamic\Model\Producto;
use FacturaScripts\Plugins\ServiceRenewals\Lib\NotificationService;
use FacturaScripts\Plugins\ServiceRenewals\Lib\QuoteGenerator;
use FacturaScripts\Plugins\ServiceRenewals\Lib\RenewalCycleService;
use FacturaScripts\Plugins\ServiceRenewals\Model\ServiceRenewal;
use FacturaScripts\Plugins\ServiceRenewals\Model\ServiceRenewalNotification;
use FacturaScripts\Plugins\ServiceRenewals\Worker\SendServiceRenewalMailWorker;
use PHPUnit\Framework\TestCase;

/**
 * Tests de las notificaciones: persistencia, deduplicación y fallos de envío.
 * No necesitan un servidor SMTP real.
 *
 * @author Ernesto Serrano <info@ernesto.es>
 */
final class NotificationServiceTest extends TestCase
{
    /** @var object[] */
    private $cleanup = [];

    protected function setUp(): void
    {
        if (empty(Empresas::default()->idempresa)) {
            $this->markTestSkipped('Core default data is not installed');
        }

        // registramos el worker igual que hace Init::init() para poder encolar
        WorkQueue::addWorker('SendServiceRenewalMailWorker', 'ServiceRenewals.SendNotification');
    }

    protected function tearDown(): void
    {
        // borramos en orden inverso de creación para respetar las claves foráneas
        foreach (array_reverse($this->cleanup) as $model) {
            $model->delete();
        }
        $this->cleanup = [];

        // restauramos la configuración en memoria
        Tools::settingsClear();
    }

    public function testQuoteNotificationIsPersistedWithCustomerEmailAndTemplates(): void
    {
        [$renewal, $cycle, $quote] = $this->makeQuoteScenario('billing@example.com');

        $notification = (new NotificationService())->createQuoteNotification($renewal, $cycle, $quote);
        $this->assertNotNull($notification);
        $this->cleanup[] = $notification;

        $this->assertNotEmpty($notification->id, 'The notification must be persisted before queueing');
        $this->assertSame('billing@example.com', $notification->recipient);
        $this->assertStringContainsString($renewal->service_identifier, (string)$notification->subject);
        $this->assertStringNotContainsString('{{', (string)$notification->subject, 'No placeholder may survive');
    }

    public function testEmailOverrideTakesPriority(): void
    {
        [$renewal, $cycle, $quote] = $this->makeQuoteScenario('billing@example.com');
        $renewal->email_override = 'override@example.org';
        $this->assertTrue($renewal->save());

        $notification = (new NotificationService())->createQuoteNotification($renewal, $cycle, $quote);
        $this->assertNotNull($notification);
        $this->cleanup[] = $notification;

        $this->assertSame('override@example.org', $notification->recipient);
    }

    public function testDoesNotDuplicateNotifications(): void
    {
        [$renewal, $cycle, $quote] = $this->makeQuoteScenario('billing@example.com');
        $service = new NotificationService();

        $first = $service->createQuoteNotification($renewal, $cycle, $quote);
        $this->assertNotNull($first);
        $this->cleanup[] = $first;

        $second = $service->createQuoteNotification($renewal, $cycle, $quote);
        $this->assertNotNull($second);
        $this->assertSame((int)$first->id, (int)$second->id, 'The same notification must be reused');

        $count = ServiceRenewalNotification::count([Where::eq('cycle_id', $cycle->id)]);
        $this->assertSame(1, $count);
    }

    public function testReminderDeduplicationPerDayRule(): void
    {
        [$renewal, $cycle] = $this->makeRenewalScenario('billing@example.com');
        $service = new NotificationService();

        $first = $service->createReminderNotification($renewal, $cycle, 7);
        $this->assertNotNull($first);
        $this->cleanup[] = $first;

        $repeat = $service->createReminderNotification($renewal, $cycle, 7);
        $this->assertNotNull($repeat);
        $this->assertSame((int)$first->id, (int)$repeat->id);

        $other = $service->createReminderNotification($renewal, $cycle, 3);
        $this->assertNotNull($other);
        $this->cleanup[] = $other;
        $this->assertNotSame((int)$first->id, (int)$other->id, 'A different day rule creates a different reminder');
    }

    public function testNotificationFailsWhenCustomerHasNoEmail(): void
    {
        [$renewal, $cycle] = $this->makeRenewalScenario('');

        $notification = (new NotificationService())->createReminderNotification($renewal, $cycle, 7);
        $this->assertNotNull($notification);
        $this->cleanup[] = $notification;

        $this->assertSame(ServiceRenewalNotification::STATUS_FAILED, $notification->status);
        $this->assertNotEmpty($notification->last_error);
    }

    public function testSendWorkerMarksFailureAndKeepsError(): void
    {
        [$renewal, $cycle] = $this->makeRenewalScenario('billing@example.com');

        $notification = (new NotificationService())->createReminderNotification($renewal, $cycle, 7);
        $this->assertNotNull($notification);
        $this->cleanup[] = $notification;
        $this->assertSame(ServiceRenewalNotification::STATUS_PENDING, $notification->status);

        // rompemos la configuración SMTP solo en memoria (sin SMTP real)
        Tools::settingsSet('email', 'email', 'sender@example.com');
        Tools::settingsSet('email', 'mailer', 'smtp');
        Tools::settingsSet('email', 'host', '127.0.0.1');
        Tools::settingsSet('email', 'port', '9');
        Tools::settingsSet('email', 'user', '');
        Tools::settingsSet('email', 'password', 'wrong');
        Tools::settingsSet('email', 'enc', '');
        Tools::settingsSet('email', 'lowsecure', '1');

        $event = new WorkEvent();
        $event->name = 'ServiceRenewals.SendNotification';
        $event->value = (string)$notification->id;
        $event->setParams(['id' => $notification->id]);

        $worker = new SendServiceRenewalMailWorker();
        $this->assertTrue($worker->run($event));

        $notification->reload();
        $this->assertSame(ServiceRenewalNotification::STATUS_FAILED, $notification->status);
        $this->assertSame(1, (int)$notification->attempts);
        $this->assertNotEmpty($notification->last_error);
    }

    public function testSendWorkerRespectsMaxAttempts(): void
    {
        [$renewal, $cycle] = $this->makeRenewalScenario('billing@example.com');

        $notification = (new NotificationService())->createReminderNotification($renewal, $cycle, 7);
        $this->assertNotNull($notification);
        $this->cleanup[] = $notification;

        $notification->status = ServiceRenewalNotification::STATUS_FAILED;
        $notification->attempts = 99;
        $this->assertTrue($notification->save());

        $event = new WorkEvent();
        $event->name = 'ServiceRenewals.SendNotification';
        $event->value = (string)$notification->id;
        $event->setParams(['id' => $notification->id]);

        (new SendServiceRenewalMailWorker())->run($event);

        $notification->reload();
        $this->assertSame(99, (int)$notification->attempts, 'No more attempts allowed past the maximum');
    }

    /** @return array{0: ServiceRenewal, 1: \FacturaScripts\Plugins\ServiceRenewals\Model\ServiceRenewalCycle} */
    private function makeRenewalScenario(string $email): array
    {
        $customer = new Cliente();
        $customer->nombre = 'Mail Test Customer ' . substr(uniqid('', true), -4);
        $customer->cifnif = substr(uniqid('', true), -9);
        $customer->email = $email;
        $this->assertTrue($customer->save());
        $this->cleanup[] = $customer;

        $product = new Producto();
        $product->referencia = 'SRV-' . substr(uniqid('', true), -8);
        $product->descripcion = 'Mail test product';
        $product->precio = 30.0;
        $this->assertTrue($product->save());
        $this->cleanup[] = $product;

        $renewal = new ServiceRenewal();
        $renewal->codcustomer = $customer->codcliente;
        $renewal->idproduct = $product->idproducto;
        $renewal->service_identifier = 'mail-' . substr(uniqid('', true), -8) . '.example.com';
        $renewal->expiration_date = '2026-09-15';
        $renewal->period_months = 12;
        $this->assertTrue($renewal->save());
        $this->cleanup[] = $renewal;

        $cycle = (new RenewalCycleService())->getOrCreate($renewal);
        $this->assertNotNull($cycle);
        $this->cleanup[] = $cycle;

        return [$renewal, $cycle];
    }

    /** @return array{0: ServiceRenewal, 1: \FacturaScripts\Plugins\ServiceRenewals\Model\ServiceRenewalCycle, 2: \FacturaScripts\Dinamic\Model\PresupuestoCliente} */
    private function makeQuoteScenario(string $email): array
    {
        [$renewal, $cycle] = $this->makeRenewalScenario($email);

        $quote = (new QuoteGenerator())->generate($renewal, $cycle);
        $this->assertNotNull($quote);
        $this->cleanup[] = $quote;
        $cycle->reload();

        return [$renewal, $cycle, $quote];
    }
}
