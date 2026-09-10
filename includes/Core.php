<?php
/**
 * Núcleo del plugin — Singleton principal.
 * Carga dependencias, registra hooks y conecta módulos.
 *
 * @package HUP\CamposSeoIA
 */

namespace HUP\CamposSeoIA;

defined( 'ABSPATH' ) || exit;

class Core {

    /** @var self|null */
    private static $instance = null;

    /**
     * Obtiene la instancia única del plugin.
     */
    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor privado — solo se ejecuta una vez.
     */
    private function __construct() {
        $this->init_hooks();
    }

    /**
     * Registra hooks principales.
     */
    private function init_hooks(): void {
        // Registrar proveedores de IA por defecto (siempre, para AJAX)
        AI\ProviderFactory::register( 'openai', AI\OpenAIProvider::class );
        AI\ProviderFactory::register( 'google', AI\GeminiProvider::class );

        // Purga diaria del log. Fuera del bloque is_admin(): WP-Cron corre sin
        // contexto de admin, así que el handler debe registrarse siempre.
        add_action( Logger::PURGE_HOOK, [ Logger::class, 'purge' ] );

        // Frontend: inyectar meta tags SEO cuando no hay plugin SEO activo
        if ( ! is_admin() ) {
            new Frontend\MetaOutput();
        }

        // AJAX handlers (admin-ajax.php corre bajo is_admin() = true, siempre deben registrarse)
        if ( is_admin() ) {
            new Admin\Menu();
            new Admin\Assets();

            new Ajax\GenerateHandler();
            new Ajax\PostHandler();
            new Ajax\FieldsHandler();
            new Ajax\SettingsHandler();
            new Ajax\ScanHandler();
        }

        // Punto de arranque para extensiones: se dispara con el plugin ya
        // cargado y sus proveedores registrados, así que quien se enganche
        // encuentra todo en pie. Recibe la versión para comprobar compatibilidad.
        do_action( 'hcsai_register_addon', HCSAI_VERSION );

        // Enlaces en listado de plugins
        add_filter( 'plugin_action_links_' . HCSAI_BASENAME, [ $this, 'add_plugin_action_links' ] );
    }

    /**
     * Agregar enlaces de configuración en el listado de plugins.
     *
     * @param  array $links Enlaces existentes.
     * @return array
     */
    public function add_plugin_action_links( array $links ): array {
        $configurar = '<a href="' . esc_url( admin_url( 'admin.php?page=hcsai-configuracion' ) ) . '">'
            . esc_html__( 'Configurar', 'hup-campos-seo-con-ia' ) . '</a>';

        array_unshift( $links, $configurar );

        return $links;
    }
}
