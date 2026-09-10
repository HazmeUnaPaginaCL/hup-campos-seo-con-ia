<?php
/**
 * Inyección de meta tags SEO en el <head> del frontend.
 * Solo actúa cuando NO hay un plugin SEO instalado (modo nativo).
 *
 * @package HUP\CamposSeoIA\Frontend
 */

namespace HUP\CamposSeoIA\Frontend;

use HUP\CamposSeoIA\SEO\PluginDetector;
use HUP\CamposSeoIA\SEO\FieldMap;

defined( 'ABSPATH' ) || exit;

class MetaOutput {

    public function __construct() {
        // Solo inyectar si NO hay plugin SEO activo
        if ( 'none' !== PluginDetector::detect_active() ) {
            return;
        }

        add_action( 'wp_head', [ $this, 'render_meta_tags' ], 1 );
    }

    /**
     * Renderiza meta tags SEO en el <head>.
     *
     * Manda lo que generó el plugin (`_hcsai_*`), con el contenido nativo del
     * post como respaldo. Antes se ignoraban esos campos por completo: se
     * generaban, se pagaban sus tokens y se guardaban, pero aquí se imprimía
     * siempre el título del post y el extracto, así que nunca llegaban al
     * frontend. El Scorer, en cambio, sí los contaba: podías tener 100% de score
     * con unas etiquetas OG que no reflejaban nada de lo generado.
     */
    public function render_meta_tags(): void {
        if ( ! is_singular() ) {
            return;
        }

        $post_id = get_the_ID();
        $map     = FieldMap::get( 'native' );

        $excerpt = get_post_field( 'post_excerpt', $post_id );
        $excerpt = is_string( $excerpt ) ? trim( wp_strip_all_tags( $excerpt ) ) : '';

        $description = self::field( $post_id, $map['meta_description'] ?? '' );
        if ( '' === $description ) {
            $description = $excerpt;
        }

        $og_title = self::field( $post_id, $map['og_title'] ?? '' );
        if ( '' === $og_title ) {
            $og_title = get_the_title( $post_id );
        }

        $og_description = self::field( $post_id, $map['og_description'] ?? '' );
        if ( '' === $og_description ) {
            $og_description = $description;
        }

        if ( '' !== $description ) {
            echo '<meta name="description" content="' . esc_attr( $description ) . '">' . "\n";
        }

        echo '<meta property="og:title" content="' . esc_attr( $og_title ) . '">' . "\n";

        if ( '' !== $og_description ) {
            echo '<meta property="og:description" content="' . esc_attr( $og_description ) . '">' . "\n";
        }

        echo '<meta property="og:type" content="' . esc_attr( is_singular( 'product' ) ? 'product' : 'article' ) . '">' . "\n";
        echo '<meta property="og:url" content="' . esc_url( get_permalink( $post_id ) ) . '">' . "\n";

        // OG image
        $thumb_id = get_post_thumbnail_id( $post_id );
        if ( $thumb_id ) {
            $img_url = wp_get_attachment_image_url( $thumb_id, 'large' );
            if ( $img_url ) {
                echo '<meta property="og:image" content="' . esc_url( $img_url ) . '">' . "\n";
            }
        }
    }

    /**
     * Lee un meta field del post, ya sin etiquetas y sin espacios sobrantes.
     */
    private static function field( int $post_id, string $meta_key ): string {
        if ( '' === $meta_key ) {
            return '';
        }

        $value = get_post_meta( $post_id, $meta_key, true );

        return is_string( $value ) ? trim( wp_strip_all_tags( $value ) ) : '';
    }

}
