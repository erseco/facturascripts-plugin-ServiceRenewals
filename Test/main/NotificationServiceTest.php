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
use FacturaScripts\Core\Model\WorkEvent;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Core\WorkQueue;
use FacturaScripts\Dinamic\Model\PresupuestoCliente;
use FacturaScripts\Plugins\ServiceRenewals\Lib\NotificationService;
use FacturaScripts\Plugins\ServiceRenewals\Lib\QuoteGenerator;
use FacturaScripts\Plugins\ServiceRenewals\Lib\QuoteNotificationSender;
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
    use ServiceRenewalsFixtures;

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

    public function testCreatingTheNotificationDoesNotBuildThePdf(): void
    {
        [$renewal, $cycle, $quote] = $this->makeQuoteScenario('billing@example.com');

        $service = new NotificationService();
        $notification = $service->createQuoteNotification($renewal, $cycle, $quote);
        $this->assertNotNull($notification);
        $this->cleanup[] = $notification;

        // exportar el PDF es lo más caro del flujo y no debe ocurrir en la petición:
        // bloquea la interfaz mientras dura, y en el Playground congela la pestaña
        $this->assertSame([], $notification->getAttachments(), 'The click must not export the PDF');

        // y lo construye el worker justo antes de enviar. El motor de PDF depende
        // del autoloader del núcleo, que no siempre está completo en CI: si no
        // está disponible, basta con haber comprobado lo anterior
        if (false === class_exists('Cezpdf')) {
            return;
        }

        $this->assertTrue($service->attachQuotePdf($notification, $quote));
        $attachments = $notification->getAttachments();
        $this->assertCount(1, $attachments, 'The worker attaches the quote before sending');

        $path = $notification->getFilesFolder() . DIRECTORY_SEPARATOR . $attachments[0]['file'];
        $this->assertFileExists($path);
        $this->assertGreaterThan(0, filesize($path), 'The generated PDF must not be empty');
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

    public function testSendingTheNoticeReusesTheExistingQuote(): void
    {
        [$renewal, $cycle, $quote] = $this->makeQuoteScenario('billing@example.com');
        $quotesBefore = PresupuestoCliente::count([Where::eq('codcliente', $renewal->codcustomer)]);

        $sender = new QuoteNotificationSender();
        for ($press = 1; $press <= 3; $press++) {
            $this->assertTrue($sender->send($renewal), "Press $press must queue the notice");
        }

        // ni un presupuesto nuevo por pulsar el botón varias veces
        $this->assertSame(
            $quotesBefore,
            PresupuestoCliente::count([Where::eq('codcliente', $renewal->codcustomer)]),
            'Sending the notice must never create another quote'
        );
        $cycle->reload();
        $this->assertSame((int)$quote->idpresupuesto, (int)$cycle->quote_id, 'The cycle keeps its quote');

        // y un único aviso archivado, reutilizado en cada reenvío
        $notifications = ServiceRenewalNotification::all([Where::eq('cycle_id', $cycle->id)], [], 0, 0);
        $this->assertCount(1, $notifications, 'The notice is reused, not duplicated');
        $this->cleanup[] = $notifications[0];
    }

    public function testSendingTheNoticeGeneratesTheQuoteWhenMissing(): void
    {
        if (empty(Almacenes::all())) {
            $this->markTestSkipped('No warehouse available to create documents');
        }

        // ciclo abierto todavía sin presupuesto
        [$renewal, $cycle] = $this->makeRenewalScenario('billing@example.com');
        $this->assertEmpty($cycle->quote_id);

        $this->assertTrue((new QuoteNotificationSender())->send($renewal));

        $cycle->reload();
        $this->assertNotEmpty($cycle->quote_id, 'The quote is generated so that there is something to send');
        $quote = $cycle->getQuote();
        $this->assertNotNull($quote);
        $this->cleanup[] = $quote;

        $notifications = ServiceRenewalNotification::all([Where::eq('cycle_id', $cycle->id)], [], 0, 0);
        $this->assertCount(1, $notifications);
        $this->cleanup[] = $notifications[0];
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
        $customer = $this->makeCustomer('Mail Test Customer', $email);
        $product = $this->makeServiceProduct('Mail test product', 30.0);

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
        if (empty(Almacenes::all())) {
            $this->markTestSkipped('No warehouse available to create documents');
        }

        [$renewal, $cycle] = $this->makeRenewalScenario($email);

        $quote = (new QuoteGenerator())->generate($renewal, $cycle);
        $this->assertNotNull($quote);
        $this->cleanup[] = $quote;
        $cycle->reload();

        return [$renewal, $cycle, $quote];
    }
}
