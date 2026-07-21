---
name: testing-devops
description: Tests, Docker, Makefile y CI de ServiceRenewals.
---

# Testing y DevOps

## Tests

- TDD: primero el test que falla, después la implementación mínima.
- `Test/main/*Test.php` con namespace `FacturaScripts\Test\Plugins`; el
  Makefile los copia a `Test/Plugins/` del núcleo dentro del contenedor.
- Los tests puros (fechas, plantillas, listas de días) no tocan la base de
  datos. Los de integración crean sus propios fixtures (`Cliente`,
  `Producto`) con `uniqid()` y los borran en `tearDown()` **en orden
  inverso de creación** (claves foráneas).
- Tests que crean documentos: comprobar antes
  `Empresas::default()->idempresa` y hacer `markTestSkipped` si la
  instalación no tiene datos básicos.
- Nada de SMTP real: para simular fallos de envío usar
  `Tools::settingsSet('email', ...)` en memoria y `Tools::settingsClear()`
  al terminar.
- Los tests no pueden depender del día real: la fecha de proceso se pasa
  explícitamente a `RenewalProcessor::process()` y `RenewalScanner`.

## Entorno Docker

- `make upd`: FacturaScripts (erseco/alpine-facturascripts) + MariaDB +
  Mailpit; instalación desatendida, seed del `blueprint.json` y
  `docker/setup-servicerenewals.php` (activa plugin, SMTP a Mailpit y datos
  demo).
- Web: http://localhost:8080 (admin/admin) — Mailpit: http://localhost:8025.
- `make cron` procesa renovaciones y envía emails a Mailpit.

## CI

- `.github/workflows/ci.yml`: job `docker-test` (lint + test en el
  contenedor, sin montar el plugin: se copia con `docker cp`) y job `test`
  con matriz PHP 8.1–8.5 + MySQL clonando el núcleo desde GitHub.
- `.github/workflows/release.yml`: etiquetas `N` o `N.N` (nunca `N.N.N`);
  el ZIP se construye con `git archive --prefix=ServiceRenewals/` (los
  export-ignore de `.gitattributes` deciden el contenido) y se publica en
  GitHub Releases y en la forja (`erseco/action-facturascripts-publicar-forja`).
