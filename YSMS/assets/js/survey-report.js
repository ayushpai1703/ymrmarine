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
  function post(d, retried) {
    var f = new FormData(); f.append('csrf_token', P.csrf); Object.keys(d).forEach(function (k) { f.append(k, d[k]); });
    return fetch(P.api, { method: 'POST', body: f, credentials: 'same-origin', headers: { 'X-CSRF-Token': P.csrf } })
      .then(function (r) {
        return r.json().then(function (j) { j._status = r.status; return j; }).catch(function () {
          // not JSON: the security token changed (signed in again elsewhere) or the session ended
          if (r.status === 403 && !retried) return freshToken().then(function (ok) { return ok ? post(d, true) : signedOut(); });
          if (r.status === 403 || r.redirected || /login/.test(r.url)) return signedOut();
          return { ok: false, _status: r.status, error: 'Unexpected answer from the server (' + r.status + ') — your changes are kept on this device.' };
        });
      })
      .then(function (j) { if (j && j._status === 401) return signedOut(); if (j && j.ok) sessionOk(); return j; })
      .catch(function () { return { ok: false, _status: 0, error: 'No connection.' }; });
  }
  function freshToken() {
    return fetch(P.api + '?a=token', { credentials: 'same-origin' }).then(function (r) { return r.json(); })
      .then(function (j) { if (j && j.ok && j.csrf) { P.csrf = j.csrf; return true; } return false; }).catch(function () { return false; });
  }
  /** Session ended: say so once, keep everything on the device, retry by itself after signing in (any tab). */
  function signedOut() {
    if (!S.signedOut) {
      S.signedOut = true;
      var b = document.querySelector('[data-banners]');
      if (b) b.insertAdjacentHTML('afterbegin', signedOutHtml());
      clearInterval(S.reauth);
      S.reauth = setInterval(function () { freshToken().then(function (ok) { if (ok) { sessionOk(); if (S.dirty) saveNow(); } }); }, 8000);
    }
    return { ok: false, _status: 401, error: 'Session expired — sign in again. Your changes are kept on this device.' };
  }
  function signedOutHtml() { return '<div class="sr-note err" data-signed-out data-testid="sr-signed-out"><i class="fa-solid fa-right-to-bracket"></i> Your session has expired. Your changes are kept on this device. <a href="login.php" target="_blank" rel="noopener">Sign in again</a> (new tab) — saving continues by itself.</div>'; }
  function sessionOk() {
    if (!S.signedOut) return;
    S.signedOut = false; clearInterval(S.reauth);
    var n = document.querySelector('[data-signed-out]'); if (n) n.remove();
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
    $('[data-subtitle]').textContent = (res.report.kind_label || 'Survey') + ' report';
    headChips();
    S.isAdmin = !!res.is_admin; S.tankLog = null;
    var have = steps().map(function (s) { return s.id; });
    var mapStep = function (x) { return /^g:/.test(x || '') ? 'tanks' : x === 'summary' ? 'calc' : x; };
    S.data.step = mapStep(S.data.step);
    var mine = mapStep(read(LS + '_step')); if (mine && have.indexOf(mine) >= 0) S.data.step = mine; // where this person was on this device
    if (have.indexOf(S.data.step) < 0) S.data.step = 'vessel';
    var seen = read(LS + '_seen') || []; if (seen.indexOf(S.data.step) < 0) { seen.push(S.data.step); store(LS + '_seen', seen); }
    if (VESSEL().some(function (k) { return k !== 'vessel_name' && val(k) !== ''; })) S.manual = true; // a draft with particulars: show them (a new report opens with the IMO search only)
    render();
    saveState(S.dirty ? (online() ? 'Saving…' : 'Changes on this device') : 'Saved', S.dirty ? 'dirty' : '');
    if (S.dirty && online() && !fromCache) saveNow();
    if (online() && !fromCache) { precache(); loadPrevious(); setTimeout(finalAfterOffline, 1500); }
  }
  /** Keep this page and everything it loaded (scripts incl. ExcelJS, styles, fonts, CDN files) for offline use. */
  function headChips() {
    var set = function (sel, v) { var e = $(sel); if (e) e.textContent = v; };
    set('[data-vchip]', S.report.vessel || '—'); set('[data-kchip]', S.report.kind_label || '—'); set('[data-kindcrumb]', S.report.kind_label || 'Survey');
    var d = val('completed_date') || val('started_date'), dt = d ? new Date(d + 'T00:00:00') : new Date();
    set('[data-dchip]', isNaN(dt) ? d : dt.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }));
  }
  function precache() {
    if (!('caches' in window)) return;
    var urls = [location.href];
    $$('script[src]').forEach(function (x) { urls.push(x.src); });
    urls.push(new URL(BANNER, location.href).href); // the letterhead banner of the draft PDF
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
  var ROW_KEYS = ['name', 'ref_height', 'sounding', 'volume', 'density', 'temp', 'mode', 'ullage', 'done', 'id', 'copy'];
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
      if (!rows.length) { add('tanks', '', short + ': add at least one tank.'); return; }
      rows.forEach(function (r, i) {
        var e = tankErrors(r), tn = short + ' tank ' + (i + 1) + (r.name ? ' (' + r.name + ')' : '');
        Object.keys(e).forEach(function (col) { add('tanks', 't:' + x + ':' + i + ':' + col, tn + ': ' + e[col]); });
      });
    });
    return out;
  }

  // ---------- steps ----------
  function steps() {
    return [{ id: 'vessel', title: 'Vessel Details', short: 'Vessel Details', m: 'Vessel' }, { id: 'survey', title: 'Survey Details', short: 'Survey Details', m: 'Survey' },
      { id: 'tanks', title: 'Tank Details', short: 'Tank Details', m: 'Tank' }, { id: 'calc', title: 'Calculations', short: 'Calculations', m: 'Calc' },
      { id: 'docs', title: 'Documents', short: 'Documents', m: 'Docs' }, { id: 'generate', title: 'Preview & Generate', short: 'Preview & Generate', m: 'Generate' }];
  }
  function stepIndex(id) { var l = steps(); for (var i = 0; i < l.length; i++) if (l[i].id === id) return i; return 0; }
  function stepDone(id) {
    if (id === 'generate') return false;
    if (id === 'calc' || id === 'docs') return !issues().length;
    return !issues(id).length && (id !== 'survey' || chosen().length > 0);
  }
  /** A step can be opened from the step bar when every step before it is complete, or when it was opened before (to come back after a fix). */
  function reachable(i) {
    var l = steps(), seen = read(LS + '_seen') || [];
    if (l[i] && seen.indexOf(l[i].id) >= 0) return true;
    for (var j = 0; j < i; j++) if (!stepDone(l[j].id)) return false;
    return true;
  }
  function go(id) {
    var seen = read(LS + '_seen') || []; if (seen.indexOf(id) < 0) { seen.push(id); store(LS + '_seen', seen); }
    S.data.step = id; store(LS + '_step', id); S.sheet = null; render(); window.scrollTo(0, 0); // the step is remembered on this device; moving between steps saves nothing by itself (two people can look at the same report without clashing)
    if (id === 'calc' || id === 'generate') { verify(); }
    if (id === 'docs') S.tankLog = null;
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
    $('[data-banners]').innerHTML = (S.signedOut ? signedOutHtml() : '') + note;
    S.wide = window.matchMedia('(min-width: 992px)').matches;
    var list = steps(), i = stepIndex(S.data.step), st = list[i], body;
    if (st.id === 'vessel') body = vesselHtml();
    else if (st.id === 'survey') body = surveyHtml();
    else if (st.id === 'tanks') body = tanksHtml();
    else if (st.id === 'calc') body = calcHtml();
    else if (st.id === 'docs') body = docsHtml();
    else body = generateHtml();
    var crumb = $('[data-crumb]'); if (crumb) crumb.textContent = st.title;
    var bar = '<ol class="sr-steps" data-testid="sr-steps">' + list.map(function (x, j) {
      var cls = j === i ? 'on' : stepDone(x.id) ? 'done' : '', can = reachable(j) || j < i;
      return '<li class="' + cls + '"><button type="button" data-goto="' + x.id + '"' + (can ? '' : ' disabled') + ' data-testid="sr-step-' + x.id + '" title="' + esc(x.title) + '">' +
        '<span class="n">' + (cls === 'done' ? '<i class="fa-solid fa-check"></i>' : j + 1) + '</span><span class="t">' + esc(x.short) + '</span><span class="tm">' + esc(x.m) + '</span></button></li>';
    }).join('') + '</ol>';
    var next = i < list.length - 1 ? list[i + 1] : null;
    var blocked = st.id === 'vessel' && issues('vessel').length > 0;
    $('[data-form]').innerHTML =
      '<div class="sr-wiz" data-testid="sr-wizard">' + bar +
      '<div class="sr-wiz-top"><span data-testid="sr-step-no">Step ' + (i + 1) + ' of ' + list.length + '</span><span class="sr-muted">' + list.filter(function (x) { return stepDone(x.id); }).length + ' of ' + (list.length - 1) + ' steps complete</span></div>' +
      '<h2 class="sr-qt' + (st.id === 'tanks' ? ' sr-vh' : '') + '" data-testid="sr-step-title">' + esc(st.title) + '</h2>' +
      '<div class="sr-step" data-step="' + st.id + '">' + body + '</div>' +
      '<div class="sr-wiz-nav" data-testid="sr-nav"><button type="button" class="sr-btn" data-back' + (i ? '' : ' disabled') + ' data-testid="sr-back"><i class="fa-solid fa-chevron-left"></i> Previous</button>' +
      '<span class="sr-nav-r">' + (S.canEdit ? '<button type="button" class="sr-btn" data-save-now data-testid="sr-save-now"><i class="fa-solid fa-floppy-disk"></i> Save Draft</button>' : '') +
      (next ? '<button type="button" class="sr-btn pri" data-next data-testid="sr-next"' + (blocked ? ' disabled title="Complete the vessel particulars first"' : '') + '>Next: ' + esc(next.title) + ' <i class="fa-solid fa-arrow-right"></i></button>' : '') + '</span></div></div>' +
      (S.sheet ? sheetHtml() : '');
    showErrors(); netState(); pvHint();
  }
  // step 1
  function vesselHtml() {
    var show = S.manual || S.lookup;
    var miss = show ? issues('vessel').filter(function (x) { return x.key !== 'f:imo'; }) : [];
    return '<section class="sr-card sr-imo" data-testid="sr-vessel"><div class="sr-lookup">' + inputHtml('imo', { label: 'IMO Number', req: true }) +
      (S.canEdit ? '<button type="button" class="sr-btn pri" data-lookup data-online-only data-testid="sr-lookup"><i class="fa-solid fa-magnifying-glass"></i> Lookup</button>' : '') + '</div>' +
      (S.canEdit && S.prev ? '<div class="sr-prev" data-testid="sr-prev"><button type="button" class="sr-btn alt-o" data-prev data-testid="sr-prev-fill"><i class="fa-solid fa-clock-rotate-left"></i> Fill from previous attendance</button>' +
        '<span class="sr-muted sr-small">This vessel was attended before — ' + esc(S.prev.label) + '. Fills the particulars, the grades and the tank names with heights.</span></div>' : '') +
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
      card('Other details', 'fa-file-lines', inputHtml('voyage_no') + inputHtml('charterers'), 'other');
  }
  // tank steps
  /** Readings as the surveyors write them, however typed: sounding 0.000, density 0.0000, temperature 00.0. */
  var TFMT = { sounding: 3, density: 4, temp: 1 };
  function fmtT(col, v) {
    var n = num(v); if (n === null || !(col in TFMT)) return v == null ? '' : String(v);
    var t = n.toFixed(TFMT[col]);
    if (col === 'temp' && n >= 0 && n < 10) t = '0' + t;
    return t;
  }
  function fmtRow(r) { Object.keys(TFMT).forEach(function (c) { if (c !== 'sounding' || r.mode !== 'ullage') r[c] = fmtT(c, r[c]); }); }
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
  // ---------- step 3: tank details — a grid on a computer, a list + bottom sheet on a phone ----------
  var OPT_COLS = [['ullage', 'Ullage'], ['volume', 'Volume'], ['vcf', 'V.C.F.'], ['wcf', 'W.C.F.'], ['gsv', 'G.S.V.']];
  function hiddenCols() { return read('ysms_sr_cols') || []; }
  function allTotals() {
    var n = 0, mt = 0, gsv = 0;
    chosen().forEach(function (g) { var t = gradeTotals(g); n += S.data.tanks[g].length; mt += t.mt || 0; gsv += t.gsv || 0; });
    return { n: n, mt: ReportCalc.round(mt, 3), gsv: ReportCalc.round(gsv, 3) };
  }
  function f3c(v) { return v === null || v === undefined || v === '' || isNaN(v) ? '' : (+v).toLocaleString('en-US', { minimumFractionDigits: 3, maximumFractionDigits: 3 }); }
  function totalsBar() {
    var t = allTotals();
    return '<div class="sr-totbar" data-testid="sr-totbar"><div><span>Total Tanks:</span> <b data-testid="sr-tot-tanks">' + t.n + '</b></div>' +
      '<div class="r"><span>Total G.S.V.:</span> <b data-testid="sr-tot-gsv">' + f3c(t.gsv) + ' m³</b></div><div class="r mt"><span>Total MT (Air):</span> <b data-testid="sr-tot-mt">' + f3c(t.mt) + ' MT</b></div></div>';
  }
  function tankToolbar() {
    var prevTanks = S.prev && Object.keys(S.prev.tanks || {}).length;
    return '<div class="sr-tk-tools"><h3><i class="fa-solid fa-database"></i> Tank Details <span class="sr-muted">(Bunker)</span></h3><div class="sr-tk-btns">' +
      (S.canEdit ? '<button type="button" class="sr-btn" data-import data-online-only data-testid="sr-import"><i class="fa-solid fa-file-import"></i> Import from Excel</button>' +
        '<input type="file" accept=".xlsx,.xlsm,.xls,.csv" data-import-file hidden data-testid="sr-import-file">' +
        (prevTanks ? '<button type="button" class="sr-btn" data-prev-tanks data-testid="sr-copy-prev"><i class="fa-regular fa-copy"></i> Copy from Previous</button>' : '') : '') +
      (S.wide ? '<div class="sr-cols"><button type="button" class="sr-btn" data-cols data-testid="sr-cols"><i class="fa-solid fa-table-columns"></i> Columns <i class="fa-solid fa-chevron-down" style="font-size:10px"></i></button>' +
        (S.colsOpen ? '<div class="sr-cols-m" data-testid="sr-cols-menu">' + OPT_COLS.map(function (c) { return '<label><input type="checkbox" data-colt="' + c[0] + '"' + (hiddenCols().indexOf(c[0]) < 0 ? ' checked' : '') + '> ' + c[1] + '</label>'; }).join('') + '</div>' : '') + '</div>' : '') +
      '</div></div>';
  }
  function tanksHtml() {
    var g = chosen();
    if (!g.length) return '<div class="sr-note warn"><i class="fa-solid fa-gas-pump"></i> Choose the fuel grades on board in Survey Details first. <button type="button" class="sr-btn sm" data-goto="survey">Go to Survey Details</button></div>';
    return '<section class="sr-card sr-tkcard">' + tankToolbar() + (S.wide ? gridHtml() : listHtml()) + '</section>' + totalsBar();
  }
  function gradeSelect(g, i) {
    return '<select data-tg="' + g + ':' + i + '" data-testid="sr-tg-' + g + '-' + i + '"' + (S.canEdit ? '' : ' disabled') + '>' + chosen().map(function (x) { return '<option value="' + x + '"' + (x === g ? ' selected' : '') + '>' + esc(gradeInfo(x).short) + '</option>'; }).join('') + '</select>';
  }
  function cellIn(g, i, col, r, mode) {
    var key = g + ':' + i;
    return '<label class="sr-f sr-cellf" data-tw="' + key + ':' + col + '"><input data-t="' + key + ':' + col + '" data-testid="sr-t-' + g + '-' + i + '-' + col + '" value="' + esc(shown(r, col)) + '"' +
      (mode ? ' inputmode="decimal"' : '') + (S.canEdit ? '' : ' disabled') + ' autocomplete="off"' + (col === 'name' ? ' placeholder="Tank name"' : '') + '><em data-terr="' + key + ':' + col + '"></em></label>';
  }
  /** A cell's value as shown: readings in the report's format (0.000 / 0.0000 / 00.0), the other of sounding / ullage worked out. */
  function shown(r, col) { return col === 'ullage' ? ullageOf(r) : col === 'sounding' ? fmtT('sounding', sounding(r)) : col in TFMT ? fmtT(col, r[col]) : r[col]; }
  /** Ullage shown next to the sounding: typed, or reference height − sounding. */
  function ullageOf(r) {
    if (r.mode === 'ullage') return String(r.ullage || '');
    var a = num(r.ref_height), b = num(r.sounding);
    return a === null || b === null ? '' : (Math.round((a - b) * 1000) / 1000).toFixed(3);
  }
  function rowState(r) { return r.done && !r._editing ? 'done' : r.copy ? 'copy' : 'open'; }
  function rowHtml(g, r, i, n) {
    var key = g + ':' + i, t = calc(r), st = rowState(r), hid = hiddenCols();
    var c = function (k, v) { return '<td class="sr-calc c-' + k + (hid.indexOf(k) >= 0 ? ' hid' : '') + '" data-rc="' + key + ':' + k + '">' + v + '</td>'; };
    return '<tr class="sr-row ' + st + '" data-tank="' + key + '" data-testid="sr-tank-' + g + '-' + i + '"><td class="sr-no">' + n + '</td>' +
      '<td class="c-name">' + cellIn(g, i, 'name', r) + (st === 'copy' ? '<span class="sr-copy-dot" title="Copy — change the values, then save" data-testid="sr-copy-badge-' + g + '-' + i + '">Copy</span>' : '') + '</td><td class="c-grade">' + gradeSelect(g, i) + '</td>' +
      '<td>' + cellIn(g, i, 'ref_height', r, true) + '</td><td>' + cellIn(g, i, 'sounding', r, true) + '</td>' +
      '<td class="c-ullage' + (hid.indexOf('ullage') >= 0 ? ' hid' : '') + '">' + cellIn(g, i, 'ullage', r, true) + '</td>' +
      '<td class="c-volume' + (hid.indexOf('volume') >= 0 ? ' hid' : '') + '">' + cellIn(g, i, 'volume', r, true) + '</td>' +
      '<td>' + cellIn(g, i, 'temp', r, true) + '</td><td>' + cellIn(g, i, 'density', r, true) + '</td>' +
      c('vcf', t.vcf == null ? '' : t.vcf.toFixed(4)) + c('wcf', t.wcf == null ? '' : t.wcf.toFixed(4)) + c('gsv', f3c(t.gsv)) + '<td class="sr-calc c-mt" data-rc="' + key + ':mt">' + f3c(t.mt) + '</td>' +
      '<td class="sr-acts">' + actsHtml(g, i, st) + '</td></tr>';
  }
  function actsHtml(g, i, st) {
    if (!S.canEdit) return '';
    var key = g + ':' + i;
    return (st === 'done' ? '<button type="button" class="sr-ic ok" data-edit="' + key + '" title="Saved — edit" data-testid="sr-edit-' + g + '-' + i + '"><i class="fa-solid fa-circle-check"></i></button>'
        : '<button type="button" class="sr-ic save" data-save-tank="' + key + '" title="Save tank" data-testid="sr-save-tank-' + g + '-' + i + '"><i class="fa-solid fa-check"></i></button>') +
      '<button type="button" class="sr-ic" data-copy="' + key + '" title="Copy tank" data-testid="sr-copy-' + g + '-' + i + '"><i class="fa-regular fa-copy"></i></button>' +
      '<button type="button" class="sr-ic del" data-del="' + key + '" title="Delete" data-testid="sr-del-' + g + '-' + i + '"><i class="fa-solid fa-trash-can"></i></button>';
  }
  function gridHtml() {
    var hid = hiddenCols(), n = 0;
    var th = function (k, l, u) { return '<th class="c-' + k + (hid.indexOf(k) >= 0 ? ' hid' : '') + '">' + l + (u ? '<small>' + u + '</small>' : '') + '</th>'; };
    return '<div class="sr-grid-wrap"><table class="sr-tgrid" data-testid="sr-tgrid"><thead><tr><th class="sr-no">#</th><th class="c-name">Tank Name <b class="sr-req">*</b></th><th class="c-grade">Grade</th>' +
      th('ref', 'Ref. Height', '(m)') + th('snd', 'Sounding', '(m)') + th('ullage', 'Ullage', '(m)') + th('volume', 'Volume', '(m³)') + th('temp', 'Temp', '(°C)') + th('den', 'Density', '(kg/l)') +
      th('vcf', 'V.C.F.') + th('wcf', 'W.C.F.') + th('gsv', 'G.S.V.', '(m³ @15°C)') + th('mt', 'MT', '(Air)') + '<th class="sr-acts"></th></tr></thead>' +
      chosen().map(function (g) {
        var gi = gradeInfo(g), rows = S.data.tanks[g], tot = gradeTotals(g);
        return '<tbody data-testid="sr-tanks-' + g + '"><tr class="sr-grp"><td colspan="14"><b>' + esc(gi.short) + '</b> <span class="sr-muted">' + esc(gi.name) + '</span><span class="sr-grp-t">' + rows.length + ' tank' + (rows.length === 1 ? '' : 's') +
          ' · G.S.V. <b data-testid="sr-total-gsv-' + g + '">' + f3(tot.gsv) + '</b> · MT <b data-testid="sr-total-' + g + '">' + f3(tot.mt) + '</b></span></td></tr>' +
          rows.map(function (r, i) { n++; return rowHtml(g, r, i, n); }).join('') +
          (S.canEdit ? '<tr class="sr-addrow"><td colspan="14"><button type="button" data-add="' + g + '" data-testid="sr-add-' + g + '"><i class="fa-solid fa-circle-plus"></i> Add ' + esc(gi.short) + ' tank</button></td></tr>' : '') + '</tbody>';
      }).join('') + '</table></div>';
  }
  function listHtml() {
    var q = (S.q || '').toUpperCase(), n = 0;
    return '<div class="sr-msearch"><i class="fa-solid fa-magnifying-glass"></i><input type="search" data-q value="' + esc(S.q || '') + '" placeholder="Search tank (e.g. NO 1 PORT)" data-testid="sr-q"></div>' +
      chosen().map(function (g) {
        var gi = gradeInfo(g), rows = S.data.tanks[g], tot = gradeTotals(g);
        return '<div class="sr-mgrp" data-testid="sr-tanks-' + g + '"><div class="sr-mgrp-h"><b>' + esc(gi.short) + '</b><span>' + rows.length + ' · <b data-testid="sr-total-' + g + '">' + f3(tot.mt) + '</b> MT</span><b hidden data-testid="sr-total-gsv-' + g + '">' + f3(tot.gsv) + '</b></div>' +
          rows.map(function (r, i) {
            n++;
            if (q && String(r.name).toUpperCase().indexOf(q) < 0) return '';
            var t = calc(r), st = rowState(r);
            return '<button type="button" class="sr-mrow ' + st + '" data-open="' + g + ':' + i + '" data-testid="sr-tank-' + g + '-' + i + '"><span class="no">' + n + '</span><span class="nm"><b>' + esc(r.name || 'New tank') + '</b><span class="bd"><i>' + esc(gi.short) + '</i>' +
              (st === 'done' ? '<i class="ok"><i class="fa-solid fa-check"></i> Saved</i>' : st === 'copy' ? '<i class="cp">Copy</i>' : '<i class="op">Not saved</i>') + '</span></span><span class="mt">' + (t.mt == null ? '—' : f3c(t.mt) + ' MT') + '</span><i class="fa-solid fa-chevron-right"></i></button>';
          }).join('') + '</div>';
      }).join('') +
      (S.canEdit ? '<button type="button" class="sr-madd" data-add="' + chosen()[0] + '" data-testid="sr-add-' + chosen()[0] + '"><i class="fa-solid fa-plus"></i> Add Tank</button>' : '');
  }
  function sheetHtml() {
    var p = S.sheet.split(':'), g = p[0], i = +p[1], r = S.data.tanks[g] && S.data.tanks[g][i];
    if (!r) { S.sheet = null; return ''; }
    var key = g + ':' + i, t = calc(r), dis = S.canEdit ? '' : ' disabled';
    var f = function (col, lab, mode) { return '<label class="sr-f" data-tw="' + key + ':' + col + '"><span>' + lab + '</span><input data-t="' + key + ':' + col + '" data-testid="sr-t-' + g + '-' + i + '-' + col + '" value="' + esc(shown(r, col)) + '"' + (mode ? ' inputmode="decimal"' : '') + dis + ' autocomplete="off"><em data-terr="' + key + ':' + col + '"></em></label>'; };
    return '<div class="sr-sheet-bg" data-sheet-close></div><div class="sr-sheet" role="dialog" data-tank="' + key + '" data-testid="sr-sheet"><div class="sr-sheet-grab"></div>' +
      '<div class="sr-sheet-h"><b>Edit Tank – ' + esc(r.name || 'New tank') + '</b><button type="button" class="sr-ic" data-sheet-close data-testid="sr-sheet-close" aria-label="Close"><i class="fa-solid fa-xmark"></i></button></div>' +
      (r.copy ? '<div class="sr-copy-b" data-testid="sr-copy-badge-' + g + '-' + i + '"><i class="fa-regular fa-copy"></i> Copy — change the values, then Save Tank</div>' : '') +
      '<div class="sr-sheet-f">' + f('name', 'Tank Name *') + '<label class="sr-f"><span>Grade *</span>' + gradeSelect(g, i) + '</label>' +
      f('ref_height', 'Ref. Height (m)', true) + f('sounding', 'Sounding (m)', true) + f('ullage', 'Ullage (m)', true) + f('temp', 'Temperature (°C)', true) + f('volume', 'Volume (m³)', true) + f('density', 'Density (kg/l)', true) + '</div>' +
      '<div class="sr-res" data-res="' + key + '">' + resHtml(t) + '</div>' +
      (S.canEdit ? '<div class="sr-sheet-a"><button type="button" class="sr-btn danger" data-del="' + key + '" data-testid="sr-del-' + g + '-' + i + '"><i class="fa-solid fa-trash"></i> Delete</button>' +
        '<button type="button" class="sr-btn" data-copy="' + key + '" data-testid="sr-copy-' + g + '-' + i + '"><i class="fa-regular fa-copy"></i> Copy</button>' +
        '<button type="button" class="sr-btn pri" data-save-tank="' + key + '" data-testid="sr-save-tank-' + g + '-' + i + '"><i class="fa-solid fa-check"></i> Save Tank</button></div>' : '') + '</div>';
  }
  /** After a typed reading: the row's results, the other of sounding / ullage, the totals — without redrawing the inputs. */
  function updateRow(g, i) {
    var r = S.data.tanks[g][i], key = g + ':' + i, t = calc(r);
    var set = function (k, v) { var e = $('[data-rc="' + key + ':' + k + '"]'); if (e) e.textContent = v; };
    set('vcf', t.vcf == null ? '' : t.vcf.toFixed(4)); set('wcf', t.wcf == null ? '' : t.wcf.toFixed(4)); set('gsv', f3c(t.gsv)); set('mt', f3c(t.mt));
    var res = $('[data-res="' + key + '"]'); if (res) res.innerHTML = resHtml(t);
    var other = r.mode === 'ullage' ? ['sounding', sounding(r)] : ['ullage', ullageOf(r)];
    var oi = $('[data-t="' + key + ':' + other[0] + '"]'); if (oi && document.activeElement !== oi) oi.value = other[1];
    var tot = gradeTotals(g), a = $('[data-testid=sr-total-' + g + ']'), b = $('[data-testid=sr-total-gsv-' + g + ']');
    if (a) a.textContent = f3(tot.mt); if (b) b.textContent = f3(tot.gsv);
    var all = allTotals(), x;
    if ((x = $('[data-testid=sr-tot-tanks]'))) x.textContent = all.n;
    if ((x = $('[data-testid=sr-tot-gsv]'))) x.textContent = f3c(all.gsv) + ' m³';
    if ((x = $('[data-testid=sr-tot-mt]'))) x.textContent = f3c(all.mt) + ' MT';
  }
  function trackOf(r) { return [sounding(r), r.volume, r.density, r.temp].map(function (v) { var n = num(v); return n === null ? String(v || '') : String(n); }).join('|'); }
  /** Leaving a row (grid) or closing the sheet after typing: a complete tank is saved (its version is written). */
  function finishRow(g, i) {
    var r = S.data.tanks[g] && S.data.tanks[g][i]; if (!r || !r._editing) return;
    delete r._editing;
    if (Object.keys(tankErrors(r)).length) { r._tried = true; showErrors(); markRow(g, i); return; }
    if (r.copy && r._src && trackOf(r) === r._src) { markRow(g, i); return; } // an unchanged copy waits for new values
    fmtRow(r); r.done = '1'; r.copy = ''; delete r._src; delete r._tried;
    changed(true); markRow(g, i);
  }
  function markRow(g, i) {
    var r = S.data.tanks[g][i], tr = $('tr[data-tank="' + g + ':' + i + '"]');
    if (!tr) return;
    var html = rowHtml(g, r, i, +$('.sr-no', tr).textContent), tmp = document.createElement('tbody'); tmp.innerHTML = html;
    var nw = tmp.firstChild; tr.parentNode.replaceChild(nw, tr); showErrors();
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
  function problemsHtml() {
    var all = issues(), byStep = {};
    if (!all.length) return '';
    all.forEach(function (x) { (byStep[x.step] = byStep[x.step] || []).push(x); });
    var names = {}; steps().forEach(function (s) { names[s.id] = s.title; }); names.grades = 'Survey Details';
    return '<section class="sr-card sr-probs" data-testid="sr-problems"><h3><i class="fa-solid fa-triangle-exclamation"></i> Before making the report, complete:</h3>' + Object.keys(byStep).map(function (s) {
      var target = names[s] ? s : 'survey';
      return '<div class="sr-prob" data-testid="sr-prob-' + s + '"><div><b>' + esc(names[s] || s) + '</b><ul>' + byStep[s].slice(0, 6).map(function (x) { return '<li>' + esc(x.msg) + '</li>'; }).join('') +
        (byStep[s].length > 6 ? '<li>… and ' + (byStep[s].length - 6) + ' more</li>' : '') + '</ul></div><button type="button" class="sr-btn sm" data-goto="' + target + '">Go to step</button></div>';
    }).join('') + '</section>';
  }
  /** Step 4: the ROB comparison (checked on the server) and the totals per grade. */
  function calcHtml() {
    var rows = summaryRows(), tot = 0, any = false;
    rows.forEach(function (r) { if (r.diff !== null) { tot += r.diff; any = true; } });
    tot = ReportCalc.round(tot, 3);
    var srv = S.verifying ? '<span class="sr-ver" data-testid="sr-verify"><i class="fa-solid fa-spinner fa-spin"></i> Checking on the server…</span>'
      : S.server ? '<span class="sr-ver ok" data-testid="sr-verify"><i class="fa-solid fa-shield-halved"></i> Calculated on the server from the template\'s formulas</span>' : '';
    var gt = chosen().map(function (g) { var t = gradeTotals(g); return '<tr><td><b>' + esc(gradeInfo(g).short) + '</b></td><td>' + S.data.tanks[g].length + '</td><td>' + f3c(t.gsv) + '</td><td><b>' + f3c(t.mt) + '</b></td></tr>'; }).join('');
    return '<section class="sr-card"><h3><i class="fa-solid fa-scale-balanced"></i> ROB comparison ' + srv +
      (rows.length ? '<button type="button" class="sr-share" data-share data-testid="sr-share"><i class="fa-brands fa-whatsapp"></i> Share</button>' : '') + '</h3>' +
      (rows.length ? '<div class="sr-cmp-wrap"><table class="sr-cmp" data-testid="sr-cmp"><thead><tr><th>Fuel Grade</th><th>Survey Completed</th><th>Log Book</th><th>Difference</th></tr></thead><tbody>' +
        rows.map(function (r) { return '<tr data-testid="sr-cmp-' + r.g + '"><td>' + esc(r.short) + '</td><td data-c="completed">' + f3(r.completed) + '</td><td data-c="logbook">' + f3(r.logbook) + '</td><td data-c="diff" class="' + (r.diff < 0 ? 'neg' : r.diff > 0 ? 'pos' : '') + '">' + sgn(r.diff) + '</td></tr>'; }).join('') +
        '</tbody></table></div><div class="sr-totdiff" data-testid="sr-total-diff"><span>TOTAL DIFFERENCE</span><b class="' + (tot < 0 ? 'neg' : tot > 0 ? 'pos' : '') + '">' + (any ? sgn(tot) + ' MT' : '—') + '</b></div>' +
        '<p class="sr-muted sr-small">Survey Completed = sum of MT in Air of the tanks of each grade. Log Book = ROB at survey start (engine-room log book). Difference = Survey Completed − Log Book.</p>'
        : '<p class="sr-muted">No fuel grade with tanks yet.</p>') + '</section>' +
      '<section class="sr-card"><h3><i class="fa-solid fa-calculator"></i> Totals per grade</h3><div class="sr-cmp-wrap"><table class="sr-cmp" data-testid="sr-gtotals"><thead><tr><th>Grade</th><th>Tanks</th><th>G.S.V. (m³ @15°C)</th><th>MT (Air)</th></tr></thead><tbody>' + gt + '</tbody></table></div></section>' +
      problemsHtml();
  }
  /** Step 5: the reports made (PDF), the Excel kept for the Word report generator, the Admin's tank history. */
  function docsHtml() {
    var pdfs = S.files.filter(function (f) { return f.type === 'pdf' || S.isAdmin; });
    return '<section class="sr-card"><h3><i class="fa-solid fa-folder-open"></i> Reports made</h3><div data-files data-testid="sr-files">' + (pdfs.length ? filesHtml(pdfs) : '<span class="sr-muted">No report made yet — make it in Preview &amp; Generate.</span>') + '</div></section>' +
      '<section class="sr-card"><h3><i class="fa-solid fa-file-word"></i> Word report</h3><p class="sr-small" data-testid="sr-excel-note"><i class="fa-solid fa-circle-info"></i> The Excel of each report made is kept in the system and is picked automatically in the Word report generator for this vessel.</p>' +
      (S.report.survey_id ? '<a class="sr-btn" href="report_generator.php?id=' + S.report.survey_id + '" data-testid="sr-word-link"><i class="fa-solid fa-file-word"></i> Open Word report generator</a>' : '') + '</section>' +
      tankLogHtml();
  }
  /** Step 6: preview and make the report. */
  function generateHtml() {
    var all = issues();
    return problemsHtml() +
      '<section class="sr-card"><h3><i class="fa-solid fa-file-export"></i> Preview &amp; Generate</h3><div class="sr-out" data-testid="sr-output">' +
      '<button type="button" class="sr-btn pri big" data-gen="pdf" data-online-only' + (all.length ? ' data-blocked' : '') + ' data-testid="sr-download-pdf"><i class="fa-solid fa-file-pdf"></i> Download PDF Report</button>' +
      '<button type="button" class="sr-btn big" data-pv="load" data-testid="sr-preview-summary"><i class="fa-solid fa-eye"></i> Preview Report</button></div>' +
      '<p class="sr-small sr-muted"><i class="fa-solid fa-circle-info"></i> The PDF is made by the server from the company template; its Excel is kept in the system for the Word report.</p>' +
      '<button type="button" class="sr-link" data-offline-pdf data-testid="sr-offline-pdf"' + (all.length ? ' hidden' : '') + '><i class="fa-solid fa-plane"></i> No internet? Download a draft PDF made on this device</button></section>';
  }
  /** Admin: the version history of the saved tanks (Sounding, Volume, Density, Temperature). */
  var HCOLS = [['sounding', 'Sounding'], ['volume', 'Volume'], ['density', 'Density'], ['temperature', 'Temp']];
  function tankLogHtml() {
    if (!S.isAdmin) return '';
    if (S.tankLog === null) { loadTankLog(); return '<section class="sr-card" data-testid="sr-tank-log"><h3><i class="fa-solid fa-clock-rotate-left"></i> Tank history</h3><span class="sr-muted">Loading…</span></section>'; }
    var T = S.tankLog, edits = 0;
    T.forEach(function (t) { edits += t.versions.length - 1; });
    return '<section class="sr-card" data-testid="sr-tank-log"><h3><i class="fa-solid fa-clock-rotate-left"></i> Tank history <small class="sr-muted">Admin only · ' + edits + ' change' + (edits === 1 ? '' : 's') + ' after the first save</small></h3>' +
      (T.length ? '<div class="sr-cmp-wrap"><table class="sr-t sr-log"><thead><tr><th>Tank</th><th>Version</th>' + HCOLS.map(function (c) { return '<th>' + c[1] + '</th>'; }).join('') + '<th>By</th><th>When</th></tr></thead><tbody>' + T.map(function (t) {
        return t.versions.slice().reverse().map(function (v, k) {
          return '<tr data-testid="sr-hist-' + esc(t.tank_id) + '-' + v.version + '">' + (k ? '' : '<td rowspan="' + t.versions.length + '"><b>' + esc(t.name) + '</b> <small class="sr-muted">' + esc(t.grade_label) + '</small>' + (t.in_report ? '' : ' <span class="sr-del">removed</span>') + '</td>') +
            '<td>v' + v.version + (v.version === t.current_version ? ' <span class="sr-cur">current</span>' : '') + '</td>' +
            HCOLS.map(function (c) { return '<td' + (v.changed.indexOf(c[0]) >= 0 ? ' class="chg"' : '') + '>' + esc(v.values[c[0]] == null ? '—' : v.values[c[0]]) + '</td>'; }).join('') +
            '<td>' + esc(v.by || '') + '</td><td>' + esc(String(v.at).slice(0, 16)) + '</td></tr>';
        }).join('');
      }).join('') + '</tbody></table></div>' : '<span class="sr-muted">No tank has been saved yet.</span>') + '</section>';
  }
  function loadTankLog() {
    if (S.tankLogLoading) return; S.tankLogLoading = true;
    fetch(P.api + '?a=tank_history&id=' + P.id, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (res) {
      S.tankLogLoading = false; S.tankLog = res.ok ? res.tanks : [];
      if (S.data.step === 'docs') render();
    }).catch(function () { S.tankLogLoading = false; });
  }
  function filesHtml(list) {
    list = list || S.files;
    if (!list.length) return '<span class="sr-muted">No files made yet.</span>';
    return '<table class="sr-t"><thead><tr><th>File</th><th>Made</th><th></th></tr></thead><tbody>' + list.map(function (f) {
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
      var marked = false;
      if (id === 'tanks') chosen().forEach(function (g) { S.data.tanks[g].forEach(function (r) { if (!r.done && !r.copy && !Object.keys(tankErrors(r)).length) { fmtRow(r); r.done = '1'; delete r._editing; marked = true; } }); }); // a copy waits for its own save
      if (marked) changed(true);
      if (issues(id).length || (id === 'survey' && !chosen().length)) { if (id === 'tanks') render(); block(id); return; }
      go(l[i + 1].id); return;
    }
    if (t.closest('[data-back]')) { go(steps()[Math.max(0, stepIndex(S.data.step) - 1)].id); return; }
    if (t.closest('[data-lookup]')) { lookup(); return; }
    if (t.closest('[data-prev]')) { fillPrevious(); return; }
    if (t.closest('[data-share]')) { shareCard(); return; }
    if (t.closest('[data-undo]')) { undoLookup(); return; }
    if (t.closest('[data-manual]')) { S.manual = true; render(); var f = $('[data-f=vessel_name]'); if (f) f.focus(); return; }
    if ((b = t.closest('[data-add]'))) { addTank(b.getAttribute('data-add')); return; }
    if ((b = t.closest('[data-open]'))) { openSheet(b.getAttribute('data-open')); return; }
    if (t.closest('[data-sheet-close]')) { closeSheet(); return; }
    if (t.closest('[data-import]')) { var fi = $('[data-import-file]'); if (fi) { fi.value = ''; fi.click(); } return; }
    if (t.closest('[data-prev-tanks]')) { fillPrevious(true); return; }
    if (t.closest('[data-cols]')) { S.colsOpen = !S.colsOpen; render(); return; }
    if (S.colsOpen && !t.closest('.sr-cols')) { S.colsOpen = false; var cm = $('[data-testid=sr-cols-menu]'); if (cm) cm.remove(); }
    if ((b = t.closest('[data-save-tank]'))) { saveTank(b.getAttribute('data-save-tank')); return; }
    if ((b = t.closest('[data-copy]'))) { copyTank(b.getAttribute('data-copy')); return; }
    if ((b = t.closest('[data-edit]'))) { var ek = b.getAttribute('data-edit'); if (!S.wide) { openSheet(ek); return; } var x = $('[data-t="' + ek + ':name"]'); if (x) { x.focus(); x.select(); } return; }
    if ((b = t.closest('[data-expand]'))) { var q = b.getAttribute('data-expand').split(':'), rr = S.data.tanks[q[0]][+q[1]]; S.expand[rr.id] = !S.expand[rr.id]; render(); return; }
    if ((b = t.closest('[data-del]'))) { delTank(b.getAttribute('data-del')); return; }
    if ((b = t.closest('[data-mode]'))) { var m = b.getAttribute('data-mode').split(':'), tr = S.data.tanks[m[0]][+m[1]]; tr.mode = m[2]; tr.sounding = m[2] === 'ullage' ? sounding(tr) : tr.sounding; changed(); render(); return; }
    if ((b = t.closest('[data-gen]'))) { generate(b.getAttribute('data-gen')); return; }
    if (t.closest('[data-offline-pdf]')) { offlinePdf(); return; }
    if (t.closest('[data-save-now]')) { saveProgress(); return; }
    if ((b = t.closest('[data-pv]'))) { pvAction(b.getAttribute('data-pv')); return; }
  });
  document.addEventListener('change', function (ev) {
    var el = ev.target;
    if (el.matches('[data-grade]')) { toggleGrade(el.getAttribute('data-grade'), el.checked); return; }
    if (el.matches('[data-tg]')) { moveTank(el.getAttribute('data-tg'), el.value); return; }
    if (el.matches('[data-colt]')) { var hc = hiddenCols().filter(function (c) { return c !== el.getAttribute('data-colt'); }); if (!el.checked) hc.push(el.getAttribute('data-colt')); store('ysms_sr_cols', hc); render(); return; }
    if (el.matches('[data-import-file]')) { if (el.files && el.files[0]) importTanks(el.files[0]); return; }
    if (el.matches('select[data-f]')) { setVal(el.getAttribute('data-f'), el.value); changed(); refreshLight(); }
    if (el.matches('[data-t]')) { // leaving a reading: shown in the report's format
      var p = el.getAttribute('data-t').split(':'), r = S.data.tanks[p[0]] && S.data.tanks[p[0]][+p[1]];
      if (r && p[2] in TFMT) { var v = fmtT(p[2], el.value); if (v !== el.value) { el.value = v; r[p[2]] = v; changed(); } }
    }
  });
  document.addEventListener('input', function (ev) {
    var el = ev.target;
    if (el.matches('[data-f]')) {
      var k = el.getAttribute('data-f'); setVal(k, el.value);
      if (S.ai[k]) { delete S.ai[k]; el.classList.remove('ai'); el.title = ''; }
      delete S.errors['f:' + k];
      changed(); refreshLight(); return;
    }
    if (el.matches('[data-q]')) { S.q = el.value; render(); var qi = $('[data-q]'); if (qi) { qi.focus(); qi.setSelectionRange(qi.value.length, qi.value.length); } return; }
    if (el.matches('[data-t]')) {
      var p = el.getAttribute('data-t').split(':'), r = S.data.tanks[p[0]][+p[1]];
      if (r.done === '1') r.done = ''; // being edited: its version is written when it is saved again
      r._editing = true;
      if (p[2] === 'sounding') { r.mode = 'sounding'; r.sounding = el.value; r.ullage = ''; }
      else if (p[2] === 'ullage') { r.mode = 'ullage'; r.ullage = el.value; r.sounding = sounding(r); }
      else { r[p[2]] = el.value; if (p[2] === 'ref_height' && r.mode === 'ullage') r.sounding = sounding(r); }
      var tr = el.closest('tr.sr-row');
      if (tr && tr.classList.contains('done')) { tr.classList.remove('done'); tr.classList.add('open'); var ac = $('.sr-acts', tr); if (ac) ac.innerHTML = actsHtml(p[0], +p[1], 'open'); } // its Save button instead of the saved mark
      updateRow(p[0], +p[1]);
      changed(); showErrors();
    }
  });
  // the grid: leaving a row saves a complete tank (its version), like "Save tank"
  document.addEventListener('focusout', function (ev) {
    var tr = ev.target.closest && ev.target.closest('tr.sr-row'); if (!tr) return;
    var key = tr.getAttribute('data-tank');
    setTimeout(function () {
      var a = document.activeElement, now = a && a.closest && a.closest('tr.sr-row');
      if (now && now.getAttribute('data-tank') === key) return;
      var p = key.split(':'); finishRow(p[0], +p[1]);
    }, 0);
  });
  var lastWide = null;
  window.addEventListener('resize', function () { var w = window.matchMedia('(min-width: 992px)').matches; if (S.data && lastWide !== null && w !== lastWide && S.data.step === 'tanks') render(); lastWide = w; });
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
  /** Earlier attendance of this vessel (a report on the website, or its uploaded Excel). */
  function loadPrevious() {
    if (!S.canEdit || !online()) return;
    fetch(P.api + '?a=previous&id=' + P.id, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (res) {
      S.prev = res && res.ok ? res.previous : null;
      if (S.prev && (S.data.step === 'vessel' || S.data.step === 'tanks')) render();
    }).catch(function () {});
  }
  function fillPrevious(tanksOnly) {
    var P0 = S.prev; if (!P0) return;
    var gNames = (P0.grades || []).map(function (g) { var i = gradeInfo(g); return i ? i.short : g; });
    var tankCount = 0; Object.keys(P0.tanks || {}).forEach(function (g) { tankCount += P0.tanks[g].length; });
    var busy = Object.keys(P0.tanks || {}).filter(function (g) { return gradeInfo(g) && S.data.tanks[g] && S.data.tanks[g].length; });
    Swal.fire({ icon: 'question', title: 'Fill from previous attendance?', confirmButtonText: 'Fill', showCancelButton: true,
      html: '<div style="text-align:left;font-size:14px">From: <b>' + esc(P0.label) + '</b><ul style="margin:8px 0 0 18px;padding:0">' +
        (tanksOnly ? '' : '<li>Step 1: ' + Object.keys(P0.fields || {}).length + ' vessel particulars</li>' +
        '<li>Step 2: grades ' + esc(gNames.join(', ') || '—') + '</li>') +
        '<li>Step 3: ' + tankCount + ' tank names with reference heights (readings stay empty)</li></ul>' +
        (busy.length ? '<p class="sr-muted" style="margin-top:8px">Tanks already entered (' + esc(busy.map(function (g) { return gradeInfo(g).short; }).join(', ')) + ') are kept as they are.</p>' : '') + '</div>'
    }).then(function (x) {
      if (!x.isConfirmed) return;
      if (!tanksOnly) Object.keys(P0.fields || {}).forEach(function (k) { if (FIELD(k)) { setVal(k, P0.fields[k]); if (P0.source === 'excel') S.ai[k] = true; } });
      var gs = (P0.grades || []).filter(function (g) { return !!gradeInfo(g); });
      if (gs.length && !tanksOnly) S.data.grades = S.cfg.grades.map(function (g) { return g.key; }).filter(function (g) { return gs.indexOf(g) >= 0; });
      if (tanksOnly) gs.forEach(function (g) { if (S.data.grades.indexOf(g) < 0 && (P0.tanks[g] || []).length) S.data.grades.push(g); });
      Object.keys(P0.tanks || {}).forEach(function (g) {
        if (!gradeInfo(g) || (S.data.tanks[g] && S.data.tanks[g].length)) return;
        S.data.tanks[g] = P0.tanks[g].map(function (t) { var r = emptyRow(); r.name = t.name; r.ref_height = t.ref_height; return r; });
      });
      S.manual = true; S.errors = {};
      changed(); render(); toast('success', 'Filled from the previous attendance — please check');
    });
  }
  // ---------- ROB comparison as an image, shared on WhatsApp ----------
  function cardImage() {
    var rows = summaryRows(), tot = 0, any = false;
    rows.forEach(function (r) { if (r.diff !== null) { tot += r.diff; any = true; } });
    tot = ReportCalc.round(tot, 3);
    var W = 1080, pad = 64, rowH = 92, H = 520 + rows.length * rowH + 260;
    var c = document.createElement('canvas'); c.width = W; c.height = H;
    var x = c.getContext('2d'), F = function (w, s) { return w + ' ' + s + 'px Lexend, "Segoe UI", Roboto, Arial, sans-serif'; };
    var rr = function (X, Y, w, h, r) { x.beginPath(); x.moveTo(X + r, Y); x.arcTo(X + w, Y, X + w, Y + h, r); x.arcTo(X + w, Y + h, X, Y + h, r); x.arcTo(X, Y + h, X, Y, r); x.arcTo(X, Y, X + w, Y, r); x.closePath(); };
    x.fillStyle = '#F4F7FB'; x.fillRect(0, 0, W, H);
    var gr = x.createLinearGradient(0, 0, W, 300); gr.addColorStop(0, '#00A3DF'); gr.addColorStop(1, '#6C4CF1');
    x.fillStyle = gr; x.fillRect(0, 0, W, 300);
    x.fillStyle = 'rgba(255,255,255,.85)'; x.font = F(600, 30); x.fillText('YMR MARINE SOLUTIONS', pad, 78);
    x.fillStyle = '#fff'; x.font = F(800, 54);
    var vn = S.report.vessel || val('vessel_name'); while (x.measureText(vn).width > W - 2 * pad && vn.length > 4) vn = vn.slice(0, -2);
    x.fillText(vn, pad, 158);
    x.font = F(500, 30); x.fillStyle = 'rgba(255,255,255,.92)';
    var sub = [(S.report.kind_label || 'Survey') + ' · ROB comparison', val('place') || val('port_name'), val('completed_date')].filter(Boolean).join('  ·  ');
    x.fillText(sub, pad, 214);
    if (val('imo')) { x.font = F(500, 26); x.fillText('IMO ' + val('imo'), pad, 262); }
    // table card
    var top = 340, cw = W - 2 * pad;
    x.save(); x.shadowColor = 'rgba(15,23,42,.10)'; x.shadowBlur = 30; x.shadowOffsetY = 8; x.fillStyle = '#fff'; rr(pad, top, cw, 120 + rows.length * rowH, 28); x.fill(); x.restore();
    var cols = [pad + 36, pad + 330, pad + 610, pad + cw - 36];
    x.font = F(700, 24); x.fillStyle = '#64748B';
    x.textAlign = 'left'; x.fillText('FUEL GRADE', cols[0], top + 66);
    x.textAlign = 'right'; x.fillText('SURVEY (MT)', cols[1] + 150, top + 66); x.fillText('LOG BOOK (MT)', cols[2] + 150, top + 66); x.fillText('DIFFERENCE', cols[3], top + 66);
    rows.forEach(function (r, i) {
      var y = top + 120 + i * rowH;
      x.strokeStyle = '#E2E8F0'; x.lineWidth = 2; x.beginPath(); x.moveTo(pad + 24, y - 34); x.lineTo(pad + cw - 24, y - 34); x.stroke();
      x.textAlign = 'left'; x.fillStyle = '#0F172A'; x.font = F(800, 34); x.fillText(r.short, cols[0], y + 22);
      x.textAlign = 'right'; x.font = F(600, 32); x.fillStyle = '#0F172A';
      x.fillText(f3(r.completed) || '—', cols[1] + 150, y + 22); x.fillText(f3(r.logbook) || '—', cols[2] + 150, y + 22);
      x.fillStyle = r.diff < 0 ? '#DC2626' : r.diff > 0 ? '#16A34A' : '#334155'; x.font = F(800, 34); x.fillText(sgn(r.diff), cols[3], y + 22);
    });
    // total
    var ty = top + 150 + rows.length * rowH;
    x.fillStyle = '#0F2B5B'; rr(pad, ty, cw, 120, 28); x.fill();
    x.textAlign = 'left'; x.fillStyle = 'rgba(255,255,255,.8)'; x.font = F(700, 28); x.fillText('TOTAL DIFFERENCE', pad + 36, ty + 72);
    x.textAlign = 'right'; x.fillStyle = tot < 0 ? '#FCA5A5' : tot > 0 ? '#86EFAC' : '#fff'; x.font = F(800, 48); x.fillText(any ? sgn(tot) + ' MT' : '—', pad + cw - 36, ty + 78);
    x.textAlign = 'left'; x.fillStyle = '#64748B'; x.font = F(500, 24);
    x.fillText('Survey Completed − Log Book ROB at survey start' + (val('surveyor_name') ? '  ·  Surveyor: ' + val('surveyor_name') : ''), pad, H - 50);
    return new Promise(function (ok) { c.toBlob(function (b) { ok(b); }, 'image/png'); });
  }
  function shareCard() {
    var name = ((S.report.vessel || 'vessel') + ' ROB comparison').replace(/[^A-Za-z0-9 ._-]+/g, '').trim().replace(/\s+/g, '_') + '.png';
    var text = (S.report.vessel || '') + ' — ' + (S.report.kind_label || 'Survey') + ' ROB comparison';
    var ready = document.fonts && document.fonts.ready ? document.fonts.ready : Promise.resolve();
    ready.then(cardImage).then(function (blob) {
      S.shareBlob = blob;
      var file = new File([blob], name, { type: 'image/png' });
      if (navigator.canShare && navigator.canShare({ files: [file] })) { // phone: the share sheet (WhatsApp)
        return navigator.share({ files: [file], text: text, title: text }).catch(function () {});
      }
      // computer: copy the picture (paste it in the chat) and download it, then open WhatsApp
      var copied = window.ClipboardItem && navigator.clipboard && navigator.clipboard.write ? navigator.clipboard.write([new ClipboardItem({ 'image/png': blob })]).then(function () { return true; }, function () { return false; }) : Promise.resolve(false);
      return copied.then(function (ok) {
        var a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = name; a.setAttribute('data-testid', 'sr-share-dl');
        document.body.appendChild(a); a.click(); setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 4000);
        Swal.fire({ icon: 'success', title: 'Image ready', html: (ok ? 'The picture is copied — paste it in the WhatsApp chat (Ctrl+V).' : 'The picture was downloaded — attach it in the WhatsApp chat.'),
          confirmButtonText: '<i class="fa-brands fa-whatsapp"></i> Open WhatsApp', showCancelButton: true, cancelButtonText: 'Close' })
          .then(function (r) { if (r.isConfirmed) window.open('https://web.whatsapp.com/send?text=' + encodeURIComponent(text), '_blank', 'noopener'); });
      });
    }).catch(function (e) { toast('error', 'Could not make the image: ' + (e && e.message || e)); });
  }
  function addTank(g) {
    var row = emptyRow(); S.data.tanks[g].push(row); changed();
    var key = g + ':' + (S.data.tanks[g].length - 1);
    if (!S.wide) { openSheet(key); return; }
    render(); var n = $('[data-t="' + key + ':name"]'); if (n) { n.focus(); n.scrollIntoView({ block: 'center' }); }
  }
  function openSheet(key) { S.sheet = key; render(); document.body.classList.add('sr-sheet-on'); var n = $('.sr-sheet [data-t$=":name"]'); if (n && !n.value) n.focus(); }
  function closeSheet() {
    if (S.sheet) { var p = S.sheet.split(':'); S.sheet = null; finishRow(p[0], +p[1]); }
    document.body.classList.remove('sr-sheet-on'); render();
  }
  function moveTank(key, to) {
    var p = key.split(':'), from = p[0], i = +p[1];
    if (from === to || !S.data.tanks[to]) return;
    var r = S.data.tanks[from].splice(i, 1)[0]; S.data.tanks[to].push(r);
    if (S.sheet === key) S.sheet = to + ':' + (S.data.tanks[to].length - 1);
    changed(); render(); toast('success', (r.name || 'Tank') + ' moved to ' + gradeInfo(to).short);
  }
  /** "Import from Excel": tank rows from a workbook (company format or a plain table), added to the grid. */
  function importTanks(file) {
    var f = new FormData(); f.append('csrf_token', P.csrf); f.append('a', 'import_tanks'); f.append('id', P.id); f.append('file', file);
    Swal.fire({ title: 'Reading the Excel…', allowOutsideClick: false, didOpen: function () { Swal.showLoading(); } });
    fetch(P.api, { method: 'POST', body: f, credentials: 'same-origin', headers: { 'X-CSRF-Token': P.csrf } }).then(function (r) { return r.json(); }).then(function (res) {
      if (!res.ok) { Swal.fire({ icon: 'error', title: 'Not imported', text: res.error || 'Please try again.' }); return; }
      var rows = res.rows, have = 0; chosen().forEach(function (g) { have += S.data.tanks[g].length; });
      var byG = {}; rows.forEach(function (r) { byG[r.grade] = (byG[r.grade] || 0) + 1; });
      Swal.fire({ icon: 'question', title: 'Import ' + rows.length + ' tank' + (rows.length === 1 ? '' : 's') + '?', confirmButtonText: have ? 'Add to the list' : 'Import', showCancelButton: true, showDenyButton: !!have, denyButtonText: 'Replace the list',
        html: Object.keys(byG).map(function (g) { return esc((gradeInfo(g) || { short: g }).short) + ': ' + byG[g]; }).join(' · ') })
        .then(function (x) {
          if (!x.isConfirmed && !x.isDenied) return;
          if (x.isDenied) chosen().forEach(function (g) { S.data.tanks[g] = []; });
          rows.forEach(function (t) {
            if (!S.data.tanks[t.grade]) return;
            if (S.data.grades.indexOf(t.grade) < 0) S.data.grades.push(t.grade);
            var r = emptyRow(); ['name', 'ref_height', 'volume', 'density', 'temp'].forEach(function (k) { r[k] = t[k] || ''; });
            if (t.ullage && !t.sounding) { r.mode = 'ullage'; r.ullage = t.ullage; r.sounding = sounding(r); } else r.sounding = t.sounding || '';
            if (!Object.keys(tankErrors(r)).length) { fmtRow(r); r.done = '1'; }
            S.data.tanks[t.grade].push(r);
          });
          changed(); render(); toast('success', rows.length + ' tanks imported');
        });
    }).catch(function () { Swal.fire({ icon: 'error', title: 'Not imported', text: 'No connection.' }); });
  }
  function emptyRow() { return { name: '', ref_height: '', sounding: '', volume: '', density: '', temp: '', mode: 'sounding', ullage: '', done: '', id: uid() }; }
  function saveTank(key) {
    var p = key.split(':'), r = S.data.tanks[p[0]][+p[1]], e = tankErrors(r);
    r._tried = true;
    if (Object.keys(e).length) { showErrors(); var first = $('[data-tank="' + key + '"] .sr-f.bad'); if (first) first.scrollIntoView({ behavior: 'smooth', block: 'center' }); toast('error', 'Complete the marked values of this tank.'); return; }
    fmtRow(r); r.done = '1'; r.copy = ''; delete S.open[r.id]; delete r._tried; delete r._editing; delete r._src;
    if (S.sheet === key) { S.sheet = null; document.body.classList.remove('sr-sheet-on'); }
    changed(); saveNow(); render();
    var card = $('[data-tank="' + key + '"]'); if (card) card.scrollIntoView({ block: 'nearest' });
  }
  /** A new tank right after this one with the same values, open for editing; its version 1 is made when it is saved. */
  function copyTank(key) {
    var p = key.split(':'), src = S.data.tanks[p[0]][+p[1]], row = {};
    ROW_KEYS.forEach(function (k) { row[k] = src[k] == null ? '' : String(src[k]); });
    row.id = uid(); row.done = ''; row.copy = '1'; row._src = trackOf(src);
    S.data.tanks[p[0]].splice(+p[1] + 1, 0, row);
    changed();
    var nk = p[0] + ':' + (+p[1] + 1);
    if (!S.wide) { S.sheet = nk; render(); return toast('success', 'Tank copied — change the values and press Save Tank'); }
    render();
    var n = $('[data-t="' + nk + ':name"]'); if (n) { n.scrollIntoView({ block: 'center' }); n.focus(); n.select(); }
    toast('success', 'Tank copied — change the values and press Save tank');
  }
  function delTank(key) {
    var p = key.split(':'), r = S.data.tanks[p[0]][+p[1]];
    var doit = function () { S.data.tanks[p[0]].splice(+p[1], 1); if (S.sheet) { S.sheet = null; document.body.classList.remove('sr-sheet-on'); } changed(); render(); };
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
        if (payload() === sent) { S.dirty = false; store(LS, null); saveState('Saved', ''); pvHint(); }
        else { store(LS, { data: S.data, base: S.version, dirty: true, at: Date.now() }); saveNow(); }
        var c = read(CACHE); if (c) { c.report.version = S.version; c.report.data = JSON.parse(sent); store(CACHE, c); }
        showErrors();
        return true;
      }
      if (res._status === 409 && res.server) {
        saveState('Save failed — changed elsewhere', 'err');
        Swal.fire({ icon: 'warning', title: 'Changed by someone else', html: esc(res.error) + '<br>Keep your changes (they replace the other version) or load the saved one?', showDenyButton: true,
          confirmButtonText: 'Keep my changes', denyButtonText: 'Load the saved report', allowOutsideClick: false }).then(function (r) {
          if (r.isConfirmed) { S.version = res.server.version; saveNow(); }
          else { S.version = res.server.version; S.data = normalise(res.server.data); S.dirty = false; store(LS, null); render(); saveState('Saved', ''); }
        });
        return false;
      }
      saveState(res._status === 0 ? 'Changes on this device' : 'Save failed', 'err');
      if (res.error) toast('error', res.error);
      return false;
    }).catch(function () { S.saving = false; saveState('Changes on this device', 'dirty'); return false; });
  }
  function flush() { // wait for the save in flight and save what is left
    clearTimeout(S.timer);
    var wait = function () { return S.saving ? new Promise(function (r) { setTimeout(r, 150); }).then(wait) : Promise.resolve(); };
    return wait().then(function () { return S.dirty ? saveNow() : true; }).then(function () { return wait(); });
  }
  window.addEventListener('beforeunload', function () { if (S.dirty && online()) saveNow(); });
  /**
   * Live: what someone else saves shows up here within seconds (e.g. the Admin watching the
   * surveyor fill the tanks). Only while nothing is waiting to be saved on this device and no box
   * is being typed in; this person's own step stays where it is.
   */
  function livePoll() {
    if (document.hidden || !online() || S.dirty || S.saving || !S.data || S.signedOut || S.offlineMode) return;
    var a = document.activeElement;
    if (a && a.matches && a.matches('[data-f],[data-t]') && S.canEdit) return;
    fetch(P.api + '?a=version&id=' + P.id, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (v) {
      if (!v || !v.ok || v.version <= S.version) return null;
      return fetch(P.api + '?a=get&id=' + P.id, { credentials: 'same-origin' }).then(function (r) { return r.json(); });
    }).then(function (res) {
      if (!res || !res.ok || res.report.version <= S.version || S.dirty || S.saving) return;
      var step = S.data.step;
      S.version = res.report.version; S.data = normalise(res.report.data); S.data.step = step;
      S.files = res.files || S.files; S.server = null; S.tankLog = null;
      store(CACHE, res);
      render();
      if (S.data.step === 'calc' || S.data.step === 'generate') verify();
      headChips();
      var who = res.report.updated_by || 'someone';
      var el = $('[data-live]'); if (el) { el.textContent = 'Updated live — ' + who + ', ' + String(res.report.updated_at).slice(11, 16); el.hidden = false; }
      pvHint();
    }).catch(function () {});
  }
  setInterval(livePoll, 8000); // what is not saved yet stays on this device (localStorage)
  /** "Save Progress": save now (whatever the timer), say how it went. */
  function saveProgress() {
    if (!S.canEdit) return;
    if (!online()) { toast('info', 'Offline — kept on this device, saved when you are back online.'); return; }
    if (!S.dirty) { saveState('Saved', ''); toast('success', 'All changes are saved.'); return; }
    saveState('Saving…', 'dirty');
    flush().then(function () { if (!S.dirty) toast('success', 'Progress saved.'); });
  }

  // ---------- live preview (right panel; a phone opens it with the Preview button) ----------
  // The server makes the report from the SAVED values into a temporary PDF (ajax a=preview): nothing
  // is stored with the report, no file is listed, the vessel's status is not touched.
  var PV = { doc: null, page: 1, zoom: 1, seq: 0, timer: null, ver: null, lib: null };
  function pvOpen() { return document.body.classList.contains('sr-pv-open'); }
  function pvEl(s) { return document.querySelector('[data-pv-' + s + ']'); }
  function pvLib() {
    if (PV.lib) return PV.lib;
    PV.lib = new Promise(function (ok, no) {
      if (window.pdfjsLib) return ok(window.pdfjsLib);
      var sc = document.createElement('script'); sc.src = P.pdfjs; sc.onload = function () { window.pdfjsLib.GlobalWorkerOptions.workerSrc = P.pdfjsWorker; ok(window.pdfjsLib); };
      sc.onerror = function () { PV.lib = null; no(new Error('The preview viewer could not be loaded.')); }; document.head.appendChild(sc);
    });
    return PV.lib;
  }
  function pvStatus(text, cls) { var e = pvEl('status'); if (e) { e.textContent = text; e.className = 'sr-pv-st ' + (cls || ''); } }
  function preview(now) {
    if (!pvEl('canvas')) return;
    clearTimeout(PV.timer);
    PV.timer = setTimeout(pvLoad, now ? 0 : 900);
  }
  function pvLoad() {
    if (!online()) { pvStatus('Offline — preview when back online', 'warn'); return; }
    if (S.dirty || S.saving) { PV.timer = setTimeout(pvLoad, 700); return; } // preview what is saved, never a half-saved state
    if (PV.ver === S.version && PV.doc) return;
    var seq = ++PV.seq, ver = S.version;
    pvStatus('Updating…', 'busy');
    Promise.all([pvLib(), fetch(P.api + '?a=preview&id=' + P.id + '&v=' + ver, { credentials: 'same-origin' }).then(function (r) {
      if (!r.ok) return r.json().catch(function () { return {}; }).then(function (j) { throw new Error(j.error || 'Preview not available (' + r.status + ').'); });
      return r.arrayBuffer();
    })]).then(function (x) {
      if (seq !== PV.seq) return; // a newer preview was asked for meanwhile
      return x[0].getDocument({ data: new Uint8Array(x[1]) }).promise.then(function (doc) {
        if (seq !== PV.seq) return;
        if (PV.doc) PV.doc.destroy();
        PV.doc = doc; PV.ver = ver; PV.page = Math.min(PV.page, doc.numPages) || 1;
        pvStatus('Preview of the saved report', 'ok');
        return pvDraw();
      });
    }).catch(function (e) { if (seq === PV.seq) pvStatus(e.message || 'Preview not available.', 'err'); });
  }
  function pvDraw() {
    if (!PV.doc) return;
    var box = pvEl('canvas'), n = PV.doc.numPages;
    pvEl('page').textContent = PV.page + ' / ' + n;
    pvEl('zoomv').textContent = Math.round(PV.zoom * 100) + '%';
    return PV.doc.getPage(PV.page).then(function (pg) {
      var w = Math.max(240, box.clientWidth - 16), v1 = pg.getViewport({ scale: 1 }), scale = (w / v1.width) * PV.zoom, dpr = window.devicePixelRatio || 1;
      var vp = pg.getViewport({ scale: scale * dpr }), c = document.createElement('canvas');
      c.width = vp.width; c.height = vp.height; c.style.width = (vp.width / dpr) + 'px'; c.style.height = (vp.height / dpr) + 'px';
      c.setAttribute('data-testid', 'sr-pv-page');
      return pg.render({ canvasContext: c.getContext('2d'), viewport: vp }).promise.then(function () { box.innerHTML = ''; box.appendChild(c); });
    });
  }
  function pvPanelShown() { var a = $('[data-testid=sr-preview]'); return !!a && a.offsetParent !== null && !pvOpen() || pvOpen(); }
  function pvTab(t) {
    PV.tab = t;
    $$('[data-pv-tab]').forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-pv-tab') === t); });
    var c = pvEl('canvas'), x = $('[data-pv-xl]'), bar = $('[data-pv-bar]');
    if (c) c.hidden = t !== 'pdf'; if (x) x.hidden = t !== 'excel'; if (bar) bar.hidden = false;
    var nav = ['prev', 'next', 'in', 'out', 'fit']; $$('[data-pv-bar] .sr-pv-b, [data-pv-page]').forEach(function (e) { e.hidden = t !== 'pdf'; });
  }
  /** Report changed since the preview was made: say so (the preview is refreshed only when pressed). */
  function pvHint() {
    if (PV.tab && PV.ver != null && S.version !== PV.ver) pvStatus('The report changed — press ' + (PV.tab === 'excel' ? 'Excel View' : 'Report Preview') + ' to refresh', 'warn');
  }
  function excelView() {
    var x = $('[data-pv-xl]');
    if (!x) { // made only when asked for (an empty sandboxed frame is never on the page)
      x = document.createElement('iframe'); x.className = 'sr-pv-xl'; x.title = 'Excel view'; x.setAttribute('sandbox', 'allow-same-origin'); x.setAttribute('data-pv-xl', ''); x.setAttribute('data-testid', 'sr-pv-xl'); // no scripts run inside
      var cv = pvEl('canvas'); cv.parentNode.insertBefore(x, cv.nextSibling);
    }
    if (!online()) { pvStatus('Offline — the views are made by the server', 'warn'); return; }
    pvStatus('Updating…', 'busy');
    var go2 = function () {
      fetch(P.api + '?a=excel_view&id=' + P.id, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (res) {
        if (!res.ok) { pvStatus(res.error || 'Not available', 'err'); return; }
        x.srcdoc = '<!doctype html><html><head><meta charset="utf-8"><style>body{margin:8px;font-family:Arial,sans-serif;background:#fff}.xv-sheet{font:700 13px Arial;color:#0f2b5b;margin:14px 0 6px}table{border-collapse:collapse}</style></head><body>' + res.html + '</body></html>';
        PV.ver = res.version; pvStatus('Excel view of the saved report', 'ok');
      }).catch(function () { pvStatus('Not available', 'err'); });
    };
    if (S.dirty || S.saving) flush().then(go2); else go2();
  }
  function pvAction(a) {
    if (a === 'load') { if (!pvPanelShown()) document.body.classList.add('sr-pv-open'); pvTab('pdf'); PV.ver = null; if (S.dirty || S.saving) flush(); preview(true); return; }
    if (a === 'excel') { if (!pvPanelShown()) document.body.classList.add('sr-pv-open'); pvTab('excel'); excelView(); return; }
    if (a === 'open') { document.body.classList.add('sr-pv-open'); if (!PV.tab) pvAction('load'); else if (PV.doc) pvDraw(); return; }
    if (a === 'close') { document.body.classList.remove('sr-pv-open'); return; }
    if (a === 'refresh') { PV.ver = null; preview(true); return; }
    if (!PV.doc) return;
    if (a === 'prev' && PV.page > 1) PV.page--;
    else if (a === 'next' && PV.page < PV.doc.numPages) PV.page++;
    else if (a === 'in') PV.zoom = Math.min(3, PV.zoom + 0.25);
    else if (a === 'out') PV.zoom = Math.max(0.5, PV.zoom - 0.25);
    else if (a === 'fit') PV.zoom = 1;
    else return;
    pvDraw();
  }
  window.addEventListener('resize', function () { clearTimeout(PV.rs); PV.rs = setTimeout(pvDraw, 200); });
  document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape' && pvOpen()) pvAction('close'); });

  // ---------- summary check + files ----------
  function verify() {
    if (!online() || !chosen().length) return;
    S.verifying = true; render();
    flush().then(function () {
      return fetch(P.api + '?a=verify&id=' + P.id, { credentials: 'same-origin' }).then(function (r) { return r.json(); });
    }).then(function (res) {
      S.verifying = false;
      var here = S.data.step === 'calc' || S.data.step === 'generate';
      if (res && res.ok && res.results && here && !S.dirty) S.server = res.results;
      if (here) render();
    }).catch(function () { S.verifying = false; if (S.data.step === 'calc' || S.data.step === 'generate') render(); });
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
      S.files = res.files; S.server = res.results; S.tankLog = null;
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
  // ---------- draft PDF made on this device (no internet) ----------
  // The final report is made by the server from the company template. Without internet the
  // surveyor still gets a PDF: the same particulars, tank tables and ROB summary, drawn here with
  // jsPDF and marked DRAFT. When the connection is back the data is saved and the final PDF (and
  // the Excel kept in the system) is made by itself.
  var BANNER = 'assets/images/survey-letterhead.png';
  function imgData(url) {
    return fetch(url, { credentials: 'same-origin' }).then(function (r) { if (!r.ok) throw new Error(); return r.blob(); }).then(function (b) {
      return new Promise(function (ok, no) { var fr = new FileReader(); fr.onload = function () { var im = new Image(); im.onload = function () { ok({ data: fr.result, w: im.naturalWidth, h: im.naturalHeight }); }; im.onerror = no; im.src = fr.result; }; fr.onerror = no; fr.readAsDataURL(b); });
    }).catch(function () { return null; });
  }
  function offlinePdf() {
    if (!window.jspdf || !window.jspdf.jsPDF) { Swal.fire({ icon: 'error', title: 'Not available', text: 'The PDF tools are not on this device yet — open the report once while online.' }); return; }
    var all = issues();
    if (all.length) { Swal.fire({ icon: 'warning', title: 'Please complete the report first', html: '<ul class="sr-swal-list">' + all.slice(0, 8).map(function (x) { return '<li>' + esc(x.msg) + '</li>'; }).join('') + '</ul>' }); return; }
    imgData(BANNER).then(function (banner) {
      var doc = new window.jspdf.jsPDF({ unit: 'mm', format: 'a4' }), W = 210, M = 12;
      var navy = [15, 43, 91], grey = [100, 116, 139];
      var head = function () {
        var y = M;
        if (banner) { var bw = W - 2 * M, h = bw * banner.h / banner.w; if (h > 42) { h = 42; bw = h * banner.w / banner.h; } doc.addImage(banner.data, 'PNG', (W - bw) / 2, y, bw, h); y += h + 3; } // the letterhead, in proportion
        else { doc.setFont('helvetica', 'bold'); doc.setFontSize(18); doc.setTextColor(0, 163, 223); doc.text('YMR MARINE SOLUTIONS', W / 2, y + 8, { align: 'center' }); y += 14; }
        doc.saveGraphicsState(); doc.setGState(new doc.GState({ opacity: 0.08 })); doc.setFont('helvetica', 'bold'); doc.setFontSize(80); doc.setTextColor(220, 38, 38);
        doc.text('DRAFT', W / 2, 170, { align: 'center', angle: 35 }); doc.restoreGraphicsState();
        return y;
      };
      var foot = function () {
        doc.setFont('helvetica', 'italic'); doc.setFontSize(7.5); doc.setTextColor.apply(doc, grey);
        doc.text('DRAFT made on this device without internet — the final report is made by the YSMS server from the company template.', W / 2, 290, { align: 'center' });
      };
      var vessel = val('vessel_name') || S.report.vessel, grades = chosen();
      // ---- page 1: particulars + tank tables
      var y = head();
      doc.setTextColor.apply(doc, navy); doc.setFont('helvetica', 'bold'); doc.setFontSize(13); doc.text(vessel, W / 2, y + 2, { align: 'center' });
      doc.setFontSize(9.5); if (val('imo')) doc.text('IMO No. ' + val('imo'), W / 2, y + 7, { align: 'center' });
      doc.setFontSize(10.5); doc.text((S.report.kind_label || 'Bunker') .toUpperCase() + ' — BUNKER SURVEY', W / 2, y + 13, { align: 'center' });
      y += 18;
      var dt = function (d, t) { return [d, t ? t + ' Hrs LT' : ''].filter(Boolean).join('  '); };
      var part = [['Owners', val('owners')], ['Charterers', val('charterers')], ['FWE', dt(val('fwe_date'), val('fwe_time'))], ['Survey Started', dt(val('started_date'), val('started_time'))],
        ['Survey Completed', dt(val('completed_date'), val('completed_time'))], ['Place of Survey', [val('place'), val('port_name')].filter(Boolean).join(', ')], ['Voyage No.', val('voyage_no')],
        ['Draft Fwd / Aft', [val('draft_fwd'), val('draft_aft')].filter(Boolean).join(' / ') + ' m'], ['List', val('list') ? val('list') + '°' : '']];
      doc.autoTable({ startY: y, margin: { left: M, right: W / 2 + 2 }, body: part, theme: 'grid', styles: { fontSize: 7.5, cellPadding: 1.2, lineColor: [148, 163, 184], lineWidth: 0.15, textColor: 20 }, columnStyles: { 0: { fontStyle: 'bold', cellWidth: 30 } } });
      var y1 = doc.lastAutoTable.finalY;
      var brob = grades.map(function (g) { return [gradeInfo(g).short, val('brob_fwe_' + g), val('brob_started_' + g)]; });
      doc.autoTable({ startY: y, margin: { left: W / 2 + 6, right: M }, head: [['BROB (MT)', 'At FWE', 'Survey started']], body: brob, theme: 'grid',
        headStyles: { fillColor: navy, textColor: 255, fontStyle: 'bold', fontSize: 7.5, cellPadding: 1.2 }, styles: { fontSize: 7.5, cellPadding: 1.2, halign: 'right', lineColor: [148, 163, 184], lineWidth: 0.15 }, columnStyles: { 0: { halign: 'left', fontStyle: 'bold' } } });
      y = Math.max(y1, doc.lastAutoTable.finalY) + 6;
      grades.forEach(function (g) {
        var rows = S.data.tanks[g] || []; if (!rows.length) return;
        var gt = gradeTotals(g), body = rows.map(function (r) {
          var t = calc(r);
          return [r.name, r.ref_height, fmtT('sounding', sounding(r)), r.volume, fmtT('density', r.density), fmtT('temp', r.temp), t.vcf == null ? '' : t.vcf.toFixed(4), f3(t.gsv), f3(t.mt)];
        });
        body.push([{ content: 'TOTAL', colSpan: 7, styles: { halign: 'right', fontStyle: 'bold' } }, { content: f3(gt.gsv), styles: { fontStyle: 'bold' } }, { content: f3(gt.mt), styles: { fontStyle: 'bold' } }]);
        if (y > 250) { foot(); doc.addPage(); y = head(); }
        doc.setFont('helvetica', 'bold'); doc.setFontSize(9.5); doc.setTextColor.apply(doc, navy); doc.text(gradeInfo(g).name || gradeInfo(g).short, M, y);
        doc.autoTable({ startY: y + 2, margin: { left: M, right: M }, head: [['Tank', 'Ref. Ht (m)', 'Sounding (m)', 'Volume (m³)', 'Density @15°C', 'Temp (°C)', 'V.C.F.', 'G.S.V. (m³)', 'MT in Air']], body: body, theme: 'grid',
          headStyles: { fillColor: [230, 246, 252], textColor: navy, fontSize: 7, halign: 'center', cellPadding: 1.2 }, styles: { fontSize: 7.5, cellPadding: 1.1, halign: 'right', lineColor: [148, 163, 184], lineWidth: 0.15, textColor: 20 },
          columnStyles: { 0: { halign: 'left', cellWidth: 34 } }, didDrawPage: function () {} });
        y = doc.lastAutoTable.finalY + 7;
      });
      foot();
      // ---- page 2: ROB summary + signatures
      doc.addPage(); y = head();
      doc.setFont('helvetica', 'bold'); doc.setFontSize(13); doc.setTextColor.apply(doc, navy); doc.text('ROB SUMMARY', W / 2, y + 2, { align: 'center' }); y += 8;
      var sr = summaryRows(), tot = 0;
      sr.forEach(function (r) { if (r.diff !== null) tot += r.diff; });
      tot = ReportCalc.round(tot, 3);
      doc.autoTable({ startY: y, margin: { left: M, right: M }, head: [['Fuel Grade', 'Survey Completed (MT)', 'Log Book ROB (MT)', 'Difference (MT)']],
        body: sr.map(function (r) { return [r.short, f3(r.completed), f3(r.logbook), sgn(r.diff).replace('−', '-')]; }).concat([[{ content: 'TOTAL DIFFERENCE', colSpan: 3, styles: { halign: 'right', fontStyle: 'bold' } }, { content: sgn(tot).replace('−', '-') + ' MT', styles: { fontStyle: 'bold' } }]]),
        theme: 'grid', headStyles: { fillColor: navy, textColor: 255, fontStyle: 'bold', fontSize: 9 }, styles: { fontSize: 10, cellPadding: 2.2, halign: 'right', lineColor: [148, 163, 184], lineWidth: 0.15, textColor: 20 }, columnStyles: { 0: { halign: 'left', fontStyle: 'bold' } } });
      y = doc.lastAutoTable.finalY + 8;
      doc.setFont('helvetica', 'normal'); doc.setFontSize(8.5); doc.setTextColor(30, 41, 59);
      doc.text(doc.splitTextToSize('All bunker tanks were carefully sounded together with the Chief Engineer / his representative and the quantities calculated from the vessel\'s calibration tables. Survey Completed = sum of MT in Air of the tanks of each grade; Log Book = ROB at survey start.', W - 2 * M), M, y);
      y += 26;
      var sig = function (x, t, n) { doc.setDrawColor(100); doc.line(x, y, x + 70, y); doc.setFont('helvetica', 'bold'); doc.setFontSize(8.5); doc.text(t, x, y + 5); doc.setFont('helvetica', 'normal'); doc.text(n || '', x, y + 10); };
      sig(M, 'The Master', val('master')); sig(W - M - 70, 'Independent Marine Surveyor', val('surveyor_name')); y += 26;
      sig(M, 'The Chief Engineer', val('chief_engineer'));
      foot();
      var name = (vessel + ' - ' + (S.report.kind_label || 'SURVEY') + ' - DRAFT').toUpperCase().replace(/[^A-Z0-9 .\-]+/g, '').trim() + '.pdf';
      doc.save(name);
      store(LS + '_offpdf', { at: Date.now(), version: S.version }); // make the final report as soon as the connection is back
      toast('success', 'Draft PDF saved on this device');
    });
  }
  /** Back online after a draft PDF was made offline: save, then make the final PDF (and the Excel kept in the system). */
  function finalAfterOffline() {
    var f = read(LS + '_offpdf'); if (!f || !online() || !S.canEdit || S.finalBusy) return;
    S.finalBusy = true;
    flush().then(function () {
      if (S.dirty || issues().length) { S.finalBusy = false; return; }
      return post({ a: 'generate', id: P.id, pdf: 1 }).then(function (res) {
        S.finalBusy = false;
        if (!res || !res.ok) return;
        store(LS + '_offpdf', null);
        S.files = res.files || S.files; if (res.version) S.version = res.version;
        var id = res.new_files && res.new_files.pdf;
        Swal.fire({ icon: 'success', title: 'Synced — final report ready', text: 'Your offline changes are saved and the final PDF was made from the company template.',
          confirmButtonText: '<i class="fa-solid fa-file-pdf"></i> Download final PDF', showCancelButton: !!id, cancelButtonText: 'Later', showConfirmButton: !!id })
          .then(function (r) { if (r.isConfirmed && id) { var a = document.createElement('a'); a.href = P.api + '?a=file&id=' + id; a.setAttribute('download', ''); document.body.appendChild(a); a.click(); setTimeout(function () { a.remove(); }, 4000); } });
      });
    }).catch(function () { S.finalBusy = false; });
  }
  window.addEventListener('online', function () { setTimeout(finalAfterOffline, 1500); });

  // ---------- offline support ----------
  if ('serviceWorker' in navigator) navigator.serviceWorker.register('survey_report_sw.js', { scope: './survey_report' }).catch(function () {});
  load();
})();
