/* =====================================================================
   KIVU Tracker — public/js/mapcore.js
   One small map API used by every page. If Leaflet is available (CDN) it
   is used; otherwise a built-in canvas slippy-map takes over so the whole
   console keeps working without downloading any library (only the map
   TILES need the network — geometry, tracks and geofences do not).

       const map = MapCore.create(el, {lat, lon, zoom, tiles})
       map.setUnits([...]) / map.updateUnit(u) / map.setTrack(points)
       map.showGeofences(list) / map.drawPreview(shape) / map.on('click', fn)
   ===================================================================== */
'use strict';

const MapCore = (function () {
  function create(container, opts) {
    opts = opts || {};
    const hasLeaflet = (typeof L !== 'undefined' && L.map);
    const impl = hasLeaflet ? leafletImpl(container, opts) : canvasImpl(container, opts);
    impl.backend = hasLeaflet ? 'leaflet' : 'canvas';
    return impl;
  }

  /* =====================================================================
     Leaflet backend (preferred when the CDN is reachable)
     ===================================================================== */
  function leafletImpl(container, opts) {
    const map = L.map(container, { zoomControl: true, attributionControl: false })
      .setView([opts.lat || -1.2921, opts.lon || 36.8219], opts.zoom || 11);
    let tileLayer = null;
    if (opts.tiles !== false) {
      tileLayer = L.tileLayer(opts.tiles || 'https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(map);
    }
    const unitsLayer = L.layerGroup().addTo(map);
    const fenceLayer = L.layerGroup().addTo(map);
    const previewLayer = L.layerGroup().addTo(map);
    const trackLayer = L.layerGroup().addTo(map);
    const markers = {};
    const clickHandlers = [];
    let trackLine = null, playerMarker = null;
    map.on('click', e => clickHandlers.forEach(f => f({ lat: e.latlng.lat, lon: e.latlng.lng })));

    function markerIcon(u) {
      const color = u.color || '#2f9cf4';
      const glyph = assetGlyph(u.asset_type);
      const initial = String(u.name || '?').replace(/[^A-Za-z0-9]/g, '').slice(0, 2) || '?';
      const course = Number(u.course) || 0;
      /* the marker rotates with the heading; the glyph stays upright */
      const inner = glyph
        ? '<span class="g" style="display:block;transform:rotate(' + (-course) + 'deg)">' + glyph + '</span>'
        : '<span>' + initial + '</span>';
      const html = '<div class="unit-marker' + (glyph ? ' has-glyph' : '')
        + '" style="background:' + color + ';transform:rotate(' + course + 'deg)">' + inner + '</div>';
      return L.divIcon({ className: '', html: html, iconSize: [26, 26], iconAnchor: [13, 13] });
    }
    function popupHtml(u) {
      return '<div class="mk-pop"><b>' + h(u.name || '') + '</b><table>'
        + '<tr><td>Status</td><td>' + h((STATUS[u.status] || STATUS.never).label) + '</td></tr>'
        + '<tr><td>Speed</td><td>' + fmt.speed(u.speed) + '</td></tr>'
        + '<tr><td>Updated</td><td>' + h(fmt.dt(u.last_time)) + '</td></tr>'
        + '<tr><td>Location</td><td>' + h(u.address || '') + '</td></tr></table></div>';
    }
    return {
      raw: function () { return map; },
      setTiles: function (url) {
        if (tileLayer) map.removeLayer(tileLayer);
        tileLayer = L.tileLayer(url, { maxZoom: 19 }).addTo(map);
      },
      setView: function (lat, lon, zoom) { map.setView([lat, lon], zoom || map.getZoom()); },
      getView: function () { const c = map.getCenter(); return { lat: c.lat, lon: c.lng, zoom: map.getZoom() }; },
      fitPoints: function (points) {
        if (!points || !points.length) return;
        map.fitBounds(L.latLngBounds(points.map(p => [p.lat, p.lon])).pad(0.15));
      },
      on: function (evt, fn) { if (evt === 'click') clickHandlers.push(fn); },
      clearUnits: function () { unitsLayer.clearLayers(); Object.keys(markers).forEach(k => delete markers[k]); },
      setUnits: function (list, onClick) {
        Object.keys(markers).forEach(id => {
          if (!list.some(u => String(u.id) === String(id))) {
            unitsLayer.removeLayer(markers[id]);
            delete markers[id];
          }
        });
        list.forEach(u => {
          if (u.lat === null || u.lat === undefined) return;
          const existing = markers[u.id];
          if (existing) {
            existing.setLatLng([u.lat, u.lon]);
            existing.setIcon(markerIcon(u));
            existing.setPopupContent(popupHtml(u));
          } else {
            const m = L.marker([u.lat, u.lon], { icon: markerIcon(u) }).addTo(unitsLayer);
            m.bindPopup(popupHtml(u));
            if (onClick) m.on('click', () => onClick(u));
            markers[u.id] = m;
          }
        });
      },
      updateUnit: function (u, onClick) { this.setUnits([u], onClick); },
      setTrack: function (points, style) {
        if (trackLine) { trackLayer.removeLayer(trackLine); trackLine = null; }
        if (!points || points.length < 2) return;
        style = style || {};
        trackLine = L.polyline(points.map(p => [p.lat, p.lon]),
          { color: style.color || '#2f9cf4', weight: style.weight || 3, opacity: 0.9 }).addTo(trackLayer);
      },
      setTrackPlayer: function (point) {
        if (!point) {
          if (playerMarker) { trackLayer.removeLayer(playerMarker); playerMarker = null; }
          return;
        }
        if (!playerMarker) {
          playerMarker = L.circleMarker([point.lat, point.lon],
            { radius: 7, color: '#fff', weight: 2, fillColor: '#e74c3c', fillOpacity: 1 }).addTo(trackLayer);
        } else playerMarker.setLatLng([point.lat, point.lon]);
      },
      showGeofences: function (list, onClick) {
        fenceLayer.clearLayers();
        (list || []).forEach(g => {
          const color = g.color || '#2f9cf4';
          let shape = null;
          if (g.type === 'circle') shape = L.circle(g.points[0], { radius: Number(g.radius_m) || 0, color: color });
          else if (g.type === 'polyline') shape = L.polyline(g.points, { color: color, weight: g.width || 3 });
          else shape = L.polygon(g.points, { color: color, weight: g.width || 2, fillOpacity: 0.12 });
          if (onClick) shape.on('click', () => onClick(g));
          if (g.name) shape.bindPopup('<div class="mk-pop"><b>' + h(g.name) + '</b><br>' + h(g.description || '') + '</div>');
          shape.addTo(fenceLayer);
        });
      },
      drawPreview: function (shape) {
        previewLayer.clearLayers();
        if (!shape || !shape.points || !shape.points.length) return;
        const color = shape.color || '#f1c40f';
        if (shape.type === 'circle') {
          L.circle(shape.points[0], { radius: Number(shape.radius_m) || 0, color: color, dashArray: '5,4' }).addTo(previewLayer);
        } else if (shape.type === 'polygon' && shape.points.length >= 2) {
          L.polygon(shape.points, { color: color, dashArray: '5,4', fillOpacity: 0.1 }).addTo(previewLayer);
        } else if (shape.points.length >= 2) {
          L.polyline(shape.points, { color: color, dashArray: '5,4' }).addTo(previewLayer);
        }
        shape.points.forEach(p => L.circleMarker(p, { radius: 4, color: color, fillOpacity: 1 }).addTo(previewLayer));
      },
      clearPreview: function () { previewLayer.clearLayers(); },
      invalidateSize: function () { map.invalidateSize(); },
      drawMode: function (on) {
        map.getContainer().style.cursor = on ? 'crosshair' : '';
        if (on) map.doubleClickZoom.disable(); else map.doubleClickZoom.enable();
      }
    };
  }

  /* =====================================================================
     Canvas backend — dependency-free slippy map used when Leaflet is not
     available (offline installs). Identical API to the Leaflet backend.
     ===================================================================== */
  function canvasImpl(container, opts) {
    container.innerHTML = '';
    container.style.position = 'relative';
    container.style.overflow = 'hidden';
    container.style.cursor = 'grab';
    const world = el('div', { style: 'position:absolute;left:0;top:0;right:0;bottom:0' });
    const tileLayer = el('div', { style: 'position:absolute;left:0;top:0;right:0;bottom:0' });
    const canvas = el('canvas', { style: 'position:absolute;left:0;top:0' });
    const markerLayer = el('div', { style: 'position:absolute;left:0;top:0;right:0;bottom:0;pointer-events:none' });
    world.appendChild(tileLayer); world.appendChild(canvas); world.appendChild(markerLayer);
    const ctrls = el('div', { class: 'map-legend' });
    const zin = el('button', { class: 'btn xs', text: '+' });
    const zout = el('button', { class: 'btn xs', text: '-' });
    ctrls.appendChild(zin); ctrls.appendChild(zout);
    container.appendChild(world); container.appendChild(ctrls);

    let center = { lat: opts.lat || -1.2921, lon: opts.lon || 36.8219 };
    let zoom = Math.round(opts.zoom || 11);
    let tileUrl = opts.tiles || 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
    const clickHandlers = [];
    const markerEls = {};
    const spot = { units: [], onClick: null, track: [], trackStyle: {}, player: null, fences: [], preview: null };
    let dragging = null;

    const size = () => ({ w: container.clientWidth || 640, h: container.clientHeight || 400 });
    function project(lat, lon, z) {
      const scale = 256 * Math.pow(2, z);
      const x = (lon + 180) / 360 * scale;
      const s = Math.max(-0.99999, Math.min(0.99999, Math.sin(lat * Math.PI / 180)));
      const y = (0.5 - Math.log((1 + s) / (1 - s)) / (4 * Math.PI)) * scale;
      return { x: x, y: y };
    }
    function unproject(x, y, z) {
      const scale = 256 * Math.pow(2, z);
      return {
        lat: 180 / Math.PI * Math.atan(Math.sinh(Math.PI - 2 * Math.PI * y / scale)),
        lon: x / scale * 360 - 180
      };
    }
    function origin() {
      const s = size(), c = project(center.lat, center.lon, zoom);
      return { x: c.x - s.w / 2, y: c.y - s.h / 2 };
    }
    function toScreen(lat, lon) {
      const o = origin(), p = project(lat, lon, zoom);
      return { x: p.x - o.x, y: p.y - o.y };
    }
    function toLatLon(x, y) {
      const o = origin();
      return unproject(x + o.x, y + o.y, zoom);
    }
    function metersPerPixel(lat) {
      return 156543.03392 * Math.cos(lat * Math.PI / 180) / Math.pow(2, zoom);
    }
    function renderTiles() {
      const s = size(), o = origin(), ts = 256;
      tileLayer.innerHTML = '';
      const max = Math.pow(2, zoom);
      const x0 = Math.floor(o.x / ts), x1 = Math.floor((o.x + s.w) / ts);
      const y0 = Math.max(0, Math.floor(o.y / ts)), y1 = Math.min(max - 1, Math.floor((o.y + s.h) / ts));
      for (let x = x0; x <= x1; x++) {
        for (let y = y0; y <= y1; y++) {
          const wrapX = ((x % max) + max) % max;
          tileLayer.appendChild(el('img', {
            src: tileUrl.replace('{z}', zoom).replace('{x}', wrapX).replace('{y}', y),
            style: 'position:absolute;width:256px;height:256px;left:' + (x * ts - o.x) + 'px;top:'
              + (y * ts - o.y) + 'px;user-select:none;-webkit-user-drag:none'
          }));
        }
      }
    }

    function drawShape(ctx, shape, dash) {
      const pts = (shape.points || []).map(p => toScreen(Number(p[0]), Number(p[1])));
      ctx.lineWidth = shape.width || 2;
      ctx.strokeStyle = shape.color || '#2f9cf4';
      ctx.fillStyle = shape.color || '#2f9cf4';
      if (dash) ctx.setLineDash([6, 5]);
      if (shape.type === 'circle' && pts.length) {
        const r = (Number(shape.radius_m) || 0) / Math.max(0.01, metersPerPixel(Number(shape.points[0][0])));
        ctx.globalAlpha = 0.12;
        ctx.beginPath(); ctx.arc(pts[0].x, pts[0].y, Math.max(2, r), 0, Math.PI * 2); ctx.fill();
        ctx.globalAlpha = 1; ctx.stroke();
      } else if (pts.length >= 2) {
        ctx.beginPath();
        pts.forEach((p, i) => { if (i === 0) ctx.moveTo(p.x, p.y); else ctx.lineTo(p.x, p.y); });
        if (shape.type === 'polygon') {
          ctx.closePath();
          ctx.globalAlpha = 0.12; ctx.fill(); ctx.globalAlpha = 1;
        }
        ctx.stroke();
      }
      ctx.setLineDash([]);
      ctx.globalAlpha = 1;
    }
    function renderVectors() {
      const s = size(), dpr = window.devicePixelRatio || 1;
      canvas.width = Math.floor(s.w * dpr);
      canvas.height = Math.floor(s.h * dpr);
      canvas.style.width = s.w + 'px';
      canvas.style.height = s.h + 'px';
      const ctx = canvas.getContext('2d');
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      ctx.clearRect(0, 0, s.w, s.h);
      (spot.fences || []).forEach(g => drawShape(ctx, { type: g.type, points: g.points || [],
        radius_m: g.radius_m, color: g.color, width: g.width }, false));
      if (spot.preview) {
        drawShape(ctx, { type: spot.preview.type, points: spot.preview.points || [],
          radius_m: spot.preview.radius_m, color: spot.preview.color || '#f1c40f', width: 2 }, true);
      }
      if (spot.track && spot.track.length > 1) {
        ctx.strokeStyle = spot.trackStyle.color || '#2f9cf4';
        ctx.lineWidth = spot.trackStyle.weight || 3;
        ctx.beginPath();
        spot.track.forEach((p, i) => {
          const q = toScreen(p.lat, p.lon);
          if (i === 0) ctx.moveTo(q.x, q.y); else ctx.lineTo(q.x, q.y);
        });
        ctx.stroke();
      }
      if (spot.player) {
        const q = toScreen(spot.player.lat, spot.player.lon);
        ctx.beginPath(); ctx.arc(q.x, q.y, 7, 0, Math.PI * 2);
        ctx.fillStyle = '#e74c3c'; ctx.fill();
        ctx.lineWidth = 2; ctx.strokeStyle = '#fff'; ctx.stroke();
      }
    }
    function renderMarkers() {
      const s = size(), alive = {};
      (spot.units || []).forEach(u => {
        if (u.lat === null || u.lat === undefined) return;
        alive[u.id] = true;
        const p = toScreen(u.lat, u.lon);
        let node = markerEls[u.id];
        if (!node) {
          node = el('div', { class: 'unit-marker', style: 'position:absolute;pointer-events:auto;cursor:pointer' });
          node.appendChild(el('span'));
          markerLayer.appendChild(node);
          markerEls[u.id] = node;
        }
        node.onclick = function (ev) {
          ev.stopPropagation();
          if (spot.onClick) spot.onClick(u);
        };
        node.style.display = (p.x < -60 || p.y < -60 || p.x > s.w + 60 || p.y > s.h + 60) ? 'none' : '';
        node.style.background = u.color || '#2f9cf4';
        node.style.left = p.x + 'px';
        node.style.top = p.y + 'px';
        node.style.transform = 'rotate(' + (Number(u.course) || 0) + 'deg)';
        const glyph = assetGlyph(u.asset_type);
        node.firstChild.textContent = glyph || (String(u.name || '').replace(/[^A-Za-z0-9]/g, '').slice(0, 2) || '?');
        /* keep the glyph upright inside the rotating marker */
        node.firstChild.style.transform = glyph
          ? 'rotate(' + (-(Number(u.course) || 0)) + 'deg)' : '';
        if (glyph) node.classList.add('has-glyph'); else node.classList.remove('has-glyph');
        node.title = u.name + ' \u2014 ' + fmt.speed(u.speed) + ' \u2014 ' + (u.address || '');
      });
      Object.keys(markerEls).forEach(id => { if (!alive[id]) { markerEls[id].remove(); delete markerEls[id]; } });
    }
    function render() { renderTiles(); renderVectors(); renderMarkers(); }

    /* ---- interaction: drag to pan, wheel/buttons to zoom, click to pick -- */
    function zoomBy(d) { zoom = Math.max(3, Math.min(19, zoom + d)); render(); }
    let down = null;
    container.addEventListener('mousedown', e => {
      if (e.button !== 0) return;
      down = { x: e.clientX, y: e.clientY, moved: false };
      container.style.cursor = 'grabbing';
    });
    window.addEventListener('mousemove', e => {
      if (!down) return;
      const dx = e.clientX - down.x, dy = e.clientY - down.y;
      if (Math.abs(dx) > 3 || Math.abs(dy) > 3) down.moved = true;
      world.style.transform = 'translate(' + dx + 'px,' + dy + 'px)';
    });
    window.addEventListener('mouseup', e => {
      if (!down) return;
      const dx = e.clientX - down.x, dy = e.clientY - down.y;
      const moved = down.moved;
      down = null;
      container.style.cursor = 'grab';
      if (moved) {
        const o = origin(), s = size();
        center = unproject(o.x - dx + s.w / 2, o.y - dy + s.h / 2, zoom);
        world.style.transform = '';
        render();
      } else {
        const r = container.getBoundingClientRect();
        const p = toLatLon(e.clientX - r.left, e.clientY - r.top);
        clickHandlers.forEach(f => f(p));
      }
    });
    container.addEventListener('wheel', e => { e.preventDefault(); zoomBy(e.deltaY < 0 ? 1 : -1); }, { passive: false });
    zin.addEventListener('click', () => zoomBy(1));
    zout.addEventListener('click', () => zoomBy(-1));
    let touchStart = null;
    container.addEventListener('touchstart', e => {
      if (e.touches.length !== 1) return;
      touchStart = { x: e.touches[0].clientX, y: e.touches[0].clientY, moved: false };
    }, { passive: true });
    container.addEventListener('touchmove', e => {
      if (!touchStart || e.touches.length !== 1) return;
      const dx = e.touches[0].clientX - touchStart.x, dy = e.touches[0].clientY - touchStart.y;
      if (Math.abs(dx) > 4 || Math.abs(dy) > 4) touchStart.moved = true;
      world.style.transform = 'translate(' + dx + 'px,' + dy + 'px)';
      e.preventDefault();
    }, { passive: false });
    container.addEventListener('touchend', e => {
      if (!touchStart) return;
      const dx = e.changedTouches[0].clientX - touchStart.x, dy = e.changedTouches[0].clientY - touchStart.y;
      const moved = touchStart.moved;
      touchStart = null;
      if (moved) {
        const o = origin(), s = size();
        center = unproject(o.x - dx + s.w / 2, o.y - dy + s.h / 2, zoom);
        world.style.transform = '';
        render();
      } else {
        const r = container.getBoundingClientRect();
        const p = toLatLon(e.changedTouches[0].clientX - r.left, e.changedTouches[0].clientY - r.top);
        clickHandlers.forEach(f => f(p));
      }
    });
    window.addEventListener('resize', debounce(() => render(), 200));

    render();
    return {
      raw: function () { return { center: center, zoom: zoom }; },
      setTiles: function (url) { if (url) { tileUrl = url; render(); } },
      setView: function (lat, lon, z) {
        center = { lat: lat, lon: lon };
        if (z) zoom = Math.round(z);
        render();
      },
      getView: function () { return { lat: center.lat, lon: center.lon, zoom: zoom }; },
      fitPoints: function (points) {
        if (!points || !points.length) return;
        const s = size();
        let minLat = 90, maxLat = -90, minLon = 180, maxLon = -180;
        points.forEach(p => {
          minLat = Math.min(minLat, p.lat); maxLat = Math.max(maxLat, p.lat);
          minLon = Math.min(minLon, p.lon); maxLon = Math.max(maxLon, p.lon);
        });
        center = { lat: (minLat + maxLat) / 2, lon: (minLon + maxLon) / 2 };
        if (minLat === maxLat && minLon === maxLon) { zoom = 14; render(); return; }
        for (let z = 18; z >= 3; z--) {
          zoom = z;
          const a = project(minLat, minLon, z), b = project(maxLat, maxLon, z);
          if (Math.abs(b.x - a.x) < s.w * 0.85 && Math.abs(b.y - a.y) < s.h * 0.85) break;
        }
        render();
      },
      on: function (evt, fn) { if (evt === 'click') clickHandlers.push(fn); },
      clearUnits: function () { spot.units = []; renderMarkers(); },
      setUnits: function (list, onClick) { spot.units = list || []; spot.onClick = onClick || null; renderMarkers(); },
      updateUnit: function (u, onClick) {
        spot.units = spot.units.filter(x => String(x.id) !== String(u.id)).concat([u]);
        if (onClick) spot.onClick = onClick;
        renderMarkers();
      },
      setTrack: function (points, style) { spot.track = points || []; spot.trackStyle = style || {}; renderVectors(); },
      setTrackPlayer: function (point) { spot.player = point || null; renderVectors(); },
      showGeofences: function (list) { spot.fences = list || []; renderVectors(); },
      drawPreview: function (shape) { spot.preview = shape || null; renderVectors(); },
      clearPreview: function () { spot.preview = null; renderVectors(); },
      invalidateSize: function () { render(); },
      drawMode: function (on) { container.style.cursor = on ? 'crosshair' : 'grab'; }
    };
  }

  return { create: create, hasLeaflet: () => (typeof L !== 'undefined' && !!L.map) };
})();
