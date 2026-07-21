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

use FacturaScripts\Plugins\ServiceRenewals\Lib\ReminderDayList;
use PHPUnit\Framework\TestCase;

/**
 * Tests de la normalización de listas de días de recordatorio.
 *
 * @author Ernesto Serrano <info@ernesto.es>
 */
final class ReminderDayListTest extends TestCase
{
    public function testNormalizesSpacesAndOrder(): void
    {
        $this->assertSame('30,15,7', ReminderDayList::normalize(' 7, 30 ,15 '));
    }

    public function testRemovesDuplicates(): void
    {
        $this->assertSame('15,7', ReminderDayList::normalize('7,15,7'));
    }

    public function testEmptyReturnsNull(): void
    {
        $this->assertNull(ReminderDayList::normalize(''));
        $this->assertNull(ReminderDayList::normalize(null));
        $this->assertNull(ReminderDayList::normalize('  '));
    }

    public function testRejectsNegativeDays(): void
    {
        $this->assertFalse(ReminderDayList::isValid('-5,7'));
        $this->assertNull(ReminderDayList::normalize('-5,7'));
    }

    public function testRejectsNonNumericValues(): void
    {
        $this->assertFalse(ReminderDayList::isValid('7,soon'));
        $this->assertNull(ReminderDayList::normalize('7,soon'));
    }

    public function testAcceptsZero(): void
    {
        $this->assertSame('7,0', ReminderDayList::normalize('0,7'));
    }

    public function testToArrayReturnsIntegersInDescendingOrder(): void
    {
        $this->assertSame([30, 15, 7], ReminderDayList::toArray('7,30,15'));
    }

    public function testToArrayOfEmptyIsEmptyArray(): void
    {
        $this->assertSame([], ReminderDayList::toArray(null));
        $this->assertSame([], ReminderDayList::toArray(''));
    }
}
