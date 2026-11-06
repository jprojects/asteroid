/* Quasar · comportamiento de cliente: tablas, formularios, permisos y atajos de teclado */
(() => {
  'use strict';

  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));
  const csrfToken = () => $('meta[name="csrf-token"]')?.content ?? '';
  const fmt = (text, vars) => text.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m));

  // Estado por pestaña (página, orden, filtros). Si el almacenamiento no está disponible, se ignora.
  const store = {
    get(key) {
      try { return JSON.parse(sessionStorage.getItem(key) || 'null'); } catch { return null; }
    },
    set(key, value) {
      try { sessionStorage.setItem(key, JSON.stringify(value)); } catch { /* sin almacenamiento */ }
    },
  };

  async function request(url, { method = 'GET', data = null, signal } = {}) {
    const options = {
      method,
      signal,
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'X-Requested-With': 'fetch' },
    };
    if (data) {
      const body = new URLSearchParams(data);
      body.set('_csrf', csrfToken());
      options.body = body;
    }
    const res = await fetch(url, options);
    if (res.status === 401) {
      window.location.reload(); // sesión caducada: el servidor redirige al login
      throw new Error('401');
    }
    const json = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(json.error || res.statusText);
    return json;
  }

  // ------------------------------------------------------------------ diálogos y avisos

  // Textos del sistema, inyectados por la plantilla en <meta name="qs-i18n">
  const T = (() => {
    try { return JSON.parse($('meta[name="qs-i18n"]')?.content || '{}'); } catch { return {}; }
  })();

  // Iconos del sprite que pinta el layout (<symbol id="qs-i-…">, set Lucide)
  const SVG_NS = 'http://www.w3.org/2000/svg';
  const icon = (name, className = '') => {
    const svg = document.createElementNS(SVG_NS, 'svg');
    svg.setAttribute('class', `qs-icon ${className}`.trim());
    svg.setAttribute('aria-hidden', 'true');
    svg.setAttribute('focusable', 'false');
    const use = document.createElementNS(SVG_NS, 'use');
    use.setAttribute('href', `#qs-i-${name}`);
    svg.append(use);
    return svg;
  };
  /** Etiqueta de color: tone es un nombre de la paleta (.qs-tone-*) o un color "#rrggbb". */
  const tag = (text, tone) => {
    const el = document.createElement('span');
    el.className = 'qs-tag';
    if (/^#[0-9a-f]{3,6}$/i.test(tone)) el.style.setProperty('--t', tone);
    else if (/^[a-z]+$/.test(tone)) el.classList.add(`qs-tone-${tone}`);
    el.textContent = text;
    return el;
  };
  const hasIcon = (name) => /^[a-z0-9-]+$/.test(name || '') && !!document.getElementById(`qs-i-${name}`);
  const ICONS = { danger: 'circle-alert', warning: 'triangle-alert', info: 'info', success: 'circle-check', ai: 'sparkles', default: 'circle-help' };

  /**
   * Ventanas modales propias (sustituyen a confirm/alert del navegador).
   *   if (await Quasar.dialog.confirm('¿Eliminar?', { variant: 'danger', okText: 'Eliminar' })) …
   *   await Quasar.dialog.alert('No se pudo guardar', { variant: 'danger' });
   * variant: default | danger | warning | info | success
   */
  const Dialog = {
    // content: nodo opcional que se muestra bajo el mensaje (p. ej. una vista previa); wide: diálogo más ancho
    open({ title = '', message = '', content = null, wide = false, okText = T.ok || 'OK', cancelText = T.cancel || 'Cancel', variant = 'default', showCancel = true } = {}) {
      return new Promise((resolve) => {
        const previousFocus = document.activeElement;
        const dlg = document.createElement('dialog');
        dlg.className = `qs-dialog qs-dialog-${variant}${wide ? ' qs-dialog-wide' : ''}`;
        const id = `qs-dlg-${Date.now().toString(36)}`;
        dlg.setAttribute('aria-labelledby', `${id}-t`);
        dlg.setAttribute('aria-describedby', `${id}-m`);

        const box = document.createElement('div');
        box.className = 'qs-dialog-box';
        const badge = document.createElement('span');
        badge.className = 'qs-dialog-icon';
        badge.setAttribute('aria-hidden', 'true');
        badge.append(icon(ICONS[variant] || ICONS.default));
        const h = document.createElement('h2');
        h.id = `${id}-t`;
        h.textContent = title;
        const p = document.createElement('p');
        p.id = `${id}-m`;
        p.textContent = message;
        p.hidden = !message;
        const actions = document.createElement('div');
        actions.className = 'qs-dialog-actions';
        const ok = document.createElement('button');
        ok.type = 'button';
        ok.className = `qs-btn ${variant === 'danger' ? 'qs-btn-danger-solid' : 'qs-btn-primary'}`;
        ok.textContent = okText;
        let cancel = null;
        if (showCancel) {
          cancel = document.createElement('button');
          cancel.type = 'button';
          cancel.className = 'qs-btn';
          cancel.textContent = cancelText;
          actions.append(cancel);
        }
        actions.append(ok);
        box.append(badge, h, p, ...(content ? [content] : []), actions);
        dlg.append(box);

        let done = false;
        const finish = (value) => {
          if (done) return;
          done = true;
          dlg.classList.add('is-closing');
          setTimeout(() => { dlg.close(); dlg.remove(); previousFocus?.focus?.(); resolve(value); }, 120);
        };
        ok.addEventListener('click', () => finish(true));
        cancel?.addEventListener('click', () => finish(false));
        dlg.addEventListener('cancel', (e) => { e.preventDefault(); finish(false); }); // Esc
        dlg.addEventListener('click', (e) => { if (e.target === dlg) finish(false); }); // clic fuera

        document.body.append(dlg);
        dlg.showModal();
        // En acciones peligrosas el foco va a "Cancelar": un Enter accidental no borra nada
        (variant === 'danger' && cancel ? cancel : ok).focus();
      });
    },
    confirm(message, opts = {}) {
      return Dialog.open({ title: T.confirmTitle, ...opts, message });
    },
    alert(message, opts = {}) {
      return Dialog.open({ title: opts.variant === 'danger' ? T.errorTitle : T.alertTitle, variant: 'info', ...opts, message, showCancel: false });
    },
    isOpen() {
      return !!$('dialog.qs-dialog[open]');
    },
  };

  /** Avisos flotantes que desaparecen solos: Quasar.toast.show('Guardado', 'success') */
  const Toast = {
    show(message, type = 'success', ms = 4000) {
      let host = $('.qs-toasts');
      if (!host) {
        host = document.createElement('div');
        host.className = 'qs-toasts';
        host.setAttribute('role', 'status');
        host.setAttribute('aria-live', 'polite');
        document.body.append(host);
      }
      const el = document.createElement('div');
      el.className = `qs-toast qs-toast-${type}`;
      const badge = document.createElement('span');
      badge.className = 'qs-toast-icon';
      badge.setAttribute('aria-hidden', 'true');
      badge.append(icon(ICONS[type] || ICONS.info));
      const text = document.createElement('span');
      text.className = 'qs-toast-text';
      text.textContent = message;
      const close = document.createElement('button');
      close.type = 'button';
      close.className = 'qs-toast-close';
      close.setAttribute('aria-label', T.close || 'Close');
      close.append(icon('x'));
      el.append(badge, text, close);
      host.append(el);
      const remove = () => { el.classList.add('is-leaving'); setTimeout(() => el.remove(), 200); };
      close.addEventListener('click', remove);
      if (ms > 0) setTimeout(remove, type === 'danger' ? ms * 2 : ms);
    },
  };

  window.Quasar = Object.assign(window.Quasar || {}, { dialog: Dialog, toast: Toast, icon, tag, busy: setBusy });

  // ------------------------------------------------------------------ doble clic

  /** Botón «trabajando»: no admite más clics y muestra un indicador. */
  function setBusy(btn, busy) {
    btn.classList.toggle('is-loading', busy);
    btn.disabled = busy;
    if (busy) btn.setAttribute('aria-busy', 'true');
    else btn.removeAttribute('aria-busy');
  }
  /** Igual, durante un tiempo (descargas: la página no cambia y no hay respuesta que esperar). */
  function busyFor(btn, ms) {
    setBusy(btn, true);
    setTimeout(() => setBusy(btn, false), ms);
  }

  // ------------------------------------------------------------------ acciones de módulo

  /**
   * Ejecuta una acción propia del módulo (botón con "action" en el JSON). cfg viene del servidor:
   * {run, label, confirm?, variant?, download?, id?}. Pide confirmación, la llama por POST y muestra
   * el resultado. Devuelve la respuesta, o null si no se ejecutó o falló.
   */
  async function runAction(cfg, btn = null) {
    if (btn?.classList.contains('is-loading')) return null; // ya en marcha (doble clic)
    if (cfg.confirm) {
      const ok = await Dialog.confirm(cfg.confirm, {
        title: cfg.label, okText: cfg.label, variant: cfg.variant === 'danger' ? 'danger' : 'default',
      });
      if (!ok) return null;
    }
    const fields = { _id: cfg.id ?? '', _back: `index.php${window.location.search}` };
    if (cfg.download) {
      // Formulario normal: el navegador recibe el fichero (o vuelve aquí con el aviso)
      const form = document.createElement('form');
      form.method = 'post';
      form.action = cfg.run;
      form.hidden = true;
      Object.entries({ ...fields, _csrf: csrfToken() }).forEach(([name, value]) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        form.append(input);
      });
      document.body.append(form);
      form.submit();
      form.remove();
      if (btn) busyFor(btn, 3000); // la descarga no cambia de página: se libera sola
      return null;
    }
    if (btn) setBusy(btn, true);
    try {
      const res = await request(cfg.run, { method: 'POST', data: fields });
      if (res.redirect) window.location.href = res.redirect;
      else if (res.reload) window.location.reload();
      else if (res.message) Toast.show(res.message, res.type || 'success');
      return res;
    } catch (err) {
      Dialog.alert(err.message, { variant: 'danger' });
      return null;
    } finally {
      if (btn) setBusy(btn, false);
    }
  }

  // Botones de la barra de la tabla y de la ficha
  document.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-qs-action]');
    if (!btn) return;
    e.preventDefault();
    const res = await runAction(JSON.parse(btn.dataset.qsAction), btn);
    // En el listado, la tabla se recarga para mostrar los cambios
    if (res && !res.redirect && !res.reload) btn.closest('.qs-datatable')?.qsTable?.load();
  });

  // ------------------------------------------------------------------ informes con lenguaje natural

  // Envía la petición; el servidor crea el informe y devuelve su URL
  $$('form[data-report-ask]').forEach((form) => form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const input = form.elements.namedItem('q');
    const text = input.value.trim();
    if (!text) { input.focus(); return; }
    const button = $('button[type="submit"]', form);
    form.classList.add('is-busy');
    button.disabled = true;
    try {
      const res = await request(form.action, { method: 'POST', data: { q: text } });
      if (res.ok && res.url) { window.location.href = res.url; return; }
      Toast.show(res.note || '', 'warning', 8000);
    } catch (err) {
      Dialog.alert(err.message, { variant: 'danger' });
    } finally {
      form.classList.remove('is-busy');
      button.disabled = false;
    }
  }));
  // Enter envía; Mayús+Enter, salto de línea
  $$('form[data-report-ask] textarea').forEach((ta) => ta.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); ta.form.requestSubmit(); }
  }));

  // Comentario de la IA sobre el resultado: se guarda con el informe y se recarga la página para verlo
  $$('[data-report-comment]').forEach((btn) => btn.addEventListener('click', async () => {
    const label = btn.innerHTML;
    btn.disabled = true;
    btn.closest('.qs-report-comment').classList.add('is-busy');
    btn.textContent = btn.dataset.working;
    try {
      await request(btn.dataset.reportComment, { method: 'POST', data: { id: btn.dataset.id } });
      window.location.reload();
    } catch (err) {
      Dialog.alert(err.message, { variant: 'danger' });
      btn.innerHTML = label;
      btn.disabled = false;
      btn.closest('.qs-report-comment').classList.remove('is-busy');
    }
  }));

  // Gráfico del informe (SVG propio): columnas, barras horizontales, líneas y circular. Las filas se piden una
  // vez; tipo, categorías y valores se cambian en el navegador. Colores de --chart-1…8 (claro y oscuro).
  class ReportChart {
    constructor(root) {
      this.root = root;
      this.cfg = JSON.parse(root.dataset.chart);
      this.i18n = this.cfg.i18n;
      this.canvas = $('[data-role="canvas"]', root);
      this.note = $('[data-role="note"]', root);
      this.typeSel = $('[data-role="type"]', root);
      this.xSel = $('[data-role="x"]', root);
      this.checks = $$('[data-role="series"] input', root);
      this.saveBtn = $('[data-role="save"]', root);
      this.state = this.cfg.chart;
      this.saved = JSON.stringify(this.cfg.chart);
      this.rows = null;
      this.svg = null;
      this.tip = document.createElement('div');
      this.tip.className = 'qs-chart-tip';
      this.tip.hidden = true;

      this.typeSel.addEventListener('change', () => this.changed());
      this.xSel.addEventListener('change', () => this.changed());
      this.checks.forEach((c) => c.addEventListener('change', () => {
        // El circular tiene una sola serie: marcar una desmarca las demás
        if (this.typeSel.value === 'pie' && c.checked) this.checks.forEach((o) => { if (o !== c) o.checked = false; });
        this.changed();
      }));
      this.saveBtn?.addEventListener('click', () => this.save());
      $('[data-role="download"]', root).addEventListener('click', () => this.download());
      new ResizeObserver(() => { if (this.rows) this.render(); }).observe(this.canvas);
      window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => { if (this.rows) this.render(); });
    }

    // Al abrir la pestaña: pide las filas la primera vez
    async show() {
      if (this.rows || this.loading) { this.render(); return; }
      this.loading = true;
      this.message(this.i18n.loading);
      try {
        const res = await request(this.cfg.dataUrl);
        this.rows = res.rows;
        this.note.textContent = res.truncated;
        this.note.hidden = !res.truncated;
        this.state ??= this.guess();
        this.syncControls();
        if (this.saveBtn) this.saveBtn.hidden = JSON.stringify(this.state) === this.saved;
        this.render();
      } catch (err) {
        this.message(err.message || this.i18n.error);
      } finally {
        this.loading = false;
      }
    }

    // Sin gráfico guardado: categorías = primera columna no numérica; valores = las numéricas (sin ids)
    guess() {
      const cols = this.cfg.columns;
      const x = (cols.find((c) => !c.numeric) || cols[0]).name;
      const numeric = cols.filter((c) => c.numeric && c.name !== x);
      const named = numeric.filter((c) => !/(^|_)id$/i.test(c.name));
      const series = (named.length ? named : numeric).slice(0, 8).map((c) => c.name);
      const xCol = cols.find((c) => c.name === x);
      const timeLike = ['date', 'datetime'].includes(xCol.type) || /^\d{4}-\d{2}/.test(this.rows[0]?.[cols.indexOf(xCol)]?.[0] || '');
      const type = timeLike ? 'line' : (this.rows.length > 12 ? 'bar' : 'column');
      return { type, x, series };
    }

    syncControls() {
      this.typeSel.value = this.state.type;
      this.xSel.value = this.state.x;
      this.checks.forEach((c) => { c.checked = this.state.series.includes(c.value); });
    }

    changed() {
      const series = this.checks.filter((c) => c.checked && c.value !== this.xSel.value).map((c) => c.value);
      this.state = { type: this.typeSel.value, x: this.xSel.value, series: this.typeSel.value === 'pie' ? series.slice(0, 1) : series };
      if (this.state.type === 'pie') this.syncControls();
      if (this.saveBtn) this.saveBtn.hidden = JSON.stringify(this.state) === this.saved || !this.state.series.length;
      this.render();
    }

    async save() {
      try {
        const res = await request(this.cfg.saveUrl, { method: 'POST', data: { id: this.cfg.id, chart: JSON.stringify(this.state) } });
        this.saved = JSON.stringify(this.state);
        this.saveBtn.hidden = true;
        Toast.show(res.message);
      } catch (err) {
        Dialog.alert(err.message, { variant: 'danger' });
      }
    }

    message(text) {
      const p = document.createElement('p');
      p.className = 'qs-chart-message';
      p.textContent = text;
      this.svg = null;
      this.canvas.replaceChildren(p);
    }

    // ---- utilidades de dibujo

    el(name, attrs = {}, parent = null) {
      const node = document.createElementNS(SVG_NS, name);
      for (const [k, v] of Object.entries(attrs)) node.setAttribute(k, v);
      parent?.append(node);
      return node;
    }

    text(parent, x, y, content, attrs = {}) {
      const node = this.el('text', { x, y, fill: this.colors.muted, 'font-size': 11, ...attrs }, parent);
      node.textContent = content;
      return node;
    }

    measure(text, size = 11) {
      this.ctx ??= document.createElement('canvas').getContext('2d');
      this.ctx.font = `${size}px ${this.font}`;
      return this.ctx.measureText(text).width;
    }

    // Recorta con «…» para que quepa en max píxeles
    fit(text, max, size = 11) {
      if (this.measure(text, size) <= max) return text;
      let s = text;
      while (s.length > 1 && this.measure(`${s}…`, size) > max) s = s.slice(0, -1);
      return `${s}…`;
    }

    // Marcas de eje "redondas" (0, 5, 10… o 0, 2K, 4K…) que cubren min…max
    ticks(min, max, count = 5) {
      if (min === max) { max = min === 0 ? 1 : max + Math.abs(max) * 0.1; min = Math.min(0, min); }
      const raw = (max - min) / count;
      const mag = 10 ** Math.floor(Math.log10(raw));
      const step = [1, 2, 2.5, 5, 10].map((m) => m * mag).find((s) => s >= raw);
      const out = [];
      for (let v = Math.floor(min / step) * step; v <= Math.ceil(max / step) * step + step / 2; v += step) out.push(+v.toFixed(10));
      return out;
    }

    // Columna con el extremo de los datos redondeado (4px) y la base recta
    barPath(x, y, w, h, horizontal) {
      const neg = h < 0;
      const len = Math.abs(h);
      const r = Math.min(4, len, (horizontal ? w : w) / 2);
      if (!horizontal) {
        const top = neg ? y : y - len;
        return neg
          ? `M${x},${top}H${x + w}V${top + len - r}Q${x + w},${top + len} ${x + w - r},${top + len}H${x + r}Q${x},${top + len} ${x},${top + len - r}Z`
          : `M${x},${y}V${top + r}Q${x},${top} ${x + r},${top}H${x + w - r}Q${x + w},${top} ${x + w},${top + r}V${y}Z`;
      }
      // horizontal: x = base, y = arriba de la barra, w = grosor, h = largo (negativo hacia la izquierda)
      const left = neg ? x - len : x;
      return neg
        ? `M${x},${y}H${left + r}Q${left},${y} ${left},${y + r}V${y + w - r}Q${left},${y + w} ${left + r},${y + w}H${x}Z`
        : `M${x},${y}H${x + len - r}Q${x + len},${y} ${x + len},${y + r}V${y + w - r}Q${x + len},${y + w} ${x + len - r},${y + w}H${x}Z`;
    }

    // Valor como en la tabla para un número calculado aquí (p. ej. "Otros" del circular)
    formatNumber(col, n) {
      const digits = col.type === 'integer' ? 0 : 2;
      const s = new Intl.NumberFormat(docLang(), { minimumFractionDigits: digits, maximumFractionDigits: digits }).format(n);
      return col.type === 'percent' ? `${s} %` : s;
    }

    compact(n) {
      return new Intl.NumberFormat(docLang(), { notation: 'compact', maximumFractionDigits: 1 }).format(n);
    }

    // Leyenda arriba (dos o más series, o las porciones del circular); devuelve su alto
    legend(svg, items, width) {
      if (items.length < 2) return 0;
      let x = 0;
      let y = 6;
      const g = this.el('g', {}, svg);
      items.forEach((it) => {
        const label = this.fit(it.label, 220);
        const w = 14 + this.measure(label) + 16;
        if (x > 0 && x + w > width) { x = 0; y += 18; }
        this.el('rect', { x, y, width: 10, height: 10, rx: 2, fill: it.color }, g);
        this.text(g, x + 14, y + 9, label, { fill: this.colors.text });
        x += w;
      });
      return y + 22;
    }

    showTip(event, title, lines) {
      this.tip.replaceChildren();
      const head = document.createElement('strong');
      head.textContent = title;
      this.tip.append(head);
      lines.forEach(({ color, label, value }) => {
        const row = document.createElement('div');
        const sw = document.createElement('i');
        sw.style.background = color;
        const name = document.createElement('span');
        name.textContent = label;
        const val = document.createElement('b');
        val.textContent = value;
        row.append(sw, name, val);
        this.tip.append(row);
      });
      this.tip.hidden = false;
      const box = this.canvas.getBoundingClientRect();
      let left = event.clientX - box.left + 14;
      const top = event.clientY - box.top + 14;
      if (left + this.tip.offsetWidth > box.width) left = Math.max(0, event.clientX - box.left - this.tip.offsetWidth - 14);
      this.tip.style.left = `${left}px`;
      this.tip.style.top = `${top}px`;
    }

    hideTip() {
      this.tip.hidden = true;
    }

    // ---- dibujo

    render() {
      if (!this.rows || !this.canvas.offsetWidth) return;
      const style = getComputedStyle(this.root);
      const v = (name) => style.getPropertyValue(name).trim();
      this.font = getComputedStyle(document.body).fontFamily;
      this.colors = {
        series: [1, 2, 3, 4, 5, 6, 7, 8].map((i) => v(`--chart-${i}`)),
        surface: v('--surface'), text: v('--text'), muted: v('--text-muted'), grid: v('--border'), axis: v('--border-strong'),
      };
      const cols = this.cfg.columns;
      const xi = cols.findIndex((c) => c.name === this.state.x);
      const si = this.state.series.map((name) => cols.findIndex((c) => c.name === name)).filter((i) => i >= 0 && i !== xi);
      if (xi < 0 || !si.length || !this.rows.length) { this.message(this.i18n.empty); return; }
      const data = {
        cols: si.map((i) => cols[i]),
        points: this.rows.map((r) => ({ label: r[xi][0] || '—', values: si.map((i) => r[i][1]), texts: si.map((i) => r[i][0]) })),
      };
      const width = Math.floor(this.canvas.clientWidth);
      const svg = this.el('svg', { xmlns: SVG_NS, width, 'font-family': this.font, role: 'img', class: 'qs-chart-svg' });
      this.el('rect', { width: '100%', height: '100%', fill: this.colors.surface }, svg);
      svg.addEventListener('mouseleave', () => this.hideTip());
      const height = { column: this.drawColumns, bar: this.drawBars, line: this.drawLine, pie: this.drawPie }[this.state.type].call(this, svg, data, width);
      svg.setAttribute('height', height);
      svg.setAttribute('viewBox', `0 0 ${width} ${height}`);
      svg.setAttribute('aria-label', `${this.typeSel.selectedOptions[0].text}: ${data.cols.map((c) => c.label).join(', ')} / ${cols[xi].label}`);
      this.svg = svg;
      this.canvas.replaceChildren(svg, this.tip);
      this.hideTip();
    }

    seriesLegend(svg, data, width) {
      return this.legend(svg, data.cols.map((c, s) => ({ label: c.label, color: this.colors.series[s] })), width);
    }

    tipLines(data, p) {
      return data.cols.map((c, s) => ({ color: this.colors.series[s], label: c.label, value: p.texts[s] || '—' }));
    }

    // Escala de valores (vertical u horizontal) con el 0 siempre dentro
    scale(data, from, to, count = 5) {
      const all = data.points.flatMap((p) => p.values).filter((n) => n !== null);
      const ticks = this.ticks(Math.min(0, ...all), Math.max(0, ...all), count);
      const lo = ticks[0];
      const hi = ticks[ticks.length - 1];
      return { ticks, at: (n) => from + ((n - lo) / (hi - lo)) * (to - from) };
    }

    // Banda transparente por categoría: tooltip con todas las series
    hoverBand(svg, attrs, data, p, onEnter = null) {
      const band = this.el('rect', { ...attrs, fill: 'transparent' }, svg);
      band.addEventListener('mousemove', (e) => this.showTip(e, p.label, this.tipLines(data, p)));
      if (onEnter) {
        band.addEventListener('mouseenter', () => onEnter(true));
        band.addEventListener('mouseleave', () => onEnter(false));
      }
    }

    drawColumns(svg, data, width) {
      const top = this.seriesLegend(svg, data, width) + 8;
      const plotH = 300;
      const probe = this.scale(data, top + plotH, top);
      const left = Math.max(...probe.ticks.map((t) => this.measure(this.compact(t)))) + 14;
      const right = 8;
      const plotW = width - left - right;
      const y = probe.at;
      probe.ticks.forEach((t) => {
        this.el('line', { x1: left, x2: width - right, y1: y(t), y2: y(t), stroke: t === 0 ? this.colors.axis : this.colors.grid, 'stroke-width': 1 }, svg);
        this.text(svg, left - 6, y(t) + 4, this.compact(t), { 'text-anchor': 'end' });
      });
      const n = data.points.length;
      const m = data.cols.length;
      const band = plotW / n;
      const barW = Math.max(1, Math.min(24, (band * 0.8 - 2 * (m - 1)) / m));
      const groupW = m * barW + (m - 1) * 2;
      const every = Math.max(1, Math.ceil(70 / band)); // una etiqueta cada tantas columnas si no caben
      data.points.forEach((p, i) => {
        const bx = left + i * band;
        const g = this.el('g', {}, svg);
        p.values.forEach((val, s) => {
          if (val === null || val === 0) return;
          this.el('path', { d: this.barPath(bx + (band - groupW) / 2 + s * (barW + 2), y(0), barW, y(0) - y(val), false), fill: this.colors.series[s] }, g);
        });
        if (i % every === 0) this.text(svg, bx + band / 2, top + plotH + 16, this.fit(p.label, band * every - 6), { 'text-anchor': 'middle' });
        this.hoverBand(svg, { x: bx, y: top, width: band, height: plotH }, data, p, (on) => g.setAttribute('opacity', on ? 0.75 : 1));
      });
      return top + plotH + 26;
    }

    drawBars(svg, data, width) {
      const top = this.seriesLegend(svg, data, width) + 4;
      const m = data.cols.length;
      const thick = m === 1 ? 18 : Math.max(8, Math.min(14, 40 / m));
      const band = m * thick + (m - 1) * 2 + 10;
      const labelW = Math.min(width * 0.35, Math.max(...data.points.map((p) => this.measure(p.label)))) + 10;
      const valueW = m === 1 ? Math.max(...data.points.map((p) => this.measure(p.texts[0] || ''))) + 10 : 12;
      const left = labelW;
      const right = width - valueW;
      const plotH = data.points.length * band;
      const sc = this.scale(data, left, right, Math.max(2, Math.min(5, Math.floor((right - left) / 70))));
      const x = sc.at;
      sc.ticks.forEach((t) => {
        this.el('line', { x1: x(t), x2: x(t), y1: top, y2: top + plotH, stroke: t === 0 ? this.colors.axis : this.colors.grid, 'stroke-width': 1 }, svg);
        this.text(svg, x(t), top + plotH + 14, this.compact(t), { 'text-anchor': 'middle' });
      });
      data.points.forEach((p, i) => {
        const by = top + i * band;
        const g = this.el('g', {}, svg);
        this.text(svg, labelW - 8, by + band / 2 + 4, this.fit(p.label, labelW - 12), { 'text-anchor': 'end', fill: this.colors.text });
        p.values.forEach((val, s) => {
          if (val === null || val === 0) return;
          this.el('path', { d: this.barPath(x(0), by + 5 + s * (thick + 2), thick, x(val) - x(0), true), fill: this.colors.series[s] }, g);
        });
        // Con una sola serie, el valor al final de la barra
        if (m === 1 && p.values[0] !== null) {
          const end = x(p.values[0]);
          const neg = p.values[0] < 0;
          this.text(svg, neg ? Math.min(end, x(0)) - 4 : Math.max(end, x(0)) + 4, by + band / 2 + 4, p.texts[0], { 'text-anchor': neg ? 'end' : 'start', fill: this.colors.text });
        }
        this.hoverBand(svg, { x: 0, y: by, width, height: band }, data, p, (on) => g.setAttribute('opacity', on ? 0.75 : 1));
      });
      return top + plotH + 22;
    }

    drawLine(svg, data, width) {
      const top = this.seriesLegend(svg, data, width) + 8;
      const plotH = 300;
      const probe = this.scale(data, top + plotH, top);
      const left = Math.max(...probe.ticks.map((t) => this.measure(this.compact(t)))) + 14;
      const right = 16;
      const plotW = width - left - right;
      const y = probe.at;
      const n = data.points.length;
      const xAt = (i) => (n === 1 ? left + plotW / 2 : left + (i * plotW) / (n - 1));
      probe.ticks.forEach((t) => {
        this.el('line', { x1: left, x2: width - right, y1: y(t), y2: y(t), stroke: t === 0 ? this.colors.axis : this.colors.grid, 'stroke-width': 1 }, svg);
        this.text(svg, left - 6, y(t) + 4, this.compact(t), { 'text-anchor': 'end' });
      });
      const step = n > 1 ? plotW / (n - 1) : plotW;
      const every = Math.max(1, Math.ceil(80 / step));
      data.points.forEach((p, i) => {
        if (i % every === 0) this.text(svg, xAt(i), top + plotH + 16, this.fit(p.label, step * every - 6), { 'text-anchor': i === 0 && n > 1 ? 'start' : (i === n - 1 && n > 1 ? 'end' : 'middle') });
      });
      const markers = n <= 40;
      data.cols.forEach((c, s) => {
        // Tramos sin huecos (un valor vacío corta la línea)
        let d = '';
        let open = false;
        data.points.forEach((p, i) => {
          const val = p.values[s];
          if (val === null) { open = false; return; }
          d += `${open ? 'L' : 'M'}${xAt(i)},${y(val)}`;
          open = true;
        });
        if (data.cols.length === 1 && n > 1 && !data.points.some((p) => p.values[0] === null)) {
          this.el('path', { d: `${d}L${xAt(n - 1)},${y(0)}L${xAt(0)},${y(0)}Z`, fill: this.colors.series[s], 'fill-opacity': 0.1 }, svg);
        }
        this.el('path', { d, fill: 'none', stroke: this.colors.series[s], 'stroke-width': 2, 'stroke-linejoin': 'round', 'stroke-linecap': 'round' }, svg);
        if (markers) {
          data.points.forEach((p, i) => {
            if (p.values[s] !== null) this.el('circle', { cx: xAt(i), cy: y(p.values[s]), r: 4, fill: this.colors.series[s], stroke: this.colors.surface, 'stroke-width': 2 }, svg);
          });
        }
      });
      // Línea vertical y tooltip en el punto más cercano
      const cross = this.el('line', { y1: top, y2: top + plotH, stroke: this.colors.axis, 'stroke-width': 1, visibility: 'hidden' }, svg);
      data.points.forEach((p, i) => {
        this.hoverBand(svg, { x: xAt(i) - step / 2, y: top, width: step, height: plotH }, data, p, (on) => {
          cross.setAttribute('x1', xAt(i));
          cross.setAttribute('x2', xAt(i));
          cross.setAttribute('visibility', on ? 'visible' : 'hidden');
        });
      });
      return top + plotH + 26;
    }

    drawPie(svg, data, width) {
      const col = data.cols[0];
      // Solo valores positivos, de mayor a menor; a partir del 8.º, juntos en "Otros"
      const slices = data.points.filter((p) => p.values[0] > 0).map((p) => ({ label: p.label, value: p.values[0], text: p.texts[0] }))
        .sort((a, b) => b.value - a.value);
      if (!slices.length) { this.text(svg, 0, 20, this.i18n.empty); return 40; }
      if (slices.length > 8) {
        const rest = slices.splice(7);
        const sum = rest.reduce((t, s) => t + s.value, 0);
        slices.push({ label: `${this.i18n.other} (${rest.length})`, value: sum, text: this.formatNumber(col, sum) });
      }
      const total = slices.reduce((t, s) => t + s.value, 0);
      const pct = (n) => new Intl.NumberFormat(docLang(), { style: 'percent', maximumFractionDigits: 1 }).format(n / total);
      const size = Math.min(280, width);
      const side = width - size >= 220; // leyenda a la derecha si cabe; si no, debajo
      const r = size / 2 - 4;
      const cx = size / 2;
      const cy = size / 2;
      const inner = r * 0.58;
      let angle = -Math.PI / 2;
      slices.forEach((s, i) => {
        const a = (s.value / total) * Math.PI * 2;
        const end = angle + a;
        const pt = (rad, rr) => `${cx + rr * Math.cos(rad)},${cy + rr * Math.sin(rad)}`;
        const large = a > Math.PI ? 1 : 0;
        const d = slices.length === 1
          ? `M${cx},${cy - r}A${r},${r} 0 1 1 ${cx - 0.01},${cy - r}ZM${cx},${cy - inner}A${inner},${inner} 0 1 0 ${cx + 0.01},${cy - inner}Z`
          : `M${pt(angle, r)}A${r},${r} 0 ${large} 1 ${pt(end, r)}L${pt(end, inner)}A${inner},${inner} 0 ${large} 0 ${pt(angle, inner)}Z`;
        const path = this.el('path', { d, fill: this.colors.series[i], stroke: this.colors.surface, 'stroke-width': 2, 'fill-rule': 'evenodd' }, svg);
        path.addEventListener('mousemove', (e) => this.showTip(e, s.label, [{ color: this.colors.series[i], label: col.label, value: `${s.text} · ${pct(s.value)}` }]));
        path.addEventListener('mouseenter', () => path.setAttribute('opacity', 0.75));
        path.addEventListener('mouseleave', () => path.setAttribute('opacity', 1));
        angle = end;
      });
      this.text(svg, cx, cy - 2, this.i18n.total, { 'text-anchor': 'middle' });
      this.text(svg, cx, cy + 16, this.formatNumber(col, total), { 'text-anchor': 'middle', fill: this.colors.text, 'font-size': 15, 'font-weight': 600 });
      // Leyenda con valor y porcentaje
      const lx = side ? size + 24 : 0;
      let ly = side ? Math.max(8, cy - (slices.length * 22) / 2) : size + 16;
      const labelW = Math.min(Math.max(...slices.map((s) => this.measure(s.label))), Math.max(60, (side ? width - lx : width) - 150));
      slices.forEach((s, i) => {
        this.el('rect', { x: lx, y: ly, width: 10, height: 10, rx: 2, fill: this.colors.series[i] }, svg);
        this.text(svg, lx + 16, ly + 9, this.fit(s.label, labelW), { fill: this.colors.text });
        this.text(svg, lx + 16 + labelW + 8, ly + 9, `${s.text} · ${pct(s.value)}`);
        ly += 22;
      });
      return side ? size : ly + 4;
    }

    // PNG del gráfico tal como se ve (a doble resolución)
    download() {
      if (!this.svg) return;
      const clone = this.svg.cloneNode(true);
      const w = +clone.getAttribute('width');
      const h = +clone.getAttribute('height');
      const url = URL.createObjectURL(new Blob([new XMLSerializer().serializeToString(clone)], { type: 'image/svg+xml' }));
      const img = new Image();
      img.onload = () => {
        const canvas = document.createElement('canvas');
        canvas.width = w * 2;
        canvas.height = h * 2;
        const ctx = canvas.getContext('2d');
        ctx.scale(2, 2);
        ctx.drawImage(img, 0, 0, w, h);
        URL.revokeObjectURL(url);
        canvas.toBlob((blob) => {
          const a = document.createElement('a');
          a.href = URL.createObjectURL(blob);
          a.download = `informe-${this.cfg.id}.png`;
          a.click();
          setTimeout(() => URL.revokeObjectURL(a.href), 1000);
        });
      };
      img.src = url;
    }
  }

  // Pestañas Tabla / Gráfico (la última elegida se recuerda en la pestaña del navegador)
  $$('.qs-report').forEach((section) => {
    const tabs = $$('[role="tab"]', section);
    const chartRoot = $('.qs-report-chart[data-chart]', section);
    const chart = chartRoot ? new ReportChart(chartRoot) : null;
    const select = (name, focus = false) => {
      tabs.forEach((t) => {
        const on = t.dataset.tab === name;
        t.setAttribute('aria-selected', on ? 'true' : 'false');
        t.tabIndex = on ? 0 : -1;
        if (on && focus) t.focus();
      });
      $$('[data-panel]', section).forEach((p) => { p.hidden = p.dataset.panel !== name; });
      store.set('qs-report-tab', name);
      if (name === 'chart') chart?.show();
    };
    tabs.forEach((t, i) => {
      t.addEventListener('click', () => select(t.dataset.tab));
      t.addEventListener('keydown', (e) => {
        const step = { ArrowRight: 1, ArrowLeft: -1 }[e.key];
        if (step) select(tabs[(i + step + tabs.length) % tabs.length].dataset.tab, true);
      });
    });
    if (store.get('qs-report-tab') === 'chart') select('chart');
  });

  // ------------------------------------------------------------------ tabla de datos

  class DataTable {
    constructor(root) {
      this.root = root;
      root.qsTable = this; // para los botones propios de la barra
      this.cfg = JSON.parse(root.dataset.config);
      this.i18n = this.cfg.i18n;
      this.form = $('.qs-filters', root);
      this.search = $('[data-search]', root);
      this.toggleBtn = $('[data-filters-toggle]', root);
      this.badge = $('[data-filters-count]', root);
      this.tbody = $('tbody', root);
      this.info = $('[data-role="info"]', root);
      this.pageInput = $('[data-role="page"]', root);
      this.pagesLabel = $('[data-role="pages"]', root);
      this.sizeSelect = $('[data-role="size"]', root);
      this.storeKey = `qs.table.${this.cfg.module}`;
      this.controller = null;
      this.pages = 1;
      this.expanded = new Set(); // filas desplegadas (se mantienen al recargar los datos)
      this.chips = $('[data-chips]', root);
      this.aiForm = $('[data-ai]', root);
      this.expandAllBtn = $('[data-expand-all]', root);

      const saved = store.get(this.storeKey) || {};
      this.state = {
        page: saved.page || 1,
        size: saved.size || this.cfg.size,
        sort: saved.sort || this.cfg.sort,
        dir: saved.dir || this.cfg.dir,
      };
      if (this.form && Array.isArray(saved.filters)) this.setFilterEntries(saved.filters);

      this.panelKey = `qs.filters.open.${this.cfg.module}`;
      let open = this.cfg.filtersOpen;
      try { const v = localStorage.getItem(this.panelKey); if (v !== null) open = v === '1'; } catch { /* sin almacenamiento */ }
      this.setFiltersOpen(open, false);
      this.updateFilterBadge();

      this.bind();
      this.load();
    }

    /**
     * Pone valores en el panel de filtros: [[nombre, valor], …] como los da FormData
     * (los filtros de lista con varios valores repiten el nombre "f[campo][]").
     * @param labels {nombre: {valor: texto}} para opciones que no vienen en la página (select remoto)
     */
    setFilterEntries(entries, labels = {}) {
      $$('select[multiple]', this.form).forEach((sel) => [...sel.options].forEach((o) => { o.selected = false; }));
      for (const [name, value] of entries) {
        const el = this.form.elements.namedItem(name);
        if (!el) continue;
        if (el instanceof HTMLSelectElement && value !== '') {
          let opt = [...el.options].find((o) => o.value === value);
          if (!opt) {
            // Select con buscador remoto: la opción no viene en la página; el combobox resolverá su texto
            const label = labels[name]?.[value];
            opt = new Option(label ?? value, value);
            if (label === undefined) opt.dataset.pending = '1';
            el.add(opt);
          }
          if (el.multiple) { opt.selected = true; continue; }
        }
        if ('value' in el) el.value = value;
      }
    }

    /** Despliega o pliega el panel de filtros (y lo recuerda por módulo). */
    setFiltersOpen(open, remember = true) {
      if (!this.form || !this.toggleBtn) return;
      this.form.hidden = !open;
      this.toggleBtn.setAttribute('aria-expanded', String(open));
      this.toggleBtn.classList.toggle('is-active', open);
      if (remember) {
        try { localStorage.setItem(this.panelKey, open ? '1' : '0'); } catch { /* sin almacenamiento */ }
        if (open) $('.qs-input', this.form)?.focus();
      }
    }

    /** Número de filtros con valor (un rango cuenta como uno), visible aunque el panel esté plegado. */
    updateFilterBadge() {
      if (!this.form) return;
      const active = $$('[data-filter]', this.form)
        .filter((block) => $$('input, select', block).some((el) => el.value.trim() !== '')).length;
      if (this.badge) {
        this.badge.textContent = String(active);
        this.badge.hidden = active === 0;
      }
      $$('[data-filters-clear]', this.root).forEach((btn) => {
        if (!this.form.contains(btn)) btn.hidden = active === 0;
      });
    }

    /** Vacía los filtros del panel (no la búsqueda). */
    clearFilters() {
      $$('[data-filter] input, [data-filter] select', this.form).forEach((el) => {
        el.value = '';
        el.dispatchEvent(new Event('qs-sync')); // refresca los combobox
      });
    }

    bind() {
      $$('th[data-sortable]', this.root).forEach((th) => {
        th.tabIndex = 0;
        const sort = () => {
          const field = th.dataset.field;
          this.state.dir = this.state.sort === field && this.state.dir === 'asc' ? 'desc' : 'asc';
          this.state.sort = field;
          this.state.page = 1;
          this.load();
        };
        th.addEventListener('click', sort);
        th.addEventListener('keydown', (e) => {
          if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); sort(); }
        });
      });

      this.chips?.addEventListener('click', (e) => {
        const chip = e.target.closest('[data-chip-for]');
        if (!chip) return;
        if (e.target.closest('[data-remove]')) {
          this.clearChip(chip.dataset.chipFor);
          this.refresh();
        } else if (chip.dataset.chipFor !== '_q') {
          // Abre el panel de filtros en ese filtro
          this.setFiltersOpen(true, false);
          $('input, select', this.form.querySelector(`[data-filter="${CSS.escape(chip.dataset.chipFor)}"]`))?.focus();
        } else this.search?.focus();
      });
      this.aiForm?.addEventListener('submit', (e) => { e.preventDefault(); this.ask(); });
      this.form?.addEventListener('qs-labels', () => this.renderChips());

      if (this.form) {
        let timer;
        const refresh = () => this.refresh();
        const onInput = (e) => {
          // Solo controles que se envían (no el buscador interno de un combobox)
          if (e.target.name && e.target.matches('input[type="search"], input[type="text"], input[type="number"]')) {
            this.updateFilterBadge(); // la insignia al momento; la consulta, al dejar de escribir
            clearTimeout(timer);
            timer = setTimeout(refresh, 350);
          }
        };
        this.form.addEventListener('submit', (e) => { e.preventDefault(); clearTimeout(timer); refresh(); });
        this.form.addEventListener('input', onInput);
        this.form.addEventListener('change', (e) => {
          if (e.target.matches('select, input[type="date"]')) refresh();
        });
        // El buscador está en la barra de herramientas (asociado al form con form="…"):
        // sus eventos no pasan por el <form>
        this.search?.addEventListener('input', onInput);
        $$('[data-filters-clear]', this.root).forEach((btn) => btn.addEventListener('click', () => {
          this.clearFilters();
          clearTimeout(timer);
          refresh();
        }));
        this.toggleBtn?.addEventListener('click', () => this.setFiltersOpen(this.form.hidden));
      }

      $$('[data-export]', this.root).forEach((btn) => btn.addEventListener('click', () => {
        if (btn.classList.contains('is-loading')) return;
        busyFor(btn, 4000); // la descarga tarda en empezar: evita pedir dos veces el mismo fichero
        this.exportTo(btn.dataset.export);
      }));

      $$('[data-page]', this.root).forEach((btn) => btn.addEventListener('click', () => {
        const target = { first: 1, prev: this.state.page - 1, next: this.state.page + 1, last: this.pages };
        this.goTo(target[btn.dataset.page]);
      }));
      this.pageInput?.addEventListener('change', () => this.goTo(parseInt(this.pageInput.value, 10) || 1));
      this.sizeSelect?.addEventListener('change', () => {
        this.state.size = parseInt(this.sizeSelect.value, 10);
        this.state.page = 1;
        this.load();
      });

      // Doble clic / Enter abre el registro; flechas navegan (→ ← despliegan); Supr borra
      this.tbody.addEventListener('dblclick', (e) => {
        const tr = e.target.closest('tr[data-id]');
        if (tr && !e.target.closest('a, button')) this.open(tr.dataset.id);
      });
      this.tbody.addEventListener('click', (e) => {
        const del = e.target.closest('[data-action="delete"]');
        if (del) { e.preventDefault(); this.remove(del.closest('tr').dataset.id); }
        const toggle = e.target.closest('[data-expand]');
        if (toggle) this.toggleRow(toggle.closest('tr'));
        const run = e.target.closest('[data-run]');
        if (run) {
          e.preventDefault();
          const cfg = { ...this.cfg.rowActions[Number(run.dataset.run)], id: run.closest('tr').dataset.id };
          runAction(cfg, run).then((res) => { if (res && !res.redirect && !res.reload) this.load(); });
        }
      });
      this.expandAllBtn?.addEventListener('click', () => {
        const rows = $$('tr[data-id] [data-expand]', this.tbody).map((b) => b.closest('tr'));
        const open = !rows.every((tr) => this.expanded.has(tr.dataset.id));
        rows.forEach((tr) => this.toggleRow(tr, open));
      });
      const sibling = (tr, dir) => {
        let el = tr[dir];
        while (el && !el.dataset.id) el = el[dir]; // salta las filas de detalle
        return el || tr;
      };
      this.tbody.addEventListener('keydown', (e) => {
        const tr = e.target.closest('tr[data-id]');
        if (!tr || e.target !== tr) return;
        if (e.key === 'Enter') { e.preventDefault(); this.open(tr.dataset.id); }
        else if (e.key === 'ArrowDown') { e.preventDefault(); sibling(tr, 'nextElementSibling').focus(); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); sibling(tr, 'previousElementSibling').focus(); }
        else if ((e.key === 'ArrowRight' || e.key === 'ArrowLeft') && $('[data-expand]', tr)) {
          e.preventDefault();
          this.toggleRow(tr, e.key === 'ArrowRight');
        }
        else if (e.key === 'Delete' && this.cfg.canDelete && !Dialog.isOpen()) { e.preventDefault(); this.remove(tr.dataset.id); }
        else if (e.key === 'PageDown') { e.preventDefault(); this.goTo(this.state.page + 1); }
        else if (e.key === 'PageUp') { e.preventDefault(); this.goTo(this.state.page - 1); }
      });
    }

    /** Vuelve a la primera página con los filtros actuales. */
    refresh() {
      this.state.page = 1;
      this.updateFilterBadge();
      this.load();
    }

    // ---------------------------------------------------------------- chips de filtros activos

    /** Un chip por filtro con valor (y por la búsqueda): «País: España, Portugal ×». */
    renderChips() {
      if (!this.chips) return;
      const items = [];
      const q = this.search?.value.trim();
      if (q) items.push(['_q', this.i18n.search, q]);
      $$('[data-filter]', this.form || document.createElement('form')).forEach((block) => {
        const text = this.filterText(block);
        if (text) items.push([block.dataset.filter, $('label', block)?.textContent.trim() ?? '', text]);
      });
      this.chips.replaceChildren(...items.map(([key, label, text]) => {
        const chip = removableTag('', 'blue', this.i18n.removeFilter);
        chip.classList.add('qs-chip');
        chip.dataset.chipFor = key;
        const title = document.createElement('span');
        title.className = 'qs-chip-label';
        title.textContent = `${label}:`;
        chip.querySelector('.qs-tag-text').before(title);
        chip.querySelector('.qs-tag-text').textContent = text;
        chip.title = `${label}: ${text}`;
        return chip;
      }));
      this.chips.hidden = items.length === 0;
    }

    /** Texto legible del valor de un filtro, o '' si está vacío. */
    filterText(block) {
      const ctrls = $$('input[name], select[name]', block);
      const sel = ctrls.find((c) => c.tagName === 'SELECT');
      if (sel) return [...sel.selectedOptions].filter((o) => o.value !== '').map((o) => o.textContent.trim()).join(', ');
      const show = (c) => {
        const v = c.value.trim();
        if (v && c.type === 'date') {
          const d = new Date(`${v}T00:00:00`);
          return Number.isNaN(d.getTime()) ? v : d.toLocaleDateString(document.documentElement.lang || undefined);
        }
        return v;
      };
      if (ctrls.length === 2) {
        const [from, to] = ctrls.map(show);
        if (!from && !to) return '';
        if (!to) return `≥ ${from}`;
        if (!from) return `≤ ${to}`;
        return `${from} – ${to}`;
      }
      return ctrls[0] ? show(ctrls[0]) : '';
    }

    clearChip(key) {
      if (key === '_q') { if (this.search) this.search.value = ''; return; }
      $$('input[name], select[name]', this.form.querySelector(`[data-filter="${CSS.escape(key)}"]`)).forEach((el) => {
        el.value = '';
        el.dispatchEvent(new Event('qs-sync'));
      });
    }

    // ---------------------------------------------------------------- filtrar con lenguaje natural

    /** Envía la frase al servidor, que la convierte en filtros validados, y los aplica al panel. */
    async ask() {
      const input = $('input[name="ai_q"]', this.aiForm);
      const text = input.value.trim();
      if (!text) { input.focus(); return; }
      const button = $('button[type="submit"]', this.aiForm);
      this.aiForm.classList.add('is-busy');
      button.disabled = true;
      try {
        const res = await request(this.cfg.ai.url, { method: 'POST', data: { q: text } });
        if (!res.ok) {
          Toast.show(res.note || this.i18n.aiNotUnderstood, 'warning', 6000);
          return;
        }
        this.applyAi(res.params);
        if (res.note) Toast.show(res.note, 'info', 6000);
      } catch (err) {
        Dialog.alert(err.message, { variant: 'danger' });
      } finally {
        this.aiForm.classList.remove('is-busy');
        button.disabled = false;
      }
    }

    /** Sustituye búsqueda, filtros y orden por los que ha devuelto el servidor. */
    applyAi(params) {
      if (this.form) {
        this.clearFilters();
        const entries = [];
        Object.entries(params.f || {}).forEach(([field, value]) => {
          if (Array.isArray(value)) value.forEach((v) => entries.push([`f[${field}][]`, v]));
          else if (value && typeof value === 'object') {
            if (value.from) entries.push([`f[${field}][from]`, value.from]);
            if (value.to) entries.push([`f[${field}][to]`, value.to]);
          } else entries.push([`f[${field}]`, value]);
        });
        const labels = {};
        Object.entries(params.labels || {}).forEach(([field, map]) => { labels[`f[${field}][]`] = map; });
        this.setFilterEntries(entries, labels);
        $$('[data-filter] select', this.form).forEach((el) => el.dispatchEvent(new Event('qs-sync')));
      }
      if (this.search) this.search.value = params.q || '';
      if (params.sort) {
        this.state.sort = params.sort;
        this.state.dir = params.dir === 'desc' ? 'desc' : 'asc';
      }
      this.refresh();
    }

    goTo(page) {
      const p = Math.min(Math.max(1, page), this.pages);
      if (p !== this.state.page) {
        this.state.page = p;
        this.load();
      } else if (this.pageInput) {
        this.pageInput.value = p;
      }
    }

    editUrl(id) {
      return `${this.cfg.editUrl}&id=${encodeURIComponent(id)}`;
    }

    open(id) {
      if (this.cfg.editUrl) window.location.href = this.editUrl(id); // las tablas de informes no tienen ficha
    }

    // Mismos parámetros que la consulta de datos (filtros, búsqueda y orden)
    queryParams() {
      const params = new URLSearchParams(this.filterEntries());
      Object.entries(this.state).forEach(([k, v]) => params.set(k, v));
      return params;
    }

    exportTo(format) {
      const params = this.queryParams();
      params.delete('page');
      params.delete('size');
      params.set('format', format);
      window.location.href = `${this.cfg.exportUrl}&${params}`;
    }

    filterEntries() {
      return this.form ? Array.from(new FormData(this.form).entries()) : [];
    }

    async load() {
      this.controller?.abort();
      const controller = new AbortController();
      this.controller = controller;

      const params = this.queryParams();
      this.renderChips();

      this.root.classList.add('is-loading');
      try {
        const data = await request(`${this.cfg.dataUrl}&${params}`, { signal: controller.signal });
        Object.assign(this.state, { page: data.page, size: data.size, sort: data.sort, dir: data.dir });
        this.pages = data.pages;
        store.set(this.storeKey, { ...this.state, filters: this.filterEntries() });
        this.render(data);
      } catch (err) {
        if (err.name === 'AbortError') return;
        this.message(`${this.i18n.error}${err.message ? `: ${err.message}` : ''}`);
      } finally {
        if (this.controller === controller) this.root.classList.remove('is-loading');
      }
    }

    render(data) {
      const hadFocus = this.tbody.contains(document.activeElement);
      this.tbody.replaceChildren();
      if (!data.rows.length) {
        this.message(this.i18n.empty);
      } else {
        const frag = document.createDocumentFragment();
        data.rows.forEach((row) => {
          const tr = this.renderRow(row);
          frag.append(tr);
          if (row.details && this.expanded.has(row.id)) frag.append(this.renderDetail(tr, row));
        });
        this.tbody.append(frag);
        if (hadFocus) this.tbody.firstElementChild.focus();
      }
      this.syncExpandAll();
      this.renderTotals(data.totals);

      const from = data.total ? (data.page - 1) * data.size + 1 : 0;
      const to = Math.min(data.page * data.size, data.total);
      this.info.textContent = fmt(this.i18n.info, { from, to, total: data.total });
      if (this.pageInput) { this.pageInput.value = data.page; this.pageInput.max = data.pages; }
      if (this.pagesLabel) this.pagesLabel.textContent = data.pages;
      if (this.sizeSelect) this.sizeSelect.value = String(data.size);
      $$('[data-page]', this.root).forEach((btn) => {
        const back = btn.dataset.page === 'first' || btn.dataset.page === 'prev';
        btn.disabled = back ? data.page <= 1 : data.page >= data.pages;
      });
      $$('th[data-sortable]', this.root).forEach((th) => {
        const active = th.dataset.field === data.sort;
        th.setAttribute('aria-sort', active ? (data.dir === 'asc' ? 'ascending' : 'descending') : 'none');
      });
    }

    renderRow(row) {
      const tr = document.createElement('tr');
      tr.dataset.id = row.id;
      tr.tabIndex = 0;
      tr._row = row;
      if (this.cfg.expand.length) {
        const td = document.createElement('td');
        td.className = 'qs-col-expand';
        if (row.details) {
          const btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'qs-icon-btn qs-expand-btn';
          btn.dataset.expand = '';
          btn.tabIndex = -1; // desde el teclado: → y ← sobre la fila
          btn.setAttribute('aria-expanded', 'false');
          btn.title = this.i18n.expand;
          btn.setAttribute('aria-label', this.i18n.expand);
          btn.append(icon('chevron-right'));
          td.append(btn);
        }
        tr.append(td);
      }
      for (const col of this.cfg.columns) {
        const td = document.createElement('td');
        td.className = `qs-align-${col.align}`;
        if (col.minWidth) td.style.minWidth = col.minWidth;
        if (col.maxWidth) td.style.maxWidth = col.maxWidth;
        const text = row.cells[col.field] ?? '';
        const tone = row.badges?.[col.field];
        const many = row.tags?.[col.field];
        const wrap = col.overflow === 'wrap';
        // Texto entero en varias líneas; con "lines", recortado a ese número de líneas
        const box = wrap && col.lines ? document.createElement('div') : td;
        if (wrap) td.classList.add('qs-cell-wrap');
        if (box !== td) {
          box.className = 'qs-cell-clamp';
          box.style.setProperty('--lines', String(col.lines));
          td.append(box);
        }
        if (many) {
          td.classList.add('qs-cell-tags');
          many.forEach(([t, c]) => box.append(tag(t, c)));
        } else if (tone && text !== '') box.append(tag(text, tone));
        else box.textContent = text;
        if (!wrap || col.lines) td.title = text; // el texto entero, si puede quedar recortado
        tr.append(td);
      }

      const actions = document.createElement('td');
      actions.className = 'qs-col-actions';
      if (this.cfg.editUrl) actions.append(this.iconLink(this.editUrl(row.id), this.cfg.canEdit ? this.i18n.edit : this.i18n.view, this.cfg.canEdit ? 'pencil' : 'eye'));
      // Conversación del registro: mensajes y, resaltados, los no leídos
      if (this.cfg.chat && row.chat) {
        const [total, unread] = row.chat;
        const link = this.iconLink(`${this.editUrl(row.id)}#chat`, fmt(unread ? this.i18n.chatUnread : this.i18n.chat, { n: unread || total }), 'message-circle');
        link.classList.add('qs-chat-count');
        if (unread) link.classList.add('has-unread');
        const n = document.createElement('span');
        n.textContent = String(unread || total);
        link.append(n);
        actions.append(link);
      }
      this.cfg.rowActions.forEach((action, i) => {
        if (row.hide?.includes(i)) return; // su "showon" no se cumple en esta fila
        if (action.url) {
          actions.append(this.iconLink(action.url.replaceAll('{id}', encodeURIComponent(row.id)), action.label, action.icon));
          return;
        }
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = `qs-icon-btn${action.variant === 'danger' ? ' qs-danger' : ''}`;
        btn.dataset.run = String(i);
        btn.title = action.label;
        btn.setAttribute('aria-label', action.label);
        if (hasIcon(action.icon)) btn.append(icon(action.icon));
        else btn.textContent = action.icon || action.label;
        actions.append(btn);
      });
      if (this.cfg.canDelete) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'qs-icon-btn qs-danger';
        btn.dataset.action = 'delete';
        btn.title = this.i18n.delete;
        btn.setAttribute('aria-label', this.i18n.delete);
        btn.append(icon('trash-2'));
        actions.append(btn);
      }
      tr.append(actions);
      return tr;
    }

    /** Pie con las sumas de "list.totals" (de todos los registros filtrados). */
    renderTotals(totals) {
      const table = this.tbody.closest('table');
      let tfoot = table.tFoot;
      if (!totals) { tfoot?.remove(); return; }
      if (!tfoot) tfoot = table.createTFoot();
      const tr = document.createElement('tr');
      tr.className = 'qs-totals';
      if (this.cfg.expand.length) tr.append(document.createElement('td'));
      let labelled = false;
      this.cfg.columns.forEach((col) => {
        const td = document.createElement('td');
        td.className = `qs-align-${col.align}`;
        if (col.field in totals) td.textContent = totals[col.field];
        else if (!labelled) { td.textContent = this.i18n.total; labelled = true; }
        tr.append(td);
      });
      tr.append(document.createElement('td'));
      tfoot.replaceChildren(tr);
    }

    /** Fila con los campos de "list.expand" (textos largos) debajo de la fila del registro. */
    renderDetail(tr, row) {
      const detail = document.createElement('tr');
      detail.className = 'qs-row-detail';
      detail.id = `${this.cfg.module}-detail-${row.id}`.replace(/[^\w-]/g, '_');
      const td = document.createElement('td');
      td.colSpan = this.colCount();
      const list = document.createElement('dl');
      list.className = 'qs-detail-list';
      for (const { field, label } of this.cfg.expand) {
        const d = row.details[field];
        if (!d) continue;
        const item = document.createElement('div');
        // Los textos largos o con varias líneas ocupan todo el ancho
        if (d.text.length > 60 || d.text.includes('\n')) item.className = 'is-wide';
        const dt = document.createElement('dt');
        dt.textContent = label;
        const dd = document.createElement('dd');
        if (d.tags) d.tags.forEach(([t, c]) => dd.append(tag(t, c), ' '));
        else if (d.tone) dd.append(tag(d.text, d.tone));
        else dd.textContent = d.text;
        item.append(dt, dd);
        list.append(item);
      }
      td.append(list);
      detail.append(td);
      const btn = $('[data-expand]', tr);
      btn.setAttribute('aria-expanded', 'true');
      btn.setAttribute('aria-controls', detail.id);
      btn.title = this.i18n.collapse;
      btn.setAttribute('aria-label', this.i18n.collapse);
      tr.classList.add('is-expanded');
      return detail;
    }

    /** Despliega o pliega una fila (open: true/false; sin indicar, alterna). */
    toggleRow(tr, open) {
      const btn = $('[data-expand]', tr);
      if (!btn) return;
      const isOpen = btn.getAttribute('aria-expanded') === 'true';
      const target = open ?? !isOpen;
      if (target === isOpen) return;
      if (target) {
        this.expanded.add(tr.dataset.id);
        tr.after(this.renderDetail(tr, tr._row));
      } else {
        this.expanded.delete(tr.dataset.id);
        const detail = tr.nextElementSibling;
        if (detail?.classList.contains('qs-row-detail')) detail.remove();
        btn.setAttribute('aria-expanded', 'false');
        btn.removeAttribute('aria-controls');
        btn.title = this.i18n.expand;
        btn.setAttribute('aria-label', this.i18n.expand);
        tr.classList.remove('is-expanded');
      }
      this.syncExpandAll();
    }

    /** El botón de la cabecera indica si están todas desplegadas. */
    syncExpandAll() {
      if (!this.expandAllBtn) return;
      const rows = $$('tr[data-id] [data-expand]', this.tbody);
      const all = rows.length > 0 && rows.every((b) => b.getAttribute('aria-expanded') === 'true');
      this.expandAllBtn.setAttribute('aria-expanded', String(all));
      this.expandAllBtn.title = all ? this.i18n.collapseAll : this.i18n.expandAll;
      this.expandAllBtn.setAttribute('aria-label', this.expandAllBtn.title);
      this.expandAllBtn.disabled = rows.length === 0;
    }

    colCount() {
      return this.cfg.columns.length + 1 + (this.cfg.expand.length ? 1 : 0);
    }

    iconLink(href, label, iconName) {
      const a = document.createElement('a');
      a.href = href;
      a.className = 'qs-icon-btn';
      a.title = label;
      a.setAttribute('aria-label', label);
      // Nombre de icono del sprite; si no lo es (p. ej. un emoji de una configuración antigua), texto
      if (hasIcon(iconName)) a.append(icon(iconName));
      else a.textContent = iconName || label;
      return a;
    }

    message(text) {
      const tr = document.createElement('tr');
      const td = document.createElement('td');
      td.className = 'qs-empty';
      td.colSpan = this.colCount();
      td.textContent = text;
      tr.append(td);
      this.tbody.replaceChildren(tr);
    }

    async remove(id) {
      const ok = await Dialog.confirm(this.i18n.confirmDelete, {
        title: this.i18n.deleteTitle,
        okText: this.i18n.delete,
        variant: 'danger',
      });
      if (!ok) return;
      try {
        await request(this.cfg.deleteUrl, { method: 'POST', data: { _id: id } });
        Toast.show(this.i18n.deleted, 'success');
        this.load();
      } catch (err) {
        Dialog.alert(err.message, { variant: 'danger' });
      }
    }
  }

  // ------------------------------------------------------------------ formularios

  function initForm(form) {
    let dirty = false;
    let leaving = false;
    // Escribir en un buscador (combobox, tags) no es un cambio hasta que se elige algo
    form.addEventListener('input', (e) => { if (e.target.name) dirty = true; });
    form.addEventListener('change', () => { dirty = true; });
    form.addEventListener('submit', (e) => { if (!e.defaultPrevented) leaving = true; });
    // Cerrar la pestaña o recargar: el navegador solo permite su aviso nativo
    window.addEventListener('beforeunload', (e) => {
      if (dirty && !leaving) { e.preventDefault(); e.returnValue = ''; }
    });

    // Navegar dentro de la aplicación con cambios sin guardar: aviso propio
    // href null: cerrar la ventana del alta rápida (quickCreate) en la que está el formulario
    const leaveTo = async (href) => {
      if (dirty && !leaving) {
        const ok = await Dialog.confirm(T.unsaved, {
          title: T.unsavedTitle, okText: T.leave, cancelText: T.stay, variant: 'warning',
        });
        if (!ok) return;
      }
      if (href === null) {
        window.parent.postMessage({ type: 'qs:modal-close' }, window.location.origin);
        return;
      }
      leaving = true;
      window.location.href = href;
    };
    form.addEventListener('click', (e) => {
      if (e.target.closest('[data-modal-close]')) { e.preventDefault(); leaveTo(null); }
    });
    window.addEventListener('message', (e) => {
      if (e.origin === window.location.origin && e.data?.type === 'qs:modal-escape') leaveTo(null);
    });
    document.addEventListener('click', (e) => {
      const a = e.target.closest('a[href]');
      if (!a || e.defaultPrevented || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
      if (a.target || a.hasAttribute('download') || a.closest('.qs-media') || a.getAttribute('href').startsWith('#')) return;
      if (!dirty || leaving) return;
      e.preventDefault();
      leaveTo(a.href);
    });

    document.addEventListener('keydown', (e) => {
      if (Dialog.isOpen()) return;
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
        e.preventDefault();
        // Ctrl+Mayús+S: "Desa i nou" (si el usuario puede crear); si no, guardar
        const save = (e.shiftKey && $('[data-shortcut="save-new"]', form)) || $('[data-shortcut="save"]', form);
        if (save) form.requestSubmit(save);
      } else if (e.key === 'Escape' && !e.target.closest?.('select')) {
        const back = $('[data-role="back"]', form);
        if (back) leaveTo('modalClose' in back.dataset ? null : back.href);
      }
    });

    const first = $('.has-error .qs-input', form)
      || $('.qs-section .qs-input:not([readonly]):not([disabled]):not([type="hidden"])', form);
    first?.focus();
  }

  /*
   * Botones de envío con confirmación:
   *   <button data-confirm="¿Seguro?" [data-confirm-title="…"] [data-confirm-ok="…"] [data-confirm-variant="danger">
   * Los botones .qs-btn-danger usan la variante "danger" automáticamente.
   */
  document.addEventListener('submit', (e) => {
    const btn = e.submitter;
    if (!btn?.dataset.confirm) return;
    if (btn.dataset.confirmed) { delete btn.dataset.confirmed; return; }
    e.preventDefault();
    e.stopImmediatePropagation();
    const form = e.target;
    const danger = btn.dataset.confirmVariant === 'danger' || btn.classList.contains('qs-btn-danger');
    Dialog.confirm(btn.dataset.confirm, {
      title: btn.dataset.confirmTitle || (danger ? T.deleteTitle : T.confirmTitle),
      okText: btn.dataset.confirmOk || btn.textContent.trim(),
      variant: btn.dataset.confirmVariant || (danger ? 'danger' : 'default'),
    }).then((ok) => {
      if (!ok) return;
      btn.dataset.confirmed = '1';
      form.requestSubmit(btn);
    });
  }, true);

  // Formularios lentos (p. ej. los que esperan a la IA): <form data-busy="Texto mientras se envía">
  document.addEventListener('submit', (e) => {
    const form = e.target;
    if (e.defaultPrevented || !form.matches?.('form[data-busy]')) return;
    form.classList.add('is-busy');
    setTimeout(() => {
      $$('button[type="submit"]', form).forEach((b) => {
        b.disabled = true;
        if (b === e.submitter || !e.submitter) b.textContent = form.dataset.busy;
      });
    });
  });

  // ------------------------------------------------------------------ campos condicionales (showon)

  /*
   * Cada campo va en un contenedor [data-field-name]; los condicionales llevan además
   * data-showon con la condición ya analizada por el servidor (Quasar\Core\ShowOn).
   * Ámbito: el formulario, o la fila si el campo está dentro de un repeater.
   */
  const scopeOf = (el) => el.parentElement?.closest('[data-row], form') || document.body;
  const showonCache = new WeakMap();
  const showonOf = (w) => {
    if (!showonCache.has(w)) showonCache.set(w, JSON.parse(w.dataset.showon));
    return showonCache.get(w);
  };

  const findField = (scope, name) => $$(`[data-field-name="${CSS.escape(name)}"]`, scope).find((w) => scopeOf(w) === scope);

  /** Valores actuales de un campo como lista de textos (un campo oculto cuenta como vacío). */
  const fieldValues = (w) => {
    if (!w || w.hidden) return [''];
    const ctrls = $$('input, select, textarea', w)
      .filter((c) => c.closest('[data-field-name]') === w && c.name && !['file', 'button', 'submit'].includes(c.type));
    const box = ctrls.find((c) => c.type === 'checkbox');
    if (box && ctrls.every((c) => c.type === 'checkbox' || c.type === 'hidden')) return [box.checked ? '1' : '0'];
    const radios = ctrls.filter((c) => c.type === 'radio');
    if (radios.length) return [radios.find((r) => r.checked)?.value ?? ''];
    const visible = ctrls.filter((c) => c.type !== 'hidden');
    if (visible.length) {
      const c = visible[0];
      if (c.multiple) {
        const selected = [...c.selectedOptions].map((o) => o.value);
        return selected.length ? selected : [''];
      }
      return [c.value.trim()];
    }
    const values = ctrls.map((c) => c.value).filter((v) => v !== ''); // p. ej. media: ids en inputs ocultos
    return values.length ? values : [''];
  };

  const conditionMet = (cond, values) => {
    const hit = values.some((v) => cond.values.includes(v));
    return cond.not ? !hit : hit;
  };

  // Un campo oculto no se envía ni bloquea la validación del navegador (sus controles se desactivan)
  const setFieldVisible = (w, show) => {
    w.hidden = !show;
    $$('input, select, textarea, button', w).forEach((c) => {
      if (!show && !c.disabled) {
        c.disabled = true;
        c.dataset.showonDisabled = '1';
      } else if (show && c.dataset.showonDisabled && !c.closest('[data-field-name][hidden]')) {
        c.disabled = false;
        delete c.dataset.showonDisabled;
      }
    });
  };

  function applyShowOn(root) {
    const targets = $$('[data-showon]', root);
    // Se repite hasta que no cambia nada: resuelve dependencias encadenadas (A → B → C)
    for (let pass = 0; pass <= targets.length; pass++) {
      let changed = false;
      for (const w of targets) {
        const scope = scopeOf(w);
        const show = showonOf(w).some((group) => group.every((cond) => conditionMet(cond, fieldValues(findField(scope, cond.field)))));
        if (w.hidden === show) {
          setFieldVisible(w, show);
          changed = true;
        }
      }
      if (!changed) break;
    }
    // Secciones cuyos campos están todos ocultos
    $$('.qs-section', root).forEach((section) => {
      const fields = $$(':scope > .qs-grid > [data-field-name]', section);
      if (fields.length && fields.some((f) => f.dataset.showon)) section.hidden = fields.every((f) => f.hidden);
    });
  }

  function initShowOn(form) {
    $$('[data-showon][hidden]', form).forEach((w) => setFieldVisible(w, false)); // estado inicial del servidor
    applyShowOn(form);
    const update = () => applyShowOn(form);
    form.addEventListener('input', update);
    form.addEventListener('change', update);
  }

  // ------------------------------------------------------------------ permisos

  function initPermissions(form) {
    const boxes = (scope) => $$('input[type="checkbox"][data-action]', scope);
    const sync = (row, changed) => {
      const view = $('input[data-action="view"]', row);
      if (!view) return;
      if (changed !== view && changed.checked) view.checked = true;
      if (changed === view && !view.checked) boxes(row).forEach((b) => { b.checked = false; });
    };
    const toggleAll = (list) => {
      const target = !list.every((b) => b.checked);
      list.forEach((b) => { b.checked = target; sync(b.closest('tr'), b); });
      form.dispatchEvent(new Event('change'));
    };

    form.addEventListener('change', (e) => {
      if (e.target.matches('input[data-action]')) sync(e.target.closest('tr'), e.target);
    });
    $$('[data-toggle-row]', form).forEach((btn) => btn.addEventListener('click', () => toggleAll(boxes(btn.closest('tr')))));
    $$('[data-toggle-col]', form).forEach((btn) => btn.addEventListener('click', () => {
      toggleAll($$(`input[data-action="${CSS.escape(btn.dataset.toggleCol)}"]`, form));
    }));
  }

  // ------------------------------------------------------------------ ordenar arrastrando

  // Reordena los hijos directos de `container` que cumplan `itemSelector`, arrastrando por [data-drag-handle]
  function makeSortable(container, itemSelector, onChange) {
    let dragging = null;
    const isOwnItem = (el) => el && el.parentElement === container && el.matches(itemSelector);

    container.addEventListener('mousedown', (e) => {
      const item = e.target.closest('[data-drag-handle]')?.closest(itemSelector);
      if (isOwnItem(item)) item.draggable = true;
    });
    container.addEventListener('mouseup', () => {
      $$(`:scope > ${itemSelector}`, container).forEach((el) => { el.draggable = false; });
    });
    container.addEventListener('dragstart', (e) => {
      const item = e.target;
      if (!isOwnItem(item) || !item.draggable) return;
      e.stopPropagation();
      dragging = item;
      item.classList.add('is-dragging');
      e.dataTransfer.effectAllowed = 'move';
      e.dataTransfer.setData('text/plain', '');
    });
    container.addEventListener('dragover', (e) => {
      if (!dragging) return;
      e.preventDefault();
      const after = $$(`:scope > ${itemSelector}`, container)
        .filter((el) => el !== dragging)
        .find((el) => { const r = el.getBoundingClientRect(); return e.clientY < r.top + r.height / 2; });
      container.insertBefore(dragging, after || null);
    });
    container.addEventListener('dragend', () => {
      if (!dragging) return;
      dragging.classList.remove('is-dragging');
      dragging.draggable = false;
      dragging = null;
      onChange();
    });
  }

  // ------------------------------------------------------------------ repeater

  let rowSeq = 0;

  function initRepeater(root) {
    if (root.dataset.ready) return;
    root.dataset.ready = '1';
    const rows = $(':scope > [data-rows]', root);
    const tpl = $(':scope > template[data-row-template]', root);
    const empty = $(':scope > [data-empty]', root);
    const addBtn = $(':scope > .qs-repeater-footer [data-add]', root);
    const counter = $(':scope > .qs-repeater-footer [data-counter]', root);
    const min = parseInt(root.dataset.min, 10) || 0;
    const max = parseInt(root.dataset.max, 10) || 0;
    const ownRows = () => $$(':scope > [data-row]', rows);

    const update = () => {
      const n = ownRows().length;
      empty.hidden = n > 0;
      if (addBtn) addBtn.disabled = max > 0 && n >= max;
      if (counter) counter.textContent = `${n} / ${max}`;
      ownRows().forEach((row) => {
        const remove = $(':scope > .qs-repeater-tools [data-remove]', row);
        if (remove) remove.disabled = n <= min;
      });
    };
    const changed = () => {
      update();
      root.dispatchEvent(new Event('change', { bubbles: true }));
    };

    addBtn?.addEventListener('click', () => {
      const key = `n${Date.now().toString(36)}${rowSeq++}`;
      const t = document.createElement('template');
      t.innerHTML = tpl.innerHTML.replaceAll(root.dataset.placeholder, key);
      const row = t.content.firstElementChild;
      rows.append(row);
      initWidgets(row);
      const form = root.closest('form');
      if (form) {
        $$('[data-showon][hidden]', row).forEach((w) => setFieldVisible(w, false));
        applyShowOn(form);
      }
      changed();
      $('.qs-input:not([type="hidden"]):not([readonly])', row)?.focus();
    });

    root.addEventListener('click', (e) => {
      const btn = e.target.closest('[data-remove], [data-move]');
      // Solo los botones de las filas de ESTE repeater (no los de campos anidados)
      if (!btn || !btn.parentElement.matches('.qs-repeater-tools')) return;
      const row = btn.closest('[data-row]');
      if (row.parentElement !== rows) return;
      if (btn.dataset.move === 'up' && row.previousElementSibling) rows.insertBefore(row, row.previousElementSibling);
      else if (btn.dataset.move === 'down' && row.nextElementSibling) rows.insertBefore(row.nextElementSibling, row);
      else if (btn.hasAttribute('data-remove')) row.remove();
      changed();
    });

    makeSortable(rows, '[data-row]', changed);
    update();
  }

  // ------------------------------------------------------------------ media (dropzone)

  const formatSize = (bytes) => {
    const units = ['B', 'KB', 'MB', 'GB'];
    let i = 0;
    let n = bytes;
    while (n >= 1024 && i < units.length - 1) { n /= 1024; i++; }
    return i === 0 ? `${bytes} B` : `${n.toFixed(n < 10 ? 1 : 0)} ${units[i]}`;
  };

  function initMedia(root) {
    if (root.dataset.ready) return;
    root.dataset.ready = '1';
    const cfg = JSON.parse(root.dataset.media);
    const list = $(':scope > [data-list]', root);
    const fileInput = $('[data-file-input]', root);
    const zone = $('[data-dropzone]', root);
    const inputName = cfg.multiple ? `${cfg.name}[]` : cfg.name;
    const items = () => $$(':scope > [data-item]', list);
    const changed = () => root.dispatchEvent(new Event('change', { bubbles: true }));

    const accepts = (file) => {
      if (!cfg.accept.length) return true;
      const ext = `.${file.name.split('.').pop().toLowerCase()}`;
      return cfg.accept.some((rule) => {
        const r = rule.toLowerCase();
        return r === ext || r === file.type || (r.endsWith('/*') && file.type.startsWith(r.slice(0, -1)));
      });
    };

    const notify = (text) => {
      const msg = document.createElement('div');
      msg.className = 'qs-media-msg';
      msg.setAttribute('role', 'alert');
      msg.textContent = text;
      root.insertBefore(msg, zone);
      setTimeout(() => msg.remove(), 6000);
    };

    // Construye (o rellena) un elemento de la lista a partir de la info del servidor
    const fillItem = (li, info) => {
      li.replaceChildren();
      const hidden = document.createElement('input');
      hidden.type = 'hidden';
      if (info.id) { hidden.name = inputName; hidden.value = info.id; }
      li.append(hidden);
      if (cfg.multiple) {
        const handle = document.createElement('span');
        handle.className = 'qs-repeater-handle';
        handle.dataset.dragHandle = '';
        handle.append(icon('grip-vertical'));
        li.append(handle);
      }
      const thumb = document.createElement('a');
      thumb.className = 'qs-media-thumb';
      thumb.target = '_blank';
      thumb.rel = 'noopener';
      if (info.url) thumb.href = info.url;
      if (info.isImage && (info.thumb || info.preview)) {
        const img = document.createElement('img');
        img.src = info.thumb || info.preview;
        img.alt = '';
        thumb.append(img);
      } else {
        const ext = document.createElement('span');
        ext.className = 'qs-media-ext';
        ext.textContent = (info.ext || info.name.split('.').pop() || '').toUpperCase();
        thumb.append(ext);
      }
      const meta = document.createElement('span');
      meta.className = 'qs-media-meta';
      const name = document.createElement(info.download ? 'a' : 'span');
      name.className = 'qs-media-name';
      name.textContent = info.name;
      if (info.download) { name.href = info.download; name.title = cfg.i18n.download; }
      const size = document.createElement('span');
      size.className = 'qs-muted';
      size.textContent = info.sizeText || '';
      meta.append(name, size);
      if (info.uploading) {
        const bar = document.createElement('span');
        bar.className = 'qs-progress';
        bar.append(document.createElement('span'));
        meta.append(bar);
      }
      const remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'qs-icon-btn qs-danger';
      remove.dataset.remove = '';
      remove.title = cfg.i18n.remove;
      remove.append(icon('x'));
      li.append(thumb, meta, remove);
    };

    const upload = (file) => {
      if (file.size > cfg.maxSize) { notify(`${file.name}: ${cfg.i18n.tooLarge}`); return; }
      if (!accepts(file)) { notify(`${file.name}: ${cfg.i18n.type}`); return; }
      if (cfg.multiple && items().length >= cfg.maxFiles) { notify(cfg.i18n.maxFiles); return; }
      if (!cfg.multiple) items().forEach((li) => { li.xhr?.abort(); li.remove(); });

      const li = document.createElement('li');
      li.className = 'qs-media-item is-uploading';
      li.dataset.item = '';
      const preview = file.type.startsWith('image/') ? URL.createObjectURL(file) : null;
      fillItem(li, { name: file.name, sizeText: formatSize(file.size), isImage: !!preview, preview, uploading: true });
      list.append(li);

      const fail = (message) => {
        li.classList.remove('is-uploading');
        li.classList.add('has-error');
        $('.qs-media-meta .qs-muted', li).textContent = message;
        $('.qs-progress', li)?.remove();
        setTimeout(() => li.remove(), 6000);
      };

      const xhr = new XMLHttpRequest();
      li.xhr = xhr;
      xhr.open('POST', cfg.uploadUrl);
      xhr.setRequestHeader('Accept', 'application/json');
      xhr.setRequestHeader('X-Requested-With', 'fetch');
      xhr.upload.addEventListener('progress', (e) => {
        if (e.lengthComputable) $('.qs-progress > span', li).style.width = `${Math.round((e.loaded / e.total) * 100)}%`;
      });
      xhr.addEventListener('load', () => {
        if (preview) URL.revokeObjectURL(preview);
        if (xhr.status === 401) { window.location.reload(); return; }
        let data = {};
        try { data = JSON.parse(xhr.responseText); } catch { /* respuesta no JSON */ }
        if (xhr.status >= 200 && xhr.status < 300 && data.id) {
          li.classList.remove('is-uploading');
          li.xhr = null;
          fillItem(li, data);
          changed();
        } else {
          fail(data.error || cfg.i18n.error);
        }
      });
      xhr.addEventListener('error', () => fail(cfg.i18n.error));
      const body = new FormData();
      body.append('file', file);
      body.append('_csrf', csrfToken());
      xhr.send(body);
    };

    const handleFiles = (files) => {
      const selected = Array.from(files);
      (cfg.multiple ? selected : selected.slice(0, 1)).forEach(upload);
    };

    fileInput.addEventListener('change', () => { handleFiles(fileInput.files); fileInput.value = ''; });
    zone.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fileInput.click(); }
    });
    const hasFiles = (e) => Array.from(e.dataTransfer?.types || []).includes('Files');
    ['dragenter', 'dragover'].forEach((type) => zone.addEventListener(type, (e) => {
      if (!hasFiles(e)) return;
      e.preventDefault();
      zone.classList.add('is-over');
    }));
    ['dragleave', 'drop'].forEach((type) => zone.addEventListener(type, () => zone.classList.remove('is-over')));
    zone.addEventListener('drop', (e) => {
      if (!hasFiles(e)) return;
      e.preventDefault();
      handleFiles(e.dataTransfer.files);
    });

    list.addEventListener('click', (e) => {
      const btn = e.target.closest('[data-remove]');
      const li = btn?.closest('[data-item]');
      if (!li || li.parentElement !== list) return;
      li.xhr?.abort();
      li.remove();
      changed();
    });

    if (cfg.multiple) makeSortable(list, '[data-item]', changed);
  }

  // No se envía el formulario mientras haya subidas en curso
  document.addEventListener('submit', (e) => {
    const pending = $('.qs-media-item.is-uploading', e.target);
    if (!pending) return;
    e.preventDefault();
    const media = pending.closest('[data-media]');
    Dialog.alert(media ? JSON.parse(media.dataset.media).i18n.wait : '…', { variant: 'warning' });
  }, true);

  // ------------------------------------------------------------------ lista desplegable (combobox y tags)

  let dropdownSeq = 0;
  const fold = (text) => text.normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase();

  class Dropdown {
    /** @param autoActive marca de entrada la primera opción (combobox) o ninguna hasta usar las flechas (tags) */
    constructor(owner, onPick, { autoActive = true } = {}) {
      this.owner = owner; // elemento que recibe aria-activedescendant
      this.onPick = onPick;
      this.autoActive = autoActive;
      this.items = [];
      this.active = -1;
      this.el = document.createElement('div');
      this.el.className = 'qs-dropdown';
      this.el.hidden = true;
      this.list = document.createElement('ul');
      this.list.className = 'qs-dropdown-list';
      this.list.setAttribute('role', 'listbox');
      this.list.id = `qs-dd-${++dropdownSeq}`;
      this.el.append(this.list);
      owner.setAttribute('aria-controls', this.list.id);
      this.list.addEventListener('mousedown', (e) => e.preventDefault()); // no quitar el foco al buscador
      this.list.addEventListener('click', (e) => {
        const li = e.target.closest('li[data-i]');
        if (li) this.onPick(this.items[Number(li.dataset.i)]);
      });
    }

    get open() { return !this.el.hidden; }

    show(items, emptyText = '') {
      this.items = items;
      this.list.replaceChildren();
      if (!items.length) {
        if (!emptyText) { this.hide(); return; }
        this.message(emptyText);
        return;
      }
      items.forEach((item, i) => {
        const li = document.createElement('li');
        li.id = `${this.list.id}-${i}`;
        li.dataset.i = String(i);
        li.setAttribute('role', 'option');
        li.setAttribute('aria-selected', item.selected ? 'true' : 'false');
        li.textContent = item.label;
        if (item.selected) li.classList.add('is-selected');
        this.list.append(li);
      });
      this.el.hidden = false;
      this.owner.setAttribute('aria-expanded', 'true');
      if (this.autoActive) this.setActive(Math.max(0, items.findIndex((it) => it.selected)));
      else this.active = -1;
    }

    message(text) {
      this.items = [];
      this.active = -1;
      const li = document.createElement('li');
      li.className = 'qs-dropdown-empty';
      li.textContent = text;
      this.list.replaceChildren(li);
      this.el.hidden = false;
      this.owner.setAttribute('aria-expanded', 'true');
    }

    hide() {
      this.el.hidden = true;
      this.active = -1;
      this.owner.setAttribute('aria-expanded', 'false');
      this.owner.removeAttribute('aria-activedescendant');
    }

    move(delta) {
      if (!this.items.length) return;
      const start = this.active < 0 ? (delta > 0 ? -1 : 0) : this.active;
      this.setActive((start + delta + this.items.length) % this.items.length);
    }

    setActive(i) {
      this.active = i;
      $$('li[data-i]', this.list).forEach((li, n) => li.classList.toggle('is-active', n === i));
      const li = this.list.children[i];
      if (li) {
        li.scrollIntoView({ block: 'nearest' });
        this.owner.setAttribute('aria-activedescendant', li.id);
      }
    }

    pickActive() {
      if (this.open && this.active >= 0 && this.items[this.active]) {
        this.onPick(this.items[this.active]);
        return true;
      }
      return false;
    }
  }

  // ------------------------------------------------------------------ select con buscador (como select2)

  /** Etiqueta con botón para quitarla (tags y selección múltiple). */
  const removableTag = (text, tone, removeLabel) => {
    const chip = tag('', tone);
    const label = document.createElement('span');
    label.className = 'qs-tag-text';
    label.textContent = text;
    const remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'qs-tag-remove';
    remove.dataset.remove = '';
    remove.title = removeLabel;
    remove.setAttribute('aria-label', `${removeLabel}: ${text}`);
    remove.append(icon('x'));
    chip.append(label, remove);
    return chip;
  };

  // Select dependiente ("dependsOn"): valores elegidos en el campo del que depende (cfg.parent = su name)
  const parentValues = (select, cfg) => (cfg.parent && select.form ? [...select.form.elements] : [])
    .filter((el) => el.name === cfg.parent)
    .flatMap((el) => (el.multiple ? [...el.selectedOptions].map((o) => o.value) : [el.value]))
    .filter((v) => v !== '');
  const remoteUrl = (select, cfg) => cfg.remote + parentValues(select, cfg).map((v) => `&parent[]=${encodeURIComponent(v)}`).join('');

  /** Al cambiar el campo del que depende, quita las opciones elegidas que ya no le corresponden. */
  function watchParent(select, cfg) {
    if (!cfg.parent || !cfg.remote || !select.form) return;
    select.form.addEventListener('change', async (e) => {
      if (e.target.name !== cfg.parent) return;
      const parent = parentValues(select, cfg);
      if (parent.length !== 1) return; // sin valor (o varios en un filtro): todas sirven
      let changed = false;
      for (const o of [...select.selectedOptions].filter((opt) => opt.value !== '')) {
        const d = await request(`${cfg.remote}&value=${encodeURIComponent(o.value)}&parent=${encodeURIComponent(parent[0])}`).catch(() => null);
        if (d && !d.results.length) { o.selected = false; changed = true; }
      }
      if (changed) {
        if (!select.multiple) select.value = '';
        select.dispatchEvent(new Event('change', { bubbles: true }));
      }
    });
  }

  function initCombobox(select) {
    if (select.dataset.comboReady) return;
    select.dataset.comboReady = '1';
    const cfg = JSON.parse(select.dataset.combobox);
    watchParent(select, cfg);
    if (select.multiple) { initMultiCombobox(select, cfg); return; }
    const required = select.hasAttribute('data-required');

    const wrap = document.createElement('div');
    wrap.className = 'qs-combo';
    select.before(wrap);
    wrap.append(select);
    select.classList.add('qs-visually-hidden');
    select.tabIndex = -1;

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'qs-input qs-combo-btn';
    btn.setAttribute('aria-haspopup', 'listbox');
    btn.setAttribute('aria-expanded', 'false');
    if (select.id) {
      // La etiqueta <label for="…"> del campo pasa a nombrar el botón
      const lbl = document.querySelector(`label[for="${CSS.escape(select.id)}"]`);
      if (lbl) { lbl.id = lbl.id || `${select.id}-label`; btn.setAttribute('aria-labelledby', lbl.id); lbl.htmlFor = ''; lbl.addEventListener('click', () => btn.focus()); }
    }
    const label = document.createElement('span');
    label.className = 'qs-combo-label';
    btn.append(label, icon('chevrons-up-down', 'qs-combo-caret'));

    const clear = document.createElement('button');
    clear.type = 'button';
    clear.className = 'qs-icon-btn qs-combo-clear';
    clear.title = cfg.clear;
    clear.setAttribute('aria-label', cfg.clear);
    clear.append(icon('x'));

    const panel = document.createElement('div');
    panel.className = 'qs-combo-panel';
    panel.hidden = true;
    const search = document.createElement('input');
    search.type = 'search';
    search.className = 'qs-input qs-combo-search';
    search.placeholder = cfg.search;
    search.autocomplete = 'off';
    search.setAttribute('role', 'combobox');
    search.setAttribute('aria-autocomplete', 'list');
    const dd = new Dropdown(search, (item) => pick(item));
    dd.el.classList.add('qs-dropdown-static');
    panel.append(search, dd.el);
    wrap.append(btn, clear, panel);

    const sync = () => {
      const opt = select.selectedOptions[0];
      const empty = !opt || opt.value === '';
      label.textContent = empty ? (cfg.placeholder || '') : opt.textContent;
      label.classList.toggle('is-placeholder', empty);
      clear.hidden = required || empty || select.disabled;
      btn.disabled = select.disabled;
    };

    const localItems = (q) => {
      const needle = fold(q.trim());
      return [...select.options]
        .filter((o) => o.value !== '' && (needle === '' || fold(o.textContent).includes(needle)))
        .slice(0, 300)
        .map((o) => ({ value: o.value, label: o.textContent, selected: o.selected }));
    };

    let timer;
    let controller;
    const render = (q) => {
      if (!cfg.remote) { dd.show(localItems(q), cfg.empty); return; }
      clearTimeout(timer);
      dd.message(cfg.loading);
      timer = setTimeout(async () => {
        controller?.abort();
        controller = new AbortController();
        try {
          const data = await request(`${remoteUrl(select, cfg)}&q=${encodeURIComponent(q)}`, { signal: controller.signal });
          dd.show(data.results.map((r) => ({ ...r, selected: r.value === select.value })), cfg.empty);
        } catch (err) {
          if (err.name !== 'AbortError') dd.message(err.message);
        }
      }, 220);
    };

    const open = (initial = '') => {
      if (select.disabled) return;
      panel.hidden = false;
      wrap.classList.add('is-open');
      btn.setAttribute('aria-expanded', 'true');
      search.value = initial;
      render(initial);
      search.focus();
    };
    const close = (focusButton = true) => {
      if (panel.hidden) return;
      panel.hidden = true;
      wrap.classList.remove('is-open');
      btn.setAttribute('aria-expanded', 'false');
      dd.hide();
      if (focusButton) btn.focus();
    };
    const setValue = (value) => {
      if (select.value === value) { sync(); return; }
      select.value = value;
      select.dispatchEvent(new Event('change', { bubbles: true }));
      sync();
    };
    const pick = (item) => {
      if (![...select.options].some((o) => o.value === item.value)) select.add(new Option(item.label, item.value));
      setValue(item.value);
      close();
    };

    btn.addEventListener('click', () => (panel.hidden ? open() : close()));
    btn.addEventListener('keydown', (e) => {
      if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(e.key)) { e.preventDefault(); open(); }
      else if (e.key.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) { e.preventDefault(); open(e.key); }
      else if ((e.key === 'Delete' || e.key === 'Backspace') && !clear.hidden) { e.preventDefault(); setValue(''); }
    });
    search.addEventListener('input', () => render(search.value));
    search.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowDown') { e.preventDefault(); dd.move(1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); dd.move(-1); }
      else if (e.key === 'Enter') { e.preventDefault(); dd.pickActive(); }
      else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(); }
      else if (e.key === 'Tab') close(false);
    });
    clear.addEventListener('click', () => { setValue(''); btn.focus(); });
    document.addEventListener('mousedown', (e) => { if (!wrap.contains(e.target)) close(false); });
    select.addEventListener('change', sync);
    select.addEventListener('qs-sync', sync);
    select.addEventListener('invalid', () => wrap.classList.add('is-invalid'));
    select.addEventListener('change', () => wrap.classList.remove('is-invalid'));
    // Valor restaurado de un filtro remoto: pedir su texto
    const pending = [...select.options].find((o) => o.dataset.pending);
    if (pending && cfg.remote) {
      request(`${cfg.remote}&value=${encodeURIComponent(pending.value)}`)
        .then((d) => { if (d.results[0]) pending.textContent = d.results[0].label; delete pending.dataset.pending; sync(); })
        .catch(() => {});
    }
    // Los controles desactivados/activados por showon
    new MutationObserver(sync).observe(select, { attributes: true, attributeFilter: ['disabled'] });
    sync();
  }

  // ------------------------------------------------------------------ selección múltiple

  /*
   * Select con "multiple": las opciones elegidas son etiquetas y se añaden escribiendo en el
   * buscador. El <select multiple> original (oculto) guarda la selección y es el que se envía.
   */
  function initMultiCombobox(select, cfg) {
    const wrap = document.createElement('div');
    wrap.className = 'qs-tags qs-combo-multi';
    select.before(wrap);
    select.classList.add('qs-visually-hidden');
    select.tabIndex = -1;

    const input = document.createElement('input');
    input.type = 'text';
    input.className = 'qs-tags-input';
    input.autocomplete = 'off';
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-expanded', 'false');
    if (select.id) {
      // La etiqueta <label for="…"> del campo pasa a nombrar el buscador
      const lbl = document.querySelector(`label[for="${CSS.escape(select.id)}"]`);
      if (lbl) { lbl.id = lbl.id || `${select.id}-label`; input.setAttribute('aria-labelledby', lbl.id); lbl.htmlFor = ''; lbl.addEventListener('click', () => input.focus()); }
    }
    const dd = new Dropdown(input, (item) => toggle(item));
    dd.list.setAttribute('aria-multiselectable', 'true');
    wrap.append(input, dd.el, select);

    const toneOf = (v) => cfg.colors?.[v] ?? cfg.colors?.['*'] ?? 'gray';
    const optionOf = (value) => [...select.options].find((o) => o.value === value);
    const isSelected = (value) => !!optionOf(value)?.selected;

    const sync = () => {
      $$(':scope > [data-chip]', wrap).forEach((el) => el.remove());
      for (const opt of select.selectedOptions) {
        const chip = removableTag(opt.textContent, toneOf(opt.value), cfg.remove);
        chip.dataset.chip = opt.value;
        $('[data-remove]', chip).hidden = select.disabled;
        input.before(chip);
      }
      input.placeholder = select.selectedOptions.length ? '' : (cfg.placeholder || '');
      input.disabled = select.disabled;
      wrap.classList.toggle('is-readonly', select.disabled);
    };
    const changed = () => {
      select.dispatchEvent(new Event('change', { bubbles: true }));
      wrap.classList.remove('is-invalid');
      sync();
    };

    let timer;
    let controller;
    const render = (q) => {
      if (!cfg.remote) {
        const needle = fold(q.trim());
        dd.show([...select.options]
          .filter((o) => o.value !== '' && (needle === '' || fold(o.textContent).includes(needle)))
          .slice(0, 300)
          .map((o) => ({ value: o.value, label: o.textContent, selected: o.selected })), cfg.empty);
        return;
      }
      clearTimeout(timer);
      dd.message(cfg.loading);
      timer = setTimeout(async () => {
        controller?.abort();
        controller = new AbortController();
        try {
          const data = await request(`${remoteUrl(select, cfg)}&q=${encodeURIComponent(q)}`, { signal: controller.signal });
          dd.show(data.results.map((r) => ({ ...r, selected: isSelected(r.value) })), cfg.empty);
        } catch (err) {
          if (err.name !== 'AbortError') dd.message(err.message);
        }
      }, 220);
    };
    const open = () => { if (!select.disabled) { wrap.classList.add('is-open'); render(input.value); } };
    const close = () => { dd.hide(); wrap.classList.remove('is-open'); input.value = ''; };

    // Elegir una opción la añade o la quita; la lista sigue abierta para elegir varias
    const toggle = (item) => {
      let opt = optionOf(item.value);
      if (!opt) { opt = new Option(item.label, item.value); select.add(opt); }
      if (!opt.selected && cfg.max && select.selectedOptions.length >= cfg.max) {
        Toast.show(cfg.maxText, 'warning');
        return;
      }
      opt.selected = !opt.selected;
      changed();
      const active = dd.active;
      dd.show(dd.items.map((it) => ({ ...it, selected: isSelected(it.value) })), cfg.empty);
      if (active >= 0) dd.setActive(active);
    };

    input.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowDown') { e.preventDefault(); if (!dd.open) open(); else dd.move(1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); dd.move(-1); }
      else if (e.key === 'Enter') { e.preventDefault(); if (!dd.pickActive()) open(); } // nunca envía el formulario
      else if (e.key === 'Escape' && dd.open) { e.preventDefault(); e.stopPropagation(); close(); }
      else if (e.key === 'Tab') close();
      else if (e.key === 'Backspace' && input.value === '' && select.selectedOptions.length) {
        [...select.selectedOptions].pop().selected = false;
        changed();
        if (dd.open) render('');
      }
    });
    input.addEventListener('input', () => open());
    wrap.addEventListener('click', (e) => {
      const remove = e.target.closest('[data-remove]');
      if (remove) {
        const opt = optionOf(remove.closest('[data-chip]').dataset.chip);
        if (opt) { opt.selected = false; changed(); }
        if (dd.open) render(input.value);
        input.focus();
      } else if (!e.target.closest('.qs-dropdown') && !select.disabled) {
        input.focus();
        if (!dd.open) open();
      }
    });
    document.addEventListener('mousedown', (e) => { if (!wrap.contains(e.target)) close(); });
    select.addEventListener('change', sync);
    select.addEventListener('qs-sync', sync);
    select.addEventListener('invalid', () => wrap.classList.add('is-invalid'));
    // Valores restaurados de un filtro remoto: pedir su texto
    if (cfg.remote) {
      [...select.options].filter((o) => o.dataset.pending).forEach((o) => {
        request(`${cfg.remote}&value=${encodeURIComponent(o.value)}`)
          .then((d) => {
            if (d.results[0]) o.textContent = d.results[0].label;
            delete o.dataset.pending;
            sync();
            select.dispatchEvent(new Event('qs-labels', { bubbles: true })); // p. ej. los chips de filtros
          })
          .catch(() => {});
      });
    }
    // Los controles desactivados/activados por showon
    new MutationObserver(sync).observe(select, { attributes: true, attributeFilter: ['disabled'] });
    sync();
  }

  // ------------------------------------------------------------------ campo de etiquetas (tags)

  function initTags(root) {
    if (root.dataset.ready) return;
    root.dataset.ready = '1';
    const cfg = JSON.parse(root.dataset.tags);
    const input = $('[data-tags-input]', root);
    // Enter añade lo escrito; una sugerencia solo se elige si se ha marcado con las flechas
    const dd = new Dropdown(input, (item) => { add(item.value); input.value = ''; dd.hide(); }, { autoActive: false });
    root.append(dd.el);

    const current = () => $$(':scope > [data-tag] input', root).map((i) => i.value);
    const norm = (text) => {
      let t = String(text).replace(/[\s\u00A0]+/g, ' ').trim().replace(/^,+|,+$/g, '').trim();
      if (cfg.lowercase) t = t.toLowerCase();
      return t;
    };
    const toneOf = (t) => cfg.colors[t] ?? cfg.colors[t.toLowerCase()] ?? cfg.colors['*'] ?? 'gray';
    const changed = () => root.dispatchEvent(new Event('change', { bubbles: true }));

    const add = (text) => {
      const t = norm(text);
      if (!t) return;
      const existing = $$(':scope > [data-tag]', root).find((el) => el.querySelector('input').value.toLowerCase() === t.toLowerCase());
      if (existing) {
        existing.classList.remove('is-flash');
        void existing.offsetWidth; // reinicia la animación
        existing.classList.add('is-flash');
        return;
      }
      if (t.length > cfg.maxLength) { Toast.show(`${cfg.i18n.tooLong} «${t.slice(0, 30)}…»`, 'warning'); return; }
      if (current().length >= cfg.maxTags) { Toast.show(cfg.i18n.maxTags, 'warning'); return; }
      const chip = removableTag(t, toneOf(t), cfg.i18n.remove);
      chip.dataset.tag = '';
      const hidden = document.createElement('input');
      hidden.type = 'hidden';
      hidden.name = cfg.name;
      hidden.value = t;
      chip.prepend(hidden);
      input.before(chip);
      changed();
    };

    let timer;
    const suggest = (q) => {
      const taken = new Set(current().map((t) => t.toLowerCase()));
      const show = (list) => dd.show(list.filter((t) => !taken.has(t.toLowerCase())).slice(0, 12).map((t) => ({ value: t, label: t })));
      if (cfg.suggestUrl) {
        clearTimeout(timer);
        timer = setTimeout(() => {
          request(`${cfg.suggestUrl}&q=${encodeURIComponent(q)}`).then((d) => { if (document.activeElement === input) show(d.results); }).catch(() => {});
        }, 200);
      } else if (cfg.suggestions.length) {
        const needle = fold(q.trim());
        show(cfg.suggestions.filter((t) => needle === '' || fold(t).includes(needle)));
      }
    };

    input.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' || e.key === ',' || (e.key === 'Tab' && input.value.trim() !== '')) {
        e.preventDefault(); // Enter nunca envía el formulario desde aquí
        if (!(e.key === 'Enter' && dd.pickActive())) { add(input.value); input.value = ''; }
        dd.hide();
      } else if (e.key === 'Backspace' && input.value === '') {
        const last = $$(':scope > [data-tag]', root).pop();
        if (last) { last.remove(); changed(); }
      } else if (e.key === 'ArrowDown') { e.preventDefault(); if (!dd.open) suggest(input.value); else dd.move(1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); dd.move(-1); }
      else if (e.key === 'Escape' && dd.open) { e.preventDefault(); e.stopPropagation(); dd.hide(); }
    });
    input.addEventListener('input', () => {
      // Pegar "a, b, c" crea varias etiquetas
      if (input.value.includes(',')) {
        const parts = input.value.split(',');
        input.value = parts.pop();
        parts.forEach(add);
      }
      suggest(input.value);
    });
    input.addEventListener('focus', () => suggest(input.value));
    input.addEventListener('blur', () => { add(input.value); input.value = ''; dd.hide(); });
    root.addEventListener('click', (e) => {
      const btn = e.target.closest('[data-remove]');
      if (btn && btn.closest('[data-tag]')?.parentElement === root) {
        btn.closest('[data-tag]').remove();
        changed();
        input.focus();
      } else if (e.target === root) input.focus();
    });
  }

  // ------------------------------------------------------------------ subidas, fechas y textos

  /** Sube un archivo por POST (campo "file") con barra de progreso opcional. Devuelve la respuesta JSON. */
  function uploadFile(url, file, onProgress = null) {
    return new Promise((resolve, reject) => {
      const xhr = new XMLHttpRequest();
      xhr.open('POST', url);
      xhr.setRequestHeader('Accept', 'application/json');
      xhr.setRequestHeader('X-Requested-With', 'fetch');
      if (onProgress) {
        xhr.upload.addEventListener('progress', (e) => { if (e.lengthComputable) onProgress(e.loaded / e.total); });
      }
      xhr.addEventListener('load', () => {
        if (xhr.status === 401) { window.location.reload(); return; }
        let data = {};
        try { data = JSON.parse(xhr.responseText); } catch { /* respuesta no JSON */ }
        if (xhr.status >= 200 && xhr.status < 300 && data.id) resolve(data);
        else reject(new Error(data.error || xhr.statusText || 'Error'));
      });
      xhr.addEventListener('error', () => reject(new Error(xhr.statusText || 'Error')));
      const body = new FormData();
      body.append('file', file);
      body.append('_csrf', csrfToken());
      xhr.send(body);
    });
  }

  const docLang = () => document.documentElement.lang || undefined;
  const timeOf = (date) => date.toLocaleTimeString(docLang(), { hour: '2-digit', minute: '2-digit' });

  /** "ara", "fa 5 min", "fa 2 h", "fa 3 d" o la fecha. Los textos llevan {n}. */
  function timeAgo(iso, t) {
    const date = new Date(iso);
    const s = Math.max(0, (Date.now() - date.getTime()) / 1000);
    if (s < 60) return t.now;
    if (s < 3600) return fmt(t.minutes, { n: Math.floor(s / 60) });
    if (s < 86400) return fmt(t.hours, { n: Math.floor(s / 3600) });
    if (s < 7 * 86400) return fmt(t.days, { n: Math.floor(s / 86400) });
    return date.toLocaleDateString(docLang());
  }

  /** Texto plano con enlaces (http/https) y @menciones resaltadas, sin interpretar HTML. */
  function richText(text, names = []) {
    const frag = document.createDocumentFragment();
    const escaped = names.filter(Boolean).sort((a, b) => b.length - a.length).map((n) => n.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'));
    const re = new RegExp(`(https?://[^\\s<>"']+)${escaped.length ? `|(@(?:${escaped.join('|')}))` : ''}`, 'gu');
    let last = 0;
    for (const m of text.matchAll(re)) {
      if (m.index > last) frag.append(text.slice(last, m.index));
      if (m[1]) {
        const url = m[1].replace(/[.,;:!?)]+$/, '');
        const a = document.createElement('a');
        a.href = url;
        a.target = '_blank';
        a.rel = 'noopener noreferrer';
        a.textContent = url;
        frag.append(a);
        last = m.index + url.length;
        continue;
      }
      const mention = document.createElement('span');
      mention.className = 'qs-mention';
      mention.textContent = m[2];
      frag.append(mention);
      last = m.index + m[0].length;
    }
    if (last < text.length) frag.append(text.slice(last));
    return frag;
  }

  // ------------------------------------------------------------------ texto con formato (richtext)

  function initRichText(root) {
    if (root.qsReady) return;
    root.qsReady = true;
    const cfg = JSON.parse(root.dataset.richtext);
    const t = cfg.i18n;
    const ta = $('textarea', root);
    const bar = document.createElement('div');
    bar.className = 'qs-richtext-bar';
    bar.setAttribute('role', 'toolbar');
    const ed = document.createElement('div');
    ed.className = 'qs-richtext-editor qs-input';
    ed.contentEditable = 'true';
    ed.setAttribute('role', 'textbox');
    ed.setAttribute('aria-multiline', 'true');
    if (ta.id) { ed.id = `${ta.id}-editor`; $(`label[for="${CSS.escape(ta.id)}"]`)?.setAttribute('for', ed.id); }
    ed.innerHTML = ta.value; // HTML ya limpio (HtmlSanitizer en el servidor)
    root.append(bar, ed);

    const sync = () => {
      ta.value = ed.innerHTML;
      ta.dispatchEvent(new Event('input', { bubbles: true }));
    };
    let saved = null;
    const saveRange = () => {
      const sel = window.getSelection();
      if (sel.rangeCount && ed.contains(sel.anchorNode)) saved = sel.getRangeAt(0).cloneRange();
    };
    const restoreRange = () => {
      ed.focus();
      if (!saved) return;
      const sel = window.getSelection();
      sel.removeAllRanges();
      sel.addRange(saved);
    };
    const exec = (cmd, arg = null) => {
      restoreRange();
      document.execCommand(cmd, false, arg);
      saveRange();
      sync();
    };

    const insertImages = async (files) => {
      for (const file of files) {
        if (!file.type.startsWith('image/')) continue;
        Toast.show(`${t.uploading} ${file.name}`, 'info', 2500);
        try {
          const data = await uploadFile(cfg.upload, file);
          exec('insertImage', `${cfg.media}${data.id}`);
        } catch (err) {
          Dialog.alert(err.message, { variant: 'danger' });
        }
      }
    };

    // Enlace: pequeño campo en la propia barra (sin el prompt() del navegador)
    const linkBox = document.createElement('input');
    linkBox.type = 'url';
    linkBox.className = 'qs-input qs-richtext-link';
    linkBox.placeholder = t.linkPrompt;
    linkBox.hidden = true;
    linkBox.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        const url = linkBox.value.trim();
        linkBox.hidden = true;
        if (/^(https?:\/\/|mailto:)/i.test(url)) exec('createLink', url);
        else restoreRange();
      } else if (e.key === 'Escape') {
        e.preventDefault();
        linkBox.hidden = true;
        restoreRange();
      }
    });

    const tools = [
      ['bold', 'bold', t.bold], ['italic', 'italic', t.italic], ['underline', 'underline', t.underline],
      ['insertUnorderedList', 'list', t.ul], ['insertOrderedList', 'list-ordered', t.ol],
      ['quote', 'quote', t.quote], ['link', 'link', t.link],
      ...(cfg.upload ? [['image', 'image', t.image]] : []),
      ['removeFormat', 'remove-formatting', t.clear],
    ];
    const picker = document.createElement('input');
    picker.type = 'file';
    picker.accept = 'image/*';
    picker.multiple = true;
    picker.hidden = true;
    picker.addEventListener('change', () => { insertImages([...picker.files]); picker.value = ''; });
    tools.forEach(([cmd, iconName, label]) => {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'qs-icon-btn';
      b.title = label;
      b.setAttribute('aria-label', label);
      b.dataset.cmd = cmd;
      b.append(icon(iconName));
      bar.append(b);
    });
    bar.append(linkBox, picker);
    bar.addEventListener('mousedown', (e) => { if (e.target.closest('button')) e.preventDefault(); }); // conserva la selección
    bar.addEventListener('click', (e) => {
      const b = e.target.closest('button[data-cmd]');
      if (!b) return;
      const cmd = b.dataset.cmd;
      if (cmd === 'link') { saveRange(); linkBox.value = ''; linkBox.hidden = false; linkBox.focus(); return; }
      if (cmd === 'image') { saveRange(); picker.click(); return; }
      if (cmd === 'quote') { exec('formatBlock', 'blockquote'); return; }
      exec(cmd);
    });

    ed.addEventListener('input', sync);
    ed.addEventListener('keyup', saveRange);
    ed.addEventListener('mouseup', saveRange);
    // Pegar: imágenes como archivos; el resto como texto sin formato (el servidor limpia igualmente)
    ed.addEventListener('paste', (e) => {
      const files = [...(e.clipboardData?.files || [])];
      e.preventDefault();
      if (files.length && cfg.upload) { saveRange(); insertImages(files); return; }
      document.execCommand('insertText', false, e.clipboardData?.getData('text/plain') || '');
    });
    ed.addEventListener('drop', (e) => {
      const files = [...(e.dataTransfer?.files || [])];
      if (!files.length) return;
      e.preventDefault();
      if (cfg.upload) { saveRange(); insertImages(files); }
    });
  }

  // ------------------------------------------------------------------ ayuda a redactar (campos con "assist")

  // Menú bajo el campo; la IA devuelve una propuesta que se ve antes de sustituir el texto
  function initAssist(root) {
    if (root.qsAssist) return;
    root.qsAssist = true;
    const cfg = JSON.parse(root.dataset.assist);
    const t = cfg.i18n;
    const ta = $('textarea', root);
    const isRich = !!$('[data-richtext]', root);
    const bar = document.createElement('div');
    bar.className = 'qs-assist-bar';
    const toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'qs-btn qs-btn-sm qs-assist-toggle';
    toggle.setAttribute('aria-haspopup', 'menu');
    toggle.setAttribute('aria-expanded', 'false');
    toggle.append(icon('sparkles'), document.createTextNode(` ${t.button}`));
    const menu = document.createElement('div');
    menu.className = 'qs-assist-menu';
    menu.setAttribute('role', 'menu');
    menu.hidden = true;
    Object.entries(cfg.actions).forEach(([action, label]) => {
      const b = document.createElement('button');
      b.type = 'button';
      b.setAttribute('role', 'menuitem');
      b.dataset.action = action;
      b.textContent = label;
      menu.append(b);
    });
    bar.append(toggle, menu);
    root.append(bar);

    const close = () => { menu.hidden = true; toggle.setAttribute('aria-expanded', 'false'); };
    toggle.addEventListener('click', () => {
      menu.hidden = !menu.hidden;
      toggle.setAttribute('aria-expanded', String(!menu.hidden));
      if (!menu.hidden) menu.querySelector('button')?.focus();
    });
    document.addEventListener('click', (e) => { if (!bar.contains(e.target)) close(); });
    menu.addEventListener('keydown', (e) => {
      const items = [...menu.querySelectorAll('button')];
      const i = items.indexOf(document.activeElement);
      if (e.key === 'Escape') { close(); toggle.focus(); }
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        items[(i + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length].focus();
      }
    });

    menu.addEventListener('click', async (e) => {
      const b = e.target.closest('button[data-action]');
      if (!b) return;
      close();
      const text = ta.value;
      const plain = isRich ? (new DOMParser().parseFromString(text, 'text/html').body.textContent || '') : text;
      if (!plain.trim()) { Toast.show(t.empty, 'warning'); return; }
      toggle.disabled = true;
      root.classList.add('is-busy');
      const label = toggle.lastChild.textContent;
      toggle.lastChild.textContent = ` ${t.working}`;
      try {
        const res = await request(cfg.url, { method: 'POST', data: { action: b.dataset.action, text } });
        const preview = document.createElement('div');
        preview.className = `qs-assist-preview${isRich ? ' qs-richtext-view' : ''}`;
        if (isRich) preview.innerHTML = res.text; // HTML ya limpio (HtmlSanitizer en el servidor)
        else preview.textContent = res.text;
        const ok = await Dialog.open({ title: t.title, content: preview, wide: true, okText: t.replace, variant: 'ai' });
        if (!ok) return;
        const ed = $('.qs-richtext-editor', root);
        if (ed) {
          ed.innerHTML = res.text;
          ed.dispatchEvent(new Event('input', { bubbles: true }));
        } else {
          ta.value = res.text;
          ta.dispatchEvent(new Event('input', { bubbles: true }));
        }
      } catch (err) {
        Dialog.alert(err.message, { variant: 'danger' });
      } finally {
        toggle.disabled = false;
        root.classList.remove('is-busy');
        toggle.lastChild.textContent = label;
      }
    });
  }

  // ------------------------------------------------------------------ avisos (campana)

  function initNotifications(root) {
    const cfg = JSON.parse(root.dataset.notifications);
    const t = cfg.i18n;
    const btn = $('.qs-notify-btn', root);
    const panel = $('.qs-notify-panel', root);
    const list = $('.qs-notify-list', root);
    const count = $('.qs-notify-count', root);
    const desktop = $('[data-notify-desktop]', root);
    const baseTitle = document.title;
    let last = 0;
    let first = true;
    let timer = null;
    let items = [];

    const setCount = (n) => {
      count.hidden = !n;
      count.textContent = n > 99 ? '99+' : String(n);
      document.title = n ? `(${n}) ${baseTitle}` : baseTitle;
    };
    const render = () => {
      list.replaceChildren();
      if (!items.length) {
        const empty = document.createElement('div');
        empty.className = 'qs-notify-empty';
        empty.textContent = t.empty;
        list.append(empty);
        return;
      }
      items.forEach((i) => {
        const a = document.createElement('a');
        a.href = i.url;
        a.className = `qs-notify-item${i.read ? '' : ' is-unread'}`;
        a.setAttribute('role', 'listitem');
        const av = document.createElement('span');
        av.className = 'qs-avatar';
        if (i.actor) av.textContent = i.actor; else av.append(icon(i.type === 'chat.meet' ? 'video' : 'bell'));
        const body = document.createElement('span');
        body.className = 'qs-notify-text';
        const title = document.createElement('strong');
        title.textContent = i.title;
        body.append(title);
        if (i.body) {
          const p = document.createElement('span');
          p.className = 'qs-notify-body';
          p.textContent = i.body;
          body.append(p);
        }
        const time = document.createElement('time');
        time.dateTime = i.at;
        time.textContent = timeAgo(i.at, t);
        body.append(time);
        a.append(av, body);
        list.append(a);
      });
    };
    const announce = (i) => {
      Toast.show(i.title, 'info', 6000);
      if (document.hidden && 'Notification' in window && Notification.permission === 'granted') {
        const n = new Notification(i.title, { body: i.body || '', tag: `qs-notification-${i.id}` });
        n.onclick = () => { window.focus(); window.location.href = i.url; };
      }
    };
    const schedule = () => {
      clearTimeout(timer);
      timer = setTimeout(poll, cfg.seconds * 1000 * (document.hidden ? 4 : 1));
    };
    async function poll() {
      try {
        const res = await request(`${cfg.poll}&after=${last}`);
        items = res.items;
        setCount(res.unread);
        render();
        if (!first) res.fresh.forEach(announce);
        last = res.last;
        first = false;
      } catch { /* sin conexión: se reintenta */ }
      schedule();
    }

    const open = (show) => {
      panel.hidden = !show;
      btn.setAttribute('aria-expanded', String(show));
      if (show) render(); // tiempos relativos al día
    };
    btn.addEventListener('click', (e) => { e.stopPropagation(); open(panel.hidden); });
    document.addEventListener('click', (e) => { if (!panel.hidden && !root.contains(e.target)) open(false); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !panel.hidden) { open(false); btn.focus(); } });
    $('[data-notify-readall]', root).addEventListener('click', async () => {
      try {
        const res = await request(cfg.read, { method: 'POST', data: {} });
        items.forEach((i) => { i.read = true; });
        setCount(res.unread);
        render();
      } catch (err) { Dialog.alert(err.message, { variant: 'danger' }); }
    });
    // Avisos del escritorio: el navegador solo deja pedir permiso tras un clic
    if ('Notification' in window && Notification.permission === 'default') {
      desktop.hidden = false;
      desktop.addEventListener('click', async () => {
        const p = await Notification.requestPermission();
        desktop.hidden = true;
        Toast.show(p === 'granted' ? t.desktopOn : t.desktopDenied, p === 'granted' ? 'success' : 'warning');
      });
    }
    document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });
    document.addEventListener('qs:notifications', poll); // otro componente pide actualizar (p. ej. el chat)
    poll();
  }

  // ------------------------------------------------------------------ conversación del registro (chat)

  function initChat(root) {
    const cfg = JSON.parse(root.dataset.chat);
    const t = cfg.i18n;
    const log = $('.qs-chat-log', root);
    const form = $('[data-chat-form]', root);
    const input = form.elements.namedItem('body');
    const filesList = $('.qs-chat-files', root);
    const fileInput = $('[data-chat-file]', root);
    const muteBtn = $('[data-chat-mute]', root);
    const meetBtn = $('[data-chat-meet]', root);
    const mentionBox = $('.qs-chat-mentions', root);
    const mentionList = $('ul', mentionBox);
    const messages = new Map();
    let last = 0;
    let reads = [];
    let lastRead = null;          // último leído antes de abrir: marca de "mensajes nuevos"
    let older = false;
    let muted = false;
    let people = null;
    let pending = [];             // adjuntos subidos aún sin enviar: {id, name, li}
    const mentioned = new Map();  // nombre → id de usuario
    let timer = null;
    let sending = false;

    const nearBottom = () => log.scrollHeight - log.scrollTop - log.clientHeight < 60;
    const inView = () => !document.hidden && root.getBoundingClientRect().top < window.innerHeight && root.getBoundingClientRect().bottom > 0;
    const names = () => [cfg.me.name, ...(people || []).map((p) => p.name), ...[...messages.values()].map((m) => m.user?.name)];
    const dayKey = (d) => `${d.getFullYear()}-${d.getMonth()}-${d.getDate()}`;
    const dayLabel = (d) => {
      const today = new Date();
      const yesterday = new Date(Date.now() - 86400000);
      if (dayKey(d) === dayKey(today)) return t.today;
      if (dayKey(d) === dayKey(yesterday)) return t.yesterday;
      return d.toLocaleDateString(docLang(), { weekday: 'long', day: 'numeric', month: 'long', year: d.getFullYear() === today.getFullYear() ? undefined : 'numeric' });
    };

    function renderMedia(list) {
      const box = document.createElement('div');
      box.className = 'qs-chat-media';
      list.forEach((f) => {
        const a = document.createElement('a');
        a.href = f.url;
        a.target = '_blank';
        a.rel = 'noopener';
        if (f.isImage) {
          a.className = 'qs-chat-image';
          const img = document.createElement('img');
          img.src = f.thumb || f.url;
          img.alt = f.name;
          img.loading = 'lazy';
          a.append(img);
        } else {
          a.className = 'qs-chat-file';
          a.href = f.download;
          a.append(icon('paperclip'), ` ${f.name} `);
          const size = document.createElement('small');
          size.textContent = f.sizeText;
          a.append(size);
        }
        box.append(a);
      });
      return box;
    }

    function renderMessage(m) {
      const at = new Date(m.at);
      if (m.kind === 'system') {
        const div = document.createElement('div');
        div.className = 'qs-chat-system';
        div.textContent = `${m.body || ''} · ${m.user ? `${m.user.name} · ` : ''}${timeOf(at)}`;
        return div;
      }
      const row = document.createElement('div');
      row.className = `qs-chat-msg${m.mine ? ' is-mine' : ''}`;
      row.dataset.id = m.id;
      if (!m.mine) {
        const av = document.createElement('span');
        av.className = 'qs-avatar';
        av.textContent = m.user?.initial || '?';
        av.title = m.user?.name || '';
        row.append(av);
      }
      const bubble = document.createElement('div');
      bubble.className = 'qs-chat-bubble';
      const head = document.createElement('div');
      head.className = 'qs-chat-meta';
      const who = document.createElement('strong');
      who.textContent = m.mine ? cfg.me.name : (m.user?.name || '');
      const when = document.createElement('time');
      when.dateTime = m.at;
      when.textContent = timeOf(at);
      when.title = at.toLocaleString(docLang());
      head.append(who, when);
      if (m.canDelete) {
        const del = document.createElement('button');
        del.type = 'button';
        del.className = 'qs-icon-btn qs-chat-delete';
        del.dataset.delete = m.id;
        del.title = t.delete;
        del.setAttribute('aria-label', t.delete);
        del.append(icon('trash-2'));
        head.append(del);
      }
      bubble.append(head);
      if (m.deleted) {
        const p = document.createElement('em');
        p.className = 'qs-muted';
        p.textContent = t.deleted;
        bubble.append(p);
      } else if (m.kind === 'meet' && m.meta?.uri) {
        const card = document.createElement('div');
        card.className = 'qs-chat-meet';
        const label = document.createElement('span');
        label.append(icon('video'), ` ${t.meetStarted}`);
        const join = document.createElement('a');
        join.className = 'qs-btn qs-btn-primary qs-btn-sm';
        join.href = m.meta.uri;
        join.target = '_blank';
        join.rel = 'noopener noreferrer';
        join.textContent = t.meetJoin;
        const code = document.createElement('small');
        code.textContent = m.meta.code || m.meta.uri;
        card.append(label, join, code);
        bubble.append(card);
      } else {
        if (m.body) {
          const p = document.createElement('div');
          p.className = 'qs-chat-text';
          p.append(richText(m.body, names()));
          bubble.append(p);
        }
        if (m.media?.length) bubble.append(renderMedia(m.media));
      }
      row.append(bubble);
      return row;
    }

    function render({ keepBottom = nearBottom(), anchor = null } = {}) {
      const before = anchor ? log.scrollHeight - log.scrollTop : 0;
      const frag = document.createDocumentFragment();
      if (older) {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'qs-linkbtn qs-chat-older';
        b.dataset.older = '';
        b.textContent = t.older;
        frag.append(b);
      }
      const list = [...messages.values()].sort((a, b) => a.id - b.id);
      if (!list.length) {
        const empty = document.createElement('div');
        empty.className = 'qs-chat-empty';
        empty.textContent = t.empty;
        frag.append(empty);
      }
      // Hasta dónde ha leído cada uno: "visto" bajo mi último mensaje
      const mineLast = [...list].reverse().find((m) => m.mine && m.kind !== 'system');
      let day = '';
      let marked = false;
      list.forEach((m) => {
        const d = new Date(m.at);
        if (dayKey(d) !== day) {
          day = dayKey(d);
          const sep = document.createElement('div');
          sep.className = 'qs-chat-day';
          sep.textContent = dayLabel(d);
          frag.append(sep);
        }
        if (!marked && lastRead !== null && m.id > lastRead && !m.mine && m.kind !== 'system') {
          marked = true;
          const sep = document.createElement('div');
          sep.className = 'qs-chat-new';
          sep.textContent = t.newMessages;
          frag.append(sep);
        }
        frag.append(renderMessage(m));
        if (m === mineLast) {
          const seen = reads.filter((r) => r.lastRead >= m.id).map((r) => r.name);
          if (seen.length) {
            const s = document.createElement('div');
            s.className = 'qs-chat-seen';
            s.append(icon('check-check'), ` ${fmt(t.seen, { names: seen.join(', ') })}`);
            frag.append(s);
          }
        }
      });
      log.replaceChildren(frag);
      if (anchor) log.scrollTop = log.scrollHeight - before;
      else if (keepBottom) log.scrollTop = log.scrollHeight;
    }

    const merge = (list) => list.forEach((m) => { messages.set(m.id, m); if (m.id > last) last = m.id; });

    const setMuted = (on) => {
      muted = on;
      muteBtn.setAttribute('aria-pressed', String(on));
      muteBtn.title = on ? t.unmute : t.mute;
      muteBtn.setAttribute('aria-label', muteBtn.title);
      muteBtn.replaceChildren(icon(on ? 'bell-off' : 'bell'));
    };

    const schedule = () => {
      clearTimeout(timer);
      timer = setTimeout(poll, cfg.seconds * 1000 * (document.hidden ? 6 : 1));
    };
    async function poll() {
      try {
        const read = inView() ? 1 : 0;
        const res = await request(`${cfg.poll}&after=${last}&read=${read}`);
        const initial = lastRead === null;
        const keep = initial || nearBottom();
        if (initial) {
          lastRead = res.lastRead;
          older = !!res.older;
          setMuted(!!res.muted);
        }
        const changed = res.messages.length || JSON.stringify(res.reads) !== JSON.stringify(reads);
        reads = res.reads;
        merge(res.messages);
        if (changed || initial) render({ keepBottom: keep });
        if (read && res.messages.length) document.dispatchEvent(new Event('qs:notifications'));
      } catch { /* sin conexión: se reintenta */ }
      schedule();
    }

    async function loadOlder() {
      const first = Math.min(...messages.keys());
      try {
        const res = await request(`${cfg.poll}&before=${first}`);
        older = res.messages.length > 0 && res.messages.length >= 50;
        merge(res.messages);
        render({ anchor: true });
      } catch (err) { Dialog.alert(err.message, { variant: 'danger' }); }
    }

    // ---- adjuntos
    const renderPending = () => {
      filesList.hidden = !pending.length;
    };
    async function attach(files) {
      if (!cfg.upload) return;
      for (const file of files) {
        const li = document.createElement('li');
        li.className = 'qs-chat-pending is-uploading';
        const name = document.createElement('span');
        name.textContent = file.name;
        const bar = document.createElement('span');
        bar.className = 'qs-progress';
        const fill = document.createElement('span');
        bar.append(fill);
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'qs-icon-btn';
        remove.title = t.removedAttachment;
        remove.setAttribute('aria-label', t.removedAttachment);
        remove.append(icon('x'));
        li.append(icon('paperclip'), name, bar, remove);
        filesList.append(li);
        const item = { id: null, li };
        pending.push(item);
        renderPending();
        remove.addEventListener('click', () => { pending = pending.filter((p) => p !== item); li.remove(); renderPending(); });
        try {
          const data = await uploadFile(cfg.upload, file, (p) => { fill.style.width = `${Math.round(p * 100)}%`; });
          item.id = data.id;
          li.classList.remove('is-uploading');
          bar.remove();
        } catch (err) {
          pending = pending.filter((p) => p !== item);
          li.remove();
          renderPending();
          Dialog.alert(`${file.name}: ${err.message}`, { variant: 'danger' });
        }
      }
    }

    // ---- envío
    async function send() {
      const body = input.value.trim();
      if (sending || pending.some((p) => p.id === null)) return;
      const media = pending.map((p) => p.id);
      if (!body && !media.length) { input.focus(); return; }
      const mentions = [...mentioned].filter(([name]) => body.includes(`@${name}`)).map(([, id]) => id);
      sending = true;
      form.classList.add('is-busy');
      try {
        const res = await request(cfg.post, { method: 'POST', data: { body, media: media.join(','), mentions: mentions.join(',') } });
        merge([res.message]);
        input.value = '';
        input.style.height = '';
        pending.forEach((p) => p.li.remove());
        pending = [];
        renderPending();
        render({ keepBottom: true });
      } catch (err) {
        Dialog.alert(err.message || t.error, { variant: 'danger' });
      } finally {
        sending = false;
        form.classList.remove('is-busy');
        input.focus();
      }
    }

    // ---- menciones (@nombre)
    let options = [];
    let active = 0;
    const token = () => {
      const before = input.value.slice(0, input.selectionStart);
      const m = before.match(/(^|\s)@([^\s@]{0,30})$/u);
      return m ? { start: before.length - m[2].length - 1, q: m[2] } : null;
    };
    const closeMentions = () => { mentionBox.hidden = true; options = []; };
    const paintMentions = () => {
      mentionList.replaceChildren();
      options.forEach((p, i) => {
        const li = document.createElement('li');
        li.dataset.i = String(i);
        li.className = i === active ? 'is-active' : '';
        li.setAttribute('role', 'option');
        li.textContent = p.name;
        if (p.username !== p.name) {
          const small = document.createElement('small');
          small.className = 'qs-muted';
          small.textContent = ` ${p.username}`;
          li.append(small);
        }
        mentionList.append(li);
      });
    };
    async function suggest() {
      const tok = token();
      if (!tok) { closeMentions(); return; }
      if (people === null) {
        people = [];
        try { people = (await request(cfg.people)).people; } catch { /* sin sugerencias */ }
      }
      const q = fold(tok.q);
      options = people.filter((p) => fold(p.name).includes(q) || fold(p.username).includes(q)).slice(0, 8);
      active = 0;
      mentionBox.hidden = !options.length;
      paintMentions();
    }
    const pick = (i) => {
      const p = options[i];
      const tok = token();
      if (!p || !tok) return;
      const text = `@${p.name} `;
      input.setRangeText(text, tok.start, input.selectionStart, 'end');
      mentioned.set(p.name, p.id);
      closeMentions();
      input.focus();
    };
    mentionList.addEventListener('mousedown', (e) => {
      const li = e.target.closest('li[data-i]');
      if (li) { e.preventDefault(); pick(Number(li.dataset.i)); }
    });

    input.addEventListener('input', () => {
      input.style.height = 'auto';
      input.style.height = `${Math.min(input.scrollHeight, 220)}px`;
      suggest();
    });
    input.addEventListener('keydown', (e) => {
      if (!mentionBox.hidden && options.length) {
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
          e.preventDefault();
          active = (active + (e.key === 'ArrowDown' ? 1 : -1) + options.length) % options.length;
          paintMentions();
          return;
        }
        if (e.key === 'Enter' || e.key === 'Tab') { e.preventDefault(); pick(active); return; }
        if (e.key === 'Escape') { e.preventDefault(); closeMentions(); return; }
      }
      // Enter envía; Mayús+Enter, salto de línea
      if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); send(); }
    });
    input.addEventListener('blur', () => setTimeout(closeMentions, 150));
    input.addEventListener('paste', (e) => {
      const files = [...(e.clipboardData?.files || [])];
      if (files.length && cfg.upload) { e.preventDefault(); attach(files); }
    });
    form.addEventListener('submit', (e) => { e.preventDefault(); send(); });
    fileInput?.addEventListener('change', () => { attach([...fileInput.files]); fileInput.value = ''; });
    root.addEventListener('dragover', (e) => { if (cfg.upload && e.dataTransfer?.types.includes('Files')) { e.preventDefault(); root.classList.add('is-dragover'); } });
    root.addEventListener('dragleave', (e) => { if (!root.contains(e.relatedTarget)) root.classList.remove('is-dragover'); });
    root.addEventListener('drop', (e) => {
      root.classList.remove('is-dragover');
      if (!cfg.upload || !e.dataTransfer?.files.length) return;
      e.preventDefault();
      attach([...e.dataTransfer.files]);
    });

    log.addEventListener('click', async (e) => {
      if (e.target.closest('[data-older]')) { loadOlder(); return; }
      const del = e.target.closest('[data-delete]');
      if (!del) return;
      if (!(await Dialog.confirm(t.confirmDelete, { variant: 'danger', okText: t.delete }))) return;
      try {
        const res = await request(cfg.delete, { method: 'POST', data: { message: del.dataset.delete } });
        const m = messages.get(Number(del.dataset.delete));
        if (res.ok && m) Object.assign(m, { deleted: true, body: null, media: [], meta: null, canDelete: false });
        render();
      } catch (err) { Dialog.alert(err.message, { variant: 'danger' }); }
    });

    muteBtn.addEventListener('click', async () => {
      try {
        const res = await request(cfg.mute, { method: 'POST', data: { on: muted ? 0 : 1 } });
        setMuted(res.muted);
        Toast.show(res.muted ? t.mutedOn : t.mutedOff, 'info');
      } catch (err) { Dialog.alert(err.message, { variant: 'danger' }); }
    });

    meetBtn?.addEventListener('click', async () => {
      if (!(await Dialog.confirm(t.meetConfirm, { okText: t.meet }))) return;
      meetBtn.disabled = true;
      try {
        const res = await request(cfg.meet, { method: 'POST', data: {} });
        merge([res.message]);
        render({ keepBottom: true });
        if (res.message.meta?.uri) window.open(res.message.meta.uri, '_blank', 'noopener');
      } catch (err) {
        Dialog.alert(err.message || t.error, { variant: 'danger' });
      } finally {
        meetBtn.disabled = false;
      }
    });

    document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });
    if (window.location.hash === '#chat') {
      root.scrollIntoView({ block: 'start' });
      input.focus({ preventScroll: true });
    }
    poll();
  }

  // ------------------------------------------------------------------ ver la contraseña escrita

  // Botón con un ojo dentro de los campos de contraseña (al enviar el formulario vuelve a ocultarse)
  // y aviso de Bloq Mayús
  function initPasswordToggle(input) {
    if (input.qsPassword || input.readOnly || input.disabled) return;
    input.qsPassword = true;
    const wrap = document.createElement('span');
    wrap.className = 'qs-password';
    input.replaceWith(wrap);
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'qs-password-toggle';
    wrap.append(input, btn);
    const show = (visible) => {
      input.type = visible ? 'text' : 'password';
      const label = visible ? T.hidePassword : T.showPassword;
      btn.title = label;
      btn.setAttribute('aria-label', label);
      btn.setAttribute('aria-pressed', String(visible));
      btn.replaceChildren(icon(visible ? 'eye-off' : 'eye'));
    };
    show(false);
    btn.addEventListener('click', () => {
      const end = input.value.length;
      show(input.type === 'password');
      input.focus();
      input.setSelectionRange(end, end);
    });
    input.form?.addEventListener('submit', () => show(false));

    // Aviso de Bloq Mayús activado (causa típica de «no me deja entrar»)
    const caps = document.createElement('span');
    caps.className = 'qs-capslock';
    caps.setAttribute('role', 'status');
    caps.hidden = true;
    caps.append(icon('triangle-alert'), document.createTextNode(` ${T.capsLock}`));
    wrap.after(caps);
    const checkCaps = (e) => { if (e.getModifierState) caps.hidden = !e.getModifierState('CapsLock'); };
    input.addEventListener('keydown', checkCaps);
    input.addEventListener('keyup', checkCaps);
    input.addEventListener('blur', () => { caps.hidden = true; });
  }

  // Inicializa los componentes de un fragmento (también filas nuevas de un repeater)
  function initWidgets(scope) {
    const find = (sel) => [...(scope.matches?.(sel) ? [scope] : []), ...$$(sel, scope)];
    find('input[type="password"]').forEach(initPasswordToggle);
    find('[data-repeater]').forEach(initRepeater);
    find('[data-media]').forEach(initMedia);
    find('select[data-combobox]').forEach(initCombobox);
    find('[data-tags]').forEach(initTags);
    find('[data-richtext]').forEach(initRichText);
    find('[data-assist]').forEach(initAssist);
  }

  // ------------------------------------------------------------------ calendario: semana y día

  // Arrastrar un evento cambia su hora (y su día si se suelta en otra columna); estirar el borde de abajo, su
  // duración; pulsar una franja vacía abre el alta con esa hora. Todo se ajusta a las franjas ("slot").
  // El servidor (CalendarPage::actionMove) vuelve a comprobar permisos, scope, validación y hooks.
  function initCalendarGrid(grid) {
    let cfg;
    try { cfg = JSON.parse(grid.dataset.calGrid); } catch { return; }
    const cols = $$('.qs-cal-col', grid);
    const pad = (n) => String(n).padStart(2, '0');
    const minutesOf = (value) => { // "2026-11-03 09:30" → minutos desde el inicio visible
      const m = /(\d{2}):(\d{2})/.exec(value.slice(11));
      return m ? (+m[1] - cfg.startHour) * 60 + +m[2] : 0;
    };
    const stamp = (date, minutes) => {
      const total = cfg.startHour * 60 + minutes;
      return `${date}T${pad(Math.floor(total / 60))}:${pad(total % 60)}`;
    };
    const perMinute = () => cols[0].getBoundingClientRect().height / cfg.span;
    const snap = (minutes) => Math.round(minutes / cfg.slot) * cfg.slot;
    const clamp = (v, lo, hi) => Math.max(lo, Math.min(hi, v));
    const colAt = (x) => cols.find((c) => { const r = c.getBoundingClientRect(); return x >= r.left && x < r.right; });

    // Alta en una franja vacía
    if (cfg.newUrl) {
      cols.forEach((col) => col.addEventListener('click', (e) => {
        if (e.target.closest('.qs-cal-tevent')) return;
        const start = clamp(Math.floor((e.clientY - col.getBoundingClientRect().top) / perMinute() / cfg.slot) * cfg.slot, 0, cfg.span - cfg.slot);
        const v = new URLSearchParams();
        v.set(`v[${cfg.dateField}]`, stamp(col.dataset.date, start));
        if (cfg.endField) v.set(`v[${cfg.endField}]`, stamp(col.dataset.date, Math.min(start + cfg.duration, cfg.span)));
        window.location.href = `${cfg.newUrl}&${v.toString()}`;
      }));
    }
    if (!cfg.moveUrl) return;

    let drag = null;
    let suppressClick = false;

    $$('.qs-cal-tevent:not(.is-clipped):not(.is-fixed)', grid).forEach((ev) => {
      // Es un enlace: sin esto el navegador empieza su propio arrastre (y cancela los eventos de puntero)
      ev.draggable = false;
      ev.addEventListener('dragstart', (e) => e.preventDefault());
      ev.addEventListener('pointerdown', (e) => {
        if (e.button !== 0) return;
        e.preventDefault(); // ni arrastre nativo ni selección de texto; el clic sigue llegando
        const top = minutesOf(ev.dataset.start);
        const end = ev.dataset.end && ev.dataset.end.slice(0, 10) === ev.dataset.start.slice(0, 10) ? minutesOf(ev.dataset.end) : top + cfg.duration;
        drag = {
          ev, mode: e.target.closest('.qs-cal-resize') ? 'resize' : 'move',
          x: e.clientX, y: e.clientY, moved: false,
          top, dur: Math.max(cfg.slot, end - top), col: ev.closest('.qs-cal-col'),
          style: ev.getAttribute('style'),
          timeText: $('.qs-cal-time', ev)?.textContent ?? '',
        };
        drag.newTop = drag.top;
        drag.newDur = drag.dur;
        drag.newCol = drag.col;
        ev.setPointerCapture(e.pointerId);
      });

      ev.addEventListener('pointermove', (e) => {
        if (!drag || drag.ev !== ev) return;
        if (!drag.moved && Math.hypot(e.clientX - drag.x, e.clientY - drag.y) < 5) return;
        if (!drag.moved) {
          drag.moved = true;
          ev.classList.add('is-dragging');
          ev.style.left = '0';
          ev.style.width = '100%';
        }
        const delta = snap((e.clientY - drag.y) / perMinute());
        if (drag.mode === 'move') {
          drag.newTop = clamp(drag.top + delta, 0, cfg.span - drag.dur);
          const col = colAt(e.clientX);
          if (col && col !== drag.newCol) { col.append(ev); drag.newCol = col; }
        } else {
          drag.newDur = clamp(drag.dur + delta, cfg.slot, cfg.span - drag.top);
        }
        ev.style.top = `${(drag.newTop / cfg.span) * 100}%`;
        ev.style.height = `${(drag.newDur / cfg.span) * 100}%`;
        const time = $('.qs-cal-time', ev);
        if (time) time.textContent = `${stamp('', drag.newTop).slice(1)}–${stamp('', drag.newTop + drag.newDur).slice(1)}`;
      });

      const finish = async () => {
        if (!drag || drag.ev !== ev) return;
        const d = drag;
        drag = null;
        if (!d.moved) return; // un clic: abre la ficha
        suppressClick = true;
        ev.classList.remove('is-dragging');
        if (d.newTop === d.top && d.newDur === d.dur && d.newCol === d.col) {
          ev.setAttribute('style', d.style);
          return;
        }
        ev.classList.add('is-saving');
        const date = d.newCol.dataset.date;
        const data = { id: ev.dataset.id, start: stamp(date, d.newTop) };
        if (cfg.endField) data.end = stamp(date, d.newTop + d.newDur);
        try {
          await request(cfg.moveUrl, { method: 'POST', data });
          window.location.reload(); // la rejilla vuelve a calcular las columnas de los solapados
        } catch (err) {
          // Rechazado (permisos, validación, hooks…): vuelve a su sitio y se explica por qué
          d.col.append(ev);
          ev.setAttribute('style', d.style);
          ev.classList.remove('is-saving');
          const time = $('.qs-cal-time', ev);
          if (time) time.textContent = d.timeText;
          Toast.show(fmt(cfg.error, { error: err.message }), 'danger', 6000);
        }
      };
      ev.addEventListener('pointerup', finish);
      ev.addEventListener('pointercancel', () => {
        if (drag?.ev === ev) { drag.col.append(ev); ev.setAttribute('style', drag.style); ev.classList.remove('is-dragging'); drag = null; }
      });
      ev.addEventListener('click', (e) => {
        if (suppressClick) { e.preventDefault(); suppressClick = false; }
      });
    });
  }

  // ------------------------------------------------------------------ alta rápida en una ventana (quickCreate)

  // size "small": ventana baja para formularios cortos (filtros de un informe)
  function openModal({ url, label, size = '' }) {
    const dlg = document.createElement('dialog');
    dlg.className = size === 'small' ? 'qs-modal qs-modal-sm' : 'qs-modal';
    dlg.setAttribute('aria-label', label);
    const head = document.createElement('div');
    head.className = 'qs-modal-head';
    const title = document.createElement('strong');
    title.textContent = label;
    const closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.className = 'qs-icon-btn';
    closeBtn.title = T.close || 'Close';
    closeBtn.setAttribute('aria-label', closeBtn.title);
    closeBtn.append(icon('x'));
    head.append(title, closeBtn);
    const frame = document.createElement('iframe');
    frame.src = url;
    frame.title = label;
    dlg.append(head, frame);
    document.body.append(dlg);
    dlg.showModal();
    frame.addEventListener('load', () => frame.contentWindow?.focus());

    // El formulario de dentro decide si se puede cerrar (cambios sin guardar)
    const askClose = () => frame.contentWindow?.postMessage({ type: 'qs:modal-escape' }, window.location.origin);
    const close = () => {
      window.removeEventListener('message', onMessage);
      dlg.close();
      dlg.remove();
    };
    const onMessage = (e) => {
      if (e.origin !== window.location.origin || e.source !== frame.contentWindow) return;
      if (e.data?.type === 'qs:modal-close') close();
      if (e.data?.type === 'qs:modal-saved') {
        close();
        Toast.show(e.data.message, 'success');
        $$('.qs-datatable').forEach((t) => t.qsTable?.load());
        document.dispatchEvent(new CustomEvent('qs:record-saved', { detail: e.data }));
      }
    };
    window.addEventListener('message', onMessage);
    closeBtn.addEventListener('click', askClose);
    dlg.addEventListener('cancel', (e) => { e.preventDefault(); askClose(); });
  }
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-quick-create]');
    if (btn) openModal(JSON.parse(btn.dataset.quickCreate));
  });
  // ------------------------------------------------------------------ menús de botón (Informes del listado)

  $$('[data-menu]').forEach((menu) => {
    const toggle = $('[data-menu-toggle]', menu);
    const list = $('[role="menu"]', menu);
    const items = () => $$('[role="menuitem"]', list);
    const open = (yes) => {
      list.hidden = !yes;
      toggle.setAttribute('aria-expanded', String(yes));
      if (yes) items()[0]?.focus();
    };
    toggle.addEventListener('click', () => open(list.hidden));
    document.addEventListener('click', (e) => { if (!menu.contains(e.target)) open(false); });
    list.addEventListener('click', (e) => { if (e.target.closest('[role="menuitem"]')) open(false); });
    list.addEventListener('keydown', (e) => {
      const all = items();
      const i = all.indexOf(document.activeElement);
      if (e.key === 'Escape') { open(false); toggle.focus(); }
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        all[(i + (e.key === 'ArrowDown' ? 1 : -1) + all.length) % all.length]?.focus();
      }
    });
  });

  // Filtros de un informe PDF dentro de la ventana del listado: el PDF se abre en otra pestaña y la ventana se cierra
  $$('form[data-report-form].is-modal').forEach((form) => {
    const close = () => window.parent.postMessage({ type: 'qs:modal-close' }, window.location.origin);
    form.addEventListener('submit', () => setTimeout(close, 50));
    form.addEventListener('click', (e) => { if (e.target.closest('[data-modal-close]')) close(); });
    window.addEventListener('message', (e) => {
      if (e.origin === window.location.origin && e.data?.type === 'qs:modal-escape') close();
    });
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && !e.defaultPrevented && !Dialog.isOpen() && !e.target.closest?.('select')) close();
    });
    $('.qs-input:not([type="hidden"])', form)?.focus();
  });

  // Dentro de la ventana: el registro se ha guardado, se avisa a la página que la abrió
  $$('[data-modal-done]').forEach((el) => {
    window.parent.postMessage({ type: 'qs:modal-saved', ...JSON.parse(el.dataset.modalDone) }, window.location.origin);
  });

  /*
   * Envíos repetidos: con el servidor lento, el usuario pulsa «Desa» varias veces y se guardaría (o crearía)
   * dos veces. Un formulario que ya se está enviando ignora los siguientes envíos y su botón muestra que está
   * trabajando. Va después de los demás manejadores de submit: solo cuenta lo que de verdad se envía (no lo
   * que cancela la validación, una confirmación o un formulario que se gestiona por JS).
   * Los que no cambian de página (target="_blank", descargas) se liberan solos; data-no-lock lo desactiva.
   */
  document.addEventListener('submit', (e) => {
    const form = e.target;
    if (e.defaultPrevented || !(form instanceof HTMLFormElement) || form.matches('[data-no-lock]')) return;
    if (form.dataset.submitting) {
      e.preventDefault();
      return;
    }
    form.dataset.submitting = '1';
    const btn = e.submitter instanceof HTMLButtonElement ? e.submitter : $('button[type="submit"], button:not([type])', form);
    // Después de este evento: un botón desactivado ya no enviaría su name/value
    setTimeout(() => btn && setBusy(btn, true));
    const release = () => {
      delete form.dataset.submitting;
      if (btn) setBusy(btn, false);
    };
    setTimeout(release, form.target === '_blank' ? 1500 : 20000);
  });
  // Volver con «Atrás» a una página guardada en caché: los botones vuelven a estar disponibles
  window.addEventListener('pageshow', (e) => {
    if (!e.persisted) return;
    $$('form[data-submitting]').forEach((f) => delete f.dataset.submitting);
    $$('.qs-btn.is-loading').forEach((b) => setBusy(b, false));
  });

  // ------------------------------------------------------------------ arranque

  $$('.qs-datatable[data-config]').forEach((el) => new DataTable(el));
  initWidgets(document.body);
  $$('form[data-dirty-check]').forEach(initForm);
  $$('form').forEach((form) => { if ($('[data-showon]', form)) initShowOn(form); });
  $$('form.qs-permissions').forEach(initPermissions);
  $$('[data-notifications]').forEach(initNotifications);
  $$('[data-chat]').forEach(initChat);
  $$('[data-cal-grid]').forEach(initCalendarGrid);

  // Verificación en dos pasos: cuenta atrás hasta poder reenviar el código
  $$('[data-countdown]').forEach((btn) => {
    let left = parseInt(btn.dataset.countdown, 10) || 0;
    const label = btn.textContent.trim();
    const tick = () => {
      if (left > 0) {
        btn.disabled = true;
        btn.textContent = fmt(btn.dataset.countdownText, { s: left });
        left -= 1;
        setTimeout(tick, 1000);
      } else {
        btn.disabled = false;
        btn.textContent = label;
      }
    };
    tick();
  });

  // ------------------------------------------------------------------ barra lateral plegable

  // El estado va en una cookie para que el servidor pinte la página ya plegada (sin parpadeo)
  const toggleSidebar = () => {
    const collapsed = document.body.classList.toggle('is-sidebar-collapsed');
    document.cookie = `qs_sidebar=${collapsed ? 'collapsed' : 'expanded'}; path=/; max-age=31536000; SameSite=Lax`;
    $$('[data-sidebar-toggle]').forEach((btn) => btn.setAttribute('aria-expanded', String(!collapsed)));
  };
  $$('[data-sidebar-toggle]').forEach((btn) => btn.addEventListener('click', toggleSidebar));

  // Grupos del menú plegables; los plegados van en la cookie qs_nav para pintarlos ya plegados
  $$('[data-nav-group]').forEach((section) => {
    const btn = $('.qs-nav-group', section);
    btn.addEventListener('click', () => {
      const closed = section.classList.toggle('is-closed');
      btn.setAttribute('aria-expanded', String(!closed));
      const names = $$('[data-nav-group].is-closed').map((s) => s.dataset.navGroup);
      document.cookie = `qs_nav=${encodeURIComponent(names.join(','))}; path=/; max-age=31536000; SameSite=Lax`;
    });
  });

  document.addEventListener('keydown', (e) => {
    if (Dialog.isOpen()) return;
    const typing = !!e.target.closest?.('input, textarea, select, [contenteditable]');
    // Ctrl+B: plegar/desplegar la barra lateral
    if ((e.ctrlKey || e.metaKey) && !e.altKey && e.key.toLowerCase() === 'b' && $('[data-sidebar-toggle]')) {
      e.preventDefault();
      toggleSidebar();
    // "/": ir al buscador del listado
    } else if (e.key === '/' && !typing && !e.ctrlKey && !e.metaKey && !e.altKey) {
      const search = $('[data-search]');
      if (search) { e.preventDefault(); search.focus(); search.select(); }
    }
  });

  // Cambio de idioma en el login
  $$('select[data-autosubmit-lang]').forEach((sel) => sel.addEventListener('change', () => {
    window.location.href = `index.php?r=login&lang=${encodeURIComponent(sel.value)}`;
  }));

  // Los avisos de éxito/información del servidor se muestran como toasts
  $$('.qs-flashes .qs-flash-success, .qs-flashes .qs-flash-info').forEach((el) => {
    Toast.show(el.textContent.trim(), el.classList.contains('qs-flash-success') ? 'success' : 'info');
    el.remove();
  });

  // Alt+N / Insert: nuevo registro desde el listado
  document.addEventListener('keydown', (e) => {
    if (Dialog.isOpen()) return;
    const isNew = (e.altKey && e.key.toLowerCase() === 'n') || (e.key === 'Insert' && !e.target.closest?.('input, textarea, select'));
    if (!isNew) return;
    const link = $('[data-shortcut="new"]');
    if (link) { e.preventDefault(); window.location.href = link.href; }
  });
})();
