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

namespace FacturaScripts\Test\Plugins;

use FacturaScripts\Plugins\ServiceRenewals\Lib\RenewalDateCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Tests del cálculo de fechas de renovación.
 *
 * Política documentada: al sumar meses naturales se conserva el día de
 * anclaje; si el mes de destino no tiene ese día, se usa el último día
 * del mes de destino (recorte a fin de mes).
 *
 * @author Ernesto Serrano <info@ernesto.es>
 */
final class RenewalDateCalculatorTest extends TestCase
{
    public function testAddOneMonthRegularDay(): void
    {
        $this->assertSame('2026-04-10', RenewalDateCalculator::addMonths('2026-03-10', 1));
    }

    public function testAddThreeMonths(): void
    {
        $this->assertSame('2026-04-15', RenewalDateCalculator::addMonths('2026-01-15', 3));
    }

    public function testAddSixMonths(): void
    {
        $this->assertSame('2026-11-05', RenewalDateCalculator::addMonths('2026-05-05', 6));
    }

    public function testAddTwelveMonths(): void
    {
        $this->assertSame('2027-09-15', RenewalDateCalculator::addMonths('2026-09-15', 12));
    }

    public function testAddTwentyFourMonths(): void
    {
        $this->assertSame('2028-09-15', RenewalDateCalculator::addMonths('2026-09-15', 24));
    }

    public function testEndOfJanuaryClampsToEndOfFebruary(): void
    {
        // 31/01 + 1 mes = último día de febrero (año no bisiesto).
        $this->assertSame('2026-02-28', RenewalDateCalculator::addMonths('2026-01-31', 1));
    }

    public function testEndOfJanuaryClampsToLeapFebruary(): void
    {
        // 31/01 + 1 mes en año bisiesto = 29 de febrero.
        $this->assertSame('2028-02-29', RenewalDateCalculator::addMonths('2028-01-31', 1));
    }

    public function testLeapDayPlusTwelveMonths(): void
    {
        // 29/02 + 12 meses = 28/02 del año siguiente (no bisiesto).
        $this->assertSame('2029-02-28', RenewalDateCalculator::addMonths('2028-02-29', 12));
    }

    public function testLeapDayPlusFortyEightMonths(): void
    {
        // 29/02 + 48 meses cae de nuevo en año bisiesto.
        $this->assertSame('2032-02-29', RenewalDateCalculator::addMonths('2028-02-29', 48));
    }

    public function testEndOfAugustPlusSixMonthsClampsToFebruary(): void
    {
        // 31/08 + 6 meses = último día de febrero.
        $this->assertSame('2027-02-28', RenewalDateCalculator::addMonths('2026-08-31', 6));
    }

    public function testDayThirtyOneClampsToShorterMonth(): void
    {
        $this->assertSame('2026-09-30', RenewalDateCalculator::addMonths('2026-08-31', 1));
    }

    public function testDayThirtyKeptWhenTargetMonthAllowsIt(): void
    {
        $this->assertSame('2026-05-30', RenewalDateCalculator::addMonths('2026-04-30', 1));
    }

    public function testAnchorDayIsNotStickyAfterClamping(): void
    {
        // El recorte no es acumulativo: cada suma parte de la fecha dada.
        $clamped = RenewalDateCalculator::addMonths('2026-01-31', 1); // 2026-02-28
        $this->assertSame('2026-03-28', RenewalDateCalculator::addMonths($clamped, 1));
    }

    public function testRejectsZeroMonths(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RenewalDateCalculator::addMonths('2026-01-01', 0);
    }

    public function testRejectsNegativeMonths(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RenewalDateCalculator::addMonths('2026-01-01', -3);
    }

    public function testRejectsInvalidDate(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RenewalDateCalculator::addMonths('not-a-date', 1);
    }

    public function testRejectsImpossibleDate(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RenewalDateCalculator::addMonths('2026-02-30', 1);
    }

    public function testDaysUntilPositive(): void
    {
        $this->assertSame(9, RenewalDateCalculator::daysUntil('2026-07-30', '2026-07-21'));
    }

    public function testDaysUntilZeroSameDay(): void
    {
        $this->assertSame(0, RenewalDateCalculator::daysUntil('2026-07-21', '2026-07-21'));
    }

    public function testDaysUntilNegativeWhenExpired(): void
    {
        $this->assertSame(-5, RenewalDateCalculator::daysUntil('2026-07-16', '2026-07-21'));
    }

    public function testDaysUntilAcrossLeapDay(): void
    {
        $this->assertSame(2, RenewalDateCalculator::daysUntil('2028-03-01', '2028-02-28'));
    }

    public function testAcceptsCoreDateFormat(): void
    {
        // El núcleo de FacturaScripts usa d-m-Y en Tools::date().
        $this->assertSame('2027-09-15', RenewalDateCalculator::addMonths('15-09-2026', 12));
    }

    public function testToIsoNormalizesCoreFormat(): void
    {
        $this->assertSame('2026-09-15', RenewalDateCalculator::toIso('15-09-2026'));
    }

    public function testToIsoKeepsIsoFormat(): void
    {
        $this->assertSame('2026-09-15', RenewalDateCalculator::toIso('2026-09-15'));
    }

    public function testToIsoReturnsNullOnInvalidDate(): void
    {
        $this->assertNull(RenewalDateCalculator::toIso('not-a-date'));
        $this->assertNull(RenewalDateCalculator::toIso('30-02-2026'));
        $this->assertNull(RenewalDateCalculator::toIso(''));
    }
}
