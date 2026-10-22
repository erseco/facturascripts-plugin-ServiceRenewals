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

use FacturaScripts\Core\Lib\Widget\WidgetText;
use FacturaScripts\Core\Tools;

/**
 * Widget de texto que enlaza con la URL completa indicada en fieldclick.
 *
 * El del núcleo solo admite un controlador fijo en onclick, y el documento
 * de renovación puede ser un presupuesto o un albarán según el ciclo.
 *
 * @author Ernesto Serrano <info@ernesto.es>
 */
class WidgetDocumentlink extends WidgetText
{
    protected function onclickHtml($inside, $titleurl = '')
    {
        if (empty($this->valueOnClick)) {
            return $inside;
        }

        $url = Tools::config('route') . '/' . $this->valueOnClick;

        return '<a href="' . htmlspecialchars($url) . '" class="cancelClickable">' . $inside . '</a>';
    }
}
