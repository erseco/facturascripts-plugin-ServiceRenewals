# Changelog

Formato: [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/).
FacturaScripts requiere versiones enteras o con un solo decimal, por lo que
la primera versión publicada es la `1.0` (etiqueta `1.0`).

## [1.0] - 2026-07-21

### Added

- Perfiles de renovación por producto (`ServiceRenewalProfile`): periodicidad,
  antelación del presupuesto, días de recordatorio, generación y envío
  automáticos y política de renovación.
- Suscripciones por cliente (`ServiceRenewal`) con identificador del servicio,
  proveedor, fecha real de vencimiento y valores propios que sobrescriben el
  perfil.
- Ciclos de renovación (`ServiceRenewalCycle`) con historial completo:
  presupuesto, factura detectada, renovación aplicada y errores. Restricción
  única contra duplicados.
- Generación automática de presupuestos (`PresupuestoCliente`) con la línea
  del producto, el identificador del servicio y el periodo cubierto.
- Envío de presupuestos por email con PDF adjunto mediante la cola de
  trabajos y `NewMail`, con reintentos y registro de errores.
- Recordatorios configurables antes del vencimiento, sin duplicados.
- Detección de la transformación presupuesto → factura mediante
  `DocTransformation`, incluidas cadenas presupuesto → pedido → factura.
- Renovación automática al facturar o confirmación manual, con avance de
  fecha por meses naturales y recorte a fin de mes.
- Panel de resumen con tarjetas y próximas renovaciones.
- Listado con filtros por cliente, producto, tipo, proveedor, estado,
  vencimiento y estado del ciclo.
- Pestañas de renovaciones en las fichas de cliente y de producto.
- Pantalla de configuración global en Administración (plantillas de email,
  CC/BCC, reintentos, política predeterminada).
- Tests unitarios y de integración (fechas, ciclos, presupuestos,
  notificaciones, renovación, permisos).
- Entorno de desarrollo Docker con MariaDB, Mailpit y datos de demostración.
- Publicación automática en la forja de FacturaScripts desde GitHub Actions.
