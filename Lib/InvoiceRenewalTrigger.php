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
use FacturaScripts\Plugins\ServiceRenewals\Model\ServiceRenewalCycle;
use Throwable;

/**
 * Aplica la renovación en cuanto el núcleo enlaza la factura con el
 * presupuesto, sin esperar a la siguiente pasada del cron.
 *
 * El cron sigue haciendo exactamente lo mismo y es la red de seguridad: si
 * la factura llega por otro camino (importación, API, un enlace que no se
 * registró) se detectará igualmente en la siguiente pasada. Por eso aquí
 * nada es obligatorio y ningún fallo se propaga: si algo va mal se registra
 * y se deja para el cron.
 *
 * @author Ernesto Serrano <info@ernesto.es>
 */
final class InvoiceRenewalTrigger
{
    /**
     * Reacciona al enlace de una factura con su documento de origen.
     *
     * @param string $model Modelo del documento destino de la transformación.
     * @param int    $docId Identificador de ese documento.
     */
    public static function onDocumentLinked(string $model, int $docId): void
    {
        if ('FacturaCliente' !== $model || $docId < 1) {
            return;
        }

        try {
            $cycle = self::findCycleForInvoice($docId);
            if (null === $cycle) {
                return;
            }

            (new RenewalProcessor())->detectAndRenew($cycle);
        } catch (Throwable $exception) {
            // no rompemos el guardado de la factura: lo recogerá el cron
            Tools::log()->error('service-renewal-process-error', [
                '%id%' => (string)$docId,
                '%error%' => $exception->getMessage(),
            ]);
        }
    }

    /** Ciclo pendiente al que pertenece la factura, si lo hay. */
    private static function findCycleForInvoice(int $invoiceId): ?ServiceRenewalCycle
    {
        $quoteId = DocumentTransformationFinder::findQuoteForInvoice($invoiceId);
        if (null === $quoteId) {
            return null;
        }

        return ServiceRenewalCycle::findWhere([
            Where::eq('quote_id', $quoteId),
            Where::in('status', [
                ServiceRenewalCycle::STATUS_QUOTE_CREATED,
                ServiceRenewalCycle::STATUS_QUOTE_SENT,
                ServiceRenewalCycle::STATUS_INVOICED,
            ]),
        ]);
    }
}
