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

use FacturaScripts\Core\Lib\ExtendedController\PanelController;

/**
 * Configuración global del plugin en Administración.
 *
 * Guarda los valores mediante el modelo Settings del núcleo (grupo
 * ServiceRenewals); la vista es XMLView/ServiceRenewalsConfig.xml.
 *
 * @author Ernesto Serrano <info@ernesto.es>
 */
class ServiceRenewalsConfig extends PanelController
{
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'admin';
        $data['title'] = 'service-renewals';
        $data['icon'] = 'fa-solid fa-arrows-rotate';

        return $data;
    }

    protected function createViews(): void
    {
        $this->createViewsConfig();
    }

    protected function createViewsConfig(string $viewName = 'ServiceRenewalsConfig'): void
    {
        $this->addEditView($viewName, 'Settings', 'service-renewals', 'fa-solid fa-gear');
    }

    protected function loadData($viewName, $view)
    {
        if ('ServiceRenewalsConfig' === $viewName) {
            $view->loadData('ServiceRenewals');
            $view->model->name = 'ServiceRenewals';
        }
    }
}
