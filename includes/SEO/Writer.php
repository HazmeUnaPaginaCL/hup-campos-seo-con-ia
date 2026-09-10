<?php
/**
 * Escritura de campos SEO en post meta.
 * Escribe campos nativos de WooCommerce/WordPress y campos de plugin SEO.
 *
 * @package HUP\CamposSeoIA\SEO
 */

namespace HUP\CamposSeoIA\SEO;

defined( 'ABSPATH' ) || exit;

class Writer {

    /**
     * Escribe todos los campos SEO generados para un post.
     *
     * @param int    $post_id       ID del post.
     * @param array  $fields        Campos generados: seo_title, meta_description, etc.
     * @param string $write_target  'native', 'rankmath', 'yoast', 'aioseo'.
     * @param bool   $overwrite     Sobreescribir campos existentes.
     *
     * @throws \RuntimeException Si el usuario actual no puede editar el post.
     *                           Antes se hacía `return` en silencio, y quien
     *                           llamaba daba la escritura por buena: el batch
     *                           registraba 'ok' y la UI mostraba éxito sin que
     *                           se hubiera guardado nada.
     */
    public static function write( int $post_id, array $fields, string $write_target = 'native', bool $overwrite = true ): void {
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            throw new \RuntimeException(
                sprintf( 'Sin permisos para editar el contenido #%d.', (int) $post_id )
            );
        }

        $field_map = FieldMap::get( $write_target );

        foreach ( $field_map as $field_name => $meta_key ) {
            if ( empty( $fields[ $field_name ] ) ) {
                continue;
            }

            // Si no sobreescribir, verificar que no exista
            if ( ! $overwrite ) {
                $existing = get_post_meta( $post_id, $meta_key, true );
                if ( ! empty( $existing ) ) {
                    continue;
                }
            }

            // AIOSEO v4+: campos especiales
            if ( 'aioseo' === $write_target ) {
                if ( 'focus_keyword' === $field_name ) {
                    $keyphrases = [ [ 'keyphrase' => sanitize_text_field( $fields[ $field_name ] ), 'score' => 0, 'active' => true ] ];
                    update_post_meta( $post_id, $meta_key, wp_json_encode( $keyphrases, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
                    continue;
                }
                // og_title/og_description se escriben juntos al final, no aquí
                if ( in_array( $field_name, [ 'og_title', 'og_description' ], true ) ) {
                    continue;
                }
            }

            update_post_meta( $post_id, $meta_key, sanitize_text_field( $fields[ $field_name ] ) );
        }

        // Campos nativos de WordPress: escribir en campos que WP/Woo realmente usa
        $post_type = get_post_type( $post_id );
        self::write_wp_native_fields( $post_id, $fields, $post_type, $overwrite );

        // Alt text de imagen destacada
        if ( ! empty( $fields['alt_text'] ) ) {
            self::write_thumbnail_alt( $post_id, $fields['alt_text'], $overwrite );
        }

        // Tags / etiquetas
        if ( ! empty( $fields['tags'] ) && is_array( $fields['tags'] ) ) {
            self::write_tags( $post_id, $fields['tags'], $post_type );
        }

        // AIOSEO v4+: escribir og_title y og_description juntos en una sola operación
        if ( 'aioseo' === $write_target ) {
            $og_title = ! empty( $fields['og_title'] ) ? sanitize_text_field( $fields['og_title'] ) : null;
            $og_desc  = ! empty( $fields['og_description'] ) ? sanitize_text_field( $fields['og_description'] ) : null;
            if ( null !== $og_title || null !== $og_desc ) {
                self::write_aioseo_og_batch( $post_id, $og_title, $og_desc, $overwrite );
            }
        }

        // Marcar como generado
        update_post_meta( $post_id, '_hcsai_generated', current_time( 'mysql' ) );

        // Calcular y guardar score
        $score = Scorer::calculate( $post_id, $write_target );
        update_post_meta( $post_id, '_hcsai_score', $score );
    }

    /**
     * Escribe campos nativos de WordPress/WooCommerce que el CMS realmente usa.
     * Esto garantiza que los datos sean visibles sin necesidad de plugin SEO.
     */
    private static function write_wp_native_fields( int $post_id, array $fields, string $post_type, bool $overwrite ): void {
        $update_data  = [ 'ID' => $post_id ];
        $needs_update = false;

        // Descripción larga del producto (post_content)
        if ( ! empty( $fields['product_description'] ) ) {
            $current_content = get_post_field( 'post_content', $post_id );
            if ( $overwrite || empty( trim( $current_content ) ) ) {
                $update_data['post_content'] = wp_kses_post( $fields['product_description'] );
                $needs_update = true;
            }
        }

        // Descripción corta del producto (post_excerpt)
        $excerpt_source = ! empty( $fields['short_description'] )
            ? $fields['short_description']
            : ( ! empty( $fields['meta_description'] ) ? $fields['meta_description'] : '' );

        if ( ! empty( $excerpt_source ) ) {
            $current_excerpt = get_post_field( 'post_excerpt', $post_id );
            if ( $overwrite || empty( trim( $current_excerpt ) ) ) {
                $update_data['post_excerpt'] = wp_kses_post( $excerpt_source );
                $needs_update = true;
            }
        }

        if ( $needs_update ) {
            // Escritura directa a propósito: wp_update_post() dispararía save_post,
            // y en un lote de cientos de items eso encadena reindexados de los
            // plugins SEO y de WooCommerce por cada fila. La alternativa habitual
            // (remove_all_actions) destruiría hooks de terceros, así que se
            // escribe la fila y se invalida la caché del post a mano.
            global $wpdb;
            $update_fields = [];
            $format        = [];

            if ( isset( $update_data['post_content'] ) ) {
                $update_fields['post_content'] = $update_data['post_content'];
                $format[]                      = '%s';
            }
            if ( isset( $update_data['post_excerpt'] ) ) {
                $update_fields['post_excerpt'] = $update_data['post_excerpt'];
                $format[]                      = '%s';
            }

            if ( ! empty( $update_fields ) ) {
                // Saltarse save_post no debe dejar el post con fecha de modificación
                // vieja: se actualiza a mano, como haría wp_update_post().
                $update_fields['post_modified']     = current_time( 'mysql' );
                $update_fields['post_modified_gmt'] = current_time( 'mysql', true );
                $format[]                           = '%s';
                $format[]                           = '%s';

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Escritura deliberada sin save_post (ver comentario arriba); la caché se invalida con clean_post_cache().
                $wpdb->update( $wpdb->posts, $update_fields, [ 'ID' => $post_id ], $format, [ '%d' ] );
                clean_post_cache( $post_id );
            }
        }
    }

    /**
     * Escribe alt text en la imagen destacada.
     */
    private static function write_thumbnail_alt( int $post_id, string $alt_text, bool $overwrite ): void {
        $thumb_id = get_post_thumbnail_id( $post_id );
        if ( ! $thumb_id ) {
            return;
        }

        $current = get_post_meta( $thumb_id, '_wp_attachment_image_alt', true );
        if ( ! $overwrite && ! empty( $current ) ) {
            return;
        }

        update_post_meta( $thumb_id, '_wp_attachment_image_alt', sanitize_text_field( $alt_text ) );
    }

    /**
     * Escribe og_title y og_description usando la API oficial de AIOSEO v4+.
     * Usa Post::getPost() para obtener/crear el modelo y luego save().
     *
     * @param int         $post_id   ID del post.
     * @param string|null $og_title  Valor del og_title (null = no modificar).
     * @param string|null $og_desc   Valor del og_description (null = no modificar).
     * @param bool        $overwrite Si es false, no sobreescribir valores existentes.
     */
    private static function write_aioseo_og_batch( int $post_id, ?string $og_title, ?string $og_desc, bool $overwrite ): void {
        // Usar la API oficial de AIOSEO si está disponible
        if ( class_exists( 'AIOSEO\Plugin\Common\Models\Post' ) ) {
            $aioseo_post = \AIOSEO\Plugin\Common\Models\Post::getPost( $post_id );

            if ( null !== $og_title && ( $overwrite || empty( $aioseo_post->og_title ) ) ) {
                $aioseo_post->og_title      = $og_title;
                $aioseo_post->og_title_type = 'custom';
            }
            if ( null !== $og_desc && ( $overwrite || empty( $aioseo_post->og_description ) ) ) {
                $aioseo_post->og_description      = $og_desc;
                $aioseo_post->og_description_type = 'custom';
            }

            $aioseo_post->save();
            return;
        }

        // Fallback: escritura directa en la tabla si la clase no está disponible
        global $wpdb;
        $table = $wpdb->prefix . 'aioseo_posts';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Tabla de All In One SEO; solo se usa como fallback cuando su API no está disponible y no hay caché de WordPress para ella.
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        $now  = current_time( 'mysql' );
        $og_t = ( null !== $og_title ) ? $og_title : '';
        $og_d = ( null !== $og_desc ) ? $og_desc : '';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Tabla de All In One SEO; solo se usa como fallback cuando su API no está disponible y no hay caché de WordPress para ella.
        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO %i
                (post_id, title, description, og_title, og_description, og_title_type, og_description_type,
                og_object_type, og_image_type, robots_default, robots_noindex, robots_noarchive,
                robots_nosnippet, robots_nofollow, robots_noimageindex, robots_noodp, robots_notranslate,
                robots_max_snippet, robots_max_videopreview, robots_max_imagepreview,
                pillar_content, twitter_use_og, twitter_card, twitter_image_type, seo_score, created, updated)
                VALUES (%d, '', '', %s, %s, 'custom', 'custom', 'default', 'default',
                1, 0, 0, 0, 0, 0, 0, 0, -1, -1, 'large', 0, 1, 'default', 'default', 0, %s, %s)
                ON DUPLICATE KEY UPDATE
                og_title = VALUES(og_title), og_description = VALUES(og_description),
                og_title_type = 'custom', og_description_type = 'custom', updated = VALUES(updated)",
                $table, $post_id, $og_t, $og_d, $now, $now
            )
        );
    }

    /**
     * Asigna tags/etiquetas al post.
     */
    private static function write_tags( int $post_id, array $tags, string $post_type ): void {
        $sanitized = array_map( 'sanitize_text_field', $tags );
        $sanitized = array_filter( $sanitized );

        if ( empty( $sanitized ) ) {
            return;
        }

        if ( 'product' === $post_type ) {
            wp_set_object_terms( $post_id, $sanitized, 'product_tag', true );
        } else {
            wp_set_post_tags( $post_id, $sanitized, true );
        }
    }
}
