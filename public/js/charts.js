/* =====================================================================
   KIVU Tracker — public/js/charts.js
   Small dependency-free canvas chart renderer (bar / line / pie) used by
   the report viewer. Draws axes, grid, legend and value labels, and
   redraws on container resize.
   ===================================================================== */
'use strict';

const Charts = (function () {
  const PALETTE = ['#2f9cf4', '#2ecc71', '#f1c40f', '#e74c3c', '#9b59b6', '#16a085', '#e67e22', '#7fd4ff'];

  function niceMax(v) {
    if (v <= 0) return 1;
    const pow = Math.pow(10, Math.floor(Math.log10(v)));
    const n = v / pow;
    const step = n <= 1 ? 1 : n <= 2 ? 2 : n <= 2.5 ? 2.5 : n <= 5 ? 5 : 10;
    return step * pow;
  }
  function shortNum(v) {
    if (Math.abs(v) >= 1000000) return (v / 1000000).toFixed(1) + 'M';
    if (Math.abs(v) >= 1000) return (v / 1000).toFixed(1) + 'k';
    return Math.abs(v) >= 10 || v === Math.floor(v) ? String(Math.round(v)) : v.toFixed(1);
  }

  function draw(canvas, chart) {
    const dpr = window.devicePixelRatio || 1;
    const rect = canvas.getBoundingClientRect();
    const w = Math.max(240, rect.width || canvas.clientWidth || 320);
    const hgt = Math.max(150, rect.height || canvas.clientHeight || 220);
    canvas.width = Math.floor(w * dpr);
    canvas.height = Math.floor(hgt * dpr);
    const ctx = canvas.getContext('2d');
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.clearRect(0, 0, w, hgt);
    ctx.font = '11px "Segoe UI", system-ui, sans-serif';

    const labels = (chart.labels || []).map(String);
    const series = (chart.series || []).filter(s => s && s.data);
    if (!labels.length || !series.length) {
      ctx.fillStyle = '#6b7b96';
      ctx.fillText('No data for this period', 12, 24);
      return;
    }
    let maxVal = 0;
    series.forEach(s => s.data.forEach(v => { maxVal = Math.max(maxVal, Number(v) || 0); }));
    const top = niceMax(maxVal);
    const padL = 46, padR = 12, padT = 18, padB = 46;
    const plotW = Math.max(20, w - padL - padR), plotH = Math.max(20, hgt - padT - padB);

    ctx.strokeStyle = '#263149';
    ctx.fillStyle = '#8ea2c0';
    ctx.lineWidth = 1;
    for (let i = 0; i <= 4; i++) {
      const y = padT + plotH - (plotH * i / 4);
      ctx.beginPath(); ctx.moveTo(padL, y); ctx.lineTo(padL + plotW, y); ctx.stroke();
      ctx.fillText(shortNum(top * i / 4), 6, y + 4);
    }
    const step = plotW / labels.length;

    if (chart.type === 'pie') {
      let total = 0;
      series[0].data.forEach(v => { total += Math.abs(Number(v) || 0); });
      let start = -Math.PI / 2;
      const cx = padL + plotW / 2, cy = padT + plotH / 2, r = Math.min(plotW, plotH) / 2 - 4;
      series[0].data.forEach((v, i) => {
        const ang = total > 0 ? (Math.abs(Number(v) || 0) / total) * Math.PI * 2 : 0;
        ctx.beginPath(); ctx.moveTo(cx, cy);
        ctx.arc(cx, cy, r, start, start + ang);
        ctx.closePath();
        ctx.fillStyle = PALETTE[i % PALETTE.length];
        ctx.fill();
        start += ang;
      });
      return;
    }

    if (chart.type === 'line') {
      series.forEach((s, si) => {
        ctx.strokeStyle = PALETTE[si % PALETTE.length];
        ctx.lineWidth = 2;
        ctx.beginPath();
        s.data.forEach((v, i) => {
          const x = padL + step * (i + 0.5);
          const y = padT + plotH - (Math.max(0, Number(v) || 0) / top) * plotH;
          if (i === 0) ctx.moveTo(x, y); else ctx.lineTo(x, y);
        });
        ctx.stroke();
        ctx.fillStyle = PALETTE[si % PALETTE.length];
        s.data.forEach((v, i) => {
          const x = padL + step * (i + 0.5);
          const y = padT + plotH - (Math.max(0, Number(v) || 0) / top) * plotH;
          ctx.beginPath(); ctx.arc(x, y, 2.4, 0, Math.PI * 2); ctx.fill();
        });
      });
    } else {
      const groups = series.length;
      const barW = Math.max(2, (step * 0.7) / groups - 2);
      labels.forEach((label, i) => {
        series.forEach((s, si) => {
          const v = Math.max(0, Number(s.data[i]) || 0);
          const bh = top > 0 ? (v / top) * plotH : 0;
          const x = padL + step * i + (step * 0.15) + si * (barW + 2);
          const y = padT + plotH - bh;
          ctx.fillStyle = PALETTE[si % PALETTE.length];
          ctx.fillRect(x, y, barW, bh);
          if (labels.length <= 14 && v > 0) {
            ctx.fillStyle = '#dfe6f2';
            ctx.save();
            ctx.translate(x + barW / 2, y - 3);
            ctx.textAlign = 'center';
            ctx.fillText(shortNum(v), 0, 0);
            ctx.restore();
          }
        });
      });
    }

    ctx.fillStyle = '#8ea2c0';
    ctx.textAlign = 'center';
    const skip = Math.ceil(labels.length / Math.max(3, Math.floor(plotW / 54)));
    labels.forEach((label, i) => {
      if (i % skip !== 0) return;
      const x = padL + step * (i + 0.5);
      const text = label.length > 14 ? label.slice(5) : label;
      ctx.save();
      ctx.translate(x, padT + plotH + 12);
      if (labels.length > 8) ctx.rotate(-0.5);
      ctx.fillText(text, 0, 0);
      ctx.restore();
    });

    ctx.textAlign = 'left';
    let lx = padL;
    series.forEach((s, si) => {
      ctx.fillStyle = PALETTE[si % PALETTE.length];
      ctx.fillRect(lx, hgt - 18, 9, 9);
      ctx.fillStyle = '#8ea2c0';
      ctx.fillText(s.name || ('series ' + (si + 1)), lx + 13, hgt - 10);
      lx += 22 + ctx.measureText(s.name || '').width + 10;
    });
  }

  /* Renders a list of charts into a container (one .chart-box each). */
  function render(container, charts) {
    container.innerHTML = '';
    const boxes = [];
    (charts || []).forEach(chart => {
      const box = el('div', { class: 'chart-box' });
      box.appendChild(el('h4', null, h(chart.title || chart.id || 'Chart')));
      const canvas = el('canvas', { class: 'chart' });
      box.appendChild(canvas);
      container.appendChild(box);
      const redraw = () => { try { draw(canvas, chart); } catch (e) { /* never break the page */ } };
      redraw();
      boxes.push({ canvas: canvas, chart: chart, redraw: redraw });
    });
    window.addEventListener('resize', debounce(() => boxes.forEach(b => b.redraw()), 200));
    setTimeout(() => boxes.forEach(b => b.redraw()), 120);   // canvas sizing after layout
    return boxes;
  }

  return { draw: draw, render: render, palette: PALETTE };
})();
