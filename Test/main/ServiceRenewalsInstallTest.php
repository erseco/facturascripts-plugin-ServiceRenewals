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

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Plugins\ServiceRenewals\Model\ServiceRenewal;
use FacturaScripts\Plugins\ServiceRenewals\Model\ServiceRenewalCycle;
use FacturaScripts\Plugins\ServiceRenewals\Model\ServiceRenewalNotification;
use FacturaScripts\Plugins\ServiceRenewals\Model\ServiceRenewalProfile;
use PHPUnit\Framework\TestCase;

/**
 * Tests de instalación: creación de tablas y valores predeterminados.
 *
 * @author Ernesto Serrano <info@ernesto.es>
 */
final class ServiceRenewalsInstallTest extends TestCase
{
    public function testTablesAreCreated(): void
    {
        // instanciar los modelos fuerza la creación de las tablas
        new ServiceRenewalProfile();
        new ServiceRenewal();
        new ServiceRenewalCycle();
        new ServiceRenewalNotification();

        $db = new DataBase();
        $tables = $db->getTables();

        $this->assertContains('service_renewal_profiles', $tables);
        $this->assertContains('service_renewals', $tables);
        $this->assertContains('service_renewal_cycles', $tables);
        $this->assertContains('service_renewal_notifications', $tables);
    }

    public function testModelsProvideDefaults(): void
    {
        $renewal = new ServiceRenewal();
        $this->assertSame(ServiceRenewal::STATUS_ACTIVE, $renewal->status);
        $this->assertSame(12, $renewal->period_months);

        $cycle = new ServiceRenewalCycle();
        $this->assertSame(ServiceRenewalCycle::STATUS_PENDING, $cycle->status);

        $notification = new ServiceRenewalNotification();
        $this->assertSame(ServiceRenewalNotification::STATUS_PENDING, $notification->status);
        $this->assertSame(ServiceRenewalNotification::TYPE_QUOTE, $notification->notification_type);
        $this->assertSame(0, $notification->attempts);
    }

    public function testAttachmentsJsonRoundTrip(): void
    {
        $notification = new ServiceRenewalNotification();
        $this->assertSame([], $notification->getAttachments());

        $notification->setAttachments([['file' => 'quote-1.pdf', 'name' => 'PRE-1.pdf']]);
        $this->assertSame([['file' => 'quote-1.pdf', 'name' => 'PRE-1.pdf']], $notification->getAttachments());

        // JSON corrupto no debe romper
        $notification->attachments = '{bad json';
        $this->assertSame([], $notification->getAttachments());
    }
}
