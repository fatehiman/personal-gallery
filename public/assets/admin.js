/* Personal Gallery — admin: folder picker on the user form. */
(function () {
  'use strict';
  const PG = window.PG;
  document.addEventListener('DOMContentLoaded', () => {
    const dlg = document.getElementById('folder-picker');
    const btn = document.querySelector('[data-pick-folder]');
    if (!dlg || !btn) return;
    const input = document.querySelector('[data-folder-input]');
    const list = dlg.querySelector('[data-picker-list]');
    const pathEl = dlg.querySelector('[data-picker-path]');
    let current = '';
    async function show(path) {
      list.innerHTML = `<div class="loading-note">${PG.icon('loader-circle', 'spin')}</div>`;
      try {
        const r = await PG.api('GET', '/api/admin/dirs?path=' + encodeURIComponent(path));
        current = r.path;
        pathEl.textContent = '/' + r.path;
        let html = r.parent !== null ? `<button type="button" data-p="${PG.esc(r.parent)}">${PG.icon('arrow-up')} ..</button>` : '';
        html += r.dirs.map((d) => `<button type="button" data-p="${PG.esc(d.path)}">${PG.icon('folder')} ${PG.esc(d.name)}</button>`).join('');
        list.innerHTML = html || `<p class="muted" style="padding:10px">—</p>`;
      } catch (e) { list.innerHTML = `<p class="muted" style="padding:10px">${PG.esc(e.message)}</p>`; }
    }
    btn.addEventListener('click', () => { dlg.showModal(); show(input.value.replace(/^\/+/, '')); });
    list.addEventListener('click', (e) => { const b = e.target.closest('[data-p]'); if (b) show(b.dataset.p); });
    dlg.querySelectorAll('[data-close]').forEach((b) => b.addEventListener('click', () => dlg.close()));
    dlg.querySelector('[data-picker-choose]').addEventListener('click', () => {
      input.value = current;
      const title = document.querySelector('.add-folder input[name="title"]');
      if (title && !title.value) title.value = current.split('/').pop() || '/';
      dlg.close();
    });
  });
})();

/* Personal Gallery — admin: database statistics on the scan jobs page.
   Nothing is loaded with the page: the (heavy) count runs only when the admin presses the button. */
(function () {
  'use strict';
  const PG = window.PG;
  const COLORS = 6; // c0..c5 are the pastel palette classes of the theme
  document.addEventListener('DOMContentLoaded', () => {
    const box = document.getElementById('scan-stats');
    if (!box) return;
    const btn = box.querySelector('[data-stats-load]');
    const label = box.querySelector('[data-stats-label]');
    const body = box.querySelector('[data-stats-body]');
    const pf = new Intl.NumberFormat(document.documentElement.lang === 'fa' ? 'fa-IR' : 'en-US', { style: 'percent', maximumFractionDigits: 1 });
    // "12.5%"; a small value is never shown as 0% ("<0.1%").
    const share = (v, total) => (!total || v <= 0 ? pf.format(0) : v / total < 0.001 ? '<' + pf.format(0.001) : pf.format(v / total));

    // A circle chart: one SVG circle per slice (stroke-dasharray on a circle of length 100, starts at 12 o'clock).
    function donut(title, slices, fmt) {
      const total = slices.reduce((a, s) => a + s.value, 0);
      let at = 0;
      const segs = total ? slices.filter((s) => s.value > 0).map((s) => {
        const i = slices.indexOf(s);
        const len = (s.value / total) * 100;
        const el = `<circle class="seg c${i % COLORS}" cx="21" cy="21" r="15.9155" stroke-dasharray="${len} ${100 - len}" stroke-dashoffset="${-at}">`
          + `<title>${PG.esc(s.name)}: ${PG.esc(fmt(s.value))} (${PG.esc(share(s.value, total))})</title></circle>`;
        at += len;
        return el;
      }).join('') : '';
      const legend = slices.map((s, i) => `<li><i class="c${i % COLORS}"></i><span class="nm" title="${PG.esc(s.name)}">${PG.esc(s.name)}</span>`
        + `<span class="v">${PG.esc(fmt(s.value))} · ${PG.esc(share(s.value, total))}</span></li>`).join('');
      const center = fmt(total);
      return `<div class="stat-chart"><h3>${PG.esc(title)}</h3>`
        + `<svg class="donut" viewBox="0 0 42 42" role="img" aria-label="${PG.esc(title)}: ${PG.esc(center)}">`
        + `<circle class="track" cx="21" cy="21" r="15.9155"/><g transform="rotate(-90 21 21)">${segs}</g>`
        + `<text class="${center.length > 6 ? 'sm' : ''}" x="21" y="22.5">${PG.esc(center)}</text></svg>`
        + (total ? `<ul class="legend">${legend}</ul>` : `<p class="muted small">${PG.esc(PG.t('stats_no_data'))}</p>`) + `</div>`;
    }

    function render(d) {
      const typeName = { image: PG.t('stats_images'), video: PG.t('stats_videos'), other: PG.t('stats_other') };
      const top = d.exts.slice(0, 5);
      const rest = d.exts.slice(5).reduce((a, e) => a + e.count, 0);
      const exts = top.map((e) => ({ name: e.ext ? e.ext.toUpperCase() : '—', value: e.count }));
      if (rest > 0) exts.push({ name: PG.t('stats_others'), value: rest });
      const tiles = [
        [PG.num(d.folders.total), PG.t('stats_folders')],
        [PG.num(d.files.total), PG.t('stats_files')],
        [PG.size(d.files.size), PG.t('stats_total_size')],
        [PG.num(d.files.thumbs), PG.t('stats_thumbs')],
        [PG.num(d.files.gps), PG.t('stats_gps')],
        [PG.num(d.files.hidden), PG.t('stats_deleted')],
      ].map(([v, l], i) => `<div class="stat-tile c${i}"><b>${PG.esc(v)}</b><span>${PG.esc(l)}</span></div>`).join('');
      body.innerHTML = `<div class="stats-tiles">${tiles}</div><div class="stats-charts">`
        + donut(PG.t('stats_by_type'), d.types.map((t) => ({ name: typeName[t.type], value: t.count })), PG.num)
        + donut(PG.t('stats_size_by_type'), d.types.map((t) => ({ name: typeName[t.type], value: t.size })), PG.size)
        + donut(PG.t('stats_by_ext'), exts, PG.num)
        + donut(PG.t('stats_read_status'), [
          { name: PG.t('stats_read_ok'), value: d.read.read },
          { name: PG.t('stats_not_read'), value: d.read.pending },
          { name: PG.t('stats_read_err'), value: d.read.errors },
        ], PG.num)
        + donut(PG.t('stats_folder_status'), [
          { name: PG.t('stats_listed'), value: d.folders.listed },
          { name: PG.t('stats_not_listed'), value: d.folders.total - d.folders.listed },
        ], PG.num)
        + `</div><p class="stats-foot muted small">${PG.esc(PG.t('stats_generated', { time: PG.fmtDate(d.generated_at), ms: PG.num(d.ms) }))}</p>`;
    }

    btn.addEventListener('click', async () => {
      const ic = btn.querySelector('svg');
      btn.disabled = true;
      ic.classList.add('spin');
      label.textContent = PG.t('stats_loading');
      try {
        render(await PG.api('GET', '/api/admin/scan/stats'));
        label.textContent = PG.t('stats_refresh');
      } catch (e) {
        if (e.message !== 'session') {
          body.innerHTML = `<p class="stats-empty err">${PG.icon('circle-help')} ${PG.esc(PG.t('stats_failed'))}</p>`;
          PG.error(e);
        }
        label.textContent = PG.t('stats_generate');
      } finally {
        btn.disabled = false;
        ic.classList.remove('spin');
      }
    });
  });
})();
