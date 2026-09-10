<?php
/**
 * Lectura de campos SEO de un post.
 *
 * @package HUP\CamposSeoIA\SEO
 */

namespace HUP\CamposSeoIA\SEO;

defined( 'ABSPATH' ) || exit;

class Reader {

    /**
     * Lee todos los campos SEO de un post.
     *
     * @param int    $post_id ID del post.
     * @param string $plugin  Slug del plugin SEO activo.
     * @return array Campos SEO con sus valores.
     */
    public static function read( int $post_id, string $plugin = '' ): array {
        if ( empty( $plugin ) ) {
            $plugin = PluginDetector::detect_active();
        }

        $field_map = FieldMap::get( $plugin );
        $fields    = [];

        // AIOSEO v4+: leer og_title/og_description desde la tabla aioseo_posts
        $aioseo_og = [];
        if ( 'aioseo' === $plugin ) {
            $aioseo_og = self::read_aioseo_og( $post_id );
        }

        foreach ( $field_map as $field_name => $meta_key ) {
            // AIOSEO: og_title y og_description vienen de aioseo_posts, no de post_meta
            if ( 'aioseo' === $plugin && isset( $aioseo_og[ $field_name ] ) ) {
                $fields[ $field_name ] = $aioseo_og[ $field_name ];
                continue;
            }

            $value = get_post_meta( $post_id, $meta_key, true ) ?: '';

            // AIOSEO v4+: focus_keyword se guarda como JSON en _aioseo_keyphrases
            if ( 'aioseo' === $plugin && 'focus_keyword' === $field_name && ! empty( $value ) ) {
                $decoded = json_decode( $value, true );
                if ( is_array( $decoded ) && ! empty( $decoded[0]['keyphrase'] ) ) {
                    // Decodificar secuencias unicode escapadas (\u00e1 → á)
                    $value = html_entity_decode( $decoded[0]['keyphrase'], ENT_QUOTES, 'UTF-8' );
                }
                // Si no es JSON válido, dejar el valor tal cual (texto plano)
            }

            $fields[ $field_name ] = $value;
        }

        // Campos adicionales nativos
        $fields['generated']           = get_post_meta( $post_id, '_hcsai_generated', true ) ?: '';
        $fields['score']               = (int) get_post_meta( $post_id, '_hcsai_score', true );
        $fields['product_description'] = get_post_field( 'post_content', $post_id ) ?: '';
        $fields['short_description']   = get_post_field( 'post_excerpt', $post_id ) ?: '';

        // Alt text de imagen destacada
        $thumb_id = get_post_thumbnail_id( $post_id );
        $fields['alt_text']      = $thumb_id ? ( get_post_meta( $thumb_id, '_wp_attachment_image_alt', true ) ?: '' ) : '';
        $fields['has_thumbnail'] = (bool) $thumb_id;

        // Tags
        $post_type = get_post_type( $post_id );
        $taxonomy  = ( 'product' === $post_type ) ? 'product_tag' : 'post_tag';
        $terms     = wp_get_post_terms( $post_id, $taxonomy, [ 'fields' => 'names' ] );
        $fields['tags'] = is_array( $terms ) ? $terms : [];

        // Info sobre plugin SEO
        $fields['has_plugin']  = ( 'none' !== $plugin );
        $fields['plugin_name'] = PluginDetector::display_name( $plugin );

        return $fields;
    }

    /**
     * Lee og_title y og_description desde AIOSEO v4+ usando su API oficial.
     *
     * @param int $post_id ID del post.
     * @return array ['og_title' => ..., 'og_description' => ...]
     */
    private static function read_aioseo_og( int $post_id ): array {
        // Usar API oficial de AIOSEO si está disponible
        if ( class_exists( 'AIOSEO\Plugin\Common\Models\Post' ) ) {
            $aioseo_post = \AIOSEO\Plugin\Common\Models\Post::getPost( $post_id );
            return [
                'og_title'       => $aioseo_post->og_title ?? '',
                'og_description' => $aioseo_post->og_description ?? '',
            ];
        }

        // Fallback: query directo a la tabla
        global $wpdb;
        $table = $wpdb->prefix . 'aioseo_posts';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Tabla de All In One SEO; solo se usa como fallback cuando su API no está disponible y no hay caché de WordPress para ella.
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return [];
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Tabla de All In One SEO; solo se usa como fallback cuando su API no está disponible y no hay caché de WordPress para ella.
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT og_title, og_description FROM %i WHERE post_id = %d', $table, $post_id ),
            ARRAY_A
        );

        return [
            'og_title'       => ! empty( $row['og_title'] ) ? $row['og_title'] : '',
            'og_description' => ! empty( $row['og_description'] ) ? $row['og_description'] : '',
        ];
    }
}
