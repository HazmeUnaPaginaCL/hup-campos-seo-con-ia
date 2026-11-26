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
        add_filter( 'pre_get_document_title', [ $this, 'filter_document_title' ] );
    }

    /**
     * Si el plugin imprime sus etiquetas en el <head>.
     *
     * Filtro `hcsai_output_meta_tags`: devuelve false para que el plugin no
     * imprima nada (título, descripción ni Open Graph), por ejemplo si otro
     * plugin o el tema ya se encarga y aparecen etiquetas repetidas. Los campos
     * se siguen generando y guardando.
     *
     * @param string $part 'title', 'description' u 'open_graph'.
     */
    private static function enabled( string $part ): bool {
        return (bool) apply_filters( 'hcsai_output_meta_tags', true, $part, get_queried_object_id() );
    }

    /**
     * Si el tema Bricks ya imprime esa etiqueta para la página actual.
     *
     * Bricks imprime siempre sus Open Graph (salvo que se apaguen en Bricks →
     * Configuración → General) y la descripción y el título solo si la página o
     * su plantilla los tienen en «Ajustes de página → SEO». Se repite aquí su
     * misma lógica para no duplicar etiquetas.
     *
     * @param string $part 'title', 'description' u 'open_graph'.
     */
    private static function bricks_prints( string $part ): bool {
        if ( ! class_exists( '\Bricks\Database' ) || ! defined( 'BRICKS_DB_PAGE_SETTINGS' ) ) {
            return false;
        }

        $global = (array) ( \Bricks\Database::$global_settings ?? [] );

        if ( 'open_graph' === $part ) {
            return ! apply_filters( 'bricks/frontend/disable_opengraph', ! empty( $global['disableOpenGraph'] ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Filtro de Bricks: se consulta para saber qué imprime el tema.
        }

        if ( apply_filters( 'bricks/frontend/disable_seo', ! empty( $global['disableSeo'] ) ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Filtro de Bricks: se consulta para saber qué imprime el tema.
            return false;
        }

        $setting     = 'title' === $part ? 'documentTitle' : 'metaDescription';
        $template_id = (int) ( \Bricks\Database::$active_templates['content'] ?? 0 );

        foreach ( array_unique( array_filter( [ get_queried_object_id(), $template_id ] ) ) as $id ) {
            $settings = get_post_meta( $id, BRICKS_DB_PAGE_SETTINGS, true );
            if ( is_array( $settings ) && ! empty( $settings[ $setting ] ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Usa el título SEO generado como <title> de la página.
     *
     * Se generaba, se pagaba y sumaba 20 puntos al score, pero no llegaba nunca
     * al frontend. Solo actúa en temas con soporte de `title-tag`, que son los
     * que construyen el título con wp_get_document_title().
     *
     * @param string $title Título que propone WordPress u otro filtro.
     */
    public function filter_document_title( $title ) {
        if ( ! is_singular() || ! self::enabled( 'title' ) || self::bricks_prints( 'title' ) ) {
            return $title;
        }

        $seo_title = self::field( get_queried_object_id(), FieldMap::key( 'native', 'seo_title' ) );

        // Con este filtro WordPress devuelve el valor tal cual, sin pasarlo por
        // esc_html() como hace con el título que compone él mismo.
        return '' !== $seo_title ? esc_html( $seo_title ) : $title;
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
            $og_title = self::field( $post_id, $map['seo_title'] ?? '' );
        }
        if ( '' === $og_title ) {
            $og_title = get_the_title( $post_id );
        }

        $og_description = self::field( $post_id, $map['og_description'] ?? '' );
        if ( '' === $og_description ) {
            $og_description = $description;
        }

        if ( '' !== $description && self::enabled( 'description' ) && ! self::bricks_prints( 'description' ) ) {
            echo '<meta name="description" content="' . esc_attr( $description ) . '">' . "\n";
        }

        // Con las Open Graph de Bricks activas, se dejan a Bricks: si se apagan en
        // Bricks → Configuración, el plugin imprime las suyas con lo generado.
        if ( ! self::enabled( 'open_graph' ) || self::bricks_prints( 'open_graph' ) ) {
            return;
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
