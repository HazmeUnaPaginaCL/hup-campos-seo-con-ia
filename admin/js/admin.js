/**
 * HUP Campos SEO con IA — Admin JS
 * Toda la interactividad del panel de administración.
 */
(function ($) {
  'use strict';

  // ── Helpers ──────────────────────────────────────────
  function ajax(action, data) {
    data = data || {};
    data.action = 'hcsai_' + action;
    data.nonce = hcsai.nonce;
    return $.post(hcsai.ajax_url, data);
  }

  // msg puede venir del servidor y contener el texto de error que devolvió la
  // API de IA, así que se inserta con .text() y nunca concatenado en HTML.
  function toast(msg, type) {
    var icon = type === 'error' ? 'dashicons-warning' : 'dashicons-yes-alt';
    var $t = $('<div class="hup-toast"></div>')
      .toggleClass('hcsai-toast--error', type === 'error')
      .append($('<span class="dashicons"></span>').addClass(icon))
      .append(document.createTextNode(msg));
    $('.hup-app').append($t);
    setTimeout(function () { $t.remove(); }, 3500);
  }

  // El badge de score se repinta desde tres sitios (completar, guardar y
  // escanear). Centralizado para que no se pierda ninguna clase por el camino:
  // reescribir el atributo class entero borraba el modificador --link.
  function updateScoreBadge($row, score) {
    var level = Math.min(3, Math.floor(score / 25)); // 0-24, 25-49, 50-74, 75-100
    $row.find('.hup-seo-score')
      .attr('class', 'hup-seo-score hup-seo-score--link hup-seo-score-score-' + level)
      .attr('data-score', level)
      .text(score + '%');
  }

  function generatedCell($row) {
    var $cell = $row.find('[data-hcsai-col="generated"]');
    // Las vistas de extensiones anteriores todavía usan la penúltima columna.
    return $cell.length ? $cell : $row.find('td').eq(-2);
  }


  // ── Provider selector ───────────────────────────────
  $(document).on('change', 'input[name="hcsai_provider"]', function () {
    var slug = $(this).val();
    $('.hup-seo-provider-block').prop('hidden', true);
    $('.hup-seo-provider-block[data-provider="' + slug + '"]').prop('hidden', false);
  });

  // ── Test API — handler movido a sección mejorada abajo ──

  // ── Reset prompt ────────────────────────────────────
  $(document).on('click', '.hup-seo-reset-prompt', function () {
    var $btn = $(this);
    var $choice = $('#hcsai-reset-prompt-type');
    var type = $choice.val();
    var label = $choice.find('option:selected').text();
    if (!type || !confirm('¿Restaurar el prompt de ' + label + ' al valor por defecto? Se guardará de inmediato.')) return;

    $btn.prop('disabled', true);
    ajax('reset_prompt', { type: type })
      .done(function (r) {
        if (r.success) {
          $('#hup-seo-prompt-' + type).val(r.data.prompt);
          toast('Prompt restaurado y guardado');
        } else {
          toast((r.data && r.data.message) || 'No se pudo restaurar el prompt', 'error');
        }
      })
      .fail(function () { toast('Error de red al restaurar el prompt', 'error'); })
      .always(function () { $btn.prop('disabled', false); });
  });

  // ── Completar un contenido ──────────────────────────
  $(document).on('click', '.hup-seo-gen-single', function () {
    var $btn = $(this);
    var id = $btn.data('id');
    var $row = $btn.closest('tr');

    $btn.prop('disabled', true).html('<span class="hup-spinner"></span>Completando...');

    // Si sobrescribe o no lo decide Configuración, no este botón.
    ajax('generate', { post_id: id })
      .done(function (r) {
        if (r.success) {
          var d = r.data;
          updateScoreBadge($row, d.score);

          // Sin campos vacíos no se llama a la IA: se avisa y no se toca la fila.
          if (d.skipped) {
            toast(d.message);
            return;
          }

          // Actualizar estado
          generatedCell($row).empty().append(
            $('<span class="hup-badge hup-badge--success hup-badge--dot">IA</span>')
              .attr('title', 'Generado el ' + new Date().toLocaleString('es-CL'))
          );
          // Feedback de imagen
          var msg = 'Campos completados — Score: ' + d.score + '%';
          if (d.has_thumbnail === false) {
            msg += ' Sin imagen destacada: alt text no aplicado';
          }
          toast(msg);
          // Abrir modal automáticamente después de completar
          setTimeout(function() {
            $row.find('.hup-seo-edit-seo').trigger('click');
          }, 500);
        } else {
          toast(r.data.message, 'error');
        }
      })
      .fail(function () { toast('Error de red', 'error'); })
      .always(function () { $btn.prop('disabled', false).text('Completar'); });
  });

  // ── Sincronía de prompts ────────────────────────────
  // El prompt corregido ya viene calculado en el servidor y se muestra en la
  // vista previa; aquí sólo se copia al textarea. No se guarda nada hasta que
  // el usuario pulse Guardar: la decisión sigue siendo suya.
  $(document).on('click', '.hup-seo-prompt-sync__apply', function () {
    var $aviso = $(this).closest('.hup-seo-prompt-sync');
    var tipo = $(this).data('type');
    var limpio = $aviso.find('.hup-seo-prompt-sync__preview').val();

    $('#hup-seo-prompt-' + tipo).val(limpio);
    $aviso.slideUp(200);
    toast('Campos quitados del prompt — pulsa Guardar para aplicarlo');
  });

  // ── Desglose del score ──────────────────────────────
  // Un 40% sin explicación parece un fallo del plugin. Con el desglose se ve
  // que son campos por completar, cuáles, y cuánto suma cada uno.
  var ESTADO_ICONO = { ok: 'dashicons-yes-alt', parcial: 'dashicons-minus', vacio: 'dashicons-dismiss' };

  function renderBreakdown(b) {
    var $panel = $('#modal-score-detail');
    var $list  = $('#modal-score-list').empty();

    // Se limpian las anotaciones anteriores: al abrir otro contenido los campos
    // medidos pueden ser distintos.
    $('.hup-seo-field-score').remove();
    $('.hup-field[data-hcsai-field]').removeAttr('data-estado');

    if (!b || !b.fields || !b.fields.length) {
      $panel.prop('hidden', true);
      return;
    }

    $panel.prop('hidden', false);
    $('#modal-score-detail-total').text(b.score);
    $('#modal-score-entorno').text(b.entorno || '');

    var aMano = 0;

    $.each(b.fields, function (_, f) {
      // Todo con .text(): el label y la nota son texto, nunca HTML.
      var $li = $('<li></li>').addClass('hup-seo-score-detail__item is-' + f.estado);
      $li.append($('<span></span>').addClass('hup-seo-score-detail__icon dashicons ' + (ESTADO_ICONO[f.estado] || 'dashicons-minus')).attr('aria-hidden', 'true'));
      $li.append($('<span></span>').addClass('hup-seo-score-detail__label').text(f.label));
      $li.append($('<span></span>').addClass('hup-seo-score-detail__pts').text(f.earned + '/' + f.weight + ' pts'));
      if (f.nota) {
        $li.append($('<span></span>').addClass('hup-seo-score-detail__nota').text(f.nota));
      }
      if (!f.ia) {
        aMano++;
        $li.append($('<span></span>').addClass('hup-seo-score-detail__manual').text('a mano'));
      }
      $list.append($li);

      // Anotación junto al campo del formulario, para no obligar a mirar arriba.
      var $row = $('.hup-field[data-hcsai-field="' + f.field + '"]');
      if ($row.length) {
        $row.attr('data-estado', f.estado);
        $row.find('.hup-label').first().append(
          $('<span></span>')
            .addClass('hup-seo-field-score is-' + f.estado)
            .text(f.earned + '/' + f.weight + ' pts')
        );
      }
    });

    $('#modal-score-manual').text(
      aMano > 0
        ? 'Los campos marcados «a mano» no los rellena la IA, pero al completarlos aquí suman igual al score.'
        : ''
    );
  }

  // ── Edit SEO modal ──────────────────────────────────
  // El badge de score de la tabla abre el mismo modal: es donde está el
  // desglose y donde se arregla lo que falta.
  // Campos del modal y el valor con el que se abrieron. Al guardar solo se
  // envía lo que cambió: reenviar el contenido entero cada vez lo pasaba por
  // wp_kses_post y le quitaba iframes, formularios y demás aunque solo se
  // hubiera tocado la meta descripción.
  var MODAL_FIELDS = {
    product_description: '#modal-product-desc',
    short_description: '#modal-short-desc',
    seo_title: '#modal-seo-title',
    meta_description: '#modal-meta-desc',
    focus_keyword: '#modal-focus-kw',
    og_title: '#modal-og-title',
    og_description: '#modal-og-desc',
    alt_text: '#modal-alt-text',
    tags: '#modal-tags'
  };
  var modalOriginal = {};

  // Se toma de .val() y no de los datos del servidor: el navegador normaliza
  // los saltos de línea de un textarea, y comparar contra el dato crudo
  // marcaría como cambiado un campo intacto.
  function snapshotModal() {
    modalOriginal = {};
    $.each(MODAL_FIELDS, function (key, sel) {
      modalOriginal[key] = $(sel).val() || '';
    });
  }

  $(document).on('click', '.hup-seo-edit-seo, .hup-seo-score--link[data-id]', function () {
    var id = $(this).data('id');
    $('#modal-post-id').val(id);
    // Hasta que lleguen los datos de este contenido, el formulario aún tiene
    // los del anterior: guardar ahora mezclaría los dos.
    $('#modal-save-fields').prop('disabled', true);
    $('#modal-post-title').text('Cargando...');
    $('#modal-cancel-fields').text('Cancelar');
    $('#hup-seo-seo-modal').prop('hidden', false);

    ajax('get_fields', { post_id: id }).done(function (r) {
      if (r.success) {
        var f = r.data.fields;
        var hasPlugin = !!f.has_plugin;

        // Título del modal
        $('#modal-post-title').text(r.data.post_title);

        // Campos nativos WordPress
        $('#modal-product-desc').val(f.product_description || '');
        $('#modal-short-desc').val(f.short_description || '');
        var tags = (f.tags && Array.isArray(f.tags)) ? f.tags.join(', ') : '';
        $('#modal-tags').val(tags);
        $('#modal-alt-text').val(f.alt_text || '');

        // Campos plugin SEO
        $('#modal-seo-title').val(f.seo_title || '');
        $('#modal-meta-desc').val(f.meta_description || '');
        $('#modal-focus-kw').val(f.focus_keyword || '');
        $('#modal-og-title').val(f.og_title || '');
        $('#modal-og-desc').val(f.og_description || '');

        // La sección SEO se ve siempre. Antes se ocultaba sin plugin SEO, y
        // entonces los campos propios —los que se imprimen en el <head>, y
        // los que puntúan en los posts— no se podían revisar ni completar.
        $('#modal-seo-plugin-label').text(hasPlugin && f.plugin_name ? f.plugin_name : 'SEO y redes sociales');
        $('#modal-seo-native-help').prop('hidden', hasPlugin);

        // Barra de estado
        $('#modal-score-value').text(f.score || 0);
        if (hasPlugin) {
          $('#modal-status-native').prop('hidden', true);
          $('#modal-status-plugin').prop('hidden', false);
          $('#modal-plugin-name').text(f.plugin_name);
        } else {
          $('#modal-status-native').prop('hidden', false);
          $('#modal-status-plugin').prop('hidden', true);
        }

        // Feedback sin imagen
        if (!f.has_thumbnail) {
          $('#modal-alt-text').attr('placeholder', 'Sin imagen destacada — no se aplicará alt text').prop('disabled', true);
        } else {
          $('#modal-alt-text').attr('placeholder', '').prop('disabled', false);
        }

        // Desglose del score
        renderBreakdown(r.data.breakdown);

        // Contadores
        updateCharCounts();
        updateWordCount();

        snapshotModal();
        $('#modal-save-fields').prop('disabled', false);
      } else {
        toast((r.data && r.data.message) || 'No se pudieron cargar los campos', 'error');
      }
    }).fail(function () { toast('Error de red al cargar los campos', 'error'); });
  });

  $(document).on('click', '.hup-seo-modal-close', function () {
    $('#hup-seo-seo-modal').prop('hidden', true);
    // El botón vuelve a su etiqueta original: tras guardar pasa a "Cerrar".
    $('#modal-cancel-fields').text('Cancelar');
  });

  $(document).on('keydown', function (event) {
    if (event.key === 'Escape') $('#hup-seo-seo-modal').prop('hidden', true);
  });

  $(document).on('click', '#modal-save-fields', function () {
    var $btn = $(this);
    var id = $('#modal-post-id').val();
    var payload = { post_id: id };
    var cambios = 0;

    $.each(MODAL_FIELDS, function (key, sel) {
      var $el = $(sel);
      var val = $el.val() || '';
      if (!$el.prop('disabled') && val !== modalOriginal[key]) {
        payload['fields[' + key + ']'] = val;
        cambios++;
      }
    });

    if (!cambios) {
      toast('No hay cambios que guardar');
      return;
    }

    $btn.prop('disabled', true).html('<span class="hup-spinner"></span>Guardando...');

    ajax('save_fields', payload)
      .done(function (r) {
        if (r.success) {
          snapshotModal();
          toast('Campos guardados — Score: ' + r.data.score + '%');

          // El modal se queda abierto con el desglose recalculado: si aún falta
          // algo para el 100 %, se ve al momento y sin volver a entrar.
          renderBreakdown(r.data.breakdown);
          $('#modal-score-value').text(r.data.score);
          $('#modal-cancel-fields').text('Cerrar');

          var $row = $('tr[data-post-id="' + id + '"]').first();
          if ($row.length) {
            updateScoreBadge($row, r.data.score);
          }
        } else {
          toast(r.data.message, 'error');
        }
      })
      .always(function () { $btn.prop('disabled', false).text('Guardar campos'); });
  });

  function updateCharCounts() {
    var seoTitle = $('#modal-seo-title').val() || '';
    var metaDesc = $('#modal-meta-desc').val() || '';
    var shortDesc = $('#modal-short-desc').val() || '';
    $('#modal-seo-title-count').text(seoTitle.length);
    $('#modal-meta-desc-count').text(metaDesc.length);
    $('#modal-short-desc-count').text(shortDesc.length);
  }

  function updateWordCount() {
    var text = ($('#modal-product-desc').val() || '').replace(/<[^>]*>/g, '').trim();
    var words = text ? text.split(/\s+/).length : 0;
    $('#modal-product-desc-words').text(words);
  }

  $(document).on('input', '#modal-seo-title, #modal-meta-desc, #modal-short-desc', updateCharCounts);
  $(document).on('input', '#modal-product-desc', updateWordCount);

  // ── Scan batch (leer scores sin IA) ─────────────────
  function startScan($triggerBtn, postType) {
    // Obtener o crear el wrap de progreso del scan
    var wrapId = 'hcsai-scan-wrap-' + ($triggerBtn.attr('id') || 'btn');
    var $card  = $triggerBtn.closest('.hup-card, .hup-seo-card');
    var $wrap  = $card.find('#' + wrapId);
    var $fill, $log, $curr, $tot, $pct;

    if (!$wrap.length) {
      $wrap = $(
        '<div id="' + wrapId + '" class="hcsai-scan-progress hup-notice">' +
        '<div class="hcsai-scan-progress__body">' +
        '<div class="hup-inline"><strong>Escaneando contenido</strong>' +
        '<span><span class="sc-curr">0</span> / <span class="sc-tot">?</span> (<span class="sc-pct">0</span>%)</span></div>' +
        '<div class="hup-progress"><span class="sc-fill"></span></div>' +
        '<div class="sc-log"></div>' +
        '</div>'
      );
      // Insertar siempre fuera del form — directamente en el card
      var $form = $triggerBtn.closest('form');
      if ($form.length) {
        $form.after($wrap);
      } else {
        $card.append($wrap);
      }
    } else {
      $wrap.find('.sc-fill').css('width', '0%');
      $wrap.find('.sc-log').empty();
      $wrap.find('.sc-curr, .sc-pct').text('0');
      $wrap.find('.sc-tot').text('?');
      $wrap.show();
    }

    // Referencias directas — no buscar IDs globales en callbacks
    $fill = $wrap.find('.sc-fill');
    $log  = $wrap.find('.sc-log');
    $curr = $wrap.find('.sc-curr');
    $tot  = $wrap.find('.sc-tot');
    $pct  = $wrap.find('.sc-pct');

    function scanLog(msg, type) {
      // .text(): msg puede incluir mensajes de error que vienen del servidor.
      $log.append(
        $('<div></div>')
          .addClass(type === 'ok' ? 'is-ok' : type === 'err' ? 'is-error' : '')
          .text('[' + new Date().toLocaleTimeString() + '] ' + msg)
      );
      $log.scrollTop($log[0].scrollHeight);
    }

    if (!$triggerBtn.data('orig-label')) { $triggerBtn.data('orig-label', $triggerBtn.html()); }
    $triggerBtn.prop('disabled', true).html('<span class="hup-spinner"></span>Escaneando...');

    var total  = 0;
    var done   = 0;
    var manual = 0;
    var ai     = 0;

    function restoreBtn() {
      $triggerBtn.prop('disabled', false).html($triggerBtn.data('orig-label') || '<span class="dashicons dashicons-search" aria-hidden="true"></span>Escanear');
    }

    function finish() {
      scanLog('Listo: ' + ai + ' IA · ' + manual + ' manuales · ' + (total - ai - manual) + ' sin SEO.', 'ok');
      restoreBtn();
      // Refrescar contadores del dashboard si están en el DOM
      ajax('scan_stats', { post_type: postType }).done(function (rs) {
        if (rs.success && rs.data && rs.data.with_seo !== undefined) {
          $('.hup-stat').first().find('.hup-stat__value').text(rs.data.with_seo + ' / ' + rs.data.total);
          $('.hup-stat').eq(1).find('.hup-stat__value').text(rs.data.without_seo);
        }
      });
    }

    // Los IDs se piden por páginas. Antes se traían todos de una sola vez
    // (posts_per_page: -1), lo que en catálogos grandes cargaba miles de IDs
    // en una única consulta y una única respuesta JSON.
    function fetchPage(page) {
      ajax('scan_ids', { post_type: postType, paged: page })
        .done(function (r) {
          if (!r.success || !r.data || !r.data.ids || !r.data.ids.length) {
            if (page === 1) {
              scanLog('Sin items para escanear.');
              restoreBtn();
            } else {
              finish();
            }
            return;
          }

          if (page === 1) {
            total = r.data.total || r.data.ids.length;
            $tot.text(total);
            scanLog('Escaneando ' + total + ' items — leyendo campos SEO sin IA...');
          }

          scanPage(r.data.ids, page, r.data.pages || 1);
        })
        .fail(function (xhr) {
          scanLog('Error HTTP ' + xhr.status + ' al pedir la página ' + page + '.', 'err');
          restoreBtn();
        });
    }

    function scanPage(ids, page, pages) {
      var i = 0;

      function nextScan() {
        if (i >= ids.length) {
          if (page < pages) { fetchPage(page + 1); } else { finish(); }
          return;
        }

        var id = ids[i];
        ajax('scan', { post_id: id })
          .done(function (r2) {
            i++;
            done++;
            var p = total ? Math.round((done / total) * 100) : 0;
            $fill.css('width', p + '%');
            $curr.text(done);
            $pct.text(p);

            if (r2.success) {
              var score  = r2.data.score || 0;
              var status = r2.data.status || 'manual';
              if (status === 'ai') { ai++; } else { manual++; }

              var $row = $('tr[data-post-id="' + id + '"]').first();
              if ($row.length) {
                updateScoreBadge($row, score);
                if (status !== 'ai' && score > 0) {
                  generatedCell($row).html('<span class="hup-badge hup-badge--dot">Manual</span>');
                } else if (score === 0) {
                  generatedCell($row).html('<span class="hup-badge hup-badge--warning">Pendiente</span>');
                }
              }
            } else {
              scanLog('#' + id + ' → ' + ((r2.data && r2.data.message) || 'error'), 'err');
            }
            setTimeout(nextScan, 80);
          })
          .fail(function (xhr) {
            i++;
            done++;
            scanLog('#' + id + ' → Error HTTP ' + xhr.status, 'err');
            setTimeout(nextScan, 300);
          });
      }

      nextScan();
    }

    fetchPage(1);
  }

  // Botón Escanear en la vista de Productos
  $(document).on('click', '#btn-scan-products', function () {
    var postType = $(this).data('post-type') || 'product';
    startScan($(this), postType);
  });

  // #btn-scan-dashboard ahora es un enlace <a> — no necesita listener JS


  // ── Create post ─────────────────────────────────────
  $(document).on('click', '#btn-create-post', function () {
    var $btn = $(this);
    var topic = $('#create-topic').val().trim();
    if (!topic) { toast('Ingresa un tema o keyword', 'error'); return; }

    $btn.prop('disabled', true).html('<span class="hup-spinner"></span>Creando...');
    var $result = $('.hup-seo-create-result');

    ajax('create_post', {
      topic: topic,
      tone: $('#create-tone').val(),
      length: $('#create-length').val(),
      category_id: $('#create-category').val()
    })
      .done(function (r) {
        if (r.success) {
          var postTitle = r.data.post_title || 'Post';
          // El título lo genera la IA y edit_url va en un atributo: se arma con
          // jQuery en vez de concatenar.
          var $notice = $('<div class="hup-notice hup-notice--success"></div>')
            .append('<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>')
            .append(document.createTextNode('Post creado como borrador: '))
            .append($('<strong></strong>').text(postTitle))
            .append('<br>')
            .append(
              $('<a target="_blank"></a>')
                .attr('href', r.data.edit_url)
                .text('Editar "' + postTitle + '"')
            )
            .append(document.createTextNode(' — Tokens: ' + r.data.tokens));
          $result.prop('hidden', false).empty().append($notice);
          toast('Post creado exitosamente');
        } else {
          $result.prop('hidden', false).empty().append(
            $('<div class="hup-notice hup-notice--error"></div>').text(r.data.message)
          );
          toast(r.data.message, 'error');
        }
      })
      .fail(function () { toast('Error de red', 'error'); })
      .always(function () { $btn.prop('disabled', false).text('Crear post con IA'); });
  });

  // ── Improve post ────────────────────────────────────
  var IMPROVE_LABELS = {
    post_title: 'Título',
    seo_title: 'Título SEO',
    meta_description: 'Meta descripción',
    focus_keyword: 'Palabra clave',
    og_title: 'Título para redes sociales',
    og_description: 'Descripción para redes sociales',
    short_description: 'Extracto',
    alt_text: 'Alt text de la imagen destacada',
    tags: 'Etiquetas'
  };

  $(document).on('click', '#btn-improve-post', function () {
    var $btn = $(this);
    var postId = $('#improve-post-id').val();
    if (!postId) { toast('Selecciona un post', 'error'); return; }

    $btn.prop('disabled', true).html('<span class="hup-spinner"></span>Mejorando...');
    var $result = $('.hup-seo-improve-result');

    ajax('improve_post', {
      post_id: postId,
      improve_content: $('#improve-content').is(':checked') ? 1 : 0,
      improve_seo: $('#improve-seo').is(':checked') ? 1 : 0,
      add_cta: $('#improve-cta').is(':checked') ? 1 : 0,
      improve_tags: $('#improve-tags').is(':checked') ? 1 : 0
    })
      .done(function (r) {
        if (r.success) {
          var d = r.data.improved;
          var $body = $('<div class="hup-card__body"></div>');

          // La vista previa muestra TODO lo que se va a aplicar, contenido
          // incluido. Antes solo enseñaba cuatro campos y el artículo se
          // reescribía a ciegas.
          $.each(IMPROVE_LABELS, function (key, label) {
            var val = d[key];
            if (!val || (Array.isArray(val) && !val.length)) return;
            $body.append(
              $('<p></p>')
                .append($('<strong></strong>').text(label + ': '))
                .append(document.createTextNode(Array.isArray(val) ? val.join(', ') : val))
            );
          });

          if (d.post_content) {
            if (r.data.se_pierde && r.data.se_pierde.length) {
              $body.append(
                $('<div class="hup-notice hup-notice--warning"></div>').text(
                  'El post original tiene ' + r.data.se_pierde.join(', ') +
                  '. La versión mejorada se reescribe como texto y no los conserva.'
                )
              );
            }
            // El HTML ya viene pasado por wp_kses_post en el servidor: es el
            // mismo filtro que WordPress aplica al contenido que muestra.
            $body.append(
              $('<details class="hup-accordion hup-seo-improve-preview" open></details>')
                .append($('<summary></summary>').text('Contenido propuesto'))
                .append($('<div class="hup-seo-improve-preview__body"></div>').html(d.post_content))
            );
            $body.append(
              $('<p class="hup-hint"></p>').text(
                r.data.revision
                  ? 'Al aplicar se guarda una revisión: puedes volver a la versión anterior desde el editor.'
                  : 'Este sitio tiene las revisiones desactivadas: el contenido actual no se podrá recuperar.'
              )
            );
          }

          var $apply = $('<button type="button" class="hup-btn hup-seo-apply-improvement">Aplicar mejoras</button>');

          $result.prop('hidden', false).empty()
            .append($('<div class="hup-notice hup-notice--success"></div>').text('Mejoras generadas (tokens: ' + r.data.tokens + ')'))
            .append($('<div class="hup-card"></div>').append($body).append($('<div class="hup-card__foot"></div>').append($apply)));

          // El objeto se pasa por .data(), no serializado dentro de un atributo:
          // JSON.stringify no escapa comillas simples, así que un apóstrofo en el
          // texto de la IA cortaba el atributo y el botón se quedaba sin datos
          // (aplicaba "con éxito" sin aplicar nada).
          $apply.data({ 'post-id': postId, improved: d });

          toast('Mejoras generadas');
        } else {
          $result.prop('hidden', false).empty().append(
            $('<div class="hup-notice hup-notice--error"></div>').text(r.data.message)
          );
        }
      })
      .fail(function () { toast('Error de red', 'error'); })
      .always(function () { $btn.prop('disabled', false).text('Mejorar con IA'); });
  });

  // Apply improvement
  $(document).on('click', '.hup-seo-apply-improvement', function () {
    var $btn = $(this);
    var postId = $btn.data('post-id');
    var data = $btn.data('improved');

    $btn.prop('disabled', true).text('Aplicando...');

    // Se envía todo lo generado: el servidor ya solo devolvió los campos de
    // las opciones marcadas, y vuelve a filtrarlos contra el registro. Antes se
    // elegían aquí a mano y quedaban fuera el extracto y los Open Graph, que se
    // pagaban sin aplicarse (y el Writer ponía la meta como extracto).
    var payload = $.extend({}, data, { post_id: postId });

    ajax('apply_improvement', payload)
      .done(function (r) {
        if (r.success) {
          toast('Mejoras aplicadas al post');
          $btn.text('✓ Aplicado').addClass('hup-btn--secondary').removeClass('');
        } else {
          toast(r.data.message, 'error');
          $btn.prop('disabled', false).text('Aplicar mejoras');
        }
      })
      .fail(function () {
        toast('Error de red', 'error');
        $btn.prop('disabled', false).text('Aplicar mejoras');
      });
  });

  // ── Calculator ──────────────────────────────────────
  // Costos por token: los provee PHP vía wp_localize_script (Settings::token_costs()),
  // que es la única fuente de verdad y sí pasa por el filtro hcsai_token_costs.
  // Antes había aquí una copia a mano que se desincronizaba del catálogo.
  var modelCosts = (typeof hcsai !== 'undefined' && hcsai.costs) ? hcsai.costs : {};
  var modelCostsIn = (typeof hcsai !== 'undefined' && hcsai.costs_in) ? hcsai.costs_in : {};
  var FALLBACK_COST = 0.000001;
  var FALLBACK_COST_IN = 0.0000002;
  // Tokens promedio por generación. Antes solo se contaba la salida, y el
  // prompt —con el contenido del producto o el post— se cobra igual.
  var AVG_TOKENS_IN = 1000;
  var AVG_TOKENS_OUT = 800;

  // Costo en USD de una generación promedio con el modelo dado.
  // parseFloat: wp_localize_script puede entregar los números como cadena.
  function costPerTask(model) {
    var costOut = parseFloat(modelCosts[model]) || FALLBACK_COST;
    var costIn = parseFloat(modelCostsIn[model]) || FALLBACK_COST_IN;
    return AVG_TOKENS_IN * costIn + AVG_TOKENS_OUT * costOut;
  }

  function calcCost() {
    var model = $('#calc-model').val();
    var tasks = parseInt($('#calc-tasks').val()) || 0;
    var clpRate = parseInt($('#calc-clp').val()) || 960;
    var perTask = costPerTask(model);
    var totalUsd = tasks * perTask;
    var totalClp = totalUsd * clpRate;

    $('#calc-result-usd').text('$' + totalUsd.toLocaleString('es-CL', { minimumFractionDigits: 4, maximumFractionDigits: 4 }));
    $('#calc-result-clp').text('$' + Math.round(totalClp).toLocaleString('es-CL'));

    // Actualizar tabla de costos referenciales
    updateCalcTable(model, clpRate, perTask);
  }

  function updateCalcTable(model, clpRate, perTask) {
    var volumes = [100, 250, 500, 750, 1000];
    var tbody = $('#calc-ref-body');
    if (!tbody.length) return;

    var $row = $('<tr></tr>').append($('<td></td>').append($('<strong></strong>').text(model)));
    volumes.forEach(function(vol) {
      var usd = vol * perTask;
      var clp = Math.round(usd * clpRate);
      $row.append($('<td></td>').text('$' + clp.toLocaleString('es-CL')));
    });
    tbody.empty().append($row);
  }

  $(document).on('change input', '#calc-model, #calc-tasks, #calc-clp', calcCost);

  // ── Test API — mejor UX ────────────────────────────
  // Actualizar badge de estado después de test exitoso
  $(document).on('click', '.hup-seo-test-api', function () {
    var $btn = $(this);
    var provider = $btn.data('provider');
    var $block = $btn.closest('.hup-card');
    var model = $block.find('.hup-seo-model-select').val();
    var $result = $block.find('.hup-seo-conn-result');
    var $status = $block.find('.hup-badge').first();
    var inputKey = $block.find('.hup-seo-api-key-input').val() || '';

    $btn.prop('disabled', true).html('<span class="hup-spinner"></span>Probando...');
    $result.text('').removeClass('hup-seo-conn-ok hup-seo-conn-error');

    ajax('test_api', { provider: provider, model: model, api_key: inputKey })
      .done(function (r) {
        if (r.success) {
          $result.text(r.data.message).addClass('hup-seo-conn-ok');
          $status.attr('class', 'hup-badge hup-badge--success hup-badge--dot').text('Verificada');
          toast('Conexión exitosa con ' + provider);
        } else {
          $result.text(r.data.message).addClass('hup-seo-conn-error');
          $status.attr('class', 'hup-badge hup-badge--danger hup-badge--dot').text('Error de conexión');
          toast(r.data.message, 'error');
        }
      })
      .fail(function () {
        $result.text('Error de red').addClass('hup-seo-conn-error');
        toast('Error de conexión', 'error');
      })
      .always(function () {
        $btn.prop('disabled', false).html('<span class="dashicons dashicons-admin-network" aria-hidden="true"></span>Probar conexión');
      });
  });

  // ── Init ────────────────────────────────────
  $(function () {
    // Trigger calc on load
    if ($('#calc-model').length) calcCost();

    // Auto-scroll al anchor #hcsai-scan si viene del dashboard
    if (window.location.hash === '#hcsai-scan') {
      var $target = $('#hcsai-scan');
      if ($target.length) {
        setTimeout(function () {
          $('html, body').animate({ scrollTop: $target.offset().top - 40 }, 400);
          $target.addClass('hcsai-highlight');
          setTimeout(function () { $target.removeClass('hcsai-highlight'); }, 2000);
        }, 300);
      }
    }
  });

})(jQuery);
