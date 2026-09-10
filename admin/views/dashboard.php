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
<div class="hup-seo-stats-grid">
    <?php if ( $has_woo ) : ?>
    <a href="<?php echo esc_url( admin_url( 'admin.php?page=hcsai-productos&seo_status=done' ) ); ?>" class="hup-seo-stat-card" style="text-decoration:none;cursor:pointer;" title="Ver productos con SEO">
        <div class="hup-seo-stat-number"><?php echo esc_html( $products_with_seo ); ?> / <?php echo esc_html( $products_total ); ?></div>
        <div class="hup-seo-stat-label">Productos con SEO</div>
    </a>
    <a href="<?php echo esc_url( admin_url( 'admin.php?page=hcsai-productos&seo_status=pending' ) ); ?>" class="hup-seo-stat-card <?php echo $products_no_seo > 0 ? 'hup-seo-stat-warn' : ''; ?>" style="text-decoration:none;cursor:pointer;" title="Ver productos sin SEO">
        <div class="hup-seo-stat-number"><?php echo esc_html( $products_no_seo ); ?></div>
        <div class="hup-seo-stat-label">Productos sin SEO</div>
    </a>
    <?php endif; ?>
    <div class="hup-seo-stat-card <?php echo $posts_no_seo > 0 ? 'hup-seo-stat-warn' : ''; ?>">
        <div class="hup-seo-stat-number"><?php echo esc_html( $posts_no_seo ); ?></div>
        <div class="hup-seo-stat-label">Posts sin SEO</div>
    </div>
    <div class="hup-seo-stat-card">
        <div class="hup-seo-stat-number">$<?php echo esc_html( number_format( $monthly, 4 ) ); ?></div>
        <div class="hup-seo-stat-label">Gasto mensual (USD)</div>
    </div>
    <div class="hup-seo-stat-card">
        <div class="hup-seo-stat-number"><?php echo esc_html( number_format( $stats['total_generations'] ) ); ?></div>
        <div class="hup-seo-stat-label">Generaciones totales</div>
    </div>
</div>

<!-- Sistema -->
<div class="hup-seo-card" style="margin-bottom:8px;">
    <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px;">
        <p style="margin:0; font-size:13px; color:var(--hup-seo-text-3);">
            ⚠️ ¿Los scores no reflejan el contenido actual? Usa <strong>Escanear scores</strong> en la sección Productos para leer los campos SEO existentes (Yoast, RankMath, AIOSEO o nativos) sin usar IA.
        </p>
        <?php if ( $has_woo ) : ?>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=hcsai-productos#hcsai-scan' ) ); ?>" class="hup-seo-btn hup-seo-btn-outline hup-seo-btn-sm">
            🔍 Escanear productos
        </a>
        <?php endif; ?>
    </div>
</div>

<div class="hup-seo-card">
    <h3>Estado del sistema</h3>
    <div class="hup-seo-system-grid" style="margin-top: 12px;">
        <div class="hup-seo-system-item">
            <span class="hup-seo-system-label">Proveedor IA</span>
            <span class="hup-seo-system-value"><?php echo esc_html( $providers[ $provider ] ?? $provider ); ?></span>
        </div>
        <div class="hup-seo-system-item">
            <span class="hup-seo-system-label">Conexión API</span>
            <span class="hup-seo-system-value">
                <?php if ( 'ok' === $conn_status ) : ?>
                    <span style="color: var(--hup-seo-success);">● Verificada</span>
                <?php elseif ( 'error' === $conn_status ) : ?>
                    <span style="color: var(--hup-seo-danger);">● Error</span>
                <?php else : ?>
                    <span style="color: var(--hup-seo-warning);">● Sin verificar</span>
                <?php endif; ?>
            </span>
        </div>
        <div class="hup-seo-system-item">
            <span class="hup-seo-system-label">Modelo activo</span>
            <span class="hup-seo-system-value"><?php echo esc_html( $model ); ?></span>
        </div>
        <div class="hup-seo-system-item">
            <span class="hup-seo-system-label">Plugin SEO</span>
            <span class="hup-seo-system-value"><?php echo esc_html( $seo_name ); ?></span>
        </div>
        <div class="hup-seo-system-item">
            <span class="hup-seo-system-label">WooCommerce</span>
            <span class="hup-seo-system-value"><?php echo $has_woo ? 'Activo' : 'No detectado'; ?></span>
        </div>
    </div>
</div>

<!-- Log reciente -->
<div class="hup-seo-card">
    <h3 style="margin-bottom: 16px;">Últimas generaciones</h3>
    <?php if ( empty( $last_logs ) ) : ?>
        <p style="color: var(--hup-seo-text-3); text-align: center; padding: 24px;">No hay generaciones registradas aún.</p>
    <?php else : ?>
    <table class="hup-seo-table">
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
                        <span class="hup-seo-score hup-seo-score--good">OK</span>
                    <?php else : ?>
                        <span class="hup-seo-score hup-seo-score--low" title="<?php echo esc_attr( $log->error_msg ?? '' ); ?>"
                              style="cursor:help;">Error &#9432;</span>
                        <?php if ( ! empty( $log->error_msg ) ) : ?>
                        <div style="font-size:11px;color:#991b1b;max-width:200px;word-break:break-word;margin-top:2px;"><?php echo esc_html( mb_substr( $log->error_msg, 0, 100 ) ); ?></div>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
                <td><?php
                    $dt = new \DateTime( $log->created_at, new \DateTimeZone( 'UTC' ) );
                    $dt->setTimezone( new \DateTimeZone( wp_timezone_string() ) );
                    echo esc_html( $dt->format( 'Y-m-d H:i:s' ) );
                ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/partials/layout-close.php'; ?>
