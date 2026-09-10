<?php
/**
 * AJAX: Acciones de configuración (test API, reset prompts).
 *
 * @package HUP\CamposSeoIA\Ajax
 */

namespace HUP\CamposSeoIA\Ajax;

use HUP\CamposSeoIA\Settings;
use HUP\CamposSeoIA\Activator;
use HUP\CamposSeoIA\AI\ProviderFactory;

defined( 'ABSPATH' ) || exit;

class SettingsHandler extends BaseHandler {

    protected function register_actions(): void {
        $this->add_ajax( 'test_api', 'handle_test_api' );
        $this->add_ajax( 'reset_prompt', 'handle_reset_prompt' );
        $this->add_ajax( 'get_models', 'handle_get_models' );
    }

    /**
     * Prueba la conexión con la API del proveedor.
     */
    public function handle_test_api(): void {
        $this->verify_request();

        // test_connection() hace una llamada real a la API del proveedor, así que
        // este endpoint gasta tokens igual que los demás. Era el único de IA que
        // se había quedado sin límite. Mismo tope que create_post/improve_post:
        // es un botón de verificación, no algo que se pulse en bucle.
        if ( ! $this->check_rate_limit( 'test_api', 10, 60 ) ) {
            wp_send_json_error( [ 'message' => 'Demasiadas peticiones. Espera un momento.' ] );
        }

        $provider_slug = $this->input( 'provider' );
        $model         = $this->input( 'model' );

        if ( empty( $provider_slug ) || empty( $model ) ) {
            wp_send_json_error( [ 'message' => 'Proveedor y modelo requeridos.' ] );
        }

        // Priorizar key enviada desde el input (permite probar antes de guardar)
        $input_key = $this->input( 'api_key' );
        $api_key   = ! empty( $input_key ) ? $input_key : Settings::api_key( $provider_slug );

        if ( empty( $api_key ) ) {
            wp_send_json_error( [ 'message' => Settings::api_key_error( $provider_slug ) ] );
        }

        if ( ! ProviderFactory::has( $provider_slug ) ) {
            wp_send_json_error( [ 'message' => 'Proveedor no registrado: ' . $provider_slug ] );
        }

        $provider = ProviderFactory::create( $provider_slug );
        $result   = $provider->test_connection( $model, $api_key );

        // Guardar estado de conexión
        Settings::set_connection_status( $provider_slug, $result['success'] ? 'ok' : 'error' );

        if ( $result['success'] ) {
            wp_send_json_success( [ 'message' => $result['message'] ] );
        } else {
            wp_send_json_error( [ 'message' => $result['message'] ] );
        }
    }

    /**
     * Resetea un prompt a su valor por defecto.
     */
    public function handle_reset_prompt(): void {
        $this->verify_request();

        $type = $this->input( 'type' );

        $defaults = [
            'product'  => Activator::default_prompt_product(),
            'post'     => Activator::default_prompt_post(),
            'post_seo' => Activator::default_prompt_post_seo(),
            'page'     => Activator::default_prompt_page(),
            'improve'  => Activator::default_prompt_improve(),
        ];

        if ( ! isset( $defaults[ $type ] ) ) {
            wp_send_json_error( [ 'message' => 'Tipo de prompt no válido.' ] );
        }

        $key = HCSAI_PREFIX . 'prompt_' . $type;
        update_option( $key, $defaults[ $type ] );

        wp_send_json_success( [ 'prompt' => $defaults[ $type ] ] );
    }

    /**
     * Obtiene los modelos disponibles para un proveedor.
     */
    public function handle_get_models(): void {
        $this->verify_request();

        $provider_slug = $this->input( 'provider' );

        if ( empty( $provider_slug ) ) {
            wp_send_json_error( [ 'message' => 'Proveedor requerido.' ] );
        }

        $models = Settings::models_for( $provider_slug );

        wp_send_json_success( [ 'models' => $models ] );
    }
}
