/* Survey report wizard (survey_report.php). Data: ajax/survey_reports.php.
   Steps: 1 Vessel particulars (IMO + Vessel lookup, the 18 particulars) → 2 Survey particulars
   (fuel grades on board, FWE / start / completion, staff, location, drafts) → one step per chosen
   fuel grade (tank cards, one below the other) → ROB summary with the PDF / Excel downloads.
   - V.C.F. / W.C.F. / G.S.V. / metric tons are shown as you type (assets/js/report-calc.js, the
     template's own TABLES 54B formulas). The summary is checked on the server (a=verify) and the
     files are always calculated by the server from the template's formulas.
   - Saves by itself, the current step included. Offline: kept on this device and saved when the
     connection is back (a change made by someone else meanwhile is never overwritten without asking). */
(function () {
  'use strict';
  var P = window.SR_PAGE || {};
  var LS = 'ysms_sr_' + P.id, CACHE = 'ysms_sr_get_' + P.id, TPL_CACHE = 'ysms-sr-templates';
  var S = { cfg: null, report: null, data: null, version: 0, canEdit: false, errors: {}, files: [], dirty: false, saving: false, timer: null, offlineMode: false,
    ai: {}, lookup: null, lookupMsg: '', manual: false, showErr: {}, open: {}, expand: {}, server: null, serverIssues: null, verifying: false };
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function f3(v) { return v === null || v === undefined || v === '' || isNaN(v) ? '' : (+v).toFixed(3); }
  function sgn(v) { return v === null || v === undefined || isNaN(v) ? '—' : (v > 0 ? '+' : v < 0 ? '−' : '') + Math.abs(v).toFixed(3); }
  function store(k, v) { try { if (v === null) localStorage.removeItem(k); else localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} }
  function read(k) { try { return JSON.parse(localStorage.getItem(k) || 'null'); } catch (e) { return null; } }
  function toast(icon, title) { if (window.Swal) Swal.fire({ toast: true, position: 'top-end', icon: icon, title: title, showConfirmButton: false, timer: icon === 'success' ? 2000 : 4500 }); }
  function post(d) {
    var f = new FormData(); f.append('csrf_token', P.csrf); Object.keys(d).forEach(function (k) { f.append(k, d[k]); });
    return fetch(P.api, { method: 'POST', body: f, credentials: 'same-origin', headers: { 'X-CSRF-Token': P.csrf } })
      .then(function (r) { return r.json().then(function (j) { j._status = r.status; return j; }).catch(function () { return { ok: false, _status: r.status, error: r.status === 403 ? 'Session expired — please reload the page.' : 'Server error (' + r.status + ').' }; }); })
      .catch(function () { return { ok: false, _status: 0, error: 'No connection.' }; });
  }
  /** JSON with sorted keys and without page-only flags, to compare two copies of the data. */
  function canon(v) {
    if (Array.isArray(v)) return '[' + v.map(canon).join(',') + ']';
    if (v && typeof v === 'object') return '{' + Object.keys(v).filter(function (k) { return k !== '_tried'; }).sort().map(function (k) { return JSON.stringify(k) + ':' + canon(v[k]); }).join(',') + '}';
    return JSON.stringify(v == null ? '' : String(v));
  }
  function uid() { return 't' + Date.now().toString(36) + Math.random().toString(36).slice(2, 6); }
  function num(v) { var t = String(v == null ? '' : v).replace(/,/g, '').trim(); return t !== '' && !isNaN(t) ? parseFloat(t) : null; }

  // ---------- network state ----------
  function online() { return navigator.onLine !== false; }
  function netState() {
    var el = $('[data-net]');
    el.className = 'sr-net ' + (online() ? 'on' : 'off');
    el.innerHTML = online() ? '<i class="fa-solid fa-wifi"></i> Online' : '<i class="fa-solid fa-plane"></i> Offline — saved on this device';
    $$('[data-online-only]').forEach(function (b) { b.disabled = !online() || !S.canEdit || b.hasAttribute('data-blocked'); });
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
      if (!res) { $('[data-form]').innerHTML = '<div class="sr-note err"><i class="fa-solid fa-plane"></i> You are offline and this report has not been opened on this device before. Open it once while online.</div>'; return; }
      start(res, true);
    });
  }
  function start(res, fromCache) {
    S.cfg = res.config; S.report = res.report; S.canEdit = res.can_edit; S.files = res.files || []; S.offlineMode = fromCache;
    S.version = res.report.version; S.data = normalise(res.report.data);
    var local = read(LS);
    if (local && local.dirty) {
      if (local.base === S.version || fromCache) { S.data = normalise(local.data); S.dirty = true; }
      else if (canon(normalise(JSON.parse(JSON.stringify(local.data)))) === canon(S.data)) store(LS, null); // already saved (e.g. on leaving the page)
      else return conflictLocal(local, res);
    }
    $('[data-title]').textContent = res.report.vessel;
    $('[data-subtitle]').textContent = 'Offhire report';
    store(LS + '_step', null);
    var have = steps().map(function (s) { return s.id; });
    if (have.indexOf(S.data.step) < 0) S.data.step = 'vessel';
    var seen = read(LS + '_seen') || []; if (seen.indexOf(S.data.step) < 0) { seen.push(S.data.step); store(LS + '_seen', seen); }
    if (VESSEL().some(function (k) { return k !== 'vessel_name' && val(k) !== ''; })) S.manual = true; // a draft with particulars: show them (a new report opens with the IMO search only)
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
  var ROW_KEYS = ['name', 'ref_height', 'sounding', 'volume', 'density', 'temp', 'mode', 'ullage', 'done', 'id'];
  function normalise(d) {
    d = d || {}; d.fields = d.fields || {}; d.tanks = d.tanks || {}; d.extra = d.extra || {};
    d.grades = Array.isArray(d.grades) ? d.grades : [];
    d.step = d.step || 'vessel';
    S.cfg.grades.forEach(function (g) {
      d.tanks[g.key] = (d.tanks[g.key] || []).map(function (r) {
        var o = {}; ROW_KEYS.forEach(function (k) { o[k] = r[k] == null ? '' : String(r[k]); });
        o.mode = o.mode === 'ullage' ? 'ullage' : 'sounding'; if (!o.id) o.id = uid();
        return o;
      });
    });
    return d;
  }
  function cacheTemplate() { // the template workbook, for the Excel made on this device
    if (!('caches' in window)) return;
    var url = P.api + '?a=template&id=' + P.id + '&t=' + S.cfg.template.id;
    caches.open(TPL_CACHE).then(function (c) { return c.match(url).then(function (hit) { if (!hit) return fetch(url, { credentials: 'same-origin' }).then(function (r) { if (r.ok) c.put(url, r); }); }); }).catch(function () {});
  }

  // ---------- fields ----------
  var MONTHS = ['JANUARY', 'FEBRUARY', 'MARCH', 'APRIL', 'MAY', 'JUNE', 'JULY', 'AUGUST', 'SEPTEMBER', 'OCTOBER', 'NOVEMBER', 'DECEMBER'];
  var LABELS = { vessel_name: 'Vessel Name', flag: 'Flag', port_of_registry: 'Port of Registry', grt: 'GRT', nrt: 'NRT', owners: 'Owner Name', holds: 'Number of Holds',
    builder: 'Builder', keel_month: 'Keel Laid Month', keel_year: 'Keel Laid Year', operator: 'Manager / Operator Name', callsign: 'Callsign', loa: 'LOA (m)',
    breadth: 'Breadth Moulded (m)', depth: 'Depth Moulded (m)', summer_draft: 'Summer Draft (m)', summer_dwt: 'Summer Deadweight (MT)', 'class': 'Class', imo: 'IMO Number',
    fwe_date: 'FWE Date', fwe_time: 'FWE Time (LT)', started_date: 'Survey Started Date', started_time: 'Survey Started Time (LT)', completed_date: 'Survey Completed Date',
    completed_time: 'Survey Completed Time (LT)', surveyor_name: 'Surveyor Name', chief_engineer: 'Chief Engineer Name', master: 'Master Name', port_name: 'Port Name',
    place: 'Place of Survey', draft_fwd: 'Forward Draft (m)', draft_aft: 'Aft Draft (m)', list: 'List (degrees)', voyage_no: 'Voyage No.', charterers: 'Charterers Name', alongside: 'Alongside' };
  function FIELD(k) {
    var f = null;
    S.cfg.fields.forEach(function (x) { if (x.key === k) f = x; });
    if (!f) S.cfg.extra_fields.forEach(function (x) { if (x.key === k) f = { key: x.key, label: x.label, type: x.type, extra: true }; });
    return f;
  }
  function isExtra(k) { var f = FIELD(k); return !!(f && f.extra); }
  function val(k) { var v = isExtra(k) ? S.data.extra[k] : S.data.fields[k]; return v == null ? '' : String(v).trim(); }
  function setVal(k, v) { if (isExtra(k)) S.data.extra[k] = v; else S.data.fields[k] = v; }
  function VESSEL() { return S.cfg.vessel_keys; }
  function gradeInfo(g) { var o = null; S.cfg.grades.forEach(function (x) { if (x.key === g) o = x; }); return o; }
  function chosen() { return S.cfg.grades.map(function (g) { return g.key; }).filter(function (g) { return S.data.grades.indexOf(g) >= 0; }); }
  function label(k) {
    var m = /^brob_(fwe|started)_(\w+)$/.exec(k);
    if (m) return (m[1] === 'fwe' ? 'FWE ROB ' : 'Log Book ROB ') + gradeInfo(m[2]).short + ' (MT)';
    return LABELS[k] || (FIELD(k) || {}).label || k;
  }
  /** Quick type check as in the server (sfValidate); the server checks again. */
  function typeError(f, v) {
    if (v === '') return '';
    var n = v.replace(/,/g, '');
    if (f.type === 'imo') { var d = v.replace(/^IMO\s*/i, ''); if (!/^\d{7}$/.test(d)) return 'IMO number has 7 digits.'; var s = 0; for (var i = 0; i < 6; i++) s += +d[i] * (7 - i); return s % 10 === +d[6] ? '' : 'IMO check digit does not match — please re-check the number.'; }
    if (f.type === 'int') return /^\d+$/.test(n) ? '' : 'Whole number only.';
    if (/^num/.test(f.type)) { if (isNaN(n)) return 'Numbers only.'; var dec = +f.type.split(':')[1], p = n.split('.')[1]; return p && p.length > dec ? 'Use at most ' + dec + ' decimals.' : ''; }
    if (f.type === 'time') return /^([01]\d|2[0-3]):[0-5]\d$/.test(v) ? '' : 'Enter the time as HH:MM (24 h).';
    if (f.type === 'date') return /^\d{4}-\d{2}-\d{2}$/.test(v) ? '' : 'Enter a valid date.';
    return '';
  }

  // ---------- checks (same rules as srValidate on the server) ----------
  function tankErrors(r) {
    var e = {}, ull = r.mode === 'ullage';
    var need = [['name', 'Tank name', 'text'], ['ref_height', 'Reference height', 'height'], [ull ? 'ullage' : 'sounding', ull ? 'Ullage' : 'Sounding', ull ? 'num:3' : 'height'], ['volume', 'Volume', 'num:3'], ['density', 'Density @15°C', 'num:4'], ['temp', 'Temperature', 'num:1']];
    need.forEach(function (n) {
      var v = String(r[n[0]] || '').trim();
      if (v === '') { e[n[0]] = n[1] + ' is missing.'; return; }
      if (n[2] === 'height' || n[2] === 'text') return;
      var t = typeError({ type: n[2] }, v); if (t) { e[n[0]] = n[1] + ': ' + t; return; }
      var x = num(v);
      if (n[0] === 'density' && (x < 0.6 || x > 1.1)) e[n[0]] = 'Density @15°C is normally between 0.6000 and 1.1000.';
      if (n[0] === 'temp' && (x < -10 || x > 150)) e[n[0]] = 'Temperature looks wrong (−10 to 150 °C).';
      if (n[0] === 'volume' && x < 0) e[n[0]] = 'Volume must be 0 or more.';
    });
    if (ull && !e.ref_height && !e.ullage) {
      if (num(r.ref_height) === null) e.ref_height = 'Reference height must be a number to work out the sounding from the ullage.';
      else if (num(r.ullage) > num(r.ref_height)) e.ullage = 'Ullage is more than the reference height.';
    }
    return e;
  }
  function issues(stepId) {
    var out = [];
    var add = function (step, key, msg) { if (!stepId || stepId === step) out.push({ step: step, key: key, msg: msg }); };
    ['imo'].concat(VESSEL()).forEach(function (k) {
      var f = FIELD(k); if (!f) return;
      var v = val(k), t = typeError(f, v);
      if (v === '') add('vessel', 'f:' + k, label(k) + ' is missing.'); else if (t) add('vessel', 'f:' + k, label(k) + ': ' + t);
    });
    var g = chosen();
    if (!g.length) add('survey', 'grades', 'Choose the fuel grades on board.');
    var req = S.cfg.survey_required.slice();
    g.forEach(function (x) { req.push('brob_fwe_' + x, 'brob_started_' + x); });
    req.concat(['voyage_no', 'charterers', 'alongside', 'list']).forEach(function (k) {
      var f = FIELD(k); if (!f) return;
      var v = val(k), t = typeError(f, v);
      if (v === '' && req.indexOf(k) >= 0) add('survey', 'f:' + k, label(k) + ' is missing.'); else if (t) add('survey', 'f:' + k, label(k) + ': ' + t);
    });
    var s = val('started_date') + ' ' + val('started_time'), c = val('completed_date') + ' ' + val('completed_time');
    if (s.length === 16 && c.length === 16 && c < s) add('survey', 'f:completed_time', 'Survey Completed is before Survey Started.');
    g.forEach(function (x) {
      var rows = S.data.tanks[x], short = gradeInfo(x).short;
      if (!rows.length) { add('g:' + x, '', short + ': add at least one tank.'); return; }
      rows.forEach(function (r, i) {
        var e = tankErrors(r), tn = short + ' tank ' + (i + 1) + (r.name ? ' (' + r.name + ')' : '');
        Object.keys(e).forEach(function (col) { add('g:' + x, 't:' + x + ':' + i + ':' + col, tn + ': ' + e[col]); });
      });
    });
    return out;
  }

  // ---------- steps ----------
  function steps() {
    var list = [{ id: 'vessel', title: 'Vessel Particulars', short: 'Vessel' }, { id: 'survey', title: 'Survey Particulars', short: 'Survey' }];
    chosen().forEach(function (g) { var i = gradeInfo(g); list.push({ id: 'g:' + g, title: i.short + ' Tanks', short: i.short, grade: g }); });
    list.push({ id: 'summary', title: 'ROB Summary & Export', short: 'Summary' });
    return list;
  }
  function stepIndex(id) { var l = steps(); for (var i = 0; i < l.length; i++) if (l[i].id === id) return i; return 0; }
  function stepDone(id) { return id !== 'summary' && !issues(id).length && (id !== 'survey' || chosen().length > 0); }
  /** A step can be opened from the step bar when every step before it is complete, or when it was opened before (to come back after a fix). */
  function reachable(i) {
    var l = steps(), seen = read(LS + '_seen') || [];
    if (l[i] && seen.indexOf(l[i].id) >= 0) return true;
    for (var j = 0; j < i; j++) if (!stepDone(l[j].id)) return false;
    return true;
  }
  function go(id) {
    var seen = read(LS + '_seen') || []; if (seen.indexOf(id) < 0) { seen.push(id); store(LS + '_seen', seen); }
    S.data.step = id; changed(true); render(); window.scrollTo(0, 0);
    if (id === 'summary') verify();
  }

  // ---------- render ----------
  function inputHtml(k, opts) {
    opts = opts || {};
    var f = FIELD(k); if (!f) return '';
    var v = val(k), dis = S.canEdit && !opts.ro ? '' : ' disabled', inp;
    var attrs = ' data-f="' + k + '" data-testid="sr-f-' + k + '"' + dis + (S.ai[k] ? ' class="ai" title="Filled by Vessel lookup — please check"' : '');
    if (f.type === 'month') inp = '<select' + attrs + '><option value=""></option>' + MONTHS.map(function (m) { return '<option' + (m === v ? ' selected' : '') + '>' + m + '</option>'; }).join('') + '</select>';
    else {
      var t = f.type === 'date' ? 'date' : f.type === 'time' ? 'time' : 'text';
      var mode = /^(num|int|imo)/.test(f.type) ? ' inputmode="decimal"' : '';
      inp = '<input type="' + t + '"' + mode + attrs + ' value="' + esc(v) + '" maxlength="200" autocomplete="off">';
    }
    return '<label class="sr-f' + (opts.wide ? ' wide' : '') + '" data-fw="' + k + '"><span>' + esc(opts.label || label(k)) + (opts.req ? ' <b class="sr-req">*</b>' : '') + '</span>' + inp + '<em data-err="' + k + '"></em></label>';
  }
  function render() {
    var note = '';
    if (!S.canEdit) note = '<div class="sr-note warn"><i class="fa-solid fa-lock"></i> View only.</div>';
    else if (S.offlineMode) note = '<div class="sr-note warn"><i class="fa-solid fa-plane"></i> Offline — saved on this device, sent when you are back online.</div>';
    $('[data-banners]').innerHTML = note;
    var list = steps(), i = stepIndex(S.data.step), st = list[i], body;
    if (st.id === 'vessel') body = vesselHtml();
    else if (st.id === 'survey') body = surveyHtml();
    else if (st.grade) body = gradeHtml(st.grade);
    else body = summaryHtml();
    var bar = '<ol class="sr-steps" data-testid="sr-steps">' + list.map(function (x, j) {
      var cls = j === i ? 'on' : stepDone(x.id) ? 'done' : '', can = reachable(j) || j < i;
      return '<li class="' + cls + '"><button type="button" data-goto="' + x.id + '"' + (can ? '' : ' disabled') + ' data-testid="sr-step-' + x.id.replace(':', '-') + '" title="' + esc(x.title) + '">' +
        '<span class="n">' + (cls === 'done' ? '<i class="fa-solid fa-check"></i>' : j + 1) + '</span><span class="t">' + esc(x.short) + '</span></button></li>';
    }).join('') + '</ol>';
    var next = i < list.length - 1 ? list[i + 1] : null;
    var nextLabel = !next ? '' : next.grade && st.grade ? 'Next grade: ' + next.short : next.id === 'summary' ? 'Summary' : 'Next';
    var blocked = st.id === 'vessel' && issues('vessel').length > 0;
    $('[data-form]').innerHTML =
      '<div class="sr-wiz" data-testid="sr-wizard">' + bar +
      '<div class="sr-wiz-top"><span data-testid="sr-step-no">Step ' + (i + 1) + ' of ' + list.length + '</span><span class="sr-muted">' + list.filter(function (x) { return stepDone(x.id); }).length + ' of ' + (list.length - 1) + ' steps complete</span></div>' +
      '<h2 class="sr-qt" data-testid="sr-step-title">' + esc(st.title) + '</h2>' +
      '<div class="sr-step" data-step="' + st.id + '">' + body + '</div>' +
      '<div class="sr-wiz-nav" data-testid="sr-nav"><button type="button" class="sr-btn" data-back' + (i ? '' : ' disabled') + ' data-testid="sr-back"><i class="fa-solid fa-arrow-left"></i> Back</button>' +
      (next ? '<button type="button" class="sr-btn pri" data-next data-testid="sr-next"' + (blocked ? ' disabled title="Complete the vessel particulars first"' : '') + '>' + esc(nextLabel) + ' <i class="fa-solid fa-arrow-right"></i></button>' : '<span></span>') + '</div></div>';
    showErrors(); netState();
  }
  // step 1
  function vesselHtml() {
    var show = S.manual || S.lookup;
    var miss = show ? issues('vessel').filter(function (x) { return x.key !== 'f:imo'; }) : [];
    return '<section class="sr-card sr-imo" data-testid="sr-vessel"><div class="sr-lookup">' + inputHtml('imo', { label: 'IMO Number', req: true }) +
      (S.canEdit ? '<button type="button" class="sr-btn pri" data-lookup data-online-only data-testid="sr-lookup"><i class="fa-solid fa-magnifying-glass"></i> Lookup</button>' : '') + '</div>' +
      '<div data-lookup-info data-testid="sr-lookup-info">' + lookupInfoHtml() + '</div>' +
      (!show && S.canEdit ? '<button type="button" class="sr-link" data-manual data-testid="sr-manual">or enter the particulars by hand</button>' : '') + '</section>' +
      (show ? '<section class="sr-card"><h3>Vessel particulars</h3>' +
        (miss.length ? '<div class="sr-note err" data-testid="sr-vessel-missing"><i class="fa-solid fa-circle-exclamation"></i> ' + miss.length + ' particular' + (miss.length === 1 ? '' : 's') + ' still to enter: ' + esc(miss.map(function (x) { return label(x.key.slice(2)); }).join(', ')) + '. Type them in to continue.</div>' : '') +
        '<div class="sr-grid">' + VESSEL().map(function (k) { return inputHtml(k, { req: true }); }).join('') + '</div></section>' : '');
  }
  function lookupInfoHtml() {
    if (S.lookupMsg) return '<div class="sr-note warn" data-testid="sr-lookup-msg"><i class="fa-solid fa-circle-info"></i> ' + esc(S.lookupMsg) + ' You can type the particulars in below.</div>';
    var L = S.lookup; if (!L) return '';
    var n = Object.keys(L.prev).length;
    return '<div class="sr-note sr-ai-note"><b><i class="fa-solid fa-wand-magic-sparkles"></i> ' + n + ' field' + (n === 1 ? '' : 's') + ' filled for IMO ' + esc(L.imo) + '</b> — found on the web; please check the highlighted values against the ship\'s documents.' +
      (S.canEdit ? ' <button type="button" class="sr-btn sm" data-undo data-testid="sr-undo"><i class="fa-solid fa-rotate-left"></i> Undo</button>' : '') +
      (L.sources.length ? '<div class="sr-src">Sources: ' + L.sources.map(function (s) { return '<a href="' + esc(s.uri) + '" target="_blank" rel="noopener noreferrer">' + esc(s.title || s.uri) + '</a>'; }).join(' · ') + '</div>' : '') + '</div>';
  }
  // step 2
  function surveyHtml() {
    var g = chosen(), req = { req: true };
    var robs = function (kind) {
      if (!g.length) return '<p class="sr-muted sr-span">Choose the fuel grades above to enter the ROB.</p>';
      return g.map(function (x) { return inputHtml('brob_' + kind + '_' + x, req); }).join('');
    };
    var mean = num(val('draft_fwd')) !== null && num(val('draft_aft')) !== null ? ((num(val('draft_fwd')) + num(val('draft_aft'))) / 2).toFixed(3) : '';
    var trim = mean !== '' ? (num(val('draft_aft')) - num(val('draft_fwd'))).toFixed(3) : '';
    var card = function (title, icon, inner, tid) { return '<section class="sr-card" data-testid="sr-grp-' + tid + '"><h3><i class="fa-solid ' + icon + '"></i> ' + title + '</h3><div class="sr-grid">' + inner + '</div></section>'; };
    return '<section class="sr-card" data-testid="sr-grades" data-fw="grades"><h3><i class="fa-solid fa-gas-pump"></i> Fuel grades on board <b class="sr-req">*</b></h3>' +
      '<div class="sr-chips">' + S.cfg.grades.map(function (x) {
        var on = g.indexOf(x.key) >= 0;
        return '<label class="sr-chip' + (on ? ' on' : '') + '" data-testid="sr-grade-' + x.key + '"><input type="checkbox" data-grade="' + x.key + '"' + (on ? ' checked' : '') + (S.canEdit ? '' : ' disabled') + '><b>' + esc(x.short) + '</b><small>' + esc(x.name) + '</small></label>';
      }).join('') + '</div><em data-err="grades"></em></section>' +
      card('A. Engine Particulars (FWE)', 'fa-gears', inputHtml('fwe_date', req) + inputHtml('fwe_time', req) + robs('fwe'), 'fwe') +
      card('B. Survey Start', 'fa-play', inputHtml('started_date', req) + inputHtml('started_time', req) + robs('started'), 'started') +
      card('C. Survey Completion', 'fa-flag-checkered', inputHtml('completed_date', req) + inputHtml('completed_time', req) + inputHtml('surveyor_name', req), 'completed') +
      card('D. Ship Staff', 'fa-user-tie', inputHtml('chief_engineer', req) + inputHtml('master', req), 'staff') +
      card('E. Survey Location', 'fa-location-dot', inputHtml('port_name', req) + inputHtml('place', req), 'location') +
      card('F. Draft Particulars', 'fa-ruler-vertical', inputHtml('draft_fwd', req) + inputHtml('draft_aft', req) +
        '<label class="sr-f calc"><span>Mean Draft (m)</span><output data-testid="sr-mean">' + mean + '</output></label>' +
        '<label class="sr-f calc"><span>Trim (m) <small>Aft − Fwd</small></span><output data-testid="sr-trim">' + trim + '</output></label>' + inputHtml('list'), 'drafts') +
      card('Other details', 'fa-file-lines', inputHtml('voyage_no') + inputHtml('charterers') + inputHtml('alongside'), 'other');
  }
  // tank steps
  function sounding(r) {
    if (r.mode !== 'ullage') return String(r.sounding || '');
    var a = num(r.ref_height), b = num(r.ullage);
    return a === null || b === null ? '' : (Math.round((a - b) * 1000) / 1000).toFixed(3);
  }
  function calc(r) {
    var t = window.ReportCalc && S.cfg.calc ? ReportCalc.tank(r, S.cfg.calc) : {};
    var d = num(r.density);
    t.wcf = d === null || !S.cfg.calc ? null : ReportCalc.round(d - S.cfg.calc.wcf, 4);
    return t;
  }
  function gradeTotals(g) {
    var mt = 0, gsv = 0, ok = true;
    S.data.tanks[g].forEach(function (r) { var t = calc(r); if (t.mt === null || t.mt === undefined) ok = false; else { mt += t.mt; gsv += t.gsv; } });
    return { mt: ReportCalc.round(mt, 3), gsv: ReportCalc.round(gsv, 3), ok: ok && S.data.tanks[g].length > 0 };
  }
  function gradeHtml(g) {
    var gi = gradeInfo(g), rows = S.data.tanks[g], tot = gradeTotals(g);
    return '<p class="sr-sub2">' + esc(gi.name) + '</p><div class="sr-tanks" data-tanks="' + g + '" data-testid="sr-tanks-' + g + '">' +
      (rows.length ? rows.map(function (r, i) { return tankHtml(g, r, i); }).join('') : '<div class="sr-empty-t">No tanks yet — add the first tank of ' + esc(gi.short) + '.</div>') + '</div>' +
      (S.canEdit ? '<button type="button" class="sr-btn sr-add" data-add="' + g + '" data-testid="sr-add-' + g + '"><i class="fa-solid fa-plus"></i> ' + (rows.length ? 'Add another tank' : 'Add tank') + '</button>' : '') +
      '<div class="sr-gtot" data-testid="sr-gtot-' + g + '"><div><span>Tanks</span><b>' + rows.length + '</b></div><div><span>G.S.V. m³ @15°C</span><b data-testid="sr-total-gsv-' + g + '">' + f3(tot.gsv) + '</b></div><div class="mt"><span>Total MT in Air</span><b data-testid="sr-total-' + g + '">' + f3(tot.mt) + '</b></div></div>';
  }
  function tankHtml(g, r, i) {
    var key = g + ':' + i, t = calc(r), open = !r.done || S.open[r.id];
    var head = '<div class="sr-tk-h"><b>Tank ' + (i + 1) + (r.name ? ' – ' + esc(r.name) : '') + '</b>' + (r.done && !S.open[r.id] ? '<span class="sr-ok"><i class="fa-solid fa-circle-check"></i> Saved</span>' : '') + '</div>';
    if (!open) {
      var line = function (l, v) { return '<div><dt>' + l + '</dt><dd>' + esc(v) + '</dd></div>'; };
      var more = S.expand[r.id] ? '<dl class="sr-more" data-testid="sr-more-' + g + '-' + i + '">' + line('Reference Height', r.ref_height + ' m') + (r.mode === 'ullage' ? line('Ullage', r.ullage + ' m') : '') + line('Volume', r.volume + ' m³') +
        line('V.C.F.', t.vcf == null ? '' : t.vcf.toFixed(4)) + line('W.C.F.', t.wcf == null ? '' : t.wcf.toFixed(4)) + line('G.S.V.', f3(t.gsv) + ' m³') + '</dl>' : '';
      return '<article class="sr-tank done" data-tank="' + key + '" data-testid="sr-tank-' + g + '-' + i + '">' + head +
        '<dl class="sr-sum5">' + line('Tank Name', r.name) + line('Sounding', sounding(r) + (num(sounding(r)) !== null ? ' m' : '')) + line('Density', r.density) + line('Temperature', r.temp + ' °C') + line('MT in Air', f3(t.mt)) + '</dl>' + more +
        '<div class="sr-tk-act">' + (S.canEdit ? '<button type="button" class="sr-btn sm" data-edit="' + key + '" data-testid="sr-edit-' + g + '-' + i + '"><i class="fa-solid fa-pen"></i> Edit</button>' : '') +
        '<button type="button" class="sr-btn sm" data-expand="' + key + '" data-testid="sr-expand-' + g + '-' + i + '"><i class="fa-solid fa-chevron-' + (S.expand[r.id] ? 'up' : 'down') + '"></i> ' + (S.expand[r.id] ? 'Less' : 'Expand') + '</button>' +
        (S.canEdit ? '<button type="button" class="sr-btn sm danger" data-del="' + key + '" data-testid="sr-del-' + g + '-' + i + '"><i class="fa-solid fa-trash"></i> Delete</button>' : '') + '</div></article>';
    }
    var dis = S.canEdit ? '' : ' disabled';
    var inp = function (col, lab, unit, mode) {
      return '<label class="sr-f" data-tw="' + key + ':' + col + '"><span>' + lab + (unit ? ' <small>' + unit + '</small>' : '') + '</span><input data-t="' + key + ':' + col + '" data-testid="sr-t-' + g + '-' + i + '-' + col + '" value="' + esc(r[col]) + '"' + (mode ? ' inputmode="decimal"' : '') + dis + ' autocomplete="off"><em data-terr="' + key + ':' + col + '"></em></label>';
    };
    var ull = r.mode === 'ullage';
    return '<article class="sr-tank open" data-tank="' + key + '" data-testid="sr-tank-' + g + '-' + i + '">' + head +
      '<div class="sr-tk-f">' + inp('name', 'Tank Name', '', false) + inp('ref_height', 'Reference Height / Total Height', 'm', true) +
      '<div class="sr-f sr-mode"><span>Measured</span><div class="sr-seg" role="group">' +
      '<button type="button" class="' + (ull ? '' : 'on') + '" data-mode="' + key + ':sounding" data-testid="sr-mode-' + g + '-' + i + '-sounding"' + dis + '>Sounding</button>' +
      '<button type="button" class="' + (ull ? 'on' : '') + '" data-mode="' + key + ':ullage" data-testid="sr-mode-' + g + '-' + i + '-ullage"' + dis + '>Ullage</button></div></div>' +
      (ull ? inp('ullage', 'Ullage', 'm', true) + '<div class="sr-f calc"><span>Sounding <small>= Ref. height − Ullage</small></span><output data-snd="' + key + '" data-testid="sr-snd-' + g + '-' + i + '">' + esc(sounding(r)) + '</output></div>' : inp('sounding', 'Sounding', 'm', false)) +
      inp('volume', 'Volume', 'm³', true) + inp('density', 'Density at 15°C', 'kg/l', true) + inp('temp', 'Temperature', '°C', true) + '</div>' +
      '<div class="sr-res" data-res="' + key + '">' + resHtml(t) + '</div>' +
      (S.canEdit ? '<div class="sr-tk-act"><button type="button" class="sr-btn pri" data-save-tank="' + key + '" data-testid="sr-save-tank-' + g + '-' + i + '"><i class="fa-solid fa-check"></i> Save tank</button>' +
        '<button type="button" class="sr-btn sm danger" data-del="' + key + '" data-testid="sr-del-' + g + '-' + i + '"><i class="fa-solid fa-trash"></i> Delete</button></div>' : '') + '</article>';
  }
  function resHtml(t) {
    var c = function (l, v, cls) { return '<div class="' + (cls || '') + '"><span>' + l + '</span><b>' + (v === '' || v == null ? '—' : v) + '</b></div>'; };
    return c('V.C.F.', t.vcf == null ? '' : t.vcf.toFixed(4)) + c('W.C.F.', t.wcf == null ? '' : t.wcf.toFixed(4)) + c('G.S.V. m³ @15°C', f3(t.gsv)) + c('MT in Air', f3(t.mt), 'mt');
  }
  // summary
  function summaryRows() {
    return chosen().map(function (g) {
      var tot = gradeTotals(g), log = num(val('brob_started_' + g)), sv = S.server && S.server.grades && S.server.grades[g];
      var done = sv ? sv.completed : tot.ok ? tot.mt : null;
      return { g: g, short: gradeInfo(g).short, completed: done, logbook: log, diff: done !== null && log !== null ? ReportCalc.round(done - log, 3) : null };
    }).filter(function (x) { return S.data.tanks[x.g].length; });
  }
  function summaryHtml() {
    var rows = summaryRows(), all = issues(), tot = 0, any = false;
    rows.forEach(function (r) { if (r.diff !== null) { tot += r.diff; any = true; } });
    tot = ReportCalc.round(tot, 3);
    var srv = S.verifying ? '<span class="sr-ver" data-testid="sr-verify"><i class="fa-solid fa-spinner fa-spin"></i> Checking on the server…</span>'
      : S.server ? '<span class="sr-ver ok" data-testid="sr-verify"><i class="fa-solid fa-shield-halved"></i> Calculated on the server from the template\'s formulas</span>' : '';
    var byStep = {};
    all.forEach(function (x) { (byStep[x.step] = byStep[x.step] || []).push(x); });
    var names = {}; steps().forEach(function (s) { names[s.id] = s.title; }); names.grades = 'Survey Particulars';
    var probs = Object.keys(byStep).map(function (s) {
      var target = names[s] ? s : 'survey';
      return '<div class="sr-prob" data-testid="sr-prob-' + s.replace(':', '-') + '"><div><b>' + esc(names[s] || s) + '</b><ul>' + byStep[s].slice(0, 6).map(function (x) { return '<li>' + esc(x.msg) + '</li>'; }).join('') +
        (byStep[s].length > 6 ? '<li>… and ' + (byStep[s].length - 6) + ' more</li>' : '') + '</ul></div><button type="button" class="sr-btn sm" data-goto="' + target + '">Go to step</button></div>';
    }).join('');
    return '<section class="sr-card"><h3><i class="fa-solid fa-scale-balanced"></i> ROB comparison ' + srv + '</h3>' +
      (rows.length ? '<div class="sr-cmp-wrap"><table class="sr-cmp" data-testid="sr-cmp"><thead><tr><th>Fuel Grade</th><th>Survey Completed</th><th>Log Book</th><th>Difference</th></tr></thead><tbody>' +
        rows.map(function (r) { return '<tr data-testid="sr-cmp-' + r.g + '"><td>' + esc(r.short) + '</td><td data-c="completed">' + f3(r.completed) + '</td><td data-c="logbook">' + f3(r.logbook) + '</td><td data-c="diff" class="' + (r.diff < 0 ? 'neg' : r.diff > 0 ? 'pos' : '') + '">' + sgn(r.diff) + '</td></tr>'; }).join('') +
        '</tbody></table></div><div class="sr-totdiff" data-testid="sr-total-diff"><span>TOTAL DIFFERENCE</span><b class="' + (tot < 0 ? 'neg' : tot > 0 ? 'pos' : '') + '">' + (any ? sgn(tot) + ' MT' : '—') + '</b></div>' +
        '<p class="sr-muted sr-small">Survey Completed = sum of MT in Air of the tanks of each grade. Log Book = ROB at survey start (engine-room log book). Difference = Survey Completed − Log Book.</p>'
        : '<p class="sr-muted">No fuel grade with tanks yet.</p>') + '</section>' +
      (all.length ? '<section class="sr-card sr-probs" data-testid="sr-problems"><h3><i class="fa-solid fa-triangle-exclamation"></i> Before exporting, complete:</h3>' + probs + '</section>' : '') +
      '<section class="sr-card"><h3><i class="fa-solid fa-file-export"></i> Export</h3><div class="sr-out" data-testid="sr-output">' +
      '<button type="button" class="sr-btn pri big" data-gen="pdf" data-online-only' + (all.length ? ' data-blocked' : '') + ' data-testid="sr-download-pdf"><i class="fa-solid fa-file-pdf"></i> Download PDF Report</button>' +
      '<button type="button" class="sr-btn pri big alt" data-gen="xlsx" data-online-only' + (all.length ? ' data-blocked' : '') + ' data-testid="sr-download-xlsx"><i class="fa-solid fa-file-excel"></i> Download Excel Report</button></div>' +
      '<p class="sr-small sr-muted">Both files come from one calculation on the server (the company template with its TABLES 54B formulas), so they show the same values.</p>' +
      '<button type="button" class="sr-link" data-offline-xlsx data-testid="sr-offline-xlsx"><i class="fa-solid fa-download"></i> Draft Excel on this device (works offline)</button>' +
      '<details class="sr-files"' + (S.files.length ? '' : ' hidden') + '><summary>Earlier files (' + S.files.length + ')</summary><div data-files>' + filesHtml() + '</div></details></section>';
  }
  function filesHtml() {
    if (!S.files.length) return '<span class="sr-muted">No files made yet.</span>';
    return '<table class="sr-t"><thead><tr><th>File</th><th>Made</th><th></th></tr></thead><tbody>' + S.files.map(function (f) {
      var url = P.api + '?a=file&id=' + f.id;
      return '<tr data-testid="sr-file-' + f.id + '"><td><i class="fa-solid ' + (f.type === 'pdf' ? 'fa-file-pdf" style="color:#dc2626' : 'fa-file-excel" style="color:#16a34a') + '"></i> ' + esc(f.file_name) + '</td>' +
        '<td>' + esc(String(f.created_at).slice(0, 16)) + '<br><small class="sr-muted">' + esc(f.created_by_name || '') + '</small></td>' +
        '<td style="white-space:nowrap"><a href="' + url + '">Download</a>' + (f.type === 'pdf' ? ' · <a href="' + url + '&inline=1" target="_blank" rel="noopener">View</a>' : '') + '</td></tr>';
    }).join('') + '</tbody></table>';
  }

  // ---------- error marks ----------
  function showErrors() {
    $$('.sr-f.bad').forEach(function (e) { e.classList.remove('bad'); });
    $$('[data-err],[data-terr]').forEach(function (e) { e.textContent = ''; });
    var id = S.data.step, list = issues(id), show = S.showErr[id] || id === 'vessel' && (S.lookup || S.lookupMsg || S.manual);
    if (id === 'vessel' && !S.showErr[id]) list = list.filter(function (x) { return x.key !== 'f:imo' || val('imo') !== ''; });
    Object.keys(S.errors || {}).forEach(function (k) { if (k.indexOf('f:') === 0) list.push({ key: k, msg: S.errors[k], server: true }); });
    list.forEach(function (x) {
      if (!show && !x.server && !/: /.test(x.msg)) return; // "missing" marks wait for Next (wrong values show at once)
      if (x.key.indexOf('f:') === 0 || x.key === 'grades') {
        var k = x.key === 'grades' ? 'grades' : x.key.slice(2), w = $('[data-fw="' + k + '"]');
        if (w && !w.classList.contains('bad')) { w.classList.add('bad'); var em = $('[data-err="' + k + '"]', w); if (em) em.textContent = x.msg.replace(label(k) + ': ', '').replace(label(k) + ' is missing.', 'Required.'); }
      } else if (x.key.indexOf('t:') === 0) {
        var p = x.key.split(':'), tk = p[1] + ':' + p[2] + ':' + p[3], tw = $('[data-tw="' + tk + '"]');
        var r = S.data.tanks[p[1]][+p[2]];
        if (tw && (S.showErr[id] || (r && r._tried))) { tw.classList.add('bad'); $('[data-terr="' + tk + '"]').textContent = x.msg.replace(/^.*?: /, ''); }
      }
    });
  }
  function block(id) { // Next / Save tank on an incomplete step: mark, scroll to the first, say what is missing
    S.showErr[id] = true; showErrors();
    var list = issues(id);
    var first = $('.sr-step .sr-f.bad, .sr-step [data-fw=grades].bad');
    if (first) { first.scrollIntoView({ behavior: 'smooth', block: 'center' }); var i = $('input,select', first); if (i) setTimeout(function () { i.focus({ preventScroll: true }); }, 250); }
    Swal.fire({ icon: 'warning', title: 'Please complete this step', html: '<ul class="sr-swal-list">' + list.slice(0, 8).map(function (x) { return '<li>' + esc(x.msg) + '</li>'; }).join('') + (list.length > 8 ? '<li>… and ' + (list.length - 8) + ' more</li>' : '') + '</ul>', confirmButtonText: 'OK' });
  }

  // ---------- events ----------
  document.addEventListener('click', function (ev) {
    var t = ev.target, b;
    if ((b = t.closest('[data-goto]'))) { if (!b.disabled) go(b.getAttribute('data-goto')); return; }
    if (t.closest('[data-next]')) {
      var l = steps(), i = stepIndex(S.data.step), id = l[i].id;
      if (l[i].grade) S.data.tanks[l[i].grade].forEach(function (r) { if (!r.done && !Object.keys(tankErrors(r)).length) { r.done = '1'; delete S.open[r.id]; } });
      if (issues(id).length || (id === 'survey' && !chosen().length)) { if (l[i].grade) render(); block(id); return; }
      go(l[i + 1].id); return;
    }
    if (t.closest('[data-back]')) { go(steps()[Math.max(0, stepIndex(S.data.step) - 1)].id); return; }
    if (t.closest('[data-lookup]')) { lookup(); return; }
    if (t.closest('[data-undo]')) { undoLookup(); return; }
    if (t.closest('[data-manual]')) { S.manual = true; render(); var f = $('[data-f=vessel_name]'); if (f) f.focus(); return; }
    if ((b = t.closest('[data-add]'))) { var g = b.getAttribute('data-add'), row = emptyRow(); S.data.tanks[g].push(row); changed(); render(); var n = $('[data-t="' + g + ':' + (S.data.tanks[g].length - 1) + ':name"]'); if (n) { n.focus(); n.scrollIntoView({ block: 'center' }); } return; }
    if ((b = t.closest('[data-save-tank]'))) { saveTank(b.getAttribute('data-save-tank')); return; }
    if ((b = t.closest('[data-edit]'))) { var p = b.getAttribute('data-edit').split(':'), r = S.data.tanks[p[0]][+p[1]]; S.open[r.id] = true; render(); var x = $('[data-t="' + p[0] + ':' + p[1] + ':name"]'); if (x) x.focus(); return; }
    if ((b = t.closest('[data-expand]'))) { var q = b.getAttribute('data-expand').split(':'), rr = S.data.tanks[q[0]][+q[1]]; S.expand[rr.id] = !S.expand[rr.id]; render(); return; }
    if ((b = t.closest('[data-del]'))) { delTank(b.getAttribute('data-del')); return; }
    if ((b = t.closest('[data-mode]'))) { var m = b.getAttribute('data-mode').split(':'), tr = S.data.tanks[m[0]][+m[1]]; tr.mode = m[2]; tr.sounding = m[2] === 'ullage' ? sounding(tr) : tr.sounding; changed(); render(); return; }
    if ((b = t.closest('[data-gen]'))) { generate(b.getAttribute('data-gen')); return; }
    if (t.closest('[data-offline-xlsx]')) offlineXlsx();
  });
  document.addEventListener('change', function (ev) {
    var el = ev.target;
    if (el.matches('[data-grade]')) { toggleGrade(el.getAttribute('data-grade'), el.checked); return; }
    if (el.matches('select[data-f]')) { setVal(el.getAttribute('data-f'), el.value); changed(); refreshLight(); }
  });
  document.addEventListener('input', function (ev) {
    var el = ev.target;
    if (el.matches('[data-f]')) {
      var k = el.getAttribute('data-f'); setVal(k, el.value);
      if (S.ai[k]) { delete S.ai[k]; el.classList.remove('ai'); el.title = ''; }
      delete S.errors['f:' + k];
      changed(); refreshLight(); return;
    }
    if (el.matches('[data-t]')) {
      var p = el.getAttribute('data-t').split(':'), r = S.data.tanks[p[0]][+p[1]];
      r[p[2]] = el.value;
      if (r.mode === 'ullage') { r.sounding = sounding(r); var o = $('[data-snd="' + p[0] + ':' + p[1] + '"]'); if (o) o.textContent = r.sounding; }
      $('[data-res="' + p[0] + ':' + p[1] + '"]').innerHTML = resHtml(calc(r));
      var tot = gradeTotals(p[0]); $('[data-testid=sr-total-' + p[0] + ']').textContent = f3(tot.mt); $('[data-testid=sr-total-gsv-' + p[0] + ']').textContent = f3(tot.gsv);
      changed(); showErrors();
    }
  });
  document.addEventListener('keydown', function (ev) { // Enter: lookup from the IMO box, otherwise the next box
    if (ev.key !== 'Enter' || !ev.target.matches('[data-f],[data-t]')) return;
    ev.preventDefault();
    if (ev.target.matches('[data-f=imo]') && $('[data-lookup]')) { lookup(); return; }
    var all = $$('.sr-step [data-f]:not([disabled]), .sr-step [data-t]:not([disabled])'), i = all.indexOf(ev.target);
    if (i < all.length - 1) all[i + 1].focus();
  });
  /** After a typed value: step marks, the Next button and the computed drafts, without redrawing the inputs. */
  function refreshLight() {
    var id = S.data.step;
    if (id === 'vessel') {
      var nx = $('[data-next]'); if (nx) nx.disabled = issues('vessel').length > 0;
      var miss = issues('vessel').filter(function (x) { return x.key !== 'f:imo'; }), box = $('[data-testid=sr-vessel-missing]');
      if (box && !miss.length) box.remove(); else if (box) box.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i> ' + miss.length + ' particular' + (miss.length === 1 ? '' : 's') + ' still to enter: ' + esc(miss.map(function (x) { return label(x.key.slice(2)); }).join(', ')) + '. Type them in to continue.';
    }
    if (id === 'survey') {
      var a = num(val('draft_aft')), f = num(val('draft_fwd')), m = $('[data-testid=sr-mean]'), t = $('[data-testid=sr-trim]');
      if (m) m.textContent = a !== null && f !== null ? ((a + f) / 2).toFixed(3) : '';
      if (t) t.textContent = a !== null && f !== null ? (a - f).toFixed(3) : '';
    }
    showErrors();
    var l = steps(), cur = stepIndex(id);
    $$('.sr-steps li').forEach(function (li, j) {
      var s = l[j]; if (!s) return;
      var b = $('button', li); if (b) b.disabled = !(reachable(j) || j < cur);
      if (!li.classList.contains('on')) { li.classList.toggle('done', stepDone(s.id)); var n = $('.n', li); if (n) n.innerHTML = li.classList.contains('done') ? '<i class="fa-solid fa-check"></i>' : j + 1; }
    });
  }
  function emptyRow() { return { name: '', ref_height: '', sounding: '', volume: '', density: '', temp: '', mode: 'sounding', ullage: '', done: '', id: uid() }; }
  function saveTank(key) {
    var p = key.split(':'), r = S.data.tanks[p[0]][+p[1]], e = tankErrors(r);
    r._tried = true;
    if (Object.keys(e).length) { showErrors(); var first = $('[data-tank="' + key + '"] .sr-f.bad'); if (first) first.scrollIntoView({ behavior: 'smooth', block: 'center' }); toast('error', 'Complete the marked values of this tank.'); return; }
    r.done = '1'; delete S.open[r.id]; delete r._tried;
    changed(); saveNow(); render();
    var card = $('[data-tank="' + key + '"]'); if (card) card.scrollIntoView({ block: 'nearest' });
  }
  function delTank(key) {
    var p = key.split(':'), r = S.data.tanks[p[0]][+p[1]];
    var doit = function () { S.data.tanks[p[0]].splice(+p[1], 1); changed(); render(); };
    Swal.fire({ icon: 'question', title: 'Delete tank ' + (+p[1] + 1) + (r.name ? ' "' + r.name + '"' : '') + '?', text: 'Its readings are removed from the report.', showCancelButton: true, confirmButtonText: 'Delete', confirmButtonColor: '#dc2626' })
      .then(function (x) { if (x.isConfirmed) doit(); });
  }
  function toggleGrade(g, on) {
    var apply = function () {
      if (on && S.data.grades.indexOf(g) < 0) S.data.grades.push(g);
      if (!on) S.data.grades = S.data.grades.filter(function (x) { return x !== g; });
      changed(); render();
    };
    if (!on && S.data.tanks[g].length) {
      Swal.fire({ icon: 'question', title: 'Remove ' + gradeInfo(g).short + '?', text: 'Its ' + S.data.tanks[g].length + ' tank(s) are kept but left out of the report. Tick it again to bring them back.', showCancelButton: true, confirmButtonText: 'Remove' })
        .then(function (x) { if (x.isConfirmed) apply(); else render(); });
    } else apply();
  }
  // paste rows copied from Excel into a tank (name, ref. height, sounding, volume, density, temp)
  document.addEventListener('paste', function (ev) {
    var el = ev.target; if (!el.matches || !el.matches('[data-t]')) return;
    var txt = (ev.clipboardData || window.clipboardData).getData('text');
    if (txt.indexOf('\t') < 0 && txt.indexOf('\n') < 0) return;
    ev.preventDefault();
    var p = el.getAttribute('data-t').split(':'), g = p[0], r0 = +p[1], keys = ['name', 'ref_height', 'sounding', 'volume', 'density', 'temp'], c0 = Math.max(0, keys.indexOf(p[2]));
    var lines = txt.replace(/\r/g, '').split('\n').filter(function (l) { return l.trim() !== ''; });
    lines.forEach(function (line, li) {
      var r = r0 + li; if (r >= 60) return;
      while (S.data.tanks[g].length <= r) S.data.tanks[g].push(emptyRow());
      var row = S.data.tanks[g][r]; row.mode = 'sounding';
      line.split('\t').forEach(function (v, ci) { var k = keys[c0 + ci]; if (k) row[k] = v.trim(); });
      if (!Object.keys(tankErrors(row)).length) row.done = '1';
    });
    changed(); render();
    toast('success', lines.length + ' tank' + (lines.length === 1 ? '' : 's') + ' pasted');
  });

  // ---------- vessel lookup by IMO (AI + web search on the server) ----------
  function lookup() {
    var imo = val('imo');
    var e = typeError(FIELD('imo'), imo);
    if (imo === '' || e) { S.showErr.vessel = true; showErrors(); toast('error', e || 'Enter the 7-digit IMO number first.'); var i = $('[data-f=imo]'); if (i) i.focus(); return; }
    var btn = $('[data-lookup]'); btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Looking up…';
    S.lookupMsg = '';
    post({ a: 'lookup', id: P.id, imo: imo }).then(function (res) {
      S.manual = true;
      if (!res.ok) { S.lookupMsg = res.error || 'Nothing found for this IMO.'; S.lookup = null; render(); return; }
      var prev = {};
      Object.keys(res.fields).forEach(function (k) {
        if (VESSEL().indexOf(k) < 0) return;
        var old = val(k);
        if (old === res.fields[k]) return;
        prev[k] = old; setVal(k, res.fields[k]); S.ai[k] = true;
      });
      S.lookup = { imo: res.imo, prev: prev, sources: res.sources || [] };
      if (Object.keys(prev).length) changed();
      render();
    });
  }
  function undoLookup() {
    var L = S.lookup; if (!L) return;
    Object.keys(L.prev).forEach(function (k) { setVal(k, L.prev[k]); delete S.ai[k]; });
    S.lookup = null; changed(); render();
  }

  // ---------- saving ----------
  function changed(quiet) {
    if (!S.canEdit) return;
    S.dirty = true; S.server = null;
    store(LS, { data: S.data, base: S.version, dirty: true, at: Date.now() });
    if (!quiet) saveState(online() ? 'Saving…' : 'Changes on this device', 'dirty');
    clearTimeout(S.timer); S.timer = setTimeout(saveNow, quiet ? 300 : 1000);
  }
  function payload() { return JSON.stringify(S.data, function (k, v) { return k === '_tried' ? undefined : v; }); }
  function saveNow() {
    if (!S.dirty || S.saving || !S.canEdit) return Promise.resolve(true);
    if (!online()) { saveState('Changes on this device', 'dirty'); return Promise.resolve(false); }
    S.saving = true;
    var sent = payload();
    return post({ a: 'save', id: P.id, version: S.version, data: sent }).then(function (res) {
      S.saving = false;
      if (res.ok) {
        S.version = res.version; S.errors = res.errors || {};
        if (payload() === sent) { S.dirty = false; store(LS, null); saveState('Saved', ''); }
        else { store(LS, { data: S.data, base: S.version, dirty: true, at: Date.now() }); saveNow(); }
        var c = read(CACHE); if (c) { c.report.version = S.version; c.report.data = JSON.parse(sent); store(CACHE, c); }
        showErrors();
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
  function flush() { // wait for the save in flight and save what is left
    clearTimeout(S.timer);
    var wait = function () { return S.saving ? new Promise(function (r) { setTimeout(r, 150); }).then(wait) : Promise.resolve(); };
    return wait().then(function () { return S.dirty ? saveNow() : true; }).then(function () { return wait(); });
  }
  window.addEventListener('beforeunload', function () { if (S.dirty && online()) saveNow(); }); // what is not saved yet stays on this device (localStorage)

  // ---------- summary check + files ----------
  function verify() {
    if (!online() || !chosen().length) return;
    S.verifying = true; render();
    flush().then(function () {
      return fetch(P.api + '?a=verify&id=' + P.id, { credentials: 'same-origin' }).then(function (r) { return r.json(); });
    }).then(function (res) {
      S.verifying = false;
      if (res && res.ok && res.results && S.data.step === 'summary' && !S.dirty) S.server = res.results;
      if (S.data.step === 'summary') render();
    }).catch(function () { S.verifying = false; if (S.data.step === 'summary') render(); });
  }
  function generate(type) {
    var btns = $$('[data-gen]'); btns.forEach(function (b) { b.disabled = true; });
    var btn = $('[data-gen="' + type + '"]'), html = btn.innerHTML; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Making the report…';
    flush().then(function () {
      return post({ a: 'generate', id: P.id, pdf: type === 'pdf' ? 1 : 0 });
    }).then(function (res) {
      btn.innerHTML = html; btns.forEach(function (b) { b.disabled = false; });
      if (res.version) S.version = res.version;
      if (!res.ok) {
        if (res.issues && res.issues.length) Swal.fire({ icon: 'warning', title: 'Report not made', html: '<ul class="sr-swal-list">' + res.issues.slice(0, 8).map(function (x) { return '<li>' + esc(x.msg) + '</li>'; }).join('') + '</ul>' });
        else Swal.fire({ icon: 'error', title: 'Report not made', text: res.error || 'Please try again.' });
        return;
      }
      if (type === 'pdf' && res.pdf_error) { Swal.fire({ icon: 'warning', title: 'PDF not available', text: res.pdf_error + ' The Excel report was made; download it below.' }); }
      S.files = res.files; S.server = res.results;
      var c = read(CACHE); if (c) { c.files = S.files; c.report.version = S.version; store(CACHE, c); }
      render();
      var id = res.new_files && res.new_files[type];
      if (id) {
        var a = document.createElement('a'); a.href = P.api + '?a=file&id=' + id; a.setAttribute('download', ''); a.setAttribute('data-testid', 'sr-dl-link');
        document.body.appendChild(a); a.click(); setTimeout(function () { a.remove(); }, 4000);
        toast('success', (type === 'pdf' ? 'PDF' : 'Excel') + ' report downloaded');
      }
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
    // the template's own sections only, with the grades on board (sounding worked out from ullage)
    var secs = S.cfg.sections.map(function (s) { return s.key; }), g = chosen(), d = { fields: S.data.fields, tanks: {} };
    secs.forEach(function (s) { d.tanks[s] = g.indexOf(s) >= 0 ? S.data.tanks[s].map(function (r) { var o = {}; ['name', 'ref_height', 'volume', 'density', 'temp'].forEach(function (k) { o[k] = r[k]; }); o.sounding = sounding(r); return o; }) : []; });
    var extra = g.filter(function (x) { return secs.indexOf(x) < 0; });
    Swal.fire({ title: 'Making the Excel on this device…', allowOutsideClick: false, didOpen: function () { Swal.showLoading(); } });
    get.then(function (buf) { return ReportOfflineXlsx.build(buf, S.cfg.offline, d, S.cfg.calc, secs); })
      .then(function (blob) {
        var a = document.createElement('a'), name = (String(S.data.fields.vessel_name || S.report.vessel).toUpperCase() + ' - OFFHIRE - FIELD REPORT (device).xlsx').replace(/[\\/:*?"<>|]+/g, '_');
        a.href = URL.createObjectURL(blob); a.download = name; document.body.appendChild(a); a.click();
        setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 4000);
        Swal.fire({ icon: 'success', title: 'Draft Excel saved on this device', text: (extra.length ? extra.map(function (x) { return gradeInfo(x).short; }).join(' / ') + ' and the ROB summary are only in the server report. ' : '') + 'Make the final report when you are online.', timer: 5000 });
      }).catch(function (e) { Swal.fire({ icon: 'error', title: 'Excel not made', text: e.message || String(e) }); });
  }

  // ---------- offline support ----------
  if ('serviceWorker' in navigator) navigator.serviceWorker.register('survey_report_sw.js', { scope: './survey_report' }).catch(function () {});
  load();
})();
