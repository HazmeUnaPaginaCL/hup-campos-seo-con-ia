# HUP Admin UI — Changelog

Versionado semántico. Cada plugin copia `hup-ui/` y anota aquí qué versión usa.
- **Mayor** (2.0.0): cambia el markup de algún componente → hay que tocar los plugins.
- **Menor** (1.1.0): componente nuevo, sin romper nada.
- **Parche** (1.0.1): ajuste visual en tokens o CSS.

## 1.4.0 — 26/11/2026
Sale primero con HUP - Sincroniza productos en planillas 1.1.0; después, Campos SEO 1.2.0, Kit 1.4.0 e Indicadores 1.3.0. No cambia el markup existente: basta con copiar `hup-ui/` y subir la versión del enqueue.
- **Listas desplegables con flecha.** `.hup-select` dibuja su propia flecha (antes `background: #fff` borraba la de WordPress y parecían campos de texto). Deshabilitados con fondo gris.
- **Móvil (≤ 782 px):** header más compacto, título y badges en filas separadas (antes el título ocupaba 4-5 líneas), descripción en varias líneas, menos relleno en `.hup-main` y tarjetas, y la pestaña activa del menú se centra al cargar y al cambiar.
- `.hup-card__head` baja el botón o badge de la cabecera bajo el título cuando no cabe a su lado.
- `.hup-btn-group` no se corta: en móvil ocupa el ancho y reparte los botones.
- `.hup-account`: el botón baja si no cabe junto al correo (antes se montaba).
- **Tablas con desplazamiento propio:** `.hup-table-wrap` nuevo; `hup-admin.js` envuelve solo las `.hup-table` que no lo traen ni están ya en un contenedor con scroll. Encabezados sin cortar en móvil.
- **Modales:** cualquier `.hup-modal` que se muestre recibe `role="dialog"`, `aria-modal` y nombre; el foco entra, no sale con Tab y vuelve al botón que lo abrió. Escape cierra (pulsa su `[data-hup-close]`). `data-hup-static` impide cerrar con Escape o con el fondo. La caja normal tiene alto máximo con scroll.
- `.hup-modal__scroll > * { flex-shrink: 0 }`: el arreglo de Campos SEO 1.1.2 pasa a la copia maestra.
- Evento cancelable `hup:modal-close` en el `.hup-modal` antes de cerrar con `[data-hup-close]`, el fondo o Escape. Con `preventDefault()` el modal no se cierra (lo usa Campos SEO para avisar de cambios sin guardar).
- `.hup-card__head + .hup-card__foot`: una tarjeta sin cuerpo ya no deja el texto pegado a la línea del pie.
- **Toasts:** se apilan, tienen botón de cerrar, duran 5 s (error 10 s) más un extra si el texto es largo y se pausan con el puntero o el foco encima. `HUP.toast(msg, tipo, { duration })`; `duration: 0` deja el aviso hasta cerrarlo.
- **Mostrar/ocultar clave:** `[data-hup-reveal]` cambia el ícono (`visibility` / `hidden`), `aria-pressed` y su texto accesible.
- **Tooltips:** nombre accesible (`role="img"` + `aria-label` con el texto), Escape los oculta y no se salen por los bordes de la pantalla.
- **«Otros plugins HUP»:** `otros-plugins.php` (lista única de plugins) y componentes `.hup-plugins` / `.hup-plugin`. Separador del menú `.hup-nav__sep`.
- `HUP.refresh(raiz)`: vuelve a preparar tablas y tooltips de contenido cargado por AJAX.
- «Copiar» desde un `<input>` o `<textarea>` copia su valor actual.

## Sin cambio de versión — 19/11/2026 · Pie del menú
- El pie de `.hup-nav` pasa a dos líneas: `Hecho por HUP 💻` y `Desde Quilpué para WordPress`, con los enlaces en negrita (`<strong>`). «HUP» enlaza a hazmeunapagina.cl y «WordPress» a `https://wordpress.org/plugins/search/hup/`. Regla y markup en `CLAUDE.md` (Estructura de página).
- Solo cambia el texto del pie en cada panel; `tokens.css`, `hup-admin.css` y `hup-admin.js` siguen iguales, así que la versión se mantiene en 1.3.1.

## 1.3.1 — 13/11/2026
- `.hup-card__foot` con `flex-wrap: wrap`: en móvil los botones del pie no bajaban de línea: se salían de la tarjeta y el último quedaba cortado. Visto en Sincronización.
- Solo cambia `hup-admin.css`. Los plugins publicados en WordPress.org (Kit, Campos SEO, Indicadores) lo llevan en su próxima versión; no se publicó una versión solo por esto.

## 1.3.0 — 13/11/2026
Copia maestra consolidada. Hasta aquí había dos "1.2.0" distintas (Campos SEO con IA y su Pro): esta versión es la unión de ambas más lo agregado al adaptar HUP - Kit Ecommerce Chile. **Es la versión a copiar en todos los plugins.**
- Unifica las dos 1.2.0: modal ancho y acordeón con párrafos (Free) + `.hup-log` y arreglos de ancho en móvil (Pro).
- El acordeón también admite `<ul>` directo.
- `HUP.toast(msg, 'error')`: variante de error (fondo rojo, ícono de advertencia, `role="alert"`).
- `.hup-dot`: punto de color para datos que elige el usuario (color de una zona). El color va en `--hup-dot`.
- `.hup-badge--pro`: insignia para funciones de la versión Pro.
- `.hup-checkgroup__actions` (acciones en la cabecera), `.hup-checkgroup__body--scroll` (cuerpo con alto máximo), `.hup-checkgroup__foot` (pie) y estado `.is-empty`.
- `.hup-toolbar`: búsqueda + acciones sobre una lista.
- `.hup-checklist`: resultado de una verificación, con `is-ok` / `is-fail`.
- Corregido: los ítems del menú lateral salían con el color de enlace (`.hup-app a`) en vez del de la navegación.

## 1.2.0 — 31/10/2026 (Campos SEO con IA)
- Modal ancho `.hup-modal__box--wide` y área desplazable `.hup-modal__scroll` para formularios extensos.
- El acordeón admite párrafos y listas directos además de un contenedor `div`.

## 1.2.0 — 31/10/2026 (Campos SEO con IA Pro)
- Componente `.hup-log` para mostrar resultados de procesos largos con estados de éxito, error e información.
- `.hup-checkgroup` usa una columna en pantallas estrechas.
- Las pilas, filas de campos y tarjetas de opción pueden encogerse sin cortar contenido en móvil.

## 1.1.0 — 30/10/2026
Agregado al adaptar HUP - Instagram Feed.
- Componente nuevo `.hup-slider`: deslizador + campo numérico + unidad en una fila.
- `.hup-app [hidden]` oculta siempre, aunque el componente tenga su propio `display`.
- `.hup-choice__title` y `.hup-choice__desc` son bloque: ya no hace falta `style="display:block"`.
- `.hup-seg`: el estilo de botón va solo en `input + span`; antes también lo recibía el `<span>` del dashicon interno.
- `hup-admin.js`: «Copiar» funciona sin HTTPS (sitios locales), donde no existe `navigator.clipboard`.

## 1.0.0 — 30/10/2026
- Estilo "Suave": tarjetas con sombra leve, radios 10/12/16/20, botones píldora.
- Menú lateral claro por defecto; `hup-app--nav-dark` opcional.
- `tokens.css` separado de `hup-admin.css`.
- Componentes: header, nav, page-head, card, field, input, input-group, search, select, textarea, toggle, check, seg, choice, chip, checkgroup, btn (primario, secondary, ghost, danger, danger-outline, sm, icon, add), btn-group, spinner, badge, notice, tip, toast, modal, progress, skeleton, empty, pager, tabs, kv, stat, status-row, table, rows, sortable, repeater, code, copyfield, tag, steps, accordion, account, avatar, media, range, preview.
