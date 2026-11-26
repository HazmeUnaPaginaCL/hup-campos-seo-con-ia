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

        // Con WooCommerce: después del Kit (99) y de Sincronización (110).
        // Sin WooCommerce: al final de Herramientas, tras los demás paneles HUP.
        add_action( 'admin_menu', [ $this, 'register_page' ], self::en_woocommerce() ? 120 : 9993 );
        add_action( 'admin_page_access_denied', [ $this, 'redirect_moved_page' ] );
    }

    /** El panel va dentro de WooCommerce si está activo; si no, en Herramientas. */
    private static function en_woocommerce(): bool {
        return class_exists( 'WooCommerce' );
    }

    /**
     * URL del panel según el menú en que esté.
     *
     * @param array $args Parámetros extra, por ejemplo [ 'tab' => 'blog' ].
     */
    public static function url( array $args = [] ): string {
        return add_query_arg(
            [ 'page' => 'hcsai' ] + $args,
            admin_url( self::en_woocommerce() ? 'admin.php' : 'tools.php' )
        );
    }

    /** Registra la página del plugin en WooCommerce o en Herramientas. */
    public function register_page(): void {
        global $submenu;

        $parent   = self::en_woocommerce() ? 'woocommerce' : 'tools.php';
        $position = null;

        // En WooCommerce, justo debajo del último plugin HUP registrado.
        if ( 'woocommerce' === $parent && ! empty( $submenu[ $parent ] ) ) {
            $slugs = array_column( $submenu[ $parent ], 2 );
            foreach ( [ 'hupkec-settings', 'hup-sincroniza-productos-en-planillas' ] as $slug ) {
                $index = array_search( $slug, $slugs, true );
                if ( false !== $index ) {
                    $position = max( (int) $position, $index + 1 );
                }
            }
        }

        add_submenu_page(
            $parent,
            'HUP - Campos SEO con IA',
            'HUP - SEO con IA',
            'manage_options',
            'hcsai',
            [ $this, 'render' ],
            $position
        );
    }

    /** Un enlace guardado a Herramientas sigue abriendo si luego se activa WooCommerce. */
    public function redirect_moved_page(): void {
        global $pagenow, $plugin_page;

        if ( 'tools.php' === $pagenow && 'hcsai' === $plugin_page && self::en_woocommerce() && current_user_can( 'manage_options' ) ) {
            wp_safe_redirect( self::url() );
            exit;
        }
    }

    /** Conserva las rutas anteriores como alias. */
    public function register_menus(): void {
        // Enlaces antiguos, incluidos los que usa el addon, siguen abriendo.
        add_submenu_page( '', 'Productos', 'Productos', 'manage_options', 'hcsai-productos', [ $this, 'render_productos' ] );
        add_submenu_page( '', 'Blog', 'Blog', 'manage_options', 'hcsai-blog', [ $this, 'render_blog' ] );
        add_submenu_page( '', 'Configuración', 'Configuración', 'manage_options', 'hcsai-configuracion', [ $this, 'render_configuracion' ] );
    }

    /** Despacha las secciones de la página principal. */
    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Sin permisos.', 'hup-campos-seo-con-ia' ) );
        }

        // Solo elige qué sección se muestra (lectura, sin cambios en datos), y
        // el valor se limita a la lista del switch: no hace falta nonce.
        $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
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
            case 'otros':
                $this->check_capability();
                require HCSAI_PATH . 'admin/views/otros.php';
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
