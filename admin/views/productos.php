<?php
/**
 * Vista de administración.
 *
 * Las vistas se cargan con `require` DENTRO de un método de Admin\Menu, así que
 * las variables de este archivo son locales a ese método, no globales: el sniff
 * de prefijos no puede verlo desde aquí y marcaría cada una como global sin
 * prefijo. Los $_GET son filtros, orden y paginación de un listado de solo
 * lectura —no cambian estado, así que no llevan nonce— y todos van sanitizados
 * al leerlos. meta_query es la forma soportada de filtrar por los campos que
 * escriben los plugins SEO, y el listado va paginado.
 *
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
 * phpcs:disable WordPress.Security.NonceVerification.Recommended
 * phpcs:disable WordPress.DB.SlowDBQuery
 *
 * @package HUP\CamposSeoIA
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WooCommerce' ) ) {
    require __DIR__ . '/partials/layout-open.php';
    echo '<div class="hup-notice hup-notice--warning"><span class="dashicons dashicons-warning" aria-hidden="true"></span><p>WooCommerce no está activo. Esta sección requiere WooCommerce.</p></div>';
    require __DIR__ . '/partials/layout-close.php';
    return;
}

$seo_plugin   = \HUP\CamposSeoIA\SEO\PluginDetector::detect_active();

// Paginación y filtros
$paged           = max( 1, absint( $_GET['paged'] ?? 1 ) );
$per_page_opts   = [ 10, 20, 50, 100 ];
$per_page        = in_array( absint( $_GET['per_page'] ?? 20 ), $per_page_opts, true ) ? absint( $_GET['per_page'] ?? 20 ) : 20;
$search          = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
$filter_cat      = absint( $_GET['cat'] ?? 0 );
$filter_seo      = sanitize_text_field( wp_unslash( $_GET['seo_status'] ?? '' ) );
$filter_score_lv = sanitize_key( $_GET['score_level'] ?? '' );  // '', '0','1','2','3'

$args = [
    'post_type'      => 'product',
    'post_status'    => [ 'publish', 'draft' ],
    'posts_per_page' => $per_page,
    'paged'          => $paged,
];

if ( ! empty( $search ) ) {
    $args['s'] = $search;
}
if ( $filter_cat > 0 ) {
    $args['tax_query'] = [ [ 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $filter_cat ] ];
}
// Meta key del plugin SEO activo para detectar "con SEO"
$seo_title_key = \HUP\CamposSeoIA\SEO\FieldMap::key( $seo_plugin, 'seo_title' );
// Todos los meta keys del plugin SEO activo (para columna de campos llenos)
$seo_field_map = \HUP\CamposSeoIA\SEO\FieldMap::get( $seo_plugin );

// Clausulas de meta_query según filtro
if ( 'pending' === $filter_seo ) {
    // Sin SEO: ni score guardado ni campo del plugin SEO lleno
    $mq = [ 'relation' => 'AND',
        [ 'key' => '_hcsai_score', 'compare' => 'NOT EXISTS' ],
    ];
    if ( ! empty( $seo_title_key ) ) {
        $mq[] = [ 'relation' => 'OR',
            [ 'key' => $seo_title_key, 'compare' => 'NOT EXISTS' ],
            [ 'key' => $seo_title_key, 'value' => '', 'compare' => '=' ],
        ];
    }
    $args['meta_query'] = $mq;
} elseif ( 'done' === $filter_seo ) {
    // Con SEO: score guardado > 0 O campo del plugin SEO lleno
    $mq = [ 'relation' => 'OR',
        [ 'key' => '_hcsai_score', 'value' => '0', 'compare' => '>', 'type' => 'NUMERIC' ],
    ];
    if ( ! empty( $seo_title_key ) ) {
        $mq[] = [ 'key' => $seo_title_key, 'value' => '', 'compare' => '!=' ];
    }
    $args['meta_query'] = $mq;
} elseif ( 'manual' === $filter_seo ) {
    // Manual: tiene SEO (score>0 o campo del plugin) pero NO lo generó la IA.
    //
    // La condición "no lo generó la IA" se expresa con NOT EXISTS dentro de la
    // misma meta_query. Antes se resolvía cargando TODOS los IDs con
    // _hcsai_generated y pasándolos a post__not_in: en un catálogo grande eso
    // construía un NOT IN (...) con miles de IDs en cada carga de la página.
    $tiene_seo = [ 'relation' => 'OR',
        [ 'key' => '_hcsai_score', 'value' => '0', 'compare' => '>', 'type' => 'NUMERIC' ],
    ];
    if ( ! empty( $seo_title_key ) ) {
        $tiene_seo[] = [ 'key' => $seo_title_key, 'value' => '', 'compare' => '!=' ];
    }
    $args['meta_query'] = [ 'relation' => 'AND',
        [ 'key' => '_hcsai_generated', 'compare' => 'NOT EXISTS' ],
        $tiene_seo,
    ];
} elseif ( 'ai' === $filter_seo ) {
    $args['meta_query'] = [ [ 'key' => '_hcsai_generated', 'compare' => 'EXISTS' ] ];
}

// Filtro por nivel de score
if ( '' !== $filter_score_lv && in_array( $filter_score_lv, [ '0', '1', '2', '3' ], true ) ) {
    $lv   = (int) $filter_score_lv;
    $min  = $lv * 25;          // 0,25,50,75
    $max  = $lv * 25 + 24;     // 24,49,74,99 — nivel 3 = 75-100
    if ( 3 === $lv ) { $max = 100; }
    $score_lv_clause = [ 'relation' => 'AND',
        [ 'key' => '_hcsai_score', 'value' => $min, 'compare' => '>=', 'type' => 'NUMERIC' ],
        [ 'key' => '_hcsai_score', 'value' => $max, 'compare' => '<=', 'type' => 'NUMERIC' ],
    ];
    if ( isset( $args['meta_query'] ) ) {
        $args['meta_query'] = [ 'relation' => 'AND', $args['meta_query'], $score_lv_clause ];
    } else {
        $args['meta_query'] = $score_lv_clause;
    }
}

// Ordenamiento
$orderby_raw = sanitize_key( $_GET['orderby'] ?? '' );
$order_raw   = ( 'desc' === sanitize_key( $_GET['order'] ?? '' ) ) ? 'DESC' : 'ASC';
$hcsai_score_filter = null;
if ( 'score' === $orderby_raw ) {
    // LEFT JOIN para incluir posts sin _hcsai_score (aparecen con valor 0)
    $order_raw_sql      = $order_raw;
    $hcsai_score_filter = function ( $clauses ) use ( $order_raw_sql ) {
        global $wpdb;
        $clauses['join']   .= " LEFT JOIN {$wpdb->postmeta} AS hcsai_score_meta ON ({$wpdb->posts}.ID = hcsai_score_meta.post_id AND hcsai_score_meta.meta_key = '_hcsai_score')";
        $clauses['orderby'] = "CAST(COALESCE(hcsai_score_meta.meta_value, 0) AS SIGNED) {$order_raw_sql}, {$wpdb->posts}.post_title ASC";
        return $clauses;
    };
    add_filter( 'posts_clauses', $hcsai_score_filter, 10 );
} elseif ( 'title' === $orderby_raw ) {
    $args['orderby'] = 'title';
    $args['order']   = $order_raw;
} else {
    $args['orderby'] = 'title';
    $args['order']   = 'ASC';
}

$query      = new WP_Query( $args );
$categories = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => true ] );
if ( null !== $hcsai_score_filter ) {
    remove_filter( 'posts_clauses', $hcsai_score_filter, 10 );
}

require __DIR__ . '/partials/layout-open.php';
?>

<?php require __DIR__ . '/partials/entorno-seo.php'; ?>

<?php
/**
 * Punto de extensión de la interfaz. Esta versión completa los campos de a un
 * producto por vez, con el botón "Completar" de cada fila de la tabla.
 */
do_action( 'hcsai_admin_after_table', 'product' );
?>


<!-- Tabla de productos -->
<div class="hup-card" id="hcsai-scan">
    <div class="hup-card__head">
        <h3 class="hup-card__title">Productos</h3>
    </div>
    <div class="hup-card__body">

        <form class="hup-seo-filters" method="get">
            <input type="hidden" name="page" value="hcsai">
            <input type="hidden" name="tab" value="productos">
            <input type="search" name="s" class="hup-input" placeholder="Buscar productos..." aria-label="Buscar productos" value="<?php echo esc_attr( $search ); ?>">
            <select name="cat" class="hup-select">
                <option value="">Todas las categorías</option>
                <?php if ( is_array( $categories ) ) : foreach ( $categories as $cat ) : ?>
                <option value="<?php echo esc_attr( $cat->term_id ); ?>" <?php selected( $filter_cat, $cat->term_id ); ?>><?php echo esc_html( $cat->name ); ?></option>
                <?php endforeach; endif; ?>
            </select>
            <select name="seo_status" class="hup-select">
                <option value="">Estado SEO</option>
                <option value="pending" <?php selected( $filter_seo, 'pending' ); ?>>Sin SEO (0%)</option>
                <option value="done" <?php selected( $filter_seo, 'done' ); ?>>Con SEO</option>
                <option value="manual" <?php selected( $filter_seo, 'manual' ); ?>>Manual</option>
                <option value="ai" <?php selected( $filter_seo, 'ai' ); ?>>Generado con IA</option>
            </select>
            <select name="score_level" class="hup-select">
                <option value="">Score: todos</option>
                <option value="0" <?php selected( $filter_score_lv, '0' ); ?>>0–24%</option>
                <option value="1" <?php selected( $filter_score_lv, '1' ); ?>>25–49%</option>
                <option value="2" <?php selected( $filter_score_lv, '2' ); ?>>50–74%</option>
                <option value="3" <?php selected( $filter_score_lv, '3' ); ?>>75–100%</option>
            </select>
            <button type="submit" class="hup-btn hup-btn--secondary">Filtrar</button>
            <button type="button" class="hup-btn hup-btn--secondary" id="btn-scan-products" data-post-type="product" title="Lee los campos SEO existentes sin usar IA">
                <span class="dashicons dashicons-search" aria-hidden="true"></span>Escanear productos
            </button>
        </form>


        <?php if ( $query->have_posts() ) : ?>
        <div class="hcsai-table-scroll"><table class="hup-table">
            <thead>
<?php
$orderby = sanitize_key( $_GET['orderby'] ?? '' );
$order   = ( 'desc' === sanitize_key( $_GET['order'] ?? '' ) ) ? 'desc' : 'asc';
$next_order = ( 'asc' === $order ) ? 'desc' : 'asc';
if ( ! function_exists( 'hcsai_sort_url' ) ) {
    function hcsai_sort_url( $col ) {
        $cur_orderby = sanitize_key( $_GET['orderby'] ?? '' );
        $cur_order   = sanitize_key( $_GET['order'] ?? 'asc' );
        $next = ( $col === $cur_orderby && 'asc' === $cur_order ) ? 'desc' : 'asc';
        return add_query_arg( [ 'orderby' => $col, 'order' => $next ] );
    }
}
if ( ! function_exists( 'hcsai_sort_arrow' ) ) {
    function hcsai_sort_arrow( $col ) {
        $cur_orderby = sanitize_key( $_GET['orderby'] ?? '' );
        $cur_order   = sanitize_key( $_GET['order'] ?? 'asc' );
        if ( $col !== $cur_orderby ) return ' <span class="hcsai-sort-arrow">&#8597;</span>';
        return 'asc' === $cur_order ? ' <span class="hcsai-sort-arrow">&#8593;</span>' : ' <span class="hcsai-sort-arrow">&#8595;</span>';
    }
}
?>
                <tr>
                    <th><a class="hcsai-sort-link" href="<?php echo esc_url( hcsai_sort_url( 'title' ) ); ?>">Producto<?php echo wp_kses_post( hcsai_sort_arrow( 'title' ) ); ?></a></th>
                    <th>Categoría</th>
                    <th><a class="hcsai-sort-link" href="<?php echo esc_url( hcsai_sort_url( 'score' ) ); ?>">Score<?php echo wp_kses_post( hcsai_sort_arrow( 'score' ) ); ?></a></th>
                    <th>Generado</th>
                    <th>Campos completos</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php while ( $query->have_posts() ) : $query->the_post(); global $post;
                    // Score en vivo: si ya fue escaneado usa el guardado, si no calcula ahora
                    $saved_score = get_post_meta( $post->ID, '_hcsai_score', true );
                    $score       = ( '' !== $saved_score ) ? (int) $saved_score : \HUP\CamposSeoIA\SEO\Scorer::calculate( $post->ID );
                    $generated   = get_post_meta( $post->ID, '_hcsai_generated', true );
                    $scanned     = get_post_meta( $post->ID, '_hcsai_scanned', true );
                    $cats        = wp_get_post_terms( $post->ID, 'product_cat', [ 'fields' => 'names' ] );
                    // Campos llenos
                    $thumb_id    = get_post_thumbnail_id( $post->ID );
                    $cf = [
                        'product_description' => ! empty( trim( get_post_field( 'post_content', $post->ID ) ) ),
                        'short_description'   => ! empty( trim( get_post_field( 'post_excerpt', $post->ID ) ) ),
                        'tags'                => ! is_wp_error( wp_get_post_terms( $post->ID, 'product_tag' ) ) && ! empty( wp_get_post_terms( $post->ID, 'product_tag' ) ),
                        'alt_text'            => $thumb_id && ! empty( get_post_meta( $thumb_id, '_wp_attachment_image_alt', true ) ),
                    ];
                    if ( 'none' !== $seo_plugin ) :
                        foreach ( $seo_field_map as $sf_key => $sf_meta ) :
                            if ( in_array( $sf_key, [ 'seo_title', 'meta_description', 'focus_keyword', 'og_title', 'og_description' ], true ) ) :
                                $cf[ $sf_key ] = ! empty( get_post_meta( $post->ID, $sf_meta, true ) );
                            endif;
                        endforeach;
                    endif;
                ?>
                <tr data-post-id="<?php echo esc_attr( $post->ID ); ?>">
                    <td>
                        <a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>">
                            <?php echo esc_html( $post->post_title ); ?>
                        </a>
                    </td>
                    <td><?php echo esc_html( is_array( $cats ) ? implode( ', ', $cats ) : '' ); ?></td>
                    <?php $score_level = min(3, floor($score / 25)); // 0-3 niveles: 0-24, 25-49, 50-74, 75-100 ?>
                    <td>
                        <button type="button" class="hup-seo-score hup-seo-score--link hup-seo-score-score-<?php echo esc_attr( $score_level ); ?>"
                                data-score="<?php echo esc_attr( $score_level ); ?>" data-id="<?php echo esc_attr( $post->ID ); ?>"
                                title="Ver qué campos suman a este score">
                            <?php echo esc_html( $score ); ?>%
                        </button>
                    </td>
                    <td data-hcsai-col="generated">
                        <?php if ( $generated ) : ?>
                            <span class="hup-badge hup-badge--success hup-badge--dot" title="Generado con IA el <?php echo esc_attr( $generated ); ?>">IA</span>
                        <?php elseif ( $scanned && $score > 0 ) : ?>
                            <span class="hup-badge hup-badge--dot" title="Contenido manual escaneado el <?php echo esc_attr( $scanned ); ?>">Manual</span>
                        <?php elseif ( $score > 0 ) : ?>
                            <span class="hup-badge hup-badge--dot" title="Tiene contenido SEO — haz clic en Escanear para actualizar">Manual</span>
                        <?php else : ?>
                            <span class="hup-badge hup-badge--warning">Pendiente</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="hup-badge <?php echo count( array_filter( $cf ) ) === count( $cf ) ? 'hup-badge--success' : 'hup-badge--warning'; ?>"><?php echo esc_html( count( array_filter( $cf ) ) ); ?> de <?php echo esc_html( count( $cf ) ); ?></span></td>
                    <td>
                        <button type="button" class="hup-btn hup-btn--sm hup-btn--secondary hup-seo-gen-single" data-id="<?php echo esc_attr( $post->ID ); ?>">Completar</button>
                        <button type="button" class="hup-btn hup-btn--sm hup-btn--secondary hup-seo-edit-seo" data-id="<?php echo esc_attr( $post->ID ); ?>">Editar</button>
                    </td>
                </tr>
                <?php endwhile; wp_reset_postdata(); ?>
            </tbody>
        </table></div>

        <!-- Paginación + por página -->
        <div class="hup-pager">
            <div class="hup-pager__links">
            <?php
            $total_pages = $query->max_num_pages;
            if ( $total_pages > 1 ) :
            $paginate_base = add_query_arg( array_filter( [
                'page'        => 'hcsai',
                'tab'         => 'productos',
                's'           => $search ?: null,
                'cat'         => $filter_cat ?: null,
                'seo_status'  => $filter_seo ?: null,
                'score_level' => $filter_score_lv ?: null,
                'per_page'    => ( $per_page !== 20 ) ? $per_page : null,
                'orderby'     => $orderby_raw ?: null,
                'order'       => sanitize_key( $_GET['order'] ?? '' ) ?: null,
                'paged'       => '%#%',
            ] ) );
            echo wp_kses_post( paginate_links( [
                'base'    => $paginate_base,
                'format'  => '',
                'current' => $paged,
                'total'   => $total_pages,
            ] ) );
            endif;
            ?>
            </div>
            <form method="get" class="hup-inline">
                <input type="hidden" name="page" value="hcsai">
                <input type="hidden" name="tab" value="productos">
                <?php if ( $search ) : ?><input type="hidden" name="s" value="<?php echo esc_attr( $search ); ?>"><?php endif; ?>
                <?php if ( $filter_cat ) : ?><input type="hidden" name="cat" value="<?php echo esc_attr( $filter_cat ); ?>"><?php endif; ?>
                <?php if ( $filter_seo ) : ?><input type="hidden" name="seo_status" value="<?php echo esc_attr( $filter_seo ); ?>"><?php endif; ?>
                <?php if ( $filter_score_lv ) : ?><input type="hidden" name="score_level" value="<?php echo esc_attr( $filter_score_lv ); ?>"><?php endif; ?>
                <label class="hup-label" for="hcsai-per-page">Por página</label>
                <select name="per_page" id="hcsai-per-page" class="hup-select hup-input--sm" onchange="this.form.submit()">
                    <?php foreach ( $per_page_opts as $pp ) : ?>
                    <option value="<?php echo esc_attr( $pp ); ?>" <?php selected( $per_page, $pp ); ?>><?php echo esc_html( $pp ); ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>

        <?php else : ?>
            <div class="hup-empty"><div class="hup-empty__icon"><span class="dashicons dashicons-products" aria-hidden="true"></span></div><div class="hup-empty__title">No se encontraron productos</div><p>Prueba con otros filtros o revisa tus productos en WooCommerce.</p></div>
        <?php endif; ?>
    </div>
</div>
<?php
// El modal es idéntico en productos y blog salvo los textos: vive en un partial
// para que no haya que cambiarlo dos veces.
$hcsai_modal_tipo = 'product';
require __DIR__ . '/partials/modal-campos.php';
?>

<?php require __DIR__ . '/partials/layout-close.php'; ?>
