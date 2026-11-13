<?php
/** Cabecera y navegación compartidas del panel HUP. */
defined( 'ABSPATH' ) || exit;

// Navegación: solo decide qué ítem del menú se marca como activo y se valida
// contra $hcsai_sections más abajo. No modifica datos, así que no lleva nonce.
$hcsai_page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : 'hcsai'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$hcsai_tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$hcsai_aliases = [
    'hcsai-productos'     => 'productos',
    'hcsai-blog'          => 'blog',
    'hcsai-configuracion' => 'configuracion',
    'hcsai-paginas'       => 'paginas',
];
if ( isset( $hcsai_aliases[ $hcsai_page ] ) ) {
    $hcsai_tab = $hcsai_aliases[ $hcsai_page ];
}

$hcsai_sections = [
    'dashboard'     => [ 'Dashboard', 'dashicons-dashboard', 'Revisa el estado del contenido y las generaciones recientes.' ],
    'productos'     => [ 'Productos', 'dashicons-products', 'Escanea, completa y edita los campos de tus productos.' ],
    'blog'          => [ 'Blog', 'dashicons-edit-page', 'Crea, mejora y completa el contenido de tus posts.' ],
    'configuracion' => [ 'Configuración', 'dashicons-admin-settings', 'Configura la IA, el perfil del sitio y los prompts.' ],
];

// El addon actual registra Páginas como submenú. También puede añadir secciones
// propias mediante el filtro sin depender de selectores JavaScript antiguos.
global $submenu;
foreach ( (array) ( $submenu['hcsai'] ?? [] ) as $hcsai_submenu_item ) {
    if ( 'hcsai-paginas' === ( $hcsai_submenu_item[2] ?? '' ) ) {
        $hcsai_sections['paginas'] = [ 'Páginas', 'dashicons-admin-page', 'Completa y edita los campos SEO de tus páginas.' ];
        break;
    }
}
$hcsai_sections = (array) apply_filters( 'hcsai_admin_sections', $hcsai_sections );
if ( ! isset( $hcsai_sections[ $hcsai_tab ] ) ) {
    $hcsai_tab = 'dashboard';
}

$hcsai_nav_url = static function ( string $section ): string {
    if ( 'paginas' === $section ) {
        return admin_url( 'admin.php?page=hcsai-paginas' );
    }
    return add_query_arg( [ 'page' => 'hcsai', 'tab' => $section ], admin_url( 'admin.php' ) );
};
?>
<div class="wrap">
    <h1 class="screen-reader-text">HUP - Campos SEO con IA</h1>
    <?php settings_errors(); ?>
    <div class="hup-app" style="--hup-plugin:#7c3aed">
        <header class="hup-header">
            <div class="hup-header__icon"><span class="dashicons dashicons-welcome-write-blog" aria-hidden="true"></span></div>
            <div class="hup-header__text">
                <h2 class="hup-header__title">HUP - Campos SEO con IA <span class="hup-badge hup-badge--version">v<?php echo esc_html( HCSAI_VERSION ); ?></span></h2>
                <p class="hup-header__desc">Completa con IA los campos SEO de tus productos y posts.</p>
            </div>
            <div class="hup-header__support"><span class="dashicons dashicons-email-alt" aria-hidden="true"></span>¿Dudas o soporte? <a href="mailto:contacto@hazmeunapagina.cl">contacto@hazmeunapagina.cl</a></div>
        </header>

        <div class="hup-layout">
            <nav class="hup-nav" aria-label="Secciones">
                <ul class="hup-nav__list">
                    <?php foreach ( $hcsai_sections as $hcsai_slug => $hcsai_section ) : ?>
                    <li><a class="hup-nav__item<?php echo $hcsai_slug === $hcsai_tab ? ' is-active' : ''; ?>" href="<?php echo esc_url( $hcsai_nav_url( $hcsai_slug ) ); ?>"<?php echo $hcsai_slug === $hcsai_tab ? ' aria-current="page"' : ''; ?>><span class="dashicons <?php echo esc_attr( $hcsai_section[1] ); ?>" aria-hidden="true"></span><?php echo esc_html( $hcsai_section[0] ); ?></a></li>
                    <?php endforeach; ?>
                </ul>
                <div class="hup-nav__footer">Hecho por <a href="https://hazmeunapagina.cl" target="_blank" rel="noopener noreferrer">HUP</a></div>
            </nav>
            <main class="hup-main">
                <div class="hup-page-head">
                    <h2><?php echo esc_html( $hcsai_sections[ $hcsai_tab ][0] ); ?></h2>
                    <p><?php echo esc_html( $hcsai_sections[ $hcsai_tab ][2] ); ?></p>
                </div>
                <div class="hup-stack">
