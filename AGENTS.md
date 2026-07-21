# AGENTS.md — Instrucciones canónicas para agentes

Este archivo es la fuente canónica de instrucciones para agentes de IA que
trabajen en este repositorio. `CLAUDE.md`, `GEMINI.md` y
`.github/copilot-instructions.md` solo remiten aquí.

## Descripción del proyecto

**ServiceRenewals** es un plugin para FacturaScripts (2025+, PHP 8.1+) que
gestiona renovaciones de servicios recurrentes: perfiles por producto,
suscripciones por cliente con fecha real de vencimiento, ciclos con
historial, presupuestos automáticos, emails con PDF y renovación al
facturar o con confirmación manual.

- Código (clases, métodos, variables, tablas, columnas, claves): **inglés**.
- Documentación, comentarios y docblocks: **español**.
- Interfaz: siempre mediante claves de traducción (`Translation/es_ES.json`
  es la principal).
- Estándar: PSR-12, línea máxima 120, sin SQL concatenado, sin lógica de
  negocio en controladores, sin JavaScript innecesario.

## Skills

| Skill | Cuándo usarlo |
|---|---|
| `.agents/php-expert/SKILL.md` | Cualquier cambio en código PHP |
| `.agents/facturascripts-plugin/SKILL.md` | Modelos, controladores, XMLViews, workers, cron, extensiones |
| `.agents/testing-devops/SKILL.md` | Tests, Docker, Makefile, CI |
| `.agents/documentation/SKILL.md` | README, manual, forja, ADR, changelog |
| `.agents/usability-accessibility/SKILL.md` | Vistas, panel, textos de interfaz |

## Archivos clave antes de tocar código

| Archivo | Qué define |
|---|---|
| `facturascripts.ini` | Metadatos del plugin (versión entera o con un decimal) |
| `Init.php` | Workers y extensiones registrados |
| `Cron.php` | Job periódico que encola el procesamiento |
| `Lib/RenewalProcessor.php` | Orquestación idempotente del flujo completo |
| `Lib/RenewalDateCalculator.php` | Política de fechas (meses naturales, recorte a fin de mes) |
| `docs/adr/` | Decisiones de arquitectura (leer antes de cambiar el diseño) |
| `Test/main/` | Tests: se copian a `Test/Plugins/` del núcleo |

## Flujo de validación

Ejecutar siempre, por este orden, antes de dar nada por terminado:

```bash
make format   # php-cs-fixer
make lint     # phpcs (debe terminar sin errores ni avisos)
make test     # phpunit dentro del contenedor (requiere Docker)
```

Los tres comandos levantan el entorno Docker automáticamente si hace falta.

## Reglas de oro del dominio

1. La fecha de vencimiento vive en la **suscripción**, jamás en el producto.
2. Todas las operaciones del procesador son **idempotentes**: repetir el
   cron nunca duplica ciclos, presupuestos ni emails.
3. La fecha solo avanza al detectar la factura (política `invoice`) o al
   confirmar manualmente (política `manual`), y **una sola vez por ciclo**.
4. Las sumas de periodo son por meses naturales con recorte a fin de mes
   (`RenewalDateCalculator`); prohibido `strtotime('+365 days')`.
5. Las notificaciones se persisten **antes** de encolar y el PDF se conserva
   hasta el envío correcto.
6. Nunca se escriben las tablas del núcleo directamente: presupuestos con
   `PresupuestoCliente` + `Calculator`, emails con `NewMail`, colas con
   `WorkQueue`.
7. No guardar credenciales de proveedores en ningún campo.

## Definition of Done

- [ ] `make format` sin cambios pendientes.
- [ ] `make lint` sin errores ni avisos.
- [ ] `make test` en verde (los tests DB corren dentro del contenedor).
- [ ] Tests nuevos para cualquier comportamiento nuevo (TDD).
- [ ] Traducciones `es_ES` y `en_EN` sincronizadas.
- [ ] ADR nuevo si la decisión es arquitectónica.
- [ ] `docs/CHANGELOG.md` actualizado.
- [ ] Sin referencias accidentales a los plugins usados como plantilla
      (AiScan, ScheduledMail).
