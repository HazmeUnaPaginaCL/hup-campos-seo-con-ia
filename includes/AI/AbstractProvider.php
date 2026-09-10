<?php
/**
 * Clase abstracta base para proveedores de IA.
 * Contiene lógica compartida: peticiones HTTP, manejo de errores, timeout.
 *
 * @package HUP\CamposSeoIA\AI
 */

namespace HUP\CamposSeoIA\AI;

defined( 'ABSPATH' ) || exit;

abstract class AbstractProvider implements ProviderInterface {

    /** Timeout para peticiones HTTP en segundos */
    protected const TIMEOUT = 60;

    /**
     * Realiza una petición POST con wp_remote_post.
     *
     * @param string $url     URL del endpoint.
     * @param array  $headers Headers HTTP.
     * @param array  $body    Body de la petición.
     *
     * @return array Respuesta decodificada.
     * @throws \RuntimeException Si hay error HTTP.
     */
    protected function http_post( string $url, array $headers, array $body ): array {
        $response = wp_remote_post( $url, [
            'timeout' => static::TIMEOUT,
            'headers' => $headers,
            'body'    => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $response ) ) {
            $err_code = $response->get_error_code();
            if ( in_array( $err_code, [ 'http_request_failed', 'curle_operation_timeouted' ], true )
                || false !== stripos( $response->get_error_message(), 'timed out' ) ) {
                throw new \RuntimeException(
                    sprintf( 'Tiempo de espera agotado (timeout %ds). Intenta de nuevo o reduce el contenido.', (int) static::TIMEOUT )
                );
            }
            throw new \RuntimeException(
                sprintf( 'Error de conexión: %s', esc_html( $response->get_error_message() ) )
            );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $raw  = wp_remote_retrieve_body( $response );
        $data = json_decode( $raw, true );

        if ( $code < 200 || $code >= 300 ) {
            $err     = is_array( $data ) ? $data : [];
            $api_msg = $err['error']['message'] ?? $err['error']['status'] ?? $err['message'] ?? '';

            switch ( $code ) {
                case 401:
                    throw new \RuntimeException( 'API key inválida o sin permisos. Verifica la clave en Configuración.' );
                case 403:
                    throw new \RuntimeException( 'Acceso denegado. Tu plan puede no tener acceso a este modelo.' );
                case 429:
                    throw new \RuntimeException( 'Límite de solicitudes alcanzado (rate limit). Espera unos segundos e intenta de nuevo.' );
                case 500:
                case 502:
                case 503:
                    throw new \RuntimeException( 'El servidor de la API no está disponible temporalmente. Intenta de nuevo en unos minutos.' );
                default:
                    throw new \RuntimeException(
                        $api_msg
                            ? sprintf( 'Error API (%d): %s', (int) $code, esc_html( $api_msg ) )
                            : sprintf( 'Error API (%d).', (int) $code )
                    );
            }
        }

        if ( null === $data ) {
            throw new \RuntimeException( 'La API devolvió una respuesta no JSON.' );
        }

        return $data;
    }

    /**
     * Prueba genérica de conexión: envía un prompt sencillo.
     */
    public function test_connection( string $model, string $api_key ): array {
        try {
            $result = $this->generate(
                'Responde solo "ok" en JSON: {"status":"ok"}',
                $model,
                $api_key,
                [],
                50,
                false
            );

            return [
                'success' => true,
                'message' => sprintf(
                    'Conexión exitosa con %s. Tokens: %d',
                    $this->get_display_name(),
                    $result['tokens_input'] + $result['tokens_output']
                ),
            ];
        } catch ( \RuntimeException $e ) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }
}
