<?php
/**
 * Proveedor de IA: Google Gemini.
 * Usa structured outputs (responseSchema) para forzar JSON válido.
 *
 * Integración directa con la API, a propósito. Plugin Check sugiere el cliente
 * de IA que WordPress 7.0 introdujo (`wp_ai_client_prompt()`), pero es una
 * recomendación, no un requisito, y aquí no encaja todavía: este plugin exige
 * WordPress 6.2, gestiona la API key del usuario cifrada, su propia caché de
 * respuestas, su catálogo de modelos y el cálculo de costo por token. Migrar
 * supondría rehacer esa capa entera y subir el mínimo a 7.0. Queda anotado en
 * TASKS.md como candidato a una versión futura.
 *
 * El servicio externo está declarado en readme.txt («Third-Party Services») y
 * en Configuración, como exige WordPress.org.
 *
 * @package HUP\CamposSeoIA\AI
 */

namespace HUP\CamposSeoIA\AI;

defined( 'ABSPATH' ) || exit;

class GeminiProvider extends AbstractProvider {

    private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';

    /** Techo mínimo de salida para modelos que razonan antes de responder. */
    private const MIN_TOKENS_THINKING = 8192;

    public function get_slug(): string {
        return 'google';
    }

    /**
     * Gemini 3 en adelante.
     *
     * Cambia el contrato respecto a la línea 2.5: se recomienda no enviar
     * temperature/topP/topK, y el razonamiento se controla con
     * thinkingConfig.thinkingLevel (minimal|low|medium|high, default medium).
     */
    private static function is_gemini_3( string $model ): bool {
        return 1 === preg_match( '/^gemini-3/i', $model );
    }

    public function get_display_name(): string {
        return 'Google (Gemini)';
    }

    /**
     * {@inheritdoc}
     */
    public function generate( string $prompt, string $model, string $api_key, array $schema = [], int $max_tokens = 2048, bool $use_cache = true ): array {
        $is_g3 = self::is_gemini_3( $model );

        // En Gemini 3 el razonamiento consume presupuesto de salida: con un techo
        // bajo la respuesta sale truncada (finishReason MAX_TOKENS). Se le da
        // margen. maxOutputTokens es un tope, no un consumo: subirlo no encarece
        // nada por sí solo. Se ajusta antes de la clave de caché para que casen.
        if ( $is_g3 ) {
            $max_tokens = max( $max_tokens, self::MIN_TOKENS_THINKING );
        }

        $cache_key = 'hcsai_ai_' . md5( $prompt . $model . $max_tokens . wp_json_encode( $schema ) );
        if ( $use_cache ) {
            $cached = get_transient( $cache_key );
            if ( false !== $cached ) {
                return $cached;
            }
        }

        $url = self::API_BASE . $model . ':generateContent';

        $generation_config = [ 'maxOutputTokens' => $max_tokens ];

        if ( $is_g3 ) {
            // Gemini 3 recomienda dejar temperature en su valor por defecto (1.0):
            // cambiarlo puede provocar bucles o degradar la respuesta, así que no
            // se envía. thinkingLevel al mínimo útil porque aquí solo se piden
            // campos SEO, no razonamiento complejo.
            //
            // Ojo con la forma: la Interactions API lo recibe como
            // generation_config.thinking_level, pero generateContent —que es la que
            // usa este plugin— lo anida en generationConfig.thinkingConfig.thinkingLevel.
            $generation_config['thinkingConfig'] = [ 'thinkingLevel' => 'low' ];
        } else {
            $generation_config['temperature'] = 0.7;
        }

        $body = [
            'system_instruction' => [
                'parts' => [
                    [ 'text' => 'Eres un asistente de SEO. Responde SIEMPRE con JSON válido, sin markdown ni explicaciones.' ],
                ],
            ],
            'contents' => [
                [
                    'parts' => [
                        [ 'text' => $prompt ],
                    ],
                ],
            ],
            'generationConfig' => $generation_config,
        ];

        // Structured outputs: forzar JSON con schema
        if ( ! empty( $schema ) ) {
            $body['generationConfig']['responseMimeType'] = 'application/json';
            $body['generationConfig']['responseSchema']   = $schema;
        }

        $headers = [
            'Content-Type'   => 'application/json',
            'x-goog-api-key' => $api_key,
        ];

        $data = $this->http_post( $url, $headers, $body );

        // Extraer respuesta de Gemini
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $usage = $data['usageMetadata'] ?? [];

        // Verificar finishReason
        $finish = $data['candidates'][0]['finishReason'] ?? '';

        if ( empty( $text ) ) {
            if ( 'SAFETY' === $finish ) {
                throw new \RuntimeException( 'Gemini bloqueó la respuesta por filtros de seguridad.' );
            }
            throw new \RuntimeException( 'Gemini no devolvió contenido en la respuesta.' );
        }

        if ( 'MAX_TOKENS' === $finish ) {
            throw new \RuntimeException( 'La respuesta de Gemini fue cortada por exceder el límite de tokens. Intenta con un contenido más corto.' );
        }

        $result = [
            'text'          => trim( $text ),
            'tokens_input'  => (int) ( $usage['promptTokenCount'] ?? 0 ),
            'tokens_output' => (int) ( $usage['candidatesTokenCount'] ?? $usage['totalTokenCount'] ?? 0 ),
        ];

        set_transient( $cache_key, $result, 12 * HOUR_IN_SECONDS );

        return $result;
    }

    /**
     * Descripción de cada campo para el responseSchema de Gemini.
     *
     * @return array<string, array>
     */
    private static function field_schemas(): array {
        $texto = static function ( string $desc ): array {
            return [ 'type' => 'STRING', 'description' => $desc ];
        };

        return [
            'seo_title'           => $texto( 'Titulo SEO optimizado, maximo 60 caracteres' ),
            'meta_description'    => $texto( 'Meta descripcion, 120-160 caracteres' ),
            'focus_keyword'       => $texto( 'Palabra clave principal' ),
            'og_title'            => $texto( 'Titulo Open Graph' ),
            'og_description'      => $texto( 'Descripcion Open Graph' ),
            'alt_text'            => $texto( 'Texto alternativo para imagen, maximo 120 caracteres' ),
            'product_description' => $texto( 'Descripcion completa del producto, minimo 150 palabras' ),
            'short_description'   => $texto( 'Descripcion corta del producto, 1-2 oraciones' ),
            'post_title'          => $texto( 'Titulo del post' ),
            'post_content'        => $texto( 'Contenido completo del post' ),
            'tags'                => [
                'type'        => 'ARRAY',
                'items'       => [ 'type' => 'STRING' ],
                'description' => 'Etiquetas SEO relevantes',
            ],
        ];
    }

    /**
     * Construye el responseSchema de Gemini para una lista de campos.
     *
     * Recibe la lista en vez de deducirla de un "tipo": qué campos se piden lo
     * decide Settings::generated_fields(), que es filtrable. Antes había una rama
     * por tipo de contenido, que había que mantener sincronizada a mano con los
     * prompts.
     *
     * @param array<string> $fields Campos a pedir.
     * @return array Schema para structured outputs.
     */
    public static function build_schema( array $fields ): array {
        $catalogo   = self::field_schemas();
        $properties = [];

        foreach ( $fields as $field ) {
            if ( isset( $catalogo[ $field ] ) ) {
                $properties[ $field ] = $catalogo[ $field ];
            }
        }

        if ( empty( $properties ) ) {
            return [];
        }

        // Solo se marcan como obligatorios los campos que realmente se piden:
        // exigir uno que no está en properties hace que Gemini rechace el schema.
        $required = array_values( array_intersect(
            [ 'seo_title', 'meta_description', 'focus_keyword', 'post_title', 'post_content' ],
            array_keys( $properties )
        ) );

        return [
            'type'       => 'OBJECT',
            'properties' => $properties,
            'required'   => $required,
        ];
    }
}
