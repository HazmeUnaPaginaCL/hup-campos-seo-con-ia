<?php
/**
 * Constructor de prompts con variables dinámicas.
 * Reemplaza placeholders en los prompts con datos reales del post/producto.
 *
 * @package HUP\CamposSeoIA\Generator
 */

namespace HUP\CamposSeoIA\Generator;

use HUP\CamposSeoIA\Settings;

defined( 'ABSPATH' ) || exit;

class PromptBuilder {

    /**
     * Construye el prompt para generación SEO de un post/producto existente.
     *
     * @param \WP_Post $post Post o producto.
     * @param string   $type 'product', 'post', 'page'.
     * @return string Prompt con variables reemplazadas.
     */
    public static function build_seo( \WP_Post $post, string $type = 'product' ): string {
        // Los posts existentes usan su propia plantilla: la de tipo 'post' sirve
        // para CREAR un post nuevo ({{tema}}/{{tono}}/{{largo}}) y no recibe el
        // título ni el contenido reales, así que optimizaba sobre la nada.
        $prompt_key = ( 'post' === $type ) ? 'post_seo' : $type;

        // Se respeta siempre el prompt guardado. Antes se descartaba en silencio
        // si no contenía 'og_title': era una heurística para migrar prompts
        // anteriores a la v1.1.4, pero quedó como comportamiento permanente y
        // pisaba la personalización de quien quitaba los campos OG a propósito.
        // Esa migración ya la hace Activator::check_version_update(), y
        // Settings::prompt() cae al default si la opción está vacía.
        $template = Settings::prompt( $prompt_key );

        $variables = self::gather_variables( $post, $type );

        return self::replace_variables( $template, $variables );
    }

    /**
     * Construye el prompt para crear un post nuevo.
     *
     * @param string $topic     Tema o keyword.
     * @param string $tone      Tono del contenido.
     * @param string $length    Extensión: 'short', 'medium' o 'long'.
     * @return string Prompt completo.
     */
    public static function build_create_post( string $topic, string $tone, string $length ): string {
        $template    = Settings::prompt( 'post' );
        $length_desc = self::length_description( $length );

        $variables = [
            'tema'           => $topic,
            'tono'           => $tone,
            'largo'          => $length_desc,
            'pais'           => self::country_name(),
            'idioma'         => self::language_name(),
            'contexto_sitio' => Settings::site_context(),
        ];

        return self::replace_variables( $template, $variables );
    }

    /**
     * Construye el prompt para mejorar un post existente.
     *
     * @param \WP_Post $post    Post a mejorar.
     * @param array    $options Opciones: improve_content, improve_seo, add_cta, improve_tags.
     * @return string Prompt completo.
     */
    public static function build_improve( \WP_Post $post, array $options ): string {
        $instructions = [];

        if ( ! empty( $options['improve_content'] ) ) {
            $instructions[] = '- Mejora la redaccion y estructura del contenido.';
        }
        if ( ! empty( $options['improve_seo'] ) ) {
            $instructions[] = '- Optimiza titulo SEO, meta descripcion y focus keyword.';
        }
        if ( ! empty( $options['add_cta'] ) ) {
            $instructions[] = '- Agrega un llamado a la accion (CTA) al final del contenido.';
        }
        if ( ! empty( $options['improve_tags'] ) ) {
            $instructions[] = '- Sugiere etiquetas SEO relevantes.';
        }

        $template = Settings::prompt( 'improve' );

        $variables = [
            'pais'           => self::country_name(),
            'idioma'         => self::language_name(),
            'contexto_sitio' => Settings::site_context(),
            'titulo'         => $post->post_title,
            'contenido'      => wp_strip_all_tags( mb_substr( $post->post_content, 0, 3000 ) ),
            'instrucciones'  => implode( "\n", $instructions ),
        ];

        return self::replace_variables( $template, $variables );
    }

    /**
     * Recopila variables dinámicas del post.
     */
    private static function gather_variables( \WP_Post $post, string $type ): array {
        $vars = [
            'nombre'         => $post->post_title,
            'descripcion'    => wp_strip_all_tags( mb_substr( $post->post_content, 0, 2000 ) ),
            'pais'           => self::country_name(),
            'idioma'         => self::language_name(),
            'contexto_sitio' => Settings::site_context(),
        ];

        if ( 'product' === $type && function_exists( 'wc_get_product' ) ) {
            $product = wc_get_product( $post->ID );
            if ( $product ) {
                $vars['sku']       = $product->get_sku();
                $vars['precio']    = $product->get_price();
                $vars['categoria'] = self::get_product_categories( $post->ID );
                $vars['atributos'] = self::get_product_attributes( $product );
            }
        }

        if ( 'post' === $type || 'page' === $type ) {
            $cats = get_the_category( $post->ID );
            $vars['categoria'] = ! empty( $cats ) ? $cats[0]->name : '';
        }

        return $vars;
    }

    /**
     * Reemplaza {{variable}} en el template.
     */
    private static function replace_variables( string $template, array $variables ): string {
        foreach ( $variables as $key => $value ) {
            $template = str_replace( '{{' . $key . '}}', (string) $value, $template );
        }
        return $template;
    }

    /**
     * Obtiene categorías de producto como string.
     */
    private static function get_product_categories( int $post_id ): string {
        $terms = wp_get_post_terms( $post_id, 'product_cat', [ 'fields' => 'names' ] );
        return is_array( $terms ) ? implode( ', ', $terms ) : '';
    }

    /**
     * Obtiene atributos de producto como string.
     */
    private static function get_product_attributes( $product ): string {
        $attrs = $product->get_attributes();
        $parts = [];

        foreach ( $attrs as $attr ) {
            if ( is_object( $attr ) && method_exists( $attr, 'get_name' ) ) {
                $name    = wc_attribute_label( $attr->get_name() );
                $options = $attr->get_options();
                if ( $attr->is_taxonomy() ) {
                    $terms   = array_map( function ( $id ) { $t = get_term( $id ); return ( $t && ! is_wp_error( $t ) ) ? $t->name : ''; }, $options );
                    $options = array_filter( $terms );
                }
                $parts[] = $name . ': ' . implode( ', ', $options );
            }
        }

        return implode( ' | ', $parts );
    }

    /**
     * Descripción de longitud para el prompt de creación.
     *
     * El mapa es filtrable para que un addon pueda añadir extensiones propias
     * sin tocar este archivo. Una clave desconocida cae a la media en vez de
     * quedarse sin instrucción de longitud.
     */
    private static function length_description( string $length ): string {
        $map = apply_filters( 'hcsai_post_lengths', [
            'short'  => 'Corto: 400-600 palabras, 3-4 párrafos.',
            'medium' => 'Mediano: 800-1200 palabras, 5-7 párrafos.',
            'long'   => 'Largo: 1500-2000 palabras, 8-12 párrafos.',
        ] );

        return $map[ $length ] ?? ( $map['medium'] ?? '' );
    }

    /**
     * Nombre del país.
     */
    private static function country_name(): string {
        $countries = [ 'CL' => 'Chile', 'MX' => 'México', 'AR' => 'Argentina', 'CO' => 'Colombia', 'PE' => 'Perú', 'ES' => 'España', 'US' => 'Estados Unidos' ];
        $code = Settings::country();
        return $countries[ $code ] ?? $code;
    }

    /**
     * Nombre del idioma.
     */
    private static function language_name(): string {
        $languages = [ 'es' => 'español', 'en' => 'inglés', 'pt' => 'portugués', 'fr' => 'francés' ];
        $code = Settings::language();
        return $languages[ $code ] ?? $code;
    }

}
