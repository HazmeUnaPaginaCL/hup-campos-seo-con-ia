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

    /** Techo mínimo de salida para modelos que razonan antes de responder. */
    private const MIN_TOKENS_REASONING = 8192;

    public function get_slug(): string {
        return 'openai';
    }

    /**
     * Modelos de razonamiento: GPT-5 en adelante (5.x, 6.x…) y la familia o*.
     *
     * Cambian el contrato de la API respecto a GPT-4: usan
     * max_completion_tokens en vez de max_tokens, y rechazan cualquier
     * temperature que no sea la de por defecto. Enviar los parámetros
     * antiguos devuelve 400. Antes la expresión era `gpt-5`, y un gpt-6 caía
     * en la rama de GPT-4.
     */
    private static function is_reasoning_model( string $model ): bool {
        return 1 === preg_match( '/^(gpt-([5-9]|\d{2,})|o\d)/i', $model );
    }

    /**
     * Adapta el schema común (ResponseSchema) al formato estricto de OpenAI.
     *
     * El modo estricto exige que TODA propiedad figure en required y que no
     * haya propiedades adicionales. Los campos que el schema común deja como
     * opcionales se declaran entonces admitiendo null: la IA puede no
     * rellenarlos, igual que con Gemini, y ResponseParser descarta los null.
     *
     * @param array $schema Schema en el formato de ResponseSchema.
     * @return array JSON Schema estricto.
     */
    private static function to_json_schema( array $schema ): array {
        $required   = $schema['required'] ?? [];
        $properties = [];

        foreach ( $schema['properties'] ?? [] as $name => $property ) {
            $properties[ $name ] = self::to_json_property( $property, ! in_array( $name, $required, true ) );
        }

        return [
            'type'                 => 'object',
            'properties'           => $properties,
            'required'             => array_keys( $properties ),
            'additionalProperties' => false,
        ];
    }

    /**
     * Convierte una propiedad del schema común: tipos en minúsculas y, si es
     * opcional, admitiendo null.
     */
    private static function to_json_property( array $property, bool $nullable ): array {
        $type = strtolower( (string) ( $property['type'] ?? 'string' ) );
        $out  = [ 'type' => $nullable ? [ $type, 'null' ] : $type ];

        if ( isset( $property['description'] ) ) {
            $out['description'] = $property['description'];
        }

        if ( 'array' === $type && isset( $property['items'] ) ) {
            $out['items'] = self::to_json_property( $property['items'], false );
        }

        return $out;
    }

    public function get_display_name(): string {
        return 'OpenAI (ChatGPT)';
    }

    /**
     * {@inheritdoc}
     */
    public function generate( string $prompt, string $model, string $api_key, array $schema = [], int $max_tokens = 2048, bool $use_cache = true ): array {
        $is_reasoning = self::is_reasoning_model( $model );

        // El razonamiento consume el mismo presupuesto que la respuesta: con un
        // techo bajo, el modelo lo gasta pensando y devuelve el JSON cortado o
        // vacío (la prueba de conexión, con 50 tokens, fallaba siempre con
        // GPT-5). Es un tope, no un consumo: subirlo no encarece nada por sí
        // solo. Se ajusta antes de la clave de caché para que casen.
        if ( $is_reasoning ) {
            $max_tokens = max( $max_tokens, self::MIN_TOKENS_REASONING );
        }

        $cache_key = 'hcsai_ai_' . md5( $prompt . $model . $max_tokens . wp_json_encode( $schema ) );
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
            // Chat Completions ya no guarda por defecto, pero se deja explícito:
            // la Responses API sí guarda por defecto, y el día que se migre a
            // ella este valor tiene que viajar con la llamada.
            'store'    => false,
        ];

        if ( $is_reasoning ) {
            // GPT-5 en adelante: 'max_tokens' se rechaza con un 400 ("is not
            // supported with this model") y 'temperature' solo admite su valor
            // por defecto, así que no se envía.
            $body['max_completion_tokens'] = $max_tokens;

            // Sin esto razonan en 'medium', el nivel por defecto, que para
            // rellenar campos SEO solo añade latencia y costo. 'low' es el
            // mínimo que aceptan todos: GPT-6 Sol y Astra rechazan 'none' y
            // 'minimal'.
            $body['reasoning_effort'] = 'low';
        } else {
            $body['max_tokens']  = $max_tokens;
            $body['temperature'] = 0.7;
        }

        // Forzar JSON siempre (evita saltos de línea y HTML roto en valores).
        // Con schema se usan Structured Outputs, que garantizan la forma de la
        // respuesta; OpenAI desaconseja el modo json_object y ya no lo lista
        // entre las capacidades de GPT-6. Sin schema (prueba de conexión) basta
        // con json_object.
        $body['response_format'] = empty( $schema )
            ? [ 'type' => 'json_object' ]
            : [
                'type'        => 'json_schema',
                'json_schema' => [
                    'name'   => 'campos_seo',
                    'strict' => true,
                    'schema' => self::to_json_schema( $schema ),
                ],
            ];

        $headers = [
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $api_key,
        ];

        try {
            $data = $this->http_post( self::API_URL, $headers, $body );
        } catch ( \RuntimeException $e ) {
            // Si el modelo no soporta response_format, reintentar sin él
            if ( str_contains( $e->getMessage(), 'response_format' ) || str_contains( $e->getMessage(), 'json_schema' ) || str_contains( $e->getMessage(), 'does not support' ) ) {
                unset( $body['response_format'] );
                $data = $this->http_post( self::API_URL, $headers, $body );
            } else {
                throw $e;
            }
        }

        // Extraer respuesta
        $text   = $data['choices'][0]['message']['content'] ?? '';
        $usage  = $data['usage'] ?? [];
        $finish = $data['choices'][0]['finish_reason'] ?? '';

        // Respuesta cortada por el tope de tokens. Antes no se comprobaba: el
        // parser rescataba los campos que alcanzaban a llegar y se guardaban a
        // medias sin avisar. Gemini ya lanzaba este error; ahora los dos igual.
        if ( 'length' === $finish ) {
            throw new \RuntimeException( 'La respuesta de OpenAI fue cortada por exceder el límite de tokens. Intenta con un contenido más corto.' );
        }

        // Con Structured Outputs, una negativa del modelo llega en 'refusal' y
        // no en 'content'.
        if ( ! empty( $data['choices'][0]['message']['refusal'] ) ) {
            throw new \RuntimeException( 'OpenAI rechazó generar el contenido: ' . esc_html( $data['choices'][0]['message']['refusal'] ) );
        }

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

        // completion_tokens ya incluye los de razonamiento, que se cobran como salida.
        $result = [
            'text'          => trim( $text ),
            'tokens_input'  => (int) ( $usage['prompt_tokens'] ?? 0 ),
            'tokens_output' => (int) ( $usage['completion_tokens'] ?? 0 ),
        ];

        // Sin caché pedida, tampoco se guarda: cada generación individual dejaba
        // en wp_options una respuesta de 12 horas que nadie volvía a leer.
        if ( $use_cache ) {
            set_transient( $cache_key, $result, 12 * HOUR_IN_SECONDS );
        }

        return $result;
    }
}
