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

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\ServiceRenewals\Model\ServiceRenewal;
use FacturaScripts\Plugins\ServiceRenewals\Model\ServiceRenewalCycle;

/**
 * Condiciones del filtro por estado del ciclo en el listado de suscripciones.
 *
 * Vive fuera del controlador para poder probarse y para no dejar la
 * construcción de las subconsultas mezclada con la vista. Las subconsultas se
 * correlacionan con el vencimiento de cada suscripción; no interpolan ningún
 * valor de entrada, solo nombres de tabla y constantes del propio modelo.
 *
 * @author Ernesto Serrano <info@ernesto.es>
 */
final class RenewalCycleFilter
{
    /**
     * Opciones del filtro tal y como las espera addFilterSelectWhere().
     *
     * @return array<int, array{label: string, where: DataBaseWhere[]}>
     */
    public static function options(): array
    {
        return [
            [
                'label' => '------',
                'where' => [],
            ],
            [
                'label' => Tools::lang()->trans('with-quote'),
                'where' => self::withQuoteWhere(),
            ],
            [
                'label' => Tools::lang()->trans('without-quote'),
                'where' => self::withoutQuoteWhere(),
            ],
            [
                'label' => Tools::lang()->trans('invoiced'),
                'where' => self::invoicedWhere(),
            ],
            [
                'label' => Tools::lang()->trans('renewal-pending'),
                'where' => self::renewalPendingWhere(),
            ],
        ];
    }

    /** Suscripciones cuyo ciclo vigente tiene presupuesto. @return DataBaseWhere[] */
    public static function withQuoteWhere(): array
    {
        return [new DataBaseWhere('id', self::currentCycle() . ' AND quote_id IS NOT NULL', 'IN')];
    }

    /** Suscripciones cuyo ciclo vigente no tiene presupuesto. @return DataBaseWhere[] */
    public static function withoutQuoteWhere(): array
    {
        return [new DataBaseWhere('id', self::currentCycle() . ' AND quote_id IS NOT NULL', 'NOT IN')];
    }

    /**
     * Suscripciones con factura en el ciclo relevante.
     *
     * Incluye tanto el ciclo vigente (aún sin renovar) como el que ya renovó:
     * al aplicar la renovación el vencimiento avanza hasta
     * next_expiration_date, así que ese ciclo deja de cumplir
     * previous_expiration_date = expiration_date y desaparecería del filtro.
     *
     * @return DataBaseWhere[]
     */
    public static function invoicedWhere(): array
    {
        $sql = 'SELECT service_renewal_id FROM ' . ServiceRenewalCycle::tableName()
            . ' WHERE invoice_id IS NOT NULL'
            . ' AND (previous_expiration_date = ' . ServiceRenewal::tableName() . '.expiration_date'
            . ' OR next_expiration_date = ' . ServiceRenewal::tableName() . '.expiration_date)';

        return [new DataBaseWhere('id', $sql, 'IN')];
    }

    /** Suscripciones cuyo ciclo vigente espera confirmación manual. @return DataBaseWhere[] */
    public static function renewalPendingWhere(): array
    {
        $sql = self::currentCycle()
            . " AND status = '" . ServiceRenewalCycle::STATUS_RENEWAL_PENDING . "'";

        return [new DataBaseWhere('id', $sql, 'IN')];
    }

    /** Subconsulta del ciclo vigente: el que todavía no ha avanzado la fecha. */
    private static function currentCycle(): string
    {
        return 'SELECT service_renewal_id FROM ' . ServiceRenewalCycle::tableName()
            . ' WHERE previous_expiration_date = ' . ServiceRenewal::tableName() . '.expiration_date';
    }
}
