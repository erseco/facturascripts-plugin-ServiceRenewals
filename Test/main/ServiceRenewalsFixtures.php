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

use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\Cliente;
use FacturaScripts\Dinamic\Model\FormaPago;
use FacturaScripts\Dinamic\Model\Producto;
use FacturaScripts\Dinamic\Model\Serie;

/**
 * Fixtures reutilizables para tests de integración con documentos de venta.
 *
 * Inspirado en AiScan (InvoiceMapper*Test): en instalaciones mínimas de CI
 * hace falta rellenar codpago/codserie y marcar los productos de servicio
 * como `nostock`, o la transformación presupuesto → factura falla.
 *
 * La clase que use este trait debe declarar:
 *   private array|/object[] $cleanup = [];
 * y borrar esos modelos en tearDown() en orden inverso.
 *
 * @author Ernesto Serrano <info@ernesto.es>
 */
trait ServiceRenewalsFixtures
{
    /**
     * Producto de servicio: sin control de stock.
     *
     * Con ventasinstock=false y stock 0, BusinessDocumentGenerator no puede
     * facturar líneas de productos con stock controlado.
     */
    protected function makeServiceProduct(string $label = 'Service product', float $price = 40.0): Producto
    {
        $product = new Producto();
        $product->referencia = 'SRV-' . substr(uniqid('', true), -8);
        $product->descripcion = $label;
        $product->precio = $price;
        $product->nostock = true;
        $this->assertTrue($product->save(), 'Could not create service product');
        $this->cleanup[] = $product;

        return $product;
    }

    /**
     * Cliente con forma de pago y serie rellenadas si el núcleo no las trajo.
     *
     * Algunos entornos bare-bones de CI crean clientes sin codpago/codserie
     * y el guardado de documentos de venta falla por NOT NULL.
     */
    protected function makeCustomer(string $label = 'Customer', ?string $email = null): Cliente
    {
        $customer = new Cliente();
        $customer->nombre = $label . ' ' . substr(uniqid('', true), -4);
        $customer->cifnif = substr(uniqid('', true), -9);
        if (null !== $email) {
            $customer->email = $email;
        }

        $this->ensureCustomerBillingDefaults($customer);
        $this->assertTrue($customer->save(), 'Could not create customer');
        $this->cleanup[] = $customer;

        return $customer;
    }

    /** Rellena codpago y codserie en el cliente si faltan. */
    protected function ensureCustomerBillingDefaults(Cliente $customer): void
    {
        if (empty($customer->codpago)) {
            $customer->codpago = $this->defaultPaymentCode();
        }

        if (empty($customer->codserie)) {
            $customer->codserie = $this->defaultSerieCode();
        }
    }

    protected function defaultPaymentCode(): string
    {
        $fromSettings = (string)Tools::settings('default', 'codpago', '');
        if ('' !== $fromSettings) {
            return $fromSettings;
        }

        foreach (FormaPago::all([], [], 0, 1) as $payment) {
            return (string)$payment->codpago;
        }

        $payment = new FormaPago();
        $payment->codpago = 'CONT';
        $payment->descripcion = 'Contado';
        $payment->activa = true;
        $payment->save();

        return (string)$payment->codpago;
    }

    protected function defaultSerieCode(): string
    {
        $fromSettings = (string)Tools::settings('default', 'codserie', '');
        if ('' !== $fromSettings) {
            return $fromSettings;
        }

        foreach (Serie::all([], [], 0, 1) as $serie) {
            return (string)$serie->codserie;
        }

        $serie = new Serie();
        $serie->codserie = 'A';
        $serie->descripcion = 'General';
        $serie->save();

        return (string)$serie->codserie;
    }

    /**
     * Mensaje de diagnóstico a partir del log del núcleo (útil cuando falla
     * BusinessDocumentGenerator::generate por stock, serie, etc.).
     */
    protected function recentCoreLogMessage(): string
    {
        $parts = [];
        foreach (Tools::log()->read('', ['critical', 'error', 'warning']) as $entry) {
            $parts[] = '[' . ($entry['level'] ?? '?') . '] ' . ($entry['message'] ?? '');
        }

        return empty($parts) ? '(no core log entries)' : implode('; ', $parts);
    }
}
