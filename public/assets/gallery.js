/* Personal Gallery — folder browser, views, search, lazy loading, scan controls. */
(function () {
  'use strict';
  const PG = window.PG, t = PG.t, esc = PG.esc, icon = PG.icon;
  const root = document.getElementById('gallery');
  if (!root) return;
  const content = document.getElementById('content');
  const crumbsEl = document.getElementById('crumbs');
  const panel = document.getElementById('searchpanel');
  const isAdmin = PG.cfg.admin;

  const VIEWS = ['tiny', 'small', 'medium', 'large', 'list', 'details'];
  const VIEW_ICONS = { tiny: 'grid-3x3', small: 'layout-grid', medium: 'grid-2x2', large: 'image', list: 'list', details: 'table' };
  const CHUNK = 240;
  const state = {
    mode: root.dataset.mode, path: '', data: null, items: [], files: [], shown: 0,
    view: VIEWS.includes(PG.pref('view')) ? PG.pref('view') : 'medium',
    sort: PG.pref('sort', 'name'), page: 0, scan: null, seq: 0,
  };

  // ---------------------------------------------------------------- URL <-> state
  const encPath = (p) => p.split('/').map(encodeURIComponent).join('/');
  const browseUrl = (p) => '/browse' + (p ? '/' + encPath(p) : '');
  function readUrl() {
    const u = new URL(location.href);
    if (u.pathname.startsWith('/browse')) {
      state.mode = 'browse';
      state.path = decodeURIComponent(u.pathname.replace(/^\/browse\/?/, '')).replace(/\/+$/, '');
    } else if (u.pathname === '/favorites') state.mode = 'favorites';
    else if (u.pathname === '/on-this-day') state.mode = 'onthisday';
    else if (u.pathname === '/search') state.mode = 'search';
    state.search = Object.fromEntries(u.searchParams.entries());
  }
  function go(url, replace) {
    history[replace ? 'replaceState' : 'pushState']({}, '', url);
    readUrl();
    load();
    window.scrollTo({ top: 0 });
  }
  window.addEventListener('popstate', () => {
    if (PG.skipPop) { PG.skipPop = false; return; } // the viewer used this Back step
    readUrl(); load();
  });

  // ---------------------------------------------------------------- loading
  async function load(extra = {}) {
    const my = ++state.seq;
    lazy.reset();
    if (!extra.append) {
      state.page = 0;
      content.innerHTML = `<div class="loading-note">${icon('loader-circle', 'spin')} ${esc(t('loading'))}</div>`;
    }
    updateChrome();
    let url;
    if (state.mode === 'browse') url = '/api/list?path=' + encodeURIComponent(state.path) + (extra.refresh ? '&refresh=1' : '');
    else if (state.mode === 'favorites') url = '/api/favorites?page=' + state.page;
    else if (state.mode === 'onthisday') url = '/api/on-this-day?page=' + state.page;
    else url = '/api/search?' + new URLSearchParams({ ...state.search, sort: state.sort, page: state.page });
    try {
      const data = await PG.api('GET', url);
      if (my !== state.seq) return;
      if (extra.append && state.data) {
        state.data.files = state.data.files.concat(data.files);
        state.data.more = data.more;
      } else state.data = data;
      if (data.scan !== undefined) setScan(data.scan);
      render();
      if (state.mode === 'browse' && state.path === '' && !extra.append) loadOnThisDayStrip();
    } catch (e) {
      if (my !== state.seq) return;
      if (PG.storage.isDown(e)) {
        // Wait here; when the storage is back, load the same folder / page again (the URL did not change).
        if (extra.append) { storageBanner(() => load(extra)); return; }
        content.innerHTML = '<div></div>';
        PG.storage.wait(content.firstElementChild, { onBack: () => load(extra) });
        return;
      }
      content.innerHTML = `<div class="empty-note">${icon('circle-help')}${esc(e.message)}</div>`;
    }
  }

  // One-line banner above the content (used when only some pictures fail). Shown once at a time.
  let bannerEl = null;
  function storageBanner(onBack) {
    if (bannerEl && bannerEl.isConnected) return;
    bannerEl = document.createElement('div');
    content.parentNode.insertBefore(bannerEl, content);
    PG.storage.wait(bannerEl, { banner: true, onBack: () => { bannerEl.remove(); bannerEl = null; onBack(); } });
  }
  // A picture failed: is the storage down? (asked at most every 10 s)
  let lastCheck = 0, lastResult = Promise.resolve(false);
  function storageProblem() {
    if (bannerEl && bannerEl.isConnected) return Promise.resolve(true);
    if (Date.now() - lastCheck > 10000) {
      lastCheck = Date.now();
      lastResult = PG.storage.check(false).then((ok) => {
        if (!ok) storageBanner(() => lazy.retryFailed());
        return !ok;
      });
    }
    return lastResult;
  }

  // ---------------------------------------------------------------- chrome (toolbar, crumbs)
  function updateChrome() {
    const browse = state.mode === 'browse';
    root.querySelector('[data-act="up"]').disabled = !(browse && state.path !== '');
    root.querySelectorAll('.admin-only').forEach((b) => (b.hidden = !browse));
    root.querySelectorAll('.browse-only').forEach((b) => (b.hidden = !browse || (!isAdmin && state.path === '')));
    document.querySelectorAll('[data-sort]').forEach((b) => b.classList.toggle('on', b.dataset.sort === state.sort));
    document.querySelectorAll('[data-view]').forEach((b) => b.classList.toggle('on', b.dataset.view === state.view));
    const vi = root.querySelector('.view-ic use');
    if (vi) vi.setAttribute('href', PG.cfg.icons + '#i-' + VIEW_ICONS[state.view]);
    const here = panel.querySelector('[data-here-wrap]');
    here.hidden = !(browse && state.path !== '') && !(state.search && state.search.in);
    let html = `<a href="${browseUrl('')}" data-nav="">${icon('house')}</a>`;
    if (browse) {
      (state.data && state.data.path === state.path ? state.data.crumbs : []).forEach((c) => {
        html += `<span class="sep">${icon('chevron-right')}</span><a href="${browseUrl(c.path)}" data-nav="${esc(c.path)}">${esc(c.name)}</a>`;
      });
    } else {
      const label = { favorites: t('favorites'), onthisday: t('on_this_day'), search: t('results') }[state.mode];
      html += `<span class="sep">${icon('chevron-right')}</span><a href="${location.pathname + location.search}">${esc(label)}</a>`;
    }
    crumbsEl.innerHTML = html;
    crumbsEl.scrollLeft = PG.fa ? -99999 : 99999;
    const last = (state.data && state.data.crumbs && state.data.crumbs.slice(-1)[0]) || null;
    document.title = (state.mode === 'browse' ? (last ? last.name : t('home')) : crumbsEl.lastElementChild.textContent) + ' · ' + t('app_name');
  }

  // ---------------------------------------------------------------- sorting
  const collator = new Intl.Collator(PG.fa ? 'fa' : 'en', { numeric: true, sensitivity: 'base' });
  const fileDate = (f) => f.taken || f.mtime || '';
  function sortList(list, isFolder) {
    const s = state.sort, a = list.slice();
    const byName = (x, y) => collator.compare(x.name, y.name);
    const date = (x) => (isFolder ? x.mtime || '' : String(PG.parseDate(fileDate(x))?.getTime() || 0).padStart(15, '0'));
    const cmp = {
      name: byName, name_desc: (x, y) => byName(y, x),
      date: (x, y) => (date(x) < date(y) ? -1 : date(x) > date(y) ? 1 : byName(x, y)),
      date_desc: (x, y) => (date(x) > date(y) ? -1 : date(x) < date(y) ? 1 : byName(x, y)),
      size: (x, y) => ((x.size ?? x.count ?? 0) - (y.size ?? y.count ?? 0)) || byName(x, y),
      size_desc: (x, y) => ((y.size ?? y.count ?? 0) - (x.size ?? x.count ?? 0)) || byName(x, y),
      type: (x, y) => collator.compare((x.type || '') + (x.ext || ''), (y.type || '') + (y.ext || '')) || byName(x, y),
    }[s] || byName;
    return a.sort(cmp);
  }

  // ---------------------------------------------------------------- render
  function render() {
    const d = state.data;
    const folders = sortList(d.folders || [], true).map((f) => ({ ...f, kind: 'folder' }));
    state.files = state.mode === 'search' ? d.files.slice() : sortList(d.files || [], false);
    state.items = folders.concat(state.files.map((f) => ({ ...f, kind: 'file' })));
    state.files = state.items.filter((i) => i.kind === 'file');
    state.files.forEach((f, k) => (f.fi = k));
    state.shown = 0;
    content.className = 'content view-' + state.view;
    updateChrome();

    if (!state.items.length) {
      const msg = state.mode === 'browse' ? (state.path === '' && !isAdmin ? t('no_folders') : t('empty_folder'))
        : state.mode === 'favorites' ? t('favorites_empty') : state.mode === 'onthisday' ? t('on_this_day_empty') : t('no_results');
      const ic = { favorites: 'heart', onthisday: 'history', search: 'search' }[state.mode] || 'folder-open';
      content.innerHTML = `<div class="strip-slot"></div><div class="empty-note">${icon(ic)}${esc(msg)}</div>`;
      return;
    }
    let head = '<div class="strip-slot"></div>';
    if (state.mode === 'search') {
      const nf = folders.length, nfi = state.files.length;
      head += `<p class="muted small">${icon('info')} ${esc(t('search_note'))} — ${PG.num(nf)} ${esc(t('folders_found'))}, ${PG.num(nfi)}${d.more ? '+' : ''} ${esc(t('files_found'))}</p>`;
    }
    if (state.view === 'list' || state.view === 'details') {
      content.innerHTML = head + `<div class="rows"><div class="rows-scroll"><table class="rtable"><thead>${tableHead()}</thead><tbody></tbody></table></div></div><div class="sentinel"></div>`;
    } else {
      content.innerHTML = head + '<div class="grid"></div><div class="sentinel"></div>';
    }
    appendChunk();
    if (d.more) content.insertAdjacentHTML('beforeend', `<div class="more-wrap"><button class="btn" type="button" data-act="more">${esc(t('load_more'))}</button></div>`);
    sentinelObs.observe(content.querySelector('.sentinel'));
  }

  function appendChunk() {
    const end = Math.min(state.items.length, state.shown + CHUNK);
    const slice = state.items.slice(state.shown, end);
    const start = state.shown;
    state.shown = end;
    if (state.view === 'list' || state.view === 'details') {
      content.querySelector('tbody').insertAdjacentHTML('beforeend', slice.map((it, k) => rowHtml(it, start + k)).join(''));
    } else {
      const grid = content.querySelector('.grid');
      grid.insertAdjacentHTML('beforeend', slice.map((it, k) => tileHtml(it, start + k)).join(''));
      grid.querySelectorAll('img[data-src]:not([data-obs])').forEach((img) => lazy.observe(img));
    }
    if (state.view === 'details') content.querySelectorAll('img[data-src]:not([data-obs])').forEach((img) => lazy.observe(img));
  }
  const sentinelObs = new IntersectionObserver((ents) => {
    if (ents.some((e) => e.isIntersecting) && state.shown < state.items.length) appendChunk();
  }, { rootMargin: '600px' });

  const typeIcon = (f) => (f.type === 'video' ? 'file-video' : f.type === 'image' ? 'file-image' : 'file');
  const color = (i) => 'c' + (i % 6);
  const scanBtn = (it) => {
    if (!isAdmin || it.kind !== 'folder') return '';
    const run = !!state.scan;
    return `<button type="button" class="${state.view === 'list' || state.view === 'details' ? 'icon-btn' : 'scan-mini'}" data-scan-path="${esc(it.path)}" data-name="${esc(it.name)}" title="${esc(run ? t('stop_scan') : t('scan'))}">${icon(run ? 'circle-stop' : 'scan-search')}</button>`;
  };

  function tileHtml(it, i) {
    if (it.kind === 'folder') {
      const cover = it.cover ? `<img class="cover" data-src="${esc(it.cover)}" data-ready="1" alt="">` : '';
      const count = it.count != null ? `<span class="count">${PG.num(it.count)}</span>` : '';
      const sub = state.mode === 'search' && it.parentName ? esc(it.parentName) : it.mtime ? PG.dateHtml(it.mtime, false) : '&nbsp;';
      return `<a class="tile folder ${color(i)}" href="${browseUrl(it.path)}" data-nav="${esc(it.path)}">
        <div class="thumb">${icon('folder', 'ph')}${cover}${count}</div>${scanBtn(it)}
        <div class="label">${esc(it.name)}<small>${sub}</small></div></a>`;
    }
    const fi = it.fi;
    const img = it.thumb ? `<img data-src="${esc(it.thumb)}" ${it.ready ? 'data-ready="1"' : ''} alt="" draggable="false">` : '';
    const ph = icon(it.err ? 'circle-help' : typeIcon(it), 'ph');
    const badges = (it.fav ? `<span class="badge fav">${icon('heart')}</span>` : '')
      + (it.type === 'video' && it.duration ? `<span class="badge">${PG.duration(it.duration)}</span>` : '');
    const play = it.type === 'video' ? `<span class="play-ov"><span>${icon('play')}</span></span>` : '';
    const ext = !it.thumb || it.type === 'other' ? `<span class="ext">${esc(it.ext)}</span>` : '';
    const sub = state.mode === 'search' || state.mode === 'favorites' || state.mode === 'onthisday'
      ? esc(it.folderName || '') : PG.dateHtml(it.taken || it.mtime, false);
    // A description replaces the file name on the tile (one line, cut with "…").
    return `<button type="button" class="tile file ${color(i)} ${it.err ? 'err' : ''} ${it.desc ? 'has-desc' : ''}" data-file="${fi}" id="f${it.id}" title="${esc(it.name)}">
      <div class="thumb">${ph}${img}${play}${ext}</div><div class="badge-row">${badges}</div>
      <div class="label"><span class="lbl" dir="${PG.textDir(it.desc || it.name)}">${esc(it.desc || it.name)}</span><small>${sub}</small></div></button>`;
  }

  function tableHead() {
    const c = (k, label, cls = '') => `<th data-sort-col="${k}" class="${cls}">${esc(label)}</th>`;
    if (state.view === 'list') return `<tr>${c('name', t('name'))}${c('size', t('size'), 'num')}${c('date', t('modified'))}${isAdmin ? '<th></th>' : ''}</tr>`;
    return `<tr>${c('name', t('name'))}${c('date', t('taken'))}<th>${esc(t('dimensions'))}</th>${c('size', t('size'), 'num')}${c('date', t('modified'))}<th>${esc(t('camera'))}</th><th>${esc(t('location'))}</th><th>${esc(t('tags'))}</th><th>${esc(t('persons'))}</th><th>${esc(t('description'))}</th>${isAdmin ? '<th></th>' : ''}</tr>`;
  }

  function rowHtml(it, i) {
    const admin = isAdmin ? `<td class="act">${scanBtn(it)}</td>` : '';
    if (it.kind === 'folder') {
      const nm = `<div class="nm ${color(i)}">${icon('folder')}<span>${esc(it.name)}</span>${state.mode === 'search' && it.parentName ? `<small class="muted">— ${esc(it.parentName)}</small>` : ''}</div>`;
      const cnt = it.count != null ? esc(t('items', { n: PG.num(it.count) })) : '';
      if (state.view === 'list') return `<tr data-nav-row="${esc(it.path)}"><td>${nm}</td><td class="num">${cnt}</td><td class="dt">${PG.dateHtml(it.mtime)}</td>${admin}</tr>`;
      return `<tr data-nav-row="${esc(it.path)}"><td>${nm}</td><td></td><td></td><td class="num">${cnt}</td><td class="dt">${PG.dateHtml(it.mtime)}</td><td></td><td></td><td></td><td></td><td></td>${admin}</tr>`;
    }
    const fi = it.fi;
    if (state.view === 'list') {
      return `<tr data-file="${fi}" id="f${it.id}"><td><div class="nm">${icon(typeIcon(it))}<span>${esc(it.name)}</span></div></td><td class="num">${PG.size(it.size)}</td><td class="dt">${PG.dateHtml(it.mtime)}</td>${isAdmin ? '<td></td>' : ''}</tr>`;
    }
    // Details: a small thumbnail only when it already exists (no storage read).
    const th = it.thumb && it.ready ? `<img data-src="${esc(it.thumb)}" data-ready="1" alt="">` : icon(typeIcon(it));
    const dim = it.w ? `<span dir="ltr">${PG.digits(it.w + '×' + it.h)}</span>` : it.scanned ? '' : `<small class="muted">${esc(t('not_scanned'))}</small>`;
    const loc = [it.city, it.country].filter(Boolean).join(PG.fa ? '، ' : ', ');
    const chips = (a) => `<div class="tg">${(a || []).map((x) => `<span class="chip">${esc(x)}</span>`).join('')}</div>`;
    return `<tr data-file="${fi}" id="f${it.id}"><td><div class="nm">${th}<span>${esc(it.name)}</span></div></td>
      <td class="dt">${PG.dateHtml(it.taken)}</td><td class="num">${dim}</td><td class="num">${PG.size(it.size)}</td><td class="dt">${PG.dateHtml(it.mtime)}</td>
      <td>${esc(it.camera || '')}</td><td>${esc(loc)}</td><td>${chips(it.tags)}</td><td>${chips(it.persons)}</td><td class="desc" dir="${PG.textDir(it.desc)}" title="${esc(it.desc || '')}">${esc(it.desc || '')}</td>${isAdmin ? '<td></td>' : ''}</tr>`;
  }

  // ---------------------------------------------------------------- lazy loading
  /*
   * Thumbnails that already exist on the server ("ready") load as soon as they are near the screen.
   * Others must be read from the slow storage box first, so they load only when scrolling stops for 500 ms,
   * and only if they are still on screen. A few run at the same time.
   */
  const lazy = (function () {
    const visible = new Set();
    const queue = [];
    let running = 0, runningReady = 0, idleTimer = null, scrolling = false;
    let slowCount = 0, tipShown = false;
    const MAX = 3, MAX_READY = 8;

    const io = new IntersectionObserver((ents) => {
      ents.forEach((e) => {
        if (e.isIntersecting) { visible.add(e.target); if (e.target.dataset.ready) startReady(e.target); }
        else visible.delete(e.target);
      });
      if (!scrolling) schedule(0);
    }, { rootMargin: '150px' });

    function startReady(img) {
      if (img.dataset.state) return;
      if (runningReady >= MAX_READY) { setTimeout(() => startReady(img), 120); return; }
      fire(img, true);
    }
    function fire(img, ready) {
      img.dataset.state = 'loading';
      const tile = img.closest('.tile');
      if (tile) tile.classList.add('loading');
      ready ? runningReady++ : running++;
      const t0 = performance.now();
      const done = (ok) => {
        ready ? runningReady-- : running--;
        if (tile) tile.classList.remove('loading');
        if (ok) {
          img.dataset.state = 'done';
          img.classList.add('loaded');
          if (!ready && performance.now() - t0 > 2500) slow();
          markReady(img);
        } else {
          const tries = +(img.dataset.tries || 0) + 1;
          img.dataset.tries = tries;
          img.removeAttribute('src');
          img.dataset.state = 'failed';
          // Storage down: keep it "failed"; the banner's Retry loads it again. Otherwise retry a few times.
          storageProblem().then((down) => {
            if (down || img.dataset.state !== 'failed') return;
            if (tries < 3) { img.dataset.state = ''; setTimeout(() => { if (visible.has(img)) { queue.push(img); pump(); } }, 3000 * tries); }
          });
        }
        pump();
      };
      img.onload = () => done(true);
      img.onerror = () => done(false);
      img.src = img.dataset.src;
    }
    function markReady(img) {
      const tile = img.closest('[data-file]');
      if (tile && state.files[tile.dataset.file]) state.files[tile.dataset.file].ready = true;
    }
    function pump() {
      while (running < MAX && queue.length) {
        const img = queue.shift();
        if (!img.isConnected || img.dataset.state || !visible.has(img)) continue;
        fire(img, false);
      }
    }
    function schedule(delay) {
      clearTimeout(idleTimer);
      idleTimer = setTimeout(() => {
        scrolling = false;
        queue.length = 0;
        const list = [...visible].filter((i) => !i.dataset.state && !i.dataset.ready && i.isConnected);
        list.sort((a, b) => a.getBoundingClientRect().top - b.getBoundingClientRect().top);
        queue.push(...list);
        pump();
      }, delay);
    }
    function slow() {
      slowCount++;
      if (tipShown || slowCount < 4 || PG.pref('hide_slow_tip', false) || state.view === 'list') return;
      tipShown = true;
      const el = PG.toast(`<b>${icon('timer')} ${esc(t('slow_tip_title'))}</b>${esc(t('slow_tip_text'))}
        <div class="toast-actions"><button class="btn small primary" type="button" data-x="list">${icon('list')} ${esc(t('slow_tip_list'))}</button>
        <button class="btn small ghost" type="button" data-x="ok">${esc(t('ok'))}</button>
        <label class="check small"><input type="checkbox" data-x="never"> <span>${esc(t('dont_show_again'))}</span></label></div>`, { timeout: 0 });
      el.addEventListener('click', (e) => {
        const x = e.target.closest('[data-x]');
        if (!x || x.dataset.x === 'never') return;
        if (el.querySelector('[data-x="never"]').checked) PG.setPref('hide_slow_tip', true);
        if (x.dataset.x === 'list') setView('list');
        el.close();
      });
    }
    window.addEventListener('scroll', () => { scrolling = true; schedule(500); }, { passive: true });
    return {
      observe(img) { img.dataset.obs = '1'; io.observe(img); },
      reset() { io.disconnect(); visible.clear(); queue.length = 0; },
      // After an outage: load the pictures that failed again (same page, same scroll position).
      retryFailed() {
        content.querySelectorAll('img[data-state="failed"]').forEach((img) => {
          img.dataset.state = ''; img.dataset.tries = 0;
          if (visible.has(img)) { if (img.dataset.ready) startReady(img); else queue.push(img); }
        });
        pump();
      },
    };
  })();

  // ---------------------------------------------------------------- "on this day" strip on the home page
  async function loadOnThisDayStrip() {
    try {
      const d = await PG.api('GET', '/api/on-this-day');
      const files = (d.files || []).filter((f) => f.thumb && f.ready).slice(0, 14);
      const slot = content.querySelector('.strip-slot');
      if (!files.length || !slot) return;
      slot.innerHTML = `<a class="section-title" href="/on-this-day" data-nav-mode="/on-this-day">${icon('history')} ${esc(t('on_this_day'))} · ${PG.num(d.files.length)}${d.more ? '+' : ''}</a>
        <div class="grid view-small otd">${files.map((f) => `<a class="tile file" href="/on-this-day" data-nav-mode="/on-this-day"><div class="thumb"><img src="${esc(f.thumb)}" class="loaded" alt=""></div>
        <div class="label"><small>${esc(t('years_ago', { n: PG.num(new Date().getFullYear() - PG.parseDate(f.taken).getFullYear()) }))}</small></div></a>`).join('')}</div>`;
    } catch (e) { /* not important */ }
  }

  // ---------------------------------------------------------------- views & sort
  function setView(v) {
    state.view = v;
    PG.setPref('view', v);
    if (state.data) render();
  }
  function setSort(s) {
    state.sort = s;
    PG.setPref('sort', s);
    if (state.mode === 'search') load(); else if (state.data) render();
  }

  // ---------------------------------------------------------------- scan (admin)
  function setScan(s) {
    state.scan = s && ['queued', 'running'].includes(s.status) ? s : null;
    const btn = root.querySelector('.scan-btn');
    const bar = document.getElementById('scanbar');
    if (!btn) return;
    const run = !!state.scan;
    btn.classList.toggle('running', run);
    btn.querySelector('.scan-label').textContent = run ? t('stop_scan') : t('scan');
    btn.querySelector('.scan-ic use').setAttribute('href', PG.cfg.icons + '#i-' + (run ? 'circle-stop' : 'scan-search'));
    btn.title = run ? t('stop_scan') : t('scan');
    content.querySelectorAll('[data-scan-path]').forEach((b) => {
      b.title = run ? t('stop_scan') : t('scan');
      b.querySelector('use').setAttribute('href', PG.cfg.icons + '#i-' + (run ? 'circle-stop' : 'scan-search'));
    });
    bar.hidden = !run;
    if (run) {
      const s2 = state.scan;
      bar.querySelector('[data-scan-title]').textContent = s2.stopping ? t('scan_stopping')
        : s2.status === 'queued' ? t('scan_queued') : s2.phase === 'walk' ? t('scan_walk') : t('scan_progress', { done: PG.num(s2.processed), total: PG.num(s2.total) });
      bar.querySelector('[data-scan-file]').textContent = '/' + (s2.current || s2.path || '');
      bar.querySelector('[data-scan-bar]').style.width = s2.total ? Math.min(100, (100 * s2.processed) / s2.total) + '%' : '3%';
      clearTimeout(setScan.timer);
      setScan.timer = setTimeout(pollScan, 3000);
    }
  }
  async function pollScan() {
    try {
      const r = await PG.api('GET', '/api/admin/scan');
      const was = !!state.scan;
      setScan(r.active);
      if (was && !r.active && r.last) PG.toast(`${icon('check')} ${esc(t('status_' + r.last.status))}: /${esc(r.last.path)} — ${PG.num(r.last.processed)} / ${PG.num(r.last.total)}`);
    } catch (e) { setScan.timer = setTimeout(pollScan, 10000); }
  }
  async function scanClick(path, name) {
    if (state.scan) {
      try { const r = await PG.api('POST', '/api/admin/scan/stop'); setScan(r.active); state.scan && (state.scan.stopping = true); } catch (e) { PG.error(e); }
      return;
    }
    const dlg = document.createElement('dialog');
    dlg.className = 'modal';
    dlg.innerHTML = `<div class="modal-head"><b>${icon('scan-search')} ${esc(t('scan'))}</b></div>
      <div style="padding:16px"><p>${esc(t('scan_confirm', { name: name || '/' }))}</p>
      <label class="check"><input type="checkbox" checked data-rec> <span>${esc(t('scan_include_sub'))}</span></label></div>
      <div class="modal-foot"><button class="btn ghost" type="button" data-no>${esc(t('cancel'))}</button><button class="btn primary" type="button" data-yes>${icon('scan-search')} ${esc(t('scan'))}</button></div>`;
    document.body.appendChild(dlg);
    dlg.showModal();
    dlg.addEventListener('close', () => dlg.remove());
    dlg.querySelector('[data-no]').onclick = () => dlg.close();
    dlg.querySelector('[data-yes]').onclick = async () => {
      const recursive = dlg.querySelector('[data-rec]').checked;
      dlg.close();
      try {
        const r = await PG.api('POST', '/api/admin/scan', { path, recursive });
        setScan(r.active);
        PG.toast(icon('check') + ' ' + esc(t('scan_started')));
      } catch (e) { PG.error(e); if (e.data && e.data.active) setScan(e.data.active); }
    };
  }

  // ---------------------------------------------------------------- search panel
  const form = panel;
  form.querySelectorAll('[data-datepicker]').forEach((i) => (i._dp = PG.datePicker(i)));
  form.querySelectorAll('[data-suggest]').forEach((i) => PG.suggest(i, { kind: i.dataset.suggest }));
  function fillSearchForm() {
    const s = state.search || {};
    form.q.value = s.q || '';
    ['tag', 'person', 'camera', 'place', 'type'].forEach((k) => (form[k].value = s[k] || ''));
    ['fav', 'gps', 'described'].forEach((k) => (form[k].checked = !!s[k]));
    form.here.checked = !!s.in;
    form.from._dp.set(s.from || '');
    form.to._dp.set(s.to || '');
    if (s.tag || s.person || s.camera || s.place || s.type || s.fav || s.gps || s.described) form.querySelector('.sp-more').hidden = false;
  }
  function toggleSearch(show) {
    panel.hidden = show === undefined ? !panel.hidden : !show;
    root.querySelector('[data-act="search"]').classList.toggle('on', !panel.hidden);
    if (!panel.hidden) { fillSearchForm(); form.q.focus(); }
  }
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    const p = {};
    if (form.q.value.trim()) p.q = form.q.value.trim();
    ['tag', 'person', 'camera', 'place', 'type'].forEach((k) => { if (form[k].value.trim()) p[k] = form[k].value.trim(); });
    ['fav', 'gps', 'described'].forEach((k) => { if (form[k].checked) p[k] = 1; });
    if (form.from.dataset.iso) p.from = form.from.dataset.iso;
    if (form.to.dataset.iso) p.to = form.to.dataset.iso;
    if (form.here.checked) p.in = state.mode === 'browse' ? state.path : (state.search && state.search.in) || '';
    if (!p.in) delete p.in;
    go('/search?' + new URLSearchParams(p));
  });
  form.addEventListener('reset', () => setTimeout(() => { form.from._dp.set(''); form.to._dp.set(''); }, 0));
  form.querySelector('[data-act="more-filters"]').addEventListener('click', () => { const m = form.querySelector('.sp-more'); m.hidden = !m.hidden; });

  // ---------------------------------------------------------------- events
  root.addEventListener('click', (e) => {
    const scanB = e.target.closest('[data-scan-path]');
    if (scanB) { e.preventDefault(); e.stopPropagation(); scanClick(scanB.dataset.scanPath, scanB.dataset.name); return; }
    const navMode = e.target.closest('[data-nav-mode]');
    if (navMode) { e.preventDefault(); go(navMode.getAttribute('href')); return; }
    const nav = e.target.closest('[data-nav]');
    if (nav && !e.ctrlKey && !e.metaKey && !e.shiftKey) { e.preventDefault(); go(browseUrl(nav.dataset.nav)); return; }
    const row = e.target.closest('[data-nav-row]');
    if (row) { go(browseUrl(row.dataset.navRow)); return; }
    const file = e.target.closest('[data-file]');
    if (file) { openViewer(+file.dataset.file); return; }
    const col = e.target.closest('[data-sort-col]');
    if (col) { const k = col.dataset.sortCol; setSort(state.sort === k ? k + '_desc' : k); return; }
    const act = e.target.closest('[data-act]');
    if (!act) return;
    const a = act.dataset.act;
    if (a === 'up' && state.path !== '') go(browseUrl(state.data && state.data.parent != null ? state.data.parent : ''));
    else if (a === 'search') toggleSearch();
    else if (a === 'refresh') load({ refresh: true });
    else if (a === 'scan') scanClick(state.path, (state.data && state.data.crumbs.slice(-1)[0] || { name: '/' }).name);
    else if (a === 'more') { act.closest('.more-wrap').remove(); state.page++; load({ append: true }); }
  });
  document.querySelectorAll('[data-view]').forEach((b) => b.addEventListener('click', () => setView(b.dataset.view)));
  document.querySelectorAll('[data-sort]').forEach((b) => b.addEventListener('click', () => setSort(b.dataset.sort)));
  document.addEventListener('keydown', (e) => {
    if (e.target.matches('input, textarea, select') || document.querySelector('.viewer')) return;
    if (e.key === 'Backspace' && state.mode === 'browse' && state.path) { e.preventDefault(); root.querySelector('[data-act="up"]').click(); }
    if (e.key === '/') { e.preventDefault(); toggleSearch(true); }
  });

  // ---------------------------------------------------------------- viewer
  function openViewer(index) {
    if (!window.PGViewer) return;
    window.PGViewer.open(state.files, index, {
      onUpdate(f) {
        const el = document.getElementById('f' + f.id);
        if (!el) return;
        const img = el.querySelector('img');
        if (img && f.thumb && img.dataset.src !== f.thumb) { img.dataset.src = f.thumb; img.src = f.thumb; }
        const lbl = el.querySelector('.lbl');
        if (lbl) { lbl.textContent = f.desc || f.name; lbl.dir = PG.textDir(f.desc || f.name); el.classList.toggle('has-desc', !!f.desc); }
        const badges = el.querySelector('.badge-row');
        if (badges) {
          const fav = badges.querySelector('.fav');
          if (f.fav && !fav) badges.insertAdjacentHTML('afterbegin', `<span class="badge fav">${icon('heart')}</span>`);
          if (!f.fav && fav) fav.remove();
        }
      },
      onFolder(path) { go(browseUrl(path), true); },
    });
  }

  readUrl();
  if (state.mode === 'search') fillSearchForm();
  load();
  if (isAdmin && state.mode !== 'browse') PG.api('GET', '/api/admin/scan').then((r) => setScan(r.active)).catch(() => {});
})();
