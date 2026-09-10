<?php
/**
 * Limpieza al desinstalar el plugin.
 * Elimina opciones de la base de datos y la tabla de log.
 *
 * Las API keys del usuario se conservan salvo que haya activado
 * explícitamente la opción "Eliminar también las API keys al desinstalar"
 * en Configuración → API y Modelo.
 *
 * ⚠️ Todos los patrones LIKE pasan por $wpdb->esc_like(). En SQL el guion bajo
 * es un comodín de EXACTAMENTE un carácter, no un literal: sin escapar,
 * 'hcsai_%' casa también con 'hcsaip_...'. Desinstalar este plugin le habría
 * borrado los datos a cualquier otro cuyo prefijo empiece por "hcsai" seguido
 * de algo, y cambiarle el prefijo a ese otro no lo evitaría: casa igual.
 *
 * Las variables de este archivo viven un único request de desinstalación y no
 * las lee nadie más, así que no llevan prefijo. La eliminación de la tabla es
 * el motivo de existir del fichero.
 *
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange
 *
 * @package HUP\CamposSeoIA
 */

// WordPress debe definir esta constante antes de ejecutar
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// Consentimiento explícito del usuario para borrar sus API keys.
// Se lee ANTES de eliminar las opciones, porque esta misma opción se borra abajo.
$delete_api_keys = '1' === (string) get_option( 'hcsai_delete_api_keys', '0' );

// Detener la purga programada del log antes de borrar nada.
wp_clear_scheduled_hook( 'hcsai_purge_log' );

// Eliminar todas las opciones del plugin
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Limpieza en la desinstalación; no hay API de WordPress para borrado masivo por patrón y las cachés se descartan con el plugin.
$options = $wpdb->get_col(
    $wpdb->prepare(
        "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
        $wpdb->esc_like( 'hcsai_' ) . '%'
    )
);

foreach ( $options as $option ) {
    // Sin consentimiento explícito, las API keys encriptadas se conservan
    // para que una reinstalación no obligue al usuario a recuperarlas.
    if ( ! $delete_api_keys && 0 === strpos( $option, 'hcsai_api_key_' ) ) {
        continue;
    }
    delete_option( $option );
}

// Eliminar transients del plugin (caché IA y rate limiting).
// Se almacenan con prefijo _transient_ / _transient_timeout_, que no coincide
// con el patrón 'hcsai_%' anterior, por lo que se eliminan aparte.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Limpieza en la desinstalación; no hay API de WordPress para borrado masivo por patrón y las cachés se descartan con el plugin.
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options}
         WHERE option_name LIKE %s
            OR option_name LIKE %s",
        $wpdb->esc_like( '_transient_hcsai_' ) . '%',
        $wpdb->esc_like( '_transient_timeout_hcsai_' ) . '%'
    )
);

// Eliminar tabla de log
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Se elimina la tabla propia del plugin en la desinstalación.
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'hcsai_log' ) );

// Eliminar post meta generados por el plugin
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Limpieza en la desinstalación; no hay API de WordPress para borrado masivo por patrón y las cachés se descartan con el plugin.
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
        $wpdb->esc_like( '_hcsai_' ) . '%'
    )
);
