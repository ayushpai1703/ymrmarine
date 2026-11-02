/* Cancelled Vessels (cancelled.php) — the Invoice Airtable grid: summary cards, survey-type
   quick filters, Hide fields, Filter, Sort, Export, search, resize / move columns (saved per
   user), paging, selection and a record panel. Data: ajax/cancelled_list.php (read-only). */
(function () {
  'use strict';
  var C = window.CN_CONFIG || {};
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  var COLS = {
    vessel:    { label: 'Vessel', icon: 'fa-ship', w: 200, sort: 'vessel', pk: true },
    report_no: { label: 'Report No', icon: 'fa-file-lines', w: 130, sort: 'report_no' },
    client:    { label: 'Client Name', icon: 'fa-building', w: 180, sort: 'client' },
    agent:     { label: 'Agent', icon: 'fa-people-group', w: 160, sort: 'agent' },
    type:      { label: 'Survey Type', icon: 'fa-tags', w: 150, sort: 'type' },
    port:      { label: 'Port', icon: 'fa-location-dot', w: 150, sort: 'port' },
    surveyor:  { label: 'Surveyor', icon: 'fa-user-tie', w: 170, sort: 'surveyor' },
    date:      { label: 'Date', icon: 'fa-regular fa-calendar', w: 140, sort: 'date' },
    status:    { label: 'Status', icon: 'fa-circle-half-stroke', w: 110 },
    actions:   { label: 'Actions', icon: 'fa-bolt', w: 140 }
  };
  if (!C.showSurveyor) delete COLS.surveyor;
  var DEFAULT_ORDER = ['report_no', 'client', 'agent', 'type', 'port', 'surveyor', 'date', 'status', 'actions'].filter(function (k) { return COLS[k]; });
  var SORT_LABEL = {}; Object.keys(COLS).forEach(function (k) { if (COLS[k].sort) SORT_LABEL[COLS[k].sort] = COLS[k].label; });

  var P = C.prefs || {}, I = C.init || {};
  var order = (P.order || []).filter(function (k) { return COLS[k] && !COLS[k].pk; });
  DEFAULT_ORDER.forEach(function (k) { if (order.indexOf(k) < 0) order.push(k); });
  var S = {
    q: I.q || '', f: {}, sort: Array.isArray(P.sort) ? P.sort.filter(function (s) { return SORT_LABEL[s.f]; }) : [],
    page: 1, per: [25, 50, 100, 200].indexOf(+P.per) >= 0 ? +P.per : 50,
    order: order, hidden: (P.hidden || []).filter(function (k) { return COLS[k] && !COLS[k].pk; }), widths: P.widths || {},
    rows: [], total: 0, pages: 1, sel: {}, openId: null, seq: 0
  };
  ['type', 'place', 'client', 'surveyor'].forEach(function (k) { if (I[k]) S.f[k] = String(I[k]); });

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  var MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  function fdate(d, time) { if (!d) return ''; var p = String(d).slice(0, 10).split('-'); var t = time && String(d).length > 10 ? ' ' + String(d).slice(11, 16) : ''; return p.length === 3 ? p[2] + ' ' + MON[+p[1] - 1] + ' ' + p[0] + t : esc(d); }
  function muted(t) { return '<span class="muted">' + (t || '—') + '</span>'; }
  function debounce(fn, ms) { var t; return function () { var a = arguments, me = this; clearTimeout(t); t = setTimeout(function () { fn.apply(me, a); }, ms); }; }
  function initials(n) { var p = String(n || '').split('+')[0].trim().split(/\s+/); return ((p[0] || '')[0] || '') + ((p[1] || '')[0] || ''); }
  function visible() { return ['vessel'].concat(S.order.filter(function (k) { return S.hidden.indexOf(k) < 0; })); }
  function width(k) { return Math.max(60, +(S.widths[k] || COLS[k].w)); }
  function rowById(id) { for (var i = 0; i < S.rows.length; i++) if (S.rows[i].id === id) return S.rows[i]; return null; }
  var savePrefs = debounce(function () {
    var f = new FormData(); f.append('a', 'prefs'); f.append('csrf_token', C.csrf);
    f.append('prefs', JSON.stringify({ order: S.order, hidden: S.hidden, widths: S.widths, sort: S.sort, per: S.per }));
    fetch(C.api, { method: 'POST', body: f, credentials: 'same-origin', headers: { 'X-CSRF-Token': C.csrf } });
  }, 600);
  function params() {
    var u = new URLSearchParams();
    if (S.q) u.set('q', S.q);
    Object.keys(S.f).forEach(function (k) { if (S.f[k]) u.set(k, S.f[k]); });
    return u;
  }
  function syncUrl() { try { var q = params().toString(); history.replaceState(null, '', 'cancelled.php' + (q ? '?' + q : '')); } catch (e) {} }

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
      if (!res.ok) return;
      S.rows = res.rows; S.total = res.total; S.pages = res.pages; S.page = res.page;
      Object.keys(res.summary || {}).forEach(function (k) { var el = $('[data-sum="' + k + '"]'); if (el) el.textContent = (+res.summary[k]).toLocaleString('en-US'); });
      chips(res.chips || {}, res.chips_all || 0);
      render(); toolbarState();
      if (S.openId) { var r = rowById(S.openId); if (r) renderPanel(r); else closePanel(); }
    }).catch(function () { if (my === S.seq) tbody.style.opacity = ''; });
  }
  function skeleton() {
    var cols = visible(), h = '';
    for (var i = 0; i < 8; i++) h += '<tr class="at-skel"><td class="c-no"><span></span></td>' + cols.map(function () { return '<td><span style="width:' + (40 + (i * 13) % 50) + '%"></span></td>'; }).join('') + '</tr>';
    renderHead(); tbody.innerHTML = h;
  }
  function chips(map, all) {
    var cur = S.f.type || '', keys = Object.keys(map);
    if (cur && keys.indexOf(cur) < 0) keys.push(cur);
    $('[data-chips]').innerHTML = '<button type="button" class="at-chip' + (cur ? '' : ' on') + '" data-type-chip="" data-testid="cn-chip-all"><i class="at-dot cn"></i>All Cancelled <span class="cnt">' + all + '</span></button> ' +
      keys.map(function (t) { return '<button type="button" class="at-chip' + (cur === t ? ' on' : '') + '" data-type-chip="' + esc(t) + '" data-testid="cn-chip-' + esc(t.toLowerCase().replace(/\s+/g, '-')) + '"><i class="at-dot ty"></i>' + esc(t) + ' <span class="cnt">' + (map[t] || 0) + '</span></button>'; }).join(' ');
  }
  $('[data-chips]').addEventListener('click', function (ev) { var b = ev.target.closest('[data-type-chip]'); if (!b) return; S.f.type = b.getAttribute('data-type-chip'); S.sel = {}; toolbarState(); reload(); });

  // ---------- grid ----------
  function renderHead() {
    var cols = visible();
    $('[data-cols]').innerHTML = '<col style="width:56px">' + cols.map(function (k) { return '<col data-col="' + k + '" style="width:' + width(k) + 'px">'; }).join('');
    var allSel = S.rows.length && S.rows.every(function (r) { return S.sel[r.id]; });
    $('[data-head]').innerHTML = '<tr><th class="c-no"><input type="checkbox" data-selall aria-label="Select all on this page" data-testid="cn-selall"' + (allSel ? ' checked' : '') + '></th>' + cols.map(function (k) {
      var c = COLS[k], si = -1;
      S.sort.forEach(function (s, i) { if (s.f === c.sort) si = i; });
      var ind = si >= 0 ? '<span class="sorti">' + (S.sort.length > 1 ? (si + 1) : '') + '<i class="fa-solid fa-arrow-' + (S.sort[si].d === 'desc' ? 'down' : 'up') + '"></i></span>' : '';
      var ic = c.icon.indexOf(' ') > 0 ? c.icon : 'fa-solid ' + c.icon;
      return '<th data-k="' + k + '" class="' + (c.pk ? 'c-pk ' : '') + (c.sort ? 'sortable' : '') + '"' + (c.pk ? '' : ' draggable="true"') + ' data-testid="cn-th-' + k + '" title="' + esc(c.label) + (c.sort ? ' — click to sort, Shift+click to add' : '') + '">' +
        '<div class="th"><i class="ic ' + ic + '"></i><span class="lbl">' + esc(c.label) + '</span>' + ind + '</div><span class="rz" data-rz="' + k + '"></span></th>';
    }).join('') + '</tr>';
  }
  function cell(k, d) {
    switch (k) {
      case 'vessel': return '<a class="vn" href="vessel_detail.php?id=' + d.id + '" data-testid="cn-vessel-' + d.id + '">' + (d.vessel ? esc(d.vessel) : '—') + '</a>';
      case 'report_no': return d.report_no ? esc(d.report_no) : muted();
      case 'client': return d.client ? esc(d.client) : muted();
      case 'agent': return d.agent ? esc(d.agent) : muted();
      case 'type': return d.type ? '<span class="at-tag">' + esc(d.type) + '</span>' : muted();
      case 'port': return d.port ? esc(d.port) : muted();
      case 'surveyor': return d.surveyor ? '<span class="at-sv"><i>' + esc(initials(d.surveyor).toUpperCase()) + '</i>' + esc(d.surveyor) + '</span>' : muted();
      case 'date': return d.date ? fdate(d.date, true) : muted();
      case 'status': return '<span class="at-pill cancelled-st">Cancelled</span>';
      case 'actions': return '<span class="at-act"><a href="vessel_detail.php?id=' + d.id + '" data-testid="cn-view-' + d.id + '"><i class="fa-solid fa-eye"></i> View</a>' +
        (C.canEdit ? '<a class="edit" href="vessel_detail.php?id=' + d.id + '" data-testid="cn-edit-desk-' + d.id + '"><i class="fa-solid fa-pen"></i> Edit</a>' : '') + '</span>';
    }
    return '';
  }
  function render() {
    renderHead();
    var cols = visible(), off = (S.page - 1) * S.per;
    tbody.innerHTML = S.rows.map(function (d, i) {
      return '<tr data-id="' + d.id + '" class="' + (S.sel[d.id] ? 'sel' : '') + (S.openId === d.id ? ' open' : '') + '" data-testid="cn-row-' + d.id + '">' +
        '<td class="c-no"><span class="num" data-row-num>' + (off + i + 1) + '</span><input type="checkbox" data-sel aria-label="Select row"' + (S.sel[d.id] ? ' checked' : '') + '></td>' +
        cols.map(function (k) {
          var tip = ['client', 'agent', 'port', 'type', 'surveyor'].indexOf(k) >= 0 && d[k] ? ' title="' + esc(d[k]) + '"' : '';
          return '<td class="' + (COLS[k].pk ? 'c-pk' : '') + '" data-c="' + k + '"' + tip + '>' + cell(k, d) + '</td>';
        }).join('') + '</tr>';
    }).join('');
    $('[data-empty]').hidden = S.rows.length > 0;
    $('[data-foot]').innerHTML = S.rows.length ? '<tr><td class="c-no"></td>' + cols.map(function (k) {
      return '<td class="' + (COLS[k].pk ? 'c-pk' : '') + '">' + (k === 'vessel' ? '<b>' + S.total.toLocaleString('en-US') + '</b> record' + (S.total === 1 ? '' : 's') : '') + '</td>';
    }).join('') + '</tr>' : '';
    var from = S.total ? off + 1 : 0, to = off + S.rows.length, filtered = params().toString() !== '';
    $('[data-count-text]').innerHTML = 'Showing <b>' + from + '–' + to + '</b> of <b>' + S.total.toLocaleString('en-US') + '</b> cancelled vessel' + (S.total === 1 ? '' : 's') + (filtered ? ' (filtered)' : '') +
      (S.sort.length ? ' · sorted by ' + S.sort.map(function (s) { return SORT_LABEL[s.f] + (s.d === 'desc' ? ' ↓' : ' ↑'); }).join(', ') : ' · newest first');
    $('[data-pginfo]').textContent = S.page + ' / ' + S.pages;
    $('[data-pg="-1"]').disabled = S.page <= 1; $('[data-pg="1"]').disabled = S.page >= S.pages;
    $('[data-per]').value = String(S.per);
    selInfo();
  }
  function markOpen() { $$('tr[data-id]', tbody).forEach(function (tr) { tr.classList.toggle('open', +tr.getAttribute('data-id') === S.openId); }); }
  function selInfo() {
    var n = Object.keys(S.sel).length, el = $('[data-sel-info]');
    el.hidden = !n; el.innerHTML = n + ' selected <button type="button" data-selclear>clear</button>';
    $('[data-sel-count]').textContent = '(' + n + ')';
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
    var si = $('[data-testid="cn-search"]'); if (document.activeElement !== si) si.value = S.q;
  }
  var reload = function () { S.page = 1; load(); };
  var reloadSoon = debounce(reload, 300);

  // ---------- popovers ----------
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
      if (window.innerWidth >= 992) box.style.left = Math.min(b.offsetLeft, shell.clientWidth - box.offsetWidth - 8) + 'px'; else box.style.left = '';
    });
  });
  document.addEventListener('click', function (ev) {
    if (openPop && document.contains(ev.target) && !ev.target.closest('.at-pop') && !ev.target.closest('[data-pop]')) closePop();
  });
  document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') { if (openPop) closePop(); else if (S.openId) closePanel(); } });

  function renderFields() {
    $('[data-fields]').innerHTML = '<div class="at-field fixed"><i class="grip fa-solid fa-lock"></i>' + esc(COLS.vessel.label) + ' <small class="muted">primary</small></div>' +
      S.order.map(function (k) {
        return '<div class="at-field" draggable="true" data-fk="' + k + '" data-testid="cn-field-' + k + '"><i class="grip fa-solid fa-grip-vertical"></i>' + esc(COLS[k].label) +
          '<button type="button" class="tg' + (S.hidden.indexOf(k) < 0 ? ' on' : '') + '" data-tg="' + k + '" aria-label="Show ' + esc(COLS[k].label) + '" data-testid="cn-tg-' + k + '"></button></div>';
      }).join('');
  }
  $('[data-popbox="fields"]').addEventListener('click', function (ev) {
    var t = ev.target.closest('[data-tg]'), all = ev.target.closest('[data-fields-all]');
    if (t) { var k = t.getAttribute('data-tg'), i = S.hidden.indexOf(k); if (i >= 0) S.hidden.splice(i, 1); else S.hidden.push(k); }
    else if (all) S.hidden = all.getAttribute('data-fields-all') === '1' ? [] : S.order.slice();
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

  $$('[data-f]').forEach(function (el) {
    var ev = el.tagName === 'SELECT' || el.type === 'date' ? 'change' : 'input';
    el.addEventListener(ev, function () { S.f[el.getAttribute('data-f')] = el.value.trim(); toolbarState(); reloadSoon(); });
  });
  $$('[data-act="clear"]').forEach(function (b) { b.addEventListener('click', function () { S.f = {}; S.q = ''; toolbarState(); reload(); }); });
  $('[data-testid="cn-search"]').addEventListener('input', debounce(function (ev) { S.q = ev.target.value.trim(); toolbarState(); reload(); }, 300));

  function renderSorts() {
    var opts = function (cur) { return Object.keys(SORT_LABEL).map(function (f) { return '<option value="' + f + '"' + (f === cur ? ' selected' : '') + '>' + SORT_LABEL[f] + '</option>'; }).join(''); };
    $('[data-sorts]').innerHTML = S.sort.length ? S.sort.map(function (s, i) {
      return '<div class="at-sortrow" data-si="' + i + '"><select data-sf aria-label="Sort field">' + opts(s.f) + '</select><select data-sd aria-label="Direction"><option value="asc"' + (s.d !== 'desc' ? ' selected' : '') + '>Ascending</option><option value="desc"' + (s.d === 'desc' ? ' selected' : '') + '>Descending</option></select>' +
        '<button type="button" data-sx aria-label="Remove sort"><i class="fa-solid fa-xmark"></i></button></div>';
    }).join('') : '<div class="muted" style="font-size:12px;margin-bottom:6px">Default order — newest first.</div>';
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

  var thead = $('[data-head]'), resizing = null, dragK = null;
  thead.addEventListener('click', function (ev) {
    if (ev.target.closest('[data-rz]') || resizing) return;
    if (ev.target.matches('[data-selall]')) { S.rows.forEach(function (r) { if (ev.target.checked) S.sel[r.id] = 1; else delete S.sel[r.id]; }); render(); return; }
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

  tbody.addEventListener('click', function (ev) {
    var tr = ev.target.closest('tr[data-id]'); if (!tr) return;
    var id = +tr.getAttribute('data-id');
    if (ev.target.matches('[data-sel]')) { if (ev.target.checked) S.sel[id] = 1; else delete S.sel[id]; tr.classList.toggle('sel', ev.target.checked); selInfo(); renderHead(); return; }
    if (ev.target.closest('a, button, td.c-no')) return;
    openPanel(id);
  });
  $('.at-quick').addEventListener('click', function (ev) { if (ev.target.closest('[data-selclear]')) { S.sel = {}; render(); } });

  $$('[data-pg]').forEach(function (b) { b.addEventListener('click', function () { S.page = Math.min(S.pages, Math.max(1, S.page + +b.getAttribute('data-pg'))); load(); grid.scrollTop = 0; }); });
  $('[data-per]').addEventListener('change', function (ev) { S.per = +ev.target.value; savePrefs(); reload(); });

  $('[data-popbox="export"]').addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-export]'); if (!b) return;
    var scope = b.getAttribute('data-export'), ids = Object.keys(S.sel), u;
    if (scope === 'selected' && !ids.length) { alert('Tick the vessels to export first.'); return; }
    u = scope === 'filtered' ? params() : new URLSearchParams();
    if (scope === 'selected') u.set('ids', ids.join(','));
    if (S.sort.length) u.set('sort', JSON.stringify(S.sort));
    closePop();
    window.location.href = C.export + (u.toString() ? '?' + u.toString() : '');
  });

  // ---------- record panel ----------
  var panel = $('[data-panel]'), body = $('[data-p-body]'), scrim = $('[data-scrim]');
  function openPanel(id) {
    var d = rowById(id); if (!d) return;
    S.openId = id; markOpen();
    panel.classList.add('open'); panel.setAttribute('aria-hidden', 'false'); scrim.hidden = false;
    renderPanel(d);
  }
  function closePanel() { S.openId = null; panel.classList.remove('open'); panel.setAttribute('aria-hidden', 'true'); scrim.hidden = true; markOpen(); }
  scrim.addEventListener('click', closePanel);
  $('[data-close]').addEventListener('click', closePanel);
  function renderPanel(d) {
    $('[data-p-title]').textContent = d.vessel || 'Vessel';
    var kv = function (k, v) { return '<div>' + k + '</div><div>' + v + '</div>'; };
    body.innerHTML = '<div class="at-sec"><div class="at-sec-h">Vessel &amp; survey</div><div class="at-kv">' +
      kv('Vessel', d.vessel ? '<b>' + esc(d.vessel) + '</b>' : muted()) + kv('Report No', d.report_no ? esc(d.report_no) : muted()) +
      kv('Client', d.client ? esc(d.client) : muted()) + kv('Agent', d.agent ? esc(d.agent) : muted()) +
      kv('Survey Type', d.type ? '<span class="at-tag">' + esc(d.type) + '</span>' : muted()) + kv('Port', d.port ? esc(d.port) : muted()) +
      (C.showSurveyor ? kv('Surveyor', d.surveyor ? esc(d.surveyor) : muted()) : '') +
      kv('Assigned', d.assigned ? fdate(d.assigned, true) : muted()) + kv('Survey Completed', d.completed ? fdate(d.completed) : muted()) +
      kv('Date', d.date ? fdate(d.date, true) : muted()) + kv('Status', '<span class="at-pill cancelled-st">Cancelled</span>') + '</div>' +
      '<div class="at-p-actions"><a class="at-btn pri" href="vessel_detail.php?id=' + d.id + '" data-testid="cn-p-view"><i class="fa-solid fa-eye"></i> View vessel</a>' +
      (C.canEdit ? '<a class="at-btn" href="vessel_detail.php?id=' + d.id + '" data-testid="cn-p-edit"><i class="fa-solid fa-pen"></i> Edit</a>' : '') + '</div></div>';
  }

  toolbarState();
  load();
})();
