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

use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Plugins\ServiceRenewals\Model\ServiceRenewal;
use FacturaScripts\Plugins\ServiceRenewals\Model\ServiceRenewalCycle;
use FacturaScripts\Plugins\ServiceRenewals\Model\ServiceRenewalNotification;
use Throwable;

/**
 * Orquestador del procesamiento periódico de renovaciones.
 *
 * Cada suscripción se procesa de forma aislada: un error en una no impide
 * procesar las demás. Todas las operaciones son idempotentes, por lo que
 * el cron puede ejecutarse repetidamente sin crear duplicados.
 *
 * @author Ernesto Serrano <info@ernesto.es>
 */
final class RenewalProcessor
{
    /** @var RenewalCycleService */
    private $cycleService;

    /** @var QuoteGenerator */
    private $quoteGenerator;

    /** @var NotificationService */
    private $notificationService;

    public function __construct()
    {
        $this->cycleService = new RenewalCycleService();
        $this->quoteGenerator = new QuoteGenerator();
        $this->notificationService = new NotificationService();
    }

    /**
     * Procesa las renovaciones en la fecha indicada (Y-m-d o d-m-Y).
     *
     * @return array<string, int> contadores del procesamiento
     */
    public function process(string $today): array
    {
        $stats = [
            'cycles' => 0,
            'quotes' => 0,
            'quote_notifications' => 0,
            'reminders' => 0,
            'invoices_detected' => 0,
            'renewals_applied' => 0,
            'retried_notifications' => 0,
            'errors' => 0,
        ];

        $isoToday = RenewalDateCalculator::toIso($today);
        if (null === $isoToday) {
            $stats['errors']++;
            return $stats;
        }

        foreach (RenewalScanner::findDue($isoToday) as $renewal) {
            try {
                $this->processDueRenewal($renewal, $isoToday, $stats);
            } catch (Throwable $exception) {
                $stats['errors']++;
                Tools::log()->error('service-renewal-process-error', [
                    '%id%' => (string)$renewal->id,
                    '%error%' => $exception->getMessage(),
                ]);
            }
        }

        $this->detectInvoicesAndRenew($stats);
        $this->retryFailedNotifications($stats);

        return $stats;
    }

    /**
     * Abre el ciclo, genera el presupuesto y crea las notificaciones de una
     * suscripción activa dentro del umbral.
     */
    private function processDueRenewal(ServiceRenewal $renewal, string $today, array &$stats): void
    {
        $cycle = $this->cycleService->getOrCreate($renewal);
        if (null === $cycle) {
            $stats['errors']++;
            Tools::log()->error('service-renewal-cycle-failed', ['%id%' => (string)$renewal->id]);
            return;
        }
        if (false === in_array($cycle->status, ServiceRenewalCycle::OPEN_STATUSES, true)) {
            return;
        }
        $stats['cycles']++;

        // presupuesto: como máximo uno por ciclo
        if (empty($cycle->quote_id) && $renewal->effectiveAutoGenerateQuote()) {
            $quote = $this->quoteGenerator->generate($renewal, $cycle);
            if (null !== $quote) {
                $stats['quotes']++;
            }
        }

        // email del presupuesto
        if (!empty($cycle->quote_id) && $renewal->effectiveAutoSendQuote()) {
            $quote = $cycle->getQuote();
            if (null !== $quote) {
                $notification = $this->notificationService->createQuoteNotification($renewal, $cycle, $quote);
                if (null !== $notification) {
                    $stats['quote_notifications']++;
                }
            }
        }

        // recordatorios: solo si el ciclo sigue sin facturar ni renovar
        $blockedStatuses = [
            ServiceRenewalCycle::STATUS_INVOICED,
            ServiceRenewalCycle::STATUS_RENEWAL_PENDING,
            ServiceRenewalCycle::STATUS_RENEWED,
        ];
        if (in_array($cycle->status, $blockedStatuses, true)) {
            return;
        }

        $daysLeft = $renewal->daysToExpiration($today);
        foreach ($renewal->effectiveReminderDays() as $reminderDay) {
            if ($daysLeft === $reminderDay) {
                $notification = $this->notificationService->createReminderNotification($renewal, $cycle, $reminderDay);
                if (null !== $notification) {
                    $stats['reminders']++;
                }
            }
        }
    }

    /**
     * Detecta presupuestos transformados en factura y aplica la renovación
     * según la política efectiva de cada suscripción.
     */
    private function detectInvoicesAndRenew(array &$stats): void
    {
        $cycles = ServiceRenewalCycle::all([
            Where::in('status', [
                ServiceRenewalCycle::STATUS_QUOTE_CREATED,
                ServiceRenewalCycle::STATUS_QUOTE_SENT,
                ServiceRenewalCycle::STATUS_INVOICED,
            ]),
        ]);

        foreach ($cycles as $cycle) {
            try {
                $this->detectAndRenewCycle($cycle, $stats);
            } catch (Throwable $exception) {
                $stats['errors']++;
                Tools::log()->error('service-renewal-process-error', [
                    '%id%' => (string)$cycle->service_renewal_id,
                    '%error%' => $exception->getMessage(),
                ]);
            }
        }
    }

    private function detectAndRenewCycle(ServiceRenewalCycle $cycle, array &$stats): void
    {
        $renewal = $cycle->getRenewal();
        $skipStatuses = [ServiceRenewal::STATUS_CANCELLED, ServiceRenewal::STATUS_SUSPENDED];
        if (empty($renewal->id) || in_array($renewal->status, $skipStatuses, true)) {
            return;
        }

        // detección de la factura
        if (empty($cycle->invoice_id) && !empty($cycle->quote_id)) {
            $invoiceId = DocumentTransformationFinder::findInvoiceForQuote((int)$cycle->quote_id);
            if (null === $invoiceId) {
                return;
            }

            $cycle->invoice_id = $invoiceId;
            $cycle->invoice_detected_at = Tools::dateTime();
            $cycle->status = ServiceRenewalCycle::STATUS_INVOICED;
            if (false === $cycle->save()) {
                $stats['errors']++;
                return;
            }
            $stats['invoices_detected']++;
        }

        if (ServiceRenewalCycle::STATUS_INVOICED !== $cycle->status) {
            return;
        }

        // renovación según la política efectiva
        if ('invoice' === $renewal->effectiveRenewalTrigger()) {
            if ($this->cycleService->applyRenewal($cycle)) {
                $stats['renewals_applied']++;
            } else {
                $stats['errors']++;
            }
            return;
        }

        // política manual: queda pendiente de confirmación
        $cycle->status = ServiceRenewalCycle::STATUS_RENEWAL_PENDING;
        if (false === $cycle->save()) {
            $stats['errors']++;
        }
    }

    /**
     * Reencola notificaciones fallidas que aún no han agotado los reintentos.
     */
    private function retryFailedNotifications(array &$stats): void
    {
        $maxAttempts = ServiceRenewalsSettings::maxAttempts();
        $failed = ServiceRenewalNotification::all([
            Where::eq('status', ServiceRenewalNotification::STATUS_FAILED),
            Where::lt('attempts', $maxAttempts),
        ]);

        foreach ($failed as $notification) {
            if (empty($notification->recipient)) {
                continue;
            }
            if ($this->notificationService->enqueue($notification)) {
                $stats['retried_notifications']++;
            }
        }
    }
}
