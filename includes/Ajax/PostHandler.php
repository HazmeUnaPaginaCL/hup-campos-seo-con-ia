<?php
/**
 * AJAX: Crear y mejorar posts con IA.
 *
 * @package HUP\CamposSeoIA\Ajax
 */

namespace HUP\CamposSeoIA\Ajax;

use HUP\CamposSeoIA\Settings;
use HUP\CamposSeoIA\Generator\PostCreator;
use HUP\CamposSeoIA\Generator\PostImprover;

defined( 'ABSPATH' ) || exit;

class PostHandler extends BaseHandler {

    protected function register_actions(): void {
        $this->add_ajax( 'create_post', 'handle_create' );
        $this->add_ajax( 'improve_post', 'handle_improve' );
        $this->add_ajax( 'apply_improvement', 'handle_apply' );
    }

    /**
     * Crear post nuevo con IA.
     */
    public function handle_create(): void {
        $this->verify_request();

        $topic       = $this->input( 'topic' );
        $tone        = $this->input( 'tone', 'informativo' );
        $length      = $this->input( 'length', 'medium' );
        $category_id = $this->input_int( 'category_id' );

        if ( empty( $topic ) ) {
            wp_send_json_error( [ 'message' => 'Tema o keyword requerido.' ] );
        }

        if ( ! $this->check_rate_limit( 'create_post', 10, 60 ) ) {
            wp_send_json_error( [ 'message' => 'Demasiadas peticiones. Espera un momento.' ] );
        }

        $result = PostCreator::create( $topic, $tone, $length, $category_id );

        if ( ! empty( $result['ok'] ) ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( [ 'message' => $result['error'] ?? 'Error desconocido.' ] );
        }
    }

    /**
     * Mejorar post existente con IA.
     */
    public function handle_improve(): void {
        $this->verify_request();

        $post_id = $this->input_int( 'post_id' );

        if ( ! $post_id ) {
            wp_send_json_error( [ 'message' => 'ID de post requerido.' ] );
        }

        if ( ! $this->check_rate_limit( 'improve_post', 10, 60 ) ) {
            wp_send_json_error( [ 'message' => 'Demasiadas peticiones. Espera un momento.' ] );
        }

        $options = [
            'improve_content' => (bool) $this->input_int( 'improve_content', 1 ),
            'improve_seo'     => (bool) $this->input_int( 'improve_seo', 1 ),
            'add_cta'         => (bool) $this->input_int( 'add_cta' ),
            'improve_tags'    => (bool) $this->input_int( 'improve_tags', 1 ),
        ];

        $result = PostImprover::improve( $post_id, $options );

        if ( ! empty( $result['ok'] ) ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( [ 'message' => $result['error'] ?? 'Error desconocido.' ] );
        }
    }

    /**
     * Aplicar mejoras a un post.
     */
    public function handle_apply(): void {
        $this->verify_request();

        $post_id = $this->input_int( 'post_id' );

        if ( ! $post_id ) {
            wp_send_json_error( [ 'message' => 'ID de post requerido.' ] );
        }

        // Recibir datos de mejora
        $data = [];
        // Recibir datos de mejora. Los campos SEO salen del registro para que
        // coincidan con lo que la IA generó y con lo que el Writer va a guardar.
        $accepted = array_merge( [ 'post_title', 'post_content' ], Settings::fields_for( 'post' ) );

        $data = [];
        foreach ( $accepted as $field ) {
            if ( 'tags' === $field ) {
                continue; // Llega como array, se trata aparte.
            }
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- el nonce se verifica en verify_request(), que todo handler llama antes de leer entradas.
            $val = isset( $_POST[ $field ] ) ? wp_kses_post( wp_unslash( $_POST[ $field ] ) ) : '';
            if ( ! empty( $val ) ) {
                $data[ $field ] = $val;
            }
        }

        if ( in_array( 'tags', $accepted, true ) ) {
            $tags = $this->input_array( 'tags' );
            if ( ! empty( $tags ) ) {
                $data['tags'] = $tags;
            }
        }
        $result = PostImprover::apply( $post_id, $data );

        if ( $result['ok'] ) {
            wp_send_json_success( [ 'message' => 'Mejoras aplicadas.' ] );
        } else {
            wp_send_json_error( [ 'message' => $result['error'] ] );
        }
    }
}
