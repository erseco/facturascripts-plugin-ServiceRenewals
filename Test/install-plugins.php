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
 * Prepara los plugins listados en Test/Plugins/install-plugins.txt para
 * los tests. Este archivo se copia al directorio Test/ del núcleo de
 * FacturaScripts, igual que en los plugins de referencia.
 */

$listFile = __DIR__ . '/Plugins/install-plugins.txt';
if (false === file_exists($listFile)) {
    echo "No install-plugins.txt found, nothing to do.\n";
    exit(0);
}

$plugins = array_filter(array_map('trim', (array)file($listFile)));
echo 'Plugins to prepare: ' . implode(', ', $plugins) . "\n";

foreach ($plugins as $plugin) {
    $folder = __DIR__ . '/../Plugins/' . $plugin;
    if (is_dir($folder)) {
        echo " - {$plugin}: found at Plugins/{$plugin}\n";
        continue;
    }

    echo " - {$plugin}: NOT found at Plugins/{$plugin}\n";
}

echo "Done.\n";
