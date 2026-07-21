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

/**
 * Normalización de listas de días de recordatorio ("30,15,7").
 *
 * Se almacenan como una cadena CSV normalizada: enteros no negativos,
 * sin duplicados y en orden descendente.
 *
 * @author Ernesto Serrano <info@ernesto.es>
 */
final class ReminderDayList
{
    /**
     * Normaliza una lista CSV de días. Devuelve null si está vacía o no es válida.
     */
    public static function normalize(?string $list): ?string
    {
        if (false === self::isValid($list)) {
            return null;
        }

        $days = self::toArray($list);

        return empty($days) ? null : implode(',', $days);
    }

    /**
     * Comprueba que todos los elementos son enteros no negativos.
     */
    public static function isValid(?string $list): bool
    {
        if (null === $list || '' === trim($list)) {
            return true;
        }

        foreach (explode(',', $list) as $item) {
            $item = trim($item);
            if ('' === $item || 1 !== preg_match('/^\d+$/', $item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Convierte la lista CSV en un array de enteros descendente y sin duplicados.
     *
     * @return int[]
     */
    public static function toArray(?string $list): array
    {
        if (null === $list || '' === trim($list) || false === self::isValid($list)) {
            return [];
        }

        $days = [];
        foreach (explode(',', $list) as $item) {
            $days[] = (int)trim($item);
        }

        $days = array_values(array_unique($days));
        rsort($days);

        return $days;
    }
}
