/*
 * Image → Bunker Survey Form (survey_form.php). Server: ajax/survey_form_api.php.
 * The tank figures shown while typing are a preview of the Excel format's formulas; the
 * Review step and the Excel file use the format's own formulas on the server.
 */
(function () {
    'use strict';
    var app = document.getElementById('sfApp');
    if (!app) return;
    var DRAFT_ID = app.dataset.draft;
    var S = { draft: null, data: null, version: 0, errors: {}, step: 0, dirty: false, printed: null, busy: false };
    var $ = function (sel, root) { return (root || document).querySelector(sel); };
    var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };
    var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
    var CONFIDENT = 0.8;

    // ------------------------------------------------------------ server
    function api(action, params) {
        var fd = new FormData();
        fd.append('action', action);
        fd.append('draft_id', DRAFT_ID);
        Object.keys(params || {}).forEach(function (k) { fd.append(k, params[k]); });
        return fetch('ajax/survey_form_api.php', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-CSRF-Token': window.SF_CSRF } })
            .then(function (r) { return r.json().catch(function () { return { success: false, message: 'Server error (' + r.status + ').' }; }).then(function (d) { d._status = r.status; return d; }); })
            .catch(function () { return { success: false, message: 'No connection to the server. Your changes are still on this screen — try Save again.' }; });
    }
    function uploadFile(file, onProgress) {
        return new Promise(function (resolve) {
            var fd = new FormData();
            fd.append('action', 'upload_image');
            fd.append('draft_id', DRAFT_ID);
            fd.append('image', file);
            var xhr = new XMLHttpRequest();
            xhr.__ysmsOwnOverlay = true; // this page shows its own progress per file
            xhr.open('POST', 'ajax/survey_form_api.php');
            xhr.setRequestHeader('X-CSRF-Token', window.SF_CSRF);
            xhr.upload.onprogress = function (e) { if (e.lengthComputable) onProgress(e.loaded / e.total); };
            xhr.onload = function () { var d; try { d = JSON.parse(xhr.responseText); } catch (e) { d = { success: false, message: 'Upload failed (' + xhr.status + ').' }; } resolve(d); };
            xhr.onerror = function () { resolve({ success: false, message: 'No connection — the photo was not uploaded.' }); };
            xhr.send(fd);
        });
    }

    // ------------------------------------------------------------ validation (same rules as the server)
    function validate(type, raw) {
        var v = String(raw == null ? '' : raw).trim();
        if (v === '') return '';
        if (type === 'height') return /^-?\d+(\.\d+)?$/.test(v) ? validate('num:3', v) : (v.length > 30 ? 'Too long (30 characters max).' : '');
        if (type === 'text') return v.length > 200 ? 'Too long (200 characters max).' : '';
        if (type === 'int') return /^\d{1,9}$/.test(v.replace(/,/g, '')) ? '' : 'Whole number only.';
        if (type === 'month') return /^(JAN(UARY)?|FEB(RUARY)?|MAR(CH)?|APR(IL)?|MAY|JUNE?|JULY?|AUG(UST)?|SEP(TEMBER)?|OCT(OBER)?|NOV(EMBER)?|DEC(EMBER)?)$/i.test(v) ? '' : 'Enter a month name, e.g. MAY.';
        if (type === 'imo') {
            var n = v.replace(/^IMO\s*(NO\.?)?\s*/i, '');
            if (!/^\d{7}$/.test(n)) return 'IMO number has 7 digits.';
            var sum = 0; for (var i = 0; i < 6; i++) sum += (+n[i]) * (7 - i);
            return sum % 10 === +n[6] ? '' : 'IMO check digit does not match — please re-check the number.';
        }
        if (type === 'date') return /^\d{4}-\d{2}-\d{2}$/.test(v) ? '' : 'Enter a valid date.';
        if (type === 'time') return /^([01]\d|2[0-3]):[0-5]\d$/.test(v) ? '' : 'Enter the time as HH:MM (24 h).';
        if (type.indexOf('num:') === 0) {
            var dec = +type.slice(4), m = /^-?\d+(\.(\d+))?$/.exec(v.replace(/,/g, ''));
            if (!m) return 'Numbers only.';
            if (m[2] && m[2].length > dec) return 'Use at most ' + dec + ' decimal' + (dec === 1 ? '' : 's') + ' (as the Excel format shows).';
        }
        return '';
    }
    function rangeErr(what, v) {
        if (v === '' || isNaN(+v)) return '';
        var n = +v, R = { density: [0.6, 1.1, 'Density @15°C is normally between 0.6000 and 1.1000.'], temp: [-10, 150, 'Temperature looks wrong (−10 to 150 °C).'],
            volume: [0, 100000, 'Volume must be 0 or more.'], draft_aft: [0, 30, 'Draft must be between 0 and 30 m.'], draft_fwd: [0, 30, 'Draft must be between 0 and 30 m.'],
            list: [-45, 45, 'List must be between −45 and 45 degrees.'], brob: [0, 100000, 'BROB must be 0 or more.'] }[what];
        return R && (n < R[0] || n > R[1]) ? R[2] : '';
    }
    function fieldType(key) { return S.draft.layout.fields.filter(function (f) { return f.key === key; })[0].type; }
    function tankType(col) { return S.draft.layout.tank_cols.filter(function (c) { return c.key === col; })[0].type; }
    function checkAll() {
        var e = {};
        S.draft.layout.fields.forEach(function (f) {
            var v = (S.data.fields[f.key] || {}).v || '';
            var m = validate(f.type, v) || rangeErr(f.key.indexOf('brob_') === 0 ? 'brob' : f.key, v);
            if (m) e['f:' + f.key] = m;
        });
        S.draft.layout.sections.forEach(function (sec) {
            (S.data.tanks[sec.key] || []).forEach(function (row, i) {
                S.draft.layout.tank_cols.forEach(function (c) {
                    var v = (row[c.key] || {}).v || '', m = validate(c.type, v) || rangeErr(c.key, v);
                    if (m) e['t:' + sec.key + ':' + i + ':' + c.key] = m;
                });
                if (((row.temp || {}).v || '') !== '') ['volume', 'density'].forEach(function (need) {
                    var k = 't:' + sec.key + ':' + i + ':' + need;
                    if (((row[need] || {}).v || '') === '' && !e[k]) e[k] = 'Needed when a temperature is entered.';
                });
            });
        });
        S.errors = e;
    }

    // ------------------------------------------------------------ preview of the format's tank formulas
    function xr(x, n) { var f = Math.pow(10, n); return Math.sign(x) * Math.round(parseFloat((Math.abs(x) * f).toPrecision(15))) / f; }
    function tankCalc(row) {
        var g = function (k) { return ((row[k] || {}).v || '').trim(); };
        if (g('temp') === '' || g('volume') === '' || g('density') === '' || isNaN(+g('temp')) || isNaN(+g('volume')) || isNaN(+g('density'))) return null;
        var T = +g('temp'), V = +g('volume'), D = +g('density'), K = S.draft.layout.constants;
        var rho = xr(D / 2 * 1000, 0) * 2, a = xr(K[0] / (rho * rho) + K[1] / rho, 8), dt = T - 15;
        var vcf = xr(Math.exp(-a * dt * (1 + 0.8 * a * dt)), 4), gsv = xr(V * vcf, 3), mt = xr(gsv * (D - 0.0011), 3);
        return { vcf: vcf, gsv: gsv, mt: mt };
    }
    var fmt = function (n, d) { return n == null || isNaN(n) ? '—' : Number(n).toLocaleString('en-US', { minimumFractionDigits: d, maximumFractionDigits: d }); };

    // ------------------------------------------------------------ rendering
    function cellOf(id) {
        var p = id.split(':');
        if (p[0] === 'f') return S.data.fields[p[1]] || (S.data.fields[p[1]] = { v: '' });
        var row = S.data.tanks[p[1]][+p[2]];
        return row[p[3]] || (row[p[3]] = { v: '' });
    }
    function inputAttrs(type) {
        if (type === 'date') return 'type="date"';
        if (type === 'time') return 'type="time"';
        if (type === 'int' || type === 'imo') return 'type="text" inputmode="numeric" autocomplete="off"';
        if (type.indexOf('num:') === 0) return 'type="text" inputmode="decimal" autocomplete="off"';
        return 'type="text" autocomplete="off"';
    }
    function fieldHtml(id, label, type, required) {
        var c = cellOf(id), err = S.errors[id] || '';
        var ai = c.src === 'ai', unsure = ai && (c.conf == null || c.conf < CONFIDENT);
        var cls = 'sf-field' + (ai ? (unsure ? ' is-check' : ' is-ai') : '') + (err ? ' is-error' : '') + (required && !c.v ? ' is-missing' : '');
        var tags = ai ? (unsure ? ' <span class="sf-tag sf-tag-check" title="AI confidence ' + Math.round((c.conf || 0) * 100) + '%">Check</span>' : ' <span class="sf-tag sf-tag-ai" title="AI confidence ' + Math.round((c.conf || 0) * 100) + '%">AI</span>') : '';
        var html = '<div class="' + cls + '" data-id="' + esc(id) + '"><label for="sf_' + esc(id) + '">' + esc(label) + tags + '</label>'
            + '<input id="sf_' + esc(id) + '" ' + inputAttrs(type) + ' value="' + esc(c.v) + '" data-input="' + esc(id) + '"' + (S.draft.can_edit ? '' : ' disabled') + '>';
        if (err) html += '<div class="sf-fielderr" data-testid="sf-err">' + esc(err) + '</div>';
        if (c.suggest && S.draft.can_edit) html += '<div class="sf-suggest" data-testid="sf-suggest">AI read <b>' + esc(c.suggest) + '</b>'
            + '<button type="button" data-use="' + esc(id) + '">Use</button><button type="button" data-dismiss="' + esc(id) + '">Keep mine</button></div>';
        return html + '</div>';
    }
    function renderFields() {
        var L = S.draft.layout, html = '';
        Object.keys(L.groups).forEach(function (g) {
            var fs = L.fields.filter(function (f) { return f.group === g; });
            if (!fs.length) return;
            html += '<div class="sf-card"><div class="sf-group-title">' + esc(L.groups[g]) + '</div>';
            fs.forEach(function (f) { html += fieldHtml('f:' + f.key, f.label, f.type, f.required); });
            if (g === 'drafts') html += '<div class="sf-calc" data-testid="sf-trim">Trim (aft − forward): <b data-trim>' + trimText() + '</b> m — calculated by the Excel format</div>';
            html += '</div>';
        });
        $('#sfFields').innerHTML = html;
    }
    function trimText() {
        var a = (S.data.fields.draft_aft || {}).v, f = (S.data.fields.draft_fwd || {}).v;
        return a !== '' && f !== '' && a != null && f != null && !isNaN(+a) && !isNaN(+f) ? fmt(xr(a - f, 2), 2) : '—';
    }
    function renderTanks() {
        var L = S.draft.layout, html = '';
        L.sections.forEach(function (sec) {
            var rows = S.data.tanks[sec.key] || [];
            html += '<div class="sf-card" data-section="' + sec.key + '"><div class="sf-group-title">' + esc(sec.label) + ' tanks (' + rows.length + ')</div>';
            rows.forEach(function (row, i) {
                var calc = tankCalc(row);
                html += '<div class="sf-tank" data-testid="sf-tank-' + sec.key + '"><div class="sf-tank-head"><span>Tank ' + (i + 1) + (row.name && row.name.v ? ' — ' + esc(row.name.v) : '') + '</span>'
                    + (S.draft.can_edit ? '<button type="button" class="sf-tank-del" data-del="' + sec.key + ':' + i + '" aria-label="Remove tank ' + (i + 1) + '"><i class="fa-solid fa-trash"></i></button>' : '') + '</div>';
                L.tank_cols.forEach(function (c) { html += fieldHtml('t:' + sec.key + ':' + i + ':' + c.key, c.label, c.type, false); });
                html += '<div class="sf-calc" data-calc="' + sec.key + ':' + i + '">' + calcText(calc) + '</div></div>';
            });
            if (rows.length > sec.template_rows) html += '<div class="sf-muted">The Excel format has ' + sec.template_rows + ' rows here — ' + (rows.length - sec.template_rows) + ' more will be inserted in the file.</div>';
            html += '<div class="sf-sec-total" data-total="' + sec.key + '">' + totalText(sec.key) + '</div>';
            if (S.draft.can_edit) html += '<button type="button" class="sf-btn sf-btn-block" data-add="' + sec.key + '" data-testid="sf-add-' + sec.key + '"><i class="fa-solid fa-plus"></i> Add ' + esc(sec.label) + ' tank</button>';
            html += '</div>';
        });
        $('#sfTanks').innerHTML = html;
    }
    function calcText(c) { return c ? 'V.C.F. ' + fmt(c.vcf, 4) + ' · G.S.V. ' + fmt(c.gsv, 3) + ' m³ · <b>' + fmt(c.mt, 3) + ' MT</b> <span class="sf-muted">(preview)</span>' : '<span class="sf-muted">Enter volume, density and temperature to see V.C.F. and metric tons.</span>'; }
    function totalText(sec) {
        var t = 0, any = false;
        (S.data.tanks[sec] || []).forEach(function (r) { var c = tankCalc(r); if (c) { t += c.mt; any = true; } });
        return 'Total (preview): ' + (any ? fmt(xr(t, 3), 3) + ' MT' : '—');
    }
    function renderThumbs() {
        var imgs = S.draft.images || [];
        $('#sfThumbs').innerHTML = imgs.map(function (i) {
            var isImg = /^image\/(jpeg|png|webp|gif)$/.test(i.mime);
            return '<a class="sf-thumb" href="' + esc(i.url) + '" target="_blank" rel="noopener" title="' + esc(i.name) + '">'
                + (isImg ? '<img src="' + esc(i.url) + '" alt="' + esc(i.name) + '" loading="lazy">' : '<span><i class="fa-solid ' + (i.mime === 'application/pdf' ? 'fa-file-pdf' : 'fa-file-image') + ' fa-2x"></i><br>' + esc(i.name) + '</span>') + '</a>';
        }).join('') || '<div class="sf-muted">No photos yet.</div>';
        var ex = $('#sfExtract');
        if (ex) ex.disabled = !imgs.length;
    }
    function renderAll() { checkAll(); renderThumbs(); renderFields(); renderTanks(); if (S.step === 3) renderReview(); }

    // ------------------------------------------------------------ steps & bar
    function showStep(n) {
        S.step = n;
        $$('.sf-step').forEach(function (b) { b.classList.toggle('active', +b.dataset.step === n); b.setAttribute('aria-selected', +b.dataset.step === n ? 'true' : 'false'); });
        $$('.sf-panel').forEach(function (p) { p.classList.toggle('active', +p.dataset.panel === n); });
        $('#sfBack').disabled = n === 0;
        $('#sfNext').innerHTML = n === 3 ? '<i class="fa-solid fa-file-excel"></i> Make Excel' : 'Next <i class="fa-solid fa-chevron-right"></i>';
        window.scrollTo(0, 0);
        var sc = $('.scroll-content'); if (sc) sc.scrollTop = 0;
        if (n === 3) renderReview();
    }
    function setState(t, err) { var el = $('#sfSaveState'); el.textContent = t; el.style.color = err ? '#b91c1c' : ''; }

    // One save at a time: a second click while a save is running waits for it (two saves
    // sent with the same version would make the second look like a conflict).
    function save() {
        if (S.saving) return S.saving.then(function () { return save(); });
        S.saving = saveNow().then(function (ok) { S.saving = null; return ok; }, function () { S.saving = null; return false; });
        return S.saving;
    }
    function saveNow() {
        if (!S.draft.can_edit) return Promise.resolve(true);
        if (!S.dirty) return Promise.resolve(true);
        setState('Saving…');
        var sentData = JSON.stringify(S.data), sentAt = S.changeCount;
        return api('save', { version: S.version, data: sentData }).then(function (d) {
            if (d.success) {
                S.version = d.version;
                if (S.changeCount === sentAt) { S.dirty = false; }
                setState('Saved ' + new Date().toTimeString().slice(0, 5) + (Object.keys(d.errors || {}).length ? ' · ' + Object.keys(d.errors).length + ' value(s) to fix' : ''));
                return true;
            }
            if (d.conflict) { conflict(d); return false; }
            setState(d.message || 'Not saved.', true);
            return false;
        });
    }
    function conflict(d) {
        setState('Not saved — changed on another screen.', true);
        if (d.draft && window.confirm(d.message + '\n\nLoad the latest saved version? Your unsaved changes on this screen will be lost.')) {
            load(d.draft);
        }
    }
    function load(draft) {
        S.draft = draft; S.data = draft.data; S.version = draft.version; S.dirty = false; S.changeCount = 0;
        if (!draft.can_edit) { $('#sfReadonly').hidden = false; $$('[data-edit-only]').forEach(function (e) { e.hidden = true; }); $('#sfSave').hidden = true; }
        renderAll();
        setState('Saved ' + draft.updated_at.slice(11, 16) + (draft.updated_by ? ' by ' + draft.updated_by : ''));
    }

    // ------------------------------------------------------------ review
    function jumpLink(id, text) { return '<a href="#" data-jump="' + esc(id) + '">' + esc(text) + '</a>'; }
    function labelOf(id) {
        var p = id.split(':');
        if (p[0] === 'f') return S.draft.layout.fields.filter(function (f) { return f.key === p[1]; })[0].label;
        var sec = S.draft.layout.sections.filter(function (s) { return s.key === p[1]; })[0];
        var col = S.draft.layout.tank_cols.filter(function (c) { return c.key === p[3]; })[0];
        return sec.label + ' tank ' + (+p[2] + 1) + ' — ' + col.label;
    }
    function renderReview() {
        checkAll();
        var box = $('#sfReview'), errs = Object.keys(S.errors), missing = [], unsure = [], sugg = [];
        S.draft.layout.fields.forEach(function (f) {
            var c = S.data.fields[f.key] || {};
            if (f.required && !c.v) missing.push('f:' + f.key);
            if (c.src === 'ai' && (c.conf == null || c.conf < CONFIDENT)) unsure.push('f:' + f.key);
            if (c.suggest) sugg.push('f:' + f.key);
        });
        var tankGroups = [];
        S.draft.layout.sections.forEach(function (sec) { (S.data.tanks[sec.key] || []).forEach(function (row, i) {
            var u = [], g = [];
            S.draft.layout.tank_cols.forEach(function (col) {
                var c = row[col.key] || {}, id = 't:' + sec.key + ':' + i + ':' + col.key;
                if (c.src === 'ai' && (c.conf == null || c.conf < CONFIDENT)) u.push(id);
                if (c.suggest) g.push(id);
            });
            if (u.length || g.length) tankGroups.push({ label: sec.label + ' tank ' + (i + 1) + (row.name && row.name.v ? ' (' + row.name.v + ')' : ''), unsure: u, sugg: g });
        }); });
        var html = '<div class="sf-card"><h2 class="sf-h2">Check before making the Excel file</h2>';
        if (!errs.length && !missing.length && !unsure.length && !sugg.length && !tankGroups.length) html += '<div class="sf-muted" data-testid="sf-all-clear"><i class="fa-solid fa-circle-check" style="color:#16a34a"></i> No problems found.</div>';
        errs.forEach(function (id) { html += '<div class="sf-issue" data-testid="sf-issue-error"><i class="fa-solid fa-circle-xmark" style="color:#dc2626"></i><span>' + jumpLink(id, labelOf(id)) + ': ' + esc(S.errors[id]) + '</span></div>'; });
        missing.forEach(function (id) { html += '<div class="sf-issue" data-testid="sf-issue-missing"><i class="fa-solid fa-circle-exclamation" style="color:#dc2626"></i><span>' + jumpLink(id, labelOf(id)) + ' is missing</span></div>'; });
        unsure.forEach(function (id) { html += '<div class="sf-issue" data-testid="sf-issue-unsure"><i class="fa-solid fa-triangle-exclamation" style="color:#d97706"></i><span>' + jumpLink(id, labelOf(id)) + ': AI was not sure — check against the photo</span></div>'; });
        sugg.forEach(function (id) { html += '<div class="sf-issue" data-testid="sf-issue-suggest"><i class="fa-solid fa-code-compare" style="color:#7c3aed"></i><span>' + jumpLink(id, labelOf(id)) + ': AI read a different value</span></div>'; });
        tankGroups.forEach(function (t) {
            var parts = [];
            if (t.unsure.length) parts.push('AI was not sure about ' + t.unsure.length + ' value' + (t.unsure.length === 1 ? '' : 's'));
            if (t.sugg.length) parts.push('AI read ' + t.sugg.length + ' different value' + (t.sugg.length === 1 ? '' : 's'));
            html += '<div class="sf-issue" data-testid="sf-issue-unsure"><i class="fa-solid fa-triangle-exclamation" style="color:#d97706"></i><span>' + jumpLink(t.unsure[0] || t.sugg[0], t.label) + ': ' + parts.join('; ') + ' — check against the photo</span></div>';
        });
        html += '</div><div class="sf-card"><h2 class="sf-h2">Results (calculated by the Excel format)</h2><div id="sfResults" data-testid="sf-results">' + (errs.length ? '<div class="sf-muted">Fix the values above to see the results.</div>' : '<div class="sf-muted">Calculating…</div>') + '</div></div>';
        html += '<div class="sf-card"><h2 class="sf-h2">Excel files</h2><p class="sf-muted">Fills the approved ' + esc(S.draft.format_label) + ' format. This does not complete the survey — upload the files on the vessel page as usual when ready.</p><div id="sfGenerated" data-testid="sf-generated">' + generatedHtml() + '</div></div>';
        box.innerHTML = html;
        $('#sfNext').disabled = !S.draft.can_edit || errs.length > 0;
        if (errs.length) return;
        var p = S.draft.can_edit ? save() : Promise.resolve(true);
        p.then(function () { return api('calculate', { data: JSON.stringify(S.data) }); }).then(function (d) {
            var el = $('#sfResults');
            if (!el) return;
            if (!d.success || !d.results) { el.innerHTML = '<div class="sf-err">' + esc(d.message || 'Could not calculate — fix the values above.') + '</div>'; return; }
            el.innerHTML = resultsHtml(d.results);
        });
    }
    function resultsHtml(r) {
        var L = S.draft.layout, html = '<div class="sf-results">';
        var line = function (k, v) { return '<div class="sf-res-row"><span>' + k + '</span><span>' + v + '</span></div>'; };
        L.sections.forEach(function (sec) {
            var t = r.totals[sec.key] || {}, res = r.results[sec.key] || {};
            html += '<div class="sf-res" data-testid="sf-res-' + sec.key + '"><div class="sf-res-head"><span>' + esc(sec.label) + '</span><span>' + fmt(t.mt, 3) + ' MT</span></div>'
                + line('Tanks', (S.data.tanks[sec.key] || []).length) + line('G.S.V. @ 15°C', fmt(t.gsv, 3) + ' m³') + line('Log book BROB', fmt(res.log, 3) + ' MT') + line('Difference', '<b>' + fmt(res.difference, 3) + ' MT</b>') + '</div>';
        });
        html += '</div><div class="sf-calc" style="margin-top:8px">Total difference: <b data-testid="sf-res-total">' + fmt(r.difference_total, 3) + ' MT</b> · Trim: <b>' + fmt(r.trim, 2) + ' m</b></div>';
        if (S.printed) L.sections.forEach(function (sec) {
            var p = S.printed[sec.key], mt = (r.totals[sec.key] || {}).mt;
            if (p != null && mt != null && Math.abs(p - mt) > 0.0005) html += '<div class="sf-note err" data-testid="sf-printed-mismatch">' + esc(sec.label) + ': the photo shows a total of ' + fmt(p, 3) + ' MT but the format calculates ' + fmt(mt, 3) + ' MT — check the tank values.</div>';
        });
        return html;
    }
    function generatedHtml() {
        var g = S.draft.generated || [];
        return g.length ? g.slice().reverse().map(function (f) { return '<a class="sf-draft" href="' + esc(f.url) + '" data-testid="sf-generated-link"><span><i class="fa-solid fa-file-excel" style="color:#16a34a"></i> ' + esc(f.name) + '<br><span class="sf-muted">' + esc(f.at) + (f.by ? ' · ' + esc(f.by) : '') + '</span></span><i class="fa-solid fa-download"></i></a>'; }).join('') : '<div class="sf-muted">None made yet.</div>';
    }
    function generate() {
        var btn = $('#sfNext');
        btn.disabled = true;
        setState('Making the Excel file…');
        save().then(function (ok) {
            if (!ok) { btn.disabled = false; return; }
            return api('generate', { version: S.version }).then(function (d) {
                btn.disabled = false;
                if (!d.success) { setState(d.message || 'Not made.', true); if (d.conflict) api('get').then(function (g) { if (g.success) conflict({ message: d.message, draft: g.draft }); }); return; }
                return api('get').then(function (g) {
                    if (g.success) S.draft.generated = g.draft.generated;
                    $('#sfGenerated').innerHTML = generatedHtml();
                    setState('Excel file ready.');
                    var a = document.createElement('a'); a.href = d.url; a.download = d.name; document.body.appendChild(a); a.click(); a.remove();
                });
            });
        });
    }

    // ------------------------------------------------------------ events
    document.addEventListener('input', function (e) {
        var id = e.target.dataset && e.target.dataset.input;
        if (!id) return;
        var c = cellOf(id);
        c.v = e.target.value;
        if (c.src === 'ai') { c.src = 'edited'; delete c.conf; } else if (!c.src) c.src = 'manual';
        S.dirty = true; S.changeCount = (S.changeCount || 0) + 1;
        setState('Unsaved changes');
        var wrap = e.target.closest('.sf-field');
        wrap.classList.remove('is-ai', 'is-check');
        $$('.sf-tag', wrap).forEach(function (t) { t.remove(); });
        var p = id.split(':');
        if (p[0] === 't') {
            var row = S.data.tanks[p[1]][+p[2]], cEl = $('[data-calc="' + p[1] + ':' + p[2] + '"]');
            if (cEl) cEl.innerHTML = calcText(tankCalc(row));
            var tEl = $('[data-total="' + p[1] + '"]'); if (tEl) tEl.textContent = totalText(p[1]);
        }
        if (id === 'f:draft_aft' || id === 'f:draft_fwd') { var tr = $('[data-trim]'); if (tr) tr.textContent = trimText(); }
    });
    document.addEventListener('change', function (e) {
        var id = e.target.dataset && e.target.dataset.input;
        if (!id) return;
        checkAll();
        var wrap = e.target.closest('.sf-field'), err = S.errors[id] || '', box = $('.sf-fielderr', wrap);
        wrap.classList.toggle('is-error', !!err);
        wrap.classList.toggle('is-missing', wrap.classList.contains('is-missing') && !e.target.value);
        if (err && !box) { box = document.createElement('div'); box.className = 'sf-fielderr'; box.setAttribute('data-testid', 'sf-err'); e.target.insertAdjacentElement('afterend', box); }
        if (box) { if (err) box.textContent = err; else box.remove(); }
    });
    document.addEventListener('click', function (e) {
        var t = e.target.closest('[data-step],[data-use],[data-dismiss],[data-add],[data-del],[data-jump]');
        if (!t || !app.parentNode.contains(t) && !document.body.contains(t)) return;
        if (t.dataset.step != null && t.classList.contains('sf-step')) { save(); showStep(+t.dataset.step); return; }
        if (t.dataset.use) { var c = cellOf(t.dataset.use); c.v = c.suggest; c.src = 'edited'; delete c.conf; delete c.suggest; S.dirty = true; S.changeCount = (S.changeCount || 0) + 1; setState('Unsaved changes'); renderAll(); return; }
        if (t.dataset.dismiss) { var d = cellOf(t.dataset.dismiss); delete d.suggest; S.dirty = true; S.changeCount = (S.changeCount || 0) + 1; setState('Unsaved changes'); renderAll(); return; }
        if (t.dataset.add) {
            var blank = {}; S.draft.layout.tank_cols.forEach(function (c) { blank[c.key] = { v: '' }; });
            S.data.tanks[t.dataset.add].push(blank); S.dirty = true; S.changeCount = (S.changeCount || 0) + 1; setState('Unsaved changes');
            renderTanks();
            var rows = $$('[data-section="' + t.dataset.add + '"] .sf-tank'); var last = rows[rows.length - 1];
            if (last) { last.scrollIntoView({ block: 'center' }); var inp = $('input', last); if (inp) inp.focus(); }
            return;
        }
        if (t.dataset.del) {
            var q = t.dataset.del.split(':');
            if (!window.confirm('Remove tank ' + (+q[1] + 1) + ' from ' + q[0].toUpperCase() + '?')) return;
            S.data.tanks[q[0]].splice(+q[1], 1); S.dirty = true; S.changeCount = (S.changeCount || 0) + 1; setState('Unsaved changes'); renderAll(); return;
        }
        if (t.dataset.jump) {
            e.preventDefault();
            var id = t.dataset.jump;
            showStep(id[0] === 'f' ? 1 : 2);
            var inp2 = document.getElementById('sf_' + id);
            if (inp2) { inp2.scrollIntoView({ block: 'center' }); inp2.focus(); }
        }
    });
    $('#sfBack').addEventListener('click', function () { if (S.step > 0) { save(); showStep(S.step - 1); } });
    $('#sfNext').addEventListener('click', function () {
        if (S.step < 3) { save(); showStep(S.step + 1); return; }
        generate();
    });
    $('#sfSave').addEventListener('click', function () { if (!S.dirty) { setState('Nothing new to save.'); return; } save(); });
    window.addEventListener('beforeunload', function (e) { if (S.dirty && S.draft && S.draft.can_edit) { e.preventDefault(); e.returnValue = ''; } });

    // photos
    $$('[data-sf-files]').forEach(function (inp) {
        inp.addEventListener('change', function () {
            var files = Array.prototype.slice.call(inp.files || []);
            inp.value = '';
            var box = $('#sfUploads');
            files.reduce(function (p, file) {
                return p.then(function () {
                    var item = document.createElement('div');
                    item.className = 'sf-upitem';
                    item.innerHTML = '<div>' + esc(file.name) + ' — <span data-pct>0%</span></div><div class="sf-uptrack"><div class="sf-upfill"></div></div>';
                    box.appendChild(item);
                    return uploadFile(file, function (f) { $('[data-pct]', item).textContent = Math.floor(f * 100) + '%'; $('.sf-upfill', item).style.width = Math.floor(f * 100) + '%'; })
                        .then(function (d) {
                            if (d.success) { S.draft.images = d.images; renderThumbs(); item.remove(); }
                            else { item.innerHTML = '<div class="sf-err">' + esc(file.name) + ': ' + esc(d.message || 'not uploaded') + '</div>'; }
                        });
                });
            }, Promise.resolve());
        });
    });
    // AI reading
    var exBtn = $('#sfExtract');
    if (exBtn) exBtn.addEventListener('click', function () {
        var msg = $('#sfExtractMsg');
        exBtn.disabled = true;
        exBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Reading photos…';
        save().then(function (ok) {
            if (!ok) return null;
            return api('extract', { version: S.version });
        }).then(function (d) {
            exBtn.disabled = false;
            exBtn.innerHTML = '<i class="fa-solid fa-wand-magic-sparkles"></i> Read photos with AI';
            if (!d) return;
            msg.hidden = false;
            if (!d.success) { msg.className = 'sf-note err'; msg.textContent = d.message || 'The AI could not read the photos.'; if (d.conflict) api('get').then(function (g) { if (g.success) conflict({ message: d.message, draft: g.draft }); }); return; }
            S.data = d.data; S.version = d.version; S.printed = d.printed_totals || null; S.dirty = false;
            renderAll();
            var st = d.stats || {};
            msg.className = 'sf-note';
            msg.innerHTML = 'AI filled <b>' + (st.filled || 0) + '</b> empty value' + (st.filled === 1 ? '' : 's') + (st.rows ? ' and added <b>' + st.rows + '</b> tank row' + (st.rows === 1 ? '' : 's') : '')
                + (st.suggested ? '; <b>' + st.suggested + '</b> different reading' + (st.suggested === 1 ? '' : 's') + ' shown as suggestions' : '') + '. Nothing you entered was replaced.'
                + (d.images_used < d.images_total ? '<br>Only ' + d.images_used + ' of ' + d.images_total + ' photos could be sent (size limit) — the newest first.' : '')
                + (d.notes ? '<br><span class="sf-muted">AI note: ' + esc(d.notes) + '</span>' : '')
                + '<br><b>Check every highlighted value against the photos.</b> <a href="#" data-goto-details>Check details →</a>';
            setState('Saved ' + new Date().toTimeString().slice(0, 5));
        });
    });
    document.addEventListener('click', function (e) { if (e.target.closest('[data-goto-details]')) { e.preventDefault(); showStep(1); } });

    api('get').then(function (d) {
        if (!d.success) { $('#sfPanels').innerHTML = '<div class="sf-card sf-err">' + esc(d.message || 'Could not open the form.') + '</div>'; return; }
        load(d.draft);
        showStep(0);
    });
})();
