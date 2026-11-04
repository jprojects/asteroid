/*
 * Asteroid · chat web: script que se carga en la web del cliente (WebchatWidgetPage::actionLoader lo envuelve en
 * (function (cfg) { … })(config)). Pinta el botón flotante y abre el chat en un iframe servido por Asteroid; el iframe
 * avisa por postMessage de los mensajes sin leer y de cuándo cerrar. Sin dependencias ni estilos globales.
 */
'use strict';
if (window.__asteroidWebchat) return;
window.__asteroidWebchat = true;

var host = location.hostname.toLowerCase();
if (cfg.domains.length && !cfg.domains.some(function (d) {
  d = d.replace(/:\d+$/, '');
  return host === d || (d.indexOf('*.') === 0 && host.slice(-(d.length - 1)) === d.slice(1));
})) return;

var origin = new URL(cfg.frame).origin;
var store = 'asteroid-webchat:' + cfg.frame;
var lang = ((document.documentElement.lang || navigator.language || 'ca') + '').slice(0, 2).toLowerCase();
var side = cfg.position === 'left' ? 'left' : 'right';
var P = 'asteroid-wc';

var css = document.createElement('style');
css.textContent =
  '.' + P + '-btn{position:fixed;bottom:20px;' + side + ':20px;z-index:2147483000;width:58px;height:58px;border:0;border-radius:50%;' +
  'background:' + cfg.color + ';color:#fff;cursor:pointer;box-shadow:0 6px 20px rgba(0,0,0,.25);display:grid;place-items:center;padding:0;transition:transform .15s}' +
  '.' + P + '-btn:hover{transform:scale(1.06)}.' + P + '-btn svg{width:28px;height:28px}' +
  '.' + P + '-badge{position:absolute;top:-2px;right:-2px;min-width:20px;height:20px;padding:0 5px;border-radius:10px;background:#dc2626;' +
  'color:#fff;font:700 12px/20px system-ui,sans-serif;text-align:center;display:none}' +
  '.' + P + '-panel{position:fixed;bottom:90px;' + side + ':20px;z-index:2147483000;width:370px;height:560px;max-height:calc(100vh - 110px);' +
  'border-radius:14px;overflow:hidden;box-shadow:0 12px 40px rgba(0,0,0,.25);background:#fff;display:none}' +
  '.' + P + '-panel.is-open{display:block}.' + P + '-panel iframe{width:100%;height:100%;border:0;display:block}' +
  '@media (max-width:520px){.' + P + '-panel{inset:0;width:auto;height:auto;max-height:none;border-radius:0}.' + P + '-btn.is-open{display:none}}';
document.head.appendChild(css);

var btn = document.createElement('button');
btn.type = 'button';
btn.className = P + '-btn';
btn.setAttribute('aria-label', cfg.label + (cfg.name ? ' · ' + cfg.name : ''));
btn.setAttribute('aria-expanded', 'false');
btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
  '<path d="M2.992 16.342a2 2 0 0 1 .094 1.167l-1.065 3.29a1 1 0 0 0 1.236 1.168l3.413-.998a2 2 0 0 1 1.099.092 10 10 0 1 0-4.777-4.719"/></svg>' +
  '<span class="' + P + '-badge"></span>';
var badge = btn.querySelector('.' + P + '-badge');

var panel = document.createElement('div');
panel.className = P + '-panel';
var frame = null;

function ensureFrame() {
  if (frame) return;
  frame = document.createElement('iframe');
  frame.title = cfg.name || cfg.label;
  frame.allow = 'clipboard-write';
  frame.src = cfg.frame + '&lang=' + encodeURIComponent(lang) + '&page=' + encodeURIComponent(location.href.slice(0, 500));
  panel.appendChild(frame);
}
function post(msg) {
  if (frame && frame.contentWindow) frame.contentWindow.postMessage(Object.assign({ type: 'asteroid-webchat' }, msg), origin);
}
function open() {
  ensureFrame();
  panel.classList.add('is-open');
  btn.classList.add('is-open');
  btn.setAttribute('aria-expanded', 'true');
  badge.style.display = 'none';
  post({ open: true });
  frame.addEventListener('load', function () { post({ open: true }); }, { once: true });
}
function close() {
  panel.classList.remove('is-open');
  btn.classList.remove('is-open');
  btn.setAttribute('aria-expanded', 'false');
  post({ open: false });
  btn.focus();
}
btn.addEventListener('click', function () { panel.classList.contains('is-open') ? close() : open(); });
window.addEventListener('message', function (e) {
  if (e.origin !== origin || !e.data || e.data.type !== 'asteroid-webchat') return;
  if (e.data.close) close();
  if (e.data.active) { try { localStorage.setItem(store, '1'); } catch (x) {} }
  if (typeof e.data.unread === 'number' && !panel.classList.contains('is-open')) {
    badge.textContent = e.data.unread > 9 ? '9+' : String(e.data.unread);
    badge.style.display = e.data.unread > 0 ? 'block' : 'none';
  }
});
document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && panel.classList.contains('is-open')) close(); });

document.body.appendChild(panel);
document.body.appendChild(btn);
// Si ya tiene una conversación, se carga en segundo plano para avisar de las respuestas
try { if (localStorage.getItem(store) === '1') ensureFrame(); } catch (x) {}
