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

use FacturaScripts\Core\Template\InitClass;
use FacturaScripts\Core\WorkQueue;
use FacturaScripts\Dinamic\Model\Settings;
use FacturaScripts\Plugins\ServiceRenewals\Lib\ServiceRenewalsSettings;

/**
 * Inicialización del plugin ServiceRenewals.
 *
 * Registra los workers de la cola de trabajos y las extensiones de los
 * controladores de cliente y producto. En la actualización siembra los
 * valores de configuración que falten.
 *
 * @author Ernesto Serrano <info@ernesto.es>
 */
class Init extends InitClass
{
    /** Evento del procesamiento periódico de renovaciones. */
    public const PROCESS_EVENT = 'ServiceRenewals.Process';

    /** Evento de envío de una notificación. */
    public const MAIL_EVENT = 'ServiceRenewals.SendNotification';

    public function init(): void
    {
        // workers de la cola de trabajos
        WorkQueue::addWorker('ProcessServiceRenewalsWorker', self::PROCESS_EVENT);
        WorkQueue::addWorker('SendServiceRenewalMailWorker', self::MAIL_EVENT);

        // pestañas de renovaciones en las fichas de cliente y producto
        $this->loadExtension(new Extension\Controller\EditCliente());
        $this->loadExtension(new Extension\Controller\EditProducto());
    }

    public function update(): void
    {
        $this->setupSettings();
    }

    public function uninstall(): void
    {
        // se conservan las tablas y la configuración para no perder el historial
    }

    /** Crea las claves de configuración que falten, sin pisar las existentes. */
    private function setupSettings(): void
    {
        $settings = new Settings();
        $settings->loadFromCode(ServiceRenewalsSettings::GROUP);
        $settings->name = ServiceRenewalsSettings::GROUP;

        foreach (ServiceRenewalsSettings::getDefaults() as $key => $value) {
            if (null !== $settings->getProperty($key)) {
                continue;
            }

            if (is_bool($value)) {
                $settings->{$key} = $value ? '1' : '0';
                continue;
            }

            $settings->{$key} = (string)$value;
        }

        $settings->save();
    }
}
