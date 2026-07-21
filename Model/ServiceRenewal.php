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

namespace FacturaScripts\Plugins\ServiceRenewals\Model;

use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Validator;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\Cliente;
use FacturaScripts\Dinamic\Model\Producto;
use FacturaScripts\Plugins\ServiceRenewals\Lib\ReminderDayList;
use FacturaScripts\Plugins\ServiceRenewals\Lib\RenewalDateCalculator;
use FacturaScripts\Plugins\ServiceRenewals\Lib\ServiceRenewalsSettings;

/**
 * Suscripción o servicio contratado por un cliente.
 *
 * Guarda la fecha real de vencimiento del servicio concreto (un dominio,
 * un hosting, un VPS...) y los valores que sobrescriben el perfil del
 * producto. El historial de renovaciones se guarda en los ciclos.
 *
 * @author Ernesto Serrano <info@ernesto.es>
 */
class ServiceRenewal extends ModelClass
{
    use ModelTrait;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_EXPIRED = 'expired';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_SUSPENDED,
        self::STATUS_CANCELLED,
        self::STATUS_EXPIRED,
    ];

    /** @var int Identificador único de la suscripción. */
    public $id;

    /** @var string Código del cliente. */
    public $codcustomer;

    /** @var int Perfil de renovación aplicado; puede ser null. */
    public $profile_id;

    /** @var int Producto asociado. */
    public $idproduct;

    /** @var string Título descriptivo del servicio. */
    public $title;

    /** @var string Identificador concreto del servicio (ejemplo.com, vps-01...). */
    public $service_identifier;

    /** @var string Tipo de servicio; null usa el del perfil. */
    public $service_type;

    /** @var string Nombre del proveedor o registrador. */
    public $provider_name;

    /** @var string Referencia externa en el proveedor. */
    public $external_reference;

    /** @var string Fecha de alta del servicio (Y-m-d). */
    public $start_date;

    /** @var string Fecha real de vencimiento (Y-m-d). */
    public $expiration_date;

    /** @var int Periodicidad de renovación en meses. */
    public $period_months;

    /** @var int Días de antelación propios; null usa el perfil o la configuración global. */
    public $quote_lead_days;

    /** @var string Lista CSV de días de recordatorio propia; null usa el perfil o la global. */
    public $reminder_days;

    /** @var string Email de aviso propio; null usa el email de facturación del cliente. */
    public $email_override;

    /** @var float Precio propio; null usa el precio del producto. */
    public $price_override;

    /** @var int Generar presupuesto automáticamente: 1, 0 o null para heredar. */
    public $auto_generate_quote;

    /** @var int Enviar presupuesto automáticamente: 1, 0 o null para heredar. */
    public $auto_send_quote;

    /** @var string Política de renovación propia; null hereda del perfil o la global. */
    public $renewal_trigger;

    /** @var string Estado del servicio. */
    public $status;

    /** @var string Notas internas. */
    public $notes;

    /** @var string Fecha y hora de creación. */
    public $created_at;

    /** @var string Fecha y hora de la última modificación. */
    public $updated_at;

    /** @var ServiceRenewalProfile|null Caché del perfil aplicado. */
    private $profileCache;

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public static function tableName(): string
    {
        return 'service_renewals';
    }

    public function clear(): void
    {
        parent::clear();
        $this->status = self::STATUS_ACTIVE;
        $this->period_months = 12;
        $this->created_at = Tools::dateTime();
    }

    /** Días naturales hasta el vencimiento; negativo si ya ha vencido. */
    public function daysToExpiration(string $today): int
    {
        $expiration = RenewalDateCalculator::toIso($this->expiration_date);
        $from = RenewalDateCalculator::toIso($today);
        if (null === $expiration || null === $from) {
            return PHP_INT_MAX;
        }

        return RenewalDateCalculator::daysUntil($expiration, $from);
    }

    /** Valor efectivo: la suscripción prevalece sobre el perfil y este sobre la configuración global. */
    public function effectiveQuoteLeadDays(): int
    {
        if (null !== $this->quote_lead_days) {
            return max(0, (int)$this->quote_lead_days);
        }

        $profile = $this->getProfile();
        if (null !== $profile && null !== $profile->quote_lead_days) {
            return max(0, (int)$profile->quote_lead_days);
        }

        return ServiceRenewalsSettings::defaultQuoteLeadDays();
    }

    /** @return int[] Días de recordatorio efectivos, en orden descendente. */
    public function effectiveReminderDays(): array
    {
        if (null !== $this->reminder_days && '' !== trim((string)$this->reminder_days)) {
            return ReminderDayList::toArray($this->reminder_days);
        }

        $profile = $this->getProfile();
        if (null !== $profile && null !== $profile->reminder_days && '' !== trim((string)$profile->reminder_days)) {
            return ReminderDayList::toArray($profile->reminder_days);
        }

        return ReminderDayList::toArray(ServiceRenewalsSettings::defaultReminderDays());
    }

    public function effectiveAutoGenerateQuote(): bool
    {
        if (null !== $this->auto_generate_quote) {
            return (bool)$this->auto_generate_quote;
        }

        $profile = $this->getProfile();

        return null === $profile || (bool)$profile->auto_generate_quote;
    }

    public function effectiveAutoSendQuote(): bool
    {
        if (null !== $this->auto_send_quote) {
            return (bool)$this->auto_send_quote;
        }

        $profile = $this->getProfile();
        if (null !== $profile) {
            return (bool)$profile->auto_send_quote;
        }

        return ServiceRenewalsSettings::defaultAutoSendQuote();
    }

    public function effectiveRenewalTrigger(): string
    {
        $valid = [ServiceRenewalProfile::TRIGGER_INVOICE, ServiceRenewalProfile::TRIGGER_MANUAL];
        if (in_array($this->renewal_trigger, $valid, true)) {
            return $this->renewal_trigger;
        }

        $profile = $this->getProfile();
        if (null !== $profile && in_array($profile->renewal_trigger, $valid, true)) {
            return $profile->renewal_trigger;
        }

        return ServiceRenewalsSettings::defaultRenewalTrigger();
    }

    public function effectiveServiceType(): string
    {
        if (!empty($this->service_type)) {
            return $this->service_type;
        }

        $profile = $this->getProfile();

        return null !== $profile && !empty($profile->service_type) ? $profile->service_type : 'other';
    }

    public function getCustomer(): Cliente
    {
        $customer = new Cliente();
        $customer->load((string)$this->codcustomer);

        return $customer;
    }

    public function getProduct(): Producto
    {
        $product = new Producto();
        $product->load((string)$this->idproduct);

        return $product;
    }

    public function getProfile(): ?ServiceRenewalProfile
    {
        if (null !== $this->profileCache && (int)$this->profileCache->idproduct === (int)$this->idproduct) {
            return $this->profileCache;
        }

        $this->profileCache = null;
        if (!empty($this->profile_id)) {
            $this->profileCache = ServiceRenewalProfile::find($this->profile_id);
        }
        if (null === $this->profileCache && !empty($this->idproduct)) {
            $this->profileCache = ServiceRenewalProfile::findWhere([Where::eq('idproduct', $this->idproduct)]);
        }

        return $this->profileCache;
    }

    /** @return ServiceRenewalCycle[] Ciclos de la suscripción, el más reciente primero. */
    public function getCycles(): array
    {
        return ServiceRenewalCycle::all(
            [Where::eq('service_renewal_id', $this->id)],
            ['previous_expiration_date' => 'DESC']
        );
    }

    /** Ciclo abierto (ni renovado, ni fallido, ni cancelado) más reciente, si existe. */
    public function getOpenCycle(): ?ServiceRenewalCycle
    {
        return ServiceRenewalCycle::findWhere(
            [
                Where::eq('service_renewal_id', $this->id),
                Where::notIn('status', [
                    ServiceRenewalCycle::STATUS_RENEWED,
                    ServiceRenewalCycle::STATUS_CANCELLED,
                ]),
            ],
            ['previous_expiration_date' => 'DESC']
        );
    }

    public function install(): string
    {
        // forzamos la creación previa de las tablas referenciadas por claves foráneas
        new Cliente();
        new Producto();
        new ServiceRenewalProfile();

        return parent::install();
    }

    public function test(): bool
    {
        $this->title = Tools::noHtml($this->title);
        $this->service_identifier = Tools::noHtml(trim((string)$this->service_identifier));
        $this->provider_name = Tools::noHtml($this->provider_name);
        $this->external_reference = Tools::noHtml($this->external_reference);
        $this->notes = Tools::noHtml($this->notes);

        if (empty($this->codcustomer) || false === $this->getCustomer()->exists()) {
            Tools::log()->warning('service-renewal-no-customer');
            return false;
        }

        if (empty($this->idproduct) || false === $this->getProduct()->exists()) {
            Tools::log()->warning('service-renewal-no-product');
            return false;
        }

        if (empty($this->service_identifier)) {
            Tools::log()->warning('service-renewal-no-identifier');
            return false;
        }

        $expiration = RenewalDateCalculator::toIso($this->expiration_date);
        if (null === $expiration) {
            Tools::log()->warning('service-renewal-no-expiration');
            return false;
        }
        $this->expiration_date = $expiration;
        $this->start_date = RenewalDateCalculator::toIso($this->start_date);

        if ((int)$this->period_months < 1) {
            Tools::log()->warning('service-renewal-invalid-period');
            return false;
        }

        if (null !== $this->quote_lead_days && (int)$this->quote_lead_days < 0) {
            Tools::log()->warning('service-renewal-invalid-lead-days');
            return false;
        }

        if (false === ReminderDayList::isValid($this->reminder_days)) {
            Tools::log()->warning('service-renewal-invalid-reminder-days');
            return false;
        }
        $this->reminder_days = ReminderDayList::normalize($this->reminder_days);

        if (!empty($this->email_override) && false === Validator::email($this->email_override)) {
            Tools::log()->warning('service-renewal-invalid-email');
            return false;
        }

        if (false === in_array($this->status, self::STATUSES, true)) {
            Tools::log()->warning('service-renewal-invalid-status');
            return false;
        }

        $types = array_merge([null, ''], ServiceRenewalProfile::SERVICE_TYPES);
        if (false === in_array($this->service_type, $types, true)) {
            Tools::log()->warning('service-renewal-invalid-service-type');
            return false;
        }

        $triggers = [null, '', ServiceRenewalProfile::TRIGGER_INVOICE, ServiceRenewalProfile::TRIGGER_MANUAL];
        if (false === in_array($this->renewal_trigger, $triggers, true)) {
            Tools::log()->warning('service-renewal-invalid-trigger');
            return false;
        }
        if ('' === $this->renewal_trigger) {
            $this->renewal_trigger = null;
        }
        if ('' === $this->service_type) {
            $this->service_type = null;
        }

        // vinculamos el perfil del producto si no se ha indicado
        if (empty($this->profile_id)) {
            $profile = ServiceRenewalProfile::findWhere([Where::eq('idproduct', $this->idproduct)]);
            $this->profile_id = null !== $profile ? $profile->id : null;
        }

        $this->updated_at = Tools::dateTime();

        return parent::test();
    }
}
