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
use InvalidArgumentException;

/**
 * Cálculo de fechas de renovación por meses naturales.
 *
 * Política de suma de meses: se conserva el día de anclaje de la fecha
 * original; si el mes de destino no tiene ese día (por ejemplo, 31/01 + 1
 * mes), se recorta al último día del mes de destino. El recorte no es
 * acumulativo: cada operación parte de la fecha que recibe.
 *
 * Ejemplos documentados:
 *   31/01 + 1 mes  -> 28/02 (29/02 en año bisiesto)
 *   29/02 + 12 meses -> 28/02 del año siguiente
 *   31/08 + 6 meses -> 28/02 (29/02 en año bisiesto)
 *
 * @author Ernesto Serrano <info@ernesto.es>
 */
final class RenewalDateCalculator
{
    /** Formato interno de fechas (ISO 8601, solo fecha). */
    public const DATE_FORMAT = 'Y-m-d';

    /**
     * Suma meses naturales a una fecha con recorte a fin de mes.
     *
     * @throws InvalidArgumentException si la fecha es inválida o los meses no son positivos
     */
    public static function addMonths(string $date, int $months): string
    {
        if ($months < 1) {
            throw new InvalidArgumentException('Months must be a positive integer, got ' . $months);
        }

        $start = self::parseDate($date);
        $year = (int)$start->format('Y');
        $month = (int)$start->format('n');
        $day = (int)$start->format('j');

        // Calculamos año y mes de destino sin depender del desbordamiento de DateTime.
        $totalMonths = ($year * 12) + ($month - 1) + $months;
        $targetYear = intdiv($totalMonths, 12);
        $targetMonth = ($totalMonths % 12) + 1;

        $lastDay = (int)(new DateTimeImmutable(sprintf('%04d-%02d-01', $targetYear, $targetMonth)))->format('t');
        $targetDay = min($day, $lastDay);

        return sprintf('%04d-%02d-%02d', $targetYear, $targetMonth, $targetDay);
    }

    /**
     * Días naturales desde $from hasta $date. Negativo si $date ya pasó.
     *
     * @throws InvalidArgumentException si alguna fecha es inválida
     */
    public static function daysUntil(string $date, string $from): int
    {
        $target = self::parseDate($date);
        $origin = self::parseDate($from);

        $diff = $origin->diff($target);

        return $diff->invert === 1 ? -$diff->days : $diff->days;
    }

    /**
     * Normaliza una fecha a formato Y-m-d, o null si no es válida.
     * Acepta Y-m-d (ISO) y d-m-Y (formato de Tools::date() del núcleo).
     */
    public static function toIso(?string $date): ?string
    {
        if (null === $date || '' === trim($date)) {
            return null;
        }

        try {
            return self::parseDate($date)->format(self::DATE_FORMAT);
        } catch (InvalidArgumentException $exception) {
            return null;
        }
    }

    /**
     * Valida y analiza una fecha en formato Y-m-d o d-m-Y.
     *
     * @throws InvalidArgumentException
     */
    private static function parseDate(string $date): DateTimeImmutable
    {
        foreach ([self::DATE_FORMAT, 'd-m-Y'] as $format) {
            $parsed = DateTimeImmutable::createFromFormat('!' . $format, $date);
            if (false !== $parsed && $parsed->format($format) === $date) {
                return $parsed;
            }
        }

        throw new InvalidArgumentException('Invalid date: ' . $date);
    }
}
