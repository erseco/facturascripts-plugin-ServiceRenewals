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

use FacturaScripts\Core\Tools;

/**
 * Acceso tipado a la configuración global del plugin.
 *
 * Las lecturas pasan siempre por Tools::settings('ServiceRenewals', ...)
 * con los valores predeterminados de DEFAULTS como respaldo. La escritura
 * se realiza desde el panel de configuración mediante el modelo Settings
 * del núcleo.
 *
 * @author Ernesto Serrano <info@ernesto.es>
 */
final class ServiceRenewalsSettings
{
    /** Grupo de configuración en la tabla settings del núcleo. */
    public const GROUP = 'ServiceRenewals';

    /** Valores predeterminados: fuente única de verdad. */
    private const DEFAULTS = [
        'enabled' => true,
        'default_quote_lead_days' => 30,
        'default_reminder_days' => '15,7,3',
        'default_auto_send_quote' => true,
        'default_renewal_trigger' => 'invoice',
        'quote_email_subject' => 'Renovación de {{service_title}} ({{service_identifier}})',
        'quote_email_body' => "Estimado cliente {{client_name}}:\n\n"
            . "El servicio {{service_title}} ({{service_identifier}}) vence el {{expiration_date}}.\n"
            . "Le adjuntamos el presupuesto {{quote_code}} para renovarlo hasta el {{next_expiration_date}}.\n\n"
            . "Un saludo,\n{{company_name}}",
        'reminder_email_subject' => 'Recordatorio: {{service_title}} ({{service_identifier}}) vence pronto',
        'reminder_email_body' => "Estimado cliente {{client_name}}:\n\n"
            . 'Le recordamos que el servicio {{service_title}} ({{service_identifier}}) '
            . "vence el {{expiration_date}}.\n\n"
            . "Un saludo,\n{{company_name}}",
        'global_cc' => '',
        'global_bcc' => '',
        'max_attempts' => 3,
        'from_email' => '',
    ];

    /**
     * Lee una clave de configuración con respaldo en DEFAULTS.
     *
     * @return mixed
     */
    public static function get(string $key, $default = null)
    {
        $fallback = $default ?? self::DEFAULTS[$key] ?? null;

        return Tools::settings(self::GROUP, $key, $fallback);
    }

    /** @return array<string, mixed> */
    public static function getDefaults(): array
    {
        return self::DEFAULTS;
    }

    /** Procesamiento automático activado. */
    public static function isEnabled(): bool
    {
        return (bool)self::get('enabled');
    }

    /** Días de antelación predeterminados para generar el presupuesto. */
    public static function defaultQuoteLeadDays(): int
    {
        return max(0, (int)self::get('default_quote_lead_days'));
    }

    /** Lista CSV normalizada de días de recordatorio predeterminados. */
    public static function defaultReminderDays(): string
    {
        return ReminderDayList::normalize((string)self::get('default_reminder_days')) ?? '';
    }

    /** Envío automático del presupuesto por email. */
    public static function defaultAutoSendQuote(): bool
    {
        return (bool)self::get('default_auto_send_quote');
    }

    /** Política de renovación predeterminada: invoice o manual. */
    public static function defaultRenewalTrigger(): string
    {
        $value = (string)self::get('default_renewal_trigger');

        return in_array($value, ['invoice', 'manual'], true) ? $value : 'invoice';
    }

    public static function quoteEmailSubject(): string
    {
        return (string)self::get('quote_email_subject');
    }

    public static function quoteEmailBody(): string
    {
        return (string)self::get('quote_email_body');
    }

    public static function reminderEmailSubject(): string
    {
        return (string)self::get('reminder_email_subject');
    }

    public static function reminderEmailBody(): string
    {
        return (string)self::get('reminder_email_body');
    }

    public static function globalCc(): string
    {
        return trim((string)self::get('global_cc'));
    }

    public static function globalBcc(): string
    {
        return trim((string)self::get('global_bcc'));
    }

    /** Número máximo de intentos de envío de una notificación. */
    public static function maxAttempts(): int
    {
        return max(1, (int)self::get('max_attempts'));
    }

    /** Buzón remitente opcional; vacío para usar el predeterminado del núcleo. */
    public static function fromEmail(): string
    {
        return trim((string)self::get('from_email'));
    }
}
