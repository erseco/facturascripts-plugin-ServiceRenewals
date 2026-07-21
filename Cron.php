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

namespace FacturaScripts\Plugins\ServiceRenewals;

use FacturaScripts\Core\Model\WorkEvent;
use FacturaScripts\Core\Template\CronClass;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Core\WorkQueue;
use FacturaScripts\Plugins\ServiceRenewals\Lib\ServiceRenewalsSettings;

/**
 * Tarea de cron del plugin: encola el procesamiento de renovaciones.
 *
 * Usa el cron y la cola de trabajos nativos de FacturaScripts. Se protege
 * contra ejecuciones duplicadas comprobando que no exista ya un evento de
 * proceso pendiente; el worker es en cualquier caso idempotente.
 *
 * @author Ernesto Serrano <info@ernesto.es>
 */
class Cron extends CronClass
{
    public function run(): void
    {
        $this->job('process-renewals')
            ->every('1 hour')
            ->run(function () {
                if (false === ServiceRenewalsSettings::isEnabled()) {
                    return;
                }

                // máximo un evento de proceso pendiente en la cola
                $pending = WorkEvent::count([
                    Where::eq('name', Init::PROCESS_EVENT),
                    Where::eq('done', false),
                ]);
                if ($pending > 0) {
                    return;
                }

                WorkQueue::send(Init::PROCESS_EVENT, Tools::date(), ['date' => Tools::date()]);
            });
    }
}
