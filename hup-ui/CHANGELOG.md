# HUP Admin UI — Changelog

Versionado semántico. Cada plugin copia `hup-ui/` y anota aquí qué versión usa.
- **Mayor** (2.0.0): cambia el markup de algún componente → hay que tocar los plugins.
- **Menor** (1.1.0): componente nuevo, sin romper nada.
- **Parche** (1.0.1): ajuste visual en tokens o CSS.

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
