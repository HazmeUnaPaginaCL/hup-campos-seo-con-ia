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

$paged      = max( 1, absint( $_GET['paged'] ?? 1 ) );
$per_page   = 20;
$search     = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
$filter_cat = absint( $_GET['cat'] ?? 0 );
$filter_seo = sanitize_text_field( wp_unslash( $_GET['seo_status'] ?? '' ) );

$args = [
    'post_type'      => 'post',
    'post_status'    => [ 'publish', 'draft' ],
    'posts_per_page' => $per_page,
    'paged'          => $paged,
];

if ( ! empty( $search ) ) {
    $args['s'] = $search;
}
if ( $filter_cat > 0 ) {
    $args['cat'] = $filter_cat;
}
if ( 'pending' === $filter_seo ) {
    $args['meta_query'] = [ [ 'key' => '_hcsai_generated', 'compare' => 'NOT EXISTS' ] ];
} elseif ( 'done' === $filter_seo ) {
    $args['meta_query'] = [ [ 'key' => '_hcsai_generated', 'compare' => 'EXISTS' ] ];
}

$query      = new WP_Query( $args );
$categories = get_categories( [ 'hide_empty' => false ] );

require __DIR__ . '/partials/layout-open.php';
?>

<h1 class="hup-seo-page-title">Blog</h1>

<?php require __DIR__ . '/partials/entorno-seo.php'; ?>

<!-- Crear post -->
<div class="hup-seo-card">
    <div class="hup-seo-card-header"><h3>Crear post con IA</h3></div>
    <div class="hup-seo-card-body">
        <div class="hup-seo-create-form">
            <div class="hup-seo-form-row">
                <label class="hup-seo-form-label">Tema / Keyword</label>
                <div class="hup-seo-form-field">
                    <input type="text" id="create-topic" class="hup-seo-input" placeholder="Ej: Beneficios de la proteína whey...">
                </div>
            </div>
            <div class="hup-seo-form-row hup-seo-form-row-3col">
                <div class="hup-seo-form-col">
                    <label class="hup-seo-form-label">Tono</label>
                    <select id="create-tone" class="hup-seo-select">
                        <option value="informativo">Informativo</option>
                        <option value="profesional">Profesional</option>
                        <option value="casual">Casual</option>
                    </select>
                </div>
                <div class="hup-seo-form-col">
                    <label class="hup-seo-form-label">Extensión</label>
                    <select id="create-length" class="hup-seo-select">
                        <option value="short">Corto (~500 palabras)</option>
                        <option value="medium" selected>Mediano (~1000 palabras)</option>
                        <option value="long">Largo (~2000 palabras)</option>
                    </select>
                </div>
                <div class="hup-seo-form-col">
                    <label class="hup-seo-form-label">Categoría</label>
                    <select id="create-category" class="hup-seo-select">
                        <option value="">Sin categoría</option>
                        <?php foreach ( $categories as $cat ) : ?>
                        <option value="<?php echo esc_attr( $cat->term_id ); ?>"><?php echo esc_html( $cat->name ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
        <div class="hup-seo-batch-actions">
            <button type="button" class="hup-seo-btn hup-seo-btn-primary" id="btn-create-post">Crear post con IA</button>
        </div>
        <div class="hup-seo-create-result" style="display:none;"></div>
    </div>
</div>

<!-- Mejorar post -->
<div class="hup-seo-card">
    <div class="hup-seo-card-header"><h3>Mejorar post existente</h3></div>
    <div class="hup-seo-card-body">
        <div class="hup-seo-form-row">
            <label class="hup-seo-form-label">Seleccionar post</label>
            <div class="hup-seo-form-field">
                <select id="improve-post-id" class="hup-seo-select">
                    <option value="">Seleccionar...</option>
                    <?php
                    $posts_list = get_posts( [ 'post_type' => 'post', 'post_status' => 'publish', 'numberposts' => 100 ] );
                    foreach ( $posts_list as $p ) :
                    ?>
                    <option value="<?php echo esc_attr( $p->ID ); ?>"><?php echo esc_html( $p->post_title ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="hup-seo-form-row">
            <label class="hup-seo-form-label">Opciones</label>
            <div class="hup-seo-form-field hup-seo-checkboxes">
                <label><input type="checkbox" id="improve-content" checked> Mejorar contenido</label>
                <label><input type="checkbox" id="improve-seo" checked> Mejorar SEO</label>
                <label><input type="checkbox" id="improve-cta"> Agregar CTA</label>
                <label><input type="checkbox" id="improve-tags" checked> Mejorar tags</label>
            </div>
        </div>
        <div class="hup-seo-batch-actions">
            <button type="button" class="hup-seo-btn hup-seo-btn-primary" id="btn-improve-post">Mejorar con IA</button>
        </div>
        <div class="hup-seo-improve-result" style="display:none;"></div>
    </div>
</div>

<?php
/**
 * Punto de extensión de la interfaz. Esta versión completa los campos de a un
 * post por vez, con el botón "Completar" de cada fila de la tabla.
 */
do_action( 'hcsai_admin_after_table', 'post' );
?>

<!-- Tabla posts -->
<div class="hup-seo-card">
    <div class="hup-seo-card-header"><h3>Posts</h3></div>
    <div class="hup-seo-card-body">
        <form class="hup-seo-filters" method="get">
            <input type="hidden" name="page" value="hcsai-blog">
            <input type="text" name="s" class="hup-seo-input" placeholder="Buscar..." value="<?php echo esc_attr( $search ); ?>">
            <select name="cat" class="hup-seo-select">
                <option value="">Todas</option>
                <?php foreach ( $categories as $cat ) : ?>
                <option value="<?php echo esc_attr( $cat->term_id ); ?>" <?php selected( $filter_cat, $cat->term_id ); ?>><?php echo esc_html( $cat->name ); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="seo_status" class="hup-seo-select">
                <option value="">Todos</option>
                <option value="pending" <?php selected( $filter_seo, 'pending' ); ?>>Sin SEO</option>
                <option value="done" <?php selected( $filter_seo, 'done' ); ?>>Con SEO</option>
            </select>
            <button type="submit" class="hup-seo-btn hup-seo-btn-outline">Filtrar</button>
        </form>

        <?php if ( $query->have_posts() ) : ?>
        <table class="hup-seo-table">
            <thead>
                <tr>
                    <th>Post</th>
                    <th>Categoría</th>
                    <th>Score</th>
                    <th>Generado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php while ( $query->have_posts() ) : $query->the_post(); global $post;
                    $score     = (int) get_post_meta( $post->ID, '_hcsai_score', true );
                    $generated = get_post_meta( $post->ID, '_hcsai_generated', true );
                    $cats      = get_the_category( $post->ID );
                    $cat_names = ! empty( $cats ) ? implode( ', ', wp_list_pluck( $cats, 'name' ) ) : '';
                    $score_level = min(3, floor($score / 25)); // 0-3 niveles: 0-24, 25-49, 50-74, 75-100
                ?>
                <tr data-post-id="<?php echo esc_attr( $post->ID ); ?>">
                    <td><a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>"><?php echo esc_html( $post->post_title ); ?></a></td>
                    <td><?php echo esc_html( $cat_names ); ?></td>
                    <td>
                        <button type="button" class="hup-seo-score hup-seo-score--link hup-seo-score-score-<?php echo esc_attr( $score_level ); ?>"
                                data-score="<?php echo esc_attr( $score_level ); ?>" data-id="<?php echo esc_attr( $post->ID ); ?>"
                                title="Ver qué campos suman a este score">
                            <?php echo esc_html( $score ); ?>%
                        </button>
                    </td>
                    <td><?php echo $generated ? esc_html( $generated ) : '<span class="hup-seo-badge hup-seo-badge-warn">Pendiente</span>'; ?></td>
                    <td>
                        <button type="button" class="hup-seo-btn hup-seo-btn-sm hup-seo-btn-primary hup-seo-gen-single" data-id="<?php echo esc_attr( $post->ID ); ?>">Completar</button>
                        <button type="button" class="hup-seo-btn hup-seo-btn-sm hup-seo-btn-outline hup-seo-edit-seo" data-id="<?php echo esc_attr( $post->ID ); ?>">Editar</button>
                    </td>
                </tr>
                <?php endwhile; wp_reset_postdata(); ?>
            </tbody>
        </table>

        <?php
        $total_pages = $query->max_num_pages;
        if ( $total_pages > 1 ) :
        ?>
        <div class="hup-seo-pagination">
            <?php echo wp_kses_post( paginate_links( [ 'base' => add_query_arg( 'paged', '%#%' ), 'current' => $paged, 'total' => $total_pages ] ) ); ?>
        </div>
        <?php endif; ?>

        <?php else : ?>
            <p class="hup-seo-empty">No se encontraron posts.</p>
        <?php endif; ?>
    </div>
</div>

<?php
// Mismo modal que en Productos: sólo cambian los textos del tipo de contenido.
$hcsai_modal_tipo = 'post';
require __DIR__ . '/partials/modal-campos.php';
?>

<?php require __DIR__ . '/partials/layout-close.php'; ?>
