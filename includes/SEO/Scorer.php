<?php
/**
 * Cálculo de score SEO para un post.
 * Evalúa la completitud de los campos SEO.
 *
 * @package HUP\CamposSeoIA\SEO
 */

namespace HUP\CamposSeoIA\SEO;

use HUP\CamposSeoIA\Settings;

defined( 'ABSPATH' ) || exit;

class Scorer {

    /**
     * Pesos de los campos SEO (suman 85).
     *
     * El score final NO es la suma directa: se normaliza sobre los puntos que
     * realmente aplican al contenido (ver self::normalize()). Antes se sumaba
     * en crudo, y como los 15 puntos restantes son de campos WooCommerce, un
     * post o página perfectos se quedaban topados en 85%.
     */
    private const WEIGHTS_SEO = [
        'seo_title'         => 20,
        'meta_description'  => 20,
        'focus_keyword'     => 15,
        'og_title'          => 5,
        'og_description'    => 5,
        'alt_text'          => 10,
        'tags'              => 10,
    ];

    /**
     * Pesos extra de campos nativos WooCommerce. Solo aplican a productos.
     */
    private const WEIGHTS_WC = [
        'product_description' => 10,
        'short_description'   => 5,
    ];

    /**
     * Pesos en modo nativo sin plugin SEO (total = 100).
     */
    private const WEIGHTS_NATIVE = [
        'product_description' => 30,
        'short_description'   => 30,
        'alt_text'            => 20,
        'tags'                => 20,
    ];

    /**
     * Nombres visibles de cada campo, para el desglose.
     */
    private const LABELS = [
        'seo_title'           => 'Título SEO',
        'meta_description'    => 'Meta descripción',
        'focus_keyword'       => 'Palabra clave principal',
        'og_title'            => 'Título para redes sociales',
        'og_description'      => 'Descripción para redes sociales',
        'alt_text'            => 'Texto alternativo de la imagen destacada',
        'tags'                => 'Etiquetas',
        'product_description' => 'Descripción larga',
        'short_description'   => 'Descripción corta',
    ];

    /**
     * Calcula el score SEO de un post (0-100).
     *
     * @param int    $post_id      ID del post.
     * @param string $write_target Plugin SEO activo.
     * @return int Score de 0 a 100.
     */
    public static function calculate( int $post_id, string $write_target = '' ): int {
        $desglose = self::breakdown( $post_id, $write_target );

        return $desglose['score'];
    }

    /**
     * Desglose del score: qué campos se están midiendo, cuáles están completos
     * y cuánto aporta cada uno.
     *
     * Es la función de cálculo real; calculate() solo se queda con el total.
     * Así el número de la tabla y el detalle que ve el usuario salen siempre de
     * la misma pasada: no hay dos lugares donde los pesos puedan desincronizarse.
     *
     * @param int    $post_id      ID del post.
     * @param string $write_target Plugin SEO activo.
     *
     * @return array{
     *     target: string, entorno: string, score: int, earned: int, max: int,
     *     fields: array<int, array{field: string, label: string, weight: int, earned: int, estado: string, nota: string, ia: bool}>
     * }
     */
    public static function breakdown( int $post_id, string $write_target = '' ): array {
        $write_target = self::resolve_target( $write_target );
        $post_type    = get_post_type( $post_id );

        // Los productos sin plugin SEO se miden por sus campos nativos de
        // WooCommerce; el resto, por los meta fields del plugin (o los
        // '_hcsai_*' propios cuando no hay ninguno).
        $fields = ( 'native' === $write_target && 'product' === $post_type )
            ? self::fields_native_product( $post_id )
            : self::fields_with_seo( $post_id, $write_target );

        $fields = self::mark_ai_fields( $fields, (string) $post_type );

        $earned = 0;
        $max    = 0;
        foreach ( $fields as $field ) {
            $earned += $field['earned'];
            $max    += $field['weight'];
        }

        return [
            'target'  => $write_target,
            'entorno' => self::environment_text( $write_target ),
            'score'   => self::normalize( $earned, $max ),
            'earned'  => $earned,
            'max'     => $max,
            'fields'  => $fields,
        ];
    }

    /**
     * Resuelve el destino de escritura cuando no se recibe explícito.
     */
    private static function resolve_target( string $write_target ): string {
        if ( ! empty( $write_target ) ) {
            return ( 'none' === $write_target ) ? 'native' : $write_target;
        }

        $active = PluginDetector::detect_active();

        return ( 'none' !== $active ) ? $active : 'native';
    }

    /**
     * Frase que explica qué se está midiendo, para no dejar el porcentaje solo.
     */
    private static function environment_text( string $write_target ): string {
        if ( 'native' === $write_target ) {
            return 'No hay plugin SEO activo: el score mide los campos nativos de WordPress.';
        }

        /* translators: %s: nombre del plugin SEO detectado. */
        return sprintf(
            'Plugin SEO detectado: %s. El score mide sus campos.',
            PluginDetector::display_name( $write_target )
        );
    }

    /**
     * Marca qué campos rellena la IA en esta instalación.
     *
     * Sale del mismo registro que el prompt, así que el desglose no promete
     * automatizar nada que luego no se pida.
     */
    private static function mark_ai_fields( array $fields, string $post_type ): array {
        $automaticos = Settings::fields_for( $post_type );

        foreach ( $fields as $i => $field ) {
            $fields[ $i ]['ia'] = in_array( $field['field'], $automaticos, true );
        }

        return $fields;
    }

    /**
     * Construye una entrada del desglose.
     *
     * @param string $key    Nombre lógico del campo.
     * @param int    $weight Puntos que aporta si está completo.
     * @param int    $earned Puntos obtenidos.
     * @param string $nota   Aclaración para el usuario (por qué no suma todo).
     */
    private static function entry( string $key, int $weight, int $earned, string $nota = '' ): array {
        if ( $earned >= $weight ) {
            $estado = 'ok';
        } elseif ( $earned > 0 ) {
            $estado = 'parcial';
        } else {
            $estado = 'vacio';
        }

        return [
            'field'  => $key,
            'label'  => self::LABELS[ $key ] ?? $key,
            'weight' => $weight,
            'earned' => $earned,
            'estado' => $estado,
            'nota'   => $nota,
            'ia'     => false,
        ];
    }

    /**
     * Convierte puntos obtenidos sobre puntos aplicables a una escala 0-100.
     *
     * Los campos que no aplican al contenido se excluyen del máximo en vez de
     * contar como no cumplidos: si no hay imagen destacada, el alt text no se
     * puede completar y no debería penalizar; los campos de WooCommerce no
     * aplican a un post. Así un contenido completo llega siempre a 100.
     */
    private static function normalize( int $earned, int $max ): int {
        if ( $max <= 0 ) {
            return 0;
        }

        return (int) min( 100, max( 0, round( $earned / $max * 100 ) ) );
    }

    /**
     * Campos evaluados en productos sin plugin SEO.
     */
    private static function fields_native_product( int $post_id ): array {
        $weights = self::WEIGHTS_NATIVE;
        $fields  = [];

        // Descripción del producto (post_content)
        $peso    = $weights['product_description'];
        $content = get_post_field( 'post_content', $post_id );
        $earned  = 0;
        $nota    = 'Vacía.';
        if ( ! empty( trim( $content ) ) ) {
            $len = mb_strlen( wp_strip_all_tags( $content ) );
            // Crédito parcial si es demasiado corta (menos de 150 chars)
            if ( $len >= 150 ) {
                $earned = $peso;
                $nota   = '';
            } else {
                $earned = intval( $peso * 0.5 );
                $nota   = 'Muy corta: a partir de 150 caracteres suma todos los puntos.';
            }
        }
        $fields[] = self::entry( 'product_description', $peso, $earned, $nota );

        // Descripción corta (post_excerpt)
        $peso    = $weights['short_description'];
        $excerpt = get_post_field( 'post_excerpt', $post_id );
        $earned  = 0;
        $nota    = 'Vacía.';
        if ( ! empty( trim( $excerpt ) ) ) {
            $len = mb_strlen( $excerpt );
            if ( $len >= 50 && $len <= 300 ) {
                $earned = $peso;
                $nota   = '';
            } else {
                $earned = intval( $peso * 0.7 );
                $nota   = 'Longitud fuera del rango recomendado (50-300 caracteres).';
            }
        }
        $fields[] = self::entry( 'short_description', $peso, $earned, $nota );

        $fields[] = self::entry_alt_text( $post_id, $weights['alt_text'] );
        $fields[] = self::entry_tags( $post_id, $weights['tags'], 'product' );

        return array_values( array_filter( $fields ) );
    }

    /**
     * Campos evaluados con plugin SEO activo, y en posts/páginas sin plugin.
     */
    private static function fields_with_seo( int $post_id, string $write_target ): array {
        $field_map = FieldMap::get( $write_target );
        $weights   = self::WEIGHTS_SEO;
        $fields    = [];

        // Para AIOSEO: obtener og_title/og_description desde tabla propia
        $aioseo_og = [];
        if ( 'aioseo' === $write_target ) {
            if ( class_exists( 'AIOSEO\Plugin\Common\Models\Post' ) ) {
                $ap = \AIOSEO\Plugin\Common\Models\Post::getPost( $post_id );
                $aioseo_og['og_title']       = $ap->og_title ?? '';
                $aioseo_og['og_description'] = $ap->og_description ?? '';
            }
        }

        // Evaluar campos mapeados
        foreach ( $field_map as $field_name => $meta_key ) {
            if ( ! isset( $weights[ $field_name ] ) ) {
                continue;
            }

            $peso = $weights[ $field_name ];

            // AIOSEO: og_title/og_description vienen de la tabla propia
            if ( 'aioseo' === $write_target && isset( $aioseo_og[ $field_name ] ) ) {
                $value = $aioseo_og[ $field_name ];
            } else {
                $value = get_post_meta( $post_id, $meta_key, true );
            }

            // AIOSEO focus_keyword: decodificar JSON para verificar si tiene valor real
            if ( 'aioseo' === $write_target && 'focus_keyword' === $field_name && ! empty( $value ) ) {
                $decoded = json_decode( $value, true );
                if ( is_array( $decoded ) && ! empty( $decoded[0]['keyphrase'] ) ) {
                    $value = $decoded[0]['keyphrase'];
                }
            }

            $earned = 0;
            $nota   = 'Vacío.';

            if ( ! empty( $value ) ) {
                $earned = $peso;
                $nota   = '';

                // Bonus por longitud adecuada
                if ( 'seo_title' === $field_name ) {
                    $len = mb_strlen( $value );
                    if ( $len < 30 || $len > 60 ) {
                        $earned = intval( $peso * 0.7 );
                        $nota   = 'Longitud fuera del rango recomendado (30-60 caracteres).';
                    }
                } elseif ( 'meta_description' === $field_name ) {
                    $len = mb_strlen( $value );
                    if ( $len < 120 || $len > 160 ) {
                        $earned = intval( $peso * 0.7 );
                        $nota   = 'Longitud fuera del rango recomendado (120-160 caracteres).';
                    }
                }
            }

            $fields[] = self::entry( $field_name, $peso, $earned, $nota );
        }

        $post_type = get_post_type( $post_id );

        $fields[] = self::entry_alt_text( $post_id, $weights['alt_text'] );
        $fields[] = self::entry_tags( $post_id, $weights['tags'], (string) $post_type );

        // Campos nativos de WooCommerce: solo aplican a productos
        if ( 'product' === $post_type ) {
            $peso     = self::WEIGHTS_WC['product_description'];
            $lleno    = ! empty( trim( get_post_field( 'post_content', $post_id ) ) );
            $fields[] = self::entry( 'product_description', $peso, $lleno ? $peso : 0, $lleno ? '' : 'Vacía.' );

            $peso     = self::WEIGHTS_WC['short_description'];
            $lleno    = ! empty( trim( get_post_field( 'post_excerpt', $post_id ) ) );
            $fields[] = self::entry( 'short_description', $peso, $lleno ? $peso : 0, $lleno ? '' : 'Vacía.' );
        }

        return array_values( array_filter( $fields ) );
    }

    /**
     * Alt text: solo aplica si hay imagen destacada.
     *
     * Sin imagen no se devuelve entrada, y entonces no suma al máximo: no se
     * puede penalizar por un campo que no existe.
     *
     * @return array|null
     */
    private static function entry_alt_text( int $post_id, int $peso ) {
        $thumb_id = get_post_thumbnail_id( $post_id );

        if ( ! $thumb_id ) {
            return null;
        }

        $alt = get_post_meta( $thumb_id, '_wp_attachment_image_alt', true );

        return self::entry( 'alt_text', $peso, empty( $alt ) ? 0 : $peso, empty( $alt ) ? 'Vacío.' : '' );
    }

    /**
     * Etiquetas del contenido.
     */
    private static function entry_tags( int $post_id, int $peso, string $post_type ): array {
        $taxonomy = ( 'product' === $post_type ) ? 'product_tag' : 'post_tag';
        $terms    = wp_get_post_terms( $post_id, $taxonomy );
        $tiene    = ( ! empty( $terms ) && ! is_wp_error( $terms ) );

        return self::entry( 'tags', $peso, $tiene ? $peso : 0, $tiene ? '' : 'Sin etiquetas.' );
    }
}
