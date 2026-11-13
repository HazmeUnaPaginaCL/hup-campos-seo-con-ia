<?php
/**
 * Registro de menús y submenús del admin.
 *
 * @package HUP\CamposSeoIA\Admin
 */

namespace HUP\CamposSeoIA\Admin;

defined( 'ABSPATH' ) || exit;

class Menu {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_menus' ] );
    }

    /** Registra una página HUP y conserva las rutas anteriores como alias. */
    public function register_menus(): void {
        add_menu_page(
            'HUP - Campos SEO con IA',
            'Campos SEO IA',
            'manage_options',
            'hcsai',
            [ $this, 'render' ],
            'dashicons-welcome-write-blog',
            80
        );

        // Enlaces antiguos, incluidos los que usa el addon, siguen abriendo.
        add_submenu_page( null, 'Productos', 'Productos', 'manage_options', 'hcsai-productos', [ $this, 'render_productos' ] );
        add_submenu_page( null, 'Blog', 'Blog', 'manage_options', 'hcsai-blog', [ $this, 'render_blog' ] );
        add_submenu_page( null, 'Configuración', 'Configuración', 'manage_options', 'hcsai-configuracion', [ $this, 'render_configuracion' ] );
    }

    /** Despacha las secciones de la página principal. */
    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Sin permisos.', 'hup-campos-seo-con-ia' ) );
        }

        $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard';
        switch ( $tab ) {
            case 'productos':
                $this->render_productos();
                break;
            case 'blog':
                $this->render_blog();
                break;
            case 'configuracion':
                $this->render_configuracion();
                break;
            default:
                $this->render_dashboard();
        }
    }

    public function render_dashboard(): void {
        $this->check_capability();
        require HCSAI_PATH . 'admin/views/dashboard.php';
    }

    public function render_productos(): void {
        $this->check_capability();
        require HCSAI_PATH . 'admin/views/productos.php';
    }

    public function render_blog(): void {
        $this->check_capability();
        require HCSAI_PATH . 'admin/views/blog.php';
    }

    public function render_configuracion(): void {
        $this->check_capability();
        require HCSAI_PATH . 'admin/views/configuracion.php';
    }

    private function check_capability(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Sin permisos.', 'hup-campos-seo-con-ia' ) );
        }
    }
}
