<?php
/**
 * Mapeo de campos SEO por plugin.
 * Define qué meta keys corresponden a cada campo según el plugin SEO activo.
 *
 * @package HUP\CamposSeoIA\SEO
 */

namespace HUP\CamposSeoIA\SEO;

defined( 'ABSPATH' ) || exit;

class FieldMap {

    /**
     * Mapeo completo de campos por plugin.
     * Cada plugin tiene su propio set de meta keys.
     */
    private const MAP = [
        'rankmath' => [
            'seo_title'        => 'rank_math_title',
            'meta_description' => 'rank_math_description',
            'focus_keyword'    => 'rank_math_focus_keyword',
            'og_title'         => 'rank_math_facebook_title',
            'og_description'   => 'rank_math_facebook_description',
        ],
        'yoast' => [
            'seo_title'        => '_yoast_wpseo_title',
            'meta_description' => '_yoast_wpseo_metadesc',
            'focus_keyword'    => '_yoast_wpseo_focuskw',
            'og_title'         => '_yoast_wpseo_opengraph-title',
            'og_description'   => '_yoast_wpseo_opengraph-description',
        ],
        // NOTA: AIOSEO v4+ usa su propia tabla `aioseo_posts` internamente,
        // pero también sincroniza estos post_meta, que son los que usamos aquí.
        // Si al probar con AIOSEO los campos no se llenan, habría que usar su API directamente.
        // AIOSEO v4+: usa _aioseo_keyphrases (JSON serializado), pero la keyphrase principal
        // se escribe directamente en ese campo como string simple si se hace via update_post_meta.
        // El campo _aioseo_title y _aioseo_description son los correctos para título y descripción.
        'aioseo' => [
            'seo_title'        => '_aioseo_title',
            'meta_description' => '_aioseo_description',
            'focus_keyword'    => '_aioseo_keyphrases',
            'og_title'         => '_aioseo_og_title',
            'og_description'   => '_aioseo_og_description',
        ],
        'native' => [
            'seo_title'        => '_hcsai_seo_title',
            'meta_description' => '_hcsai_meta_description',
            'focus_keyword'    => '_hcsai_focus_keyword',
            'og_title'         => '_hcsai_og_title',
            'og_description'   => '_hcsai_og_description',
        ],
    ];

    /**
     * Obtiene el mapeo de campos para un plugin.
     *
     * @param string $plugin Slug del plugin ('rankmath', 'yoast', 'aioseo', 'native' o 'none').
     * @return array<string, string> campo => meta_key
     */
    public static function get( string $plugin ): array {
        if ( 'none' === $plugin ) {
            $plugin = 'native';
        }
        return self::MAP[ $plugin ] ?? self::MAP['native'];
    }

    /**
     * Obtiene la meta key para un campo específico.
     *
     * @param string $plugin Slug del plugin.
     * @param string $field  Nombre del campo (seo_title, meta_description, etc.).
     * @return string Meta key o vacío si no existe.
     */
    public static function key( string $plugin, string $field ): string {
        $map = self::get( $plugin );
        return $map[ $field ] ?? '';
    }

}
