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

<?php require __DIR__ . '/partials/entorno-seo.php'; ?>

<!-- Crear post -->
<div class="hup-card">
    <div class="hup-card__head"><h3 class="hup-card__title">Crear post con IA</h3></div>
    <div class="hup-card__body">
        <div class="hup-seo-create-form">
            <div class="hup-field">
                <label class="hup-label" for="create-topic">Tema / Keyword</label>
                <div class="hup-control">
                    <input type="text" id="create-topic" class="hup-input" placeholder="Ej: Beneficios de la proteína whey...">
                </div>
            </div>
            <div class="hup-grid hup-grid--3">
                <div class="hup-field hup-field--stacked">
                    <label class="hup-label" for="create-tone">Tono</label>
                    <select id="create-tone" class="hup-select">
                        <option value="informativo">Informativo</option>
                        <option value="profesional">Profesional</option>
                        <option value="casual">Casual</option>
                    </select>
                </div>
                <div class="hup-field hup-field--stacked">
                    <label class="hup-label" for="create-length">Extensión</label>
                    <select id="create-length" class="hup-select">
                        <option value="short">Corto (~500 palabras)</option>
                        <option value="medium" selected>Mediano (~1000 palabras)</option>
                        <option value="long">Largo (~2000 palabras)</option>
                    </select>
                </div>
                <div class="hup-field hup-field--stacked">
                    <label class="hup-label" for="create-category">Categoría</label>
                    <select id="create-category" class="hup-select">
                        <option value="">Sin categoría</option>
                        <?php foreach ( $categories as $cat ) : ?>
                        <option value="<?php echo esc_attr( $cat->term_id ); ?>"><?php echo esc_html( $cat->name ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
        <div class="hup-seo-create-result" hidden></div>
    </div>
    <div class="hup-card__foot"><button type="button" class="hup-btn" id="btn-create-post">Crear post con IA</button></div>
</div>

<!-- Mejorar post -->
<div class="hup-card">
    <div class="hup-card__head"><h3 class="hup-card__title">Mejorar post existente</h3></div>
    <div class="hup-card__body">
        <div class="hup-field">
            <label class="hup-label" for="improve-post-id">Seleccionar post</label>
            <div class="hup-control">
                <select id="improve-post-id" class="hup-select">
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
        <div class="hup-field">
            <label class="hup-label">Opciones</label>
            <div class="hup-control hup-seo-checkboxes">
                <label class="hup-check"><input type="checkbox" id="improve-content" checked> Mejorar contenido</label>
                <label class="hup-check"><input type="checkbox" id="improve-seo" checked> Mejorar SEO</label>
                <label class="hup-check"><input type="checkbox" id="improve-cta"> Agregar CTA</label>
                <label class="hup-check"><input type="checkbox" id="improve-tags" checked> Mejorar tags</label>
            </div>
        </div>
        <div class="hup-seo-improve-result" hidden></div>
    </div>
    <div class="hup-card__foot"><button type="button" class="hup-btn" id="btn-improve-post">Mejorar con IA</button></div>
</div>

<?php
/**
 * Punto de extensión de la interfaz. Esta versión completa los campos de a un
 * post por vez, con el botón "Completar" de cada fila de la tabla.
 */
do_action( 'hcsai_admin_after_table', 'post' );
?>

<!-- Tabla posts -->
<div class="hup-card">
    <div class="hup-card__head"><h3 class="hup-card__title">Posts</h3></div>
    <div class="hup-card__body">
        <form class="hup-seo-filters" method="get">
            <input type="hidden" name="page" value="hcsai">
            <input type="hidden" name="tab" value="blog">
            <input type="search" name="s" class="hup-input" placeholder="Buscar posts..." aria-label="Buscar posts" value="<?php echo esc_attr( $search ); ?>">
            <select name="cat" class="hup-select">
                <option value="">Todas</option>
                <?php foreach ( $categories as $cat ) : ?>
                <option value="<?php echo esc_attr( $cat->term_id ); ?>" <?php selected( $filter_cat, $cat->term_id ); ?>><?php echo esc_html( $cat->name ); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="seo_status" class="hup-select">
                <option value="">Todos</option>
                <option value="pending" <?php selected( $filter_seo, 'pending' ); ?>>Sin SEO</option>
                <option value="done" <?php selected( $filter_seo, 'done' ); ?>>Con SEO</option>
            </select>
            <button type="submit" class="hup-btn hup-btn--secondary">Filtrar</button>
        </form>

        <?php if ( $query->have_posts() ) : ?>
        <div class="hcsai-table-scroll"><table class="hup-table">
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
                    <td data-hcsai-col="generated"><?php if ( $generated ) : ?><span class="hup-badge hup-badge--success hup-badge--dot" title="Generado el <?php echo esc_attr( $generated ); ?>">IA</span><?php else : ?><span class="hup-badge hup-badge--warning">Pendiente</span><?php endif; ?></td>
                    <td>
                        <button type="button" class="hup-btn hup-btn--sm hup-btn--secondary hup-seo-gen-single" data-id="<?php echo esc_attr( $post->ID ); ?>">Completar</button>
                        <button type="button" class="hup-btn hup-btn--sm hup-btn--secondary hup-seo-edit-seo" data-id="<?php echo esc_attr( $post->ID ); ?>">Editar</button>
                    </td>
                </tr>
                <?php endwhile; wp_reset_postdata(); ?>
            </tbody>
        </table></div>

        <?php
        $total_pages = $query->max_num_pages;
        if ( $total_pages > 1 ) :
        ?>
        <div class="hup-pager"><div class="hup-pager__links">
            <?php echo wp_kses_post( paginate_links( [ 'base' => add_query_arg( 'paged', '%#%' ), 'current' => $paged, 'total' => $total_pages ] ) ); ?>
        </div></div>
        <?php endif; ?>

        <?php else : ?>
            <div class="hup-empty"><div class="hup-empty__icon"><span class="dashicons dashicons-edit-page" aria-hidden="true"></span></div><div class="hup-empty__title">No se encontraron posts</div><p>Prueba con otros filtros o crea un post desde esta sección.</p></div>
        <?php endif; ?>
    </div>
</div>

<?php
// Mismo modal que en Productos: sólo cambian los textos del tipo de contenido.
$hcsai_modal_tipo = 'post';
require __DIR__ . '/partials/modal-campos.php';
?>

<?php require __DIR__ . '/partials/layout-close.php'; ?>
