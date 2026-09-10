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

    /**
     * Registra menú principal y submenús.
     */
    public function register_menus(): void {
        // Menú principal
        add_menu_page(
            'HUP - Campos SEO con IA',
            'Campos SEO IA',
            'manage_options',
            'hcsai',
            [ $this, 'render_dashboard' ],
            'dashicons-welcome-write-blog',
            80
        );

        // Submenús
        add_submenu_page( 'hcsai', 'Dashboard',      'Dashboard',      'manage_options', 'hcsai',               [ $this, 'render_dashboard' ] );
        add_submenu_page( 'hcsai', 'Productos',       'Productos',      'manage_options', 'hcsai-productos',     [ $this, 'render_productos' ] );
        add_submenu_page( 'hcsai', 'Blog',            'Blog',           'manage_options', 'hcsai-blog',          [ $this, 'render_blog' ] );
        add_submenu_page( 'hcsai', 'Configuración',   'Configuración',  'manage_options', 'hcsai-configuracion', [ $this, 'render_configuracion' ] );
    }

    public function render_dashboard(): void {
        require HCSAI_PATH . 'admin/views/dashboard.php';
    }

    public function render_productos(): void {
        require HCSAI_PATH . 'admin/views/productos.php';
    }

    public function render_blog(): void {
        require HCSAI_PATH . 'admin/views/blog.php';
    }

    public function render_configuracion(): void {
        require HCSAI_PATH . 'admin/views/configuracion.php';
    }
}
