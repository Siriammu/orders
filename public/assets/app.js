'use strict';

/*
 * Order Insights dashboard. Vanilla JS, no third-party code.
 * Security: all API data is written with textContent / createElement,
 * never innerHTML, so a product named "<img onerror=…>" stays text.
 */
(() => {
  const $ = (sel) => document.querySelector(sel);
  const usd = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' });
  const int = new Intl.NumberFormat('en-US');
  const pct = new Intl.NumberFormat('en-US', { style: 'percent', minimumFractionDigits: 1, maximumFractionDigits: 1 });

  const els = {
    main: $('#dashboard'),
    status: $('#status'),
    filter: $('#filter'),
    from: $('#from'),
    to: $('#to'),
    filterError: $('#filter-error'),
    chart: $('#chart'),
    dailyBody: $('#daily-table tbody'),
    topBody: $('#top-table tbody'),
    upload: $('#upload'),
    file: $('#file'),
    fileName: $('#file-name'),
    uploadBtn: $('#upload-btn'),
    report: $('#import-report'),
  };

  let inflight = null;
  let lastDaily = [];

  // ---------- API ----------

  async function api(path, options = {}) {
    const res = await fetch(path, { headers: { Accept: 'application/json' }, ...options });
    let body = null;
    try { body = await res.json(); } catch { /* non-JSON body */ }
    if (!res.ok) {
      const err = new Error(body?.error?.message || `The server answered ${res.status}.`);
      err.status = res.status;
      err.body = body;
      throw err;
    }
    return body;
  }

  function rangeQuery() {
    const p = new URLSearchParams();
    if (els.from.value) p.set('from', els.from.value);
    if (els.to.value) p.set('to', els.to.value);
    const s = p.toString();
    return s ? `?${s}` : '';
  }

  // ---------- dashboard ----------

  async function loadDashboard() {
    inflight?.abort();
    const controller = new AbortController();
    inflight = controller;

    const q = rangeQuery();
    const sep = q ? '&' : '?';
    setBusy(true);
    showStatus('Loading figures…');

    try {
      const opts = { signal: controller.signal };
      const [summary, top, daily] = await Promise.all([
        api(`/api/summary${q}`, opts),
        api(`/api/top-products${q}${sep}limit=5`, opts),
        api(`/api/daily-revenue${q}`, opts),
      ]);
      renderKpis(summary);
      renderDaily(daily);
      renderTop(top);

      if (summary.orders === 0 && summary.refundRate === 0) {
        showStatus(q
          ? 'No completed or refunded orders in these dates. Widen the range or show all dates.'
          : 'No orders yet. Import an orders CSV to fill the dashboard.');
      } else {
        hideStatus();
      }
    } catch (err) {
      if (err.name === 'AbortError') return;
      renderKpis(null);
      renderDaily([]);
      renderTop([]);
      showStatus(`Couldn’t load the dashboard: ${err.message}`, { error: true, retry: true });
    } finally {
      if (inflight === controller) {
        setBusy(false);
        inflight = null;
      }
    }
  }

  function setBusy(busy) {
    els.main.setAttribute('aria-busy', String(busy));
  }

  function showStatus(message, { error = false, retry = false } = {}) {
    const node = $('#tpl-status').content.cloneNode(true);
    node.querySelector('.status-msg').textContent = message;
    const btn = node.querySelector('.retry');
    btn.hidden = !retry;
    btn.addEventListener('click', loadDashboard);
    els.status.replaceChildren(node);
    els.status.classList.toggle('error', error);
    els.status.setAttribute('role', error ? 'alert' : 'status');
    els.status.hidden = false;
  }

  function hideStatus() {
    els.status.hidden = true;
    els.status.replaceChildren();
  }

  function renderKpis(s) {
    const values = s
      ? {
          revenue: usd.format(s.revenue),
          orders: int.format(s.orders),
          aov: usd.format(s.aov),
          units: int.format(s.units),
          refundRate: pct.format(s.refundRate),
        }
      : {};
    document.querySelectorAll('[data-kpi]').forEach((dd) => {
      dd.textContent = values[dd.dataset.kpi] ?? '–';
    });
  }

  function emptyRow(tbody, cols, text) {
    const tr = document.createElement('tr');
    const td = document.createElement('td');
    td.colSpan = cols;
    td.className = 'empty';
    td.textContent = text;
    tr.append(td);
    tbody.replaceChildren(tr);
  }

  function cell(text, className) {
    const td = document.createElement('td');
    td.textContent = text;
    if (className) td.className = className;
    return td;
  }

  function renderTop(rows) {
    if (!rows.length) return emptyRow(els.topBody, 4, 'No completed sales in this range.');
    els.topBody.replaceChildren(...rows.map((r) => {
      const tr = document.createElement('tr');
      tr.append(cell(r.product), cell(r.sku), cell(int.format(r.units), 'num'), cell(usd.format(r.revenue), 'num'));
      return tr;
    }));
  }

  function renderDaily(rows) {
    lastDaily = rows;
    if (!rows.length) {
      emptyRow(els.dailyBody, 2, 'No days to show.');
      const p = document.createElement('p');
      p.className = 'note';
      p.textContent = 'No completed sales in this range.';
      els.chart.replaceChildren(p);
      return;
    }
    els.dailyBody.replaceChildren(...rows.map((r) => {
      const tr = document.createElement('tr');
      tr.append(cell(r.date), cell(usd.format(r.revenue), 'num'));
      return tr;
    }));
    els.chart.replaceChildren(barChart(rows));
  }

  function barChart(rows) {
    const NS = 'http://www.w3.org/2000/svg';
    // Draw at the container's real width so labels stay legible on phones.
    const W = Math.round(Math.max(300, Math.min(1000, els.chart.clientWidth || 720)));
    const H = W < 500 ? 200 : 240, padL = 8, padR = 8, padT = 22, padB = 28;
    const max = Math.max(...rows.map((r) => r.revenue), 1);
    const step = (W - padL - padR) / rows.length;
    const barW = Math.max(2, Math.min(56, step * 0.7));
    const labelEvery = Math.ceil(rows.length / Math.max(3, Math.floor(W / 52)));
    const showValues = step >= 44;
    const total = rows.reduce((a, r) => a + r.revenue, 0);

    const svg = document.createElementNS(NS, 'svg');
    svg.setAttribute('viewBox', `0 0 ${W} ${H}`);
    svg.setAttribute('role', 'img');
    svg.setAttribute('aria-label',
      `Daily revenue from ${rows[0].date} to ${rows[rows.length - 1].date}, ${usd.format(total)} in total.`);

    const el = (name, attrs, text) => {
      const n = document.createElementNS(NS, name);
      for (const [k, v] of Object.entries(attrs)) n.setAttribute(k, String(v));
      if (text !== undefined) n.textContent = text;
      return n;
    };

    svg.append(el('line', { class: 'axis', x1: padL, x2: W - padR, y1: H - padB, y2: H - padB }));

    rows.forEach((r, i) => {
      const h = r.revenue > 0 ? Math.max(2, (r.revenue / max) * (H - padT - padB)) : 2;
      const x = padL + i * step + (step - barW) / 2;
      const y = H - padB - h;
      const bar = el('rect', { class: r.revenue > 0 ? 'bar' : 'bar zero', x, y, width: barW, height: h, rx: 2 });
      bar.append(el('title', {}, `${r.date}: ${usd.format(r.revenue)}`));
      svg.append(bar);

      if (showValues && r.revenue > 0) {
        svg.append(el('text', { class: 'value', x: x + barW / 2, y: y - 6, 'text-anchor': 'middle' },
          usd.format(r.revenue).replace('.00', '')));
      }
      if (i % labelEvery === 0) {
        svg.append(el('text', { x: x + barW / 2, y: H - 10, 'text-anchor': 'middle' }, r.date.slice(5)));
      }
    });
    return svg;
  }

  // ---------- date filter ----------

  els.filter.addEventListener('submit', (e) => {
    e.preventDefault();
    if (els.from.value && els.to.value && els.from.value > els.to.value) {
      els.filterError.textContent = '“From” must be on or before “To”.';
      els.filterError.hidden = false;
      return;
    }
    els.filterError.hidden = true;
    loadDashboard();
  });

  $('#clear').addEventListener('click', () => {
    els.from.value = '';
    els.to.value = '';
    els.filterError.hidden = true;
    loadDashboard();
  });

  // ---------- upload ----------

  els.file.addEventListener('change', () => {
    const f = els.file.files[0];
    els.fileName.textContent = f ? `${f.name} (${(f.size / 1024).toFixed(1)} KB)` : 'One row per order line, up to 5 MB';
  });

  els.upload.addEventListener('submit', async (e) => {
    e.preventDefault();
    const f = els.file.files[0];
    if (!f) {
      renderUploadError('Choose a CSV file first.');
      return;
    }
    if (f.size > 5 * 1024 * 1024) {
      renderUploadError('That file is larger than 5 MB. Split it and import the parts.');
      return;
    }

    const data = new FormData();
    data.append('file', f);
    els.uploadBtn.disabled = true;
    els.uploadBtn.textContent = 'Importing…';
    els.report.replaceChildren(statusLine('Importing and validating rows…'));

    try {
      const result = await api('/api/uploads', { method: 'POST', body: data });
      renderReport(result);
      loadDashboard();
    } catch (err) {
      renderUploadError(err.message, err.body?.error?.missingColumns);
    } finally {
      els.uploadBtn.disabled = false;
      els.uploadBtn.textContent = 'Import CSV';
    }
  });

  function statusLine(text) {
    const p = document.createElement('p');
    p.className = 'note';
    p.textContent = text;
    return p;
  }

  function renderUploadError(message, missing) {
    const box = document.createElement('div');
    box.className = 'alert';
    box.setAttribute('role', 'alert');
    box.textContent = message;
    if (Array.isArray(missing) && missing.length) {
      const p = document.createElement('p');
      p.textContent = `Add these columns to the header: ${missing.join(', ')}.`;
      box.append(p);
    }
    els.report.replaceChildren(box);
  }

  function renderReport(r) {
    const wrap = document.createElement('div');
    wrap.className = 'report';

    const dl = document.createElement('dl');
    dl.className = 'report-counts';
    [['Rows', r.totalRows, ''], ['Imported', r.imported, ''], ['Rejected', r.rejected, r.rejected ? 'bad' : '']]
      .forEach(([label, value, cls]) => {
        const d = document.createElement('div');
        if (cls) d.className = cls;
        const dt = document.createElement('dt');
        dt.textContent = label;
        const dd = document.createElement('dd');
        dd.textContent = int.format(value);
        d.append(dt, dd);
        dl.append(d);
      });
    wrap.append(dl);

    if (!r.errors.length) {
      const ok = statusLine('Every row was valid.');
      ok.classList.add('ok');
      wrap.append(ok);
    } else {
      const table = document.createElement('table');
      table.className = 'rejects';
      const cap = document.createElement('caption');
      cap.className = 'note';
      cap.textContent = 'Rejected rows were skipped. Row numbers match a spreadsheet (header is row 1).';
      const thead = document.createElement('thead');
      const htr = document.createElement('tr');
      ['Row', 'Reason'].forEach((h) => {
        const th = document.createElement('th');
        th.scope = 'col';
        th.textContent = h;
        htr.append(th);
      });
      thead.append(htr);
      const tbody = document.createElement('tbody');
      r.errors.forEach((e) => {
        const tr = document.createElement('tr');
        tr.append(cell(String(e.row)), cell(e.reason));
        tbody.append(tr);
      });
      table.append(cap, thead, tbody);
      wrap.append(table);
    }
    els.report.replaceChildren(wrap);
  }

  let resizeTimer;
  window.addEventListener('resize', () => {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(() => { if (lastDaily.length) els.chart.replaceChildren(barChart(lastDaily)); }, 150);
  });

  loadDashboard();
})();
