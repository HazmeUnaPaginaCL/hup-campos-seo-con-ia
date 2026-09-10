<?php
/**
 * Creación de posts nuevos con IA.
 *
 * @package HUP\CamposSeoIA\Generator
 */

namespace HUP\CamposSeoIA\Generator;

use HUP\CamposSeoIA\Settings;
use HUP\CamposSeoIA\Logger;
use HUP\CamposSeoIA\AI\ProviderFactory;
use HUP\CamposSeoIA\AI\ResponseParser;
use HUP\CamposSeoIA\AI\GeminiProvider;
use HUP\CamposSeoIA\SEO\Writer;
use HUP\CamposSeoIA\SEO\PluginDetector;

defined( 'ABSPATH' ) || exit;

class PostCreator {

    /**
     * Crea un post nuevo con contenido generado por IA.
     *
     * @param string $topic      Tema o keyword.
     * @param string $tone       Tono del contenido.
     * @param string $length     Extensión.
     * @param int    $category_id Categoría.
     *
     * @return array Resultado de la creación.
     */
    public static function create( string $topic, string $tone, string $length, int $category_id = 0 ): array {
        try {
            $provider = ProviderFactory::create_active();
            $model    = Settings::model();
            $api_key  = Settings::api_key( Settings::provider() );

            if ( empty( $api_key ) ) {
                return [ 'ok' => false, 'error' => Settings::api_key_error( Settings::provider() ) ];
            }

            // Construir prompt
            $prompt = PromptBuilder::build_create_post( $topic, $tone, $length );

            // Schema para Gemini
            $schema = [];
            if ( $provider instanceof GeminiProvider ) {
                $schema = GeminiProvider::build_schema( array_merge( [ 'post_title', 'post_content' ], Settings::fields_for( 'post' ) ) );
            }

            // Llamar a la IA (8192 tokens para posts largos con HTML)
            // Sin caché: crear un post es una acción única que debe dar contenido fresco
            $result = $provider->generate( $prompt, $model, $api_key, $schema, 8192, false );

            // Parsear respuesta
            $fields = ResponseParser::parse( $result['text'], [ 'post_title', 'post_content' ] );

            if ( empty( $fields['post_title'] ) || empty( $fields['post_content'] ) ) {
                $debug = mb_substr( $result['text'], 0, 300 );
                return [
                    'ok'    => false,
                    'error' => 'La IA no generó título o contenido válido. Respuesta: ' . $debug,
                ];
            }

            // Convertir contenido a HTML
            $html_content = self::text_to_html( $fields['post_content'] );

            // Crear el post como borrador
            $post_data = [
                'post_title'   => sanitize_text_field( $fields['post_title'] ),
                'post_content' => wp_kses_post( $html_content ),
                'post_status'  => 'draft',
                'post_type'    => 'post',
                'post_author'  => get_current_user_id(),
            ];

            if ( $category_id > 0 ) {
                $post_data['post_category'] = [ $category_id ];
            }

            $post_id = wp_insert_post( $post_data, true );

            if ( is_wp_error( $post_id ) ) {
                return [ 'ok' => false, 'error' => $post_id->get_error_message() ];
            }

            // Escribir campos SEO
            $seo_fields = array_intersect_key( $fields, array_flip( Settings::fields_for( 'post' ) ) );

            if ( ! empty( $seo_fields ) ) {
                $seo_plugin   = PluginDetector::detect_active();
                $write_target = ( 'none' !== $seo_plugin ) ? $seo_plugin : 'native';
                Writer::write( $post_id, $seo_fields, $write_target, true );
            }

            // Asignar tags
            if ( Settings::generates_field( 'tags' ) && ! empty( $fields['tags'] ) && is_array( $fields['tags'] ) ) {
                $tags = array_map( 'sanitize_text_field', $fields['tags'] );
                wp_set_post_tags( $post_id, $tags, true );
            }

            // Log
            $total_tokens = $result['tokens_input'] + $result['tokens_output'];
            $cost         = $result['tokens_output'] * Settings::cost_per_token( $model );
            Logger::log( $post_id, 'post', 'create_post', $total_tokens, $cost, $model, 'ok' );

            return [
                'ok'         => true,
                'post_id'    => $post_id,
                'post_title' => sanitize_text_field( $fields['post_title'] ),
                'edit_url'   => get_edit_post_link( $post_id, 'raw' ),
                'tokens'     => $total_tokens,
                'cost'       => $cost,
                'fields'     => $fields,
            ];

        } catch ( \Exception $e ) {
            Logger::log( 0, 'post', 'create_post', 0, 0, Settings::model(), 'error', $e->getMessage() );
            return [ 'ok' => false, 'error' => $e->getMessage() ];
        }
    }

    /**
     * Convierte texto plano con marcadores a HTML.
     * Soporta: ##Titulo## → <h2>, \n\n → <p>, listas con -.
     */
    private static function text_to_html( string $text ): string {
        // Si ya parece HTML, retornar tal cual
        if ( preg_match( '/<(?:p|h[1-6]|div|ul|ol)\b/i', $text ) ) {
            return $text;
        }

        // Convertir marcadores de subtítulos
        $text = preg_replace( '/##\s*(.+?)\s*##/', '<h2>$1</h2>', $text );
        $text = preg_replace( '/###\s*(.+?)\s*###/', '<h3>$1</h3>', $text );

        // Separar en párrafos
        $paragraphs = preg_split( '/\n{2,}/', trim( $text ) );
        $html       = '';

        foreach ( $paragraphs as $p ) {
            $p = trim( $p );
            if ( empty( $p ) ) {
                continue;
            }

            // Si es un heading, no envolver en <p>
            if ( preg_match( '/^<h[2-6]>/', $p ) ) {
                $html .= $p . "\n";
                continue;
            }

            // Detectar listas (líneas que empiezan con -)
            if ( preg_match( '/^[\-\*]\s/', $p ) ) {
                $items = preg_split( '/\n/', $p );
                $html .= "<ul>\n";
                foreach ( $items as $item ) {
                    $item = preg_replace( '/^[\-\*]\s+/', '', trim( $item ) );
                    if ( ! empty( $item ) ) {
                        $html .= '<li>' . $item . "</li>\n";
                    }
                }
                $html .= "</ul>\n";
                continue;
            }

            $html .= '<p>' . nl2br( $p ) . "</p>\n";
        }

        return $html;
    }
}
