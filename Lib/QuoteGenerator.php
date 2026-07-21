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

use DateTimeImmutable;
use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\DataSrc\Almacenes;
use FacturaScripts\Core\DataSrc\Series;
use FacturaScripts\Core\Lib\Calculator;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\LineaPresupuestoCliente;
use FacturaScripts\Dinamic\Model\PresupuestoCliente;
use FacturaScripts\Plugins\ServiceRenewals\Model\ServiceRenewal;
use FacturaScripts\Plugins\ServiceRenewals\Model\ServiceRenewalCycle;
use Throwable;

/**
 * Generación del presupuesto de renovación de un ciclo.
 *
 * Usa las APIs del núcleo (PresupuestoCliente + Calculator); nunca escribe
 * directamente en las tablas de presupuestos. Cada ciclo genera como máximo
 * un presupuesto: si el ciclo ya tiene uno, se devuelve el existente.
 *
 * @author Ernesto Serrano <info@ernesto.es>
 */
final class QuoteGenerator
{
    /**
     * Genera el presupuesto del ciclo, o devuelve el existente.
     * En caso de error deja el ciclo en estado failed con el error registrado.
     */
    public function generate(ServiceRenewal $renewal, ServiceRenewalCycle $cycle): ?PresupuestoCliente
    {
        if (!empty($cycle->quote_id)) {
            return $cycle->getQuote();
        }

        $customer = $renewal->getCustomer();
        $product = $renewal->getProduct();
        if (false === $customer->exists() || false === $product->exists()) {
            $this->markFailed($cycle, 'Customer or product not found');
            return null;
        }

        // fuera de la transacción: fuerza la creación de las tablas de
        // documentos que falten (no se pueden crear tablas en transacción)
        new PresupuestoCliente();
        new LineaPresupuestoCliente();

        $db = new DataBase();
        $db->beginTransaction();

        try {
            $quote = new PresupuestoCliente();
            if (false === $quote->setSubject($customer)) {
                throw new \RuntimeException('Could not assign the customer to the quote');
            }

            // en contexto CLI (cron/tests) los valores predeterminados pueden faltar
            if (empty($quote->codalmacen)) {
                $quote->codalmacen = Almacenes::default()->codalmacen;
            }
            if (empty($quote->codalmacen)) {
                foreach (Almacenes::all() as $warehouse) {
                    $quote->codalmacen = $warehouse->codalmacen;
                    break;
                }
            }
            if (empty($quote->codserie)) {
                $quote->codserie = Series::default()->codserie;
            }

            if (false === $quote->save()) {
                throw new \RuntimeException('Could not save the quote');
            }

            $line = $quote->getNewProductLine($product->referencia);
            $line->cantidad = 1;
            if (null !== $renewal->price_override) {
                $line->pvpunitario = (float)$renewal->price_override;
            }
            $line->descripcion = $this->buildLineDescription($renewal, $cycle);
            if (false === $line->save()) {
                throw new \RuntimeException('Could not save the quote line');
            }

            $lines = $quote->getLines();
            if (false === Calculator::calculate($quote, $lines, true)) {
                throw new \RuntimeException('Could not calculate the quote totals');
            }

            $cycle->quote_id = $quote->id();
            $cycle->status = ServiceRenewalCycle::STATUS_QUOTE_CREATED;
            $cycle->quote_created_at = Tools::dateTime();
            $cycle->last_error = null;
            if (false === $cycle->save()) {
                throw new \RuntimeException('Could not link the quote to the cycle');
            }

            $db->commit();

            return $quote;
        } catch (Throwable $exception) {
            $db->rollback();

            // recargamos el ciclo para descartar cambios revertidos y registramos el error
            $cycle->reload();
            $this->markFailed($cycle, $exception->getMessage());

            return null;
        }
    }

    /**
     * Descripción de la línea: identificador, periodo cubierto y proveedor.
     */
    private function buildLineDescription(ServiceRenewal $renewal, ServiceRenewalCycle $cycle): string
    {
        // el periodo cubre desde el vencimiento actual hasta el día anterior al siguiente
        $start = $cycle->previous_expiration_date;
        $end = (new DateTimeImmutable($cycle->next_expiration_date))->modify('-1 day')->format('Y-m-d');

        $text = Tools::lang()->trans('service-renewal-quote-line', [
            '%identifier%' => (string)$renewal->service_identifier,
        ]);
        $text .= "\n" . Tools::lang()->trans('service-renewal-quote-period', [
            '%start%' => Tools::date($start),
            '%end%' => Tools::date($end),
        ]);
        if (!empty($renewal->provider_name)) {
            $text .= "\n" . Tools::lang()->trans('service-renewal-quote-provider', [
                '%provider%' => (string)$renewal->provider_name,
            ]);
        }

        return $text;
    }

    private function markFailed(ServiceRenewalCycle $cycle, string $error): void
    {
        $cycle->status = ServiceRenewalCycle::STATUS_FAILED;
        $cycle->last_error = $error;
        $cycle->save();
        Tools::log()->error('service-renewal-quote-failed', [
            '%id%' => (string)$cycle->id,
            '%error%' => $error,
        ]);
    }
}
