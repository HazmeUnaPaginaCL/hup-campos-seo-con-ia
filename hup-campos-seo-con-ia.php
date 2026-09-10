<?php
/**
 * Plugin Name:       HUP - Campos SEO con IA
 * Plugin URI:        https://hazmeunapagina.cl/hup-campos-seo-ia
 * Description:       Rellena con IA los campos de tus productos y posts —descripciones, etiquetas y alt text— con textos escritos para posicionar. Detecta RankMath, Yoast SEO y All In One SEO.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      8.3
 * Author:            HazmeUnaPagina.cl
 * Author URI:        https://hazmeunapagina.cl
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       hup-campos-seo-con-ia
 * Domain Path:       /languages
 *
 * @package HUP\CamposSeoIA
 */

defined( 'ABSPATH' ) || exit;

// ── Constantes ──────────────────────────────────────────────────────────────
define( 'HCSAI_VERSION',  '1.0.0' );
define( 'HCSAI_FILE',     __FILE__ );
define( 'HCSAI_PATH',     plugin_dir_path( __FILE__ ) );
define( 'HCSAI_URL',      plugin_dir_url( __FILE__ ) );
define( 'HCSAI_BASENAME', plugin_basename( __FILE__ ) );
define( 'HCSAI_PREFIX',   'hcsai_' );

// ── Autoloader PSR-4 ────────────────────────────────────────────────────────
spl_autoload_register( function ( string $class ) {
    $namespace = 'HUP\\CamposSeoIA\\';

    if ( strpos( $class, $namespace ) !== 0 ) {
        return;
    }

    // Convertir namespace a ruta de archivo
    // HUP\CamposSeoIA\AI\OpenAIProvider → includes/AI/OpenAIProvider.php
    $relative = str_replace( $namespace, '', $class );
    $file     = HCSAI_PATH . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';

    if ( file_exists( $file ) ) {
        require_once $file;
    }
} );

// ── Activación / Desactivación ──────────────────────────────────────────────
register_activation_hook( __FILE__, [ 'HUP\\CamposSeoIA\\Activator', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'HUP\\CamposSeoIA\\Deactivator', 'deactivate' ] );

// ── Arranque del plugin ─────────────────────────────────────────────────────
// WordPress carga las traducciones solo desde 4.6 para los plugins alojados en
// WordPress.org, así que llamar a load_plugin_textdomain() aquí sobraba.
add_action( 'plugins_loaded', function () {
    HUP\CamposSeoIA\Core::instance();
} );

// ── Migraciones automáticas en admin ────────────────────────────────────────
add_action( 'admin_init', [ 'HUP\\CamposSeoIA\\Activator', 'run_migrations' ] );
