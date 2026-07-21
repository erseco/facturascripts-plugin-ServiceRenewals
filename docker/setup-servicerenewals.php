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

/**
 * Configuración automática del entorno de desarrollo Docker:
 *   - activa el plugin ServiceRenewals
 *   - crea las tablas del plugin
 *   - configura el SMTP de Mailpit
 *   - crea perfiles y suscripciones de demostración
 *
 * Se ejecuta desde POST_CONFIGURE_COMMANDS en docker-compose.yml.
 */

if (PHP_SAPI !== 'cli') {
    exit("This script must be run from the command line.\n");
}

define('FS_FOLDER', '/var/www/html');

require_once FS_FOLDER . '/vendor/autoload.php';
require_once FS_FOLDER . '/config.php';

use FacturaScripts\Core\Kernel;
use FacturaScripts\Core\Plugins;
use FacturaScripts\Core\Where;

Kernel::init();

// activamos el plugin si hace falta
if (false === in_array('ServiceRenewals', Plugins::enabled(), true)) {
    echo "[setup-servicerenewals] Enabling ServiceRenewals plugin...\n";
    if (false === Plugins::enable('ServiceRenewals')) {
        echo "[setup-servicerenewals] ERROR: could not enable the plugin.\n";
        exit(1);
    }
    Plugins::deploy(true, true);
} else {
    echo "[setup-servicerenewals] Plugin already enabled.\n";
}

// forzamos la creación de las tablas del plugin
new FacturaScripts\Dinamic\Model\ServiceRenewalProfile();
new FacturaScripts\Dinamic\Model\ServiceRenewal();
new FacturaScripts\Dinamic\Model\ServiceRenewalCycle();
new FacturaScripts\Dinamic\Model\ServiceRenewalNotification();
echo "[setup-servicerenewals] Tables ready.\n";

// configuración SMTP hacia Mailpit
$email = new FacturaScripts\Dinamic\Model\Settings();
$email->loadFromCode('email');
$email->name = 'email';
$email->email = 'facturacion@example.com';
$email->mailer = 'smtp';
$email->host = 'mailpit';
$email->port = '1025';
$email->user = '';
$email->password = 'mailpit';
$email->lowsecure = '1';
$email->enc = '';
$email->save();
echo "[setup-servicerenewals] Mailpit SMTP configured.\n";

/** Crea o recupera el perfil de renovación de un producto. */
function demoProfile(string $reference, array $data): ?int
{
    $product = FacturaScripts\Dinamic\Model\Producto::findWhere([Where::eq('referencia', $reference)]);
    if (null === $product) {
        echo "[setup-servicerenewals] Product not found: {$reference}\n";
        return null;
    }

    $profile = FacturaScripts\Dinamic\Model\ServiceRenewalProfile::findWhere([
        Where::eq('idproduct', $product->idproducto),
    ]);
    if (null !== $profile) {
        return $profile->id;
    }

    $profile = new FacturaScripts\Dinamic\Model\ServiceRenewalProfile();
    $profile->idproduct = $product->idproducto;
    foreach ($data as $key => $value) {
        $profile->{$key} = $value;
    }

    if ($profile->save()) {
        echo "[setup-servicerenewals] Profile created for {$reference}.\n";
        return $profile->id;
    }

    echo "[setup-servicerenewals] ERROR: could not create profile for {$reference}.\n";
    return null;
}

/** Crea una suscripción de demostración si no existe. */
function demoRenewal(string $identifier, string $customerName, string $reference, array $data): void
{
    $existing = FacturaScripts\Dinamic\Model\ServiceRenewal::findWhere([
        Where::eq('service_identifier', $identifier),
    ]);
    if (null !== $existing) {
        return;
    }

    $customer = FacturaScripts\Dinamic\Model\Cliente::findWhere([Where::like('nombre', $customerName)]);
    $product = FacturaScripts\Dinamic\Model\Producto::findWhere([Where::eq('referencia', $reference)]);
    if (null === $customer || null === $product) {
        echo "[setup-servicerenewals] Missing customer or product for {$identifier}.\n";
        return;
    }

    $renewal = new FacturaScripts\Dinamic\Model\ServiceRenewal();
    $renewal->codcustomer = $customer->codcliente;
    $renewal->idproduct = $product->idproducto;
    $renewal->service_identifier = $identifier;
    foreach ($data as $key => $value) {
        $renewal->{$key} = $value;
    }

    if ($renewal->save()) {
        echo "[setup-servicerenewals] Subscription created: {$identifier}\n";
    } else {
        echo "[setup-servicerenewals] ERROR: could not create subscription {$identifier}.\n";
    }
}

// perfiles de renovación por producto
demoProfile('DOM-COM', [
    'service_type' => 'domain',
    'default_period_months' => 12,
    'quote_lead_days' => 30,
    'reminder_days' => '15,7,3',
]);
demoProfile('HOST-ANUAL', [
    'service_type' => 'hosting',
    'default_period_months' => 12,
    'quote_lead_days' => 30,
    'reminder_days' => '15,7',
]);
demoProfile('VPS-MENSUAL', [
    'service_type' => 'vps',
    'default_period_months' => 1,
    'quote_lead_days' => 10,
    'reminder_days' => '5,2',
]);
demoProfile('SSL-ANUAL', [
    'service_type' => 'certificate',
    'default_period_months' => 12,
    'quote_lead_days' => 30,
    'renewal_trigger' => 'manual',
]);

// suscripciones de demostración con fechas relativas reproducibles
demoRenewal('ejemplo.com', 'Empresa Demo', 'DOM-COM', [
    'title' => 'Dominio ejemplo.com',
    'provider_name' => 'Openprovider',
    'start_date' => date('Y-m-d', strtotime('-11 months')),
    'expiration_date' => date('Y-m-d', strtotime('+30 days')),
    'period_months' => 12,
]);
demoRenewal('hosting-ejemplo', 'Asociación Ejemplo', 'HOST-ANUAL', [
    'title' => 'Hosting web asociación',
    'provider_name' => 'Hetzner',
    'start_date' => date('Y-m-d', strtotime('-1 year')),
    'expiration_date' => date('Y-m-d', strtotime('+7 days')),
    'period_months' => 12,
]);
demoRenewal('vps-production-01', 'Profesional de Prueba', 'VPS-MENSUAL', [
    'title' => 'VPS de producción',
    'provider_name' => 'DigitalOcean',
    'start_date' => date('Y-m-d', strtotime('-6 months')),
    'expiration_date' => date('Y-m-d', strtotime('-5 days')),
    'period_months' => 1,
]);
demoRenewal('ssl-ejemplo.com', 'Empresa Demo', 'SSL-ANUAL', [
    'title' => 'Certificado SSL ejemplo.com',
    'provider_name' => 'Sectigo',
    'start_date' => date('Y-m-d', strtotime('-11 months')),
    'expiration_date' => date('Y-m-d', strtotime('+20 days')),
    'period_months' => 12,
]);
demoRenewal('backup-nas-oficina', 'Empresa Demo', 'HOST-ANUAL', [
    'title' => 'Copia de seguridad NAS',
    'service_type' => 'backup',
    'provider_name' => 'Backblaze',
    'start_date' => date('Y-m-d', strtotime('-2 years')),
    'expiration_date' => date('Y-m-d', strtotime('+45 days')),
    'period_months' => 12,
    'status' => 'suspended',
]);

echo "[setup-servicerenewals] Demo data ready.\n";
echo "[setup-servicerenewals] Run 'make cron' to process renewals and send emails to Mailpit.\n";
