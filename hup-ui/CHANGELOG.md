# HUP Admin UI — Changelog

## 1.2.0 — 31/10/2026

- Modal ancho `.hup-modal__box--wide` y área desplazable `.hup-modal__scroll` para formularios extensos.
- El acordeón admite párrafos y listas directos además de un contenedor `div`.

Versionado semántico. Cada plugin copia `hup-ui/` y anota aquí qué versión usa.
- **Mayor** (2.0.0): cambia el markup de algún componente → hay que tocar los plugins.
- **Menor** (1.1.0): componente nuevo, sin romper nada.
- **Parche** (1.0.1): ajuste visual en tokens o CSS.

## 1.1.0 — 30/10/2026
Agregado al adaptar HUP - Instagram Feed (llevar también a la copia maestra).
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
