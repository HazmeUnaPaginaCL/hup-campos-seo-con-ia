<?php
/**
 * AJAX: Generación SEO individual.
 *
 * @package HUP\CamposSeoIA\Ajax
 */

namespace HUP\CamposSeoIA\Ajax;

use HUP\CamposSeoIA\Generator\SingleGenerator;

defined( 'ABSPATH' ) || exit;

class GenerateHandler extends BaseHandler {

    protected function register_actions(): void {
        $this->add_ajax( 'generate', 'handle_generate' );
    }

    /**
     * Genera los campos SEO de un contenido.
     *
     * Genera de a un contenido por vez, sin tope: el usuario puede repetirlo
     * las veces que quiera. Toda la generación pasa por
     * SingleGenerator::generate(), que es el punto de entrada reutilizable.
     */
    public function handle_generate(): void {
        $this->verify_request();

        $post_id       = $this->input_int( 'post_id' );
        $write_targets = $this->input_array( 'write_targets' );
        $overwrite     = (bool) $this->input_int( 'overwrite', 1 );
        $write_target  = ! empty( $write_targets ) ? $write_targets[0] : '';

        if ( ! $post_id ) {
            wp_send_json_error( [ 'message' => 'ID de post requerido.' ] );
        }

        if ( ! $this->check_rate_limit( 'generate', 60, 60 ) ) {
            wp_send_json_error( [ 'message' => 'Demasiadas peticiones. Espera un momento.' ] );
        }

        $selected_fields = $this->input_array( 'selected_fields' );

        // Sin caché: una generación individual debe dar respuesta fresca.
        $result = SingleGenerator::generate( $post_id, $write_target, $overwrite, $selected_fields, false );

        if ( $result['ok'] ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( [ 'message' => $result['error'] ] );
        }
    }
}
