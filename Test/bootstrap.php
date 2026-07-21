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
 * Bootstrap de PHPUnit para los tests del plugin.
 *
 * Este archivo está pensado para copiarse en el directorio Test/ del
 * núcleo de FacturaScripts (igual que hacen los plugins de referencia).
 * Define FS_FOLDER, carga el autoloader del núcleo y registra los
 * namespaces del núcleo y del plugin.
 */

define('FS_FOLDER', __DIR__ . '/..');

require_once FS_FOLDER . '/vendor/autoload.php';

if (file_exists(FS_FOLDER . '/config.php')) {
    require_once FS_FOLDER . '/config.php';
}

// Valores por defecto cuando no existe config.php (tests sin base de datos).
defined('FS_LANG') || define('FS_LANG', 'es_ES');
defined('FS_TIMEZONE') || define('FS_TIMEZONE', 'Europe/Madrid');
defined('FS_NF0') || define('FS_NF0', 2);
defined('FS_NF1') || define('FS_NF1', ',');
defined('FS_NF2') || define('FS_NF2', '.');
defined('FS_CODPAIS') || define('FS_CODPAIS', 'ESP');
defined('FS_CURRENCY_POS') || define('FS_CURRENCY_POS', 'right');
defined('FS_ITEM_LIMIT') || define('FS_ITEM_LIMIT', 50);

date_default_timezone_set(FS_TIMEZONE);

/** @var Composer\Autoload\ClassLoader $loader */
$loader = require FS_FOLDER . '/vendor/autoload.php';
$loader->addPsr4('FacturaScripts\\Core\\', FS_FOLDER . '/Core');
$loader->addPsr4('FacturaScripts\\Dinamic\\', FS_FOLDER . '/Dinamic');
$loader->addPsr4('FacturaScripts\\Plugins\\ServiceRenewals\\', FS_FOLDER . '/Plugins/ServiceRenewals');
