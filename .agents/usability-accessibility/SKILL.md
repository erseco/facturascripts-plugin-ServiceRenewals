---
name: usability-accessibility
description: Usabilidad y accesibilidad de las vistas de ServiceRenewals.
---

# Usabilidad y accesibilidad

- Componentes nativos de FacturaScripts primero: XMLViews, filtros y
  controladores estándar. Nada de SPA ni frameworks JavaScript.
- Bootstrap 5 en las vistas Twig propias (panel); iconos Font Awesome 6
  (`fa-solid fa-...`) con `aria-hidden="true"` cuando son decorativos.
- Los estados nunca se comunican solo con color: siempre hay texto (badge
  con etiqueta, columna de estado traducida).
- Todos los textos de interfaz salen de `Translation/*.json`; jamás cadenas
  incrustadas.
- Las fechas se muestran en el formato del núcleo (`d-m-Y`) y los importes
  con `Tools::money()`.
- Las acciones destructivas o irreversibles (cancelar suscripción,
  confirmar renovación) son botones explícitos con etiqueta clara, nunca
  efectos secundarios de otra acción.
- Las tablas anchas van dentro de `.table-responsive`.
