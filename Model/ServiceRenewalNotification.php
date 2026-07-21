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

/**
 * Notificación de renovación (presupuesto o recordatorio).
 *
 * Se persiste antes de encolar el envío. La restricción única
 * (cycle_id, notification_type, reminder_day) impide enviar dos veces
 * el mismo aviso. Los adjuntos se guardan como JSON con rutas relativas
 * a la carpeta MyFiles/ServiceRenewals/<id>/.
 *
 * @author Ernesto Serrano <info@ernesto.es>
 */
class ServiceRenewalNotification extends ModelClass
{
    use ModelTrait;

    public const TYPE_QUOTE = 'quote';
    public const TYPE_REMINDER = 'reminder';

    public const TYPES = [self::TYPE_QUOTE, self::TYPE_REMINDER];

    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_SENT,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
    ];

    /** Carpeta base de los adjuntos temporales. */
    public const FILES_PATH = 'MyFiles/ServiceRenewals';

    /** @var int Identificador único de la notificación. */
    public $id;

    /** @var int Ciclo al que pertenece la notificación. */
    public $cycle_id;

    /** @var string Tipo de notificación: quote o reminder. */
    public $notification_type;

    /** @var int Regla de días del recordatorio; 0 para las notificaciones de presupuesto. */
    public $reminder_day;

    /** @var string Fecha y hora en la que se programó el envío. */
    public $scheduled_at;

    /** @var string Fecha y hora del envío correcto. */
    public $sent_at;

    /** @var string Destinatario principal. */
    public $recipient;

    /** @var string Copia (CC). */
    public $cc;

    /** @var string Copia oculta (BCC). */
    public $bcc;

    /** @var string Asunto del email. */
    public $subject;

    /** @var string Cuerpo del email. */
    public $body;

    /** @var string Adjuntos en JSON: lista de {file, name}. */
    public $attachments;

    /** @var string Estado de la notificación. */
    public $status;

    /** @var int Número de intentos de envío realizados. */
    public $attempts;

    /** @var string Último error de envío. */
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
        return 'service_renewal_notifications';
    }

    public function clear(): void
    {
        parent::clear();
        $this->status = self::STATUS_PENDING;
        $this->notification_type = self::TYPE_QUOTE;
        $this->reminder_day = 0;
        $this->attempts = 0;
        $this->created_at = Tools::dateTime();
    }

    public function delete(): bool
    {
        if (false === parent::delete()) {
            return false;
        }

        $this->deleteFiles();

        return true;
    }

    /** Elimina la carpeta de adjuntos temporales de la notificación. */
    public function deleteFiles(): void
    {
        $folder = $this->getFilesFolder();
        if (is_dir($folder)) {
            Tools::folderDelete($folder);
        }
    }

    /** @return array<int, array{file: string, name: string}> */
    public function getAttachments(): array
    {
        if (empty($this->attachments)) {
            return [];
        }

        $decoded = json_decode((string)$this->attachments, true);
        if (false === is_array($decoded)) {
            return [];
        }

        $result = [];
        foreach ($decoded as $item) {
            if (is_array($item) && isset($item['file'], $item['name'])) {
                $result[] = ['file' => (string)$item['file'], 'name' => (string)$item['name']];
            }
        }

        return $result;
    }

    /** Carpeta absoluta de los adjuntos de esta notificación. */
    public function getFilesFolder(): string
    {
        return Tools::folder(self::FILES_PATH, (string)$this->id);
    }

    public function getCycle(): ServiceRenewalCycle
    {
        $cycle = new ServiceRenewalCycle();
        $cycle->load((string)$this->cycle_id);

        return $cycle;
    }

    public function install(): string
    {
        // forzamos la creación previa de la tabla de ciclos por la clave foránea
        new ServiceRenewalCycle();

        return parent::install();
    }

    /**
     * @param array<int, array{file: string, name: string}> $attachments
     */
    public function setAttachments(array $attachments): void
    {
        $this->attachments = empty($attachments) ? null : json_encode(array_values($attachments));
    }

    public function test(): bool
    {
        if (empty($this->cycle_id)) {
            Tools::log()->warning('service-renewal-notification-no-cycle');
            return false;
        }

        if (false === in_array($this->notification_type, self::TYPES, true)) {
            Tools::log()->warning('service-renewal-notification-invalid-type');
            return false;
        }

        if (false === in_array($this->status, self::STATUSES, true)) {
            Tools::log()->warning('service-renewal-notification-invalid-status');
            return false;
        }

        if ((int)$this->reminder_day < 0 || (int)$this->attempts < 0) {
            Tools::log()->warning('service-renewal-notification-invalid-values');
            return false;
        }

        foreach (['recipient', 'cc', 'bcc'] as $field) {
            if (false === $this->validateEmailList((string)$this->{$field})) {
                Tools::log()->warning('service-renewal-notification-invalid-email', ['%field%' => $field]);
                return false;
            }
        }

        $this->subject = Tools::noHtml($this->subject);
        $this->last_error = Tools::noHtml($this->last_error);
        $this->updated_at = Tools::dateTime();

        return parent::test();
    }

    public function url(string $type = 'auto', string $list = 'List'): string
    {
        // las notificaciones se consultan desde la ficha de la suscripción
        $cycle = $this->getCycle();
        if (!empty($cycle->service_renewal_id)) {
            return 'EditServiceRenewal?code=' . $cycle->service_renewal_id;
        }

        return 'ListServiceRenewal';
    }

    /** Valida una lista de emails separados por comas; la cadena vacía es válida. */
    private function validateEmailList(string $emails): bool
    {
        foreach (explode(',', $emails) as $email) {
            $email = trim($email);
            if ('' !== $email && false === Validator::email($email)) {
                return false;
            }
        }

        return true;
    }
}
