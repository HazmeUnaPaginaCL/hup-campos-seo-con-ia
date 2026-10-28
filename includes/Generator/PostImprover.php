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
use HUP\CamposSeoIA\AI\ResponseSchema;
use HUP\CamposSeoIA\SEO\Writer;
use HUP\CamposSeoIA\SEO\PluginDetector;

defined( 'ABSPATH' ) || exit;

class PostImprover {

    /**
     * Genera mejoras para un post existente.
     *
     * Lo que se devuelve es exactamente lo que se aplicará: solo los campos de
     * las opciones marcadas, con el contenido ya convertido a HTML. Antes el
     * schema pedía siempre título y contenido, así que desmarcar «Mejorar
     * contenido» no impedía que se reescribiera el artículo al aplicar.
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

        $fields_wanted = self::fields_for_options( $options );

        if ( empty( $fields_wanted ) ) {
            return [ 'ok' => false, 'error' => 'Marca al menos una opción de mejora.' ];
        }

        // La reescritura sustituye el artículo entero: si la IA solo viera el
        // principio, el resto se perdería al aplicar. Mejor no hacerla.
        $reescribe = in_array( 'post_content', $fields_wanted, true );
        $largo     = mb_strlen( PromptBuilder::plain_text( $post->post_content ) );

        if ( $reescribe && $largo > PromptBuilder::MAX_IMPROVE_CHARS ) {
            return [
                'ok'    => false,
                'error' => sprintf(
                    'Este post tiene %1$s caracteres de texto y para reescribirlo entero el máximo es %2$s. Desmarca «Mejorar contenido» y «Agregar CTA» para optimizar solo el SEO y las etiquetas.',
                    number_format_i18n( $largo ),
                    number_format_i18n( PromptBuilder::MAX_IMPROVE_CHARS )
                ),
            ];
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

            // Structured outputs: solo los campos de las opciones marcadas. El
            // schema manda sobre el JSON de ejemplo del prompt.
            $schema = ResponseSchema::for_fields( $fields_wanted );

            // Llamar a la IA (8192 tokens para posts largos con HTML)
            // Sin caché: mejorar un post debe producir una respuesta fresca cada vez
            $result = $provider->generate( $prompt, $model, $api_key, $schema, 8192, false );

            // Parsear respuesta. Lo que venga de más (p. ej. si el proveedor tuvo
            // que reintentar sin schema) se descarta.
            $fields = ResponseParser::parse( $result['text'], $fields_wanted );
            $fields = array_intersect_key( $fields, array_flip( $fields_wanted ) );

            // Se convierte aquí y no al aplicar, para que la vista previa muestre
            // lo mismo que se va a guardar.
            if ( ! empty( $fields['post_content'] ) ) {
                $fields['post_content'] = wp_kses_post( PostCreator::text_to_html( $fields['post_content'] ) );
            }

            $total_tokens = $result['tokens_input'] + $result['tokens_output'];
            $cost         = Settings::cost_for( $model, $result['tokens_input'], $result['tokens_output'] );

            Logger::log( $post_id, 'post', 'improve_post', $total_tokens, $cost, $model, 'ok' );

            return [
                'ok'        => true,
                'improved'  => $fields,
                'tokens'    => $total_tokens,
                'cost'      => $cost,
                // El texto se reescribe desde su versión plana: imágenes, enlaces
                // y bloques no sobreviven. Se avisa antes de aplicar.
                'se_pierde' => ! empty( $fields['post_content'] ) ? self::rich_elements( $post->post_content ) : [],
                'revision'  => wp_revisions_enabled( $post ),
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
            // Ya llega como HTML desde improve(); text_to_html() no lo toca en
            // ese caso, y cubre a quien llame a apply() con el texto de la IA.
            $update['post_content'] = wp_kses_post( PostCreator::text_to_html( $data['post_content'] ) );
        }

        if ( count( $update ) > 1 ) {
            // wp_update_post() espera los datos con barras, como llegan de un
            // formulario: sin wp_slash() se comía las barras invertidas.
            $result = wp_update_post( wp_slash( $update ), true );
            if ( is_wp_error( $result ) ) {
                return [ 'ok' => false, 'error' => $result->get_error_message() ];
            }
        }

        // Campos SEO, extracto, alt text y etiquetas: los escribe el Writer.
        $seo_fields = array_intersect_key( $data, array_flip( Settings::fields_for( 'post' ) ) );

        if ( ! empty( $seo_fields ) ) {
            $seo_plugin   = PluginDetector::detect_active();
            $write_target = ( 'none' !== $seo_plugin ) ? $seo_plugin : 'native';

            try {
                Writer::write( $post_id, $seo_fields, $write_target, true );
            } catch ( \RuntimeException $e ) {
                return [ 'ok' => false, 'error' => $e->getMessage() ];
            }
        }

        return [ 'ok' => true ];
    }

    /**
     * Campos que se piden según las opciones marcadas.
     *
     * El CTA va al final del contenido, así que también implica reescribirlo.
     *
     * @return array<string>
     */
    private static function fields_for_options( array $options ): array {
        $registro = Settings::fields_for( 'post' );
        $fields   = [];

        if ( ! empty( $options['improve_content'] ) || ! empty( $options['add_cta'] ) ) {
            $fields[] = 'post_title';
            $fields[] = 'post_content';
        }

        if ( ! empty( $options['improve_seo'] ) ) {
            $fields = array_merge( $fields, array_diff( $registro, [ 'tags' ] ) );
        }

        if ( ! empty( $options['improve_tags'] ) && in_array( 'tags', $registro, true ) ) {
            $fields[] = 'tags';
        }

        return array_values( array_unique( $fields ) );
    }

    /**
     * Elementos del contenido original que la versión reescrita no conserva.
     *
     * @return array<string> Descripciones legibles, vacío si no hay ninguno.
     */
    private static function rich_elements( string $html ): array {
        $checks = [
            'imágenes'                     => '/<img\b/i',
            'enlaces'                      => '/<a\s[^>]*href/i',
            'vídeos o contenido incrustado' => '/<(?:iframe|video|embed|object)\b|<!--\s*wp:embed/i',
            'tablas'                       => '/<table\b/i',
        ];

        $hay = [];
        foreach ( $checks as $label => $pattern ) {
            if ( preg_match( $pattern, $html ) ) {
                $hay[] = $label;
            }
        }

        if ( preg_match( '/' . get_shortcode_regex() . '/', $html ) ) {
            $hay[] = 'shortcodes';
        }

        return $hay;
    }
}
