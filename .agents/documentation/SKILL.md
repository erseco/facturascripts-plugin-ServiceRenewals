---
name: documentation
description: Documentación de ServiceRenewals (README, manual, forja, ADR, changelog).
---

# Documentación

- Toda la documentación se escribe en **español**, clara y sin expresiones
  publicitarias exageradas.
- `README.md`: desarrolladores y usuarios técnicos. Debe dejar claro que la
  fecha pertenece a la suscripción, que el cron es obligatorio, que no se
  renueva en el registrador ni se cobra automáticamente y que cada ciclo
  genera como máximo un presupuesto.
- `QUICKSTART.md`: probar el plugin en local en minutos con `make upd` +
  `make cron` + Mailpit.
- `.github/manual.md`: manual para usuarios finales, sin comandos de
  desarrollo.
- `.github/forja.md`: descripción pública para la forja, en párrafos, sin
  comandos ni afirmaciones falsas (no renueva dominios por API, no cobra).
- `docs/adr/NNNN-*.md`: una decisión por archivo con el formato del ADR-0000
  de AiScan (Status/Date/Context/Decision/Alternativas
  rechazadas/Consequences). Crear uno nuevo para cada decisión
  arquitectónica; no reescribir la historia de los aceptados.
- `docs/CHANGELOG.md`: formato Keep a Changelog; versiones enteras o con un
  decimal (restricción de FacturaScripts).
- Capturas: `.github/screenshot.png` debe ser una captura real del entorno
  demo, sin datos personales.
