<?php
/**
 * AJAX: Escaneo de scores SEO sin IA.
 * Lee los campos existentes, calcula el score real y lo guarda.
 * Funciona con contenido generado manualmente o con cualquier plugin SEO.
 *
 * @package HUP\CamposSeoIA\Ajax
 */

namespace HUP\CamposSeoIA\Ajax;

use HUP\CamposSeoIA\SEO\Scorer;
use HUP\CamposSeoIA\SEO\PluginDetector;
use HUP\CamposSeoIA\SEO\Status;

defined( 'ABSPATH' ) || exit;

class ScanHandler extends BaseHandler {

    protected function register_actions(): void {
        $this->add_ajax( 'scan',       'handle_scan' );
        $this->add_ajax( 'scan_ids',   'handle_scan_ids' );
        $this->add_ajax( 'scan_stats', 'handle_scan_stats' );
    }

    /**
     * Escanea un post individual: calcula score real y lo guarda.
     * No llama a la IA. Solo lee campos existentes.
     */
    public function handle_scan(): void {
        $this->verify_request();

        $post_id = $this->input_int( 'post_id' );

        if ( ! $post_id ) {
            wp_send_json_error( [ 'message' => 'ID de post requerido.' ] );
            return;
        }

        $post = get_post( $post_id );
        if ( ! $post ) {
            wp_send_json_error( [ 'message' => 'Post no encontrado.' ] );
            return;
        }

        $active_plugin = PluginDetector::detect_active();
        $write_target  = ( 'none' !== $active_plugin ) ? $active_plugin : 'native';

        // Calcular score leyendo campos reales (Yoast/RankMath/AIOSEO/native)
        $score = Scorer::calculate( $post_id, $write_target );

        // Determinar estado: IA, manual o pendiente.
        $status = Status::of( $post_id, $score );

        // Guardar score calculado
        update_post_meta( $post_id, '_hcsai_score', $score );

        // Si tiene contenido real pero nunca fue generado con IA,
        // marcar como escaneado para que no aparezca como "Pendiente"
        if ( 'manual' === $status ) {
            update_post_meta( $post_id, '_hcsai_scanned', current_time( 'mysql', true ) );
        }

        wp_send_json_success( [
            'post_id'    => $post_id,
            'score'      => $score,
            'status'     => $status,
            'plugin'     => $active_plugin,
        ] );
    }

    /**
     * Devuelve contadores actualizados de con/sin SEO para refrescar el dashboard.
     */
    public function handle_scan_stats(): void {
        $this->verify_request();

        $post_type_raw = $this->input( 'post_type', 'product' );
        $post_type     = in_array( $post_type_raw, [ 'product', 'post', 'page' ], true ) ? $post_type_raw : 'product';

        wp_send_json_success( array_merge( [ 'post_type' => $post_type ], Status::counts( $post_type ) ) );

    }

    /**
     * Cuántos IDs devuelve handle_scan_ids() por petición.
     *
     * El scan no consume IA, así que en vez de topar el total (como sí hace la
     * generación masiva) se pagina: el usuario puede escanear el catálogo
     * completo sin que ninguna petición cargue miles de IDs de golpe.
     */
    private const SCAN_PAGE_SIZE = 200;

    /**
     * Obtiene una página de IDs para el scan masivo.
     * Devuelve los posts del tipo solicitado sin filtrar por _hcsai_generated.
     *
     * La paginación es estable: el scan solo escribe _hcsai_score, no altera el
     * orden ni el post_status, así que la ventana no se desplaza entre páginas.
     */
    public function handle_scan_ids(): void {
        $this->verify_request();

        // Límite alto: recorrer un catálogo grande son muchas páginas seguidas,
        // y cada una es solo una query de IDs sin coste de IA.
        if ( ! $this->check_rate_limit( 'scan_ids', 120, 60 ) ) {
            wp_send_json_error( [ 'message' => 'Demasiadas peticiones. Espera un momento.' ] );
        }

        $post_type_raw = $this->input( 'post_type', 'product' );
        $post_type     = in_array( $post_type_raw, [ 'product', 'post', 'page' ], true ) ? $post_type_raw : 'product';
        $paged         = max( 1, $this->input_int( 'paged', 1 ) );

        $query = new \WP_Query( [
            'post_type'      => $post_type,
            'post_status'    => [ 'publish', 'draft' ],
            'posts_per_page' => self::SCAN_PAGE_SIZE,
            'paged'          => $paged,
            'fields'         => 'ids',
            'no_found_rows'  => false,
        ] );

        wp_send_json_success( [
            'ids'   => $query->posts,
            'total' => (int) $query->found_posts,
            'page'  => $paged,
            'pages' => (int) $query->max_num_pages,
        ] );
    }
}
