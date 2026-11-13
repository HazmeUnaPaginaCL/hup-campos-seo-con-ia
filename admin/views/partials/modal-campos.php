<?php
/**
 * Modal de edición manual de campos.
 *
 * Productos y blog compartían dos copias casi idénticas de este bloque, y
 * cualquier cambio había que hacerlo dos veces. Aquí sólo cambian los textos,
 * que dependen del tipo de contenido.
 *
 * TODOS los campos son editables a mano, incluidos los que la IA acaba de
 * rellenar: corregir a mano no consume tokens.
 *
 * Espera la variable $hcsai_modal_tipo: 'product' o 'post'.
 *
 * @package HUP\CamposSeoIA
 */

defined( 'ABSPATH' ) || exit;

$hcsai_modal_tipo = ( isset( $hcsai_modal_tipo ) && 'product' === $hcsai_modal_tipo ) ? 'product' : 'post';

$hcsai_textos = ( 'product' === $hcsai_modal_tipo )
    ? [
        'icono'            => 'dashicons-products',
        'larga_label'      => 'Descripción del producto',
        'larga_ph'         => 'Descripción completa del producto...',
        'corta_label'      => 'Descripción corta',
        'corta_ph'         => 'Resumen breve del producto...',
        'etiquetas_label'  => 'Etiquetas del producto',
        'etiquetas_ayuda'  => 'Ej: proteína, suplemento, deporte',
    ]
    : [
        'icono'            => 'dashicons-edit-page',
        'larga_label'      => 'Contenido del post',
        'larga_ph'         => 'Contenido completo del post...',
        'corta_label'      => 'Extracto',
        'corta_ph'         => 'Resumen breve...',
        'etiquetas_label'  => 'Etiquetas',
        'etiquetas_ayuda'  => 'Ej: marketing, seo, wordpress',
    ];
?>
<div class="hup-modal" id="hup-seo-seo-modal" hidden>
    <div class="hup-modal__box hup-modal__box--wide" role="dialog" aria-modal="true" aria-labelledby="hcsai-modal-title">
        <div class="hup-card__head">
            <h3 class="hup-card__title" id="hcsai-modal-title">Editar campos — <span id="modal-post-title"></span></h3>
            <button type="button" class="hup-btn hup-btn--icon hup-seo-modal-close" data-hup-close aria-label="Cerrar"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button>
        </div>
        <div class="hup-card__body hup-modal__scroll">
            <input type="hidden" id="modal-post-id">

            <!-- ═══ Desglose del score ═══ -->
            <div class="hup-seo-score-detail" id="modal-score-detail" hidden>
                <div class="hup-seo-score-detail__head">
                    <h4>Cómo se calcula este <span id="modal-score-detail-total">0</span>%</h4>
                    <p id="modal-score-entorno"></p>
                </div>
                <ul class="hup-seo-score-detail__list" id="modal-score-list"></ul>
                <p class="hup-hint" id="modal-score-manual"></p>
            </div>

            <!-- ═══ Sección: Campos nativos WordPress ═══ -->
            <div class="hup-seo-modal-section">
                <div class="hup-seo-modal-section-header">
                    <span class="dashicons <?php echo esc_attr( $hcsai_textos['icono'] ); ?>" aria-hidden="true"></span>
                    <h4>Campos nativos de WordPress</h4>
                </div>
                <div class="hup-field" data-hcsai-field="product_description">
                    <label class="hup-label" for="modal-product-desc"><?php echo esc_html( $hcsai_textos['larga_label'] ); ?></label>
                    <textarea id="modal-product-desc" class="hup-input" rows="5" placeholder="<?php echo esc_attr( $hcsai_textos['larga_ph'] ); ?>"></textarea>
                    <span class="hup-seo-char-count hup-seo-char-count-words"><span id="modal-product-desc-words">0</span> palabras</span>
                </div>
                <div class="hup-field" data-hcsai-field="short_description">
                    <label class="hup-label" for="modal-short-desc"><?php echo esc_html( $hcsai_textos['corta_label'] ); ?></label>
                    <textarea id="modal-short-desc" class="hup-input" rows="2" maxlength="300" placeholder="<?php echo esc_attr( $hcsai_textos['corta_ph'] ); ?>"></textarea>
                    <span class="hup-seo-char-count"><span id="modal-short-desc-count">0</span>/300</span>
                </div>
                <div class="hup-field" data-hcsai-field="tags">
                    <label class="hup-label" for="modal-tags"><?php echo esc_html( $hcsai_textos['etiquetas_label'] ); ?></label>
                    <input type="text" id="modal-tags" class="hup-input" placeholder="Separadas por coma">
                    <p class="hup-hint"><?php echo esc_html( $hcsai_textos['etiquetas_ayuda'] ); ?></p>
                </div>
                <div class="hup-field" data-hcsai-field="alt_text">
                    <label class="hup-label" for="modal-alt-text">Alt text (imagen destacada)</label>
                    <input type="text" id="modal-alt-text" class="hup-input" maxlength="120">
                </div>
            </div>

            <!-- ═══ Sección: Campos SEO y de redes ═══ -->
            <!-- Se muestra siempre: sin plugin SEO estos campos los guarda e
                 imprime este plugin, y también hay que poder revisarlos. -->
            <div class="hup-seo-modal-section">
                <div class="hup-seo-modal-section-header">
                    <span class="dashicons dashicons-admin-network" aria-hidden="true"></span>
                    <h4>Campos <span id="modal-seo-plugin-label">SEO y redes sociales</span></h4>
                </div>
                <p class="hup-hint" id="modal-seo-native-help" hidden>
                    No hay plugin SEO activo: estos campos los guarda este plugin y los imprime en el &lt;head&gt; de la página (título, meta descripción y Open Graph).
                </p>
                <div class="hup-field" data-hcsai-field="seo_title">
                    <label class="hup-label" for="modal-seo-title">Título SEO</label>
                    <input type="text" id="modal-seo-title" class="hup-input" maxlength="60">
                    <span class="hup-seo-char-count"><span id="modal-seo-title-count">0</span>/60</span>
                </div>
                <div class="hup-field" data-hcsai-field="meta_description">
                    <label class="hup-label" for="modal-meta-desc">Meta descripción</label>
                    <textarea id="modal-meta-desc" class="hup-input" rows="3" maxlength="160"></textarea>
                    <span class="hup-seo-char-count"><span id="modal-meta-desc-count">0</span>/160</span>
                </div>
                <div class="hup-field" data-hcsai-field="focus_keyword">
                    <label class="hup-label" for="modal-focus-kw">Palabra clave principal</label>
                    <input type="text" id="modal-focus-kw" class="hup-input">
                </div>
                <div class="hup-field" data-hcsai-field="og_title">
                    <label class="hup-label" for="modal-og-title">Título para redes sociales</label>
                    <input type="text" id="modal-og-title" class="hup-input">
                </div>
                <div class="hup-field" data-hcsai-field="og_description">
                    <label class="hup-label" for="modal-og-desc">Descripción para redes sociales</label>
                    <textarea id="modal-og-desc" class="hup-input" rows="2"></textarea>
                </div>
            </div>

            <!-- ═══ Barra de estado ═══ -->
            <div class="hup-seo-modal-status" id="modal-seo-info">
                <div id="modal-status-native" class="hup-seo-modal-status-badge hup-seo-status-native">
                    Sin plugin SEO — se trabaja con campos nativos de WordPress
                </div>
                <div id="modal-status-plugin" class="hup-seo-modal-status-badge hup-seo-status-plugin" hidden>
                    Plugin detectado: <strong><span id="modal-plugin-name">—</span></strong>
                </div>
                <div class="hup-seo-modal-status-score">
                    Score: <strong><span id="modal-score-value">0</span>%</strong>
                </div>
            </div>

        </div>
        <div class="hup-card__foot">
            <button type="button" class="hup-btn hup-btn--secondary hup-seo-modal-close" data-hup-close id="modal-cancel-fields">Cancelar</button>
            <button type="button" class="hup-btn" id="modal-save-fields">Guardar campos</button>
        </div>
    </div>
</div>
