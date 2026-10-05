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
