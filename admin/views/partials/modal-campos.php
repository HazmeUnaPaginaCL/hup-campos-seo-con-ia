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
        'icono'            => '📦',
        'larga_label'      => 'Descripción del producto',
        'larga_ph'         => 'Descripción completa del producto...',
        'corta_label'      => 'Descripción corta',
        'corta_ph'         => 'Resumen breve del producto...',
        'etiquetas_label'  => 'Etiquetas del producto',
        'etiquetas_ayuda'  => 'Ej: proteína, suplemento, deporte',
    ]
    : [
        'icono'            => '📝',
        'larga_label'      => 'Contenido del post',
        'larga_ph'         => 'Contenido completo del post...',
        'corta_label'      => 'Extracto',
        'corta_ph'         => 'Resumen breve...',
        'etiquetas_label'  => 'Etiquetas',
        'etiquetas_ayuda'  => 'Ej: marketing, seo, wordpress',
    ];
?>
<div class="hup-seo-modal" id="hup-seo-seo-modal" style="display:none;">
    <div class="hup-seo-modal-overlay"></div>
    <div class="hup-seo-modal-content hup-seo-modal-wide">
        <div class="hup-seo-modal-header">
            <h3>Editar campos — <span id="modal-post-title"></span></h3>
            <button type="button" class="hup-seo-modal-close">&times;</button>
        </div>
        <div class="hup-seo-modal-body">
            <input type="hidden" id="modal-post-id">

            <!-- ═══ Desglose del score ═══ -->
            <div class="hup-seo-score-detail" id="modal-score-detail" style="display:none;">
                <div class="hup-seo-score-detail__head">
                    <h4>Cómo se calcula este <span id="modal-score-detail-total">0</span>%</h4>
                    <p id="modal-score-entorno"></p>
                </div>
                <ul class="hup-seo-score-detail__list" id="modal-score-list"></ul>
                <p class="hup-seo-help" id="modal-score-manual"></p>
            </div>

            <!-- ═══ Sección: Campos nativos WordPress ═══ -->
            <div class="hup-seo-modal-section">
                <div class="hup-seo-modal-section-header">
                    <span class="hup-seo-section-icon"><?php echo esc_html( $hcsai_textos['icono'] ); ?></span>
                    <h4>Campos nativos de WordPress</h4>
                </div>
                <div class="hup-seo-form-row" data-hcsai-field="product_description">
                    <label class="hup-seo-form-label"><?php echo esc_html( $hcsai_textos['larga_label'] ); ?></label>
                    <textarea id="modal-product-desc" class="hup-seo-textarea" rows="5" placeholder="<?php echo esc_attr( $hcsai_textos['larga_ph'] ); ?>"></textarea>
                    <span class="hup-seo-char-count hup-seo-char-count-words"><span id="modal-product-desc-words">0</span> palabras</span>
                </div>
                <div class="hup-seo-form-row" data-hcsai-field="short_description">
                    <label class="hup-seo-form-label"><?php echo esc_html( $hcsai_textos['corta_label'] ); ?></label>
                    <textarea id="modal-short-desc" class="hup-seo-textarea" rows="2" maxlength="300" placeholder="<?php echo esc_attr( $hcsai_textos['corta_ph'] ); ?>"></textarea>
                    <span class="hup-seo-char-count"><span id="modal-short-desc-count">0</span>/300</span>
                </div>
                <div class="hup-seo-form-row" data-hcsai-field="tags">
                    <label class="hup-seo-form-label"><?php echo esc_html( $hcsai_textos['etiquetas_label'] ); ?></label>
                    <input type="text" id="modal-tags" class="hup-seo-input" placeholder="Separadas por coma">
                    <p class="hup-seo-help"><?php echo esc_html( $hcsai_textos['etiquetas_ayuda'] ); ?></p>
                </div>
                <div class="hup-seo-form-row" data-hcsai-field="alt_text">
                    <label class="hup-seo-form-label">Alt text (imagen destacada)</label>
                    <input type="text" id="modal-alt-text" class="hup-seo-input" maxlength="120">
                </div>
            </div>

            <!-- ═══ Sección: Campos de plugin SEO ═══ -->
            <div class="hup-seo-modal-section hup-seo-modal-seo-only">
                <div class="hup-seo-modal-section-header">
                    <span class="hup-seo-section-icon">🔌</span>
                    <h4>Campos <span id="modal-seo-plugin-label">Plugin SEO</span></h4>
                </div>
                <div class="hup-seo-form-row" data-hcsai-field="seo_title">
                    <label class="hup-seo-form-label">Título SEO</label>
                    <input type="text" id="modal-seo-title" class="hup-seo-input" maxlength="60">
                    <span class="hup-seo-char-count"><span id="modal-seo-title-count">0</span>/60</span>
                </div>
                <div class="hup-seo-form-row" data-hcsai-field="meta_description">
                    <label class="hup-seo-form-label">Meta descripción</label>
                    <textarea id="modal-meta-desc" class="hup-seo-textarea" rows="3" maxlength="160"></textarea>
                    <span class="hup-seo-char-count"><span id="modal-meta-desc-count">0</span>/160</span>
                </div>
                <div class="hup-seo-form-row" data-hcsai-field="focus_keyword">
                    <label class="hup-seo-form-label">Palabra clave principal</label>
                    <input type="text" id="modal-focus-kw" class="hup-seo-input">
                </div>
                <div class="hup-seo-form-row" data-hcsai-field="og_title">
                    <label class="hup-seo-form-label">Título para redes sociales</label>
                    <input type="text" id="modal-og-title" class="hup-seo-input">
                </div>
                <div class="hup-seo-form-row" data-hcsai-field="og_description">
                    <label class="hup-seo-form-label">Descripción para redes sociales</label>
                    <textarea id="modal-og-desc" class="hup-seo-textarea" rows="2"></textarea>
                </div>
            </div>

            <!-- ═══ Barra de estado ═══ -->
            <div class="hup-seo-modal-status" id="modal-seo-info">
                <div id="modal-status-native" class="hup-seo-modal-status-badge hup-seo-status-native">
                    📦 Sin plugin SEO — se trabaja con campos nativos de WordPress
                </div>
                <div id="modal-status-plugin" class="hup-seo-modal-status-badge hup-seo-status-plugin" style="display:none;">
                    🔌 Plugin detectado: <strong><span id="modal-plugin-name">—</span></strong>
                </div>
                <div class="hup-seo-modal-status-score">
                    Score: <strong><span id="modal-score-value">0</span>%</strong>
                </div>
            </div>

        </div>
        <div class="hup-seo-modal-footer">
            <button type="button" class="hup-seo-btn hup-seo-btn-outline hup-seo-modal-close" id="modal-cancel-fields">Cancelar</button>
            <button type="button" class="hup-seo-btn hup-seo-btn-primary" id="modal-save-fields">Guardar</button>
        </div>
    </div>
</div>
