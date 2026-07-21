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

use FacturaScripts\Core\Lib\ExtendedController\EditController;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Plugins\ServiceRenewals\Lib\NotificationService;
use FacturaScripts\Plugins\ServiceRenewals\Lib\QuoteGenerator;
use FacturaScripts\Plugins\ServiceRenewals\Lib\RenewalCycleService;
use FacturaScripts\Plugins\ServiceRenewals\Model\ServiceRenewal;
use FacturaScripts\Plugins\ServiceRenewals\Model\ServiceRenewalCycle;
use FacturaScripts\Plugins\ServiceRenewals\Model\ServiceRenewalNotification;

/**
 * Ficha de una suscripción: edición, ciclos, notificaciones y acciones.
 *
 * @author Ernesto Serrano <info@ernesto.es>
 */
class EditServiceRenewal extends EditController
{
    public function getModelClassName(): string
    {
        return 'ServiceRenewal';
    }

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'sales';
        $data['title'] = 'service-renewal';
        $data['icon'] = 'fa-solid fa-arrows-rotate';

        return $data;
    }

    protected function createViews(): void
    {
        parent::createViews();
        $this->setTabsPosition('top');

        // historial de ciclos
        $this->addListView('ListServiceRenewalCycle', 'ServiceRenewalCycle', 'cycles', 'fa-solid fa-clock-rotate-left');
        $this->views['ListServiceRenewalCycle']->setSettings('btnNew', false);
        $this->views['ListServiceRenewalCycle']->setSettings('btnDelete', false);
        $this->views['ListServiceRenewalCycle']->setSettings('checkBoxes', false);

        // notificaciones
        $this->addListView(
            'ListServiceRenewalNotification',
            'ServiceRenewalNotification',
            'notifications',
            'fa-regular fa-envelope'
        );
        $this->views['ListServiceRenewalNotification']->setSettings('btnNew', false);
        $this->views['ListServiceRenewalNotification']->setSettings('btnDelete', false);
        $this->views['ListServiceRenewalNotification']->setSettings('checkBoxes', false);

        // acciones de la suscripción
        $mainView = $this->getMainViewName();
        $this->addButton($mainView, [
            'type' => 'action',
            'action' => 'generate-quote',
            'color' => 'info',
            'icon' => 'fa-solid fa-file-invoice-dollar',
            'label' => 'generate-quote',
        ]);
        $this->addButton($mainView, [
            'type' => 'action',
            'action' => 'send-quote-email',
            'color' => 'info',
            'icon' => 'fa-regular fa-paper-plane',
            'label' => 'send-notification',
        ]);
        $this->addButton($mainView, [
            'type' => 'action',
            'action' => 'confirm-renewal',
            'color' => 'success',
            'icon' => 'fa-solid fa-check',
            'label' => 'confirm-renewal',
        ]);
        $this->addButton($mainView, [
            'type' => 'action',
            'action' => 'suspend-renewal',
            'color' => 'warning',
            'icon' => 'fa-solid fa-pause',
            'label' => 'suspend',
        ]);
        $this->addButton($mainView, [
            'type' => 'action',
            'action' => 'reactivate-renewal',
            'color' => 'secondary',
            'icon' => 'fa-solid fa-play',
            'label' => 'reactivate',
        ]);
        $this->addButton($mainView, [
            'type' => 'action',
            'action' => 'cancel-renewal',
            'color' => 'danger',
            'icon' => 'fa-solid fa-ban',
            'label' => 'cancel-subscription',
        ]);
    }

    protected function execPreviousAction($action)
    {
        $renewalActions = [
            'generate-quote', 'send-quote-email', 'confirm-renewal',
            'suspend-renewal', 'reactivate-renewal', 'cancel-renewal',
        ];
        if (false === in_array($action, $renewalActions, true)) {
            return parent::execPreviousAction($action);
        }

        // permisos y token en todas las acciones de renovación
        if (false === $this->permissions->allowUpdate) {
            Tools::log()->warning('not-allowed-modify');
            return true;
        }
        if (false === $this->validateFormToken()) {
            return true;
        }

        $renewal = new ServiceRenewal();
        $code = $this->request->request->get('code', $this->request->query->get('code', ''));
        if (empty($code) || false === $renewal->load($code)) {
            Tools::log()->warning('record-not-found');
            return true;
        }

        switch ($action) {
            case 'generate-quote':
                $this->generateQuoteAction($renewal);
                break;

            case 'send-quote-email':
                $this->sendQuoteEmailAction($renewal);
                break;

            case 'confirm-renewal':
                $this->confirmRenewalAction($renewal);
                break;

            case 'suspend-renewal':
                $this->changeStatusAction($renewal, ServiceRenewal::STATUS_SUSPENDED);
                break;

            case 'reactivate-renewal':
                $this->changeStatusAction($renewal, ServiceRenewal::STATUS_ACTIVE);
                break;

            case 'cancel-renewal':
                $this->changeStatusAction($renewal, ServiceRenewal::STATUS_CANCELLED);
                break;
        }

        return true;
    }

    protected function loadData($viewName, $view)
    {
        $mainViewName = $this->getMainViewName();

        switch ($viewName) {
            case 'ListServiceRenewalCycle':
                $id = $this->getViewModelValue($mainViewName, 'id');
                $view->loadData('', [Where::eq('service_renewal_id', $id)], ['previous_expiration_date' => 'DESC']);
                foreach ($view->cursor as $cycle) {
                    $cycle->status_label = Tools::lang()->trans('service-renewal-cycle-status-' . $cycle->status);
                    $quote = $cycle->getQuote();
                    $cycle->quote_code = null !== $quote ? (string)$quote->codigo : '-';
                    $invoice = $cycle->getInvoice();
                    $cycle->invoice_code = null !== $invoice ? (string)$invoice->codigo : '-';
                }
                break;

            case 'ListServiceRenewalNotification':
                $id = $this->getViewModelValue($mainViewName, 'id');
                $cycleIds = [];
                foreach (ServiceRenewalCycle::allWhereEq('service_renewal_id', $id) as $cycle) {
                    $cycleIds[] = $cycle->id;
                }
                $where = empty($cycleIds) ? [Where::eq('cycle_id', -1)] : [Where::in('cycle_id', $cycleIds)];
                $view->loadData('', $where, ['id' => 'DESC']);
                foreach ($view->cursor as $notification) {
                    $notification->type_label = Tools::lang()->trans(
                        'service-renewal-notification-type-' . $notification->notification_type
                    );
                    $notification->status_label = Tools::lang()->trans(
                        'service-renewal-notification-status-' . $notification->status
                    );
                }
                break;

            default:
                parent::loadData($viewName, $view);
        }
    }

    /** Cambia el estado de la suscripción. */
    private function changeStatusAction(ServiceRenewal $renewal, string $status): void
    {
        $renewal->status = $status;
        if ($renewal->save()) {
            Tools::log()->notice('record-updated-correctly');
            return;
        }

        Tools::log()->error('record-save-error');
    }

    /** Confirmación manual de la renovación pendiente. */
    private function confirmRenewalAction(ServiceRenewal $renewal): void
    {
        $cycle = $renewal->getOpenCycle();
        if (null === $cycle) {
            Tools::log()->warning('service-renewal-no-pending-renewal');
            return;
        }

        // aceptamos también un ciclo facturado con política manual aún no marcado
        $manualInvoiced = ServiceRenewalCycle::STATUS_INVOICED === $cycle->status
            && 'manual' === $renewal->effectiveRenewalTrigger();
        if ($manualInvoiced) {
            $cycle->status = ServiceRenewalCycle::STATUS_RENEWAL_PENDING;
            $cycle->save();
        }

        if ((new RenewalCycleService())->confirmManualRenewal($cycle)) {
            Tools::log()->notice('service-renewal-renewed');
            return;
        }

        Tools::log()->warning('service-renewal-no-pending-renewal');
    }

    /** Genera (o recupera) el presupuesto del ciclo actual. */
    private function generateQuoteAction(ServiceRenewal $renewal): void
    {
        if (ServiceRenewal::STATUS_CANCELLED === $renewal->status) {
            Tools::log()->warning('service-renewal-cancelled-no-actions');
            return;
        }

        $cycle = (new RenewalCycleService())->getOrCreate($renewal);
        if (null === $cycle) {
            Tools::log()->error('service-renewal-cycle-failed', ['%id%' => (string)$renewal->id]);
            return;
        }

        $quote = (new QuoteGenerator())->generate($renewal, $cycle);
        if (null !== $quote) {
            Tools::log()->notice('service-renewal-quote-generated', ['%code%' => (string)$quote->codigo]);
            return;
        }

        Tools::log()->error('service-renewal-quote-error');
    }

    /** Envía o reenvía el email del presupuesto del ciclo actual. */
    private function sendQuoteEmailAction(ServiceRenewal $renewal): void
    {
        $blocked = [ServiceRenewal::STATUS_CANCELLED, ServiceRenewal::STATUS_SUSPENDED];
        if (in_array($renewal->status, $blocked, true)) {
            Tools::log()->warning('service-renewal-cancelled-no-actions');
            return;
        }

        $cycle = $renewal->getOpenCycle();
        $quote = null !== $cycle ? $cycle->getQuote() : null;
        if (null === $cycle || null === $quote) {
            Tools::log()->warning('service-renewal-no-quote-to-send');
            return;
        }

        $service = new NotificationService();
        $notification = $service->createQuoteNotification($renewal, $cycle, $quote);
        if (null === $notification) {
            Tools::log()->error('service-renewal-notification-error');
            return;
        }

        // reenvío: recuperamos una notificación ya enviada o fallida
        if (ServiceRenewalNotification::STATUS_SENT === $notification->status) {
            $notification->status = ServiceRenewalNotification::STATUS_PENDING;
            $notification->attempts = 0;
            $notification->sent_at = null;
            $notification->save();
        }
        if (empty($notification->recipient)) {
            Tools::log()->error('service-renewal-notification-error');
            return;
        }

        if ($service->enqueue($notification)) {
            Tools::log()->notice('service-renewal-notification-queued');
        }
    }
}
