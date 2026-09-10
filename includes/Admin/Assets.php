<?php
/**
 * Enqueue de CSS y JS del admin.
 *
 * @package HUP\CamposSeoIA\Admin
 */

namespace HUP\CamposSeoIA\Admin;

use HUP\CamposSeoIA\Settings;
use HUP\CamposSeoIA\SEO\PluginDetector;

defined( 'ABSPATH' ) || exit;

class Assets {

    public function __construct() {
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
        add_action( 'admin_post_hcsai_save_settings', [ $this, 'handle_save_settings' ] );
    }

    /**
     * Encola assets solo en las páginas del plugin.
     */
    public function enqueue( string $hook ): void {
        if ( false === strpos( $hook, 'hcsai' ) ) {
            return;
        }

        wp_enqueue_style(
            'hup-seo-admin',
            HCSAI_URL . 'admin/css/hup-seo.css',
            [],
            HCSAI_VERSION
        );

        // Dependencia de jQuery a propósito: este script solo se encola en el
        // admin (ver el guard de $hook arriba) y WordPress ya carga jQuery en
        // toda página de admin, así que no añade ninguna petición. El plugin no
        // encola nada en el frontend, de modo que ningún visitante lo descarga.
        wp_enqueue_script(
            'hcsai-admin',
            HCSAI_URL . 'admin/js/admin.js',
            [ 'jquery' ],
            HCSAI_VERSION,
            true
        );

        wp_localize_script( 'hcsai-admin', 'hcsai', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'hcsai_nonce' ),
            'provider' => Settings::provider(),
            'model'    => Settings::model(),
            'models'   => Settings::models_catalog(),
            // La calculadora usa esta tabla: antes tenía su propia copia en JS,
            // que se desincronizaba y no pasaba por el filtro hcsai_token_costs.
            'costs'    => Settings::token_costs(),
            'i18n'     => [
                'generating'  => __( 'Generando...', 'hup-campos-seo-con-ia' ),
                'completed'   => __( 'Completado', 'hup-campos-seo-con-ia' ),
                'error'       => __( 'Error', 'hup-campos-seo-con-ia' ),
                'confirm'     => __( '¿Estás seguro?', 'hup-campos-seo-con-ia' ),
                'saved'       => __( 'Guardado correctamente', 'hup-campos-seo-con-ia' ),
            ],
        ] );
    }

    /**
     * Procesa el guardado de la configuración.
     */
    public function handle_save_settings(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Sin permisos.', 'hup-campos-seo-con-ia' ) );
        }

        check_admin_referer( 'hcsai_save_settings' );

        // Proveedor — validado contra allowlist
        $allowed_providers = array_keys( Settings::providers_display() );
        $provider          = sanitize_text_field( wp_unslash( $_POST['hcsai_provider'] ?? '' ) );
        if ( ! empty( $provider ) && in_array( $provider, $allowed_providers, true ) ) {
            Settings::save( 'provider', $provider );
        }

        // API keys — guardar para cada proveedor presente en el form
        $providers = $allowed_providers;
        foreach ( $providers as $p ) {
            $key_field = 'hcsai_api_key_' . $p;
            if ( isset( $_POST[ $key_field ] ) ) {
                $raw_key = sanitize_text_field( wp_unslash( $_POST[ $key_field ] ) );
                // Solo guardar si el usuario ingresó una key nueva (no vacío, no placeholder)
                if ( ! empty( $raw_key ) ) {
                    Settings::save_api_key( $p, $raw_key );
                }
            }
        }

        // Modelo por proveedor — validado contra catálogo
        $models_catalog = Settings::models_catalog();
        foreach ( $providers as $p ) {
            $model_field = 'hcsai_model_' . $p;
            if ( ! empty( $_POST[ $model_field ] ) ) {
                $raw_model     = sanitize_text_field( wp_unslash( $_POST[ $model_field ] ) );
                $allowed_models = array_keys( $models_catalog[ $p ] ?? [] );
                if ( in_array( $raw_model, $allowed_models, true ) ) {
                    Settings::save( 'model_' . $p, $raw_model );
                }
            }
        }

        // Contexto del sitio
        if ( isset( $_POST['hcsai_site_context'] ) ) {
            Settings::save( 'site_context', sanitize_textarea_field( wp_unslash( $_POST['hcsai_site_context'] ) ) );
        }

        // País e idioma
        if ( ! empty( $_POST['hcsai_country'] ) ) {
            Settings::save( 'country', sanitize_text_field( wp_unslash( $_POST['hcsai_country'] ) ) );
        }
        if ( ! empty( $_POST['hcsai_language'] ) ) {
            Settings::save( 'language', sanitize_text_field( wp_unslash( $_POST['hcsai_language'] ) ) );
        }

        // Tasa USD a CLP
        if ( isset( $_POST['hcsai_usd_to_clp'] ) ) {
            Settings::save( 'usd_to_clp', absint( $_POST['hcsai_usd_to_clp'] ) );
        }

        // Prompts personalizados. Los cuatro que muestra Configuración: sin el
        // módulo Páginas no hay prompt 'page' que guardar.
        foreach ( [ 'product', 'post', 'post_seo', 'improve' ] as $type ) {
            $field = 'hcsai_prompt_' . $type;
            if ( isset( $_POST[ $field ] ) ) {
                Settings::save( 'prompt_' . $type, sanitize_textarea_field( wp_unslash( $_POST[ $field ] ) ) );
            }
        }

        // Retención del log de generaciones (0 = conservar todo).
        if ( isset( $_POST['hcsai_log_retention_days'] ) ) {
            Settings::save( 'log_retention_days', absint( $_POST['hcsai_log_retention_days'] ) );
        }

        // Desinstalación: consentimiento explícito para borrar las API keys.
        // El checkbox siempre forma parte de este formulario, por lo que su
        // ausencia en el POST significa "desmarcado".
        Settings::save( 'delete_api_keys', isset( $_POST['hcsai_delete_api_keys'] ) ? '1' : '0' );

        // Redireccionar de vuelta a configuración
        $redirect_url = admin_url( 'admin.php?page=hcsai-configuracion&saved=1' );
        wp_safe_redirect( $redirect_url );
        exit;
    }
}
