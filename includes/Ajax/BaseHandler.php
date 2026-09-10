<?php
/**
 * Handler base para AJAX.
 * Provee validación de nonce, permisos y rate limiting.
 *
 * @package HUP\CamposSeoIA\Ajax
 */

namespace HUP\CamposSeoIA\Ajax;

defined( 'ABSPATH' ) || exit;

abstract class BaseHandler {

    /** Prefijo para las acciones AJAX */
    protected const ACTION_PREFIX = 'hcsai_';

    /** Nonce action */
    protected const NONCE_ACTION = 'hcsai_nonce';

    /**
     * Constructor: registra las acciones AJAX.
     */
    public function __construct() {
        $this->register_actions();
    }

    /**
     * Cada handler debe implementar el registro de sus acciones.
     */
    abstract protected function register_actions(): void;

    /**
     * Verifica la petición AJAX: nonce y permisos.
     *
     * @param string $capability Permiso requerido (default: manage_options).
     * @throws \RuntimeException Si la verificación falla.
     */
    protected function verify_request( string $capability = 'manage_options' ): void {
        if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Nonce inválido.' ], 403 );
        }

        if ( ! current_user_can( $capability ) ) {
            wp_send_json_error( [ 'message' => 'Sin permisos.' ], 403 );
        }
    }

    /**
     * Rate limiting simple usando transients.
     * Permite máximo $max_requests en $window_seconds.
     *
     * @param string $action          Identificador de la acción.
     * @param int    $max_requests    Máximo de peticiones permitidas.
     * @param int    $window_seconds  Ventana de tiempo en segundos.
     * @return bool true si se permite, false si se excede.
     */
    protected function check_rate_limit( string $action, int $max_requests = 30, int $window_seconds = 60 ): bool {
        $user_id = get_current_user_id();
        $key     = 'hcsai_rate_' . $action . '_' . $user_id;
        $count   = (int) get_transient( $key );

        if ( $count >= $max_requests ) {
            return false;
        }

        set_transient( $key, $count + 1, $window_seconds );
        return true;
    }

    /**
     * Helper: registra una acción AJAX con prefijo.
     *
     * @param string $action  Nombre de la acción (sin prefijo).
     * @param string $method  Método del handler.
     */
    protected function add_ajax( string $action, string $method ): void {
        add_action( 'wp_ajax_' . self::ACTION_PREFIX . $action, [ $this, $method ] );
    }

    /**
     * Obtiene un parámetro POST sanitizado.
     */
    protected function input( string $key, string $default = '' ): string {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- el nonce se verifica en verify_request(), que todo handler llama antes de leer entradas.
        return sanitize_text_field( wp_unslash( $_POST[ $key ] ?? $default ) );
    }

    /**
     * Obtiene un parámetro POST como entero.
     */
    protected function input_int( string $key, int $default = 0 ): int {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- el nonce se verifica en verify_request(), que todo handler llama antes de leer entradas.
        return absint( $_POST[ $key ] ?? $default );
    }

    /**
     * Obtiene un parámetro POST como array.
     */
    protected function input_array( string $key ): array {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- el nonce se verifica en verify_request(), que todo handler llama antes de leer entradas.
        if ( ! isset( $_POST[ $key ] ) || ! is_array( $_POST[ $key ] ) ) {
            return [];
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- el nonce se verifica en verify_request(), que todo handler llama antes de leer entradas.
        return array_map( 'sanitize_text_field', wp_unslash( $_POST[ $key ] ) );
    }
}
