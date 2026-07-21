# Manual de ServiceRenewals

## 1. Introducción

ServiceRenewals es un plugin para FacturaScripts que gestiona las
renovaciones de los servicios que vendes a tus clientes: dominios,
alojamientos web, servidores VPS o dedicados, certificados SSL,
mantenimientos, licencias, copias de seguridad y cualquier otro servicio
recurrente. El plugin avisa antes del vencimiento, genera el presupuesto de
renovación, lo envía por email y, cuando el presupuesto se factura, avanza
la fecha de vencimiento automáticamente.

## 2. Qué es una renovación de servicio

Cuando vendes un servicio como «el dominio ejemplo.com», ese servicio tiene
una fecha de vencimiento propia, distinta de la de cualquier otro dominio de
cualquier otro cliente. Renovarlo significa dos cosas distintas:

- **La renovación comercial**: presupuestar y facturar el nuevo periodo al
  cliente. Esto es lo que hace este plugin.
- **La renovación técnica**: renovar el dominio en el registrador o el
  servidor en el proveedor. Esto sigue haciéndose fuera de FacturaScripts.

En el plugin manejarás cuatro conceptos:

| Concepto | Qué es | Ejemplo |
|---|---|---|
| Producto | La definición reutilizable del servicio y sus valores predeterminados | «Renovación de dominio .com», 12 meses |
| Suscripción | Un servicio concreto contratado por un cliente, con su fecha real | ejemplo.com de Empresa Demo, vence el 15/09/2026 |
| Ciclo | El expediente de una renovación concreta | presupuesto, factura y avance de fecha del vencimiento de 2026 |
| Presupuesto / Factura | Los documentos de venta normales de FacturaScripts | PRE-2026-15, FAC-2026-33 |

## 3. Requisitos

- FacturaScripts 2025 o superior.
- PHP 8.1 o superior.
- El cron de FacturaScripts configurado (imprescindible para los
  automatismos).
- Un servidor SMTP configurado en Administración → Email para poder enviar
  los avisos.

## 4. Instalación

1. Descarga el ZIP del plugin desde la forja de FacturaScripts.
2. Ve a **Administración → Plugins**, pulsa «Añadir» y sube el ZIP.
3. Activa el plugin **ServiceRenewals**.
4. Las tablas se crean automáticamente al activar.

## 5. Configuración del cron

Los automatismos se ejecutan con el cron estándar de FacturaScripts. En tu
servidor, añade al crontab:

```cron
0 * * * * cd /ruta/a/facturascripts && php index.php -cron
```

Debe ejecutarse **al menos una vez al día**; lo recomendable es cada hora.
Sin cron no habrá presupuestos, avisos ni renovaciones automáticas, aunque
todas las acciones seguirán disponibles manualmente.

## 6. Configuración general

En **Administración → Renovaciones de servicios** encontrarás:

- **Activado**: interruptor general del procesamiento automático.
- **Días de antelación del presupuesto**: con cuántos días de antelación se
  genera el presupuesto de renovación (30 por defecto).
- **Días de recordatorio**: lista separada por comas, por ejemplo `15,7,3`.
- **Enviar presupuestos automáticamente**: si se envían por email al
  generarse.
- **Política de renovación predeterminada**: al facturar o manual.
- **Reintentos máximos**: cuántas veces se reintenta un email fallido.
- **Email remitente, CC y BCC globales**: opcionales.
- **Plantillas**: asunto y cuerpo de los emails de presupuesto y de
  recordatorio, con marcadores como `{{client_name}}` o
  `{{expiration_date}}` (la lista completa está en la propia pantalla y en
  el README).

## 7. Preparación de productos

Crea un producto por cada tipo de servicio que renuevas («Renovación de
dominio .com», «Hosting gestionado anual», «Servidor VPS mensual»...) con su
precio. En la ficha del producto verás la pestaña **Perfil de renovación**:
actívala y define la periodicidad en meses, la antelación, los días de
recordatorio y la política de renovación. Estos valores son los
predeterminados para todas las suscripciones de ese producto.

**Importante**: el producto nunca guarda la fecha de vencimiento. La fecha
pertenece a cada suscripción, porque cada cliente vence en una fecha
distinta.

## 8. Creación de suscripciones

En **Ventas → Renovaciones de servicios**, pulsa «Nuevo»:

- **Cliente** y **producto**: obligatorios.
- **Identificador del servicio**: obligatorio; el dato concreto que se
  renueva (`ejemplo.com`, `vps-production-01`, `ssl-ejemplo.com`).
- **Fecha de vencimiento**: obligatoria; la fecha real en la que caduca.
- **Periodicidad**: meses que cubre cada renovación.
- **Proveedor** y **referencia externa**: opcionales, para saber dónde está
  contratado el servicio.
- **Valores propios**: si los dejas vacíos se usan los del perfil del
  producto; si los rellenas, prevalecen (antelación, recordatorios, email de
  aviso, precio particular, generación y envío automáticos, política).

No guardes nunca contraseñas ni claves API del proveedor en las notas.

## 9. Tipos de servicio

Dominio, alojamiento web, servidor VPS, servidor dedicado, certificado,
mantenimiento, licencia, copia de seguridad y otro. El tipo sirve para
filtrar y para los textos de los emails; no cambia el comportamiento.

## 10. Fechas y periodicidades

Las renovaciones suman **meses naturales**: 12 meses desde el 15/09/2026 es
el 15/09/2027. Si el mes de destino no tiene el día de origen, se usa el
último día del mes: 31/01 + 1 mes = 28/02 (29/02 en año bisiesto), y
29/02 + 12 meses = 28/02 del año siguiente.

## 11. Generación automática del presupuesto

Cuando una suscripción activa entra en el umbral de antelación (por ejemplo,
faltan 30 días o menos, incluidas las ya vencidas), el cron:

1. Abre un **ciclo** de renovación (si no existe ya).
2. Genera un **presupuesto** al cliente con la línea del producto, el
   identificador del servicio y el periodo cubierto.
3. Si el precio particular está definido, lo usa; si no, usa el precio del
   producto.

El presupuesto es un documento normal de FacturaScripts: puedes editarlo,
añadir líneas o descuentos antes de enviarlo o facturarlo. Cada ciclo genera
como máximo un presupuesto, aunque el cron se ejecute muchas veces.

## 12. Envío de avisos

Si el envío automático está activo, el presupuesto se envía en PDF al email
de facturación del cliente (o al email de aviso propio de la suscripción).
Además se envían recordatorios los días configurados (15, 7 y 3 días antes,
por ejemplo) mientras el servicio siga activo y el presupuesto sin facturar.
Ningún aviso se envía dos veces. En la pestaña **Notificaciones** de la
suscripción puedes ver cada envío con su estado, sus reintentos y su error
si lo hubo, y reenviarlo con el botón «Enviar aviso».

## 13. Conversión en factura

Cuando el cliente acepta, transforma el presupuesto en factura desde
FacturaScripts como siempre (aprobándolo directamente o pasando por pedido
y albarán). El plugin detecta la transformación automáticamente en la
siguiente pasada del cron, aunque haya documentos intermedios.

## 14. Renovación automática al facturar

Con la política **al facturar** (la predeterminada), al detectar la factura
el plugin:

1. Guarda la factura en el ciclo.
2. Avanza la fecha de vencimiento de la suscripción el periodo configurado.
3. Marca el ciclo como **Renovado**.

La fecha avanza **una sola vez** por ciclo, aunque el cron se repita.

## 15. Renovación manual

Con la política **confirmación manual**, al detectar la factura el ciclo
queda «Pendiente de confirmar» y la fecha no cambia. Cuando hayas hecho la
renovación técnica en el proveedor, pulsa **Confirmar renovación** en la
ficha de la suscripción: la fecha avanzará entonces, una sola vez.

## 16. Panel e informes

En **Ventas → Panel de renovaciones** tienes las tarjetas de resumen
(activas, vencidas, vencen en 7 y 30 días, presupuestos pendientes,
renovaciones por confirmar y emails fallidos) y la lista de próximos
vencimientos.

## 17. Estados

- **Suscripción**: Activa, Suspendida (no genera documentos ni emails),
  Cancelada (no genera ninguna acción) y Vencida.
- **Ciclo**: Pendiente → Presupuesto creado → Presupuesto enviado →
  Facturado → Renovado; además Pendiente de confirmar (política manual),
  Fallido y Cancelado.
- **Notificación**: Pendiente, Enviado, Fallido (se reintenta hasta el
  máximo configurado) y Cancelado.

## 18. Filtros

El listado de renovaciones se filtra por cliente, producto, tipo de
servicio, proveedor, estado, rango de fechas de vencimiento, accesos rápidos
(vencidos, próximos 7/30/60 días) y estado del ciclo actual (con o sin
presupuesto, facturados, pendientes de renovar).

## 19. Historial

Cada suscripción conserva su historial completo en las pestañas **Ciclos**
(cada renovación con su presupuesto, su factura y su fecha de avance) y
**Notificaciones** (cada email con su estado y sus errores). Nada se
sobrescribe al renovar.

## 20. Solución de problemas

- **No se generan presupuestos**: comprueba que el cron se ejecuta, que el
  procesamiento está activado en la configuración, que la suscripción está
  activa y que está dentro del umbral de antelación.
- **No llegan los emails**: comprueba la configuración SMTP en
  Administración → Email y revisa la pestaña Notificaciones: el error exacto
  queda registrado y el envío se reintenta automáticamente.
- **La fecha no avanza**: con política manual debes pulsar «Confirmar
  renovación»; con política al facturar, comprueba que el presupuesto se
  transformó realmente en factura y espera a la siguiente pasada del cron.
- **Aparece un marcador `{{...}}` en un email**: la plantilla contiene un
  marcador desconocido; corrígelo en la configuración.

## 21. Preguntas frecuentes

**¿El plugin renueva el dominio en mi registrador?** No. Gestiona la parte
comercial (aviso, presupuesto, factura y fechas); la renovación técnica se
hace en el proveedor.

**¿Puede un cliente tener dos servicios del mismo producto?** Sí, crea una
suscripción por cada servicio, cada una con su identificador y su fecha.

**¿Qué pasa si el cron se ejecuta varias veces seguidas?** Nada: no se
duplican ciclos, presupuestos ni emails.

**¿Puedo editar el presupuesto generado?** Sí, es un presupuesto normal de
FacturaScripts.

**¿Qué pasa si el cliente no paga?** El ciclo queda abierto con su
presupuesto; la fecha no avanza. Puedes suspender o cancelar la suscripción
cuando lo decidas.

## 22. Limitaciones

- No renueva servicios en registradores ni proveedores (sin integraciones
  API en esta versión).
- No realiza cobros automáticos ni integra pasarelas de pago.
- No agrupa varias suscripciones en un mismo presupuesto.
- La renovación se dispara al facturar o manualmente, no al cobro.
- Los recordatorios se envían el día exacto configurado; el cron debe
  ejecutarse a diario.
