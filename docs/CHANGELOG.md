# Changelog

Formato: [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/).
FacturaScripts requiere versiones enteras o con un solo decimal, por lo que
la primera versión publicada es la `1.0` (etiqueta `1.0`).

## [Unreleased]

### Added

- Acceso a la factura de renovación desde la ficha de la suscripción: botón
  «Ver factura» junto a «Ver presupuesto», visible solo cuando la factura
  existe. Nuevo método `ServiceRenewal::getLastInvoice()`, espejo de
  `getLastQuote()`, y nueva clave de traducción `view-invoice` (el núcleo
  solo trae `view-estimation`).
- Textos de ayuda bajo los campos de la ficha de suscripción, del perfil de
  producto y de la configuración general, mediante el atributo
  `description` de las vistas. El de **fecha de vencimiento** avisa de que
  es la próxima, no la del último cobro, y de que una fecha pasada genera
  el presupuesto de inmediato.
- La renovación se aplica **en cuanto se emite la factura**, sin esperar a
  la siguiente pasada del cron. Nueva extensión del modelo `DocTransformation`
  del núcleo: al escribirse el vínculo presupuesto → factura se ejecuta la
  misma comprobación que hace el cron, sobre ese único ciclo
  (`RenewalProcessor::detectAndRenew()`). Respeta la política de cada
  suscripción, es idempotente y nunca interrumpe el guardado de la factura;
  el cron sigue siendo la red de seguridad.
- Estado de cobro de la factura en el listado y en el panel: **Emitida**,
  **Pagada** o **Vencida**, con color de fila y etiqueta propia. Sale de los
  campos `pagada` y `vencida` del núcleo.
- Nueva tarjeta del panel **Facturadas pendientes de renovar**: ciclos en
  estado `invoiced` o `renewal_pending`, es decir, aquellos con factura ya
  detectada cuya fecha de vencimiento todavía no ha avanzado.
- La tabla **Próximas renovaciones** del panel muestra el presupuesto y la
  factura del último ciclo, enlazados al documento del núcleo.
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

- El listado de suscripciones se quedaba sin presupuesto, sin factura y sin
  estado de ciclo justo después de renovar. Con la política `invoice`, el
  procesador detecta la factura y aplica la renovación en la misma pasada,
  dejando el ciclo en `renewed`; `RenewalListDecorator::decorateFull()` solo
  miraba `getOpenCycle()`, que excluye ese estado, y se quedaba sin ciclo del
  que leer los documentos. Ahora usa el ciclo abierto y, si no hay, el último.
- Los productos de la demo (`blueprint.json`) se creaban con control de
  stock, así que el presupuesto no llegaba a convertirse en factura: el
  núcleo revertía el documento con «No hay suficiente stock». Como son
  servicios, ahora se siembran con `nostock: true` y el flujo completo
  funciona sin tocar nada.
- El filtro **Facturados** del listado no encontraba las suscripciones ya
  renovadas: la subconsulta emparejaba el ciclo por
  `previous_expiration_date = expiration_date` y, al renovar, el vencimiento
  avanza y deja de coincidir. Ahora acepta también el ciclo cuyo
  `next_expiration_date` es el vencimiento actual, que es exactamente el que
  acaba de renovar.
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
