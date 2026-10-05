/* Personal Gallery — shared helpers (no libraries). */
(function () {
  'use strict';
  const cfg = JSON.parse(document.getElementById('pg-config').textContent);
  const fa = cfg.locale === 'fa';
  const PG = (window.PG = { cfg, fa });

  // ---------------------------------------------------------------- text
  PG.t = (key, params) => {
    let s = (cfg.t && cfg.t[key]) || key;
    if (params) for (const k in params) s = s.split(':' + k).join(params[k]);
    return s;
  };
  PG.esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  PG.icon = (name, cls) => `<svg class="ic ${cls || ''}" aria-hidden="true"><use href="${cfg.icons}#i-${name}"/></svg>`;
  const nf = new Intl.NumberFormat(fa ? 'fa-IR' : 'en-US');
  PG.num = (n) => nf.format(n);
  PG.size = (b) => {
    if (b == null) return '';
    const u = fa ? ['بایت', 'ک.ب', 'م.ب', 'گ.ب', 'ت.ب'] : ['B', 'KB', 'MB', 'GB', 'TB'];
    let i = 0;
    while (b >= 1024 && i < u.length - 1) { b /= 1024; i++; }
    return new Intl.NumberFormat(fa ? 'fa-IR' : 'en-US', { maximumFractionDigits: i ? 1 : 0 }).format(b) + ' ' + u[i];
  };
  PG.duration = (s) => {
    if (!s) return '';
    s = Math.round(s);
    const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), x = s % 60;
    const p = (v) => String(v).padStart(2, '0');
    return PG.digits((h ? h + ':' + p(m) : m) + ':' + p(x));
  };
  // Text with any Persian/Arabic letter is shown right-to-left (so "…" cuts at the correct end).
  PG.textDir = (s) => (/[\u0590-\u08FF\uFB1D-\uFDFF\uFE70-\uFEFF]/.test(String(s || '')) ? 'rtl' : 'ltr');
  PG.digits = (s) => (fa ? String(s).replace(/\d/g, (d) => '۰۱۲۳۴۵۶۷۸۹'[d]) : String(s));

  // ---------------------------------------------------------------- dates
  // ISO with "Z" = real moment (file dates). Without "Z" = camera wall-clock time (date taken), shown as is.
  PG.parseDate = (iso) => {
    if (!iso) return null;
    if (/Z$|[+-]\d\d:\d\d$/.test(iso)) return new Date(iso);
    const m = iso.match(/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::(\d{2}))?)?/);
    return m ? new Date(+m[1], m[2] - 1, +m[3], +(m[4] || 0), +(m[5] || 0), +(m[6] || 0)) : null;
  };
  const fmtCache = {};
  const fmt = (cal, withTime) => {
    const key = cal + withTime;
    if (!fmtCache[key]) {
      const loc = (fa ? 'fa-IR' : 'en-GB') + (cal === 'jalali' ? '-u-ca-persian' : '-u-ca-gregory');
      const o = { year: 'numeric', month: 'short', day: 'numeric' };
      if (withTime) Object.assign(o, { hour: '2-digit', minute: '2-digit', hour12: false });
      fmtCache[key] = new Intl.DateTimeFormat(loc, o);
    }
    return fmtCache[key];
  };
  PG.fmtDate = (iso, withTime = true, cal = cfg.calendar) => {
    const d = typeof iso === 'string' ? PG.parseDate(iso) : iso;
    return d && !isNaN(d) ? fmt(cal, withTime).format(d).replace(/\s?AP\b/, '') : '';
  };
  /** A <time> element: date in the user's calendar, the other calendar as a tooltip. */
  PG.dateHtml = (iso, withTime = true) => {
    if (!iso) return '';
    const other = cfg.calendar === 'jalali' ? 'gregorian' : 'jalali';
    return `<time datetime="${PG.esc(iso)}" title="${PG.esc(PG.fmtDate(iso, withTime, other))}" data-tip>${PG.esc(PG.fmtDate(iso, withTime))}</time>`;
  };
  PG.renderTimes = (root = document) => {
    root.querySelectorAll('time[data-dt]').forEach((el) => {
      el.outerHTML = PG.dateHtml(el.dataset.dt, true);
    });
  };

  // Jalali <-> Gregorian (algorithm from jalaali-js, MIT).
  const div = (a, b) => ~~(a / b), mod = (a, b) => a - ~~(a / b) * b;
  const breaks = [-61, 9, 38, 199, 426, 686, 756, 818, 1111, 1181, 1210, 1635, 2060, 2097, 2192, 2262, 2324, 2394, 2456, 3178];
  function jalCal(jy) {
    let gy = jy + 621, leapJ = -14, jp = breaks[0], jm, jump, n, i;
    for (i = 1; i < breaks.length; i++) {
      jm = breaks[i]; jump = jm - jp;
      if (jy < jm) break;
      leapJ += div(jump, 33) * 8 + div(mod(jump, 33), 4);
      jp = jm;
    }
    n = jy - jp;
    leapJ += div(n, 33) * 8 + div(mod(n, 33) + 3, 4);
    if (mod(jump, 33) === 4 && jump - n === 4) leapJ += 1;
    const leapG = div(gy, 4) - div((div(gy, 100) + 1) * 3, 4) - 150;
    const march = 20 + leapJ - leapG;
    if (jump - n < 6) n = n - jump + div(jump + 4, 33) * 33;
    let leap = mod(mod(n + 1, 33) - 1, 4);
    if (leap === -1) leap = 4;
    return { leap, gy, march };
  }
  function g2d(gy, gm, gd) {
    let d = div((gy + div(gm - 8, 6) + 100100) * 1461, 4) + div(153 * mod(gm + 9, 12) + 2, 5) + gd - 34840408;
    return d - div(div(gy + 100100 + div(gm - 8, 6), 100) * 3, 4) + 752;
  }
  function d2g(jdn) {
    let j = 4 * jdn + 139361631;
    j = j + div(div(4 * jdn + 183187720, 146097) * 3, 4) * 4 - 3908;
    const i = div(mod(j, 1461), 4) * 5 + 308;
    const gd = div(mod(i, 153), 5) + 1, gm = mod(div(i, 153), 12) + 1;
    return { gy: div(j, 1461) - 100100 + div(8 - gm, 6), gm, gd };
  }
  function j2d(jy, jm, jd) { const r = jalCal(jy); return g2d(r.gy, 3, r.march) + (jm - 1) * 31 - div(jm, 7) * (jm - 7) + jd - 1; }
  function d2j(jdn) {
    const gy = d2g(jdn).gy;
    let jy = gy - 621;
    const r = jalCal(jy);
    let k = jdn - g2d(gy, 3, r.march);
    if (k >= 0) {
      if (k <= 185) return { jy, jm: 1 + div(k, 31), jd: mod(k, 31) + 1 };
      k -= 186;
    } else { jy -= 1; k += 179; if (r.leap === 1) k += 1; }
    return { jy, jm: 7 + div(k, 30), jd: mod(k, 30) + 1 };
  }
  PG.jalali = {
    toJalali: (gy, gm, gd) => d2j(g2d(gy, gm, gd)),
    toGregorian: (jy, jm, jd) => d2g(j2d(jy, jm, jd)),
    monthLength: (jy, jm) => (jm <= 6 ? 31 : jm <= 11 ? 30 : jalCal(jy).leap === 0 ? 30 : 29),
  };

  // ---------------------------------------------------------------- api
  PG.api = async (method, url, data) => {
    const opt = { method, headers: { Accept: 'application/json', 'X-CSRF-TOKEN': cfg.csrf, 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' };
    if (data !== undefined) { opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(data); }
    const r = await fetch(url, opt);
    if (r.status === 419 || r.status === 401) { location.reload(); throw new Error('session'); }
    let j = null;
    try { j = await r.json(); } catch (e) { /* empty */ }
    if (!r.ok) {
      const msg = (j && (j.message || (j.errors && Object.values(j.errors)[0][0]))) || PG.t('error');
      const err = new Error(msg); err.status = r.status; err.data = j; throw err;
    }
    return j;
  };
  PG.debounce = (fn, ms) => { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; };

  // ---------------------------------------------------------------- storage outage
  /*
   * When the photo storage does not answer, the server says {storage: "down"} (HTTP 503).
   * PG.storage.wait() shows a friendly box with a Retry button and an automatic retry every 30 s.
   * When the storage is back, onBack() runs, so the user continues exactly where they were.
   */
  PG.storage = {
    isDown: (e) => !!(e && e.status === 503 && e.data && e.data.storage === 'down'),
    async check(fresh) {
      try { return (await PG.api('GET', '/api/health' + (fresh ? '?fresh=1' : ''))).storage === 'ok'; } catch (e) { return false; }
    },
    /** el: element to fill; opts: { onBack, banner: true for the small one-line version } */
    wait(el, opts) {
      const every = 30;
      let left = every, timer = null, busy = false;
      el.classList.add(opts.banner ? 'storage-banner' : 'storage-wait');
      el.innerHTML = opts.banner
        ? `${PG.icon('hard-drive')}<span class="sw-text">${PG.esc(PG.t('storage_down_banner'))}</span>
           <small class="muted" data-sw-count></small><button type="button" class="btn small primary" data-sw-retry>${PG.icon('refresh-cw')} ${PG.esc(PG.t('retry'))}</button>`
        : `<div class="sw-ic">${PG.icon('hard-drive')}</div><h2>${PG.esc(PG.t('storage_down_title'))}</h2>
           <p>${PG.esc(PG.t('storage_down_text'))}</p><p class="muted small">${PG.esc(PG.t('storage_down_tell'))}</p>
           <button type="button" class="btn primary" data-sw-retry>${PG.icon('refresh-cw')} ${PG.esc(PG.t('retry'))}</button>
           <p class="muted small" data-sw-count></p>`;
      const count = el.querySelector('[data-sw-count]');
      const btn = el.querySelector('[data-sw-retry]');
      const stop = () => { clearInterval(timer); timer = null; };
      const tryNow = async () => {
        if (busy) return;
        busy = true; btn.disabled = true; count.textContent = PG.t('retrying');
        const ok = await PG.storage.check(true);
        busy = false; btn.disabled = false;
        if (!el.isConnected) { stop(); return; }
        if (ok) {
          stop();
          el.classList.remove('storage-banner', 'storage-wait');
          el.innerHTML = '';
          PG.toast(PG.icon('check') + ' ' + PG.esc(PG.t('storage_back')), { timeout: 2500 });
          opts.onBack && opts.onBack();
        } else { left = every; tick(); }
      };
      const tick = () => { count.textContent = PG.t('retry_in', { s: PG.num(left) }); };
      btn.addEventListener('click', tryNow);
      tick();
      timer = setInterval(() => {
        if (!el.isConnected) { stop(); return; }
        if (busy) return;
        if (--left <= 0) tryNow(); else tick();
      }, 1000);
      return { stop };
    },
  };

  // ---------------------------------------------------------------- toasts
  PG.toast = (html, opts = {}) => {
    const box = document.getElementById('toasts');
    const el = document.createElement('div');
    el.className = 'toast ' + (opts.type || '');
    el.innerHTML = html;
    box.appendChild(el);
    const close = () => { el.style.opacity = '0'; el.style.transition = 'opacity .25s'; setTimeout(() => el.remove(), 260); };
    if (opts.timeout !== 0) setTimeout(close, opts.timeout || 4000);
    el.close = close;
    return el;
  };
  PG.error = (e) => PG.toast(PG.esc(e && e.message ? e.message : PG.t('error')), { type: 'err', timeout: 6000 });

  // ---------------------------------------------------------------- prefs
  PG.pref = (k, d) => {
    try { const v = localStorage.getItem('pg.' + k); if (v !== null) return JSON.parse(v); } catch (e) { /* storage blocked */ }
    return cfg.prefs && cfg.prefs[k] !== undefined ? cfg.prefs[k] : d;
  };
  PG.setPref = (k, v, server = true) => {
    try { localStorage.setItem('pg.' + k, JSON.stringify(v)); } catch (e) { /* storage blocked */ }
    if (server) PG.api('POST', '/api/prefs', { [k]: v }).catch(() => {});
  };

  // ---------------------------------------------------------------- autocomplete
  /**
   * Suggest list under an input. Asks the server only after 500 ms without typing, and only with at least 1 letter.
   * opts: kind, onPick(name), allowNew (show "Add …")
   */
  PG.suggest = (input, opts) => {
    const wrap = document.createElement('span');
    wrap.className = 'suggest-wrap';
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);
    const box = document.createElement('div');
    box.className = 'suggest';
    box.hidden = true;
    wrap.appendChild(box);
    let items = [], hl = -1, seq = 0;
    const hide = () => { box.hidden = true; hl = -1; };
    const mark = (s, q) => {
      const i = s.toLowerCase().indexOf(q.toLowerCase());
      return i < 0 ? PG.esc(s) : PG.esc(s.slice(0, i)) + '<mark>' + PG.esc(s.slice(i, i + q.length)) + '</mark>' + PG.esc(s.slice(i + q.length));
    };
    const render = (q) => {
      let html = items.map((s, i) => `<button type="button" data-i="${i}" class="${i === hl ? 'hl' : ''}">${mark(s, q)}</button>`).join('');
      if (opts.allowNew && q && !items.some((s) => s.toLowerCase() === q.toLowerCase())) {
        html += `<button type="button" class="new ${hl === items.length ? 'hl' : ''}" data-new="1">${PG.icon('plus')} ${PG.esc(PG.t('new_item', { name: q }))}</button>`;
      }
      if (!html) html = `<div class="hint">${PG.esc(PG.t('no_results'))}</div>`;
      box.innerHTML = html;
      box.hidden = false;
    };
    const load = PG.debounce(async () => {
      const q = input.value.trim();
      if (!q) { hide(); return; }
      const my = ++seq;
      try {
        const res = await PG.api('GET', '/api/suggest/' + opts.kind + '?q=' + encodeURIComponent(q));
        if (my !== seq) return;
        items = res; hl = -1; render(q);
      } catch (e) { hide(); }
    }, 500);
    const pick = (name) => { hide(); if (opts.onPick) opts.onPick(name); else input.value = name; };
    input.addEventListener('input', () => { if (!input.value.trim()) hide(); load(); });
    input.addEventListener('keydown', (e) => {
      const max = items.length + (opts.allowNew && input.value.trim() ? 1 : 0);
      if (e.key === 'ArrowDown' && !box.hidden) { hl = Math.min(max - 1, hl + 1); render(input.value.trim()); e.preventDefault(); }
      else if (e.key === 'ArrowUp' && !box.hidden) { hl = Math.max(0, hl - 1); render(input.value.trim()); e.preventDefault(); }
      else if (e.key === 'Enter') {
        const q = input.value.trim();
        if (!box.hidden && hl >= 0 && hl < items.length) { e.preventDefault(); pick(items[hl]); }
        else if (opts.allowNew && q) { e.preventDefault(); pick(q); }
      } else if (e.key === 'Escape') hide();
    });
    box.addEventListener('mousedown', (e) => e.preventDefault());
    box.addEventListener('click', (e) => {
      const b = e.target.closest('button');
      if (!b) return;
      pick(b.dataset.new ? input.value.trim() : items[+b.dataset.i]);
    });
    input.addEventListener('blur', () => setTimeout(hide, 150));
    return { hide };
  };

  // ---------------------------------------------------------------- date picker
  const pad = (n) => String(n).padStart(2, '0');
  PG.isoToDisplay = (iso) => (iso ? PG.fmtDate(iso, false) : '');
  PG.datePicker = (input) => {
    let box = null;
    const jal = cfg.calendar === 'jalali';
    const gMonths = Array.from({ length: 12 }, (_, i) => new Intl.DateTimeFormat(fa ? 'fa-IR-u-ca-gregory' : 'en-GB', { month: 'long' }).format(new Date(2020, i, 15)));
    const jMonths = fa
      ? ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند']
      : ['Farvardin', 'Ordibehesht', 'Khordad', 'Tir', 'Mordad', 'Shahrivar', 'Mehr', 'Aban', 'Azar', 'Dey', 'Bahman', 'Esfand'];
    const wd = jal ? (fa ? ['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج'] : ['Sa', 'Su', 'Mo', 'Tu', 'We', 'Th', 'Fr'])
      : (fa ? ['د', 'س', 'چ', 'پ', 'ج', 'ش', 'ی'] : ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su']);
    let y, m; // shown month in the user's calendar
    const set = (iso) => { input.dataset.iso = iso || ''; input.value = PG.isoToDisplay(iso); input.title = iso ? PG.fmtDate(iso, false, jal ? 'gregorian' : 'jalali') : ''; };
    set(input.dataset.iso || '');
    const close = () => { if (box) { box.remove(); box = null; document.removeEventListener('mousedown', outside); } };
    const outside = (e) => { if (box && !box.contains(e.target) && e.target !== input) close(); };
    const draw = () => {
      const sel = input.dataset.iso;
      const now = new Date();
      const todayIso = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
      let days, first; // first: weekday index of day 1 (0 = first column)
      if (jal) {
        days = PG.jalali.monthLength(y, m);
        const g = PG.jalali.toGregorian(y, m, 1);
        first = (new Date(g.gy, g.gm - 1, g.gd).getDay() + 1) % 7; // Saturday first
      } else {
        days = new Date(y, m, 0).getDate();
        first = (new Date(y, m - 1, 1).getDay() + 6) % 7; // Monday first
      }
      let cells = wd.map((d) => `<span>${d}</span>`).join('') + '<i></i>'.repeat(first);
      for (let d = 1; d <= days; d++) {
        let iso;
        if (jal) { const g = PG.jalali.toGregorian(y, m, d); iso = `${g.gy}-${pad(g.gm)}-${pad(g.gd)}`; }
        else iso = `${y}-${pad(m)}-${pad(d)}`;
        cells += `<button type="button" data-iso="${iso}" class="${iso === sel ? 'sel' : ''} ${iso === todayIso ? 'today' : ''}">${PG.digits(d)}</button>`;
      }
      const names = jal ? jMonths : gMonths;
      box.innerHTML = `<div class="dp-head"><button type="button" class="icon-btn" data-step="-1">${PG.icon(fa ? 'chevron-right' : 'chevron-left')}</button>
        <b><select data-m>${names.map((n, i) => `<option value="${i + 1}" ${i + 1 === m ? 'selected' : ''}>${n}</option>`).join('')}</select>
        <input type="number" data-y value="${y}" style="width:5.2em;padding:4px 6px"></b>
        <button type="button" class="icon-btn" data-step="1">${PG.icon(fa ? 'chevron-left' : 'chevron-right')}</button></div>
        <div class="dp-grid">${cells.replace(/<i><\/i>/g, '<span></span>')}</div>
        <div class="dp-foot"><button type="button" class="btn ghost small" data-today>${PG.t('today')}</button><button type="button" class="btn ghost small" data-clear>${PG.t('clear')}</button></div>`;
    };
    const open = () => {
      if (box) return;
      const base = input.dataset.iso ? PG.parseDate(input.dataset.iso) : new Date();
      if (jal) { const j = PG.jalali.toJalali(base.getFullYear(), base.getMonth() + 1, base.getDate()); y = j.jy; m = j.jm; }
      else { y = base.getFullYear(); m = base.getMonth() + 1; }
      box = document.createElement('div');
      box.className = 'dp';
      document.body.appendChild(box);
      const r = input.getBoundingClientRect();
      box.style.top = window.scrollY + r.bottom + 6 + 'px';
      const left = fa ? r.right - 280 : r.left;
      box.style.left = Math.max(8, Math.min(window.innerWidth - 288, left)) + window.scrollX + 'px';
      draw();
      box.addEventListener('click', (e) => {
        const b = e.target.closest('button');
        if (!b) return;
        if (b.dataset.step) { m += +b.dataset.step; if (m < 1) { m = 12; y--; } if (m > 12) { m = 1; y++; } draw(); }
        else if (b.dataset.iso) { set(b.dataset.iso); close(); input.dispatchEvent(new Event('change', { bubbles: true })); }
        else if (b.hasAttribute('data-today')) { const n = new Date(); set(`${n.getFullYear()}-${pad(n.getMonth() + 1)}-${pad(n.getDate())}`); close(); }
        else if (b.hasAttribute('data-clear')) { set(''); close(); }
      });
      box.addEventListener('change', (e) => {
        if (e.target.matches('[data-m]')) m = +e.target.value;
        if (e.target.matches('[data-y]')) y = +e.target.value || y;
        draw();
      });
      setTimeout(() => document.addEventListener('mousedown', outside), 0);
    };
    input.addEventListener('click', open);
    input.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); } if (e.key === 'Escape') close(); });
    return { set, close };
  };

  // ---------------------------------------------------------------- page wiring
  document.addEventListener('DOMContentLoaded', () => {
    PG.renderTimes();
    document.querySelectorAll('[data-toggle-menu]').forEach((b) => b.addEventListener('click', () => document.body.classList.toggle('menu-open')));
    document.querySelectorAll('.flash[data-autohide]').forEach((f) => setTimeout(() => { f.style.transition = 'opacity .4s'; f.style.opacity = '0'; setTimeout(() => f.remove(), 450); }, 3500));
    document.querySelectorAll('form[data-confirm]').forEach((f) => f.addEventListener('submit', (e) => { if (!confirm(f.dataset.confirm)) e.preventDefault(); }));
    document.querySelectorAll('[data-preview-avatar]').forEach((inp) => inp.addEventListener('change', () => {
      const file = inp.files && inp.files[0];
      const img = inp.closest('.avatar-edit') && inp.closest('.avatar-edit').querySelector('.avatar');
      if (!file || !img) return;
      const url = URL.createObjectURL(file);
      const ni = document.createElement('img');
      ni.className = img.className.replace('avatar-initials', '');
      ni.src = url;
      img.replaceWith(ni);
    }));
    // generic dropdowns
    document.addEventListener('click', (e) => {
      const t = e.target.closest('[data-dd]');
      document.querySelectorAll('.dd-menu.open').forEach((m) => { if (!t || m.dataset.ddMenu !== t.dataset.dd) m.classList.remove('open'); });
      if (t) document.querySelector(`[data-dd-menu="${t.dataset.dd}"]`).classList.toggle('open');
    });
  });
})();
