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
use FacturaScripts\Dinamic\Model\Producto;
use FacturaScripts\Plugins\ServiceRenewals\Lib\ReminderDayList;

/**
 * Perfil de renovación de un producto.
 *
 * Contiene los valores predeterminados de renovación que se aplican a las
 * suscripciones creadas sobre ese producto. La fecha de vencimiento real
 * nunca se guarda aquí: pertenece a cada suscripción.
 *
 * @author Ernesto Serrano <info@ernesto.es>
 */
class ServiceRenewalProfile extends ModelClass
{
    use ModelTrait;

    /** Renovación al detectar la factura del presupuesto. */
    public const TRIGGER_INVOICE = 'invoice';

    /** Renovación mediante confirmación manual. */
    public const TRIGGER_MANUAL = 'manual';

    /** Tipos de servicio admitidos. */
    public const SERVICE_TYPES = [
        'domain',
        'hosting',
        'vps',
        'dedicated_server',
        'certificate',
        'maintenance',
        'license',
        'backup',
        'other',
    ];

    /** @var int Identificador único del perfil. */
    public $id;

    /** @var int Producto al que pertenece el perfil (relación uno a uno). */
    public $idproduct;

    /** @var bool Indica si el perfil está activo. */
    public $enabled;

    /** @var string Tipo de servicio predeterminado. */
    public $service_type;

    /** @var int Periodicidad predeterminada en meses. */
    public $default_period_months;

    /** @var int Días de antelación para generar el presupuesto; null usa la configuración global. */
    public $quote_lead_days;

    /** @var string Lista CSV de días de recordatorio; null usa la configuración global. */
    public $reminder_days;

    /** @var bool Generar presupuesto automáticamente. */
    public $auto_generate_quote;

    /** @var bool Enviar el presupuesto por email automáticamente. */
    public $auto_send_quote;

    /** @var string Política de renovación; null usa la configuración global. */
    public $renewal_trigger;

    /** @var string Fecha y hora de creación. */
    public $created_at;

    /** @var string Fecha y hora de la última modificación. */
    public $updated_at;

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public static function tableName(): string
    {
        return 'service_renewal_profiles';
    }

    public function clear(): void
    {
        parent::clear();
        $this->enabled = true;
        $this->service_type = 'other';
        $this->default_period_months = 12;
        $this->auto_generate_quote = true;
        $this->auto_send_quote = true;
        $this->created_at = Tools::dateTime();
    }

    public function getProduct(): Producto
    {
        $product = new Producto();
        $product->load((string)$this->idproduct);

        return $product;
    }

    public function install(): string
    {
        // forzamos la creación previa de la tabla de productos por la clave foránea
        new Producto();

        return parent::install();
    }

    public function test(): bool
    {
        if (empty($this->idproduct)) {
            Tools::log()->warning('service-renewal-profile-no-product');
            return false;
        }

        if ((int)$this->default_period_months < 1) {
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

        if (false === in_array($this->service_type, self::SERVICE_TYPES, true)) {
            Tools::log()->warning('service-renewal-invalid-service-type');
            return false;
        }

        $triggers = [null, '', self::TRIGGER_INVOICE, self::TRIGGER_MANUAL];
        if (false === in_array($this->renewal_trigger, $triggers, true)) {
            Tools::log()->warning('service-renewal-invalid-trigger');
            return false;
        }
        if ('' === $this->renewal_trigger) {
            $this->renewal_trigger = null;
        }

        $this->updated_at = Tools::dateTime();

        return parent::test();
    }

    public function url(string $type = 'auto', string $list = 'List'): string
    {
        // el perfil se gestiona desde la pestaña de renovaciones del producto
        if (in_array($type, ['auto', 'edit', 'list', 'new'], true) && !empty($this->idproduct)) {
            return 'EditProducto?code=' . $this->idproduct;
        }

        return parent::url($type, $list);
    }
}
