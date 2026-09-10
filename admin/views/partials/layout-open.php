<?php
/**
 * Vista de administración.
 *
 * Las vistas se cargan con `require` DENTRO de un método de Admin\Menu, así que
 * las variables de este archivo son locales a ese método, no globales: el sniff
 * de prefijos no puede verlo desde aquí y marcaría cada una como global sin
 * prefijo. Los $_GET son filtros, orden y paginación de un listado de solo
 * lectura —no cambian estado, así que no llevan nonce— y todos van sanitizados
 * al leerlos. meta_query es la forma soportada de filtrar por los campos que
 * escriben los plugins SEO, y el listado va paginado.
 *
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
 * phpcs:disable WordPress.Security.NonceVerification.Recommended
 * phpcs:disable WordPress.DB.SlowDBQuery
 *
 * @package HUP\CamposSeoIA
 */

defined( 'ABSPATH' ) || exit;

$current_page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
$provider     = \HUP\CamposSeoIA\Settings::provider();
$conn_status  = \HUP\CamposSeoIA\Settings::connection_status( $provider );
$providers    = \HUP\CamposSeoIA\Settings::providers_display();
$provider_name = $providers[ $provider ] ?? $provider;

$nav_items = [
    'hcsai'               => [ 'label' => 'Dashboard',      'icon' => '📊' ],
    'hcsai-productos'     => [ 'label' => 'Productos',      'icon' => '🛒' ],
    'hcsai-blog'          => [ 'label' => 'Blog',           'icon' => '📝' ],
    'hcsai-configuracion' => [ 'label' => 'Configuración',  'icon' => '⚙️' ],
];

// Status badge
$status_badge = '';
if ( 'ok' === $conn_status ) {
    $status_badge = '<span class="hup-seo-badge hup-seo-badge--connected"><span class="hup-seo-badge__dot"></span>Conectado · ' . esc_html( $provider_name ) . '</span>';
} else {
    $status_badge = '<span class="hup-seo-badge hup-seo-badge--disconnected"><span class="hup-seo-badge__dot"></span>Sin conexión</span>';
}
?>
<div class="hup-seo-wrap">
    <div class="hup-seo-header">
        <div class="hup-seo-header__logo">
            <span class="hup-seo-header__icon">
                <svg width="28" height="28" viewBox="0 0 28 28" fill="none">
                    <rect width="28" height="28" rx="7" fill="#2271b1"/>
                    <!-- Documento con líneas (SEO/Contenido) -->
                    <path d="M7 8h14v12H7z" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
                    <path d="M10 12h8M10 15h6" stroke="white" stroke-width="1.5" stroke-linecap="round"/>
                    <!-- Brillo/Estrella (IA/Generación) -->
                    <path d="M18 7l1 2 2 1-2 1-1 2-1-2-2-1 2-1z" fill="#86efac"/>
                </svg>
            </span>
            <div>
                <h1 class="hup-seo-header__title">
                    HUP - Campos SEO con IA
                    <span class="hup-seo-header__version">v<?php echo esc_html( HCSAI_VERSION ); ?></span>
                </h1>
                <p class="hup-seo-header__desc">
                    Rellena con IA los campos de tus productos y posts con textos escritos para posicionar.
                    Detecta RankMath, Yoast SEO y All In One SEO.
                    ¿Tienes dudas? Escríbenos a <a href="mailto:contacto@hazmeunapagina.cl">contacto@hazmeunapagina.cl</a> —
                    Desarrollado por <a href="https://www.hazmeunapagina.cl" target="_blank" rel="noopener noreferrer">HazmeUnaPagina.cl</a>
                </p>
            </div>
        </div>
        <div class="hup-seo-header__status">
            <?php echo wp_kses_post( $status_badge ); ?>
        </div>
    </div>

    <div class="hup-seo-layout">
        <nav class="hup-seo-sidebar">
            <?php foreach ( $nav_items as $slug => $item ) : 
                $is_active = ( $current_page === $slug );
            ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $slug ) ); ?>"
                   class="hup-seo-tab-btn <?php echo $is_active ? 'is-active' : ''; ?>">
                    <span class="hup-seo-tab-btn__icon"><?php echo esc_html( $item['icon'] ); ?></span>
                    <span><?php echo esc_html( $item['label'] ); ?></span>
                </a>
            <?php endforeach; ?>
            <div class="hup-seo-sidebar-footer">
                Hecho con ❤️ por HUP
            </div>
        </nav>

        <main class="hup-seo-content">
