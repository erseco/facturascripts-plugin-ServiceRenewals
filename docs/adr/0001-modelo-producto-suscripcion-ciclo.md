# ADR-0001: Modelo producto / suscripción / ciclo

## Status

Accepted

## Date

2026-07-21

## Context

El plugin debe gestionar renovaciones periódicas de servicios (dominios,
alojamientos, VPS, certificados...). El plugin de referencia `fs-contratos`
guarda toda la información en un único registro de contrato, lo que provoca
varios problemas conocidos:

- La fecha de renovación avanza antes de confirmarse el hito configurado.
- No existe historial de ciclos: solo se conserva el último estado.
- El cron puede generar documentos duplicados si se ejecuta dos veces.
- Guardar la fecha de vencimiento en el producto impide que dos clientes
  tengan fechas diferentes para el mismo servicio.

## Decision

Separar el dominio en tres modelos:

1. **ServiceRenewalProfile** (perfil): valores predeterminados de renovación
   de un producto (periodicidad, antelación, recordatorios, política). Un
   producto representa una definición reutilizable ("Renovación de dominio
   .com"), nunca una fecha concreta.
2. **ServiceRenewal** (suscripción): el servicio contratado por un cliente,
   con su identificador (`ejemplo.com`), su proveedor y su **fecha real de
   vencimiento**. Puede sobrescribir cualquier valor del perfil.
3. **ServiceRenewalCycle** (ciclo): el historial de una renovación concreta,
   con el presupuesto generado, la factura detectada y el avance de fecha.
   La restricción única `(service_renewal_id, previous_expiration_date)`
   identifica cada ciclo.

Las notificaciones (emails) se registran en un cuarto modelo,
**ServiceRenewalNotification**, con deduplicación por
`(cycle_id, notification_type, reminder_day)`.

## Alternativas rechazadas

- **Un único modelo de contrato** (como fs-contratos): sin historial ni
  idempotencia; mezclaría generación documental y renovación.
- **Guardar la fecha en el producto**: un producto es compartido por muchos
  clientes; la fecha pertenece a cada suscripción.
- **Guardar el historial como JSON en la suscripción**: dificulta filtros,
  informes y restricciones de unicidad en base de datos.

## Consequences

### Positive

- Historial completo y auditable de cada renovación.
- El cron puede repetirse sin duplicar ciclos ni presupuestos.
- Perfiles reutilizables: crear una suscripción solo requiere cliente,
  identificador y fecha.

### Negative

- Más tablas y más código que la alternativa de un único modelo.

### Neutral

- Las columnas calculadas del listado (días restantes, estado del ciclo)
  se calculan al cargar la vista en lugar de desnormalizarse.
