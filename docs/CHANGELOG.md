# Changelog

Formato: [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/).
FacturaScripts requiere versiones enteras o con un solo decimal, por lo que
la primera versión publicada es la `1.0` (etiqueta `1.0`).

## [Unreleased]

### Added

- Acceso al presupuesto desde la ficha de la suscripción: botón «Ver
  presupuesto» en la pestaña principal, que abre el presupuesto del ciclo
  abierto o, si ya se renovó, el del último ciclo que generó uno. Nuevo
  método `ServiceRenewal::getLastQuote()`.
- Columnas de presupuesto y factura enlazadas al documento del núcleo en la
  pestaña **Ciclos** de la suscripción y en el listado de renovaciones.

### Changed

- La línea del presupuesto de renovación ya no incluye el proveedor: se
  queda solo con el identificador del servicio y el periodo cubierto, que es
  lo que describe qué se renueva. El proveedor sigue disponible en la ficha
  de la suscripción, en el listado, en los filtros y en los avisos por email.
  Se elimina la clave de traducción `service-renewal-quote-provider`, que
  queda sin uso.
- Las columnas de presupuesto y factura de los listados dejan el valor a
  `null` cuando no hay documento (el núcleo ya muestra el guion), de forma
  que solo se enlazan las filas con documento real.

### Fixed

- Error al abrir la ficha de una suscripción (`EditServiceRenewal`): se
  llamaba al método inexistente `allWhereEq()` al cargar las notificaciones.
  Sustituido por la API correcta del núcleo `Model::all([Where::eq(...)])`.
  El mismo fallo afectaba a `RenewalScanner::findDue()` (procesamiento del
  cron) y a `RenewalFlowTest`. Reportado por analarama.
- Tests de flujo presupuesto → factura fallaban en CI con el aviso
  «No hay suficiente stock»: los productos de servicio de prueba se crean
  ahora con `nostock = true`. Trait `ServiceRenewalsFixtures` (inspirado
  en AiScan) centraliza clientes con `codpago`/`codserie` y productos de
  servicio sin stock, y expone el log del núcleo al fallar la transformación.

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
