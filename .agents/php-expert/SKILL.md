---
name: php-expert
description: Convenciones PHP del plugin ServiceRenewals (PSR-12, PHP 8.1+, LGPL).
---

# PHP en ServiceRenewals

## Compatibilidad

- PHP mínimo: **8.1**. No usar sintaxis de 8.2+ (readonly classes,
  constantes tipadas, etc.).
- FacturaScripts 2025+: usar `FacturaScripts\Core\Template\ModelClass` +
  `ModelTrait`, `Where::` para condiciones y `FacturaScripts\Dinamic\Model\*`
  para los modelos del núcleo.

## Estilo

- PSR-12, línea máxima 120 caracteres (phpcs.xml lo comprueba).
- Arrays cortos `[]`, comillas simples, imports ordenados alfabéticamente.
- Propiedades públicas de modelos sin tipar, con docblock `@var` en español
  (convención del núcleo).
- Comparaciones Yoda en condiciones de igualdad (`null === $x`), como el
  núcleo.
- Métodos pequeños con una única responsabilidad; nada de clases `Helper`
  genéricas.

## Cabeceras

Todos los archivos PHP llevan la cabecera LGPL-3.0-or-later con
`Copyright (C) 2026 Ernesto Serrano <info@ernesto.es>`. Los docblocks de
clase añaden `@author Ernesto Serrano <info@ernesto.es>`.

## Prohibiciones

- `strtotime('+N days')` para sumar periodos de renovación: usar
  `RenewalDateCalculator::addMonths()`.
- SQL concatenado con datos del usuario.
- `unserialize()` de datos de petición; JSON siempre validado.
- `date()`/`time()` disperso en la lógica de dominio: la fecha de proceso se
  pasa explícitamente (testabilidad).
