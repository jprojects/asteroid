/*
 * Asteroid · chat web: el chat dentro del iframe (plantilla pages/webchat_widget.php, configuración en
 * <body data-config>). El visitante se identifica con un token aleatorio en el localStorage del iframe (cabecera
 * X-Visitor). Pregunta por respuestas cada 4 s con el chat abierto y cada 25 s cerrado, y avisa a la web (loader.js)
 * de las que no ha visto.
 */
(function () {
  'use strict';
  var cfg = JSON.parse(document.body.dataset.config);
  var T = cfg.i18n;
  var $ = function (s) { return document.querySelector(s); };
  var list = $('#wc-list'), form = $('#wc-form'), input = $('#wc-body'), who = $('#wc-who'), note = $('#wc-note'), err = $('#wc-error');
  function fail(text) { err.textContent = text || T.error; err.hidden = false; }
  var tokenKey = 'asteroid-webchat-token:' + cfg.key;
  var token = null;
  try { token = localStorage.getItem(tokenKey); } catch (e) {}
  if (!token || !/^[a-f0-9]{32,64}$/.test(token)) {
    var b = new Uint8Array(16);
    crypto.getRandomValues(b);
    token = Array.prototype.map.call(b, function (x) { return ('0' + x.toString(16)).slice(-2); }).join('');
    try { localStorage.setItem(tokenKey, token); } catch (e) {}
  }
  var lang = new URLSearchParams(location.search).get('lang') || 'ca';
  var last = 0, open = false, unread = 0, status = 'new', timer = null, sending = false;

  function tell(msg) { parent.postMessage(Object.assign({ type: 'asteroid-webchat' }, msg), '*'); }
  function time(iso) { var d = new Date(iso); return ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2); }
  function nearBottom() { return list.scrollHeight - list.scrollTop - list.clientHeight < 80; }

  function add(m) {
    if (document.getElementById('m' + m.id)) return;
    var el = document.createElement('div');
    el.id = 'm' + m.id;
    el.className = 'wc-msg wc-' + m.from;
    if (m.from === 'system') {
      el.textContent = m.body;
    } else {
      var bubble = document.createElement('div');
      bubble.className = 'wc-bubble';
      bubble.textContent = m.body;
      var meta = document.createElement('div');
      meta.className = 'wc-meta';
      meta.textContent = (m.from === 'agent' && m.name ? m.name + ' · ' : '') + time(m.at);
      el.appendChild(bubble);
      el.appendChild(meta);
    }
    list.appendChild(el);
    if (m.from === 'agent' && !open && m.id > 0) { unread++; tell({ unread: unread }); }
  }

  function render(msgs) {
    var stick = nearBottom();
    msgs.forEach(function (m) { add(m); if (m.id > last) last = m.id; });
    if (stick || msgs.some(function (m) { return m.from === 'visitor'; })) list.scrollTop = list.scrollHeight;
  }

  function setStatus(s) {
    status = s;
    who.hidden = s !== 'new';
    note.hidden = s !== 'closed';
    if (s !== 'new') tell({ active: true });
  }

  function poll() {
    clearTimeout(timer);
    fetch(cfg.poll + '&lang=' + encodeURIComponent(lang) + '&after=' + last, { headers: { 'X-Visitor': token } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) { if (d) { render(d.messages || []); setStatus(d.status); } })
      .catch(function () {})
      .then(function () { timer = setTimeout(poll, open ? 4000 : 25000); });
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var body = input.value.trim();
    if (!body || sending) return;
    sending = true;
    form.classList.add('is-sending');
    fetch(cfg.send + '&lang=' + encodeURIComponent(lang), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Visitor': token },
      body: JSON.stringify({
        body: body, after: last, page: cfg.page,
        name: ($('#wc-name') || {}).value || '', email: ($('#wc-email') || {}).value || '',
        website: ($('#wc-website') || {}).value || '',
      }),
    }).then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
      .then(function (res) {
        if (!res.ok) { fail(res.d && res.d.error); return; }
        err.hidden = true;
        input.value = '';
        render(res.d.messages || []);
        setStatus('open');
      })
      .catch(function () { fail(); })
      .then(function () { sending = false; form.classList.remove('is-sending'); input.focus(); });
  });
  input.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); form.requestSubmit(); }
  });
  $('#wc-close').addEventListener('click', function () { tell({ close: true }); });

  window.addEventListener('message', function (e) {
    if (!e.data || e.data.type !== 'asteroid-webchat' || typeof e.data.open !== 'boolean') return;
    open = e.data.open;
    if (open) { unread = 0; tell({ unread: 0 }); list.scrollTop = list.scrollHeight; input.focus(); poll(); }
  });

  poll();
})();
