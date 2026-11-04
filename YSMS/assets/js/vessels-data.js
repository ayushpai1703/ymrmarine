/* Vessels Data (vessels_data.php) — every vessel record in one Airtable-style grid.
   Data / saves: ajax/vessels_data.php. Admin: click a cell to edit (Enter saves, Esc cancels,
   leaving the cell saves), status dropdown, bulk status, import. Everyone: search, filters,
   sort, columns (order / visibility / width saved per user), paging, export, record panel
   with change history. */
(function () {
  'use strict';
  var C = window.VD_CONFIG || {};
  var O = C.opts || {};
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var EDIT = !!C.canEdit;

  // ---------- columns ----------
  var COLS = {
    id:          { label: 'Record ID', icon: 'fa-hashtag', w: 86, sort: 'id', num: true },
    vessel:      { label: 'Vessel Name', icon: 'fa-ship', w: 210, sort: 'vessel', pk: true, ed: 'text' },
    imo:         { label: 'IMO Number', icon: 'fa-barcode', w: 110, sort: 'imo', ed: 'text' },
    report_no:   { label: 'Report Number', icon: 'fa-file-lines', w: 140, sort: 'report_no', ed: 'text' },
    client:      { label: 'Client Name', icon: 'fa-building', w: 180, sort: 'client', ed: 'select', opts: 'clients' },
    agent:       { label: 'Agent Name', icon: 'fa-people-group', w: 160, sort: 'agent', ed: 'text' },
    type:        { label: 'Survey Type', icon: 'fa-tags', w: 160, sort: 'type', ed: 'multi', opts: 'types' },
    port:        { label: 'Port', icon: 'fa-location-dot', w: 150, sort: 'port', ed: 'select', opts: 'ports' },
    surveyor:    { label: 'Surveyor', icon: 'fa-user-tie', w: 170, sort: 'surveyor', ed: 'multi', opts: 'surveyors' },
    assigned:    { label: 'Assigned Date', icon: 'fa-regular fa-calendar-plus', w: 150, sort: 'assigned', ed: 'datetime' },
    appointment: { label: 'Appointment Date', icon: 'fa-regular fa-calendar', w: 130, sort: 'appointment', ed: 'date' },
    completed:   { label: 'Survey Completed', icon: 'fa-regular fa-calendar-check', w: 140, sort: 'completed', ed: 'date' },
    report_date: { label: 'Report Uploaded', icon: 'fa-solid fa-file-arrow-up', w: 140, sort: 'report_date', ed: 'date' },
    status:      { label: 'Status', icon: 'fa-circle-half-stroke', w: 140, sort: 'status', ed: 'status' },
    due:         { label: 'Due Days', icon: 'fa-hourglass-half', w: 90, sort: 'due', num: true },
    overdue:     { label: 'Overdue Days', icon: 'fa-triangle-exclamation', w: 104, sort: 'overdue', num: true },
    recovery:    { label: 'Total Recovery', icon: 'fa-gas-pump', w: 124, sort: 'recovery', num: true },
    vlsfo:       { label: 'VLSFO Recovery', icon: 'fa-droplet', w: 124, sort: 'vlsfo', num: true },
    lsmgo:       { label: 'LSMGO Recovery', icon: 'fa-droplet', w: 124, sort: 'lsmgo', num: true },
    hsfo:        { label: 'HSFO Recovery', icon: 'fa-droplet', w: 120, sort: 'hsfo', num: true },
    remarks:     { label: 'Remarks', icon: 'fa-comment', w: 220, sort: 'remarks', ed: 'long' },
    notes:       { label: 'Notes', icon: 'fa-note-sticky', w: 220, sort: 'notes', ed: 'long' },
    live_status: { label: 'Live Status', icon: 'fa-tower-broadcast', w: 200 },
    updated:     { label: 'Status Updated', icon: 'fa-clock-rotate-left', w: 170, sort: 'updated' },
    agent_email: { label: 'Agent Email Sent', icon: 'fa-envelope', w: 140 },
    actions:     { label: 'Actions', icon: 'fa-bolt', w: 150 }
  };
  var DEFAULT_ORDER = ['id', 'imo', 'report_no', 'client', 'agent', 'type', 'port', 'surveyor', 'assigned', 'completed', 'report_date', 'status',
    'due', 'overdue', 'recovery', 'vlsfo', 'lsmgo', 'remarks', 'notes', 'actions', 'appointment', 'hsfo', 'live_status', 'updated', 'agent_email'];
  var DEFAULT_HIDDEN = ['appointment', 'hsfo', 'live_status', 'updated', 'agent_email'];
  var SORT_LABEL = {}; Object.keys(COLS).forEach(function (k) { if (COLS[k].sort) SORT_LABEL[COLS[k].sort] = COLS[k].label; });
  var ST_CLS = { 'Pending Vessel': 'pv', 'Pending Report': 'pr', 'Completed': 'co', 'Cancelled': 'ca' };
  var PERS = [25, 50, 100, 250, 0];

  var P = C.prefs || {}, I = C.init || {};
  var order = (P.order || []).filter(function (k) { return COLS[k] && !COLS[k].pk; });
  DEFAULT_ORDER.forEach(function (k) { if (order.indexOf(k) < 0) order.push(k); });
  var S = {
    q: I.q || '', f: {}, sort: Array.isArray(P.sort) ? P.sort.filter(function (s) { return SORT_LABEL[s.f]; }) : [],
    page: 1, per: PERS.indexOf(+P.per) >= 0 && P.per !== undefined ? +P.per : 50,
    order: order, hidden: Array.isArray(P.hidden) ? P.hidden.filter(function (k) { return COLS[k] && !COLS[k].pk; }) : DEFAULT_HIDDEN.slice(),
    widths: P.widths || {}, rows: [], total: 0, pages: 1, sel: {}, openId: null, seq: 0, pending: 0, loadedAt: null
  };
  ['vessel', 'report_no', 'agent', 'imo', 'status', 'type', 'port', 'client', 'surveyor', 'a_from', 'a_to', 'c_from', 'c_to', 'r_min', 'r_max'].forEach(function (k) { if (I[k]) S.f[k] = String(I[k]); });

  // ---------- helpers ----------
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  var MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  function fdate(d, time) { if (!d) return ''; var p = String(d).slice(0, 10).split('-'); var t = time && String(d).length > 10 ? ' ' + String(d).slice(11, 16) : ''; return p.length === 3 ? p[2] + ' ' + MON[+p[1] - 1] + ' ' + p[0] + t : esc(d); }
  function muted(t) { return '<span class="muted">' + (t || '—') + '</span>'; }
  function debounce(fn, ms) { var t; return function () { var a = arguments, me = this; clearTimeout(t); t = setTimeout(function () { fn.apply(me, a); }, ms); }; }
  function initials(n) { var p = String(n || '').split('+')[0].trim().split(/\s+/); return ((p[0] || '')[0] || '') + ((p[1] || '')[0] || ''); }
  function mt(v) { return (+v).toLocaleString('en-US', { minimumFractionDigits: 3, maximumFractionDigits: 3 }); }
  function visible() { return ['vessel'].concat(S.order.filter(function (k) { return S.hidden.indexOf(k) < 0; })); }
  function width(k) { return Math.max(60, +(S.widths[k] || COLS[k].w)); }
  function rowById(id) { for (var i = 0; i < S.rows.length; i++) if (S.rows[i].id === id) return S.rows[i]; return null; }
  function toast(icon, title) {
    if (!window.Swal) return;
    Swal.fire({ toast: true, position: 'top-end', icon: icon, title: title, showConfirmButton: false, timer: icon === 'success' ? 2200 : 4800, timerProgressBar: true });
  }
  function post(data) {
    var f = new FormData(); f.append('csrf_token', C.csrf);
    Object.keys(data).forEach(function (k) { if (data[k] instanceof File) f.append(k, data[k]); else f.append(k, data[k] == null ? '' : data[k]); });
    return fetch(C.api, { method: 'POST', body: f, credentials: 'same-origin', headers: { 'X-CSRF-Token': C.csrf } })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: r.status === 403 ? 'Session expired or no permission — reload the page.' : 'Unexpected server response (' + r.status + ').' }; }); })
      .catch(function () { return { ok: false, error: 'Network error — the change was not saved. Try again.' }; });
  }
  var savePrefs = debounce(function () {
    post({ a: 'prefs', prefs: JSON.stringify({ order: S.order, hidden: S.hidden, widths: S.widths, sort: S.sort, per: S.per }) });
  }, 600);
  function params() {
    var u = new URLSearchParams();
    if (S.q) u.set('q', S.q);
    Object.keys(S.f).forEach(function (k) { if (S.f[k]) u.set(k, S.f[k]); });
    return u;
  }
  function syncUrl() { try { var q = params().toString(); history.replaceState(null, '', 'vessels_data.php' + (q ? '?' + q : '')); } catch (e) {} }

  // the value a field has, as the server compares it (ajax a=update: orig)
  function rawOf(d, k) {
    switch (k) {
      case 'client': return String(d.client_id);
      case 'port': return String(d.port_id);
      case 'type': return d.type_ids.slice().sort(function (a, b) { return a - b; }).join(',');
      case 'surveyor': return d.surveyor_ids.join(',');
      case 'assigned': return d.assigned ? d.assigned.replace(' ', 'T') : '';
      default: return d[k] == null ? '' : String(d[k]);
    }
  }

  // ---------- data ----------
  var grid = $('[data-grid]'), tbody = $('[data-body]');
  function load() {
    var my = ++S.seq, u = params();
    u.set('a', 'list'); u.set('page', S.page); u.set('per', S.per);
    if (S.sort.length) u.set('sort', JSON.stringify(S.sort));
    if (!S.rows.length) skeleton(); else tbody.style.opacity = '.55';
    $('.at-sum').classList.add('loading');
    syncUrl();
    return fetch(C.api + '?' + u.toString(), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (res) {
      if (my !== S.seq) return;
      tbody.style.opacity = ''; $('.at-sum').classList.remove('loading');
      if (!res.ok) { toast('error', res.error || 'Could not load the vessels.'); return; }
      S.rows = res.rows; S.total = res.total; S.pages = res.pages; S.page = res.page; S.capped = res.capped;
      var sm = res.summary || {};
      C.statuses.concat(['total']).forEach(function (k) { var el = $('[data-sum="' + k + '"]'); if (el) el.textContent = (+sm[k] || 0).toLocaleString('en-US'); });
      $('[data-sum="recovery"]').textContent = mt(sm.recovery || 0) + ' MT';
      $('[data-sum-sub="recovery"]').textContent = 'VLSFO ' + mt(sm.vlsfo || 0) + ' · LSMGO ' + mt(sm.lsmgo || 0) + ' MT';
      $('[data-total]').textContent = S.total.toLocaleString('en-US');
      S.loadedAt = new Date(); updatedText();
      render(); toolbarState();
      if (S.openId) { var r = rowById(S.openId); if (r) renderPanel(r); }
    }).catch(function () { if (my === S.seq) { tbody.style.opacity = ''; toast('error', 'Network error — could not load the vessels.'); } });
  }
  function updatedText() {
    var el = $('[data-updated]'); if (!S.loadedAt) return;
    var mins = Math.floor((Date.now() - S.loadedAt.getTime()) / 60000);
    el.textContent = 'Updated ' + (mins < 1 ? 'just now' : mins + ' min ago') + ' (' + S.loadedAt.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) + ')';
    el.classList.toggle('stale', mins >= 5);
  }
  setInterval(updatedText, 30000);
  function skeleton() {
    var cols = visible(), h = '';
    for (var i = 0; i < 8; i++) h += '<tr class="at-skel"><td class="c-no"><span></span></td>' + cols.map(function () { return '<td><span style="width:' + (40 + (i * 13) % 50) + '%"></span></td>'; }).join('') + '</tr>';
    renderHead(); tbody.innerHTML = h;
  }

  // ---------- grid ----------
  function renderHead() {
    var cols = visible();
    $('[data-cols]').innerHTML = '<col style="width:56px">' + cols.map(function (k) { return '<col data-col="' + k + '" style="width:' + width(k) + 'px">'; }).join('');
    var allSel = S.rows.length && S.rows.every(function (r) { return S.sel[r.id]; });
    $('[data-head]').innerHTML = '<tr><th class="c-no"><input type="checkbox" data-selall aria-label="Select all on this page" data-testid="vd-selall"' + (allSel ? ' checked' : '') + '></th>' + cols.map(function (k) {
      var c = COLS[k], si = -1;
      S.sort.forEach(function (s, i) { if (s.f === c.sort) si = i; });
      var ind = si >= 0 ? '<span class="sorti">' + (S.sort.length > 1 ? (si + 1) : '') + '<i class="fa-solid fa-arrow-' + (S.sort[si].d === 'desc' ? 'down' : 'up') + '"></i></span>' : '';
      var ic = c.icon.indexOf(' ') > 0 ? c.icon : 'fa-solid ' + c.icon;
      return '<th data-k="' + k + '" class="' + (c.pk ? 'c-pk ' : '') + (c.sort ? 'sortable' : '') + '"' + (c.pk ? '' : ' draggable="true"') + ' data-testid="vd-th-' + k + '" title="' + esc(c.label) + (c.sort ? ' — click to sort, Shift+click to add' : '') + (EDIT && c.ed ? ' · editable' : '') + '">' +
        '<div class="th"><i class="ic ' + ic + '"></i><span class="lbl">' + esc(c.label) + '</span>' + ind + '</div><span class="rz" data-rz="' + k + '"></span></th>';
    }).join('') + '</tr>';
  }
  function recCell(d, k) {
    if (d.na) return '<span class="vd-na" title="Recovery is not applicable for this survey type">' + esc(C.naLabel) + '</span>';
    if (d[k] == null) return muted();
    return (d.onhire && +d[k] !== 0 ? '-' : '') + mt(d[k]);
  }
  function cell(k, d) {
    switch (k) {
      case 'id': return '<span class="muted">#</span>' + d.id;
      case 'vessel': return esc(d.vessel) + '<button type="button" class="vd-open" data-open title="Open record" aria-label="Open record" data-testid="vd-open-' + d.id + '"><i class="fa-solid fa-up-right-and-down-left-from-center"></i></button>';
      case 'type': return d.type ? '<span class="vd-tag">' + esc(d.type) + '</span>' : muted();
      case 'surveyor': return d.surveyor === C.outsourcing ? '<span class="vd-sv out">' + esc(d.surveyor) + '</span>' : '<span class="vd-sv"><i>' + esc(initials(d.surveyor).toUpperCase()) + '</i>' + esc(d.surveyor) + '</span>';
      case 'assigned': return d.assigned ? fdate(d.assigned, true) : muted();
      case 'appointment': case 'completed': case 'report_date': return d[k] ? fdate(d[k]) : muted();
      case 'status': return '<span class="vd-st ' + ST_CLS[d.status] + '" data-testid="vd-status-' + d.id + '">' + esc(d.status) + '</span>';
      case 'due': return d.due == null ? muted() : '<span class="vd-due">' + d.due + '</span>';
      case 'overdue': return d.overdue == null ? muted() : '<span class="vd-over">' + d.overdue + '</span>';
      case 'recovery': case 'vlsfo': case 'lsmgo': case 'hsfo': return recCell(d, k);
      case 'updated': return d.updated_at ? fdate(d.updated_at, true) + (d.updated_by ? ' · ' + esc(d.updated_by) : '') : muted();
      case 'agent_email': return d.agent_email ? fdate(d.agent_email, true) : muted();
      case 'actions': return '<span class="at-act"><a href="' + esc(d.url) + '" data-testid="vd-view-' + d.id + '"><i class="fa-solid fa-eye"></i> View</a>' +
        '<a href="#" data-history data-testid="vd-hist-' + d.id + '"><i class="fa-solid fa-clock-rotate-left"></i> History</a></span>';
    }
    return d[k] ? esc(d[k]) : muted();
  }
  function tdHtml(k, d) {
    var c = COLS[k], cls = [];
    if (c.pk) cls.push('c-pk');
    if (c.num) cls.push('num-r');
    if (EDIT && c.ed) { cls.push('ed'); if (c.ed === 'select' || c.ed === 'status' || c.ed === 'multi') cls.push('ed-sel'); }
    var tip = ['client', 'agent', 'port', 'type', 'surveyor', 'remarks', 'notes', 'live_status', 'vessel'].indexOf(k) >= 0 && d[k] ? ' title="' + esc(d[k]) + '"' : '';
    return '<td class="' + cls.join(' ') + '" data-c="' + k + '"' + tip + ' data-testid="vd-cell-' + d.id + '-' + k + '">' + cell(k, d) + '</td>';
  }
  function rowHtml(d, i) {
    return '<tr data-id="' + d.id + '" class="' + (S.sel[d.id] ? 'sel' : '') + (S.openId === d.id ? ' open' : '') + '" data-testid="vd-row-' + d.id + '">' +
      '<td class="c-no"><span class="num" data-row-num>' + i + '</span><input type="checkbox" data-sel aria-label="Select row"' + (S.sel[d.id] ? ' checked' : '') + ' data-testid="vd-sel-' + d.id + '"></td>' +
      visible().map(function (k) { return tdHtml(k, d); }).join('') + '</tr>';
  }
  function render() {
    renderHead();
    var off = (S.page - 1) * (S.per || S.total);
    tbody.innerHTML = S.rows.map(function (d, i) { return rowHtml(d, off + i + 1); }).join('');
    $('[data-empty]').hidden = S.rows.length > 0;
    var cols = visible();
    $('[data-foot]').innerHTML = S.rows.length ? '<tr><td class="c-no"></td>' + cols.map(function (k) {
      return '<td class="' + (COLS[k].pk ? 'c-pk' : '') + '">' + (k === 'vessel' ? '<b>' + S.total.toLocaleString('en-US') + '</b> record' + (S.total === 1 ? '' : 's') : '') + '</td>';
    }).join('') + '</tr>' : '';
    var from = S.total ? off + 1 : 0, to = off + S.rows.length, filtered = params().toString() !== '';
    $('[data-count-text]').innerHTML = 'Showing <b>' + from + '–' + to + '</b> of <b>' + S.total.toLocaleString('en-US') + '</b> vessel' + (S.total === 1 ? '' : 's') + (filtered ? ' (filtered)' : '') +
      (S.capped ? ' · first ' + S.rows.length.toLocaleString('en-US') + ' (narrow the filters to see the rest)' : '') +
      (S.sort.length ? ' · sorted by ' + S.sort.map(function (s) { return SORT_LABEL[s.f] + (s.d === 'desc' ? ' ↓' : ' ↑'); }).join(', ') : ' · latest Assigned Date first');
    $('[data-pginfo]').textContent = S.page + ' / ' + S.pages;
    $('[data-pg="-1"]').disabled = S.page <= 1; $('[data-pg="1"]').disabled = S.page >= S.pages;
    $('[data-per]').value = String(S.per);
    selInfo();
  }
  function rerenderRow(d) {
    var tr = $('tr[data-id="' + d.id + '"]', tbody); if (!tr) return;
    var num = $('[data-row-num]', tr).textContent;
    var tmp = document.createElement('tbody'); tmp.innerHTML = rowHtml(d, num);
    tbody.replaceChild(tmp.firstChild, tr);
  }
  function flash(id, k, cls) {
    var td = $('tr[data-id="' + id + '"] td[data-c="' + k + '"]', tbody); if (!td) return;
    td.classList.remove('saved', 'failed'); void td.offsetWidth; td.classList.add(cls);
  }
  function markOpen() { $$('tr[data-id]', tbody).forEach(function (tr) { tr.classList.toggle('open', +tr.getAttribute('data-id') === S.openId); }); }
  function selInfo() {
    var n = Object.keys(S.sel).length, el = $('[data-sel-info]');
    el.hidden = !n; el.innerHTML = n + ' selected <button type="button" data-selclear>clear</button>';
    $('[data-sel-count]').textContent = '(' + n + ')';
    var b = $('[data-bulk]'); if (b) { b.hidden = !n; $('[data-bulk-n]').textContent = n; }
  }
  function activeFilters() { return Object.keys(S.f).filter(function (k) { return S.f[k]; }); }
  function toolbarState() {
    var nf = activeFilters().length;
    $('[data-n="filter"]').textContent = nf ? nf : '';
    $('[data-n="sort"]').textContent = S.sort.length ? S.sort.length : '';
    $('[data-n="fields"]').textContent = S.hidden.length ? S.hidden.length + ' hidden' : '';
    $('[data-pop="filter"]').classList.toggle('active-f', nf > 0);
    $('[data-pop="sort"]').classList.toggle('active-s', S.sort.length > 0);
    $('[data-pop="fields"]').classList.toggle('active-h', S.hidden.length > 0);
    $('.at-bar [data-act="clear"]').hidden = !(nf || S.q);
    $$('[data-f]').forEach(function (el) { var k = el.getAttribute('data-f'); if (document.activeElement !== el) el.value = S.f[k] || ''; el.classList.toggle('on', !!S.f[k]); });
    $$('[data-card]').forEach(function (b) { b.classList.toggle('on', (S.f.status || '') === b.getAttribute('data-card')); });
    var si = $('[data-testid="vd-search"]'); if (document.activeElement !== si) si.value = S.q;
  }
  var reload = function () { S.page = 1; load(); };
  var reloadSoon = debounce(reload, 350);

  // ---------- popovers (columns / filter / sort / export) ----------
  var openPop = null;
  function closePop() { if (openPop) { $('[data-popbox="' + openPop + '"]').hidden = true; $('[data-pop="' + openPop + '"]').classList.remove('on'); openPop = null; } }
  $$('[data-pop]').forEach(function (b) {
    b.addEventListener('click', function (ev) {
      ev.stopPropagation();
      var k = b.getAttribute('data-pop'), was = openPop === k;
      closePop(); if (was) return;
      if (k === 'fields') renderFields();
      if (k === 'sort') renderSorts();
      var box = $('[data-popbox="' + k + '"]'), shell = $('.at-shell');
      box.hidden = false; b.classList.add('on'); openPop = k;
      if (window.innerWidth >= 992) box.style.left = Math.max(8, Math.min(b.offsetLeft, shell.clientWidth - box.offsetWidth - 8)) + 'px'; else box.style.left = '';
    });
  });
  document.addEventListener('click', function (ev) {
    if (openPop && document.contains(ev.target) && !ev.target.closest('.at-pop') && !ev.target.closest('[data-pop]')) closePop();
  });

  function renderFields() {
    $('[data-fields]').innerHTML = '<div class="at-field fixed"><i class="grip fa-solid fa-lock"></i>' + esc(COLS.vessel.label) + ' <small class="muted">primary</small></div>' +
      S.order.map(function (k) {
        return '<div class="at-field" draggable="true" data-fk="' + k + '" data-testid="vd-field-' + k + '"><i class="grip fa-solid fa-grip-vertical"></i>' + esc(COLS[k].label) +
          '<button type="button" class="tg' + (S.hidden.indexOf(k) < 0 ? ' on' : '') + '" data-tg="' + k + '" aria-label="Show ' + esc(COLS[k].label) + '" data-testid="vd-tg-' + k + '"></button></div>';
      }).join('');
  }
  $('[data-popbox="fields"]').addEventListener('click', function (ev) {
    var t = ev.target.closest('[data-tg]'), all = ev.target.closest('[data-fields-all]');
    if (t) { var k = t.getAttribute('data-tg'), i = S.hidden.indexOf(k); if (i >= 0) S.hidden.splice(i, 1); else S.hidden.push(k); }
    else if (all) S.hidden = all.getAttribute('data-fields-all') === '1' ? [] : S.order.slice();
    else if (ev.target.closest('[data-fields-reset]')) { S.order = DEFAULT_ORDER.slice(); S.hidden = DEFAULT_HIDDEN.slice(); S.widths = {}; }
    else return;
    renderFields(); render(); toolbarState(); savePrefs();
  });
  var dragF = null;
  $('[data-fields]').addEventListener('dragstart', function (ev) { var f = ev.target.closest('[data-fk]'); if (f) { dragF = f.getAttribute('data-fk'); ev.dataTransfer.effectAllowed = 'move'; ev.dataTransfer.setData('text/plain', dragF); } });
  $('[data-fields]').addEventListener('dragover', function (ev) { var f = ev.target.closest('[data-fk]'); if (f && dragF) { ev.preventDefault(); $$('.at-field').forEach(function (x) { x.classList.toggle('drag-over', x === f); }); } });
  $('[data-fields]').addEventListener('drop', function (ev) { var f = ev.target.closest('[data-fk]'); if (f && dragF) { ev.preventDefault(); moveCol(dragF, f.getAttribute('data-fk'), false); dragF = null; renderFields(); } });
  $('[data-fields]').addEventListener('dragend', function () { dragF = null; $$('.at-field').forEach(function (x) { x.classList.remove('drag-over'); }); });
  function moveCol(k, target, after) {
    if (k === target) return;
    S.order.splice(S.order.indexOf(k), 1);
    var i = S.order.indexOf(target);
    S.order.splice(after ? i + 1 : i, 0, k);
    render(); savePrefs();
  }

  // filters (popover + quick bar share data-f; same key → same value)
  $$('[data-f]').forEach(function (el) {
    var evn = el.tagName === 'SELECT' || el.type === 'date' ? 'change' : 'input';
    el.addEventListener(evn, function () { S.f[el.getAttribute('data-f')] = el.value.trim(); S.sel = {}; toolbarState(); reloadSoon(); });
  });
  $$('[data-act="clear"]').forEach(function (b) { b.addEventListener('click', function () { S.f = {}; S.q = ''; toolbarState(); reload(); }); });
  $('[data-testid="vd-search"]').addEventListener('input', debounce(function (ev) { S.q = ev.target.value.trim(); toolbarState(); reload(); }, 350));
  $$('[data-card]').forEach(function (b) { b.addEventListener('click', function () { var v = b.getAttribute('data-card'); S.f.status = S.f.status === v ? '' : v; toolbarState(); reload(); }); });
  $('[data-act="refresh"]').addEventListener('click', function () { load(); });

  function renderSorts() {
    var opts = function (cur) { return Object.keys(SORT_LABEL).map(function (f) { return '<option value="' + f + '"' + (f === cur ? ' selected' : '') + '>' + SORT_LABEL[f] + '</option>'; }).join(''); };
    $('[data-sorts]').innerHTML = S.sort.length ? S.sort.map(function (s, i) {
      return '<div class="at-sortrow" data-si="' + i + '"><select data-sf aria-label="Sort field">' + opts(s.f) + '</select><select data-sd aria-label="Direction"><option value="asc"' + (s.d !== 'desc' ? ' selected' : '') + '>Ascending</option><option value="desc"' + (s.d === 'desc' ? ' selected' : '') + '>Descending</option></select>' +
        '<button type="button" data-sx aria-label="Remove sort"><i class="fa-solid fa-xmark"></i></button></div>';
    }).join('') : '<div class="muted" style="font-size:12px;margin-bottom:6px">Default order — latest Assigned Date first.</div>';
    $('[data-sort-add]').hidden = S.sort.length >= 3;
  }
  function sortChanged() { renderSorts(); toolbarState(); savePrefs(); reload(); }
  $('[data-popbox="sort"]').addEventListener('change', function (ev) {
    var row = ev.target.closest('[data-si]'); if (!row) return;
    var i = +row.getAttribute('data-si');
    if (ev.target.matches('[data-sf]')) S.sort[i].f = ev.target.value;
    if (ev.target.matches('[data-sd]')) S.sort[i].d = ev.target.value;
    sortChanged();
  });
  $('[data-popbox="sort"]').addEventListener('click', function (ev) {
    var x = ev.target.closest('[data-sx]');
    if (x) { S.sort.splice(+x.closest('[data-si]').getAttribute('data-si'), 1); sortChanged(); }
    if (ev.target.closest('[data-sort-add]')) {
      var used = S.sort.map(function (s) { return s.f; }), f = Object.keys(SORT_LABEL).filter(function (k) { return used.indexOf(k) < 0; })[0];
      if (f) { S.sort.push({ f: f, d: 'asc' }); sortChanged(); }
    }
  });

  // header: sort / select all / resize / drag
  var thead = $('[data-head]'), resizing = null, dragK = null;
  thead.addEventListener('click', function (ev) {
    if (ev.target.closest('[data-rz]') || resizing) return;
    if (ev.target.matches('[data-selall]')) { S.rows.forEach(function (r) { if (ev.target.checked) S.sel[r.id] = snap(r); else delete S.sel[r.id]; }); render(); return; }
    var th = ev.target.closest('th[data-k]'); if (!th) return;
    var f = COLS[th.getAttribute('data-k')].sort; if (!f) return;
    var i = -1; S.sort.forEach(function (s, j) { if (s.f === f) i = j; });
    if (ev.shiftKey) {
      if (i < 0) { if (S.sort.length < 3) S.sort.push({ f: f, d: 'asc' }); }
      else if (S.sort[i].d === 'asc') S.sort[i].d = 'desc'; else S.sort.splice(i, 1);
    } else if (i === 0 && S.sort.length === 1) {
      if (S.sort[0].d === 'asc') S.sort[0].d = 'desc'; else S.sort = [];
    } else S.sort = [{ f: f, d: 'asc' }];
    toolbarState(); savePrefs(); reload();
  });
  thead.addEventListener('mousedown', function (ev) {
    var h = ev.target.closest('[data-rz]'); if (!h) return;
    ev.preventDefault(); ev.stopPropagation();
    var k = h.getAttribute('data-rz');
    resizing = { k: k, x: ev.clientX, w: width(k), col: $('col[data-col="' + k + '"]'), h: h };
    h.classList.add('on'); document.body.style.cursor = 'col-resize';
  });
  document.addEventListener('mousemove', function (ev) {
    if (!resizing) return;
    var w = Math.max(60, Math.min(600, resizing.w + ev.clientX - resizing.x));
    resizing.col.style.width = w + 'px'; S.widths[resizing.k] = w;
  });
  document.addEventListener('mouseup', function () {
    if (!resizing) return;
    resizing.h.classList.remove('on'); document.body.style.cursor = '';
    setTimeout(function () { resizing = null; }, 0); savePrefs();
  });
  thead.addEventListener('dragstart', function (ev) {
    var th = ev.target.closest('th[draggable]'); if (!th || resizing) { ev.preventDefault(); return; }
    dragK = th.getAttribute('data-k'); th.classList.add('dragging');
    ev.dataTransfer.effectAllowed = 'move'; ev.dataTransfer.setData('text/plain', dragK);
  });
  thead.addEventListener('dragover', function (ev) {
    var th = ev.target.closest('th[draggable]'); if (!th || !dragK) return;
    ev.preventDefault();
    var r = th.getBoundingClientRect();
    $$('th', thead).forEach(function (x) { x.classList.remove('drop-l', 'drop-r'); });
    th.classList.add(ev.clientX > r.left + r.width / 2 ? 'drop-r' : 'drop-l');
  });
  thead.addEventListener('drop', function (ev) {
    var th = ev.target.closest('th[draggable]'); if (!th || !dragK) return;
    ev.preventDefault();
    var r = th.getBoundingClientRect();
    moveCol(dragK, th.getAttribute('data-k'), ev.clientX > r.left + r.width / 2); dragK = null;
  });
  thead.addEventListener('dragend', function () { dragK = null; $$('th', thead).forEach(function (x) { x.classList.remove('drop-l', 'drop-r', 'dragging'); }); });

  // ---------- selection ----------
  function snap(r) { return { status: r.status, completed: r.completed, report_date: r.report_date, vessel: r.vessel }; }
  tbody.addEventListener('click', function (ev) {
    var tr = ev.target.closest('tr[data-id]'); if (!tr) return;
    var id = +tr.getAttribute('data-id'), d = rowById(id);
    if (ev.target.matches('[data-sel]')) { if (ev.target.checked) S.sel[id] = snap(d); else delete S.sel[id]; tr.classList.toggle('sel', ev.target.checked); selInfo(); renderHead(); return; }
    if (ev.target.closest('[data-open]')) { openPanel(id); return; }
    if (ev.target.closest('[data-history]')) { ev.preventDefault(); openPanel(id, true); return; }
    if (ev.target.closest('a, button')) return;
    var td = ev.target.closest('td[data-c]');
    if (td && EDIT && COLS[td.getAttribute('data-c')].ed && !td.classList.contains('editing') && !td.classList.contains('saving')) startEdit(td, d);
  });
  document.addEventListener('click', function (ev) { if (ev.target.closest('[data-selclear]')) { S.sel = {}; render(); } });

  $$('[data-pg]').forEach(function (b) { b.addEventListener('click', function () { S.page = Math.min(S.pages, Math.max(1, S.page + +b.getAttribute('data-pg'))); load(); grid.scrollTop = 0; }); });
  $('[data-per]').addEventListener('change', function (ev) { S.per = +ev.target.value; savePrefs(); reload(); });

  // ---------- export ----------
  function exportUrl(scope) {
    var ids = Object.keys(S.sel), u;
    if (scope === 'template') return C.export + '?template=1';
    u = scope === 'filtered' ? params() : new URLSearchParams();
    if (scope === 'selected') u.set('ids', ids.join(','));
    if (S.sort.length) u.set('sort', JSON.stringify(S.sort));
    return C.export + (u.toString() ? '?' + u.toString() : '');
  }
  $('[data-popbox="export"]').addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-export]'); if (!b) return;
    var scope = b.getAttribute('data-export');
    if (scope === 'selected' && !Object.keys(S.sel).length) { toast('info', 'Tick the vessels to export first.'); return; }
    closePop();
    window.location.href = exportUrl(scope);
  });

  // ---------- cell editing (Admin) ----------
  var ED = null; // { td, d, k, input, pop, orig }
  function optList(k) {
    var c = COLS[k], list = (O[c.opts] || []).slice();
    return list;
  }
  function startEdit(td, d) {
    if (ED) finishEdit(true);
    var k = td.getAttribute('data-c'), c = COLS[k], orig = rawOf(d, k);
    if (c.ed === 'status') return statusEdit(td, d);
    if (c.ed === 'multi' || c.ed === 'long') return popEdit(td, d, k, orig);
    var input;
    if (c.ed === 'select') {
      input = document.createElement('select');
      input.innerHTML = optList(k).map(function (o) { return '<option value="' + o.id + '"' + (String(o.id) === orig ? ' selected' : '') + '>' + esc(o.name) + (o.country && o.country !== 'India' ? ' (' + esc(o.country) + ')' : '') + '</option>'; }).join('');
    } else {
      input = document.createElement('input');
      input.type = c.ed === 'date' ? 'date' : c.ed === 'datetime' ? 'datetime-local' : 'text';
      if (k === 'imo') { input.inputMode = 'numeric'; input.maxLength = 12; }
      input.value = orig;
    }
    input.setAttribute('data-testid', 'vd-editor');
    td.classList.add('editing'); td.innerHTML = ''; td.appendChild(input);
    ED = { td: td, d: d, k: k, input: input, orig: orig };
    input.focus(); if (input.select && input.type === 'text') input.select();
    input.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter') { ev.preventDefault(); finishEdit(true); }
      else if (ev.key === 'Escape') { ev.preventDefault(); ev.stopPropagation(); finishEdit(false); }
      else if (ev.key === 'Tab') { ev.preventDefault(); var next = nextEditable(td, ev.shiftKey); finishEdit(true); if (next) startEdit(next, d); }
    });
    if (c.ed === 'select') input.addEventListener('change', function () { finishEdit(true); });
    input.addEventListener('blur', function () { setTimeout(function () { if (ED && ED.input === input) finishEdit(true); }, 0); });
  }
  function nextEditable(td, back) {
    var tds = $$('td.ed', td.parentNode), i = tds.indexOf(td);
    var n = tds[i + (back ? -1 : 1)];
    return n && ['text', 'date', 'datetime', 'select'].indexOf(COLS[n.getAttribute('data-c')].ed) >= 0 ? n : null;
  }
  function finishEdit(save) {
    if (!ED) return;
    var e = ED; ED = null;
    if (e.pop) { e.pop.remove(); document.removeEventListener('mousedown', e.outside, true); }
    e.td.classList.remove('editing');
    if (e.k === 'status') { rerenderRow(rowById(e.d.id) || e.d); return; }
    var val = e.value !== undefined ? e.value : e.input.value;
    if (!save || val === e.orig || (e.k === 'type' || e.k === 'surveyor') && sameSet(val, e.orig)) { rerenderRow(rowById(e.d.id) || e.d); return; }
    saveCell(e.d, e.k, val, e.orig);
  }
  function sameSet(a, b) { var n = function (v) { return String(v).split(',').filter(Boolean).sort().join(','); }; return n(a) === n(b); }
  function saveCell(d, k, val, orig) {
    var td = $('tr[data-id="' + d.id + '"] td[data-c="' + k + '"]', tbody);
    if (td) { td.classList.add('saving'); td.innerHTML = esc(previewText(k, val)); }
    S.pending++;
    return post({ a: 'update', id: d.id, field: k, value: val, orig: orig }).then(function (res) {
      S.pending--;
      if (res.row) replaceRow(res.row);
      else if (rowById(d.id)) rerenderRow(rowById(d.id));
      if (res.ok) {
        flash(d.id, k, res.changed ? 'saved' : 'saved');
        if (res.changed) { toast('success', res.message); if (['completed', 'report_date', 'type'].indexOf(k) >= 0) refreshSummarySoon(); }
      } else {
        flash(d.id, k, 'failed');
        toast('error', res.error || 'Not saved.');
      }
      return res;
    });
  }
  function previewText(k, v) {
    var c = COLS[k];
    if (c.ed === 'select') { var o = optList(k).filter(function (x) { return String(x.id) === String(v); })[0]; return o ? o.name : v; }
    if (c.ed === 'date' || c.ed === 'datetime') return v ? fdate(v.replace('T', ' '), c.ed === 'datetime') : '';
    return v;
  }
  function replaceRow(row) {
    for (var i = 0; i < S.rows.length; i++) if (S.rows[i].id === row.id) { S.rows[i] = row; break; }
    rerenderRow(row);
    if (S.sel[row.id]) S.sel[row.id] = snap(row);
    if (S.openId === row.id) renderPanel(row);
  }
  var refreshSummarySoon = debounce(function () { if (!ED && !S.pending) load(); }, 1500);

  // popover editor: multi-select (survey types, surveyors) and long text (remarks, notes)
  function popEdit(td, d, k, orig) {
    var c = COLS[k], pop = document.createElement('div');
    pop.className = 'vd-ed-pop'; pop.setAttribute('data-testid', 'vd-pop-editor');
    var foot = '<div class="vd-ed-f"><small>' + (c.ed === 'long' ? 'Ctrl+Enter saves · Esc cancels' : 'Esc cancels') + '</small><div class="b"><button type="button" class="at-btn" data-ed-cancel>Cancel</button><button type="button" class="at-btn pri" data-ed-save data-testid="vd-pop-save">Save</button></div></div>';
    if (c.ed === 'long') {
      pop.innerHTML = '<h4>' + esc(c.label) + ' — ' + esc(d.vessel) + '</h4><textarea maxlength="5000" data-testid="vd-pop-text">' + esc(d[k] || '') + '</textarea>' + foot;
    } else {
      var sel = orig ? orig.split(',') : [], list = optList(k);
      var items = list.map(function (o) {
        var off = k === 'surveyor' && o.active === false && sel.indexOf(String(o.id)) < 0;
        return off ? '' : '<label data-name="' + esc(String(o.name).toLowerCase()) + '"><input type="checkbox" value="' + o.id + '"' + (sel.indexOf(String(o.id)) >= 0 ? ' checked' : '') + '>' + esc(o.name) + (o.active === false ? ' <small>inactive</small>' : '') + '</label>';
      }).join('');
      if (k === 'surveyor') items = '<label class="out" data-name="outsourcing"><input type="checkbox" value="" data-out' + (sel.length ? '' : ' checked') + '>' + esc(C.outsourcing) + '</label>' + items;
      pop.innerHTML = '<h4>' + esc(c.label) + ' — ' + esc(d.vessel) + '</h4><input class="vd-ms-q" placeholder="Search…" data-ms-q><div class="vd-ms-list" data-testid="vd-ms-list">' + items + '</div>' + foot;
    }
    document.body.appendChild(pop);
    var r = td.getBoundingClientRect(), top = r.bottom + 4, left = Math.min(r.left, window.innerWidth - pop.offsetWidth - 12);
    if (top + pop.offsetHeight > window.innerHeight - 8) top = Math.max(8, r.top - pop.offsetHeight - 4);
    pop.style.top = top + 'px'; pop.style.left = Math.max(8, left) + 'px';
    td.classList.add('editing');
    var value = function () {
      if (c.ed === 'long') return $('textarea', pop).value.trim();
      return $$('.vd-ms-list input:checked', pop).map(function (x) { return x.value; }).filter(Boolean).join(',');
    };
    var e = { td: td, d: d, k: k, pop: pop, orig: c.ed === 'long' ? (d[k] || '') : orig, input: { value: '' } };
    e.outside = function (ev) { if (!pop.contains(ev.target)) { e.value = value(); finishEdit(true); } };
    ED = e;
    setTimeout(function () { document.addEventListener('mousedown', e.outside, true); }, 0);
    pop.addEventListener('click', function (ev) {
      if (ev.target.closest('[data-ed-cancel]')) finishEdit(false);
      else if (ev.target.closest('[data-ed-save]')) {
        e.value = value();
        if (c.ed === 'multi' && k === 'type' && !e.value) { toast('error', 'Choose at least one survey type.'); return; }
        finishEdit(true);
      }
    });
    pop.addEventListener('change', function (ev) {
      if (k !== 'surveyor' || !ev.target.matches('input[type=checkbox]')) return;
      if (ev.target.hasAttribute('data-out') && ev.target.checked) $$('.vd-ms-list input:not([data-out])', pop).forEach(function (x) { x.checked = false; });
      else if (!ev.target.hasAttribute('data-out')) { var out = $('[data-out]', pop); out.checked = !$$('.vd-ms-list input:not([data-out]):checked', pop).length; }
    });
    var q = $('[data-ms-q]', pop);
    if (q) { q.addEventListener('input', function () { var t = q.value.trim().toLowerCase(); $$('.vd-ms-list label', pop).forEach(function (l) { l.hidden = t && l.getAttribute('data-name').indexOf(t) < 0; }); }); q.focus(); }
    else $('textarea', pop).focus();
    pop.addEventListener('keydown', function (ev) {
      if (ev.key === 'Escape') { ev.preventDefault(); ev.stopPropagation(); finishEdit(false); }
      if (ev.key === 'Enter' && (ev.ctrlKey || ev.metaKey || c.ed === 'multi' && ev.target === q)) { ev.preventDefault(); $('[data-ed-save]', pop).click(); }
    });
  }

  // ---------- status ----------
  function needs(snapRow, to) {
    var n = [];
    if ((to === 'Pending Report' || to === 'Completed') && !snapRow.completed) n.push('completed');
    if (to === 'Completed' && !snapRow.report_date) n.push('report_date');
    return n;
  }
  var EFFECT = {
    'Pending Vessel': 'It will show in Pending Vessels.',
    'Pending Report': 'It will show in Pending Reports (and in Recovery / Invoices, as a Pending Report does).',
    'Completed': 'It will show in Completed (and in Recovery). Report files are not uploaded by this — use the normal upload on the detail page for that.',
    'Cancelled': 'It will show in Cancelled and drop out of Pending lists and Recovery totals. The assigned surveyor is notified.'
  };
  function statusDialog(title, html, need, extra) {
    var today = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
    var f = need.map(function (k) {
      return '<label style="display:block;text-align:left;font-size:13px;font-weight:600;margin-top:10px">' + (k === 'completed' ? 'Survey Completed Date' : 'Report Uploaded Date') + (extra ? ' <small style="font-weight:500;color:#64748b">(for vessels that have none)</small>' : '') +
        '<input type="date" class="swal2-input" style="margin:4px 0 0;width:100%" data-need="' + k + '" value="' + today + '" data-testid="vd-need-' + k + '"></label>';
    }).join('');
    return Swal.fire({
      title: title, html: '<div style="font-size:14px;text-align:left">' + html + '</div>' + f, icon: 'question',
      showCancelButton: true, confirmButtonText: 'Change status', confirmButtonColor: '#00A3DF', focusCancel: false, showLoaderOnConfirm: true,
      preConfirm: function () {
        var out = {};
        for (var i = 0; i < need.length; i++) { var v = $('[data-need="' + need[i] + '"]').value; if (!v) { Swal.showValidationMessage('Enter the date.'); return false; } out[need[i]] = v; }
        return out;
      }
    });
  }
  function statusEdit(td, d) {
    var sel = document.createElement('select');
    sel.innerHTML = C.statuses.map(function (s) { return '<option' + (s === d.status ? ' selected' : '') + '>' + esc(s) + '</option>'; }).join('');
    sel.setAttribute('data-testid', 'vd-status-editor');
    td.classList.add('editing'); td.innerHTML = ''; td.appendChild(sel); sel.focus();
    ED = { td: td, d: d, k: 'status', input: sel, orig: d.status };
    var done = false;
    sel.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') { ev.preventDefault(); ev.stopPropagation(); done = true; ED = null; td.classList.remove('editing'); rerenderRow(d); } });
    sel.addEventListener('blur', function () { setTimeout(function () { if (!done && ED && ED.input === sel) { ED = null; td.classList.remove('editing'); rerenderRow(d); } }, 150); });
    sel.addEventListener('change', function () {
      done = true; ED = null;
      var to = sel.value; td.classList.remove('editing'); rerenderRow(d);
      if (to === d.status) return;
      var need = needs(d, to);
      statusDialog('Move to ' + to + '?', '<b>' + esc(d.vessel) + '</b>: ' + esc(d.status) + ' → <b>' + esc(to) + '</b><br><small>' + esc(EFFECT[to]) + '</small>' +
        (need.length ? '<br><small>A ' + esc(to) + ' record needs the date' + (need.length > 1 ? 's' : '') + ' below.</small>' : ''), need, false).then(function (r) {
        if (!r.isConfirmed) return;
        var dd = r.value || {};
        var cell = $('tr[data-id="' + d.id + '"] td[data-c="status"]', tbody); if (cell) cell.classList.add('saving');
        S.pending++;
        post({ a: 'status', id: d.id, status: to, completed: dd.completed || '', report_date: dd.report_date || '' }).then(function (res) {
          S.pending--;
          if (!res.ok) { if (cell) cell.classList.remove('saving'); flash(d.id, 'status', 'failed'); Swal.fire({ icon: 'error', title: 'Status not changed', text: res.error || 'Please try again.' }); return; }
          if (res.row) replaceRow(res.row);
          flash(d.id, 'status', 'saved');
          toast('success', res.message);
          load(); // counts + the row may leave a status filter
        });
      });
    });
  }

  // bulk status
  var bulkBtn = $('[data-act="bulk-apply"]');
  if (bulkBtn) bulkBtn.addEventListener('click', function () {
    var to = $('[data-bulk-status]').value, ids = Object.keys(S.sel);
    if (!ids.length) return;
    if (!to) { toast('info', 'Choose the new status first.'); return; }
    var need = [], moving = 0;
    ids.forEach(function (id) { var s = S.sel[id]; if (s.status !== to) moving++; needs(s, to).forEach(function (k) { if (need.indexOf(k) < 0) need.push(k); }); });
    statusDialog('Move ' + ids.length + ' vessel' + (ids.length === 1 ? '' : 's') + ' to ' + to + '?',
      moving + ' will change' + (ids.length - moving ? ', ' + (ids.length - moving) + ' already ' + esc(to) : '') + '.<br><small>' + esc(EFFECT[to]) + '</small><br><small>All or nothing: if one vessel cannot be changed, none is.</small>', need, true).then(function (r) {
      if (!r.isConfirmed) return;
      var dd = r.value || {};
      post({ a: 'bulk_status', ids: ids.join(','), status: to, completed: dd.completed || '', report_date: dd.report_date || '' }).then(function (res) {
        if (!res.ok) { Swal.fire({ icon: 'error', title: 'Nothing changed', text: res.error || 'Please try again.' }); return; }
        toast('success', res.message);
        S.sel = {}; $('[data-bulk-status]').value = ''; load();
      });
    });
  });
  var bulkExp = $('[data-act="bulk-export"]');
  if (bulkExp) bulkExp.addEventListener('click', function () { window.location.href = exportUrl('selected'); });

  // ---------- import (Admin) ----------
  var impBtn = $('[data-act="import"]');
  if (impBtn) impBtn.addEventListener('click', function () {
    Swal.fire({
      title: 'Import from Excel', width: 560,
      html: '<div class="vd-imp"><p>Updates <b>existing</b> vessels, matched by <b>Record ID</b>. New vessels are added with Assign Vessel / Bulk Assign. Blank cells are left as they are. You see every change before anything is saved.</p>' +
        '<label class="vd-imp-drop"><i class="fa-solid fa-file-excel"></i> Choose the Excel file (.xlsx)<input type="file" accept=".xlsx,.xls,.csv" id="vdImpFile" data-testid="vd-import-file"></label>' +
        '<p style="font-size:12px;color:#64748b">Start from <a href="' + exportUrl('filtered') + '">an export of this list</a> or the <a href="' + exportUrl('template') + '" data-testid="vd-import-template">import template</a>.</p></div>',
      showCancelButton: true, confirmButtonText: 'Check file', confirmButtonColor: '#00A3DF', showLoaderOnConfirm: true,
      preConfirm: function () {
        var f = $('#vdImpFile').files[0];
        if (!f) { Swal.showValidationMessage('Choose the Excel file.'); return false; }
        return post({ a: 'import_preview', file: f }).then(function (res) { if (!res.ok) { Swal.showValidationMessage(res.error || 'The file could not be checked.'); return false; } return res; });
      },
      allowOutsideClick: function () { return !Swal.isLoading(); }
    }).then(function (r) { if (r.isConfirmed && r.value) importPreview(r.value); });
  });
  function importPreview(p) {
    var c = p.counts;
    var rows = p.rows.map(function (x) {
      var body = x.state === 'error' ? '<span class="err">' + x.errors.map(esc).join('<br>') + '</span>'
        : x.state === 'same' ? '<span class="muted">No change</span>'
        : '<span class="chg">' + x.changes.map(function (ch) { return esc(ch.field) + ': <s>' + (ch.old ? esc(ch.old) : '—') + '</s> → <b>' + (ch.new ? esc(ch.new) : '(empty)') + '</b>'; }).join('<br>') + '</span>';
      return '<tr class="' + x.state + '" data-testid="vd-imp-row"><td>' + x.row + '</td><td>' + (x.id ? '#' + x.id : '—') + '<br><small>' + esc(x.vessel) + '</small></td><td>' + body + '</td></tr>';
    }).join('');
    Swal.fire({
      title: 'Import preview', width: 820,
      html: '<div class="vd-imp" data-testid="vd-imp-preview"><div style="font-size:12px;color:#64748b">' + esc(p.file) + (p.ignored.length ? ' · ignored columns: ' + p.ignored.map(esc).join(', ') : '') + '</div>' +
        '<div class="cnt"><div>Rows<b>' + c.rows + '</b></div><div class="ok">With changes<b data-testid="vd-imp-changes">' + c.changes + '</b></div><div>No change<b>' + c.unchanged + '</b></div><div class="bad">Cannot import<b data-testid="vd-imp-failed">' + c.failed + '</b></div></div>' +
        '<div class="vd-imp-t"><table><thead><tr><th>Row</th><th>Record</th><th>Changes / problems</th></tr></thead><tbody>' + rows + '</tbody></table></div>' +
        (c.failed ? '<p style="font-size:12px;color:#b45309;margin-top:8px">Rows that cannot be imported are skipped; the other rows can still be imported.</p>' : '') + '</div>',
      showCancelButton: true, showConfirmButton: !!p.token, confirmButtonText: 'Apply ' + c.changes + ' row' + (c.changes === 1 ? '' : 's') + ' of changes', confirmButtonColor: '#00A3DF',
      cancelButtonText: p.token ? 'Cancel' : 'Close', showLoaderOnConfirm: true,
      preConfirm: function () {
        return post({ a: 'import_apply', token: p.token }).then(function (res) { if (!res.ok) { Swal.showValidationMessage(res.error || 'Not imported.'); return false; } return res; });
      },
      allowOutsideClick: function () { return !Swal.isLoading(); }
    }).then(function (r) { if (r.isConfirmed && r.value) { toast('success', r.value.message); load(); } });
  }

  // ---------- record panel ----------
  var panel = $('[data-panel]'), body = $('[data-p-body]'), scrim = $('[data-scrim]');
  function openPanel(id, focusHistory) {
    var d = rowById(id); if (!d) return;
    S.openId = id; markOpen();
    panel.classList.add('open'); panel.setAttribute('aria-hidden', 'false'); scrim.hidden = false;
    renderPanel(d, focusHistory);
  }
  function closePanel() { S.openId = null; panel.classList.remove('open'); panel.setAttribute('aria-hidden', 'true'); scrim.hidden = true; markOpen(); }
  scrim.addEventListener('click', closePanel);
  $('[data-close]').addEventListener('click', closePanel);
  document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape' && !ED && !document.querySelector('.swal2-container')) { if (openPop) closePop(); else if (S.openId) closePanel(); } });
  function renderPanel(d, focusHistory) {
    $('[data-p-title]').textContent = d.vessel || 'Vessel';
    $('[data-p-id]').textContent = '#' + d.id;
    $('[data-p-status]').innerHTML = '<span class="vd-st ' + ST_CLS[d.status] + '">' + esc(d.status) + '</span>';
    var kv = function (k, v) { return '<div>' + k + '</div><div>' + v + '</div>'; };
    var acts = '<a class="at-btn pri" href="' + esc(d.url) + '" data-testid="vd-p-view"><i class="fa-solid fa-eye"></i> View details</a>';
    if (EDIT) acts += '<a class="at-btn" href="' + esc(d.url) + '" data-testid="vd-p-edit"><i class="fa-solid fa-pen"></i> Edit record &amp; files</a>';
    if (d.status === 'Pending Report' && C.role !== 'Super Admin') acts += '<a class="at-btn" href="report_generator.php?id=' + d.id + '" data-testid="vd-p-report"><i class="fa-solid fa-file-word"></i> Report generator</a>';
    if (d.status === 'Pending Report' || d.status === 'Completed') acts += '<a class="at-btn" href="recovery.php?q=' + encodeURIComponent(d.report_no || d.vessel) + '" data-testid="vd-p-recovery"><i class="fa-solid fa-gas-pump"></i> View recovery</a>';
    body.innerHTML = '<div class="at-sec"><div class="vd-p-acts">' + acts + '</div></div>' +
      '<div class="at-sec"><div class="at-sec-h">Vessel &amp; survey</div><div class="at-kv">' +
      kv('Record ID', '#' + d.id) + kv('Vessel', '<b>' + esc(d.vessel) + '</b>') + kv('IMO Number', d.imo ? esc(d.imo) : muted()) + kv('Report Number', d.report_no ? esc(d.report_no) : muted()) +
      kv('Client', d.client ? esc(d.client) : muted()) + kv('Agent', d.agent ? esc(d.agent) : muted()) +
      kv('Survey Type', d.type ? '<span class="vd-tag">' + esc(d.type) + '</span>' : muted()) + kv('Port', d.port ? esc(d.port) + (d.country ? ' <small class="muted">' + esc(d.country) + '</small>' : '') : muted()) +
      kv('Surveyor', esc(d.surveyor)) + '</div></div>' +
      '<div class="at-sec"><div class="at-sec-h">Dates &amp; status</div><div class="at-kv">' +
      kv('Assigned', d.assigned ? fdate(d.assigned, true) : muted()) + kv('Appointment', d.appointment ? fdate(d.appointment) : muted()) +
      kv('Survey Completed', d.completed ? fdate(d.completed) : muted()) + kv('Report Uploaded', d.report_date ? fdate(d.report_date) : muted()) +
      kv('Status', '<span class="vd-st ' + ST_CLS[d.status] + '">' + esc(d.status) + '</span>') +
      kv('Due / Overdue', d.overdue != null ? '<span class="vd-over">' + d.overdue + ' days overdue</span>' : d.due != null ? '<span class="vd-due">due in ' + d.due + ' day' + (d.due === 1 ? '' : 's') + '</span>' : muted()) +
      kv('Status updated', d.updated_at ? fdate(d.updated_at, true) + (d.updated_by ? ' · ' + esc(d.updated_by) : '') : muted()) +
      (d.live_status ? kv('Live status', esc(d.live_status)) : '') + '</div></div>' +
      '<div class="at-sec"><div class="at-sec-h">Recovery <small style="font-weight:600;text-transform:none;letter-spacing:0">from the Calculation Sheet</small></div><div class="at-kv">' +
      kv('Total', recCell(d, 'recovery') + (d.na ? '' : ' MT')) + kv('VLSFO', recCell(d, 'vlsfo')) + kv('LSMGO', recCell(d, 'lsmgo')) + kv('HSFO', recCell(d, 'hsfo')) + '</div></div>' +
      '<div class="at-sec"><div class="at-sec-h">Remarks &amp; notes</div><div class="at-kv">' + kv('Remarks', d.remarks ? esc(d.remarks) : muted()) + kv('Notes', d.notes ? esc(d.notes) : muted()) + '</div></div>' +
      '<div class="at-sec" data-p-hist-sec><div class="at-sec-h">Change history</div><div data-p-hist data-testid="vd-p-history"><span class="muted">Loading…</span></div></div>';
    fetch(C.api + '?a=history&id=' + d.id, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (res) {
      if (S.openId !== d.id) return;
      var box = $('[data-p-hist]'); if (!box) return;
      if (!res.ok) { box.innerHTML = muted(esc(res.error || 'Could not load the history.')); return; }
      box.innerHTML = res.history.length ? '<ul class="at-log vd-hist">' + res.history.map(function (h) {
        return '<li><b>' + esc(h.field) + '</b>: <span class="ov">' + (h.old ? esc(h.old) : '—') + '</span> → <span class="nv">' + (h.new ? esc(h.new) : '(empty)') + '</span>' +
          '<small>' + fdate(h.at, true) + (h.by ? ' · ' + esc(h.by) : '') + ' · ' + esc(h.source === 'grid' ? 'Vessels Data' : h.source === 'bulk' ? 'bulk change' : h.source) + '</small></li>';
      }).join('') + '</ul>' : '<span class="muted">No changes made in Vessels Data yet.</span>';
      if (focusHistory) { var sec = $('[data-p-hist-sec]'); if (sec) sec.scrollIntoView({ block: 'start' }); }
    });
  }

  // unsaved edits: warn before leaving
  window.addEventListener('beforeunload', function (ev) { if (S.pending > 0 || (ED && ((ED.value !== undefined ? ED.value : ED.input.value) !== ED.orig))) { ev.preventDefault(); ev.returnValue = ''; } });

  toolbarState();
  load();
})();
