/* Personal Gallery — photo map (Leaflet + markercluster, stored locally; tiles from OpenStreetMap). */
(function () {
  'use strict';
  const PG = window.PG;
  document.addEventListener('DOMContentLoaded', async () => {
    const el = document.getElementById('map');
    if (!el || !window.L) return;
    const map = L.map(el, { worldCopyJump: true }).setView([32.4, 53.7], 5);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19, attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
    }).addTo(map);
    let points = [];
    try { points = (await PG.api('GET', '/api/map')).points; } catch (e) { PG.error(e); return; }
    document.getElementById('map-count').textContent = PG.num(points.length);
    if (!points.length) { document.getElementById('map-empty').hidden = false; return; }
    const cluster = L.markerClusterGroup({ showCoverageOnHover: false, maxClusterRadius: 50 });
    const markers = points.map((p, i) => {
      const html = p[3] ? `<img class="map-thumb" src="${PG.esc(p[3])}" alt="" loading="lazy">` : `<span class="map-thumb" style="display:grid;place-items:center">${PG.icon('image')}</span>`;
      const m = L.marker([p[1], p[2]], { icon: L.divIcon({ html, className: '', iconSize: [46, 46], iconAnchor: [23, 23] }) });
      m.on('click', () => {
        const b = map.getBounds();
        const inView = points.filter((q) => b.contains([q[1], q[2]])).slice(0, 500);
        const files = inView.map((q) => ({ id: q[0], thumb: q[3], name: q[4], ready: !!q[3] }));
        window.PGViewer.open(files, Math.max(0, inView.indexOf(p)), {});
      });
      m._pgIndex = i;
      return m;
    });
    cluster.addLayers(markers);
    map.addLayer(cluster);
    const h = location.hash.match(/^#(-?[\d.]+),(-?[\d.]+)/);
    if (h) map.setView([+h[1], +h[2]], 15);
    else map.fitBounds(cluster.getBounds(), { padding: [30, 30], maxZoom: 14 });
  });
})();
