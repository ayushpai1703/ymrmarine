/* Survey report editor (survey_report.php). Data: ajax/survey_reports.php.
   - Form from the template's own fields (ship details, Offhire, BROB, drafts, crew, particulars,
     results, remarks, REPORT sheet) and the three TABLES 54B tank sections (rows added freely).
   - V.C.F. / G.S.V. / metric tons and totals are shown as you type (assets/js/report-calc.js,
     the template's formulas); the Excel file itself always uses the template's formulas.
   - Saves by itself. Offline: the changes are kept on this device and saved when the connection
     is back (a change made by someone else meanwhile is never overwritten without asking).
   - "Make Excel + PDF" on the server; "Excel on this device" works offline too
     (assets/js/report-offline-xlsx.js). */
(function () {
  'use strict';
  var P = window.SR_PAGE || {};
  var LS = 'ysms_sr_' + P.id, CACHE = 'ysms_sr_get_' + P.id, TPL_CACHE = 'ysms-sr-templates';
  var S = { cfg: null, report: null, data: null, version: 0, canEdit: false, errors: {}, files: [], dirty: false, saving: false, timer: null, offlineMode: false };
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function f3(v) { return v === null || v === undefined || v === '' ? '' : (+v).toFixed(3); }
  function store(k, v) { try { if (v === null) localStorage.removeItem(k); else localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} }
  function read(k) { try { return JSON.parse(localStorage.getItem(k) || 'null'); } catch (e) { return null; } }
  function toast(icon, title) { if (window.Swal) Swal.fire({ toast: true, position: 'top-end', icon: icon, title: title, showConfirmButton: false, timer: icon === 'success' ? 2000 : 4500 }); }
  function post(d) {
    var f = new FormData(); f.append('csrf_token', P.csrf); Object.keys(d).forEach(function (k) { f.append(k, d[k]); });
    return fetch(P.api, { method: 'POST', body: f, credentials: 'same-origin', headers: { 'X-CSRF-Token': P.csrf } })
      .then(function (r) { return r.json().then(function (j) { j._status = r.status; return j; }).catch(function () { return { ok: false, _status: r.status, error: r.status === 403 ? 'Session expired — log in again.' : 'Server error (' + r.status + ').' }; }); });
  }

  // ---------- network state ----------
  function online() { return navigator.onLine !== false; }
  function netState() {
    var el = $('[data-net]');
    el.className = 'sr-net ' + (online() ? 'on' : 'off');
    el.innerHTML = online() ? '<i class="fa-solid fa-wifi"></i> Online' : '<i class="fa-solid fa-plane"></i> Offline — saved on this device';
    $$('[data-online-only]').forEach(function (b) { b.disabled = !online() || !S.canEdit; });
  }
  window.addEventListener('online', function () { netState(); if (S.dirty) saveNow(); });
  window.addEventListener('offline', netState);
  function saveState(text, cls) { var el = $('[data-save-state]'); el.className = 'sr-save ' + (cls || ''); el.textContent = text; }

  // ---------- load ----------
  function load() {
    netState();
    return fetch(P.api + '?a=get&id=' + P.id, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (res) {
      if (!res.ok) { $('[data-form]').innerHTML = '<div class="sr-note err">' + esc(res.error || 'Report not found.') + '</div>'; return; }
      store(CACHE, res);
      start(res, false);
    }).catch(function () {
      var res = read(CACHE);
      if (!res) { $('[data-form]').innerHTML = '<div class="sr-note err"><i class="fa-solid fa-plane"></i> You are offline and this report has not been opened on this device before. Open it once while online to use it offline.</div>'; return; }
      start(res, true);
    });
  }
  function start(res, fromCache) {
    S.cfg = res.config; S.report = res.report; S.canEdit = res.can_edit; S.files = res.files || []; S.offlineMode = fromCache;
    S.version = res.report.version; S.data = normalise(res.report.data);
    var local = read(LS);
    if (local && local.dirty) {
      if (local.base === S.version || fromCache) { S.data = normalise(local.data); S.dirty = true; }
      else return conflictLocal(local, res);
    }
    $('[data-title]').textContent = res.report.vessel;
    $('[data-subtitle]').innerHTML = 'Offhire report · template v' + esc(S.cfg.template.version) + ' (' + esc(S.cfg.template.name) + ') · vessel status ' + esc(res.report.status);
    render();
    saveState(S.dirty ? (online() ? 'Saving…' : 'Changes on this device') : 'Saved', S.dirty ? 'dirty' : '');
    if (S.dirty && online() && !fromCache) saveNow();
    if (online() && !fromCache) { cacheTemplate(); precache(); }
  }
  /** Keep this page and everything it loaded (scripts incl. ExcelJS, styles, fonts, CDN files) for offline use. */
  function precache() {
    if (!('caches' in window)) return;
    var urls = [location.href];
    $$('script[src]').forEach(function (x) { urls.push(x.src); });
    $$('link[rel=stylesheet][href]').forEach(function (x) { urls.push(x.href); });
    try { performance.getEntriesByType('resource').forEach(function (e) { if (/\.(woff2?|ttf|otf|png|jpe?g|svg|webp|css|js)(\?|$)/i.test(e.name)) urls.push(e.name); }); } catch (e) {}
    caches.open('ysms-sr-v1').then(function (c) {
      urls.filter(function (u, i) { return urls.indexOf(u) === i; }).forEach(function (u) {
        var same = new URL(u, location.href).origin === location.origin;
        c.match(u).then(function (hit) {
          if (hit && u !== location.href) return;
          fetch(u, same ? { credentials: 'same-origin' } : { mode: 'no-cors' }).then(function (r) { if (r.ok || r.type === 'opaque') c.put(u, r); }).catch(function () {});
        });
      });
    }).catch(function () {});
  }
  function conflictLocal(local, res) {
    Swal.fire({ icon: 'warning', title: 'Changes made on this device', html: 'This device has changes that were not saved yet, and the report was saved meanwhile by ' + esc(res.report.updated_by || 'someone') + ' (' + esc(String(res.report.updated_at).slice(0, 16)) + ').<br>Which one do you want to keep?',
      showDenyButton: true, confirmButtonText: 'Keep the saved report', denyButtonText: 'Use my device changes', allowOutsideClick: false })
      .then(function (r) {
        if (r.isDenied) { local.base = res.report.version; store(LS, local); } else store(LS, null);
        start(res, false);
      });
  }
  function normalise(d) {
    d = d || {}; d.fields = d.fields || {}; d.tanks = d.tanks || {};
    S.cfg.sections.forEach(function (s) { d.tanks[s.key] = (d.tanks[s.key] || []).map(function (r) { var o = {}; S.cfg.tank_cols.forEach(function (c) { o[c.key] = r[c.key] == null ? '' : String(r[c.key]); }); return o; }); });
    return d;
  }
  function cacheTemplate() { // the template workbook, for the Excel made on this device
    if (!('caches' in window)) return;
    var url = P.api + '?a=template&id=' + P.id + '&t=' + S.cfg.template.id;
    caches.open(TPL_CACHE).then(function (c) { return c.match(url).then(function (hit) { if (!hit) return fetch(url, { credentials: 'same-origin' }).then(function (r) { if (r.ok) c.put(url, r); }); }); }).catch(function () {});
  }

  // ---------- render ----------
  var MONTHS = ['JANUARY', 'FEBRUARY', 'MARCH', 'APRIL', 'MAY', 'JUNE', 'JULY', 'AUGUST', 'SEPTEMBER', 'OCTOBER', 'NOVEMBER', 'DECEMBER'];
  function fieldHtml(f) {
    var v = S.data.fields[f.key] == null ? '' : String(S.data.fields[f.key]), dis = S.canEdit ? '' : ' disabled', inp;
    var attrs = ' data-f="' + f.key + '" data-testid="sr-f-' + f.key + '"' + dis;
    if (f.type === 'long') inp = '<textarea' + attrs + ' maxlength="3000">' + esc(v) + '</textarea>';
    else if (f.type === 'month') inp = '<select' + attrs + '><option value=""></option>' + MONTHS.map(function (m) { return '<option' + (m === v ? ' selected' : '') + '>' + m + '</option>'; }).join('') + '</select>';
    else {
      var t = f.type === 'date' || f.type === 'xdate' ? 'date' : f.type === 'time' ? 'time' : 'text';
      var mode = /^num|int|imo/.test(f.type) ? ' inputmode="decimal"' : '';
      inp = '<input type="' + t + '"' + mode + attrs + ' value="' + esc(v) + '" maxlength="200">';
    }
    return '<label class="sr-f' + (f.type === 'long' ? ' wide' : '') + '" data-fw="' + f.key + '"><span>' + esc(f.label) + '<i>' + esc(f.cell) + '</i></span>' + inp + '<em data-err="' + f.key + '"></em></label>';
  }
  function render() {
    var c = S.cfg, groups = {};
    c.fields.forEach(function (f) { (groups[f.group] = groups[f.group] || []).push(f); });
    var banners = '';
    if (!S.canEdit) banners += '<div class="sr-note warn"><i class="fa-solid fa-lock"></i> View only — ' + (S.report.status === 'Completed' || S.report.status === 'Cancelled' ? 'the vessel is ' + esc(S.report.status) + '.' : 'you can download the files.') + '</div>';
    if (S.offlineMode) banners += '<div class="sr-note warn"><i class="fa-solid fa-plane"></i> Offline copy — changes are kept on this device and saved when you are back online.</div>';
    (c.flags || []).forEach(function (fl) { banners += '<div class="sr-note err"><i class="fa-solid fa-triangle-exclamation"></i> Template check: <b>' + esc(fl.where) + '</b> — ' + esc(fl.text) + '</div>'; });
    $('[data-banners]').innerHTML = banners;
    var order = Object.keys(c.groups), h = '';
    order.forEach(function (g) {
      if (!groups[g]) return;
      if (g === 'report') return;
      h += '<div class="sr-card"><h2>' + esc(c.groups[g]) + (g === 'results' ? ' <small>outside the printed page</small>' : '') + '</h2><div class="sr-grid">' + groups[g].map(fieldHtml).join('') + '</div></div>';
      if (g === 'drafts') h += tanksHtml();
    });
    if (groups.report) h += '<details class="sr-card"><summary>REPORT sheet data <small class="sr-muted">(' + groups.report.length + ' fields, used by other documents)</small></summary><div class="sr-grid" style="margin-top:10px">' + groups.report.map(fieldHtml).join('') + '</div></details>';
    if (h.indexOf('data-tanks') < 0) h = tanksHtml() + h;
    h += summaryHtml() + outputHtml();
    $('[data-form]').innerHTML = h;
    renderErrors(); calcAll(); netState();
  }
  function tanksHtml() {
    return S.cfg.sections.map(function (s) {
      var rows = S.data.tanks[s.key];
      return '<div class="sr-card" data-tanks="' + s.key + '" data-testid="sr-tanks-' + s.key + '"><h2><i class="fa-solid fa-oil-can"></i> ' + esc(s.label) + ' <small>TABLES 54B · ' + rows.length + ' tank' + (rows.length === 1 ? '' : 's') + '</small></h2>' +
        '<div class="sr-tk-wrap"><table class="sr-tk"><thead><tr>' + S.cfg.tank_cols.map(function (tc) { return '<th>' + esc(tc.label) + '</th>'; }).join('') + '<th>V.C.F.</th><th>G.S.V. m³@15°C</th><th>Metric Tons</th><th></th></tr></thead><tbody>' +
        rows.map(function (r, i) { return rowHtml(s.key, r, i); }).join('') + '</tbody><tfoot><tr><td colspan="' + (S.cfg.tank_cols.length + 1) + '">TOTAL</td><td data-tot-gsv="' + s.key + '"></td><td data-tot-mt="' + s.key + '" data-testid="sr-total-' + s.key + '"></td><td></td></tr></tfoot></table></div>' +
        '<div class="sr-tk-f">' + (S.canEdit ? '<button type="button" class="sr-btn sm" data-add="' + s.key + '" data-testid="sr-add-' + s.key + '"><i class="fa-solid fa-plus"></i> Add tank</button>' : '<span></span>') +
        '<small>Tip: copy rows from Excel and paste into a tank name cell. Rows without a temperature are not calculated (as in the template).</small></div></div>';
    }).join('');
  }
  function rowHtml(sec, r, i) {
    var dis = S.canEdit ? '' : ' disabled';
    return '<tr data-row="' + sec + ':' + i + '">' + S.cfg.tank_cols.map(function (tc) {
      return '<td class="' + (tc.key === 'name' ? 'nm' : '') + '"><input data-t="' + sec + ':' + i + ':' + tc.key + '" value="' + esc(r[tc.key]) + '"' + (tc.type.indexOf('num') === 0 ? ' inputmode="decimal"' : '') + dis + ' data-testid="sr-t-' + sec + '-' + i + '-' + tc.key + '"></td>';
    }).join('') + '<td class="calc" data-c="vcf"></td><td class="calc" data-c="gsv"></td><td class="calc mt" data-c="mt"></td><td>' + (S.canEdit ? '<button type="button" class="x" data-del="' + sec + ':' + i + '" title="Remove tank" aria-label="Remove tank"><i class="fa-solid fa-xmark"></i></button>' : '') + '</td></tr>';
  }
  function summaryHtml() {
    return '<div class="sr-card"><h2><i class="fa-solid fa-scale-balanced"></i> Quantities found <small>certificate figures</small></h2><div class="sr-sum">' + S.cfg.sections.map(function (s) {
      return '<div><div class="l">' + esc(s.short) + '</div><div class="v" data-sum="' + s.key + '" data-testid="sr-sum-' + s.key + '">—</div><div class="s">' + esc(s.label) + '</div></div>';
    }).join('') + '</div>' + (S.cfg.calc ? '' : '<div class="sr-note warn" style="margin-top:10px">The template\'s tank formulas are not the standard pattern, so the figures are calculated only when the Excel is made on the server.</div>') + '</div>';
  }
  function outputHtml() {
    return '<div class="sr-card" data-testid="sr-output"><h2><i class="fa-solid fa-file-export"></i> Report files</h2><div class="sr-out">' +
      '<button type="button" class="sr-btn pri" data-gen data-online-only data-testid="sr-generate"><i class="fa-solid fa-file-excel"></i> Make Excel + PDF</button>' +
      '<button type="button" class="sr-btn" data-offline-xlsx data-testid="sr-offline-xlsx"><i class="fa-solid fa-download"></i> Excel on this device (works offline)</button></div>' +
      '<ul class="sr-lim"><li><b>Make Excel + PDF</b>: the company template, filled on the server — the final report.</li>' +
      '<li><b>Excel on this device</b>: same template, filled in this browser (also offline). Kept: all sheets, layout, fonts, borders, merged cells, logo, print area, formulas. Not kept: the arrows on SHIP DETAILS, printer driver settings and three broken old link names (#REF!) — make the final report on the server when online.</li></ul>' +
      '<div class="sr-files" data-files>' + filesHtml() + '</div></div>';
  }
  function filesHtml() {
    if (!S.files.length) return '<span class="sr-muted">No files made yet.</span>';
    return '<table class="sr-t"><thead><tr><th>File</th><th>Made</th><th>Template</th><th></th></tr></thead><tbody>' + S.files.map(function (f) {
      var url = P.api + '?a=file&id=' + f.id;
      return '<tr data-testid="sr-file-' + f.id + '"><td><i class="fa-solid ' + (f.type === 'pdf' ? 'fa-file-pdf" style="color:#dc2626' : 'fa-file-excel" style="color:#16a34a') + '"></i> ' + esc(f.file_name) + ' <small class="sr-muted">' + Math.round(f.file_size / 1024) + ' KB</small></td>' +
        '<td>' + esc(String(f.created_at).slice(0, 16)) + '<br><small class="sr-muted">' + esc(f.created_by_name || '') + '</small></td><td>v' + esc(f.template_version) + '</td>' +
        '<td style="white-space:nowrap"><a href="' + url + '">Download</a>' + (f.type === 'pdf' ? ' · <a href="' + url + '&inline=1" target="_blank" rel="noopener">View</a>' : '') + '</td></tr>';
    }).join('') + '</tbody></table>';
  }
  function renderErrors() {
    $$('.sr-f.bad').forEach(function (e) { e.classList.remove('bad'); });
    $$('[data-err]').forEach(function (e) { e.textContent = ''; });
    $$('.sr-tk input.bad').forEach(function (e) { e.classList.remove('bad'); e.title = ''; });
    Object.keys(S.errors || {}).forEach(function (k) {
      var msg = S.errors[k];
      if (k.indexOf('f:') === 0) { var w = $('[data-fw="' + k.slice(2) + '"]'); if (w) { w.classList.add('bad'); $('[data-err]', w).textContent = msg; } }
      else { var p = k.split(':'), inp = $('[data-t="' + p[1] + ':' + p[2] + ':' + p[3] + '"]'); if (inp) { inp.classList.add('bad'); inp.title = msg; } }
    });
  }

  // ---------- calculation preview ----------
  function calcRow(sec, i) {
    var tr = $('tr[data-row="' + sec + ':' + i + '"]'); if (!tr) return;
    var t = window.ReportCalc && S.cfg.calc ? ReportCalc.tank(S.data.tanks[sec][i], S.cfg.calc) : {};
    $('[data-c="vcf"]', tr).textContent = t.vcf == null ? '' : t.vcf.toFixed(4);
    $('[data-c="gsv"]', tr).textContent = f3(t.gsv);
    $('[data-c="mt"]', tr).textContent = f3(t.mt);
  }
  function calcTotals() {
    if (!window.ReportCalc || !S.cfg.calc) return;
    var res = ReportCalc.all(S.data, S.cfg.sections.map(function (s) { return s.key; }), S.cfg.calc);
    S.cfg.sections.forEach(function (s) {
      var g = $('[data-tot-gsv="' + s.key + '"]'), m = $('[data-tot-mt="' + s.key + '"]'), sum = $('[data-sum="' + s.key + '"]');
      if (g) g.textContent = f3(res.totals[s.key].gsv); if (m) m.textContent = f3(res.totals[s.key].mt);
      if (sum) sum.textContent = f3(res.totals[s.key].mt) + ' MT';
    });
  }
  function calcAll() { S.cfg.sections.forEach(function (s) { S.data.tanks[s.key].forEach(function (r, i) { calcRow(s.key, i); }); }); calcTotals(); }

  // ---------- editing ----------
  document.addEventListener('input', function (ev) {
    var el = ev.target;
    if (el.matches('[data-f]')) { S.data.fields[el.getAttribute('data-f')] = el.value; changed(); return; }
    if (el.matches('[data-t]')) {
      var p = el.getAttribute('data-t').split(':');
      S.data.tanks[p[0]][+p[1]][p[2]] = el.value;
      calcRow(p[0], +p[1]); calcTotals(); changed();
    }
  });
  document.addEventListener('change', function (ev) { if (ev.target.matches('select[data-f]')) { S.data.fields[ev.target.getAttribute('data-f')] = ev.target.value; changed(); } });
  function emptyRow() { var o = {}; S.cfg.tank_cols.forEach(function (c) { o[c.key] = ''; }); return o; }
  function redrawTanks(sec, focus) {
    var card = $('[data-tanks="' + sec + '"]'), tmp = document.createElement('div');
    var all = tanksHtml(); tmp.innerHTML = all;
    card.replaceWith($('[data-tanks="' + sec + '"]', tmp));
    calcAll(); renderErrors();
    if (focus) { var i = $('[data-t="' + focus + '"]'); if (i) i.focus(); }
  }
  document.addEventListener('click', function (ev) {
    var add = ev.target.closest('[data-add]'), del = ev.target.closest('[data-del]');
    if (add) { var s = add.getAttribute('data-add'); S.data.tanks[s].push(emptyRow()); shiftErrors(s, S.data.tanks[s].length, 0); redrawTanks(s, s + ':' + (S.data.tanks[s].length - 1) + ':name'); changed(); }
    if (del) {
      var p = del.getAttribute('data-del').split(':'), row = S.data.tanks[p[0]][+p[1]];
      var go = function () { S.data.tanks[p[0]].splice(+p[1], 1); shiftErrors(p[0], +p[1], -1); redrawTanks(p[0]); changed(); };
      if (Object.keys(row).some(function (k) { return row[k] !== ''; })) Swal.fire({ icon: 'question', title: 'Remove tank "' + (row.name || 'row ' + (+p[1] + 1)) + '"?', showCancelButton: true, confirmButtonText: 'Remove', confirmButtonColor: '#dc2626' }).then(function (r) { if (r.isConfirmed) go(); });
      else go();
    }
    if (ev.target.closest('[data-gen]')) generate();
    if (ev.target.closest('[data-offline-xlsx]')) offlineXlsx();
  });
  function shiftErrors(sec, from, by) { // keep error marks on the right rows after insert / delete
    var out = {};
    Object.keys(S.errors).forEach(function (k) {
      var p = k.split(':');
      if (p[0] === 't' && p[1] === sec && +p[2] >= from) { if (by < 0 && +p[2] === from) return; out['t:' + sec + ':' + (+p[2] + by) + ':' + p[3]] = S.errors[k]; }
      else out[k] = S.errors[k];
    });
    S.errors = out;
  }
  // paste rows copied from Excel into a tank cell
  document.addEventListener('paste', function (ev) {
    var el = ev.target; if (!el.matches || !el.matches('[data-t]')) return;
    var txt = (ev.clipboardData || window.clipboardData).getData('text');
    if (txt.indexOf('\t') < 0 && txt.indexOf('\n') < 0) return;
    ev.preventDefault();
    var p = el.getAttribute('data-t').split(':'), sec = p[0], r0 = +p[1], keys = S.cfg.tank_cols.map(function (c) { return c.key; }), c0 = keys.indexOf(p[2]);
    var lines = txt.replace(/\r/g, '').split('\n').filter(function (l) { return l.trim() !== ''; });
    lines.forEach(function (line, li) {
      var r = r0 + li; if (r >= 60) return;
      while (S.data.tanks[sec].length <= r) S.data.tanks[sec].push(emptyRow());
      line.split('\t').forEach(function (v, ci) { var k = keys[c0 + ci]; if (k) S.data.tanks[sec][r][k] = v.trim(); });
    });
    redrawTanks(sec); changed();
    toast('success', lines.length + ' row' + (lines.length === 1 ? '' : 's') + ' pasted');
  });

  // ---------- saving ----------
  function changed() {
    if (!S.canEdit) return;
    S.dirty = true;
    store(LS, { data: S.data, base: S.version, dirty: true, at: Date.now() });
    saveState(online() ? 'Saving…' : 'Changes on this device', 'dirty');
    clearTimeout(S.timer); S.timer = setTimeout(saveNow, 1200);
  }
  function saveNow() {
    if (!S.dirty || S.saving || !S.canEdit) return Promise.resolve(true);
    if (!online()) { saveState('Changes on this device', 'dirty'); return Promise.resolve(false); }
    S.saving = true;
    var sent = JSON.stringify(S.data);
    return post({ a: 'save', id: P.id, version: S.version, data: sent }).then(function (res) {
      S.saving = false;
      if (res.ok) {
        S.version = res.version; S.errors = res.errors || {};
        if (JSON.stringify(S.data) === sent) { S.dirty = false; store(LS, null); saveState('Saved', ''); }
        else { store(LS, { data: S.data, base: S.version, dirty: true, at: Date.now() }); saveNow(); }
        var c = read(CACHE); if (c) { c.report.version = S.version; c.report.data = S.data; store(CACHE, c); }
        renderErrors();
        var n = Object.keys(S.errors).length; if (n) saveState('Saved · ' + n + ' value' + (n === 1 ? '' : 's') + ' to check', 'err');
        return true;
      }
      if (res._status === 409 && res.server) {
        saveState('Not saved — changed elsewhere', 'err');
        Swal.fire({ icon: 'warning', title: 'Changed by someone else', html: esc(res.error) + '<br>Keep your changes (they replace the other version) or load the saved one?', showDenyButton: true,
          confirmButtonText: 'Keep my changes', denyButtonText: 'Load the saved report', allowOutsideClick: false }).then(function (r) {
          if (r.isConfirmed) { S.version = res.server.version; saveNow(); }
          else { S.version = res.server.version; S.data = normalise(res.server.data); S.dirty = false; store(LS, null); render(); saveState('Saved', ''); }
        });
        return false;
      }
      saveState(res._status === 0 ? 'Changes on this device' : 'Not saved', 'err');
      if (res.error) toast('error', res.error);
      return false;
    }).catch(function () { S.saving = false; saveState('Changes on this device', 'dirty'); return false; });
  }
  window.addEventListener('beforeunload', function (ev) { if (S.dirty && online()) { saveNow(); } });

  // ---------- files ----------
  function generate() {
    var btn = $('[data-gen]'); btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Making…';
    clearTimeout(S.timer);
    post({ a: 'generate', id: P.id, version: S.version, data: JSON.stringify(S.data), pdf: 1 }).then(function (res) {
      btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-file-excel"></i> Make Excel + PDF';
      if (res.version) { S.version = res.version; }
      if (!res.ok) {
        S.errors = res.errors || {}; renderErrors();
        if (res._status === 409) { saveNow(); return; }
        Swal.fire({ icon: 'error', title: 'Report not made', text: res.error || 'Please try again.' }); return;
      }
      S.dirty = false; store(LS, null); saveState('Saved', ''); S.errors = {}; renderErrors();
      S.files = res.files; $('[data-files]').innerHTML = filesHtml();
      var c = read(CACHE); if (c) { c.files = S.files; c.report.version = S.version; c.report.data = S.data; store(CACHE, c); }
      var tot = S.cfg.sections.map(function (s) { var t = res.results.totals[s.key]; return esc(s.short) + ' <b>' + f3(t && t.mt) + '</b> MT'; }).join(' · ');
      Swal.fire({ icon: 'success', title: 'Report made', html: tot + (res.pdf_error ? '<br><small style="color:#b45309">PDF: ' + esc(res.pdf_error) + '</small>' : '') });
    });
  }
  function offlineXlsx() {
    if (!window.ReportOfflineXlsx || !window.ExcelJS) { Swal.fire({ icon: 'error', title: 'Not available', text: 'The Excel tools have not been loaded on this device yet — open the report once while online.' }); return; }
    var url = P.api + '?a=template&id=' + P.id + '&t=' + S.cfg.template.id;
    var get = ('caches' in window ? caches.open(TPL_CACHE).then(function (c) { return c.match(url); }) : Promise.resolve(null)).then(function (hit) {
      if (hit) return hit.arrayBuffer();
      if (!online()) throw new Error('The template workbook is not on this device yet — open the report once while online.');
      return fetch(url, { credentials: 'same-origin' }).then(function (r) { if (!r.ok) throw new Error('Template not available.'); var c2 = r.clone(); if ('caches' in window) caches.open(TPL_CACHE).then(function (c) { c.put(url, c2); }); return r.arrayBuffer(); });
    });
    Swal.fire({ title: 'Making the Excel on this device…', allowOutsideClick: false, didOpen: function () { Swal.showLoading(); } });
    get.then(function (buf) { return ReportOfflineXlsx.build(buf, S.cfg.offline, S.data, S.cfg.calc, S.cfg.sections.map(function (s) { return s.key; })); })
      .then(function (blob) {
        var a = document.createElement('a'), name = (String(S.data.fields.vessel_name || S.report.vessel).toUpperCase() + ' - OFFHIRE - FIELD REPORT (device).xlsx').replace(/[\\/:*?"<>|]+/g, '_');
        a.href = URL.createObjectURL(blob); a.download = name; document.body.appendChild(a); a.click();
        setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 4000);
        Swal.fire({ icon: 'success', title: 'Excel saved on this device', text: 'Make the final report on the server when you are online.', timer: 3500 });
      }).catch(function (e) { Swal.fire({ icon: 'error', title: 'Excel not made', text: e.message || String(e) }); });
  }

  // ---------- offline support ----------
  if ('serviceWorker' in navigator) navigator.serviceWorker.register('survey_report_sw.js', { scope: './survey_report' }).catch(function () {});
  load();
})();
