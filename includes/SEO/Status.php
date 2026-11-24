<?php
/**
 * Estado SEO de un contenido: con SEO, sin SEO, manual o generado con IA.
 *
 * Fuente única para el Dashboard, los listados, sus filtros y el escaneo. Antes
 * cada pantalla tenía su propio criterio y mostraban números distintos.
 *
 * Filtrar por el score guardado requiere meta_query; los listados van paginados
 * y los conteos son del panel de administración.
 *
 * phpcs:disable WordPress.DB.SlowDBQuery
 *
 * @package HUP\CamposSeoIA\SEO
 */

namespace HUP\CamposSeoIA\SEO;

defined( 'ABSPATH' ) || exit;

class Status {

    /** Estados de publicación que el plugin lista y cuenta. */
    private const POST_STATUS = [ 'publish', 'draft' ];

    /**
     * Score de un contenido. Si nunca se calculó, lo calcula y lo guarda, para
     * que el número de la tabla y los filtros salgan del mismo dato.
     */
    public static function score( int $post_id ): int {
        $saved = get_post_meta( $post_id, '_hcsai_score', true );

        if ( '' !== $saved ) {
            return (int) $saved;
        }

        $score = Scorer::calculate( $post_id );
        update_post_meta( $post_id, '_hcsai_score', $score );

        return $score;
    }

    /**
     * Estado que muestra la columna «Generado».
     *
     * @return string 'ai', 'manual' o 'pending'.
     */
    public static function of( int $post_id, int $score ): string {
        if ( get_post_meta( $post_id, '_hcsai_generated', true ) ) {
            return 'ai';
        }

        return $score > 0 ? 'manual' : 'pending';
    }

    /**
     * Cláusula meta_query de un filtro de estado.
     *
     * @param string $estado 'pending', 'done', 'manual' o 'ai'.
     * @return array Vacío si el filtro no existe.
     */
    public static function meta_query( string $estado ): array {
        $con_seo = [ 'key' => '_hcsai_score', 'value' => '0', 'compare' => '>', 'type' => 'NUMERIC' ];

        switch ( $estado ) {
            case 'pending':
                return [
                    'relation' => 'OR',
                    [ 'key' => '_hcsai_score', 'compare' => 'NOT EXISTS' ],
                    [ 'key' => '_hcsai_score', 'value' => '0', 'compare' => '<=', 'type' => 'NUMERIC' ],
                ];
            case 'done':
                return [ $con_seo ];
            case 'manual':
                return [
                    'relation' => 'AND',
                    [ 'key' => '_hcsai_generated', 'compare' => 'NOT EXISTS' ],
                    $con_seo,
                ];
            case 'ai':
                return [ [ 'key' => '_hcsai_generated', 'compare' => 'EXISTS' ] ];
        }

        return [];
    }

    /**
     * Cuántos contenidos hay con y sin SEO.
     *
     * @return array{total: int, with_seo: int, without_seo: int, unscanned: int}
     */
    public static function counts( string $post_type ): array {
        $total    = self::count( $post_type, [] );
        $with_seo = self::count( $post_type, self::meta_query( 'done' ) );

        return [
            'total'       => $total,
            'with_seo'    => $with_seo,
            'without_seo' => max( 0, $total - $with_seo ),
            'unscanned'   => self::count( $post_type, [ [ 'key' => '_hcsai_score', 'compare' => 'NOT EXISTS' ] ] ),
        ];
    }

    /**
     * Calcula el score de los contenidos que aún no lo tienen. No usa IA.
     *
     * Va por tandas y con tope de tiempo: en un catálogo grande sin escanear, la
     * página no se queda esperando y el resto lo completa «Escanear».
     */
    public static function fill_missing( string $post_type, int $limit = 100 ): void {
        $ids = get_posts( [
            'post_type'        => $post_type,
            'post_status'      => self::POST_STATUS,
            'posts_per_page'   => $limit,
            'fields'           => 'ids',
            'orderby'          => 'ID',
            'suppress_filters' => false,
            'meta_query'       => [ [ 'key' => '_hcsai_score', 'compare' => 'NOT EXISTS' ] ],
        ] );

        if ( empty( $ids ) ) {
            return;
        }

        _prime_post_caches( $ids, true, true );
        $fin = microtime( true ) + 2;

        foreach ( $ids as $id ) {
            self::score( (int) $id );

            if ( microtime( true ) > $fin ) {
                break;
            }
        }
    }

    /**
     * Cuenta con WP_Query, igual que los listados, para que los plugins que los
     * filtran (por ejemplo, los de idiomas) afecten por igual a los dos números.
     */
    private static function count( string $post_type, array $meta_query ): int {
        $args = [
            'post_type'      => $post_type,
            'post_status'    => self::POST_STATUS,
            'posts_per_page' => 1,
            'fields'         => 'ids',
        ];

        if ( ! empty( $meta_query ) ) {
            $args['meta_query'] = $meta_query;
        }

        return (int) ( new \WP_Query( $args ) )->found_posts;
    }
}
