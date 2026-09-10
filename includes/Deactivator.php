<?php
/**
 * Acciones de desactivación del plugin.
 * No elimina datos — eso se hace en uninstall.php.
 *
 * @package HUP\CamposSeoIA
 */

namespace HUP\CamposSeoIA;

defined( 'ABSPATH' ) || exit;

class Deactivator {

    /**
     * Ejecuta al desactivar el plugin.
     */
    public static function deactivate(): void {
        // Detener la purga programada del log: sin esto el evento seguiría
        // agendado con el plugin desactivado.
        wp_clear_scheduled_hook( Logger::PURGE_HOOK );

        // No eliminar datos al desactivar.
        // La limpieza completa se hace en uninstall.php.
    }
}
