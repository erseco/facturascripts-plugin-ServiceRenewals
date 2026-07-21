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

namespace FacturaScripts\Plugins\ServiceRenewals\Lib;

use FacturaScripts\Core\DataSrc\Empresas;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Validator;
use FacturaScripts\Core\Where;
use FacturaScripts\Core\WorkQueue;
use FacturaScripts\Dinamic\Lib\ExportManager;
use FacturaScripts\Dinamic\Model\PresupuestoCliente;
use FacturaScripts\Plugins\ServiceRenewals\Model\ServiceRenewal;
use FacturaScripts\Plugins\ServiceRenewals\Model\ServiceRenewalCycle;
use FacturaScripts\Plugins\ServiceRenewals\Model\ServiceRenewalNotification;
use Throwable;

/**
 * Creación y encolado de notificaciones de renovación.
 *
 * La notificación se persiste siempre antes de encolar el envío, y el PDF
 * del presupuesto se guarda en la carpeta de la notificación hasta que el
 * email se ha enviado correctamente. La restricción única de la tabla
 * impide crear avisos duplicados.
 *
 * @author Ernesto Serrano <info@ernesto.es>
 */
final class NotificationService
{
    /**
     * Crea (o devuelve) la notificación del presupuesto del ciclo y encola su envío.
     */
    public function createQuoteNotification(
        ServiceRenewal $renewal,
        ServiceRenewalCycle $cycle,
        PresupuestoCliente $quote
    ): ?ServiceRenewalNotification {
        $existing = $this->find($cycle->id, ServiceRenewalNotification::TYPE_QUOTE, 0);
        if (null !== $existing) {
            return $existing;
        }

        $placeholders = $this->buildPlaceholders($renewal, $cycle, $quote);
        $notification = $this->buildNotification($renewal, $cycle->id, ServiceRenewalNotification::TYPE_QUOTE, 0);
        $notification->subject = $this->renderTemplate(ServiceRenewalsSettings::quoteEmailSubject(), $placeholders);
        $notification->body = $this->renderTemplate(ServiceRenewalsSettings::quoteEmailBody(), $placeholders);

        if (false === $notification->save()) {
            return null;
        }

        $this->attachQuotePdf($notification, $quote);

        if (empty($notification->recipient)) {
            $this->markFailed($notification, 'No valid recipient email');
            return $notification;
        }

        $this->enqueue($notification);

        return $notification;
    }

    /**
     * Crea (o devuelve) el recordatorio de la regla de días indicada y encola su envío.
     */
    public function createReminderNotification(
        ServiceRenewal $renewal,
        ServiceRenewalCycle $cycle,
        int $reminderDay
    ): ?ServiceRenewalNotification {
        $existing = $this->find($cycle->id, ServiceRenewalNotification::TYPE_REMINDER, $reminderDay);
        if (null !== $existing) {
            return $existing;
        }

        $placeholders = $this->buildPlaceholders($renewal, $cycle, $cycle->getQuote());
        $notification = $this->buildNotification(
            $renewal,
            $cycle->id,
            ServiceRenewalNotification::TYPE_REMINDER,
            $reminderDay
        );
        $notification->subject = $this->renderTemplate(ServiceRenewalsSettings::reminderEmailSubject(), $placeholders);
        $notification->body = $this->renderTemplate(ServiceRenewalsSettings::reminderEmailBody(), $placeholders);

        if (false === $notification->save()) {
            return null;
        }

        if (empty($notification->recipient)) {
            $this->markFailed($notification, 'No valid recipient email');
            return $notification;
        }

        $this->enqueue($notification);

        return $notification;
    }

    /**
     * Encola el envío en la cola de trabajos. Si no hay worker disponible
     * la notificación queda marcada como fallida.
     */
    public function enqueue(ServiceRenewalNotification $notification): bool
    {
        $sent = WorkQueue::send(
            \FacturaScripts\Plugins\ServiceRenewals\Init::MAIL_EVENT,
            (string)$notification->id,
            ['id' => $notification->id]
        );
        if (false === $sent) {
            $this->markFailed($notification, 'Could not queue the notification. Is a worker available?');
            return false;
        }

        return true;
    }

    /**
     * Email de destino: email_override de la suscripción o, en su defecto,
     * el email de facturación del cliente.
     */
    public function resolveRecipient(ServiceRenewal $renewal): string
    {
        if (!empty($renewal->email_override) && Validator::email($renewal->email_override)) {
            return $renewal->email_override;
        }

        $customer = $renewal->getCustomer();
        if (!empty($customer->email) && Validator::email($customer->email)) {
            return $customer->email;
        }

        $billing = $customer->getDefaultAddress('billing');
        if (!empty($billing->email) && Validator::email($billing->email)) {
            return $billing->email;
        }

        return '';
    }

    /**
     * Genera el PDF del presupuesto y lo guarda como adjunto de la notificación.
     */
    public function attachQuotePdf(ServiceRenewalNotification $notification, PresupuestoCliente $quote): bool
    {
        try {
            $exporter = new ExportManager();
            $exporter->newDoc('PDF', (string)$quote->codigo);
            $exporter->addBusinessDocPage($quote);
            $content = $exporter->getDoc();
            if (empty($content)) {
                return false;
            }

            $folder = $notification->getFilesFolder();
            if (false === Tools::folderCheckOrCreate($folder)) {
                return false;
            }

            $fileName = 'quote-' . $notification->id . '.pdf';
            if (false === file_put_contents($folder . DIRECTORY_SEPARATOR . $fileName, $content)) {
                return false;
            }

            $notification->setAttachments([
                ['file' => $fileName, 'name' => ($quote->codigo ?? 'quote') . '.pdf'],
            ]);

            return $notification->save();
        } catch (Throwable $exception) {
            Tools::log()->error('service-renewal-pdf-failed', [
                '%id%' => (string)$notification->id,
                '%error%' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Marcadores disponibles en las plantillas de email.
     *
     * @return array<string, string>
     */
    public function buildPlaceholders(
        ServiceRenewal $renewal,
        ServiceRenewalCycle $cycle,
        ?PresupuestoCliente $quote = null
    ): array {
        $customer = $renewal->getCustomer();
        $company = Empresas::default();

        return [
            'client_name' => (string)($customer->razonsocial ?? '') !== ''
                ? (string)$customer->razonsocial
                : (string)($customer->nombre ?? ''),
            'service_title' => !empty($renewal->title) ? (string)$renewal->title : (string)$renewal->service_identifier,
            'service_identifier' => (string)$renewal->service_identifier,
            'service_type' => Tools::lang()->trans('service-type-' . $renewal->effectiveServiceType()),
            'provider_name' => (string)($renewal->provider_name ?? ''),
            'expiration_date' => Tools::date($cycle->previous_expiration_date),
            'next_expiration_date' => Tools::date($cycle->next_expiration_date),
            'quote_code' => null !== $quote ? (string)($quote->codigo ?? '') : '',
            'quote_total' => null !== $quote ? Tools::money($quote->total ?? 0.0) : '',
            'company_name' => (string)($company->nombre ?? ''),
        ];
    }

    private function buildNotification(
        ServiceRenewal $renewal,
        int $cycleId,
        string $type,
        int $reminderDay
    ): ServiceRenewalNotification {
        $notification = new ServiceRenewalNotification();
        $notification->cycle_id = $cycleId;
        $notification->notification_type = $type;
        $notification->reminder_day = $reminderDay;
        $notification->recipient = $this->resolveRecipient($renewal);
        $notification->cc = ServiceRenewalsSettings::globalCc();
        $notification->bcc = ServiceRenewalsSettings::globalBcc();
        $notification->scheduled_at = Tools::dateTime();

        return $notification;
    }

    private function find(int $cycleId, string $type, int $reminderDay): ?ServiceRenewalNotification
    {
        return ServiceRenewalNotification::findWhere([
            Where::eq('cycle_id', $cycleId),
            Where::eq('notification_type', $type),
            Where::eq('reminder_day', $reminderDay),
        ]);
    }

    private function markFailed(ServiceRenewalNotification $notification, string $error): void
    {
        $notification->status = ServiceRenewalNotification::STATUS_FAILED;
        $notification->last_error = $error;
        $notification->save();
        Tools::log()->error('service-renewal-notification-failed', [
            '%id%' => (string)$notification->id,
            '%error%' => $error,
        ]);
    }

    /**
     * Renderiza una plantilla avisando de los marcadores desconocidos,
     * que se conservan visibles en el texto.
     */
    private function renderTemplate(string $template, array $placeholders): string
    {
        $result = TemplateRenderer::render($template, $placeholders);
        foreach ($result->unknownPlaceholders as $unknown) {
            Tools::log()->warning('service-renewal-unknown-placeholder', ['%placeholder%' => $unknown]);
        }

        return $result->text;
    }
}
