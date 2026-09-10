<?php
/**
 * AJAX: Leer y guardar campos SEO manualmente.
 *
 * @package HUP\CamposSeoIA\Ajax
 */

namespace HUP\CamposSeoIA\Ajax;

use HUP\CamposSeoIA\SEO\Reader;
use HUP\CamposSeoIA\SEO\Writer;
use HUP\CamposSeoIA\SEO\PluginDetector;
use HUP\CamposSeoIA\SEO\Scorer;

defined( 'ABSPATH' ) || exit;

class FieldsHandler extends BaseHandler {

    protected function register_actions(): void {
        $this->add_ajax( 'get_fields', 'handle_get_fields' );
        $this->add_ajax( 'save_fields', 'handle_save_fields' );
    }

    /**
     * Obtiene campos SEO de un post para el modal de edición.
     */
    public function handle_get_fields(): void {
        $this->verify_request();

        $post_id = $this->input_int( 'post_id' );

        if ( ! $post_id ) {
            wp_send_json_error( [ 'message' => 'ID requerido.' ] );
        }

        $post = get_post( $post_id );
        if ( ! $post ) {
            wp_send_json_error( [ 'message' => 'Post no encontrado.' ] );
        }

        $plugin = PluginDetector::detect_active();
        $fields = Reader::read( $post_id, $plugin );

        wp_send_json_success( [
            'fields'     => $fields,
            'post_title' => $post->post_title,
            // Desglose del score: sin él, un porcentaje bajo parece un fallo del
            // plugin en vez de campos por completar.
            'breakdown'  => Scorer::breakdown( $post_id, $plugin ),
        ] );
    }

    /**
     * Guarda campos SEO editados manualmente desde el modal.
     */
    public function handle_save_fields(): void {
        $this->verify_request();

        $post_id = $this->input_int( 'post_id' );

        if ( ! $post_id ) {
            wp_send_json_error( [ 'message' => 'ID requerido.' ] );
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( [ 'message' => 'Sin permisos para editar este post.' ] );
        }

        // Obtener campos del formulario. Cada valor se desescapa y sanitiza uno a
        // uno en el bucle de abajo, según lo que admita el campo: el HTML básico
        // de una descripción no se puede tratar igual que un título.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- el nonce se verifica en verify_request(), que todo handler llama antes de leer entradas; cada valor se sanitiza por elemento más abajo.
        $raw_fields = isset( $_POST['fields'] ) && is_array( $_POST['fields'] ) ? $_POST['fields'] : [];
        $fields     = [];

        foreach ( $raw_fields as $key => $value ) {
            $key = sanitize_key( $key );
            if ( 'tags' === $key ) {
                // Tags: string separado por comas → array
                $tags_str = sanitize_text_field( wp_unslash( $value ) );
                $fields['tags'] = array_filter( array_map( 'trim', explode( ',', $tags_str ) ) );
            } elseif ( 'product_description' === $key ) {
                // Descripción del producto: puede contener HTML básico
                $fields[ $key ] = wp_kses_post( wp_unslash( $value ) );
            } else {
                $fields[ $key ] = sanitize_text_field( wp_unslash( $value ) );
            }
        }

        if ( empty( $fields ) ) {
            wp_send_json_error( [ 'message' => 'Sin campos para guardar.' ] );
        }

        // Escribir campos
        $plugin       = PluginDetector::detect_active();
        $write_target = ( 'none' !== $plugin ) ? $plugin : 'native';

        Writer::write( $post_id, $fields, $write_target, true );

        // Recalcular score. Se devuelve el desglose entero para que el modal
        // muestre al instante qué campos acaba de completar el usuario.
        $breakdown = Scorer::breakdown( $post_id, $write_target );
        update_post_meta( $post_id, '_hcsai_score', $breakdown['score'] );

        wp_send_json_success( [
            'score'     => $breakdown['score'],
            'breakdown' => $breakdown,
        ] );
    }
}
