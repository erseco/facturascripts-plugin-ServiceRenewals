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

use FacturaScripts\Plugins\ServiceRenewals\Model\ServiceRenewal;

/**
 * Detección de suscripciones próximas al vencimiento.
 *
 * La fecha de proceso se pasa siempre de forma explícita para que los
 * tests puedan ejecutar los casos con una fecha fija.
 *
 * @author Ernesto Serrano <info@ernesto.es>
 */
final class RenewalScanner
{
    /**
     * ¿Debe abrirse (o mantenerse) un ciclo de renovación en la fecha dada?
     * Solo las suscripciones activas dentro del umbral de antelación,
     * incluidas las ya vencidas.
     */
    public static function isDue(ServiceRenewal $renewal, string $today): bool
    {
        if (ServiceRenewal::STATUS_ACTIVE !== $renewal->status) {
            return false;
        }

        return $renewal->daysToExpiration($today) <= $renewal->effectiveQuoteLeadDays();
    }

    /**
     * Suscripciones activas que están dentro del umbral en la fecha dada.
     *
     * @return ServiceRenewal[]
     */
    public static function findDue(string $today): array
    {
        $due = [];
        foreach (ServiceRenewal::allWhereEq('status', ServiceRenewal::STATUS_ACTIVE) as $renewal) {
            if (self::isDue($renewal, $today)) {
                $due[] = $renewal;
            }
        }

        return $due;
    }
}
