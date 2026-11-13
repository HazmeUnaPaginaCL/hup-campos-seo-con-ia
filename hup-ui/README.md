# HUP Admin UI

Sistema de diseño para los paneles de administración de los plugins HUP.

## Uso en un plugin nuevo
1. Copia esta carpeta dentro del plugin: `mi-plugin/hup-ui/`.
2. Copia `hup-ui/plantilla/admin-page.php` a `mi-plugin/includes/admin-page.php` y haz `require` desde el archivo principal.
3. Reemplaza `MIPLUGIN_` / `miplugin_` / `hup-mi-plugin` / textos / color del ícono.
4. Abre `componentes.html` en el navegador para ver y copiar cada componente.

## Uso con Claude Code
Deja `hup-ui/CLAUDE.md` en el repo. Si el plugin ya tiene un `CLAUDE.md` en la raíz, agrega esta línea:

```
Para toda la interfaz de administración sigue las reglas de hup-ui/CLAUDE.md y usa solo los componentes de hup-ui/componentes.html.
```

Prompt sugerido:
> Crea la página de ajustes del plugin siguiendo hup-ui/CLAUDE.md. Secciones: General (usuario, intervalo, imágenes locales), Diseño (shortcode + vista previa), Caché. Color del plugin: #e07a2f, ícono dashicons-instagram.

## Actualizar el sistema
Edita `tokens.css` / `hup-admin.css` en la copia maestra, sube la versión en `CHANGELOG.md` y vuelve a copiar `hup-ui/` a cada plugin.
