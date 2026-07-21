# ADR-0003: Renovación al facturar o confirmación manual

## Status

Accepted

## Date

2026-07-21

## Context

Hay que decidir cuándo avanza la fecha de vencimiento de una suscripción.
Avanzarla al generar el presupuesto (como hace fs-contratos) provoca fechas
adelantadas sin que el cliente haya aceptado nada. Avanzarla solo al cobro
requiere integrar recibos y remesas, fuera del alcance de la primera versión.

## Decision

Dos políticas configurables por perfil y por suscripción (`renewal_trigger`):

1. **`invoice`** (predeterminada): cuando el procesador detecta mediante
   `DocTransformation` que el presupuesto del ciclo se transformó en factura,
   avanza la fecha (`expiration_date = next_expiration_date` del ciclo) y
   marca el ciclo como `renewed`. La operación es transaccional e idempotente.
2. **`manual`**: al detectar la factura, el ciclo queda en `renewal_pending`
   y la fecha NO avanza. Solo la acción «Confirmar renovación» de la ficha
   aplica el avance, una única vez.

La suma del periodo se hace por **meses naturales** con recorte a fin de mes
(`RenewalDateCalculator`):

- `31/01 + 1 mes = 28/02` (29/02 en bisiesto)
- `29/02 + 12 meses = 28/02` del año siguiente
- `31/08 + 6 meses = 28/02` (29/02 en bisiesto)

El recorte no es acumulativo: cada suma parte de la fecha que recibe.

## Alternativas rechazadas

- **Renovar al crear el presupuesto**: reproduce el error de fs-contratos;
  la fecha avanzaría sin aceptación del cliente.
- **Renovar al pago (recibo pagado)**: pospuesto; requiere integrar la
  gestión de recibos. Queda documentado como mejora futura.
- **Sumar periodos con `strtotime('+365 days')`**: incorrecto con años
  bisiestos y meses de distinta longitud.
- **Día de anclaje persistente** (recordar el día 31 tras recortar a 28/02):
  más complejo y sorprendente para el usuario; se documenta que el recorte
  no es acumulativo.

## Consequences

### Positive

- La fecha solo avanza con una señal de negocio real (factura o confirmación).
- Ejecuciones repetidas nunca suman el periodo dos veces.
- El cálculo de fechas está cubierto por tests unitarios exhaustivos
  (bisiestos, 29/02, fin de mes, periodos de 1 a 24 meses).

### Negative

- Con política `manual` el usuario debe intervenir para cerrar el ciclo.

### Neutral

- Una suscripción vencida que se factura tarde se renueva a partir de su
  fecha de vencimiento original, no de la fecha de la factura.
