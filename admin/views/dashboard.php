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

$seo_plugin  = \HUP\CamposSeoIA\SEO\PluginDetector::detect_active();
$seo_name    = \HUP\CamposSeoIA\SEO\PluginDetector::display_name( $seo_plugin );
$has_woo     = class_exists( 'WooCommerce' );
$provider    = \HUP\CamposSeoIA\Settings::provider();
$model       = \HUP\CamposSeoIA\Settings::model();
$conn_status = \HUP\CamposSeoIA\Settings::connection_status( $provider );
$providers   = \HUP\CamposSeoIA\Settings::providers_display();
$monthly     = \HUP\CamposSeoIA\Logger::monthly_cost();
$stats       = \HUP\CamposSeoIA\Logger::stats();
$last_logs   = \HUP\CamposSeoIA\Logger::get_recent( 8 );
$usd_to_clp  = \HUP\CamposSeoIA\Settings::usd_to_clp();

// Contar productos/posts sin SEO
$products_total   = 0;
$products_no_seo  = 0;
$posts_no_seo     = 0;

if ( $has_woo ) {
    // Detectar meta key principal del plugin SEO activo
    $seo_meta_key = \HUP\CamposSeoIA\SEO\FieldMap::key( $seo_plugin, 'seo_title' );
    // Con SEO: tiene _hcsai_score>0 (escaneado/generado) O tiene el meta key del plugin lleno
    $with_seo_query_args = [
        'post_type'      => 'product',
        'post_status'    => [ 'publish', 'draft' ],
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'meta_query'     => [ 'relation' => 'OR',
            [ 'key' => '_hcsai_score', 'value' => '0', 'compare' => '>', 'type' => 'NUMERIC' ],
        ],
    ];
    if ( ! empty( $seo_meta_key ) ) {
        $with_seo_query_args['meta_query'][] = [ 'key' => $seo_meta_key, 'value' => '', 'compare' => '!=' ];
    }
    $products_with_seo = (int) ( new WP_Query( $with_seo_query_args ) )->found_posts;
    $products_total  = (int) ( new WP_Query( [
        'post_type'      => 'product',
        'post_status'    => [ 'publish', 'draft' ],
        'posts_per_page' => 1,
        'fields'         => 'ids',
    ] ) )->found_posts;
    $products_no_seo = max( 0, $products_total - $products_with_seo );
}

$posts_total    = (int) ( new WP_Query( [
    'post_type'      => 'post',
    'post_status'    => [ 'publish', 'draft' ],
    'posts_per_page' => 1,
    'fields'         => 'ids',
] ) )->found_posts;
$posts_with_seo = (int) ( new WP_Query( [
    'post_type'      => 'post',
    'post_status'    => [ 'publish', 'draft' ],
    'posts_per_page' => 1,
    'fields'         => 'ids',
    'meta_query'     => [ [ 'key' => '_hcsai_score', 'value' => '0', 'compare' => '>', 'type' => 'NUMERIC' ] ],
] ) )->found_posts;
$posts_no_seo = $posts_total - $posts_with_seo;

require __DIR__ . '/partials/layout-open.php';
?>

<!-- Stats -->
<div class="hup-grid hup-grid--3">
    <?php if ( $has_woo ) : ?>
    <a href="<?php echo esc_url( admin_url( 'admin.php?page=hcsai&tab=productos&seo_status=done' ) ); ?>" class="hup-stat" title="Ver productos con SEO">
        <div class="hup-stat__label">Productos con SEO</div>
        <div class="hup-stat__value"><?php echo esc_html( $products_with_seo ); ?> / <?php echo esc_html( $products_total ); ?></div>
    </a>
    <a href="<?php echo esc_url( admin_url( 'admin.php?page=hcsai&tab=productos&seo_status=pending' ) ); ?>" class="hup-stat" title="Ver productos sin SEO">
        <div class="hup-stat__label">Productos sin SEO</div>
        <div class="hup-stat__value"><?php echo esc_html( $products_no_seo ); ?></div>
    </a>
    <?php endif; ?>
    <div class="hup-stat">
        <div class="hup-stat__label">Posts sin SEO</div>
        <div class="hup-stat__value"><?php echo esc_html( $posts_no_seo ); ?></div>
    </div>
    <div class="hup-stat">
        <div class="hup-stat__label">Gasto mensual (USD)</div>
        <div class="hup-stat__value">$<?php echo esc_html( number_format( $monthly, 4, ',', '.' ) ); ?></div>
    </div>
    <div class="hup-stat">
        <div class="hup-stat__label">Generaciones totales</div>
        <div class="hup-stat__value"><?php echo esc_html( number_format( $stats['total_generations'], 0, ',', '.' ) ); ?></div>
    </div>
</div>

<!-- Sistema -->
<div class="hup-notice"><span class="dashicons dashicons-info" aria-hidden="true"></span><p>Si los scores no reflejan el contenido actual, escanea los productos para leer sus campos SEO sin usar IA. <?php if ( $has_woo ) : ?><a href="<?php echo esc_url( admin_url( 'admin.php?page=hcsai&tab=productos#hcsai-scan' ) ); ?>">Ir a Productos</a><?php endif; ?></p></div>

<div class="hup-card">
    <div class="hup-card__head"><h3 class="hup-card__title">Estado del sistema</h3></div>
    <div class="hup-card__body"><div class="hup-status-row">
        <div>
            <span>Proveedor IA</span>
            <strong><?php echo esc_html( $providers[ $provider ] ?? $provider ); ?></strong>
        </div>
        <div>
            <span>Conexión API</span>
                <?php if ( 'ok' === $conn_status ) : ?>
                    <strong class="hup-badge hup-badge--success hup-badge--dot">Verificada</strong>
                <?php elseif ( 'error' === $conn_status ) : ?>
                    <strong class="hup-badge hup-badge--danger hup-badge--dot">Error</strong>
                <?php else : ?>
                    <strong class="hup-badge hup-badge--warning hup-badge--dot">Sin verificar</strong>
                <?php endif; ?>
        </div>
        <div>
            <span>Modelo activo</span>
            <strong><?php echo esc_html( $model ); ?></strong>
        </div>
        <div>
            <span>Plugin SEO</span>
            <strong><?php echo esc_html( $seo_name ); ?></strong>
        </div>
        <div>
            <span>WooCommerce</span>
            <strong><?php echo $has_woo ? 'Activo' : 'No detectado'; ?></strong>
        </div>
    </div></div>
</div>

<!-- Log reciente -->
<div class="hup-card">
    <div class="hup-card__head"><h3 class="hup-card__title">Últimas generaciones</h3></div>
    <div class="hup-card__body">
    <?php if ( empty( $last_logs ) ) : ?>
        <div class="hup-empty"><div class="hup-empty__icon"><span class="dashicons dashicons-list-view" aria-hidden="true"></span></div><div class="hup-empty__title">Aún no hay generaciones</div><p>Completa un producto o post para ver aquí su historial.</p></div>
    <?php else : ?>
    <div class="hcsai-table-scroll"><table class="hup-table">
        <thead>
            <tr>
                <th>Post</th>
                <th>Tipo</th>
                <th>Acción</th>
                <th>Tokens</th>
                <th>USD</th>
                <th>CLP</th>
                <th>Estado</th>
                <th>Fecha</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ( $last_logs as $log ) : ?>
            <tr>
                <td><?php echo esc_html( $log->post_title ?? '#' . $log->post_id ); ?></td>
                <td><?php echo esc_html( $log->post_type ); ?></td>
                <td><?php echo esc_html( $log->action ); ?></td>
                <td><?php echo esc_html( number_format( $log->tokens_used ) ); ?></td>
                <td>$<?php echo esc_html( number_format( $log->cost_usd, 6 ) ); ?></td>
                <td>$<?php echo esc_html( number_format( $log->cost_usd * $usd_to_clp, 0, ',', '.' ) ); ?></td>
                <td>
                    <?php if ( 'ok' === $log->status ) : ?>
                        <span class="hup-badge hup-badge--success hup-badge--dot">Completado</span>
                    <?php else : ?>
                        <span class="hup-badge hup-badge--danger hup-badge--dot" title="<?php echo esc_attr( $log->error_msg ?? '' ); ?>">Error</span>
                        <?php if ( ! empty( $log->error_msg ) ) : ?>
                        <div class="hcsai-log-error"><?php echo esc_html( mb_substr( $log->error_msg, 0, 100 ) ); ?></div>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
                <td><?php
                    $dt = new \DateTime( $log->created_at, new \DateTimeZone( 'UTC' ) );
                    $dt->setTimezone( new \DateTimeZone( wp_timezone_string() ) );
                    echo esc_html( $dt->format( 'd/m/Y H:i' ) );
                ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/partials/layout-close.php'; ?>
