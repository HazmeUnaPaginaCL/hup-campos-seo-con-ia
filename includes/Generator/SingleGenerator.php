<?php
/**
 * Generador SEO individual.
 * Genera campos SEO para un post/producto usando IA.
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

class SingleGenerator {

    /**
     * Genera campos SEO para un post/producto.
     *
     * @param int    $post_id       ID del post.
     * @param string $write_target  Dónde escribir: 'native', 'rankmath', 'yoast', 'aioseo'.
     * @param bool   $overwrite     Sobreescribir campos existentes.
     * @param array  $selected_fields Campos a generar (vacío = todos).
     * @param bool   $use_cache     Usar caché de IA. Individual/regenerar: false; batch: true.
     *
     * @return array{ok: bool, score: int, tokens: int, cost: float, error: string}
     */
    public static function generate( int $post_id, string $write_target = '', bool $overwrite = true, array $selected_fields = [], bool $use_cache = true ): array {
        $post = get_post( $post_id );

        if ( ! $post ) {
            return [ 'ok' => false, 'error' => 'Post no encontrado.', 'score' => 0, 'tokens' => 0, 'cost' => 0 ];
        }

        // Se comprueba ANTES de llamar a la IA: si el resultado no se va a poder
        // guardar, no tiene sentido pagar la generación.
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return [
                'ok'     => false,
                'error'  => 'Sin permisos para editar este contenido.',
                'score'  => 0,
                'tokens' => 0,
                'cost'   => 0,
            ];
        }

        // Determinar tipo
        $type = self::resolve_type( $post->post_type );

        // Determinar write target
        if ( empty( $write_target ) ) {
            $active_plugin = PluginDetector::detect_active();
            $write_target  = ( 'none' !== $active_plugin ) ? $active_plugin : 'native';
        }

        try {
            $provider = ProviderFactory::create_active();
            $model    = Settings::model();
            $api_key  = Settings::api_key( Settings::provider() );

            if ( empty( $api_key ) ) {
                return [ 'ok' => false, 'error' => Settings::api_key_error( Settings::provider() ), 'score' => 0, 'tokens' => 0, 'cost' => 0 ];
            }

            // Construir prompt
            $prompt = PromptBuilder::build_seo( $post, $type );

            // Campos que esta instalación genera. Es la misma lista que usa el
            // prompt, así que no se pide nada que luego no se vaya a guardar.
            $fields_wanted = Settings::fields_for( $post->post_type );

            // Structured outputs de Gemini: se construye desde la misma lista.
            $schema = ( $provider instanceof GeminiProvider )
                ? GeminiProvider::build_schema( $fields_wanted )
                : [];

            // max_tokens mayor para productos: product_description es largo.
            $max_tokens = ( 'product' === $type ) ? 4096 : 2048;

            // Llamar a la IA
            $result = $provider->generate( $prompt, $model, $api_key, $schema, $max_tokens, $use_cache );

            $fields = ResponseParser::parse( $result['text'], $fields_wanted );

            // La IA puede devolver campos de más aunque no se le pidan: se
            // descartan aquí para que prompt, schema, score y escritura digan
            // todos lo mismo.
            $fields = array_intersect_key( $fields, array_flip( $fields_wanted ) );

            // Filtrar campos seleccionados
            if ( ! empty( $selected_fields ) ) {
                $fields = array_intersect_key( $fields, array_flip( $selected_fields ) );
            }

            // Info adicional para el Writer
            $has_thumbnail = (bool) get_post_thumbnail_id( $post_id );

            // Escribir campos SEO
            Writer::write( $post_id, $fields, $write_target, $overwrite );

            // Calcular costo
            $total_tokens = $result['tokens_input'] + $result['tokens_output'];
            $cost         = $result['tokens_output'] * Settings::cost_per_token( $model );
            $score        = (int) get_post_meta( $post_id, '_hcsai_score', true );

            // Registrar en log
            Logger::log( $post_id, $post->post_type, 'seo_generate', $total_tokens, $cost, $model, 'ok' );

            return [
                'ok'            => true,
                'score'         => $score,
                'tokens'        => $total_tokens,
                'cost'          => $cost,
                'error'         => '',
                'has_thumbnail' => $has_thumbnail,
                'fields_saved'  => array_keys( $fields ),
            ];

        } catch ( \Exception $e ) {
            Logger::log( $post_id, $post->post_type, 'seo_generate', 0, 0, Settings::model(), 'error', $e->getMessage() );

            return [
                'ok'     => false,
                'error'  => $e->getMessage(),
                'score'  => 0,
                'tokens' => 0,
                'cost'   => 0,
            ];
        }
    }

    /**
     * Resuelve el tipo de prompt basado en el post_type.
     */
    private static function resolve_type( string $post_type ): string {
        $map = [
            'product' => 'product',
            'post'    => 'post',
            'page'    => 'page',
        ];
        return $map[ $post_type ] ?? 'page';
    }
}
