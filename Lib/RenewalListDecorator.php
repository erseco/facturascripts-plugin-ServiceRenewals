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

/**
 * Rellena las columnas calculadas de los listados de suscripciones
 * (días restantes, etiquetas traducidas, ciclo y documentos) sin
 * persistirlas. Se usa en el listado principal y en las pestañas
 * embebidas de cliente y producto.
 *
 * @author Ernesto Serrano <info@ernesto.es>
 */
final class RenewalListDecorator
{
    /**
     * Columnas básicas: cliente, etiquetas, días restantes.
     *
     * @param ServiceRenewal[] $renewals
     */
    public static function decorate(array $renewals, string $today): void
    {
        foreach ($renewals as $renewal) {
            $renewal->days_left = $renewal->daysToExpiration($today);
            $renewal->customer_name = $renewal->getCustomer()->nombre ?? (string)$renewal->codcustomer;
            $renewal->service_type_label = Tools::lang()->trans('service-type-' . $renewal->effectiveServiceType());
            $renewal->status_label = Tools::lang()->trans('service-renewal-status-' . $renewal->status);
            if (empty($renewal->title)) {
                $renewal->title = (string)$renewal->service_identifier;
            }
        }
    }

    /**
     * Columnas completas del listado principal: añade producto, ciclo,
     * documentos e importe.
     *
     * @param ServiceRenewal[] $renewals
     */
    public static function decorateFull(array $renewals, string $today): void
    {
        self::decorate($renewals, $today);

        foreach ($renewals as $renewal) {
            $renewal->product_reference = $renewal->getProduct()->referencia ?? '-';

            $cycle = $renewal->getOpenCycle();
            $renewal->cycle_status = null !== $cycle
                ? Tools::lang()->trans('service-renewal-cycle-status-' . $cycle->status)
                : '-';

            $quote = null !== $cycle ? $cycle->getQuote() : null;
            $renewal->last_quote_code = null !== $quote ? (string)$quote->codigo : '-';

            $invoice = null !== $cycle ? $cycle->getInvoice() : null;
            $renewal->last_invoice_code = null !== $invoice ? (string)$invoice->codigo : '-';

            if (null !== $renewal->price_override) {
                $renewal->amount = (float)$renewal->price_override;
            } else {
                $product = $renewal->getProduct();
                $renewal->amount = (float)($product->precio ?? 0.0);
            }
        }
    }
}
