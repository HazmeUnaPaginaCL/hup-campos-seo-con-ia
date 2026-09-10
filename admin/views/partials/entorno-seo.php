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
<div class="hup-seo-notice hup-seo-notice-ok" style="display:flex; align-items:flex-start; gap:12px; padding:16px 20px; border-left:4px solid var(--hup-seo-primary);">
    <span style="font-size:24px; flex-shrink:0;">📦</span>
    <div>
        <strong style="font-size:14px; display:block; margin-bottom:4px;">Sin plugin SEO: se trabaja con los campos nativos de WordPress</strong>
        <span style="font-size:13px; opacity:.9;">
            El score mide la descripción larga, la corta, las etiquetas y el texto alternativo de la imagen.
            Completándolos llegas al 100&nbsp;%. Si instalas Yoast SEO, RankMath o All In One SEO, se detecta
            solo y el score pasa a medir sus campos.
        </span>
    </div>
</div>
<?php else : ?>
<div class="hup-seo-notice hup-seo-notice-ok" style="display:flex; align-items:flex-start; gap:12px; padding:16px 20px; border-left:4px solid var(--hup-seo-success);">
    <span style="font-size:24px; flex-shrink:0;">✅</span>
    <div>
        <strong style="font-size:14px; display:block; margin-bottom:4px;">Plugin SEO detectado: <?php echo esc_html( $hcsai_nombre ); ?></strong>
        <span style="font-size:13px; opacity:.9;">
            El score mide los campos de <?php echo esc_html( $hcsai_nombre ); ?> además de los nativos, y ahí
            se escriben los datos. Pulsa el score de cualquier fila para ver qué campos le faltan.
        </span>
    </div>
</div>
<?php endif; ?>
