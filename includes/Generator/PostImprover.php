<?php
/**
 * Mejora de posts existentes con IA.
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

class PostImprover {

    /**
     * Genera mejoras para un post existente.
     *
     * @param int   $post_id ID del post.
     * @param array $options Opciones: improve_content, improve_seo, add_cta, improve_tags.
     *
     * @return array Resultado con campos mejorados.
     */
    public static function improve( int $post_id, array $options ): array {
        $post = get_post( $post_id );

        if ( ! $post ) {
            return [ 'ok' => false, 'error' => 'Post no encontrado.' ];
        }

        // Igual que en SingleGenerator: sin permiso de edición, apply() nunca
        // podría guardar la mejora, así que no se paga la llamada a la IA.
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return [ 'ok' => false, 'error' => 'Sin permisos para editar este post.' ];
        }

        try {
            $provider = ProviderFactory::create_active();
            $model    = Settings::model();
            $api_key  = Settings::api_key( Settings::provider() );

            if ( empty( $api_key ) ) {
                return [ 'ok' => false, 'error' => Settings::api_key_error( Settings::provider() ) ];
            }

            // Construir prompt
            $prompt = PromptBuilder::build_improve( $post, $options );

            // Schema para Gemini
            $schema = [];
            if ( $provider instanceof GeminiProvider ) {
                $schema = GeminiProvider::build_schema( array_merge( [ 'post_title', 'post_content' ], Settings::fields_for( 'post' ) ) );
            }

            // Llamar a la IA (8192 tokens para posts largos con HTML)
            // Sin caché: mejorar un post debe producir una respuesta fresca cada vez
            $result = $provider->generate( $prompt, $model, $api_key, $schema, 8192, false );

            // Parsear respuesta
            $fields = ResponseParser::parse( $result['text'], [ 'post_title', 'post_content' ] );

            $total_tokens = $result['tokens_input'] + $result['tokens_output'];
            $cost         = $result['tokens_output'] * Settings::cost_per_token( $model );

            Logger::log( $post_id, 'post', 'improve_post', $total_tokens, $cost, $model, 'ok' );

            return [
                'ok'       => true,
                'improved' => $fields,
                'tokens'   => $total_tokens,
                'cost'     => $cost,
            ];

        } catch ( \Exception $e ) {
            Logger::log( $post_id, 'post', 'improve_post', 0, 0, Settings::model(), 'error', $e->getMessage() );
            return [ 'ok' => false, 'error' => $e->getMessage() ];
        }
    }

    /**
     * Aplica las mejoras generadas a un post.
     *
     * @param int   $post_id ID del post.
     * @param array $data    Datos mejorados (post_title, post_content, seo_title, etc.).
     * @return array Resultado.
     */
    public static function apply( int $post_id, array $data ): array {
        $post = get_post( $post_id );
        if ( ! $post || ! current_user_can( 'edit_post', $post_id ) ) {
            return [ 'ok' => false, 'error' => 'Sin permisos o post no encontrado.' ];
        }

        $update = [ 'ID' => $post_id ];

        if ( ! empty( $data['post_title'] ) ) {
            $update['post_title'] = sanitize_text_field( $data['post_title'] );
        }
        if ( ! empty( $data['post_content'] ) ) {
            $update['post_content'] = wp_kses_post( $data['post_content'] );
        }

        if ( count( $update ) > 1 ) {
            $result = wp_update_post( $update, true );
            if ( is_wp_error( $result ) ) {
                return [ 'ok' => false, 'error' => $result->get_error_message() ];
            }
        }

        // Escribir campos SEO
        $seo_fields = array_intersect_key( $data, array_flip( Settings::fields_for( 'post' ) ) );

        if ( ! empty( $seo_fields ) ) {
            $seo_plugin   = PluginDetector::detect_active();
            $write_target = ( 'none' !== $seo_plugin ) ? $seo_plugin : 'native';
            Writer::write( $post_id, $seo_fields, $write_target, true );
        }

        // Tags
        if ( Settings::generates_field( 'tags' ) && ! empty( $data['tags'] ) && is_array( $data['tags'] ) ) {
            wp_set_post_tags( $post_id, array_map( 'sanitize_text_field', $data['tags'] ), true );
        }

        return [ 'ok' => true ];
    }
}
