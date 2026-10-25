# ADR-0004: Documento de renovación: presupuesto o albarán

## Status

Accepted

## Date

2026-10-22

## Context

Cada ciclo generaba siempre un presupuesto que el cliente debía aceptar. En
alquileres de maquinaria o mantenimientos de software lo habitual es emitir
directamente un albarán (#12).

## Decision

- Nuevo ajuste `document_type` (`quote` o `delivery_note`) con la misma
  herencia que el resto: suscripción → perfil → configuración general
  (`default_document_type`, predeterminado `quote`).
- El tipo se fija al generar el documento y se guarda en el ciclo
  (`document_model`: `PresupuestoCliente` o `AlbaranCliente`). Cambiar la
  configuración después no afecta a los ciclos que ya tienen documento.
- El identificador sigue en `quote_id`, que pasa a significar «documento de
  renovación». Los ciclos antiguos tienen `document_model` vacío y se tratan
  como presupuestos, así que no hace falta migrar datos.
- La idempotencia no cambia: un ciclo tiene un único `quote_id`, comprobado
  bajo bloqueo de fila, así que nunca genera más de un documento sea cual
  sea su tipo.
- La detección de la factura recorre `DocTransformation` desde el modelo
  guardado en el ciclo; el aviso por email adjunta el PDF del documento que
  corresponda.

## Alternativas rechazadas

- **Columna `delivery_note_id` aparte**: obliga a comprobar dos columnas en
  cada sitio y complica la garantía de un solo documento por ciclo.
- **Renombrar `quote_id`**: migración de datos y cambios en todo el plugin
  sin beneficio funcional.
- **Facturar directamente**: se pierde el paso intermedio que el usuario
  revisa antes de emitir la factura; queda como posible mejora.

## Consequences

### Positive

- Sin migración: los ciclos existentes siguen funcionando igual.
- La política de renovación (`invoice` o `manual`) funciona igual con
  albaranes.

### Negative

- El nombre `quote_id` (y los estados `quote_created`/`quote_sent`) ya no
  describe con exactitud su contenido; se documenta en el modelo.
