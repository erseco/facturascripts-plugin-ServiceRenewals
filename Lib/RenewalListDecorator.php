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

            // con la política «invoice» el ciclo pasa a «renewed» en la misma pasada del
            // cron que detecta la factura, y deja de ser el ciclo abierto: si solo
            // miráramos ese, las columnas se vaciarían justo después de renovar
            $cycle = $renewal->getOpenCycle() ?? $renewal->getLastCycle();
            $renewal->cycle_status = null !== $cycle
                ? Tools::lang()->trans('service-renewal-cycle-status-' . $cycle->status)
                : null;

            // los identificadores permiten enlazar el documento desde el listado;
            // sin código dejamos null para no generar un enlace roto
            $quote = $renewal->getLastQuote();
            $renewal->last_quote_code = null !== $quote ? (string)$quote->codigo : null;
            $renewal->last_quote_id = null !== $quote ? $quote->idpresupuesto : null;

            $invoice = $renewal->getLastInvoice();
            $renewal->last_invoice_code = null !== $invoice ? (string)$invoice->codigo : null;
            $renewal->last_invoice_id = null !== $invoice ? $invoice->idfactura : null;
            $renewal->invoice_status = self::invoiceStatus($invoice, $today);
            $renewal->invoice_status_label = null !== $renewal->invoice_status
                ? Tools::lang()->trans('invoice-' . $renewal->invoice_status)
                : null;

            if (null !== $renewal->price_override) {
                $renewal->amount = (float)$renewal->price_override;
            } else {
                $product = $renewal->getProduct();
                $renewal->amount = (float)($product->precio ?? 0.0);
            }
        }
    }

    /**
     * Estado de cobro de la factura: `paid`, `overdue` o `issued`.
     *
     * Devuelve null cuando todavía no hay factura, para que la fila no se
     * coloree ni muestre etiqueta.
     *
     * No se usa el campo `vencida` del núcleo porque también marca como
     * vencidas las facturas cuyo recibo vence hoy: con forma de pago al
     * contado, una factura recién emitida saldría en rojo el mismo día. Aquí
     * solo se considera vencida cuando la fecha de cobro ya ha pasado.
     *
     * @param FacturaCliente|null $invoice
     */
    private static function invoiceStatus($invoice, string $today): ?string
    {
        if (null === $invoice) {
            return null;
        }

        if (!empty($invoice->pagada)) {
            return 'paid';
        }

        foreach ($invoice->getReceipts() as $receipt) {
            $due = RenewalDateCalculator::toIso($receipt->vencimiento);
            if (empty($receipt->pagado) && null !== $due && $due < $today) {
                return 'overdue';
            }
        }

        return 'issued';
    }
}
