/* Personal Gallery — fullscreen viewer (fade, zoom, swipe, slideshow, tags, people with face boxes, info). */
(function () {
  'use strict';
  const PG = window.PG, t = PG.t, esc = PG.esc, icon = PG.icon;
  const IMG_SHOW = ['jpg', 'jpeg', 'jpe', 'png', 'gif', 'webp', 'bmp', 'avif', 'svg'];
  const MIME = { mp4: 'video/mp4', m4v: 'video/mp4', mov: 'video/mp4', webm: 'video/webm', ogv: 'video/ogg' };
  const info = {}; // media id -> details from /api/media/{id}
  let V = null; // current viewer state

  // Back button (or swipe back on phones) closes the viewer instead of leaving the page.
  window.addEventListener('popstate', () => { if (V) { PG.skipPop = true; close(true); } });

  function open(files, index, opts = {}) {
    if (V) close(true);
    const el = document.createElement('div');
    el.className = 'viewer';
    el.setAttribute('role', 'dialog');
    el.innerHTML = `
      <div class="v-main">
        <div class="v-top">
          <button class="icon-btn" data-v="close" title="${esc(t('close'))}">${icon('x')}</button>
          <div class="v-title"><b data-v-name></b><small data-v-sub></small></div>
          <button class="icon-btn" data-v="fav" title="${esc(t('favorite'))}">${icon('heart')}</button>
          <button class="icon-btn" data-v="rotl" title="${esc(t('rotate_left'))}">${icon('rotate-ccw')}</button>
          <button class="icon-btn" data-v="rotr" title="${esc(t('rotate_right'))}">${icon('rotate-cw')}</button>
          <button class="icon-btn" data-v="face" title="${esc(t('add_person'))}">${icon('scan-face')}</button>
          <button class="icon-btn" data-v="play" title="${esc(t('slideshow'))}">${icon('play')}</button>
          <select class="v-int" data-v-int title="${esc(t('slideshow_speed'))}">${[3, 5, 10].map((n) => `<option value="${n}" ${n === +PG.pref('slide_interval', 5) ? 'selected' : ''}>${PG.digits(n)}s</option>`).join('')}</select>
          <button class="icon-btn" data-v="panel" title="${esc(t('info'))}">${icon('info')}</button>
          <a class="icon-btn" data-v="dl" title="${esc(t('download'))}" download>${icon('download')}</a>
          <button class="icon-btn v-del" data-v="del" title="${esc(t('delete'))}" ${opts.onDelete ? '' : 'hidden'}>${icon('trash-2')}</button>
          <button class="icon-btn" data-v="fs" title="${esc(t('fullscreen'))}">${icon('maximize')}</button>
        </div>
        <div class="v-stage">
          <button class="v-nav v-prev" data-v="prev" title="${esc(t('previous'))}">${icon('chevron-left')}</button>
          <button class="v-nav v-next" data-v="next" title="${esc(t('next'))}">${icon('chevron-right')}</button>
          <div class="v-slidebar"></div>
        </div>
      </div>
      <aside class="v-panel" hidden></aside>`;
    document.body.appendChild(el);
    document.body.style.overflow = 'hidden';
    V = {
      el, files, index, opts, stage: el.querySelector('.v-stage'), panel: el.querySelector('.v-panel'),
      slide: null, zoom: 1, px: 0, py: 0, player: null, drawing: false, play: false, timer: null,
      panelOpen: false, // info panel stays open while moving next/previous, but each new opening starts closed
    };
    history.pushState({ pgViewer: 1 }, '');
    requestAnimationFrame(() => el.classList.add('show'));
    bind();
    show(index, 0);
  }

  function close(fromPop) {
    if (!V) return;
    const v = V;
    abortCopy(v);
    V = null;
    stopSlideshow(v);
    if (v.player) { try { v.player.dispose(); } catch (e) { /* */ } }
    if (document.fullscreenElement) document.exitFullscreen().catch(() => {});
    v.el.classList.remove('show');
    setTimeout(() => v.el.remove(), 300);
    document.body.style.overflow = '';
    document.removeEventListener('keydown', onKey);
    window.removeEventListener('resize', onResize);
    if (!fromPop) { PG.skipPop = true; history.back(); }
  }

  const cur = () => V.files[V.index];

  // ---------------------------------------------------------------- slides
  async function show(i, dir) {
    if (!V) return;
    const n = V.files.length;
    V.index = (i + n) % n;
    const f = cur();
    abortCopy(V);
    V.zoom = 1; V.px = 0; V.py = 0; setDrawing(false);
    if (V.player) { try { V.player.dispose(); } catch (e) { /* */ } V.player = null; }
    V.el.querySelector('.v-prev').hidden = V.el.querySelector('.v-next').hidden = n < 2;

    const old = V.slide;
    const slide = document.createElement('div');
    slide.className = 'v-slide';
    slide.innerHTML = `<div class="v-spinner">${icon('loader-circle', 'spin')}</div>`;
    V.stage.appendChild(slide);
    V.slide = slide;
    requestAnimationFrame(() => slide.classList.add('in'));
    if (old) { old.classList.remove('in'); setTimeout(() => old.remove(), 460); }
    header(f);
    renderPanel(f);

    // Details first when we do not have URLs yet (e.g. opened from the map).
    if (!f.url) await loadInfo(f);
    if (!V || cur() !== f) return;
    if (f.type === 'image' && IMG_SHOW.includes(f.ext)) showImage(f, slide);
    else if (f.type === 'video') showVideo(f, slide);
    else noPreview(f, slide);
    if (!info[f.id]) loadInfo(f).then(() => {
      if (!V || cur() !== f) return;
      const fr = slide.querySelector('.v-frame');
      if (fr && fr._setSize && f.w) fr._setSize(f.w, f.h);
      header(f); renderPanel(f); drawFaces(); caption(f);
    });
    caption(f);
  }

  function header(f) {
    V.el.querySelector('[data-v-name]').textContent = f.name;
    const sub = [f.taken ? PG.dateHtml(f.taken) : f.mtime ? PG.dateHtml(f.mtime) : '', [f.city, f.country].filter(Boolean).map(esc).join(PG.fa ? '، ' : ', ')]
      .filter(Boolean).join(' · ') + (V.files.length > 1 ? ` · ${PG.num(V.index + 1)}/${PG.num(V.files.length)}` : '');
    V.el.querySelector('[data-v-sub]').innerHTML = sub;
    V.el.querySelector('[data-v="fav"]').classList.toggle('on', !!f.fav);
    const dl = V.el.querySelector('[data-v="dl"]');
    if (f.dl) dl.href = f.dl;
    const isImg = f.type === 'image';
    V.el.querySelector('[data-v="rotl"]').hidden = V.el.querySelector('[data-v="rotr"]').hidden = !isImg;
    V.el.querySelector('[data-v="face"]').hidden = !isImg;
    V.el.querySelector('[data-v="panel"]').classList.toggle('active', V.panelOpen);
    V.panel.hidden = !V.panelOpen;
  }

  function frameFor(f, slide, w, h) {
    const frame = document.createElement('div');
    frame.className = 'v-frame';
    frame.dataset.w = w; frame.dataset.h = h;
    slide.appendChild(frame);
    return frame;
  }

  // Blurred thumbnail as the frame background, the original on top. If the original is already in the
  // browser cache it paints at once, so no blur is seen. The frame size always comes from the real image.
  function showImage(f, slide) {
    const rot = f.rot || 0;
    let w0 = f.w, h0 = f.h;
    if (!w0 && f.tw) { [w0, h0] = rot % 180 ? [f.th, f.tw] : [f.tw, f.th]; } // thumbnails already contain the rotation
    const frame = frameFor(f, slide, w0 || 4, h0 || 3);
    if (!w0) frame.style.visibility = 'hidden'; // unknown size: wait for the thumbnail or the image
    // The thumbnail file is already turned by "rot" but the frame is turned too, so use it only when rot is 0.
    if (f.thumb && !rot) { frame.style.setProperty('--low', `url("${f.thumb}")`); frame.classList.add('has-low'); }
    const img = new Image();
    img.alt = ''; img.draggable = false; img.decoding = 'async';
    frame.appendChild(img);
    layout(frame);
    const setSize = (w, h) => {
      if (!w || !h || frame.classList.contains('ready')) return;
      frame.dataset.w = w; frame.dataset.h = h;
      frame.style.visibility = '';
      layout(frame);
    };
    frame._setSize = setSize;
    img.onload = () => {
      frame.classList.remove('ready');
      setSize(img.naturalWidth, img.naturalHeight); // browsers apply the EXIF orientation here
      frame.classList.add('ready');
      img.alt = f.name;
      const sp = slide.querySelector('.v-spinner'); if (sp) sp.remove();
      if (V && V.play) armSlideshow();
    };
    img.onerror = () => {
      img.style.visibility = 'hidden'; // no "broken image" icon
      const sp = slide.querySelector('.v-spinner'); if (sp) sp.remove();
      storageCheck(f, slide, () => { if (!f.thumb) noPreview(f, slide); });
    };
    const go = () => { img.src = f.url; };
    if (f.thumb) {
      // The thumbnail is small (and cached). Its size gives the right shape before the original arrives.
      // For a file never read before, this request also reads its metadata, so it runs before the original.
      const pre = new Image();
      pre.onload = () => {
        if (!f.w && pre.naturalWidth) { if (rot % 180) setSize(pre.naturalHeight, pre.naturalWidth); else setSize(pre.naturalWidth, pre.naturalHeight); }
        if (!f.ready) go();
      };
      pre.onerror = () => { if (!f.ready) go(); };
      pre.src = f.thumb;
      if (f.ready) go();
    } else go();
    drawFaces();
  }

  function showVideo(f, slide) {
    const w = f.w || 1280, h = f.h || 720;
    const frame = frameFor(f, slide, w, h);
    const sp = slide.querySelector('.v-spinner'); if (sp) sp.remove();
    if (!f.playable || !window.videojs) {
      noPreview(f, slide);
      return;
    }
    prepareVideo(f, slide).then((url) => { if (url && V && V.slide === slide) playVideo(f, slide, frame, url); });
  }

  // ---------------------------------------------------------------- video: copy to the server first, then play from there
  const GB = 1073741824, MB = 1048576;
  const copySize = (c, total) => PG.digits(total >= GB ? `${(c / GB).toFixed(1)}/${(total / GB).toFixed(1)} GB` : `${Math.round(c / MB)}/${Math.max(1, Math.round(total / MB))} MB`);
  const hhmm = (s) => PG.digits(String(Math.floor(s / 3600)).padStart(2, '0') + ':' + String(Math.floor((s % 3600) / 60)).padStart(2, '0'));
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
  const RING = 2 * Math.PI * 54;

  function abortCopy(v) {
    if (v && v.copy) {
      v.copy.cancelled = true;
      PG.api('DELETE', `/api/media/${v.copy.id}/video`).catch(() => {});
      v.copy = null;
    }
  }

  // Returns the URL of the local copy, or null (error, cancelled, or the user went away).
  async function prepareVideo(f, slide) {
    const box = document.createElement('div');
    box.className = 'v-copy';
    slide.appendChild(box);
    const job = { id: f.id, cancelled: false };
    V.copy = job;
    const stop = () => !V || V.slide !== slide || job.cancelled;
    const retryBtn = () => `<button class="btn primary" type="button" data-copy="retry">${icon('refresh-cw')} ${esc(t('video_retry'))}</button>`;
    const message = (text, extra = '') => {
      box.innerHTML = `<div class="v-copy-msg">${icon('circle-help')}<p>${esc(text)}</p>${extra}</div>`;
      const b = box.querySelector('[data-copy="retry"]');
      if (b) b.onclick = () => { if (V && cur() === f) show(V.index, 0); };
    };
    const draw = (st) => {
      const pct = st.total ? Math.min(1, st.copied / st.total) : 0;
      if (!box.querySelector('.ring')) {
        box.innerHTML = `<div class="ring"><svg viewBox="0 0 120 120"><circle class="ring-bg" cx="60" cy="60" r="54"/><circle class="ring-fg" cx="60" cy="60" r="54" stroke-dasharray="${RING}"/></svg>
          <div class="ring-text"><b data-copy-size></b></div></div>
          <div class="v-copy-meta"><span>${esc(t('video_copying'))}</span>${f.duration ? `<span>${icon('clock')} ${hhmm(f.duration)}</span>` : ''}</div>
          <button class="btn small ghost" type="button" data-copy="cancel">${icon('x')} ${esc(t('cancel'))}</button>`;
        box.querySelector('[data-copy="cancel"]').onclick = async () => {
          job.cancelled = true; V.copy = null;
          try { await PG.api('DELETE', `/api/media/${f.id}/video`); } catch (e) { /* the copy stops anyway */ }
          if (V && V.slide === slide) message(t('video_cancelled'), retryBtn());
        };
      }
      box.querySelector('.ring-fg').style.strokeDashoffset = String(RING * (1 - pct));
      box.querySelector('[data-copy-size]').textContent = copySize(st.copied, st.total);
    };
    try {
      let st = await PG.api('POST', `/api/media/${f.id}/video`);
      let idle = 0;
      for (;;) {
        if (stop()) { box.remove(); return null; }
        if (st.state === 'ready') { box.remove(); V.copy = null; return st.url; }
        if (st.state === 'error') { message(st.message || t('video_failed'), retryBtn()); V.copy = null; return null; }
        // "none" for a long time: the background process did not start
        idle = st.state === 'none' ? idle + 1 : 0;
        if (idle > 12) { message(t('video_failed'), retryBtn()); V.copy = null; return null; }
        draw(st);
        await sleep(700);
        if (stop()) { box.remove(); return null; }
        st = await PG.api('GET', `/api/media/${f.id}/video`);
      }
    } catch (e) {
      if (stop()) { box.remove(); return null; }
      if (PG.storage.isDown(e)) { box.remove(); storageCheck(f, slide, () => {}); return null; }
      message(e.message || t('video_failed'), retryBtn());
      V.copy = null;
      return null;
    }
  }

  function playVideo(f, slide, frame, url) {
    const video = document.createElement('video');
    video.className = 'video-js vjs-big-play-centered';
    video.setAttribute('controls', '');
    video.setAttribute('playsinline', '');
    video.setAttribute('preload', 'metadata');
    if (f.thumb) video.setAttribute('poster', f.thumb);
    const src = document.createElement('source');
    src.src = url; src.type = MIME[f.ext] || 'video/mp4';
    video.appendChild(src);
    frame.appendChild(video);
    frame.dataset.video = '1';
    layout(frame);
    V.player = window.videojs(video, { language: PG.cfg.locale, controlBar: { pictureInPictureToggle: true }, playbackRates: [0.5, 1, 1.5, 2] });
    V.player.on('ended', () => { if (V && V.play) next(); });
    // The local copy may have been cleaned up meanwhile (long pause): copy it again, but only once.
    V.player.on('error', () => storageCheck(f, slide, () => { if (V && cur() === f && !f.recopied) { f.recopied = true; show(V.index, 0); } }));
    V.player.on('loadedmetadata', () => {
      const vw = V && V.player && V.player.videoWidth();
      if (vw && !f.w) { frame.dataset.w = vw; frame.dataset.h = V.player.videoHeight(); layout(frame); }
    });
    layout(frame);
  }

  // The original did not load: if the storage is down, wait in the viewer and show this photo again when it is back.
  async function storageCheck(f, slide, otherwise) {
    const ok = await PG.storage.check(true);
    if (!V || V.slide !== slide) return;
    if (ok) { otherwise(); return; }
    stopSlideshow(V);
    const box = document.createElement('div');
    slide.appendChild(box);
    PG.storage.wait(box, { onBack: () => { if (V && cur() === f) show(V.index, 0); } });
  }

  function noPreview(f, slide) {
    slide.querySelectorAll('.v-frame, .v-spinner').forEach((e) => e.remove());
    const box = document.createElement('div');
    box.className = 'v-nopreview';
    box.innerHTML = `${f.thumb ? `<img src="${esc(f.thumb)}" alt="" style="max-height:40vh;border-radius:12px;margin-bottom:10px">` : icon(f.type === 'video' ? 'file-video' : 'file')}
      <p>${esc(t('no_preview'))}</p><a class="btn primary" href="${esc(f.dl || '#')}" download>${icon('download')} ${esc(t('download'))}</a>`;
    slide.appendChild(box);
    if (V && V.play) armSlideshow();
  }

  // Size and place the frame: fit the (rotated) picture inside the stage, then apply zoom and pan.
  function layout(frame) {
    frame = frame || (V && V.slide && V.slide.querySelector('.v-frame'));
    if (!frame || !V) return;
    const f = cur();
    const rot = frame.dataset.video ? 0 : (f.rot || 0);
    const pad = window.innerWidth < 760 ? 8 : 60;
    const SW = V.stage.clientWidth - pad * 2, SH = V.stage.clientHeight - 20;
    const iw = +frame.dataset.w, ih = +frame.dataset.h;
    const side = rot % 180 !== 0;
    const bw = side ? ih : iw, bh = side ? iw : ih;
    const s = Math.min(SW / bw, SH / bh, frame.dataset.video ? 10 : Math.max(1, 1600 / Math.max(iw, ih)));
    const fw = Math.round(iw * s), fh = Math.round(ih * s);
    frame.style.width = fw + 'px'; frame.style.height = fh + 'px';
    frame.style.position = 'absolute';
    frame.style.left = (V.stage.clientWidth - fw) / 2 + 'px';
    frame.style.top = (V.stage.clientHeight - fh) / 2 + 'px';
    frame.style.transform = `translate(${V.px}px, ${V.py}px) rotate(${rot}deg) scale(${V.zoom})`;
    frame.querySelectorAll(':scope > img').forEach((im) => { im.style.width = fw + 'px'; im.style.height = fh + 'px'; });
    if (V.player) V.player.dimensions(fw, fh);
    placeCaption(fw, fh, rot);
  }
  function onResize() { layout(); }

  // Description shown on the picture (also in the slideshow).
  function caption(f) {
    if (!V || !V.slide) return;
    let c = V.slide.querySelector('.v-caption');
    if (!f.desc) { if (c) c.remove(); return; }
    if (!c) { c = document.createElement('div'); c.className = 'v-caption'; V.slide.appendChild(c); }
    c.textContent = f.desc;
    c.dir = PG.textDir(f.desc);
    layout();
  }
  // Put the caption at the bottom edge of the visible (turned, zoomed) picture.
  function placeCaption(fw, fh, rot) {
    const c = V && V.slide && V.slide.querySelector('.v-caption');
    if (!c) return;
    const side = rot % 180 !== 0;
    const vw = (side ? fh : fw) * V.zoom, vh = (side ? fw : fh) * V.zoom;
    const H = V.stage.clientHeight, W = V.stage.clientWidth;
    const bottom = Math.max(8, (H - vh) / 2 - V.py + 10);
    c.style.bottom = Math.min(bottom, H - 40) + 'px';
    c.style.maxWidth = Math.max(160, Math.min(vw - 24, W - 24)) + 'px';
    c.style.left = W / 2 + V.px + 'px';
  }

  // ---------------------------------------------------------------- navigation
  function next() { if (V) show(V.index + 1, 1); }
  function prev() { if (V) show(V.index - 1, -1); }

  function onKey(e) {
    if (!V || e.target.matches('input, textarea, select')) return;
    const ltr = !PG.fa;
    const k = e.key;
    if (k === 'Escape') { if (V.drawing) setDrawing(false); else close(); }
    else if (k === 'ArrowRight') ltr ? next() : prev();
    else if (k === 'ArrowLeft') ltr ? prev() : next();
    else if (k === ' ' && !V.player) { e.preventDefault(); toggleSlideshow(); }
    else if (k === 'f') action('fav');
    else if (k === 'i') action('panel');
    else if (k === 'r') action('rotr');
    else if (k === '+' || k === '=') zoomBy(1.4);
    else if (k === '-') zoomBy(1 / 1.4);
    else return;
    e.preventDefault();
  }

  function zoomBy(m, cx, cy) {
    const frame = V.slide && V.slide.querySelector('.v-frame');
    if (!frame || frame.dataset.video) return;
    const z = Math.min(8, Math.max(1, V.zoom * m));
    if (cx !== undefined) {
      // keep the point under the cursor in place
      const r = V.stage.getBoundingClientRect();
      const ox = cx - r.left - r.width / 2 - V.px, oy = cy - r.top - r.height / 2 - V.py;
      V.px -= ox * (z / V.zoom - 1); V.py -= oy * (z / V.zoom - 1);
    }
    V.zoom = z;
    if (z === 1) { V.px = 0; V.py = 0; }
    layout(frame);
  }

  function bind() {
    document.addEventListener('keydown', onKey);
    window.addEventListener('resize', onResize);
    V.el.addEventListener('click', (e) => {
      const b = e.target.closest('[data-v]');
      if (b) { if (b.dataset.v === 'dl') return; e.preventDefault(); action(b.dataset.v); }
    });
    V.el.querySelector('[data-v-int]').addEventListener('change', (e) => { PG.setPref('slide_interval', +e.target.value); if (V.play) armSlideshow(); });
    const st = V.stage;
    st.addEventListener('wheel', (e) => { e.preventDefault(); zoomBy(e.deltaY < 0 ? 1.2 : 1 / 1.2, e.clientX, e.clientY); }, { passive: false });
    st.addEventListener('dblclick', (e) => { if (V.drawing || e.target.closest('button, a, .v-nav, .video-js')) return; V.zoom > 1 ? zoomBy(1 / V.zoom) : zoomBy(2.5, e.clientX, e.clientY); });

    // pointer: pan when zoomed, swipe to change, pinch to zoom, draw face boxes
    const pts = new Map();
    let start = null, pinch = null, draw = null;
    st.addEventListener('pointerdown', (e) => {
      if (e.target.closest('.v-nav, .vjs-control-bar, .vjs-big-play-button, .facebox span, .v-nopreview a')) return;
      if (V.player && !V.drawing) return;
      st.setPointerCapture(e.pointerId);
      pts.set(e.pointerId, { x: e.clientX, y: e.clientY });
      if (V.drawing) {
        const p = toImage(e.clientX, e.clientY);
        if (p) draw = { x0: p.x, y0: p.y, box: newBox() };
        return;
      }
      if (pts.size === 2) {
        const [a, b] = [...pts.values()];
        pinch = { d: Math.hypot(a.x - b.x, a.y - b.y), z: V.zoom };
      } else start = { x: e.clientX, y: e.clientY, px: V.px, py: V.py, t: Date.now() };
    });
    st.addEventListener('pointermove', (e) => {
      if (!pts.has(e.pointerId)) return;
      pts.set(e.pointerId, { x: e.clientX, y: e.clientY });
      if (draw) {
        const p = toImage(e.clientX, e.clientY);
        if (p) setBox(draw.box, Math.min(draw.x0, p.x), Math.min(draw.y0, p.y), Math.abs(p.x - draw.x0), Math.abs(p.y - draw.y0));
        return;
      }
      if (pinch && pts.size === 2) {
        const [a, b] = [...pts.values()];
        V.zoom = Math.min(8, Math.max(1, pinch.z * Math.hypot(a.x - b.x, a.y - b.y) / pinch.d));
        if (V.zoom === 1) { V.px = 0; V.py = 0; }
        layout();
      } else if (start && V.zoom > 1) {
        V.px = start.px + e.clientX - start.x; V.py = start.py + e.clientY - start.y;
        layout();
      }
    });
    const end = (e) => {
      if (!pts.has(e.pointerId)) return;
      pts.delete(e.pointerId);
      if (draw) {
        const d = draw; draw = null;
        const b = d.box.dataset;
        if (+b.w > 0.02 && +b.h > 0.02) askPerson({ x: +b.x, y: +b.y, w: +b.w, h: +b.h }, d.box);
        else d.box.remove();
        return;
      }
      if (pinch) { if (pts.size < 2) pinch = null; return; }
      if (start && V.zoom === 1) {
        const dx = e.clientX - start.x, dy = e.clientY - start.y;
        if (Math.abs(dx) > 60 && Math.abs(dx) > Math.abs(dy) * 1.5 && Date.now() - start.t < 800) {
          (dx < 0) !== PG.fa ? next() : prev();
        } else if (dy > 120 && Math.abs(dy) > Math.abs(dx) * 2) close();
      }
      start = null;
    };
    st.addEventListener('pointerup', end);
    st.addEventListener('pointercancel', end);
    document.addEventListener('fullscreenchange', () => setTimeout(() => layout(), 120));
  }

  async function action(a) {
    const f = cur();
    if (a === 'close') close();
    else if (a === 'next') next();
    else if (a === 'prev') prev();
    else if (a === 'panel') { V.panelOpen = !V.panelOpen; header(f); setTimeout(() => layout(), 30); }
    else if (a === 'fs') { if (document.fullscreenElement) document.exitFullscreen(); else V.el.requestFullscreen && V.el.requestFullscreen().catch(() => {}); }
    else if (a === 'play') toggleSlideshow();
    else if (a === 'del') {
      if (!V.opts.onDelete || !(await V.opts.onDelete(f)) || !V) return; // the gallery already removed it from V.files
      if (!V.files.length) close(); else show(Math.min(V.index, V.files.length - 1), 1);
    }
    else if (a === 'face') { setDrawing(!V.drawing); if (!V.panelOpen) action('panel'); }
    else if (a === 'fav') {
      try {
        const r = await PG.api('POST', `/api/media/${f.id}/favorite`, { on: !f.fav });
        f.fav = r.fav; header(f); V.opts.onUpdate && V.opts.onUpdate(f);
      } catch (e) { PG.error(e); }
    } else if (a === 'rotl' || a === 'rotr') {
      try {
        const r = await PG.api('POST', `/api/media/${f.id}/rotate`, { dir: a === 'rotr' ? 'cw' : 'ccw' });
        // keep the current image element (same file), only the turn changes
        Object.assign(f, { rot: r.rot, thumb: r.thumb, dl: r.dl, tw: r.tw, th: r.th });
        header(f); layout(); V.opts.onUpdate && V.opts.onUpdate(f);
      } catch (e) { PG.error(e); }
    }
  }

  // ---------------------------------------------------------------- slideshow
  function toggleSlideshow() {
    if (!V) return;
    V.play = !V.play;
    const b = V.el.querySelector('[data-v="play"]');
    b.classList.toggle('active', V.play);
    b.querySelector('use').setAttribute('href', PG.cfg.icons + '#i-' + (V.play ? 'pause' : 'play'));
    if (V.play) { if (V.el.requestFullscreen && !document.fullscreenElement && window.innerWidth < 900) V.el.requestFullscreen().catch(() => {}); armSlideshow(); }
    else stopSlideshow(V);
  }
  function armSlideshow() {
    if (!V || !V.play) return;
    clearTimeout(V.timer);
    if (V.player) return; // a video plays to its end first
    const sec = PG.pref('slide_interval', 5);
    const bar = V.el.querySelector('.v-slidebar');
    bar.classList.remove('run'); bar.style.transitionDuration = '0s';
    void bar.offsetWidth;
    bar.style.transitionDuration = sec + 's'; bar.classList.add('run');
    V.timer = setTimeout(next, sec * 1000);
  }
  function stopSlideshow(v) {
    clearTimeout(v.timer);
    const bar = v.el.querySelector('.v-slidebar');
    if (bar) { bar.classList.remove('run'); bar.style.transitionDuration = '0s'; }
  }

  // ---------------------------------------------------------------- details panel
  async function loadInfo(f) {
    try {
      const d = await PG.api('GET', '/api/media/' + f.id);
      info[f.id] = d;
      ['url', 'dl', 'thumb', 'rot', 'w', 'h', 'taken', 'city', 'country', 'desc', 'fav', 'type', 'ext', 'playable', 'name', 'mtime', 'size', 'tw', 'th', 'duration', 'folder', 'folderName', 'gps', 'ready', 'camera']
        .forEach((k) => { if (d[k] !== undefined) f[k] = d[k]; });
      f.tags = d.tagList.map((x) => x.name);
      f.persons = d.personList.map((x) => x.name);
    } catch (e) { if (e.status !== 404) PG.error(e); }
  }

  function renderPanel(f) {
    const d = info[f.id];
    const p = V.panel;
    if (!d) { p.innerHTML = `<div class="loading-note">${icon('loader-circle', 'spin')}</div>`; return; }
    const fact = (label, val) => (val ? `<dt>${esc(label)}</dt><dd>${val}</dd>` : '');
    const loc = [d.city, d.country].filter(Boolean).map(esc).join(PG.fa ? '، ' : ', ');
    const map = d.gps ? ` <a href="/map#${d.gps[0]},${d.gps[1]}">${icon('map-pin')} ${esc(t('show_on_map'))}</a>` : '';
    const inf = d.info || {};
    const exifRows = Object.entries(d.exif || {}).map(([k, v]) => `<dt dir="ltr">${esc(k)}</dt><dd dir="ltr">${esc(v)}</dd>`).join('');
    p.innerHTML = `
      <section>
        <h3>${icon('message-square-text')} ${esc(t('description'))}</h3>
        <textarea data-desc maxlength="2000" placeholder="${esc(t('edit_description'))}">${esc(d.desc || '')}</textarea>
        <div class="form-foot" style="margin-top:6px" data-desc-foot hidden><button class="btn small primary" type="button" data-save-desc>${icon('check')} ${esc(t('save'))}</button></div>
      </section>
      <section>
        <h3>${icon('tag')} ${esc(t('tags'))}</h3>
        <div class="taglist" data-tags>${d.tagList.map((x, i) => tagChip(x, i)).join('')}</div>
        <input data-tag-input maxlength="100" placeholder="${esc(t('add_tag'))}">
      </section>
      ${f.type === 'image' ? `<section>
        <h3>${icon('users')} ${esc(t('persons'))}</h3>
        <div class="v-hint" data-draw-hint ${V.drawing ? '' : 'hidden'}>${icon('scan-face')} ${esc(t('draw_face'))}</div>
        <div class="taglist" data-persons>${d.personList.map((x, i) => personChip(x, i)).join('')}</div>
        <div style="display:flex;gap:6px"><input data-person-input maxlength="150" placeholder="${esc(t('person_name'))}">
        <button class="btn small ghost" type="button" data-draw title="${esc(t('add_person'))}">${icon('scan-face')}</button></div>
      </section>` : ''}
      <section>
        <h3>${icon('info')} ${esc(t('info'))}</h3>
        <dl class="facts">
          ${fact(t('taken'), d.taken ? PG.dateHtml(d.taken) : '')}
          ${fact(t('location'), loc || map ? loc + map : '')}
          ${fact(t('folder'), d.folder != null ? `<a href="#" data-open-folder="${esc(d.folder)}">${icon('folder')} ${esc(d.folderName)}</a>` : '')}
          ${fact(t('dimensions'), d.w ? `<span dir="ltr">${PG.digits(d.w + ' × ' + d.h)} px</span>` : '')}
          ${fact(t('duration'), d.duration ? PG.duration(d.duration) : '')}
          ${fact(t('size'), PG.size(d.size))}
          ${fact(t('modified'), PG.dateHtml(d.mtime))}
          ${fact(t('changed'), PG.dateHtml(d.ctime))}
          ${fact(t('path'), d.path ? `<code dir="ltr">/${esc(d.path)}</code>` : '')}
        </dl>
      </section>
      ${Object.keys(inf).length ? `<section><h3>${icon('camera')} ${esc(t('camera_info'))}</h3><dl class="facts">
        ${['make', 'model', 'lens', 'exposure', 'aperture', 'iso', 'focal', 'flash', 'software', 'codec', 'mime', 'altitude'].map((k) => fact(t(k), inf[k] != null ? esc(inf[k]) : '')).join('')}
      </dl></section>` : ''}
      ${exifRows ? `<details><summary>${esc(t('all_exif'))}</summary><dl class="facts exif-table">${exifRows}</dl></details>` : ''}`;
    bindPanel(f, d);
  }
  const tagChip = (x, i) => `<span class="tag c${i % 6}">${esc(x.name)}<button type="button" data-rm-tag="${x.id}" title="${esc(t('remove'))}">${icon('x')}</button></span>`;
  const personChip = (x, i) => `<span class="tag person c${(i + 3) % 6}" data-person="${x.id}">${icon(x.w ? 'scan-face' : 'user', 'pi')}${esc(x.name)}<button type="button" data-rm-person="${x.id}" title="${esc(t('remove'))}">${icon('x')}</button></span>`;

  function bindPanel(f, d) {
    const p = V.panel;
    const ta = p.querySelector('[data-desc]');
    ta.addEventListener('input', () => (p.querySelector('[data-desc-foot]').hidden = false));
    p.querySelector('[data-save-desc]').addEventListener('click', async () => {
      try {
        const r = await PG.api('POST', `/api/media/${f.id}/description`, { description: ta.value });
        d.desc = f.desc = r.desc; p.querySelector('[data-desc-foot]').hidden = true;
        caption(f); V.opts.onUpdate && V.opts.onUpdate(f);
        PG.toast(icon('check') + ' ' + esc(t('saved')), { timeout: 1800 });
      } catch (e) { PG.error(e); }
    });
    const tagInput = p.querySelector('[data-tag-input]');
    PG.suggest(tagInput, {
      kind: 'tags', allowNew: true,
      onPick: async (name) => {
        try {
          const r = await PG.api('POST', `/api/media/${f.id}/tags`, { name });
          if (!d.tagList.some((x) => x.id === r.id)) d.tagList.push(r);
          f.tags = d.tagList.map((x) => x.name);
          tagInput.value = '';
          p.querySelector('[data-tags]').innerHTML = d.tagList.map((x, i) => tagChip(x, i)).join('');
        } catch (e) { PG.error(e); }
      },
    });
    const personInput = p.querySelector('[data-person-input]');
    if (personInput) {
      PG.suggest(personInput, { kind: 'persons', allowNew: true, onPick: (name) => { personInput.value = ''; savePerson(f, d, name, null); } });
      p.querySelector('[data-draw]').addEventListener('click', () => setDrawing(!V.drawing));
    }
    p.addEventListener('click', async (e) => {
      const rt = e.target.closest('[data-rm-tag]');
      if (rt) {
        try {
          await PG.api('DELETE', `/api/media/${f.id}/tags/${rt.dataset.rmTag}`);
          d.tagList = d.tagList.filter((x) => String(x.id) !== rt.dataset.rmTag);
          f.tags = d.tagList.map((x) => x.name);
          rt.closest('.tag').remove();
        } catch (er) { PG.error(er); }
        return;
      }
      const rp = e.target.closest('[data-rm-person]');
      if (rp) {
        try {
          await PG.api('DELETE', `/api/media/${f.id}/persons/${rp.dataset.rmPerson}`);
          d.personList = d.personList.filter((x) => String(x.id) !== rp.dataset.rmPerson);
          f.persons = d.personList.map((x) => x.name);
          rp.closest('.tag').remove(); drawFaces();
        } catch (er) { PG.error(er); }
        return;
      }
      const pc = e.target.closest('[data-person]');
      if (pc) {
        V.slide.querySelectorAll('.facebox').forEach((b) => b.classList.toggle('hl', b.dataset.id === pc.dataset.person));
        return;
      }
      const of = e.target.closest('[data-open-folder]');
      if (of) {
        e.preventDefault();
        const path = of.dataset.openFolder;
        const cb = V.opts.onFolder;
        close(true); // keep the history entry: the folder page replaces it
        if (cb) cb(path);
        else location.href = '/browse/' + path.split('/').map(encodeURIComponent).join('/');
      }
    });
  }

  async function savePerson(f, d, name, rect) {
    try {
      const r = await PG.api('POST', `/api/media/${f.id}/persons`, Object.assign({ name }, rect || {}));
      d.personList.push(r);
      f.persons = d.personList.map((x) => x.name);
      if (V && cur() === f) {
        V.panel.querySelector('[data-persons]').innerHTML = d.personList.map((x, i) => personChip(x, i)).join('');
        drawFaces();
      }
    } catch (e) { PG.error(e); drawFaces(); }
  }

  // ---------------------------------------------------------------- face boxes
  function drawFaces() {
    if (!V || !V.slide) return;
    const f = cur();
    const frame = V.slide.querySelector('.v-frame');
    const d = info[f.id];
    if (!frame || !d || frame.dataset.video) return;
    frame.querySelectorAll('.facebox:not(.new)').forEach((b) => b.remove());
    d.personList.filter((x) => x.w).forEach((x) => {
      const b = document.createElement('div');
      b.className = 'facebox';
      b.dataset.id = x.id;
      b.style.cssText = `left:${x.x * 100}%;top:${x.y * 100}%;width:${x.w * 100}%;height:${x.h * 100}%`;
      b.innerHTML = `<span>${esc(x.name)}</span>`;
      // keep the name label readable when the picture is turned
      b.querySelector('span').style.transform = `rotate(${-(f.rot || 0)}deg)`;
      frame.appendChild(b);
    });
  }
  function setDrawing(on) {
    if (!V) return;
    V.drawing = on;
    const frame = V.slide && V.slide.querySelector('.v-frame');
    if (frame) { frame.classList.toggle('drawing', on); frame.classList.toggle('show-faces', on); }
    V.stage.style.cursor = on ? 'crosshair' : '';
    V.el.querySelector('[data-v="face"]').classList.toggle('active', on);
    const h = V.panel.querySelector('[data-draw-hint]');
    if (h) h.hidden = !on;
    if (on && V.zoom !== 1) zoomBy(1 / V.zoom);
  }
  // Screen point -> position on the picture (0..1), taking rotation, zoom and pan into account.
  function toImage(cx, cy) {
    const frame = V.slide && V.slide.querySelector('.v-frame');
    if (!frame) return null;
    const r = frame.getBoundingClientRect();
    const dx = cx - (r.left + r.width / 2), dy = cy - (r.top + r.height / 2);
    const a = ((cur().rot || 0) * Math.PI) / 180;
    const x = dx * Math.cos(a) + dy * Math.sin(a), y = -dx * Math.sin(a) + dy * Math.cos(a);
    const fw = frame.offsetWidth * V.zoom, fh = frame.offsetHeight * V.zoom;
    return { x: Math.min(1, Math.max(0, x / fw + 0.5)), y: Math.min(1, Math.max(0, y / fh + 0.5)) };
  }
  function newBox() {
    const frame = V.slide.querySelector('.v-frame');
    const b = document.createElement('div');
    b.className = 'facebox new';
    frame.appendChild(b);
    return b;
  }
  function setBox(b, x, y, w, h) {
    Object.assign(b.dataset, { x, y, w, h });
    b.style.cssText = `left:${x * 100}%;top:${y * 100}%;width:${w * 100}%;height:${h * 100}%`;
  }
  function askPerson(rect, box) {
    const f = cur(), d = info[f.id];
    const pop = document.createElement('div');
    pop.style.cssText = 'position:absolute;top:100%;margin-top:6px;inset-inline-start:0;z-index:5;background:var(--surface);padding:8px;border-radius:12px;box-shadow:0 8px 24px rgba(0,0,0,.15);width:230px;display:flex;flex-direction:column;gap:6px';
    pop.style.transform = `rotate(${-(f.rot || 0)}deg)`;
    pop.innerHTML = `<input maxlength="150" placeholder="${esc(t('person_name'))}"><button type="button" class="btn small ghost">${esc(t('cancel'))}</button>`;
    box.appendChild(pop);
    pop.addEventListener('pointerdown', (e) => e.stopPropagation());
    const inp = pop.querySelector('input');
    const done = () => { box.remove(); };
    PG.suggest(inp, { kind: 'persons', allowNew: true, onPick: (name) => { done(); if (d) savePerson(f, d, name, rect); } });
    pop.querySelector('button').addEventListener('click', done);
    inp.addEventListener('keydown', (e) => { if (e.key === 'Escape') { e.stopPropagation(); done(); } });
    setTimeout(() => inp.focus(), 30);
  }

  window.PGViewer = { open, close };
})();
