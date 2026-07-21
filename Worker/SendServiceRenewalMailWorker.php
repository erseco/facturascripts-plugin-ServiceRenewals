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

namespace FacturaScripts\Plugins\ServiceRenewals\Worker;

use FacturaScripts\Core\Model\WorkEvent;
use FacturaScripts\Core\Template\WorkerClass;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Lib\Email\NewMail;
use FacturaScripts\Plugins\ServiceRenewals\Lib\NotificationService;
use FacturaScripts\Plugins\ServiceRenewals\Lib\ServiceRenewalsSettings;
use FacturaScripts\Plugins\ServiceRenewals\Model\ServiceRenewalCycle;
use FacturaScripts\Plugins\ServiceRenewals\Model\ServiceRenewalNotification;
use Throwable;

/**
 * Worker que envía por email una notificación de renovación.
 *
 * Reconstruye el email desde el registro persistido, adjunta los archivos
 * conservados en disco y solo marca la notificación como enviada tras una
 * entrega correcta. En caso de fallo conserva los adjuntos y registra el
 * error para permitir reintentos hasta el máximo configurado.
 *
 * @author Ernesto Serrano <info@ernesto.es>
 */
class SendServiceRenewalMailWorker extends WorkerClass
{
    public function run(WorkEvent $event): bool
    {
        $id = (int)($event->param('id') ?? $event->value);
        $notification = ServiceRenewalNotification::find($id);
        if (null === $notification) {
            return $this->done();
        }

        // solo se envían notificaciones pendientes o fallidas con reintentos disponibles
        $retryable = ServiceRenewalNotification::STATUS_FAILED === $notification->status
            && (int)$notification->attempts < ServiceRenewalsSettings::maxAttempts();
        if (ServiceRenewalNotification::STATUS_PENDING !== $notification->status && false === $retryable) {
            return $this->done();
        }

        try {
            if ($this->deliver($notification)) {
                $this->markSent($notification);
            } else {
                $this->markFailed($notification, 'NewMail::send() returned false. Check the SMTP configuration.');
            }
        } catch (Throwable $exception) {
            $this->markFailed($notification, $exception->getMessage());
        }

        return $this->done();
    }

    private function deliver(ServiceRenewalNotification $notification): bool
    {
        if (empty($notification->recipient)) {
            throw new \RuntimeException('No valid recipient email');
        }

        $mail = NewMail::create();

        $fromEmail = ServiceRenewalsSettings::fromEmail();
        if ('' !== $fromEmail) {
            $mail->setMailbox($fromEmail);
        }

        foreach (NewMail::splitEmails((string)$notification->recipient) as $email) {
            $mail->to($email);
        }
        foreach (NewMail::splitEmails((string)$notification->cc) as $email) {
            $mail->cc($email);
        }
        foreach (NewMail::splitEmails((string)$notification->bcc) as $email) {
            $mail->bcc($email);
        }

        $mail->subject((string)$notification->subject);

        // las plantillas son de texto plano: escapamos y convertimos los saltos de línea
        $body = nl2br(htmlspecialchars((string)$notification->body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        $mail->body($body);

        // si es la notificación del presupuesto y falta el PDF, lo regeneramos
        $this->ensureQuoteAttachment($notification);

        $folder = $notification->getFilesFolder();
        foreach ($notification->getAttachments() as $attachment) {
            $path = $folder . DIRECTORY_SEPARATOR . basename((string)$attachment['file']);
            if (file_exists($path)) {
                $mail->addAttachment($path, (string)$attachment['name']);
            }
        }

        if (false === $mail->canSendMail()) {
            throw new \RuntimeException('Email is not configured. Check the SMTP settings.');
        }

        return $mail->send();
    }

    /** Regenera el PDF del presupuesto si la notificación no lo conserva. */
    private function ensureQuoteAttachment(ServiceRenewalNotification $notification): void
    {
        if (ServiceRenewalNotification::TYPE_QUOTE !== $notification->notification_type) {
            return;
        }
        if (false === empty($notification->getAttachments())) {
            return;
        }

        $cycle = $notification->getCycle();
        $quote = $cycle->getQuote();
        if (null !== $quote) {
            (new NotificationService())->attachQuotePdf($notification, $quote);
        }
    }

    private function markSent(ServiceRenewalNotification $notification): void
    {
        $notification->status = ServiceRenewalNotification::STATUS_SENT;
        $notification->sent_at = Tools::dateTime();
        $notification->last_error = null;
        $notification->save();

        // eliminamos los adjuntos temporales tras el envío correcto
        $notification->deleteFiles();

        // reflejamos el envío en el ciclo cuando es el email del presupuesto
        if (ServiceRenewalNotification::TYPE_QUOTE === $notification->notification_type) {
            $cycle = $notification->getCycle();
            if (!empty($cycle->id)) {
                $cycle->quote_sent_at = Tools::dateTime();
                if (ServiceRenewalCycle::STATUS_QUOTE_CREATED === $cycle->status) {
                    $cycle->status = ServiceRenewalCycle::STATUS_QUOTE_SENT;
                }
                $cycle->save();
            }
        }
    }

    private function markFailed(ServiceRenewalNotification $notification, string $error): void
    {
        $notification->attempts = (int)$notification->attempts + 1;
        $notification->status = ServiceRenewalNotification::STATUS_FAILED;
        $notification->last_error = $error;
        $notification->save();

        Tools::log()->error('service-renewal-notification-failed', [
            '%id%' => (string)$notification->id,
            '%error%' => $error,
        ]);
    }
}
