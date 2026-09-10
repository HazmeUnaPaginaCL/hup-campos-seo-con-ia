<?php
/**
 * Detección de plugins SEO activos.
 * Detecta RankMath, Yoast SEO y All In One SEO.
 *
 * @package HUP\CamposSeoIA\SEO
 */

namespace HUP\CamposSeoIA\SEO;

defined( 'ABSPATH' ) || exit;

class PluginDetector {

    /**
     * Plugins SEO soportados con sus funciones de detección.
     */
    private const PLUGINS = [
        'rankmath' => 'rank_math',
        'yoast'    => 'wpseo_init',
        'aioseo'   => 'aioseo',
    ];

    /**
     * Nombres legibles de los plugins.
     */
    private const NAMES = [
        'rankmath' => 'RankMath SEO',
        'yoast'    => 'Yoast SEO',
        'aioseo'   => 'All In One SEO',
        'none'     => 'Ninguno (campos nativos)',
    ];

    /**
     * Cache estático para evitar detecciones repetidas en batch.
     *
     * @var string|null
     */
    private static $cached_plugin = null;

    /**
     * Detecta el plugin SEO activo principal.
     *
     * @return string Slug del plugin: 'rankmath', 'yoast', 'aioseo' o 'none'.
     */
    public static function detect_active(): string {
        if ( null !== self::$cached_plugin ) {
            return self::$cached_plugin;
        }

        // RankMath
        if ( class_exists( 'RankMath' ) || function_exists( 'rank_math' ) ) {
            return self::$cached_plugin = 'rankmath';
        }

        // Yoast SEO
        if ( defined( 'WPSEO_VERSION' ) || function_exists( 'wpseo_init' ) ) {
            return self::$cached_plugin = 'yoast';
        }

        // All In One SEO (v4+ usa clase AIOSEO_COMMON_VERSION o constante AIOSEO_VERSION)
        if ( defined( 'AIOSEO_VERSION' ) || class_exists( 'AIOSEO\Plugin\AIOSEO' ) || function_exists( 'aioseo' ) ) {
            return self::$cached_plugin = 'aioseo';
        }

        return self::$cached_plugin = 'none';
    }

    /**
     * Nombre visible del plugin.
     */
    public static function display_name( string $slug ): string {
        return self::NAMES[ $slug ] ?? $slug;
    }
}
