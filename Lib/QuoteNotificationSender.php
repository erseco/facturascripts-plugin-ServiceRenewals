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
use FacturaScripts\Plugins\ServiceRenewals\Model\ServiceRenewal;
use FacturaScripts\Plugins\ServiceRenewals\Model\ServiceRenewalCycle;
use FacturaScripts\Plugins\ServiceRenewals\Model\ServiceRenewalNotification;

/**
 * Envío y reenvío manual del email del presupuesto desde la ficha.
 *
 * Vive fuera del controlador para poder probarse. Reutiliza siempre lo que
 * ya existe: el presupuesto del ciclo y su aviso. Solo genera presupuesto
 * cuando el ciclo abierto aún no tiene ninguno, y nunca abre el ciclo del
 * periodo siguiente: eso es trabajo del botón «Generar presupuesto».
 *
 * @author Ernesto Serrano <info@ernesto.es>
 */
final class QuoteNotificationSender
{
    /** Envía o reenvía el presupuesto de la suscripción. */
    public function send(ServiceRenewal $renewal): bool
    {
        $blocked = [ServiceRenewal::STATUS_CANCELLED, ServiceRenewal::STATUS_SUSPENDED];
        if (in_array($renewal->status, $blocked, true)) {
            Tools::log()->warning('service-renewal-cancelled-no-actions');
            return false;
        }

        $cycle = $this->resolveCycle($renewal);
        $quote = null !== $cycle ? $cycle->getQuote() : null;
        if (null === $cycle || null === $quote) {
            Tools::log()->warning('service-renewal-no-quote-to-send');
            return false;
        }

        $service = new NotificationService();
        $notification = $service->createQuoteNotification($renewal, $cycle, $quote);
        if (null === $notification) {
            Tools::log()->error('service-renewal-notification-error');
            return false;
        }

        // reenvío: se reutiliza el aviso ya archivado, sin crear otro
        if (ServiceRenewalNotification::STATUS_SENT === $notification->status) {
            $notification->status = ServiceRenewalNotification::STATUS_PENDING;
            $notification->attempts = 0;
            $notification->sent_at = null;
            $notification->save();
        }

        if (empty($notification->recipient)) {
            Tools::log()->error('service-renewal-notification-error');
            return false;
        }

        if (false === $service->enqueue($notification)) {
            return false;
        }

        Tools::log()->notice('service-renewal-notification-queued');

        return true;
    }

    /**
     * Ciclo cuyo presupuesto se envía.
     *
     * Prevalece el ciclo abierto, generando su presupuesto si aún no lo
     * tiene. Si la suscripción ya se renovó no queda ciclo abierto, y
     * entonces se reenvía el del último ciclo que llegó a generarlo.
     */
    private function resolveCycle(ServiceRenewal $renewal): ?ServiceRenewalCycle
    {
        $cycle = $renewal->getOpenCycle();
        if (null === $cycle) {
            return $renewal->getLastCycleWithQuote();
        }

        if (empty($cycle->quote_id)) {
            $quote = (new QuoteGenerator())->generate($renewal, $cycle);
            if (null !== $quote) {
                Tools::log()->notice('service-renewal-quote-generated', ['%code%' => (string)$quote->codigo]);
                $cycle->reload();
            }
        }

        return empty($cycle->quote_id) ? $renewal->getLastCycleWithQuote() : $cycle;
    }
}
