/* Invoice Airtable (invoices_airtable.php) — grid view, filters, sort, fields, record panel.
   Data: ajax/invoice_ledger.php (JSON). All money comes from the server (DECIMAL); the page
   only formats it. */
(function () {
  'use strict';
  var C = window.AT_CONFIG || {};
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  // ---------- columns (invoice number is the primary field: always first, always shown) ----------
  var COLS = {
    invoice_no:   { label: 'Invoice Number', icon: 'fa-hashtag', w: 170, sort: 'invoice_no', pk: true },
    invoice_date: { label: 'Invoice Date', icon: 'fa-regular fa-calendar', w: 112, sort: 'invoice_date' },
    client:       { label: 'Client Name', icon: 'fa-building', w: 190, sort: 'client' },
    vessel:       { label: 'Vessel Name', icon: 'fa-ship', w: 170, sort: 'vessel' },
    port:         { label: 'Port', icon: 'fa-location-dot', w: 150, sort: 'port' },
    survey_ref:   { label: 'Survey Reference', icon: 'fa-file-lines', w: 150 },
    amount:       { label: 'Invoice Amount', icon: 'fa-indian-rupee-sign', w: 140, sort: 'amount', num: true },
    received:     { label: 'Amount Received', icon: 'fa-indian-rupee-sign', w: 140, sort: 'received', num: true },
    outstanding:  { label: 'Outstanding Amount', icon: 'fa-indian-rupee-sign', w: 150, sort: 'outstanding', num: true },
    due_date:     { label: 'Payment Due Date', icon: 'fa-regular fa-calendar-check', w: 170, sort: 'due_date' },
    last_paid:    { label: 'Last Payment Date', icon: 'fa-regular fa-calendar', w: 130, sort: 'last_paid' },
    status:       { label: 'Payment Status', icon: 'fa-circle-half-stroke', w: 135, sort: 'status' },
    document:     { label: 'Invoice Document', icon: 'fa-paperclip', w: 170 },
    remarks:      { label: 'Remarks', icon: 'fa-align-left', w: 200 },
    actions:      { label: 'Actions', icon: 'fa-bolt', w: 150 }
  };
  var DEFAULT_ORDER = ['invoice_date', 'client', 'vessel', 'port', 'survey_ref', 'amount', 'received', 'outstanding', 'due_date', 'last_paid', 'status', 'document', 'remarks', 'actions'];
  var SORT_LABEL = { invoice_no: 'Invoice Number', invoice_date: 'Invoice Date', due_date: 'Due Date', amount: 'Invoice Amount', received: 'Amount Received', outstanding: 'Outstanding Amount', client: 'Client', vessel: 'Vessel', port: 'Port', last_paid: 'Last Payment Date', status: 'Payment Status' };

  var P = C.prefs || {};
  var order = (P.order || []).filter(function (k) { return COLS[k] && !COLS[k].pk; });
  DEFAULT_ORDER.forEach(function (k) { if (order.indexOf(k) < 0) order.push(k); });
  var S = {
    q: '', f: {}, sort: Array.isArray(P.sort) ? P.sort.filter(function (s) { return SORT_LABEL[s.f]; }) : [],
    page: 1, per: [25, 50, 100, 200].indexOf(+P.per) >= 0 ? +P.per : 50,
    order: order, hidden: (P.hidden || []).filter(function (k) { return COLS[k] && !COLS[k].pk; }), widths: P.widths || {},
    rows: [], total: 0, pages: 1, summary: null, sel: {}, openKey: null, seq: 0
  };

  // ---------- helpers ----------
  var money = new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', minimumFractionDigits: 2, maximumFractionDigits: 2 });
  function inr(v) { return v === null || v === undefined || v === '' ? '' : money.format(+v); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  var MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  function fdate(d) { if (!d) return ''; var p = String(d).slice(0, 10).split('-'); return p.length === 3 ? p[2] + ' ' + MON[+p[1] - 1] + ' ' + p[0] : esc(d); }
  function days(a, b) { return Math.round((Date.parse(a + 'T00:00:00Z') - Date.parse(b + 'T00:00:00Z')) / 86400000); }
  function slug(s) { return String(s || '').toLowerCase().replace(/\s+/g, '-'); }
  function pill(s) { return '<span class="at-pill ' + slug(s) + '" data-status="' + esc(s) + '">' + esc(s) + '</span>'; }
  function muted(t) { return '<span class="muted">' + (t || '—') + '</span>'; }
  function toast(icon, title) { if (window.Swal) Swal.fire({ toast: true, position: 'top-end', icon: icon, title: title, showConfirmButton: false, timer: 2600, timerProgressBar: true }); }
  function rid() { var a = new Uint8Array(16); (window.crypto || window.msCrypto).getRandomValues(a); return Array.prototype.map.call(a, function (b) { return ('0' + b.toString(16)).slice(-2); }).join(''); }
  function debounce(fn, ms) { var t; return function () { var a = arguments, me = this; clearTimeout(t); t = setTimeout(function () { fn.apply(me, a); }, ms); }; }
  function post(data) {
    data.append('csrf_token', C.csrf);
    return fetch(C.api, { method: 'POST', body: data, credentials: 'same-origin', headers: { 'X-CSRF-Token': C.csrf } })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Unexpected server response (' + r.status + ').' }; }); });
  }
  function fd(obj) { var f = new FormData(); Object.keys(obj).forEach(function (k) { f.append(k, obj[k]); }); return f; }
  function visible() { return ['invoice_no'].concat(S.order.filter(function (k) { return S.hidden.indexOf(k) < 0; })); }
  function width(k) { return Math.max(60, +(S.widths[k] || COLS[k].w)); }

  var savePrefs = debounce(function () {
    post(fd({ a: 'prefs', prefs: JSON.stringify({ order: S.order, hidden: S.hidden, widths: S.widths, sort: S.sort, per: S.per }) }));
  }, 600);

  function params() {
    var u = new URLSearchParams();
    if (S.q) u.set('q', S.q);
    Object.keys(S.f).forEach(function (k) { if (S.f[k] !== '' && S.f[k] != null) u.set(k, S.f[k]); });
    if (S.sort.length) u.set('sort', JSON.stringify(S.sort));
    return u;
  }

  // ---------- data ----------
  var grid = $('[data-grid]'), tbody = $('[data-body]');
  function load(keepPanel) {
    var my = ++S.seq, u = params();
    u.set('a', 'list'); u.set('page', S.page); u.set('per', S.per);
    if (!S.rows.length) skeleton(); else tbody.style.opacity = '.55';
    $('.at-sum').classList.add('loading');
    return fetch(C.api + '?' + u.toString(), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (res) {
      if (my !== S.seq) return;
      tbody.style.opacity = '';
      $('.at-sum').classList.remove('loading');
      if (!res.ok) { toast('error', res.error || 'Could not load invoices.'); return; }
      S.rows = res.rows; S.total = res.total; S.pages = res.pages; S.page = res.page; S.summary = res.summary; C.today = res.today || C.today;
      render(); summary(); toolbarState();
      if (keepPanel && S.openKey) markOpen();
    }).catch(function () { if (my === S.seq) { tbody.style.opacity = ''; toast('error', 'Network error — could not load invoices.'); } });
  }
  function skeleton() {
    var cols = visible(), h = '';
    for (var i = 0; i < 8; i++) h += '<tr class="at-skel"><td class="c-no"><span></span></td>' + cols.map(function () { return '<td><span style="width:' + (40 + (i * 13) % 50) + '%"></span></td>'; }).join('') + '</tr>';
    renderHead(); tbody.innerHTML = h;
  }

  // ---------- summary ----------
  function summary() {
    var s = S.summary || {};
    var set = function (k, v) { var el = $('[data-sum="' + k + '"]'); if (el) el.textContent = v; };
    var sub = function (k, v) { var el = $('[data-sum-sub="' + k + '"]'); if (el) el.textContent = v; };
    set('invoices', (s.invoices || 0).toLocaleString('en-IN'));
    sub('invoices', (s.paid || 0) + ' paid' + (s.cancelled ? ' · ' + s.cancelled + ' cancelled (not counted)' : ''));
    set('amount', inr(s.amount || 0)); set('received', inr(s.received || 0)); set('outstanding', inr(s.outstanding || 0));
    set('overdue', inr(s.overdue || 0)); sub('overdue', (s.overdue_count || 0) + ' invoice' + (s.overdue_count === 1 ? '' : 's') + ' past due');
    set('due_soon', (s.due_soon || 0).toLocaleString('en-IN')); sub('due_soon', 'within ' + C.dueSoonDays + ' days · ' + inr(s.due_soon_amount || 0));
  }

  // ---------- grid ----------
  function renderHead() {
    var cols = visible();
    $('[data-cols]').innerHTML = '<col style="width:56px">' + cols.map(function (k) { return '<col data-col="' + k + '" style="width:' + width(k) + 'px">'; }).join('');
    var allSel = S.rows.length && S.rows.every(function (r) { return S.sel[r.key]; });
    $('[data-head]').innerHTML = '<tr><th class="c-no"><input type="checkbox" data-selall aria-label="Select all on this page" data-testid="at-selall"' + (allSel ? ' checked' : '') + '></th>' + cols.map(function (k) {
      var c = COLS[k], si = -1;
      S.sort.forEach(function (s, i) { if (s.f === c.sort) si = i; });
      var ind = si >= 0 ? '<span class="sorti">' + (S.sort.length > 1 ? (si + 1) : '') + '<i class="fa-solid fa-arrow-' + (S.sort[si].d === 'desc' ? 'down' : 'up') + '"></i></span>' : '';
      var ic = c.icon.indexOf(' ') > 0 ? c.icon : 'fa-solid ' + c.icon;
      return '<th data-k="' + k + '" class="' + (c.pk ? 'c-pk ' : '') + (c.sort ? 'sortable' : '') + (c.num ? ' num-r' : '') + '"' + (c.pk ? '' : ' draggable="true"') + ' data-testid="at-th-' + k + '" title="' + esc(c.label) + (c.sort ? ' — click to sort, Shift+click to add' : '') + '">' +
        '<div class="th"><i class="ic ' + ic + '"></i><span class="lbl">' + esc(c.label) + '</span>' + ind + '</div><span class="rz" data-rz="' + k + '"></span></th>';
    }).join('') + '</tr>';
  }
  function cell(k, r) {
    switch (k) {
      case 'invoice_no': return (r.invoice_no ? esc(r.invoice_no) : muted('No number')) + (r.kind === 'recovery' ? '<span class="at-kind">Recovery</span>' : '');
      case 'invoice_date': return r.invoice_date ? fdate(r.invoice_date) : muted();
      case 'client': return r.client ? esc(r.client) : muted();
      case 'vessel': return r.vessel ? esc(r.vessel) : muted();
      case 'port': return r.port ? esc(r.port) : muted();
      case 'survey_ref': return r.survey_ref ? esc(r.survey_ref) : muted();
      case 'amount': return r.amount === null ? muted('Add amount') : inr(r.amount);
      case 'received': return inr(r.received || 0);
      case 'outstanding': return r.outstanding === null ? muted() : inr(r.outstanding);
      case 'due_date': return dueCell(r);
      case 'last_paid': return r.last_paid ? fdate(r.last_paid) : muted();
      case 'status': return pill(r.status);
      case 'document':
        if (!r.has_doc) return muted('No file');
        var u = C.invoiceFile + '?a=file&id=' + r.invoice_id + (r.kind === 'recovery' ? '&which=recovery' : '');
        return '<span class="at-doc"><a href="' + u + '&mode=view" target="_blank" rel="noopener" title="Open ' + esc(r.doc_name) + '" data-testid="at-doc-' + r.key + '"><i class="fa-solid fa-file-pdf"></i> PDF</a>' +
          '<a class="dl" href="' + u + '&mode=download" title="Download" aria-label="Download invoice" data-testid="at-docdl-' + r.key + '"><i class="fa-solid fa-download"></i></a></span>';
      case 'remarks': return r.remarks ? esc(r.remarks) : '';
      case 'actions': return '<span class="at-act"><button type="button" data-open title="Open record" data-testid="at-open-' + r.key + '"><i class="fa-solid fa-up-right-and-down-left-from-center"></i></button>' +
        (r.status !== 'Cancelled' && r.status !== 'Paid' ? '<button type="button" data-addpay data-testid="at-addpay-' + r.key + '"><i class="fa-solid fa-plus"></i> Payment</button>' : '') + '</span>';
    }
    return '';
  }
  function dueCell(r) {
    if (!r.due_date) return muted('Set due date');
    var tag = '';
    if (r.status !== 'Paid' && r.status !== 'Cancelled') {
      var d = days(r.due_date, C.today);
      if (d < 0) tag = '<small class="late">' + (-d) + 'd overdue</small>';
      else if (d <= C.dueSoonDays) tag = '<small class="soon">' + (d === 0 ? 'today' : 'in ' + d + 'd') + '</small>';
    }
    return '<span class="at-due">' + fdate(r.due_date) + tag + '</span>';
  }
  function outClass(r) {
    if (r.status === 'Cancelled' || r.outstanding === null) return '';
    if (r.status === 'Overdue') return 'o-over';
    if (r.outstanding <= 0 && r.amount > 0) return 'o-zero';
    if (r.received > 0 && r.outstanding > 0) return 'o-part';
    return '';
  }
  function render() {
    renderHead();
    var cols = visible(), off = (S.page - 1) * S.per;
    tbody.innerHTML = S.rows.map(function (r, i) {
      return '<tr data-key="' + r.key + '" class="' + (S.sel[r.key] ? 'sel' : '') + (S.openKey === r.key ? ' open' : '') + '" data-testid="at-row-' + r.key + '">' +
        '<td class="c-no"><span class="num">' + (off + i + 1) + '</span><input type="checkbox" data-sel aria-label="Select row"' + (S.sel[r.key] ? ' checked' : '') + '></td>' +
        cols.map(function (k) {
          var c = COLS[k], cls = (c.pk ? 'c-pk ' : '') + (c.num ? 'num-r ' : '') + (k === 'outstanding' ? outClass(r) : '');
          var title = k === 'remarks' && r.remarks ? ' title="' + esc(r.remarks) + '"' : '';
          return '<td class="' + cls + '" data-c="' + k + '"' + title + '>' + cell(k, r) + '</td>';
        }).join('') + '</tr>';
    }).join('');
    $('[data-empty]').hidden = S.rows.length > 0;
    var sm = S.summary || {};
    $('[data-foot]').innerHTML = S.rows.length ? '<tr><td class="c-no"></td>' + cols.map(function (k) {
      var v = '';
      if (k === 'invoice_no') v = '<b>' + S.total.toLocaleString('en-IN') + '</b> record' + (S.total === 1 ? '' : 's');
      if (k === 'amount') v = 'Sum <b>' + inr(sm.amount || 0) + '</b>';
      if (k === 'received') v = 'Sum <b>' + inr(sm.received || 0) + '</b>';
      if (k === 'outstanding') v = 'Sum <b>' + inr(sm.outstanding || 0) + '</b>';
      return '<td class="' + (COLS[k].pk ? 'c-pk ' : '') + (COLS[k].num ? 'num-r' : '') + '">' + v + '</td>';
    }).join('') + '</tr>' : '';
    var from = S.total ? off + 1 : 0, to = off + S.rows.length;
    $('[data-count]').innerHTML = 'Showing <b>' + from + '–' + to + '</b> of <b>' + S.total.toLocaleString('en-IN') + '</b> invoice' + (S.total === 1 ? '' : 's') + (S.sort.length ? ' · sorted by ' + S.sort.map(function (s) { return SORT_LABEL[s.f] + (s.d === 'desc' ? ' ↓' : ' ↑'); }).join(', ') : ' · newest invoice first');
    $('[data-pginfo]').textContent = S.page + ' / ' + S.pages;
    $('[data-pg="-1"]').disabled = S.page <= 1; $('[data-pg="1"]').disabled = S.page >= S.pages;
    $('[data-per]').value = String(S.per);
    selInfo();
  }
  function markOpen() { $$('tr[data-key]', tbody).forEach(function (tr) { tr.classList.toggle('open', tr.getAttribute('data-key') === S.openKey); }); }
  function selInfo() {
    var n = Object.keys(S.sel).length, el = $('[data-sel-info]');
    el.hidden = !n; el.innerHTML = n + ' selected <button type="button" data-selclear>clear</button>';
    $('[data-sel-count]').textContent = '(' + n + ')';
  }

  // ---------- toolbar state ----------
  function activeFilters() { return Object.keys(S.f).filter(function (k) { return S.f[k] !== '' && S.f[k] != null; }); }
  function toolbarState() {
    var nf = activeFilters().length;
    $('[data-n="filter"]').textContent = nf ? nf : '';
    $('[data-n="sort"]').textContent = S.sort.length ? S.sort.length : '';
    $('[data-n="fields"]').textContent = S.hidden.length ? S.hidden.length + ' hidden' : '';
    $('[data-pop="filter"]').classList.toggle('active-f', nf > 0);
    $('[data-pop="sort"]').classList.toggle('active-s', S.sort.length > 0);
    $('[data-pop="fields"]').classList.toggle('active-h', S.hidden.length > 0);
    $('.at-bar [data-act="clear"]').hidden = !(nf || S.q);
    $$('[data-quick]').forEach(function (b) { b.classList.toggle('on', (S.f.status || '') === b.getAttribute('data-quick')); });
    $$('[data-f]').forEach(function (el) { var k = el.getAttribute('data-f'); if (document.activeElement !== el) el.value = S.f[k] || ''; el.classList.toggle('on', !!S.f[k]); });
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
    // (a click that re-rendered its own popover leaves a detached target: keep the popover open)
    if (openPop && document.contains(ev.target) && !ev.target.closest('.at-pop') && !ev.target.closest('[data-pop]')) closePop();
  });
  document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') { if (openPop) closePop(); else if (S.openKey) closePanel(); } });

  // fields
  function renderFields() {
    $('[data-fields]').innerHTML = '<div class="at-field fixed"><i class="grip fa-solid fa-lock"></i>' + esc(COLS.invoice_no.label) + ' <small class="muted">primary</small></div>' +
      S.order.map(function (k) {
        var on = S.hidden.indexOf(k) < 0;
        return '<div class="at-field" draggable="true" data-fk="' + k + '" data-testid="at-field-' + k + '"><i class="grip fa-solid fa-grip-vertical"></i>' + esc(COLS[k].label) +
          '<button type="button" class="tg' + (on ? ' on' : '') + '" data-tg="' + k + '" aria-label="Show ' + esc(COLS[k].label) + '" data-testid="at-tg-' + k + '"></button></div>';
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

  // filters
  $$('[data-f]').forEach(function (el) {
    var ev = el.tagName === 'SELECT' || el.type === 'date' ? 'change' : 'input';
    el.addEventListener(ev, function () { S.f[el.getAttribute('data-f')] = el.value.trim(); toolbarState(); reloadSoon(); });
  });
  $$('[data-quick]').forEach(function (b) { b.addEventListener('click', function () { S.f.status = b.getAttribute('data-quick'); toolbarState(); reload(); }); });
  $$('[data-act="clear"]').forEach(function (b) { b.addEventListener('click', function () { S.f = {}; S.q = ''; $('[data-testid="at-search"]').value = ''; toolbarState(); reload(); }); });
  $('[data-testid="at-search"]').addEventListener('input', debounce(function (ev) { S.q = ev.target.value.trim(); toolbarState(); reload(); }, 300));

  // sort
  function renderSorts() {
    var opts = function (cur) { return Object.keys(SORT_LABEL).map(function (f) { return '<option value="' + f + '"' + (f === cur ? ' selected' : '') + '>' + SORT_LABEL[f] + '</option>'; }).join(''); };
    $('[data-sorts]').innerHTML = S.sort.length ? S.sort.map(function (s, i) {
      return '<div class="at-sortrow" data-si="' + i + '"><select data-sf aria-label="Sort field">' + opts(s.f) + '</select><select data-sd aria-label="Direction"><option value="asc"' + (s.d !== 'desc' ? ' selected' : '') + '>Ascending</option><option value="desc"' + (s.d === 'desc' ? ' selected' : '') + '>Descending</option></select>' +
        '<button type="button" data-sx aria-label="Remove sort"><i class="fa-solid fa-xmark"></i></button></div>';
    }).join('') : '<div class="muted" style="font-size:12px;margin-bottom:6px">No sort — newest invoice first.</div>';
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

  // header: sort, resize, drag-reorder, select all
  var thead = $('[data-head]'), resizing = null, dragK = null;
  thead.addEventListener('click', function (ev) {
    if (ev.target.closest('[data-rz]') || resizing) return;
    if (ev.target.matches('[data-selall]')) {
      S.rows.forEach(function (r) { if (ev.target.checked) S.sel[r.key] = 1; else delete S.sel[r.key]; });
      render(); return;
    }
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
    var k = h.getAttribute('data-rz'), col = $('col[data-col="' + k + '"]');
    resizing = { k: k, x: ev.clientX, w: width(k), col: col, h: h };
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
    var r = th.getBoundingClientRect(), after = ev.clientX > r.left + r.width / 2;
    $$('th', thead).forEach(function (x) { x.classList.remove('drop-l', 'drop-r'); });
    th.classList.add(after ? 'drop-r' : 'drop-l');
  });
  thead.addEventListener('drop', function (ev) {
    var th = ev.target.closest('th[draggable]'); if (!th || !dragK) return;
    ev.preventDefault();
    var r = th.getBoundingClientRect();
    moveCol(dragK, th.getAttribute('data-k'), ev.clientX > r.left + r.width / 2); dragK = null;
  });
  thead.addEventListener('dragend', function () { dragK = null; $$('th', thead).forEach(function (x) { x.classList.remove('drop-l', 'drop-r', 'dragging'); }); });

  // rows
  tbody.addEventListener('click', function (ev) {
    var tr = ev.target.closest('tr[data-key]'); if (!tr) return;
    var key = tr.getAttribute('data-key');
    if (ev.target.matches('[data-sel]')) { if (ev.target.checked) S.sel[key] = 1; else delete S.sel[key]; tr.classList.toggle('sel', ev.target.checked); selInfo(); renderHead(); return; }
    if (ev.target.closest('a')) return;
    if (ev.target.closest('[data-addpay]')) { openPanel(key, true); return; }
    if (ev.target.closest('td.c-no')) return;
    openPanel(key, false);
  });
  $('.at-quick').addEventListener('click', function (ev) { if (ev.target.closest('[data-selclear]')) { S.sel = {}; render(); } });

  // pager
  $$('[data-pg]').forEach(function (b) { b.addEventListener('click', function () { S.page = Math.min(S.pages, Math.max(1, S.page + +b.getAttribute('data-pg'))); load(); grid.scrollTop = 0; }); });
  $('[data-per]').addEventListener('change', function (ev) { S.per = +ev.target.value; savePrefs(); reload(); });

  // export
  $('[data-popbox="export"]').addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-export]'); if (!b) return;
    var scope = b.getAttribute('data-export'), keys = Object.keys(S.sel);
    if (scope === 'selected' && !keys.length) { toast('info', 'Tick the invoices to export first.'); return; }
    var u = scope === 'all' ? new URLSearchParams() : params();
    if (scope === 'all' && S.sort.length) u.set('sort', JSON.stringify(S.sort));
    u.set('scope', scope);
    if ((scope === 'selected' || scope === 'payments') && keys.length) u.set('keys', keys.join(','));
    closePop();
    window.location.href = C.export + '?' + u.toString();
  });

  // ---------- record panel ----------
  var panel = $('[data-panel]'), body = $('[data-p-body]'), scrim = $('[data-scrim]'), D = null;
  function openPanel(key, payForm) {
    S.openKey = key; markOpen();
    panel.classList.add('open'); panel.setAttribute('aria-hidden', 'false'); scrim.hidden = false;
    if (!D || D.row.key !== key) body.innerHTML = '<div class="muted" style="padding:20px 0">Loading…</div>';
    return fetch(C.api + '?a=detail&key=' + encodeURIComponent(key), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (res) {
      if (S.openKey !== key) return;
      if (!res.ok) { body.innerHTML = '<div class="at-cancel-note">' + esc(res.error || 'Could not load this invoice.') + '</div>'; return; }
      D = res; renderPanel(payForm);
    });
  }
  function closePanel() { S.openKey = null; D = null; panel.classList.remove('open'); panel.setAttribute('aria-hidden', 'true'); scrim.hidden = true; markOpen(); }
  scrim.addEventListener('click', closePanel);
  $('[data-close]').addEventListener('click', closePanel);

  function renderPanel(payForm) {
    var r = D.row, cancelled = r.status === 'Cancelled';
    $('[data-p-kind]').textContent = r.kind === 'recovery' ? 'Recovery invoice' : 'Vessel invoice';
    $('[data-p-title]').textContent = r.invoice_no || 'Invoice (no number)';
    $('[data-p-status]').innerHTML = pill(r.status);
    var u = C.invoiceFile + '?a=file&id=' + r.invoice_id + (r.kind === 'recovery' ? '&which=recovery' : '');
    var pct = r.amount > 0 ? Math.max(0, Math.min(100, (r.received / r.amount) * 100)) : 0;
    var h = '';
    if (cancelled) h += '<div class="at-cancel-note"><i class="fa-solid fa-ban"></i> Cancelled' + (D.cancelled_reason ? ': ' + esc(D.cancelled_reason) : '') + ' — kept for audit, not counted in totals.</div>';
    h += '<div class="at-sec"><div class="at-sec-h">Invoice information</div><div class="at-kv" data-edit>' +
      '<div>Invoice Number</div><div><b>' + esc(r.invoice_no || '—') + '</b></div>' +
      '<div>Client</div><div>' + esc(r.client || '—') + '</div>' +
      '<div>Vessel</div><div>' + esc(r.vessel || '—') + '</div>' +
      '<div>Port</div><div>' + esc(r.port || '—') + '</div>' +
      '<div>Survey Reference</div><div>' + esc(r.survey_ref || '—') + '</div>' +
      '<div>Invoice Date</div><div><input type="date" data-e="invoice_date" value="' + esc(r.invoice_date) + '" data-testid="at-e-invoice-date"></div>' +
      '<div>Due Date</div><div><input type="date" data-e="due_date" value="' + esc(r.due_date) + '" data-testid="at-e-due-date"><span class="at-quickdue">' +
        [15, 30, 45].map(function (n) { return '<button type="button" data-plus="' + n + '" title="Invoice date + ' + n + ' days">+' + n + 'd</button>'; }).join('') + '</span></div>' +
      '<div>Invoice Amount</div><div><input inputmode="decimal" data-e="amount" value="' + (r.amount === null ? '' : r.amount.toFixed(2)) + '" placeholder="Enter amount (₹)" data-testid="at-e-amount"></div>' +
      '<div>Remarks</div><div><textarea rows="2" data-e="remarks" placeholder="Add remarks…" data-testid="at-e-remarks">' + esc(r.remarks) + '</textarea></div>' +
      '<div>Document</div><div>' + (r.has_doc ? '<a class="at-btn" href="' + u + '&mode=view" target="_blank" rel="noopener" data-testid="at-p-view"><i class="fa-solid fa-file-pdf" style="color:#b42318"></i> View</a>&nbsp;<a class="at-btn" href="' + u + '&mode=download" data-testid="at-p-download"><i class="fa-solid fa-download"></i> Download</a>' : muted('No file')) + '</div>' +
      '</div><div class="at-p-actions"><button type="button" class="at-btn pri" data-save disabled data-testid="at-save"><i class="fa-solid fa-floppy-disk"></i> Save changes</button><button type="button" class="at-btn" data-discard hidden>Discard</button></div></div>';
    h += '<div class="at-sec"><div class="at-sec-h">Payment summary ' + pill(r.status) + '</div><div class="at-paysum">' +
      '<div><div class="l">Invoice Amount</div><div class="v">' + (r.amount === null ? muted() : inr(r.amount)) + '</div></div>' +
      '<div><div class="l">Total Received</div><div class="v" style="color:#15803D" data-testid="at-p-received">' + inr(r.received || 0) + '</div></div>' +
      '<div><div class="l">Outstanding</div><div class="v" style="color:' + (r.status === 'Overdue' ? '#B91C1C' : (r.outstanding > 0 ? '#C2410C' : '#15803D')) + '" data-testid="at-p-outstanding">' + (r.outstanding === null ? muted() : inr(r.outstanding)) + '</div></div>' +
      '</div><div class="at-progress" title="' + pct.toFixed(0) + '% received"><i style="width:' + pct + '%"></i></div></div>';
    h += '<div class="at-sec"><div class="at-sec-h">Payment history <span>' + (!cancelled ? '<button type="button" class="at-btn pri" data-payform data-testid="at-add-payment"><i class="fa-solid fa-plus"></i> Add Payment</button>' : '') + '</span></div>';
    h += D.payments.length ? '<div style="overflow-x:auto"><table class="at-mini" data-testid="at-payments"><thead><tr><th>Payment Date</th><th>Reference</th><th>Mode</th><th>Transaction ID</th><th class="num-r">Amount</th><th>Receipt</th><th>Remarks</th><th></th></tr></thead><tbody>' +
      D.payments.map(function (p) {
        var rc = p.has_receipt ? '<a href="' + C.api + '?a=receipt&payment_id=' + p.id + '&mode=view" target="_blank" rel="noopener" data-testid="at-receipt-' + p.id + '"><i class="fa-solid fa-paperclip"></i> ' + esc(p.receipt_name || 'Receipt') + '</a>' : '<span class="muted">—</span>';
        return '<tr class="' + (p.status === 'void' ? 'void' : '') + '" data-pid="' + p.id + '"><td>' + fdate(p.date) + '</td><td>' + esc(p.reference || '—') + '</td><td>' + esc(p.mode) + '</td><td>' + esc(p.transaction_id || '—') + '</td>' +
          '<td class="num-r"><b>' + inr(p.amount) + '</b></td><td class="keep">' + rc + '</td><td>' + esc(p.remarks) + (p.status === 'void' ? '</td><td class="keep"><small style="color:#b42318">Void: ' + esc(p.void_reason) + '</small>' : '</td><td class="keep"><button type="button" class="vd" data-void="' + p.id + '" data-testid="at-void-' + p.id + '">Void</button>') + '</td></tr>';
      }).join('') + '</tbody></table></div>' : '<div class="muted" style="font-size:12.5px">No payments recorded yet.</div>';
    h += '<div data-payslot></div></div>';
    h += '<div class="at-sec"><div class="at-sec-h">Activity</div>' + (D.activity.length ? '<ul class="at-log" data-testid="at-activity">' + D.activity.map(function (a) {
      return '<li>' + esc(a.details || a.action) + '<small>' + esc(a.full_name || 'System') + ' · ' + esc(a.created_at) + '</small></li>';
    }).join('') + '</ul>' : '<div class="muted" style="font-size:12.5px">No changes yet.</div>') + '</div>';
    h += '<div class="at-sec"><button type="button" class="at-btn ' + (cancelled ? '' : 'danger') + '" data-cancel="' + (cancelled ? '0' : '1') + '" data-testid="at-cancel-toggle"><i class="fa-solid ' + (cancelled ? 'fa-rotate-left' : 'fa-ban') + '"></i> ' + (cancelled ? 'Restore invoice' : 'Cancel invoice') + '</button></div>';
    body.innerHTML = h;
    if (payForm && !cancelled) showPayForm();
  }

  // edits
  function editVals() { var o = {}; $$('[data-e]', body).forEach(function (el) { o[el.getAttribute('data-e')] = el.value.trim(); }); return o; }
  function orig() { var r = D.row; return { invoice_date: r.invoice_date || '', due_date: r.due_date || '', amount: r.amount === null ? '' : r.amount.toFixed(2), remarks: r.remarks || '' }; }
  body.addEventListener('input', function (ev) {
    if (!ev.target.matches('[data-e]')) return;
    var v = editVals(), o = orig(), dirty = false;
    $$('[data-e]', body).forEach(function (el) { var k = el.getAttribute('data-e'), ch = v[k] !== o[k]; el.parentNode.classList.toggle('chg', ch); dirty = dirty || ch; });
    $('[data-save]', body).disabled = !dirty; $('[data-discard]', body).hidden = !dirty;
  });
  body.addEventListener('click', function (ev) {
    var t;
    if ((t = ev.target.closest('[data-plus]'))) {
      var base = $('[data-e="invoice_date"]', body).value || C.today, d = new Date(base + 'T00:00:00Z');
      d.setUTCDate(d.getUTCDate() + +t.getAttribute('data-plus'));
      var due = $('[data-e="due_date"]', body); due.value = d.toISOString().slice(0, 10); due.dispatchEvent(new Event('input', { bubbles: true }));
    } else if (ev.target.closest('[data-discard]')) renderPanel(false);
    else if ((t = ev.target.closest('[data-save]'))) saveEdits(t);
    else if (ev.target.closest('[data-payform]')) showPayForm();
    else if ((t = ev.target.closest('[data-void]'))) voidPayment(+t.getAttribute('data-void'));
    else if ((t = ev.target.closest('[data-cancel]'))) toggleCancel(t.getAttribute('data-cancel') === '1');
    else if (ev.target.closest('[data-paycancel]')) { $('[data-payslot]', body).innerHTML = ''; }
  });
  function saveEdits(btn) {
    var v = editVals(), o = orig(), f = fd({ a: 'update', key: D.row.key });
    Object.keys(v).forEach(function (k) { if (v[k] !== o[k]) f.append(k, v[k]); });
    btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving';
    post(f).then(function (res) {
      if (!res.ok) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save changes'; Swal.fire({ icon: 'error', title: 'Not saved', text: res.error }); return; }
      toast('success', 'Invoice details saved'); afterChange();
    });
  }
  function afterChange(payForm) { load(true); openPanel(D.row.key, payForm); }

  // payments
  function showPayForm(over) {
    var r = D.row, slot = $('[data-payslot]', body);
    if (r.amount === null) { Swal.fire({ icon: 'info', title: 'Invoice amount needed', text: 'Enter the invoice amount (Invoice information above) before adding payments.' }); return; }
    var req = rid();
    slot.innerHTML = '<form class="at-payform" data-pf novalidate data-testid="at-payform">' +
      '<label>Payment Date <span class="req">*</span><input type="date" name="payment_date" value="' + esc(C.today) + '" max="' + esc(C.today) + '" required data-testid="at-pay-date"></label>' +
      '<label>Amount Received (₹) <span class="req">*</span><input name="amount" inputmode="decimal" value="' + (r.outstanding > 0 ? r.outstanding.toFixed(2) : '') + '" required data-testid="at-pay-amount"><span class="hint">Outstanding ' + inr(Math.max(0, r.outstanding || 0)) + '</span></label>' +
      '<label>Payment Mode <span class="req">*</span><select name="payment_mode" required data-testid="at-pay-mode">' + C.modes.map(function (m) { return '<option>' + esc(m) + '</option>'; }).join('') + '</select></label>' +
      '<label>Transaction Reference<input name="reference" maxlength="120" data-testid="at-pay-ref"></label>' +
      '<label>Transaction ID<input name="transaction_id" maxlength="120" data-testid="at-pay-txn"></label>' +
      '<label>Receipt <span class="hint">(PDF / JPG / PNG, optional)</span><input type="file" name="receipt" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" data-testid="at-pay-receipt"></label>' +
      '<label class="full">Remarks<textarea name="remarks" rows="2" maxlength="500" data-testid="at-pay-remarks"></textarea></label>' +
      '<div class="over" data-over hidden><label style="display:flex;gap:6px;align-items:center;color:#b45309;font-size:12px"><input type="checkbox" name="allow_overpay" value="1" style="width:auto" data-testid="at-pay-allow-over"> <span data-overtxt></span></label></div>' +
      '<div class="full at-p-actions"><button type="submit" class="at-btn pri" data-testid="at-pay-save"><i class="fa-solid fa-check"></i> Save payment</button><button type="button" class="at-btn" data-paycancel>Cancel</button></div>' +
      '<input type="hidden" name="request_id" value="' + req + '"></form>';
    var f = $('[data-pf]', slot);
    f.querySelector('[name=amount]').focus();
    slot.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    f.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var a = f.querySelector('[name=amount]').value.trim(), dt = f.querySelector('[name=payment_date]').value;
      if (!dt || !/^\d{1,12}(\.\d{1,2})?$/.test(a.replace(/[, ]/g, '')) || +a.replace(/[, ]/g, '') <= 0) { Swal.fire({ icon: 'warning', title: 'Check the payment', text: 'Payment date and an amount above 0 (up to 2 decimals) are required.' }); return; }
      var data = new FormData(f); data.set('a', 'add_payment'); data.set('key', D.row.key); data.set('amount', a.replace(/[, ]/g, ''));
      if (!f.querySelector('[name=allow_overpay]').checked) data.delete('allow_overpay');
      var btn = f.querySelector('[type=submit]'); btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving';
      post(data).then(function (res) {
        btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-check"></i> Save payment';
        if (res.ok) { toast('success', res.duplicate ? 'Payment already saved' : 'Payment recorded'); afterChange(); return; }
        if (res.code === 'overpay') {
          var o = f.querySelector('[data-over]'); o.hidden = false;
          f.querySelector('[data-overtxt]').textContent = res.error + ' Tick to record it anyway (overpayment).';
          return;
        }
        Swal.fire({ icon: 'error', title: 'Payment not saved', text: res.error || 'Please try again.' });
      }).catch(function () { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-check"></i> Save payment'; Swal.fire({ icon: 'error', title: 'Network error', text: 'Nothing was saved — please try again.' }); });
    });
  }
  function voidPayment(id) {
    Swal.fire({ icon: 'warning', title: 'Void this payment?', text: 'The payment stays in the history (crossed out) and stops counting towards Total Received.', input: 'text', inputPlaceholder: 'Reason (required)',
      showCancelButton: true, confirmButtonText: 'Void payment', confirmButtonColor: '#DC2626', showLoaderOnConfirm: true,
      preConfirm: function (reason) {
        if (!reason || !reason.trim()) { Swal.showValidationMessage('Give a reason.'); return false; }
        return post(fd({ a: 'void_payment', payment_id: id, reason: reason.trim() })).then(function (res) { if (!res.ok) { Swal.showValidationMessage(res.error); return false; } return res; });
      } }).then(function (r) { if (r.isConfirmed) { toast('success', 'Payment voided'); afterChange(); } });
  }
  function toggleCancel(on) {
    Swal.fire(on ? { icon: 'warning', title: 'Cancel this invoice?', text: 'It stays in the table for audit but is left out of all totals.', input: 'text', inputPlaceholder: 'Reason (required)', showCancelButton: true, confirmButtonText: 'Cancel invoice', confirmButtonColor: '#DC2626', cancelButtonText: 'Keep',
        preConfirm: function (v) { if (!v || !v.trim()) { Swal.showValidationMessage('Give a reason.'); return false; } return v.trim(); } }
      : { icon: 'question', title: 'Restore this invoice?', showCancelButton: true, confirmButtonText: 'Restore', confirmButtonColor: '#6C4CF1' }
    ).then(function (r) {
      if (!r.isConfirmed) return;
      post(fd({ a: 'cancel', key: D.row.key, cancelled: on ? '1' : '0', reason: on ? r.value : '' })).then(function (res) {
        if (!res.ok) { Swal.fire({ icon: 'error', title: 'Not saved', text: res.error }); return; }
        toast('success', on ? 'Invoice cancelled' : 'Invoice restored'); afterChange();
      });
    });
  }

  // go
  toolbarState();
  load();
})();
