<?php
/**
 * Proveedor de IA: OpenAI (ChatGPT).
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

class OpenAIProvider extends AbstractProvider {

    private const API_URL = 'https://api.openai.com/v1/chat/completions';

    public function get_slug(): string {
        return 'openai';
    }

    /**
     * Modelos de razonamiento: GPT-5 en adelante y la familia o*.
     *
     * Cambian el contrato de la API respecto a GPT-4: usan
     * max_completion_tokens en vez de max_tokens, y rechazan cualquier
     * temperature que no sea la de por defecto. Enviar los parámetros
     * antiguos devuelve 400.
     */
    private static function is_reasoning_model( string $model ): bool {
        return 1 === preg_match( '/^(gpt-5|o\d)/i', $model );
    }

    public function get_display_name(): string {
        return 'OpenAI (ChatGPT)';
    }

    /**
     * {@inheritdoc}
     */
    public function generate( string $prompt, string $model, string $api_key, array $schema = [], int $max_tokens = 2048, bool $use_cache = true ): array {
        $cache_key = 'hcsai_ai_' . md5( $prompt . $model . $max_tokens );
        if ( $use_cache ) {
            $cached = get_transient( $cache_key );
            if ( false !== $cached ) {
                return $cached;
            }
        }

        $messages = [
            [
                'role'    => 'system',
                'content' => 'Eres un asistente de SEO. Responde SIEMPRE con JSON válido, sin markdown ni explicaciones.',
            ],
            [
                'role'    => 'user',
                'content' => $prompt,
            ],
        ];

        $body = [
            'model'    => $model,
            'messages' => $messages,
        ];

        if ( self::is_reasoning_model( $model ) ) {
            // GPT-5 en adelante: 'max_tokens' se rechaza con un 400 ("is not
            // supported with this model") y 'temperature' solo admite su valor
            // por defecto, así que no se envía.
            $body['max_completion_tokens'] = $max_tokens;
        } else {
            $body['max_tokens']  = $max_tokens;
            $body['temperature'] = 0.7;
        }

        // Forzar JSON estructurado siempre (evita saltos de línea y HTML roto en valores)
        $body['response_format'] = [ 'type' => 'json_object' ];

        $headers = [
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $api_key,
        ];

        try {
            $data = $this->http_post( self::API_URL, $headers, $body );
        } catch ( \RuntimeException $e ) {
            // Si el modelo no soporta response_format, reintentar sin él
            if ( str_contains( $e->getMessage(), 'response_format' ) || str_contains( $e->getMessage(), 'does not support' ) ) {
                unset( $body['response_format'] );
                $data = $this->http_post( self::API_URL, $headers, $body );
            } else {
                throw $e;
            }
        }

        // Extraer respuesta
        $text  = $data['choices'][0]['message']['content'] ?? '';
        $usage = $data['usage'] ?? [];

        // Nota: aquí había un error_log() con la respuesta cruda. Se quitó porque
        // (a) disparaba en TODA llamada correcta, no solo al fallar —500 caracteres
        // por generación, o sea cientos de KB en debug.log tras un lote—, (b) volcaba
        // contenido del sitio a un fichero que en servidores mal configurados es
        // accesible por web, y (c) el diagnóstico ya existe por mejor vía: cuando algo
        // falla, la excepción lleva la respuesta incrustada y Logger la guarda en la
        // tabla del plugin, que el usuario sí puede consultar desde el Dashboard.

        if ( empty( $text ) ) {
            // Incluir respuesta completa en el error para diagnóstico
            $full = wp_json_encode( $data );
            throw new \RuntimeException( 'OpenAI no devolvió contenido. Respuesta: ' . esc_html( substr( $full, 0, 400 ) ) );
        }

        $result = [
            'text'          => trim( $text ),
            'tokens_input'  => (int) ( $usage['prompt_tokens'] ?? 0 ),
            'tokens_output' => (int) ( $usage['completion_tokens'] ?? 0 ),
        ];

        set_transient( $cache_key, $result, 12 * HOUR_IN_SECONDS );

        return $result;
    }
}
