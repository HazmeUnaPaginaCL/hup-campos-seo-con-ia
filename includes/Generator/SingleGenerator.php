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
use HUP\CamposSeoIA\AI\ResponseSchema;
use HUP\CamposSeoIA\SEO\Writer;
use HUP\CamposSeoIA\SEO\Reader;
use HUP\CamposSeoIA\SEO\Scorer;
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
     * @param bool   $use_cache     Usar caché de IA (respuesta de 12 h para el mismo prompt).
     *
     * @return array{ok: bool, score: int, tokens: int, cost: float, error: string, skipped?: bool, message?: string}
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

        // Campos que esta instalación genera. Es la misma lista que usa el
        // prompt, así que no se pide nada que luego no se vaya a guardar.
        $fields_wanted = Settings::fields_for( $post->post_type );

        if ( empty( $fields_wanted ) ) {
            return [ 'ok' => false, 'error' => 'Este tipo de contenido no tiene campos que completar.', 'score' => 0, 'tokens' => 0, 'cost' => 0 ];
        }

        // Sin sobrescribir, a la IA solo se le piden los campos vacíos: el Writer
        // descartaría el resto, y pagar la descripción larga de un producto para
        // luego tirarla era el grueso del costo. Si no falta nada, ni se llama.
        if ( ! $overwrite ) {
            $fields_wanted = self::missing_fields( $post_id, $fields_wanted, $write_target );

            if ( empty( $fields_wanted ) ) {
                return [
                    'ok'            => true,
                    'skipped'       => true,
                    'message'       => 'No había campos vacíos: no se usó la IA. Para reemplazar lo existente, activa «Sobrescribir» en Configuración.',
                    'score'         => Scorer::calculate( $post_id, $write_target ),
                    'tokens'        => 0,
                    'cost'          => 0,
                    'error'         => '',
                    'has_thumbnail' => (bool) get_post_thumbnail_id( $post_id ),
                    'fields_saved'  => [],
                ];
            }
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

            // Structured outputs: se construye desde la misma lista, y cada
            // proveedor lo adapta a su formato.
            $schema = ResponseSchema::for_fields( $fields_wanted );

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
            $cost         = Settings::cost_for( $model, $result['tokens_input'], $result['tokens_output'] );
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
     * De una lista de campos, los que están vacíos en el contenido.
     *
     * Lee con el mismo Reader que el modal, así que "vacío" significa lo mismo
     * aquí que lo que ve el usuario. El alt text sin imagen destacada no cuenta
     * como pendiente: no hay dónde escribirlo.
     *
     * @param array<string> $fields Campos candidatos.
     * @return array<string>
     */
    private static function missing_fields( int $post_id, array $fields, string $write_target ): array {
        $actual = Reader::read( $post_id, $write_target );

        return array_values( array_filter( $fields, static function ( string $field ) use ( $actual ): bool {
            if ( 'alt_text' === $field && empty( $actual['has_thumbnail'] ) ) {
                return false;
            }

            $valor = $actual[ $field ] ?? '';

            return is_array( $valor ) ? empty( $valor ) : '' === trim( (string) $valor );
        } ) );
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
