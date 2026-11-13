# HUP Admin UI

Sistema de diseño para los paneles de administración de los plugins HUP.

Esta copia corresponde a la versión 1.2.0: incorpora las correcciones 1.1.0
usadas por HUP - Instagram Feed y el componente `.hup-log` para procesos largos.

## Uso en un plugin nuevo
1. Copia esta carpeta dentro del plugin: `mi-plugin/hup-ui/`.
2. Copia `hup-ui/plantilla/admin-page.php` a `mi-plugin/includes/admin-page.php` y haz `require` desde el archivo principal.
3. Reemplaza `MIPLUGIN_` / `miplugin_` / `hup-mi-plugin` / textos / color del ícono.
4. Abre `componentes.html` en el navegador para ver y copiar cada componente.

## Instrucciones de desarrollo
Consulta `hup-ui/CLAUDE.md` antes de cambiar la interfaz. Si el plugin tiene
instrucciones en la raíz, añade esta línea:

```
Para toda la interfaz de administración sigue las reglas de hup-ui/CLAUDE.md y usa solo los componentes de hup-ui/componentes.html.
```

## Actualizar el sistema
Edita `tokens.css` / `hup-admin.css` en la copia maestra, sube la versión en `CHANGELOG.md` y vuelve a copiar `hup-ui/` a cada plugin.
