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

namespace FacturaScripts\Plugins\ServiceRenewals\Extension\Model;

use Closure;
use FacturaScripts\Plugins\ServiceRenewals\Lib\InvoiceRenewalTrigger;

/**
 * Extensión del registro de transformaciones del núcleo.
 *
 * Es el momento exacto en el que la factura queda unida a su presupuesto:
 * ambos documentos existen ya y el vínculo está escrito. Aprovechamos ese
 * instante para renovar sin esperar al cron.
 *
 * La extensión nunca devuelve false, que abortaría el guardado del núcleo:
 * la renovación es un añadido, no una condición para transformar documentos.
 *
 * @author Ernesto Serrano <info@ernesto.es>
 */
class DocTransformation
{
    public function saveInsert(): Closure
    {
        return function () {
            InvoiceRenewalTrigger::onDocumentLinked((string)$this->model2, (int)$this->iddoc2);
        };
    }
}
