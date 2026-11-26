<?php
/**
 * Detección de plugins SEO activos.
 * Detecta Rank Math, Yoast SEO, All In One SEO, SEOPress, The SEO Framework y Slim SEO.
 *
 * @package HUP\CamposSeoIA\SEO
 */

namespace HUP\CamposSeoIA\SEO;

defined( 'ABSPATH' ) || exit;

class PluginDetector {

    /**
     * Nombres legibles de los plugins.
     */
    private const NAMES = [
        'rankmath' => 'RankMath SEO',
        'yoast'    => 'Yoast SEO',
        'aioseo'   => 'All In One SEO',
        'seopress' => 'SEOPress',
        'tsf'      => 'The SEO Framework',
        'slimseo'  => 'Slim SEO',
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
     * Si hay varios activos manda el primero de la lista, en este orden. Las
     * comprobaciones usan la constante o la función que define cada plugin al
     * cargarse (revisadas en su código: SEOPress 10.4, The SEO Framework 5.2,
     * Slim SEO 4.11).
     *
     * @return string Slug del plugin: 'rankmath', 'yoast', 'aioseo', 'seopress', 'tsf', 'slimseo' o 'none'.
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

        // SEOPress
        if ( defined( 'SEOPRESS_VERSION' ) ) {
            return self::$cached_plugin = 'seopress';
        }

        // The SEO Framework
        if ( defined( 'THE_SEO_FRAMEWORK_VERSION' ) || function_exists( 'the_seo_framework' ) ) {
            return self::$cached_plugin = 'tsf';
        }

        // Slim SEO
        if ( defined( 'SLIM_SEO_VER' ) ) {
            return self::$cached_plugin = 'slimseo';
        }

        return self::$cached_plugin = 'none';
    }

    /**
     * Nombre visible del plugin.
     */
    public static function display_name( string $slug ): string {
        return self::NAMES[ $slug ] ?? $slug;
    }

    /**
     * Nombres de los plugins SEO que el plugin reconoce, para los textos de ayuda.
     *
     * @return string Por ejemplo «RankMath SEO, Yoast SEO, … o Slim SEO».
     */
    public static function supported_names(): string {
        $names = array_values( array_diff_key( self::NAMES, [ 'none' => true ] ) );
        $last  = array_pop( $names );

        return implode( ', ', $names ) . ' o ' . $last;
    }
}
