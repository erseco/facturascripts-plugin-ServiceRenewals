# ADR-0002: Procesamiento idempotente con el cron y WorkQueue del núcleo

## Status

Accepted

## Date

2026-07-21

## Context

Las renovaciones requieren una comprobación periódica: detectar vencimientos,
generar presupuestos, enviar emails y aplicar renovaciones. FacturaScripts
ofrece dos mecanismos nativos:

- La clase `Cron` de cada plugin (`Core\Template\CronClass`), que el núcleo
  ejecuta en cada pasada de cron con programación tipo `every('1 hour')`.
- La cola de trabajos `WorkQueue` con workers registrados en `Init::init()`.

No queremos un daemon, ni un cron PHP independiente, ni un endpoint público.

## Decision

- `Cron.php` del plugin define un job `process-renewals` con `every('1 hour')`
  que **solo encola** un evento `ServiceRenewals.Process` con la fecha del
  día como valor. Antes de encolar comprueba que no exista ya un evento de
  proceso pendiente (máximo una ejecución pendiente).
- `ProcessServiceRenewalsWorker` consume el evento y ejecuta
  `RenewalProcessor::process($date)`. La fecha viaja en el evento para que
  los tests puedan procesar con una fecha fija.
- Toda la lógica del procesador es **idempotente**:
  - Los ciclos se buscan antes de crearse y la restricción única de la tabla
    resuelve las carreras.
  - Un ciclo con presupuesto no genera otro presupuesto.
  - Las notificaciones se deduplican por `(ciclo, tipo, regla de días)`.
  - Una renovación aplicada no vuelve a avanzar la fecha.
- El envío de emails se encola como eventos `ServiceRenewals.SendNotification`
  procesados por `SendServiceRenewalMailWorker`, con la notificación
  persistida **antes** de encolar y el PDF conservado en disco hasta el
  envío correcto.

## Alternativas rechazadas

- **Ejecutar la lógica directamente en el cron job**: funcionaría, pero la
  cola de trabajos aporta trazabilidad (tabla `work_events`) y permite
  reutilizar el mismo worker desde acciones manuales.
- **Evento futuro autorreprogramado** (`sendFuture`): innecesario, porque el
  núcleo sí ofrece recurrencia directa mediante `CronClass`/`CronJob`.
- **Detección de facturas por eventos de modelo** (`Model.PresupuestoCliente.Update`):
  la transformación puede producirse por cadenas de documentos
  (presupuesto → pedido → factura) que no tocan el presupuesto; la consulta
  periódica de `DocTransformation` es más robusta y sigue las cadenas.

## Consequences

### Positive

- Sin infraestructura adicional: basta el cron estándar de FacturaScripts.
- Ejecuciones repetidas del cron no crean duplicados.
- Los fallos de una suscripción no bloquean el resto del lote.

### Negative

- La renovación se detecta con la latencia de la pasada de cron (hasta una
  hora en la configuración por defecto), no en tiempo real.

### Neutral

- Los recordatorios se disparan cuando los días restantes coinciden
  exactamente con la regla, por lo que el cron debe ejecutarse a diario.
