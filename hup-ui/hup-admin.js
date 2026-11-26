/* HUP Admin UI 1.4.0 — comportamientos mínimos. Sin dependencias. */
(function () {
  'use strict';
  var doc = document;
  var uid = 0;
  var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  window.HUP = window.HUP || {};

  function isVisible(el) { return !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length); }

  /* Copiar: <button data-hup-copy="#id"> */
  doc.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-hup-copy]');
    if (!btn) return;
    var src = doc.querySelector(btn.getAttribute('data-hup-copy'));
    if (!src) return;
    /* Desde un campo se copia lo que tiene escrito; desde <code> u otro elemento, su texto. */
    var text = (/^(INPUT|TEXTAREA)$/.test(src.tagName) ? src.value : src.textContent).trim();
    var done = function () {
      var label = btn.querySelector('.hup-btn__label') || btn;
      var prev = label.textContent;
      label.textContent = 'Copiado';
      setTimeout(function () { label.textContent = prev; }, 1600);
    };
    if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(text).then(done); return; }
    /* Sin HTTPS (p. ej. sitios locales) no existe navigator.clipboard. */
    var ta = doc.createElement('textarea');
    ta.value = text; ta.setAttribute('readonly', ''); ta.style.position = 'fixed'; ta.style.opacity = '0';
    doc.body.appendChild(ta); ta.select(); doc.execCommand('copy'); ta.remove(); done();
  });

  /* Mostrar/ocultar valores sensibles:
     <button type="button" class="hup-btn hup-btn--icon" data-hup-reveal="#api_key" aria-label="Mostrar clave" aria-pressed="false">
       <span class="dashicons dashicons-visibility" aria-hidden="true"></span></button>
     Cambia el ícono (visibility / hidden), aria-pressed y el texto accesible. */
  doc.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-hup-reveal]');
    if (!btn) return;
    var input = doc.querySelector(btn.getAttribute('data-hup-reveal'));
    if (!input) return;
    var show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    btn.setAttribute('aria-pressed', show ? 'true' : 'false');
    var label = show ? 'Ocultar clave' : 'Mostrar clave';
    var txt = btn.querySelector('.hup-btn__label');
    if (txt) txt.textContent = show ? 'Ocultar' : 'Mostrar'; else btn.setAttribute('aria-label', label);
    if (btn.hasAttribute('title')) btn.setAttribute('title', label);
    var icon = btn.querySelector('.dashicons');
    if (icon) { icon.classList.toggle('dashicons-visibility', !show); icon.classList.toggle('dashicons-hidden', show); }
  });

  /* Confirmación para acciones destructivas: <button data-hup-confirm="¿Seguro?"> */
  doc.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-hup-confirm]');
    if (btn && !window.confirm(btn.getAttribute('data-hup-confirm'))) e.preventDefault();
  });

  /* Toast: HUP.toast('Cambios guardados') · error: HUP.toast('No se pudo guardar', 'error')
     Opcional: HUP.toast(msg, tipo, { duration: ms }) · duration 0 = queda hasta cerrarlo.
     Dura 5 s (error 10 s) más un poco si el texto es largo; se pausa con el puntero o el foco encima
     y tiene botón de cerrar. Devuelve { close }. */
  HUP.toast = function (msg, type, opts) {
    opts = opts || {};
    msg = String(msg == null ? '' : msg);
    var isError = type === 'error';
    var host = doc.querySelector('.hup-app') || doc.body;
    var stack = null;
    for (var i = 0; i < host.children.length; i++) { if (host.children[i].classList.contains('hup-toasts')) { stack = host.children[i]; break; } }
    var fresh = !stack;
    if (fresh) { stack = doc.createElement('div'); stack.className = 'hup-toasts'; host.appendChild(stack); }

    var el = doc.createElement('div');
    el.className = 'hup-toast' + (isError ? ' hup-toast--error' : '');
    el.setAttribute('role', isError ? 'alert' : 'status');
    el.innerHTML = '<span class="dashicons dashicons-' + (isError ? 'warning' : 'yes-alt') + '" aria-hidden="true"></span>' +
      '<span class="hup-toast__text"></span>' +
      '<button type="button" class="hup-toast__close" aria-label="Cerrar aviso"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button>';
    el.querySelector('.hup-toast__text').textContent = msg;

    var ms = typeof opts.duration === 'number' ? opts.duration : (isError ? 10000 : 5000) + Math.max(0, msg.length - 60) * 50;
    var timer = null, left = ms, start = 0, paused = 0;
    function close() {
      clearTimeout(timer);
      if (el.parentNode) el.remove();
      if (stack.parentNode && !stack.children.length) stack.remove();
    }
    function run() { if (ms <= 0) return; start = Date.now(); timer = setTimeout(close, Math.max(left, 1500)); }
    function pause() { if (paused++ === 0 && ms > 0) { clearTimeout(timer); left -= Date.now() - start; } }
    function resume() { if (paused > 0 && --paused === 0) run(); }
    el.addEventListener('mouseenter', pause);
    el.addEventListener('mouseleave', resume);
    el.addEventListener('focusin', pause);
    el.addEventListener('focusout', resume);
    el.querySelector('.hup-toast__close').addEventListener('click', close);

    /* Si el contenedor es nuevo, se espera un momento: los lectores de pantalla
       no anuncian un aviso que aparece en el mismo instante que su contenedor. */
    var show = function () { stack.appendChild(el); run(); };
    if (fresh) setTimeout(show, 60); else show();
    return { close: close };
  };

  /* Modal: <button data-hup-modal="#mi-modal">; dentro, [data-hup-close] cierra.
     Para cualquier .hup-modal que se muestre (por este atributo o quitándole `hidden` desde el plugin):
     - pone role="dialog" y aria-modal en la caja y la nombra con su primer título;
     - lleva el foco adentro y lo mantiene ahí con Tab;
     - Escape cierra (pulsa su [data-hup-close] si existe, para que corra la lógica del plugin);
       con data-hup-static en el .hup-modal, Escape y el clic en el fondo no cierran;
     - al cerrar, devuelve el foco al elemento que lo abrió. */
  var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), summary, [tabindex]:not([tabindex="-1"])';
  function modalBox(m) { return m.querySelector('.hup-modal__box') || m; }
  function openModals() { return [].filter.call(doc.querySelectorAll('.hup-modal:not([hidden])'), isVisible); }
  function focusables(box) { return [].filter.call(box.querySelectorAll(FOCUSABLE), isVisible); }

  function onModalShow(m) {
    if (m.hupOpen) return;
    m.hupOpen = true;
    m.hupOpener = doc.activeElement;
    var box = modalBox(m);
    if (!box.hasAttribute('role')) box.setAttribute('role', 'dialog');
    box.setAttribute('aria-modal', 'true');
    if (!box.hasAttribute('aria-labelledby') && !box.hasAttribute('aria-label')) {
      var title = box.querySelector('h1, h2, h3, .hup-card__title');
      if (title) { if (!title.id) title.id = 'hup-modal-title-' + (++uid); box.setAttribute('aria-labelledby', title.id); }
    }
    if (!box.hasAttribute('tabindex')) box.setAttribute('tabindex', '-1');
    setTimeout(function () {
      if (m.hidden || box.contains(doc.activeElement)) return;
      var target = box.querySelector('[autofocus]') || focusables(box)[0] || box;
      target.focus();
    }, 30);
  }
  function onModalHide(m) {
    if (!m.hupOpen) return;
    m.hupOpen = false;
    var opener = m.hupOpener;
    m.hupOpener = null;
    var active = doc.activeElement;
    if (opener && opener.focus && doc.contains(opener) && (!active || active === doc.body || m.contains(active))) opener.focus();
  }
  function syncModal(m) { if (m.hidden || !isVisible(m)) onModalHide(m); else onModalShow(m); }
  function closeModal(m) {
    var btn = m.querySelector('[data-hup-close]');
    if (btn && !btn.disabled) btn.click(); else if (requestClose(m)) m.hidden = true;
  }

  /* Antes de cerrar por [data-hup-close], el fondo o Escape se dispara en el .hup-modal el evento
     cancelable `hup:modal-close`. Para impedir el cierre (p. ej. cambios sin guardar):
     modal.addEventListener('hup:modal-close', function (e) { if (!confirm('¿Cerrar sin guardar?')) e.preventDefault(); }); */
  function requestClose(m) {
    var ev;
    try { ev = new CustomEvent('hup:modal-close', { bubbles: true, cancelable: true }); }
    catch (err) { ev = doc.createEvent('CustomEvent'); ev.initCustomEvent('hup:modal-close', true, true, null); }
    return m.dispatchEvent(ev);
  }

  doc.addEventListener('click', function (e) {
    var open = e.target.closest('[data-hup-modal]');
    if (open) { var m = doc.querySelector(open.getAttribute('data-hup-modal')); if (m) { m.hupOpener = open; m.hidden = false; } return; }
    var close = e.target.closest('[data-hup-close]');
    var backdrop = e.target.classList && e.target.classList.contains('hup-modal') && !e.target.hasAttribute('data-hup-static');
    if (close || backdrop) { var mm = e.target.closest('.hup-modal'); if (mm && requestClose(mm)) mm.hidden = true; }
  });

  /* Tooltips (.hup-tip): nombre accesible, Escape los oculta y no se salen de la pantalla. */
  function prepTip(t) {
    if (t.hupReady) return;
    t.hupReady = true;
    var text = t.getAttribute('data-tip') || '';
    if (!t.hasAttribute('aria-label')) t.setAttribute('aria-label', text);
    if (!t.hasAttribute('role')) t.setAttribute('role', 'img');
    if (!t.hasAttribute('tabindex')) t.tabIndex = 0;
    var icon = t.querySelector('.dashicons');
    if (icon) icon.setAttribute('aria-hidden', 'true');
  }
  var measureCtx = null;
  function placeTip(t) {
    prepTip(t);
    t.classList.remove('is-dismissed');
    t.style.removeProperty('--hup-tip-shift');
    var vw = doc.documentElement.clientWidth;
    var max = Math.min(260, vw - 32);
    if (!measureCtx) measureCtx = doc.createElement('canvas').getContext('2d');
    var width = max;
    if (measureCtx) {
      measureCtx.font = '12px ' + window.getComputedStyle(t).fontFamily;
      width = Math.min(max, measureCtx.measureText(t.getAttribute('data-tip') || '').width + 26);
    }
    var r = t.getBoundingClientRect();
    var left = r.left + r.width / 2 - width / 2;
    var shift = 0;
    if (left < 16) shift = 16 - left;
    else if (left + width > vw - 16) shift = vw - 16 - (left + width);
    if (shift) t.style.setProperty('--hup-tip-shift', Math.round(shift) + 'px');
  }
  doc.addEventListener('mouseover', function (e) { var t = e.target.closest && e.target.closest('.hup-tip'); if (t && !t.contains(e.relatedTarget)) placeTip(t); });
  doc.addEventListener('focusin', function (e) { var t = e.target.closest && e.target.closest('.hup-tip'); if (t) placeTip(t); });

  doc.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' || e.key === 'Esc') {
      var tips = doc.querySelectorAll('.hup-tip:hover, .hup-tip:focus');
      for (var i = 0; i < tips.length; i++) tips[i].classList.add('is-dismissed');
      if (e.target.closest && e.target.closest('.hup-tip')) return;
      var list = openModals();
      if (!list.length) return;
      var top = list[list.length - 1];
      if (top.hasAttribute('data-hup-static')) return;
      e.preventDefault();
      closeModal(top);
      return;
    }
    if (e.key !== 'Tab') return;
    var mods = openModals();
    if (!mods.length) return;
    var box = modalBox(mods[mods.length - 1]);
    var items = focusables(box);
    if (!items.length) { e.preventDefault(); box.focus(); return; }
    var first = items[0], last = items[items.length - 1], active = doc.activeElement;
    if (!box.contains(active)) { e.preventDefault(); (e.shiftKey ? last : first).focus(); }
    else if (e.shiftKey && (active === first || active === box)) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && active === last) { e.preventDefault(); first.focus(); }
  });

  /* Menú en móvil: la pestaña activa queda a la vista (también al cambiar de pestaña sin recargar). */
  function centerNav(smooth) {
    [].forEach.call(doc.querySelectorAll('.hup-nav__list'), function (list) {
      if (list.scrollWidth <= list.clientWidth + 1) return;
      var a = list.querySelector('.hup-nav__item.is-active');
      if (!a) return;
      var lr = list.getBoundingClientRect(), ar = a.getBoundingClientRect();
      var to = list.scrollLeft + (ar.left + ar.width / 2) - (lr.left + lr.width / 2);
      if (smooth && !reduceMotion && list.scrollTo) list.scrollTo({ left: to, behavior: 'smooth' }); else list.scrollLeft = to;
    });
  }
  doc.addEventListener('click', function (e) {
    if (e.target.closest && e.target.closest('.hup-nav__item')) setTimeout(function () { centerNav(true); }, 0);
  });

  /* Tablas: desplazamiento propio. Se envuelven las .hup-table que no estén ya dentro de un contenedor con scroll. */
  function wrapTables(root) {
    [].forEach.call((root || doc).querySelectorAll('.hup-app table.hup-table'), function (t) {
      var p = t.parentElement;
      if (!p || p.classList.contains('hup-table-wrap')) return;
      var ox = window.getComputedStyle(p).overflowX;
      if (ox === 'auto' || ox === 'scroll') return;
      var w = doc.createElement('div');
      w.className = 'hup-table-wrap';
      p.insertBefore(w, t);
      w.appendChild(t);
    });
    [].forEach.call((root || doc).querySelectorAll('.hup-table-wrap'), function (w) {
      /* Solo enfocable (para moverla con el teclado) si de verdad se desplaza. */
      if (w.scrollWidth > w.clientWidth + 1) { if (!w.hasAttribute('tabindex')) { w.tabIndex = 0; w.setAttribute('role', 'region'); if (!w.hasAttribute('aria-label')) w.setAttribute('aria-label', 'Tabla desplazable'); } }
    });
  }
  HUP.refresh = function (root) { wrapTables(root); [].forEach.call((root || doc).querySelectorAll('.hup-tip'), prepTip); };

  function init() {
    HUP.refresh();
    centerNav(false);
    [].forEach.call(doc.querySelectorAll('.hup-modal'), syncModal);
    if (window.MutationObserver) {
      new MutationObserver(function (records) {
        records.forEach(function (r) {
          if (r.type === 'attributes' && r.target.classList && r.target.classList.contains('hup-modal')) syncModal(r.target);
        });
      }).observe(doc.body, { subtree: true, attributes: true, attributeFilter: ['hidden', 'style', 'class'] });
    }
    var resizeTimer;
    window.addEventListener('resize', function () { clearTimeout(resizeTimer); resizeTimer = setTimeout(function () { centerNav(false); wrapTables(); }, 150); });
  }
  if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', init); else init();

  /* Lista ordenable: requiere wp_enqueue_script('jquery-ui-sortable') */
  if (window.jQuery && jQuery.fn.sortable) jQuery(function ($) { $('.hup-sortable').sortable({ handle: '.hup-handle', items: '> li:not(.is-locked)', placeholder: 'ui-sortable-placeholder' }); });
}());

/* Filas repetibles: <div class="hup-repeater" data-hup-repeater><template>fila</template>…<button data-hup-add></button></div> */
document.addEventListener('click', function (e) {
  var add = e.target.closest('[data-hup-add]');
  if (add) { var r = add.closest('[data-hup-repeater]'); var tpl = r && r.querySelector('template'); if (tpl) add.before(tpl.content.cloneNode(true)); return; }
  var del = e.target.closest('[data-hup-remove]');
  if (del) del.closest('.hup-repeater__row').remove();
});
