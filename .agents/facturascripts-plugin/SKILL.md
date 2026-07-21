---
name: facturascripts-plugin
description: Arquitectura de plugins de FacturaScripts aplicada a ServiceRenewals.
---

# Plugin de FacturaScripts

## Estructura

- `Init.php` (`Core\Template\InitClass`): `init()` registra workers
  (`WorkQueue::addWorker`) y extensiones (`$this->loadExtension(...)`);
  `update()` siembra los Settings que falten.
- `Cron.php` (`Core\Template\CronClass`): job `process-renewals` con
  `every('1 hour')` que encola el evento `ServiceRenewals.Process`.
- `Model/` + `Table/*.xml`: cada modelo declara `tableName()`,
  `primaryColumn()`, `clear()`, `test()`, `install()`. El XML de tabla usa
  columnas `serial/integer/character varying(N)/date/timestamp/boolean/text`
  y constraints SQL en crudo (`PRIMARY KEY`, `UNIQUE`, `FOREIGN KEY`).
- `Controller/` + `XMLView/`: `ListController`/`EditController`/
  `PanelController`; el nombre del XMLView debe coincidir con el nombre de
  la vista añadida.
- `Extension/Controller/<Controlador>.php`: cada método público devuelve un
  `Closure` que se ejecuta con `$this` ligado al controlador destino
  (hooks: `createViews`, `loadData`, `execPreviousAction`...).
- `Worker/` (`Core\Template\WorkerClass`): `run(WorkEvent $event): bool`,
  terminar siempre con `return $this->done();`.

## Reglas

- Modelos del núcleo siempre desde `FacturaScripts\Dinamic\Model\*`.
- Presupuestos: `setSubject()` + `save()` + `getNewProductLine()` +
  `Calculator::calculate($doc, $lines, true)`. En CLI, asignar
  `codalmacen`/`codserie` si faltan (`Almacenes::default()`,
  `Series::default()`).
- Detección de facturas: `DocTransformation` (model1/iddoc1 →
  model2/iddoc2), siguiendo cadenas presupuesto → pedido → factura.
- Emails: `NewMail::create()` fluido; `send()` devuelve bool y no lanza por
  fallos SMTP.
- Fechas: el modelo normaliza a ISO al guardar, pero el núcleo devuelve
  `d-m-Y` al recargar; comparar siempre con
  `RenewalDateCalculator::toIso()`.
- Permisos en acciones: `$this->permissions->allowUpdate` +
  `$this->validateFormToken()` antes de cualquier mutación.
- Traducciones: claves kebab-case en inglés; `es_ES.json` y `en_EN.json`
  siempre sincronizados.
