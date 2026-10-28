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

    /**
     * Techo mínimo para Gemini 4. Su guía de migración (ai.google.dev, «What's
     * new in Gemini 4 Argon») pide al menos 32.768: el tope cubre razonamiento
     * y respuesta juntos, y con menos el JSON sale cortado.
     */
    private const MIN_TOKENS_GEMINI_4 = 32768;

    public function get_slug(): string {
        return 'google';
    }

    /**
     * Gemini 3 en adelante (3.x, 4-argon…).
     *
     * Cambia el contrato respecto a la línea 2.5: se recomienda no enviar
     * temperature/topP/topK, y el razonamiento se controla con
     * thinkingConfig.thinkingLevel. Antes la expresión solo reconocía
     * `gemini-3`, así que gemini-4-argon caía en la rama antigua: sin margen
     * para razonar y con temperature.
     */
    private static function is_gemini_3( string $model ): bool {
        return 1 === preg_match( '/^gemini-([3-9]|\d{2,})/i', $model );
    }

    /** Gemini 4 en adelante. */
    private static function is_gemini_4( string $model ): bool {
        return 1 === preg_match( '/^gemini-([4-9]|\d{2,})/i', $model );
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
            $max_tokens = max( $max_tokens, self::is_gemini_4( $model ) ? self::MIN_TOKENS_GEMINI_4 : self::MIN_TOKENS_THINKING );
        }

        $cache_key = 'hcsai_ai_' . md5( $prompt . $model . $max_tokens . wp_json_encode( $schema ) );
        if ( $use_cache ) {
            $cached = get_transient( $cache_key );
            if ( false !== $cached ) {
                return $cached;
            }
        }

        $url = self::API_BASE . $model . ':generateContent';

        // Sin temperature en ningún modelo: Google la marcó como obsoleta (junto
        // con topP y topK) el 2026-07-21, y Gemini 4 directamente la ignora.
        $generation_config = [ 'maxOutputTokens' => $max_tokens ];

        if ( $is_g3 ) {
            // thinkingLevel al mínimo útil porque aquí solo se piden campos SEO,
            // no razonamiento complejo.
            //
            // 'low' y no 'minimal' porque es el mínimo que aceptan todos: 3.8
            // Flash y 4 Argon devuelven error con 'minimal'.
            //
            // Ojo con la forma: la Interactions API lo recibe como
            // generation_config.thinking_level, pero generateContent —que es la que
            // usa este plugin— lo anida en generationConfig.thinkingConfig.thinkingLevel.
            $generation_config['thinkingConfig'] = [ 'thinkingLevel' => 'low' ];
        }

        $body = [
            // Desde el 2026-10-26 los proyectos de pago nuevos guardan cada
            // petición en los logs de AI Studio por defecto (prompt y respuesta,
            // hasta 55 días). El plugin declara que el contenido del sitio no se
            // queda en ningún otro sitio, así que se desactiva por petición. Va
            // en la raíz del cuerpo, no en generationConfig.
            'store' => false,
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

        // Extraer respuesta de Gemini. Se unen todas las partes de texto, no solo
        // la primera: la respuesta puede llegar repartida, y las partes de
        // razonamiento (thought) no forman parte del JSON.
        $text = '';
        foreach ( $data['candidates'][0]['content']['parts'] ?? [] as $part ) {
            if ( empty( $part['thought'] ) && isset( $part['text'] ) ) {
                $text .= $part['text'];
            }
        }
        $usage = $data['usageMetadata'] ?? [];

        // Verificar finishReason
        $finish = $data['candidates'][0]['finishReason'] ?? '';

        // Antes que el caso vacío: si el razonamiento agota el tope, la respuesta
        // llega sin texto, y "no devolvió contenido" ocultaba la causa real.
        if ( 'MAX_TOKENS' === $finish ) {
            throw new \RuntimeException( 'La respuesta de Gemini fue cortada por exceder el límite de tokens. Intenta con un contenido más corto.' );
        }

        if ( '' === trim( $text ) ) {
            if ( 'SAFETY' === $finish ) {
                throw new \RuntimeException( 'Gemini bloqueó la respuesta por filtros de seguridad.' );
            }
            throw new \RuntimeException( 'Gemini no devolvió contenido en la respuesta.' );
        }

        // El razonamiento (thoughtsTokenCount) se cobra a precio de salida pero
        // no va incluido en candidatesTokenCount: sin sumarlo, el costo
        // registrado salía por debajo del real con todos los Gemini 3.
        $result = [
            'text'          => trim( $text ),
            'tokens_input'  => (int) ( $usage['promptTokenCount'] ?? 0 ),
            'tokens_output' => (int) ( $usage['candidatesTokenCount'] ?? 0 ) + (int) ( $usage['thoughtsTokenCount'] ?? 0 ),
        ];

        // Sin caché pedida, tampoco se guarda: cada generación individual dejaba
        // en wp_options una respuesta de 12 horas que nadie volvía a leer.
        if ( $use_cache ) {
            set_transient( $cache_key, $result, 12 * HOUR_IN_SECONDS );
        }

        return $result;
    }

    /**
     * Construye el responseSchema de Gemini para una lista de campos.
     *
     * Se conserva porque es pública y otros plugins pueden estar llamándola; la
     * lógica vive en ResponseSchema, que comparten los dos proveedores.
     *
     * @param array<string> $fields Campos a pedir.
     * @return array Schema para structured outputs.
     */
    public static function build_schema( array $fields ): array {
        return ResponseSchema::for_fields( $fields );
    }
}
