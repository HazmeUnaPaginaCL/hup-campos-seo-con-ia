<?php
/**
 * HUP Admin UI 1.4.0: sección «Otros plugins HUP».
 *
 * La lista de plugins vive solo aquí: para sumar uno, se agrega una entrada y
 * se copia hup-ui/ a cada plugin. El archivo devuelve una función anónima y no
 * declara nada global, así que varios plugins HUP activos no chocan aunque
 * traigan versiones distintas de hup-ui.
 *
 * Uso, dentro de la sección del panel (después de su .hup-page-head):
 *
 *     $hup_otros = include PLUGIN_DIR . 'hup-ui/otros-plugins.php';
 *     $hup_otros( 'slug-de-este-plugin' );
 *
 * Imprime una tarjeta por plugin (ícono con su color, nombre, una frase y
 * enlace a WordPress.org). Se omite a sí mismo y marca «Instalado» los que ya
 * están activos. Sin imágenes externas ni consultas a WordPress.org.
 */

defined( 'ABSPATH' ) || exit;

return static function ( $self_slug = '' ) {
	// slug => [ nombre, frase, dashicon, color del plugin ].
	$plugins = array(
		'hup-kit-ecommerce-chile'               => array( 'HUP - Kit Ecommerce Chile', 'Regiones, comunas, RUT, despacho y facturación para tu tienda WooCommerce.', 'dashicons-cart', '#0039a6' ),
		'hup-sincroniza-productos-en-planillas' => array( 'HUP - Sincroniza productos en planillas', 'Edita tus productos de WooCommerce desde Google Sheets.', 'dashicons-media-spreadsheet', '#0f766e' ),
		'hup-campos-seo-con-ia'                 => array( 'HUP - Campos SEO con IA', 'Completa con IA las descripciones, etiquetas y campos SEO.', 'dashicons-welcome-write-blog', '#7c3aed' ),
		'hup-indicadores-economicos-chile'      => array( 'HUP - Indicadores Económicos (Chile)', 'Publica UF, UTM, dólar y más con un shortcode.', 'dashicons-chart-line', '#15803d' ),
	);

	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	echo '<ul class="hup-plugins">';
	foreach ( $plugins as $slug => $plugin ) {
		if ( $slug === $self_slug ) {
			continue;
		}
		$active = is_plugin_active( $slug . '/' . $slug . '.php' );
		$url    = 'https://wordpress.org/plugins/' . $slug . '/';
		?>
		<li>
			<article class="hup-plugin">
				<div class="hup-plugin__icon" style="--hup-plugin:<?php echo esc_attr( $plugin[3] ); ?>"><span class="dashicons <?php echo esc_attr( $plugin[2] ); ?>" aria-hidden="true"></span></div>
				<div class="hup-plugin__text">
					<h3 class="hup-plugin__name">
						<?php echo esc_html( $plugin[0] ); ?>
						<?php if ( $active ) : ?>
							<span class="hup-badge hup-badge--success hup-badge--dot">Instalado</span>
						<?php endif; ?>
					</h3>
					<p class="hup-plugin__desc"><?php echo esc_html( $plugin[1] ); ?></p>
					<a class="hup-plugin__link" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer">Ver en WordPress.org<span class="dashicons dashicons-external" aria-hidden="true"></span><span class="screen-reader-text"> (se abre en una pestaña nueva)</span></a>
				</div>
			</article>
		</li>
		<?php
	}
	echo '</ul>';
};
