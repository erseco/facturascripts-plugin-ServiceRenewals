# QUICKSTART — ServiceRenewals

Guía rápida para probar el plugin en local en menos de 10 minutos.

## 1. Levanta el entorno

```bash
make upd
```

Esto arranca FacturaScripts, MariaDB y Mailpit con Docker, activa el plugin,
configura el SMTP hacia Mailpit y carga datos de demostración (clientes,
productos, perfiles y suscripciones).

- FacturaScripts: http://localhost:8080 — usuario `admin`, contraseña `admin`
- Mailpit (bandeja de correo de pruebas): http://localhost:8025

## 2. Explora los datos de demostración

- **Ventas → Renovaciones de servicios**: listado con suscripciones que
  vencen en 7 días, en 30 días, una vencida, una con política manual y una
  suspendida.
- **Ventas → Panel de renovaciones**: tarjetas de resumen y próximos
  vencimientos.

## 3. Ejecuta el cron

```bash
make cron
```

El cron detecta los vencimientos dentro del umbral, crea los ciclos, genera
los presupuestos y envía los emails. Compruébalo:

1. Abre una suscripción → pestaña **Ciclos**: verás el ciclo con su
   presupuesto.
2. Pestaña **Notificaciones**: el email del presupuesto como «Enviado».
3. Abre http://localhost:8025: el email con el PDF adjunto está en Mailpit.
4. Vuelve a ejecutar `make cron`: no se duplica nada.

## 4. Crea tu propia suscripción

1. **Producto**: crea un producto (por ejemplo «Renovación dominio .org»,
   precio 12 €) y en su pestaña **Perfil de renovación** configura
   periodicidad 12 meses y antelación 30 días.
2. **Cliente**: crea un cliente con email.
3. **Suscripción**: en Ventas → Renovaciones de servicios → Nuevo, elige
   cliente y producto, identificador `midominio.org` y una fecha de
   vencimiento dentro de los próximos 30 días.
4. Ejecuta `make cron`: se crea el ciclo, el presupuesto y el email.

## 5. Factura y renueva

1. Abre el presupuesto generado (pestaña Ciclos → columna Presupuesto) y
   apruébalo/transfórmalo en factura desde la interfaz normal de
   FacturaScripts.
2. Ejecuta `make cron` otra vez.
3. La suscripción se renueva: su fecha de vencimiento avanza 12 meses y el
   ciclo queda «Renovado».
4. Con la política «Confirmación manual», el ciclo queda «Pendiente de
   confirmar» y debes pulsar el botón **Confirmar renovación** en la ficha.

## 6. Otros comandos útiles

```bash
make logs      # logs de los contenedores
make shell     # shell dentro del contenedor de FacturaScripts
make test      # tests PHPUnit dentro del contenedor
make lint      # comprobación de estilo (PSR-12)
make fresh     # reinicia el entorno desde cero (borra la base de datos)
```
