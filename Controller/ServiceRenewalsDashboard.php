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

namespace FacturaScripts\Plugins\ServiceRenewals\Controller;

use FacturaScripts\Core\Template\Controller;
use FacturaScripts\Core\Where;
use FacturaScripts\Plugins\ServiceRenewals\Model\ServiceRenewal;
use FacturaScripts\Plugins\ServiceRenewals\Model\ServiceRenewalCycle;
use FacturaScripts\Plugins\ServiceRenewals\Model\ServiceRenewalNotification;

/**
 * Panel de resumen de renovaciones: tarjetas y próximos vencimientos.
 *
 * @author Ernesto Serrano <info@ernesto.es>
 */
class ServiceRenewalsDashboard extends Controller
{
    /** @var array<string, int> Contadores de las tarjetas del panel. */
    public $cards = [];

    /** @var ServiceRenewal[] Próximas renovaciones ordenadas por vencimiento. */
    public $upcoming = [];

    /** @var string Fecha de referencia (Y-m-d). */
    public $today;

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'sales';
        $data['title'] = 'service-renewals-dashboard';
        $data['icon'] = 'fa-solid fa-gauge-high';
        $data['ordernum'] = 99;

        return $data;
    }

    public function run(): void
    {
        parent::run();

        $this->today = date('Y-m-d');
        $this->loadCards();
        $this->loadUpcoming();

        $this->view('ServiceRenewalsDashboard.html.twig');
    }

    private function loadCards(): void
    {
        $active = Where::eq('status', ServiceRenewal::STATUS_ACTIVE);
        $in7days = date('Y-m-d', strtotime($this->today . ' +7 days'));
        $in30days = date('Y-m-d', strtotime($this->today . ' +30 days'));

        $this->cards = [
            'active' => ServiceRenewal::count([$active]),
            'expired' => ServiceRenewal::count([$active, Where::lt('expiration_date', $this->today)]),
            'expiring7' => ServiceRenewal::count([
                $active,
                Where::gte('expiration_date', $this->today),
                Where::lte('expiration_date', $in7days),
            ]),
            'expiring30' => ServiceRenewal::count([
                $active,
                Where::gte('expiration_date', $this->today),
                Where::lte('expiration_date', $in30days),
            ]),
            'pendingQuotes' => ServiceRenewalCycle::count([
                Where::in('status', [
                    ServiceRenewalCycle::STATUS_QUOTE_CREATED,
                    ServiceRenewalCycle::STATUS_QUOTE_SENT,
                ]),
            ]),
            'pendingRenewals' => ServiceRenewalCycle::count([
                Where::eq('status', ServiceRenewalCycle::STATUS_RENEWAL_PENDING),
            ]),
            'failedEmails' => ServiceRenewalNotification::count([
                Where::eq('status', ServiceRenewalNotification::STATUS_FAILED),
            ]),
        ];
    }

    private function loadUpcoming(): void
    {
        $this->upcoming = ServiceRenewal::all(
            [Where::eq('status', ServiceRenewal::STATUS_ACTIVE)],
            ['expiration_date' => 'ASC'],
            0,
            10
        );
    }
}
