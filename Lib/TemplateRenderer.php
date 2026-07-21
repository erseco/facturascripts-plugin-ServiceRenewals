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

/**
 * Renderizador de plantillas de email con marcadores {{nombre}}.
 *
 * Solo realiza sustitución literal de marcadores: no evalúa código ni
 * expande de nuevo los valores sustituidos. Los marcadores desconocidos
 * se conservan en el texto y se devuelven en la lista de desconocidos
 * para que el llamador pueda avisar del problema.
 *
 * @author Ernesto Serrano <info@ernesto.es>
 */
final class TemplateRenderer
{
    /** Expresión regular de un marcador: {{clave}} con espacios opcionales. */
    private const PLACEHOLDER_PATTERN = '/\{\{\s*([a-z0-9_]+)\s*\}\}/i';

    /**
     * Sustituye los marcadores conocidos de la plantilla.
     *
     * @param array<string, string|int|float|null> $values     valores por clave de marcador
     * @param bool                                 $escapeHtml escapa los valores para contexto HTML
     */
    public static function render(string $template, array $values, bool $escapeHtml = false): TemplateResult
    {
        $unknown = [];

        $text = (string)preg_replace_callback(
            self::PLACEHOLDER_PATTERN,
            function (array $match) use ($values, $escapeHtml, &$unknown): string {
                $key = $match[1];
                if (false === array_key_exists($key, $values)) {
                    if (false === in_array($key, $unknown, true)) {
                        $unknown[] = $key;
                    }

                    // Conservamos el marcador original para que el problema sea visible.
                    return $match[0];
                }

                $value = (string)($values[$key] ?? '');

                return $escapeHtml ? htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : $value;
            },
            $template
        );

        return new TemplateResult($text, $unknown);
    }
}
