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

namespace FacturaScripts\Plugins\ServiceRenewals\Extension\Controller;

use Closure;
use FacturaScripts\Core\Where;
use FacturaScripts\Plugins\ServiceRenewals\Lib\RenewalListDecorator;

/**
 * Extensión de la ficha de producto: perfil de renovación y suscripciones.
 *
 * @author Ernesto Serrano <info@ernesto.es>
 */
class EditProducto
{
    public function createViews(): Closure
    {
        return function () {
            $this->addEditView(
                'EditServiceRenewalProfile',
                'ServiceRenewalProfile',
                'renewal-profile',
                'fa-solid fa-arrows-rotate'
            );
            $this->addListView('ListServiceRenewalSub', 'ServiceRenewal', 'renewals', 'fa-solid fa-list');
            $this->views['ListServiceRenewalSub']->addOrderBy(['expiration_date'], 'expiration-date', 1);
        };
    }

    public function loadData(): Closure
    {
        return function ($viewName, $view) {
            $idproducto = $this->getViewModelValue($this->getMainViewName(), 'idproducto');

            if ('EditServiceRenewalProfile' === $viewName) {
                $view->loadData('', [Where::eq('idproduct', $idproducto)]);
                if (empty($view->model->id)) {
                    // perfil nuevo: fijamos el producto de esta ficha
                    $view->model->idproduct = $idproducto;
                }
                return;
            }

            if ('ListServiceRenewalSub' === $viewName) {
                $view->loadData('', [Where::eq('idproduct', $idproducto)]);
                RenewalListDecorator::decorate($view->cursor, date('Y-m-d'));
            }
        };
    }
}
