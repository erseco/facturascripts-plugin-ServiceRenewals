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

namespace FacturaScripts\Test\Plugins;

use FacturaScripts\Plugins\ServiceRenewals\Lib\TemplateRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Tests del renderizador de plantillas de email.
 *
 * @author Ernesto Serrano <info@ernesto.es>
 */
final class TemplateRendererTest extends TestCase
{
    public function testReplacesAllKnownPlaceholders(): void
    {
        $template = 'Hola {{client_name}}, el servicio {{service_identifier}} vence el {{expiration_date}}.';
        $result = TemplateRenderer::render($template, [
            'client_name' => 'Empresa Demo, S.L.',
            'service_identifier' => 'example.com',
            'expiration_date' => '15-09-2026',
        ]);

        $this->assertSame(
            'Hola Empresa Demo, S.L., el servicio example.com vence el 15-09-2026.',
            $result->text
        );
        $this->assertSame([], $result->unknownPlaceholders);
    }

    public function testEmptyOptionalValuesRenderAsEmptyString(): void
    {
        $result = TemplateRenderer::render('Proveedor: {{provider_name}}.', ['provider_name' => '']);
        $this->assertSame('Proveedor: .', $result->text);
    }

    public function testNullValuesRenderAsEmptyString(): void
    {
        $result = TemplateRenderer::render('Proveedor: {{provider_name}}.', ['provider_name' => null]);
        $this->assertSame('Proveedor: .', $result->text);
    }

    public function testUnknownPlaceholdersAreKeptAndReported(): void
    {
        $result = TemplateRenderer::render('Hola {{client_name}}, código {{mystery_code}}.', [
            'client_name' => 'Ana',
        ]);

        $this->assertSame('Hola Ana, código {{mystery_code}}.', $result->text);
        $this->assertSame(['mystery_code'], $result->unknownPlaceholders);
    }

    public function testValuesAreNotReExpanded(): void
    {
        // Un valor que contiene un marcador no debe expandirse de nuevo.
        $result = TemplateRenderer::render('X: {{client_name}} Y: {{service_title}}', [
            'client_name' => '{{service_title}}',
            'service_title' => 'Hosting',
        ]);

        $this->assertSame('X: {{service_title}} Y: Hosting', $result->text);
    }

    public function testDoesNotExecuteCode(): void
    {
        $result = TemplateRenderer::render('{{client_name}}', [
            'client_name' => '<?php echo "boom"; ?>',
        ]);

        $this->assertSame('<?php echo "boom"; ?>', $result->text);
    }

    public function testHtmlEscapingWhenRequested(): void
    {
        $result = TemplateRenderer::render('Hola {{client_name}}', [
            'client_name' => 'Empresa <B> & Co.',
        ], true);

        $this->assertSame('Hola Empresa &lt;B&gt; &amp; Co.', $result->text);
    }

    public function testNoEscapingByDefault(): void
    {
        $result = TemplateRenderer::render('Hola {{client_name}}', [
            'client_name' => 'Empresa & Co.',
        ]);

        $this->assertSame('Hola Empresa & Co.', $result->text);
    }

    public function testPlaceholdersWithSpacesInsideBracesAreAccepted(): void
    {
        $result = TemplateRenderer::render('Hola {{ client_name }}', ['client_name' => 'Ana']);
        $this->assertSame('Hola Ana', $result->text);
    }

    public function testTemplateWithoutPlaceholders(): void
    {
        $result = TemplateRenderer::render('Texto plano.', ['client_name' => 'Ana']);
        $this->assertSame('Texto plano.', $result->text);
        $this->assertSame([], $result->unknownPlaceholders);
    }
}
