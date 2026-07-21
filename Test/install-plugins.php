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
 * Activa y despliega los plugins listados en Test/Plugins/install-plugins.txt.
 *
 * Este archivo se copia al directorio Test/ del núcleo de FacturaScripts y se
 * ejecuta antes de los tests (Makefile y CI). La activación es imprescindible:
 * los XML de tablas de un plugin solo llegan a Dinamic/Table/ al desplegar
 * los plugins activados, y sin ellos las tablas se crearían vacías.
 */

if (PHP_SAPI !== 'cli') {
    exit("This script must be run from the command line.\n");
}

define('FS_FOLDER', dirname(__DIR__));

$listFile = __DIR__ . '/Plugins/install-plugins.txt';
if (false === file_exists($listFile)) {
    echo "No install-plugins.txt found, nothing to do.\n";
    exit(0);
}

if (false === file_exists(FS_FOLDER . '/config.php')) {
    echo "No config.php found, skipping plugin installation.\n";
    exit(0);
}

/** @var Composer\Autoload\ClassLoader $loader */
$loader = require FS_FOLDER . '/vendor/autoload.php';
require_once FS_FOLDER . '/config.php';

// registramos los namespaces del núcleo: el autoloader de composer puede
// haberse regenerado sin ellos al instalar las herramientas de desarrollo
$loader->addPsr4('FacturaScripts\\Core\\', FS_FOLDER . '/Core');
$loader->addPsr4('FacturaScripts\\Dinamic\\', FS_FOLDER . '/Dinamic');

use FacturaScripts\Core\Kernel;
use FacturaScripts\Core\Plugins;

Kernel::init();

$plugins = array_filter(array_map('trim', (array)file($listFile)));
echo 'Plugins to install: ' . implode(', ', $plugins) . "\n";

// las clases de Dinamic extienden las del plugin, así que su namespace
// también debe estar registrado antes del despliegue
foreach ($plugins as $plugin) {
    $loader->addPsr4('FacturaScripts\\Plugins\\' . $plugin . '\\', FS_FOLDER . '/Plugins/' . $plugin);
}

$changes = false;
foreach ($plugins as $plugin) {
    if (false === is_dir(FS_FOLDER . '/Plugins/' . $plugin)) {
        echo " - {$plugin}: ERROR, not found at Plugins/{$plugin}\n";
        exit(1);
    }

    if (in_array($plugin, Plugins::enabled(), true)) {
        echo " - {$plugin}: already enabled\n";
        continue;
    }

    if (false === Plugins::enable($plugin)) {
        echo " - {$plugin}: ERROR, could not be enabled\n";
        exit(1);
    }

    echo " - {$plugin}: enabled\n";
    $changes = true;
}

if ($changes) {
    Plugins::deploy(true, true);
    echo "Dinamic deployed.\n";
}

// aprovisionamiento mínimo para los tests, igual que hace el núcleo en su
// DefaultSettingsTrait: sin esto, una instalación inicializada por CLI no
// tiene todas las tablas ni los valores predeterminados de venta

// 1) instanciamos todos los modelos del núcleo para crear sus tablas con
//    los datos por defecto (fuera de cualquier transacción). Primero los
//    modelos base en el mismo orden que usa el instalador del núcleo
//    (Wizard::initModels), porque folderScan no garantiza el orden y hay
//    claves foráneas entre ellos.
$baseModels = [
    'AttachedFile', 'Diario', 'EstadoDocumento', 'FormaPago', 'Impuesto',
    'Retencion', 'Serie', 'Provincia', 'Empresa', 'Ejercicio', 'Almacen',
];
foreach ($baseModels as $name) {
    $className = '\\FacturaScripts\\Dinamic\\Model\\' . $name;
    if (class_exists($className)) {
        new $className();
    }
}

$modelFiles = FacturaScripts\Core\Tools::folderScan(FacturaScripts\Core\Tools::folder('Core', 'Model'));
sort($modelFiles);
foreach ($modelFiles as $fileName) {
    if ('.php' !== substr($fileName, -4)) {
        continue;
    }

    $className = '\\FacturaScripts\\Dinamic\\Model\\' . substr($fileName, 0, -4);
    if (false === class_exists($className)
        || false === is_subclass_of($className, FacturaScripts\Core\Template\ModelClass::class)
        || (new ReflectionClass($className))->isAbstract()) {
        continue;
    }

    new $className();
}
echo "Core tables ready.\n";

// 2) settings predeterminados del país (incluye serie, forma de pago, etc.)
$codpais = FacturaScripts\Core\DataSrc\Paises::default()->codpais;
$defaultsFile = FS_FOLDER . '/Core/Data/Codpais/' . $codpais . '/default.json';
if (file_exists($defaultsFile)) {
    $defaultValues = json_decode((string)file_get_contents($defaultsFile), true) ?? [];
    foreach ($defaultValues as $group => $values) {
        foreach ($values as $key => $value) {
            FacturaScripts\Core\Tools::settingsSet($group, $key, $value);
        }
    }
}

// 3) almacén predeterminado de la empresa
$idempresa = FacturaScripts\Core\Tools::settings('default', 'idempresa', 1);
foreach (FacturaScripts\Dinamic\Model\Almacen::all() as $warehouse) {
    if ((int)$warehouse->idempresa === (int)$idempresa) {
        FacturaScripts\Core\Tools::settingsSet('default', 'codalmacen', $warehouse->codalmacen);
    }
}

FacturaScripts\Core\Tools::settingsSave();
echo "Default settings ready.\n";

echo "Done.\n";
