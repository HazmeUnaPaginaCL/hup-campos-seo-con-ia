<?php
/**
 * Aviso del entorno detectado y de qué mide el score en él.
 *
 * Antes, no tener plugin SEO se anunciaba como una advertencia. No lo es: sin
 * plugin SEO el score se calcula sobre los campos nativos y llega igual al
 * 100 %. Lo que cambia no es cuánto se puede conseguir, sino qué se mide.
 *
 * @package HUP\CamposSeoIA
 */

defined( 'ABSPATH' ) || exit;

$hcsai_plugin = \HUP\CamposSeoIA\SEO\PluginDetector::detect_active();
$hcsai_nombre = \HUP\CamposSeoIA\SEO\PluginDetector::display_name( $hcsai_plugin );
?>

<?php if ( 'none' === $hcsai_plugin ) : ?>
<div class="hup-notice"><span class="dashicons dashicons-info" aria-hidden="true"></span><p>Sin plugin SEO, el score mide descripción, extracto, etiquetas y texto alternativo. Puedes llegar al 100&nbsp;%. Si instalas Yoast, RankMath o AIOSEO, el plugin lo detectará.</p></div>
<?php else : ?>
<div class="hup-notice hup-notice--success"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><p>Plugin SEO detectado: <strong><?php echo esc_html( $hcsai_nombre ); ?></strong>. El score incluye sus campos y los nativos. Pulsa el score de una fila para ver el detalle.</p></div>
<?php endif; ?>
