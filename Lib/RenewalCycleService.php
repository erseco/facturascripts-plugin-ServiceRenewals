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

namespace FacturaScripts\Plugins\ServiceRenewals\Lib;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Plugins\ServiceRenewals\Model\ServiceRenewal;
use FacturaScripts\Plugins\ServiceRenewals\Model\ServiceRenewalCycle;

/**
 * Gestión idempotente de los ciclos de renovación.
 *
 * Un ciclo queda identificado por la suscripción y la fecha de vencimiento
 * que cubre; la restricción única de la tabla garantiza que las ejecuciones
 * repetidas del cron reutilicen siempre el mismo ciclo.
 *
 * @author Ernesto Serrano <info@ernesto.es>
 */
final class RenewalCycleService
{
    /**
     * Devuelve el ciclo del vencimiento actual de la suscripción,
     * creándolo si no existe. Devuelve null si no puede crearse.
     */
    public function getOrCreate(ServiceRenewal $renewal): ?ServiceRenewalCycle
    {
        $previous = RenewalDateCalculator::toIso($renewal->expiration_date);
        if (null === $previous || empty($renewal->id)) {
            return null;
        }

        $existing = $this->find($renewal->id, $previous);
        if (null !== $existing) {
            return $existing;
        }

        $cycle = new ServiceRenewalCycle();
        $cycle->service_renewal_id = $renewal->id;
        $cycle->previous_expiration_date = $previous;
        $cycle->next_expiration_date = RenewalDateCalculator::addMonths(
            $previous,
            max(1, (int)$renewal->period_months)
        );
        if ($cycle->save()) {
            return $cycle;
        }

        // si el guardado falla puede deberse a una carrera con otra ejecución:
        // la restricción única impide duplicados, así que reintentamos la búsqueda
        return $this->find($renewal->id, $previous);
    }

    /**
     * Aplica la renovación: avanza la fecha de la suscripción y marca el
     * ciclo como renovado, en una única transacción. Es idempotente: un
     * ciclo ya renovado no vuelve a avanzar la fecha.
     */
    public function applyRenewal(ServiceRenewalCycle $cycle): bool
    {
        if (ServiceRenewalCycle::STATUS_RENEWED === $cycle->status) {
            return true;
        }

        $renewal = $cycle->getRenewal();
        if (empty($renewal->id)) {
            return false;
        }

        $db = new DataBase();
        $db->beginTransaction();

        $renewal->expiration_date = $cycle->next_expiration_date;
        if (ServiceRenewal::STATUS_EXPIRED === $renewal->status) {
            $renewal->status = ServiceRenewal::STATUS_ACTIVE;
        }

        $cycle->status = ServiceRenewalCycle::STATUS_RENEWED;
        $cycle->renewed_at = Tools::dateTime();
        $cycle->last_error = null;

        if ($renewal->save() && $cycle->save()) {
            $db->commit();
            return true;
        }

        $db->rollback();
        Tools::log()->error('service-renewal-renewal-failed', ['%id%' => $cycle->id]);

        return false;
    }

    /**
     * Confirmación manual: solo un ciclo en renewal_pending avanza la fecha.
     */
    public function confirmManualRenewal(ServiceRenewalCycle $cycle): bool
    {
        if (ServiceRenewalCycle::STATUS_RENEWAL_PENDING !== $cycle->status) {
            return false;
        }

        return $this->applyRenewal($cycle);
    }

    private function find(int $renewalId, string $previous): ?ServiceRenewalCycle
    {
        return ServiceRenewalCycle::findWhere([
            Where::eq('service_renewal_id', $renewalId),
            Where::eq('previous_expiration_date', $previous),
        ]);
    }
}
