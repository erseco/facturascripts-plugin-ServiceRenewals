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

use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\DocTransformation;

/**
 * Localiza la factura generada a partir de un presupuesto.
 *
 * Se apoya en los registros de DocTransformation que crea el núcleo al
 * transformar documentos, de modo que no dependemos del código visible
 * del documento ni modificamos controladores del núcleo. La cadena
 * presupuesto → pedido/albarán → factura también se sigue de forma
 * transitiva.
 *
 * @author Ernesto Serrano <info@ernesto.es>
 */
final class DocumentTransformationFinder
{
    /** Profundidad máxima de la cadena de transformaciones a seguir. */
    private const MAX_DEPTH = 4;

    /**
     * Identificador de la FacturaCliente generada (directa o indirectamente)
     * desde el presupuesto, o null si todavía no existe.
     */
    public static function findInvoiceForQuote(int $quoteId): ?int
    {
        return self::findInvoice('PresupuestoCliente', $quoteId, self::MAX_DEPTH);
    }

    private static function findInvoice(string $model, int $docId, int $depth): ?int
    {
        if ($depth < 1) {
            return null;
        }

        $transformations = DocTransformation::all([
            Where::eq('model1', $model),
            Where::eq('iddoc1', $docId),
        ]);

        // primero buscamos una factura directa
        foreach ($transformations as $transformation) {
            if ('FacturaCliente' === $transformation->model2 && !empty($transformation->iddoc2)) {
                return (int)$transformation->iddoc2;
            }
        }

        // después seguimos la cadena por documentos intermedios (pedido, albarán)
        foreach ($transformations as $transformation) {
            if (empty($transformation->iddoc2) || $transformation->model2 === $model) {
                continue;
            }

            $invoiceId = self::findInvoice((string)$transformation->model2, (int)$transformation->iddoc2, $depth - 1);
            if (null !== $invoiceId) {
                return $invoiceId;
            }
        }

        return null;
    }
}
