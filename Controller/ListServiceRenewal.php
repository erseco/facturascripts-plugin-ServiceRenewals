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

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Lib\ExtendedController\ListController;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\ServiceRenewals\Lib\RenewalCycleFilter;
use FacturaScripts\Plugins\ServiceRenewals\Lib\RenewalListDecorator;
use FacturaScripts\Plugins\ServiceRenewals\Model\ServiceRenewal;
use FacturaScripts\Plugins\ServiceRenewals\Model\ServiceRenewalProfile;

/**
 * Listado principal de suscripciones de servicios.
 *
 * @author Ernesto Serrano <info@ernesto.es>
 */
class ListServiceRenewal extends ListController
{
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'sales';
        $data['title'] = 'service-renewals';
        $data['icon'] = 'fa-solid fa-arrows-rotate';

        return $data;
    }

    protected function createViews(): void
    {
        $this->createViewsRenewals();
    }

    protected function createViewsRenewals(string $viewName = 'ListServiceRenewal'): void
    {
        $this->addView($viewName, 'ServiceRenewal', 'service-renewals', 'fa-solid fa-arrows-rotate');
        $this->addSearchFields($viewName, [
            'service_identifier', 'title', 'provider_name', 'external_reference', 'notes',
        ]);

        $this->addOrderBy($viewName, ['expiration_date'], 'expiration-date', 1);
        $this->addOrderBy($viewName, ['codcustomer'], 'customer');
        $this->addOrderBy($viewName, ['service_identifier'], 'service');
        $this->addOrderBy($viewName, ['created_at'], 'date');

        // filtros principales
        $this->addFilterAutocomplete(
            $viewName,
            'codcustomer',
            'customer',
            'codcustomer',
            'clientes',
            'codcliente',
            'nombre'
        );
        $this->addFilterAutocomplete(
            $viewName,
            'idproduct',
            'product',
            'idproduct',
            'productos',
            'idproducto',
            'descripcion'
        );

        $types = ['' => '------'];
        foreach (ServiceRenewalProfile::SERVICE_TYPES as $type) {
            $types[$type] = Tools::lang()->trans('service-type-' . $type);
        }
        $this->addFilterSelect(
            $viewName,
            'service_type',
            'service-type',
            'service_type',
            $this->toFilterOptions($types)
        );

        $this->addFilterAutocomplete(
            $viewName,
            'provider',
            'provider-name',
            'provider_name',
            'service_renewals',
            'provider_name',
            'provider_name'
        );

        $statuses = ['' => '------'];
        foreach (ServiceRenewal::STATUSES as $status) {
            $statuses[$status] = Tools::lang()->trans('service-renewal-status-' . $status);
        }
        $this->addFilterSelect($viewName, 'status', 'status', 'status', $this->toFilterOptions($statuses));

        // filtros por fecha de vencimiento
        $this->addFilterPeriod($viewName, 'expiration', 'expiration-date', 'expiration_date');
        $this->addFilterSelectWhere($viewName, 'expiry', $this->expiryFilterValues());

        // filtros por estado del ciclo actual
        $this->addFilterSelectWhere($viewName, 'cycle', $this->cycleFilterValues());
    }

    protected function loadData($viewName, $view)
    {
        parent::loadData($viewName, $view);

        if ('ListServiceRenewal' === $viewName) {
            $this->fillComputedColumns($view);
        }
    }

    /**
     * Rellena las columnas calculadas del listado (días restantes, ciclo,
     * documentos e importe) sin persistirlas.
     */
    protected function fillComputedColumns($view): void
    {
        RenewalListDecorator::decorateFull($view->cursor, date('Y-m-d'));
    }

    /**
     * Opciones del filtro rápido de vencimiento.
     *
     * @return array<int, array{label: string, where: DataBaseWhere[]}>
     */
    private function expiryFilterValues(): array
    {
        $today = date('Y-m-d');

        return [
            [
                'label' => '------',
                'where' => [],
            ],
            [
                'label' => Tools::lang()->trans('expired'),
                'where' => [new DataBaseWhere('expiration_date', $today, '<')],
            ],
            [
                'label' => Tools::lang()->trans('next-7-days'),
                'where' => [
                    new DataBaseWhere('expiration_date', $today, '>='),
                    new DataBaseWhere('expiration_date', date('Y-m-d', strtotime('+7 days')), '<='),
                ],
            ],
            [
                'label' => Tools::lang()->trans('next-30-days'),
                'where' => [
                    new DataBaseWhere('expiration_date', $today, '>='),
                    new DataBaseWhere('expiration_date', date('Y-m-d', strtotime('+30 days')), '<='),
                ],
            ],
            [
                'label' => Tools::lang()->trans('next-60-days'),
                'where' => [
                    new DataBaseWhere('expiration_date', $today, '>='),
                    new DataBaseWhere('expiration_date', date('Y-m-d', strtotime('+60 days')), '<='),
                ],
            ],
        ];
    }

    /**
     * Opciones del filtro por estado del ciclo. Las condiciones viven en
     * RenewalCycleFilter para poder probarlas fuera del controlador.
     *
     * @return array<int, array{label: string, where: DataBaseWhere[]}>
     */
    private function cycleFilterValues(): array
    {
        return RenewalCycleFilter::options();
    }

    /**
     * Convierte un mapa clave-etiqueta en opciones de addFilterSelect.
     *
     * @param array<string, string> $map
     *
     * @return array<int, array{code: string, description: string}>
     */
    private function toFilterOptions(array $map): array
    {
        $options = [];
        foreach ($map as $code => $description) {
            $options[] = ['code' => $code, 'description' => $description];
        }

        return $options;
    }
}
