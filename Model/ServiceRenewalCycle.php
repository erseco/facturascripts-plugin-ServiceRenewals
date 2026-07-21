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
use FacturaScripts\Dinamic\Model\FacturaCliente;
use FacturaScripts\Dinamic\Model\PresupuestoCliente;
use FacturaScripts\Plugins\ServiceRenewals\Lib\RenewalDateCalculator;

/**
 * Ciclo de renovación de una suscripción.
 *
 * Representa el historial de una renovación concreta: el presupuesto
 * generado, la factura detectada y el avance de fecha aplicado. La
 * restricción única (service_renewal_id, previous_expiration_date)
 * garantiza que el cron pueda repetirse sin crear duplicados.
 *
 * @author Ernesto Serrano <info@ernesto.es>
 */
class ServiceRenewalCycle extends ModelClass
{
    use ModelTrait;

    public const STATUS_PENDING = 'pending';
    public const STATUS_QUOTE_CREATED = 'quote_created';
    public const STATUS_QUOTE_SENT = 'quote_sent';
    public const STATUS_INVOICED = 'invoiced';
    public const STATUS_RENEWAL_PENDING = 'renewal_pending';
    public const STATUS_RENEWED = 'renewed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_QUOTE_CREATED,
        self::STATUS_QUOTE_SENT,
        self::STATUS_INVOICED,
        self::STATUS_RENEWAL_PENDING,
        self::STATUS_RENEWED,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
    ];

    /** Estados en los que el ciclo sigue abierto (admite acciones automáticas). */
    public const OPEN_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_QUOTE_CREATED,
        self::STATUS_QUOTE_SENT,
        self::STATUS_INVOICED,
        self::STATUS_RENEWAL_PENDING,
        self::STATUS_FAILED,
    ];

    /** @var int Identificador único del ciclo. */
    public $id;

    /** @var int Suscripción a la que pertenece el ciclo. */
    public $service_renewal_id;

    /** @var string Fecha de vencimiento que cubre este ciclo (Y-m-d). */
    public $previous_expiration_date;

    /** @var string Nueva fecha de vencimiento tras renovar (Y-m-d). */
    public $next_expiration_date;

    /** @var string Estado del ciclo. */
    public $status;

    /** @var int Presupuesto generado (idpresupuesto); puede ser null. */
    public $quote_id;

    /** @var int Factura detectada (idfactura); puede ser null. */
    public $invoice_id;

    /** @var string Fecha y hora de creación del presupuesto. */
    public $quote_created_at;

    /** @var string Fecha y hora de envío del presupuesto. */
    public $quote_sent_at;

    /** @var string Fecha y hora de detección de la factura. */
    public $invoice_detected_at;

    /** @var string Fecha y hora en la que se aplicó la renovación. */
    public $renewed_at;

    /** @var string Último error registrado en el ciclo. */
    public $last_error;

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
        return 'service_renewal_cycles';
    }

    public function clear(): void
    {
        parent::clear();
        $this->status = self::STATUS_PENDING;
        $this->created_at = Tools::dateTime();
    }

    public function getRenewal(): ServiceRenewal
    {
        $renewal = new ServiceRenewal();
        $renewal->load((string)$this->service_renewal_id);

        return $renewal;
    }

    public function getQuote(): ?PresupuestoCliente
    {
        if (empty($this->quote_id)) {
            return null;
        }

        $quote = new PresupuestoCliente();

        return $quote->load((string)$this->quote_id) ? $quote : null;
    }

    public function getInvoice(): ?FacturaCliente
    {
        if (empty($this->invoice_id)) {
            return null;
        }

        $invoice = new FacturaCliente();

        return $invoice->load((string)$this->invoice_id) ? $invoice : null;
    }

    public function install(): string
    {
        // forzamos la creación previa de la tabla de suscripciones por la clave foránea
        new ServiceRenewal();

        return parent::install();
    }

    public function test(): bool
    {
        if (empty($this->service_renewal_id)) {
            Tools::log()->warning('service-renewal-cycle-no-renewal');
            return false;
        }

        $previous = RenewalDateCalculator::toIso($this->previous_expiration_date);
        $next = RenewalDateCalculator::toIso($this->next_expiration_date);
        if (null === $previous || null === $next) {
            Tools::log()->warning('service-renewal-cycle-invalid-dates');
            return false;
        }
        $this->previous_expiration_date = $previous;
        $this->next_expiration_date = $next;

        if (false === in_array($this->status, self::STATUSES, true)) {
            Tools::log()->warning('service-renewal-cycle-invalid-status');
            return false;
        }

        $this->last_error = Tools::noHtml($this->last_error);
        $this->updated_at = Tools::dateTime();

        return parent::test();
    }

    public function url(string $type = 'auto', string $list = 'List'): string
    {
        // los ciclos se consultan desde la ficha de la suscripción
        if (!empty($this->service_renewal_id)) {
            return 'EditServiceRenewal?code=' . $this->service_renewal_id;
        }

        return 'ListServiceRenewal';
    }
}
