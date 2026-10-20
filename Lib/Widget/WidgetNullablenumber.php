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

namespace FacturaScripts\Plugins\ServiceRenewals\Lib\Widget;

use FacturaScripts\Core\Lib\Widget\WidgetNumber;

/**
 * Widget number que guarda null cuando el campo se deja vacío.
 *
 * El del núcleo convierte el vacío en 0, y en los campos de la suscripción
 * null significa "heredar del producto, perfil o configuración global".
 *
 * @author Ernesto Serrano <info@ernesto.es>
 */
class WidgetNullablenumber extends WidgetNumber
{
    public function processFormData(&$model, $request)
    {
        $value = trim((string)$request->request->get($this->fieldname));
        $model->{$this->fieldname} = '' === $value ? null : (float)$value;
    }
}
