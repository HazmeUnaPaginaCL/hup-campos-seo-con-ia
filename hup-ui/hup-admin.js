/* HUP Admin UI — comportamientos mínimos. Sin dependencias. */
document.addEventListener('click', function (e) {
  var btn = e.target.closest('[data-hup-copy]');
  if (!btn) return;
  var src = document.querySelector(btn.getAttribute('data-hup-copy'));
  if (!src) return;
  var text = src.textContent.trim();
  var done = function () {
    var label = btn.querySelector('.hup-btn__label') || btn;
    var prev = label.textContent;
    label.textContent = 'Copiado';
    setTimeout(function () { label.textContent = prev; }, 1600);
  };
  if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(text).then(done); return; }
  /* Sin HTTPS (p. ej. sitios locales) no existe navigator.clipboard. */
  var ta = document.createElement('textarea');
  ta.value = text; ta.setAttribute('readonly', ''); ta.style.position = 'fixed'; ta.style.opacity = '0';
  document.body.appendChild(ta); ta.select(); document.execCommand('copy'); ta.remove(); done();
});
/* Mostrar/ocultar valores sensibles: <button data-hup-reveal="#api_key"> */
document.addEventListener('click', function (e) {
  var btn = e.target.closest('[data-hup-reveal]');
  if (!btn) return;
  var input = document.querySelector(btn.getAttribute('data-hup-reveal'));
  if (input) input.type = input.type === 'password' ? 'text' : 'password';
});
/* Confirmación para acciones destructivas: <button data-hup-confirm="¿Seguro?"> */
document.addEventListener('click', function (e) {
  var btn = e.target.closest('[data-hup-confirm]');
  if (btn && !window.confirm(btn.getAttribute('data-hup-confirm'))) e.preventDefault();
});

/* Toast: HUP.toast('Cambios guardados') · error: HUP.toast('No se pudo guardar', 'error') */
window.HUP = window.HUP || {};
HUP.toast = function (msg, type) {
  var el = document.createElement('div');
  var isError = type === 'error';
  el.className = 'hup-toast' + (isError ? ' hup-toast--error' : '');
  el.setAttribute('role', isError ? 'alert' : 'status');
  el.innerHTML = '<span class="dashicons dashicons-' + (isError ? 'warning' : 'yes-alt') + '"></span>';
  el.appendChild(document.createTextNode(msg));
  (document.querySelector('.hup-app') || document.body).appendChild(el);
  setTimeout(function () { el.remove(); }, isError ? 4000 : 2400);
};
/* Modal: <button data-hup-modal="#mi-modal">; dentro, [data-hup-close] cierra */
document.addEventListener('click', function (e) {
  var open = e.target.closest('[data-hup-modal]');
  if (open) { var m = document.querySelector(open.getAttribute('data-hup-modal')); if (m) m.hidden = false; return; }
  var close = e.target.closest('[data-hup-close]');
  if (close || e.target.classList.contains('hup-modal')) { var mm = e.target.closest('.hup-modal'); if (mm) mm.hidden = true; }
});
/* Filas repetibles: <div class="hup-repeater" data-hup-repeater><template>fila</template>…<button data-hup-add></button></div> */
document.addEventListener('click', function (e) {
  var add = e.target.closest('[data-hup-add]');
  if (add) { var r = add.closest('[data-hup-repeater]'); var tpl = r && r.querySelector('template'); if (tpl) add.before(tpl.content.cloneNode(true)); return; }
  var del = e.target.closest('[data-hup-remove]');
  if (del) del.closest('.hup-repeater__row').remove();
});
/* Lista ordenable: requiere wp_enqueue_script('jquery-ui-sortable') */
if (window.jQuery && jQuery.fn.sortable) jQuery(function ($) { $('.hup-sortable').sortable({ handle: '.hup-handle', items: '> li:not(.is-locked)', placeholder: 'ui-sortable-placeholder' }); });
