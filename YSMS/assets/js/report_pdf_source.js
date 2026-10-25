/*
 * Report Generator — "Upload PDF" input source (server: ajax/report_pdf.php).
 * Fills the same sectionState[sid].formValues the Excel source fills, so the existing
 * form, Missing/Filled lists and Word generation are used unchanged. Per field it keeps
 * where a value came from (file + page) and its status: ok / review / conflict / calc.
 * Values typed by the user (or loaded from Excel) are never replaced without asking.
 */
(function () {
    'use strict';
    var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
    var FUELS = { vlsfo: 'VLSFO', hsfo: 'HSFO', lsmgo: 'LSMGO' };
    var PROFILE_FIELDS = { SURVEYOR_NAME: 1, SURVEYOR_SIGN: 1 }; // filled from the user's profile
    var MAX_MB = 14;
    var P = {}; // per section: { source, files: [], meta: {}, tankNote }
    function st(sid) { return P[sid] || (P[sid] = { source: 'excel', files: [], meta: {}, tankNote: '' }); }
    var seq = 0;

    var css = '.rg-src{display:grid;grid-template-columns:1fr 1fr;gap:6px;margin:12px 15px 0;background:#f1f5f9;border-radius:14px;padding:4px}'
        + '.rg-src button{min-height:46px;border:0;border-radius:11px;background:transparent;font-weight:700;font-size:13.5px;color:#475569;display:flex;align-items:center;justify-content:center;gap:6px}'
        + '.rg-src button.on{background:#fff;color:#0f172a;box-shadow:0 2px 8px rgba(15,23,42,.08)}'
        + '[data-source=excel] .rg-pdf-zone{display:none}[data-source=pdf] [id^=excelZone_]{display:none}'
        + '.rg-pdf-drop{display:block;border:2px dashed #cbd5e1;border-radius:14px;padding:16px;text-align:center;cursor:pointer;background:#fff;min-height:90px}'
        + '.rg-pdf-drop.drag{border-color:#2563eb;background:#eff6ff}'
        + '.rg-pdf-file{display:flex;flex-wrap:wrap;align-items:center;gap:6px 10px;text-align:left;border:1px solid #e2e8f0;border-radius:12px;padding:8px 10px;margin-top:8px;font-size:12.5px;background:#fff}'
        + '.rg-pdf-file .nm{font-weight:700;color:#0f172a;flex:1 1 160px;min-width:0;overflow-wrap:anywhere}'
        + '.rg-pdf-file .stt{flex:1 1 100%;color:#475569}.rg-pdf-file .stt.err{color:#b91c1c;font-weight:600}.rg-pdf-file .stt.ok{color:#166534}'
        + '.rg-pdf-file button,.rg-pdf-file a{min-height:36px;padding:4px 12px;border-radius:9px;border:1px solid #cbd5e1;background:#fff;font-weight:700;font-size:12px;color:#1e3a8a;text-decoration:none;display:inline-flex;align-items:center}'
        + '.rg-pdf-bar{height:6px;border-radius:999px;background:#e2e8f0;overflow:hidden;flex:1 1 100%}.rg-pdf-bar>div{height:100%;background:linear-gradient(90deg,#2563eb,#5b2dee);width:0;transition:width .2s}'
        + '.rg-pdf-read{width:100%;min-height:46px;margin-top:10px;border:0;border-radius:12px;background:#1e3a8a;color:#fff;font-weight:800}'
        + '.rg-pdf-read:disabled{opacity:.5}.rg-pdf-note{font-size:12px;margin-top:8px;text-align:left;color:#475569}'
        + '.rg-b{display:block;font-size:11.5px;line-height:1.35;margin-top:4px;border-radius:8px;padding:4px 7px}'
        + '.rg-b-ok{background:#ecfdf5;color:#166534}.rg-b-review,.rg-b-calc{background:#fffbeb;color:#92400e}.rg-b-conflict{background:#fef2f2;color:#991b1b}'
        + '.rg-b-edit{background:#f1f5f9;color:#475569}.rg-b-none{background:#f8fafc;color:#64748b}'
        + '.rg-xxx-row{display:flex;align-items:center;flex-wrap:wrap;gap:8px;margin:12px 15px 0;font-size:12px;color:#475569}'
        + '.rg-xxx-btn{min-height:42px;padding:6px 16px;border-radius:10px;border:1.5px solid #f59e0b;background:#fffbeb;color:#92400e;font-weight:800}'
        + '.rg-b button{margin:4px 6px 0 0;min-height:32px;padding:2px 10px;border-radius:8px;border:1px solid currentColor;background:#fff;color:inherit;font-weight:700;font-size:11.5px}';
    var s = document.createElement('style'); s.textContent = css; document.head.appendChild(s);

    // ------------------------------------------------------------ markup used by buildSectionBlock()
    window.rgSourceHtml = function (sid) {
        return '<div class="rg-src" role="tablist" aria-label="Input source" data-testid="rg-source-' + sid + '">'
            + '<button type="button" class="on" data-rg-src="excel" data-sid="' + sid + '" data-testid="rg-source-excel-' + sid + '">📊 Excel</button>'
            + '<button type="button" data-rg-src="pdf" data-sid="' + sid + '" data-testid="rg-source-pdf-' + sid + '">📑 PDF</button></div>';
    };
    window.rgPdfZoneHtml = function (sid) {
        return '<div class="upload-zone-pro rg-pdf-zone" id="pdfZone_' + sid + '" data-testid="rg-pdf-zone-' + sid + '">'
            + '<div class="fw-bold text-dark" style="font-size:13px;">PDF documents <span class="badge bg-warning text-dark" style="font-size:9px;">ONE OR MORE</span></div>'
            + '<label class="rg-pdf-drop" data-rg-drop="' + sid + '"><div style="font-size:24px;">📑</div>'
            + '<div style="font-size:12.5px;font-weight:700;color:#1e3a8a">Tap to choose PDFs</div>'
            + '<div class="text-muted" style="font-size:11px">Ship particulars, BDN, sounding sheets, survey reports — text or scanned, up to ' + MAX_MB + ' MB each</div>'
            + '<input type="file" accept="application/pdf,.pdf" multiple hidden data-rg-files="' + sid + '" data-testid="rg-pdf-input-' + sid + '"></label>'
            + '<div data-rg-list="' + sid + '"></div>'
            + '<button type="button" class="rg-pdf-read" data-rg-read="' + sid + '" data-testid="rg-pdf-read-' + sid + '" disabled>Read PDFs</button>'
            + '<div class="rg-pdf-note" data-rg-note="' + sid + '">Values are filled into the form below for you to check. Nothing you typed is replaced without asking.</div></div>';
    };

    function setSource(sid, src) {
        st(sid).source = src;
        var w = document.getElementById('sectionBlock_' + sid);
        if (!w) return;
        w.setAttribute('data-source', src);
        w.querySelectorAll('[data-rg-src]').forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-rg-src') === src); b.setAttribute('aria-selected', b.getAttribute('data-rg-src') === src ? 'true' : 'false'); });
        renderGroupedGrids(sid);
    }

    // ------------------------------------------------------------ file list
    function addFiles(sid, list) {
        var S = st(sid);
        Array.prototype.forEach.call(list, function (f) {
            var item = { id: ++seq, file: f, status: 'queued', pct: 0, doc: null, error: '', url: URL.createObjectURL(f) };
            if (!/\.pdf$/i.test(f.name) && f.type !== 'application/pdf') { item.status = 'error'; item.error = 'Not a PDF file.'; item.noRetry = true; }
            else if (f.size > MAX_MB * 1048576) { item.status = 'error'; item.error = 'Larger than ' + MAX_MB + ' MB — split it and upload the parts.'; item.noRetry = true; }
            S.files.push(item);
        });
        renderList(sid);
    }
    function statusText(it) {
        if (it.status === 'queued') return 'Ready to read';
        if (it.status === 'uploading') return 'Uploading… ' + it.pct + '%';
        if (it.status === 'reading') return 'Reading the PDF…';
        if (it.status === 'error') return it.error;
        var d = it.doc, n = Object.keys(d.fields).length, t = 0;
        Object.keys(d.tanks).forEach(function (k) { t += d.tanks[k].length; });
        return '✓ ' + (d.document_type ? d.document_type + ' · ' : '') + d.pages + ' page' + (d.pages === 1 ? '' : 's') + ' · '
            + (d.kind === 'scanned' ? 'scanned (read by OCR — check values)' : d.kind === 'mixed' ? 'text + scanned pages' : 'text PDF') + ' · '
            + n + ' value' + (n === 1 ? '' : 's') + (t ? ', ' + t + ' tank reading' + (t === 1 ? '' : 's') : '') + (d.notes ? ' — ' + d.notes : '');
    }
    function renderList(sid) {
        var S = st(sid), box = document.querySelector('[data-rg-list="' + sid + '"]');
        if (!box) return;
        box.innerHTML = S.files.map(function (it) {
            return '<div class="rg-pdf-file" data-testid="rg-pdf-file"><span class="nm">📄 ' + esc(it.file.name) + ' <span class="text-muted" style="font-weight:500">(' + (it.file.size / 1048576).toFixed(1) + ' MB)</span></span>'
                + '<a href="' + it.url + '" target="_blank" rel="noopener">Preview</a>'
                + (it.status === 'error' && !it.noRetry ? '<button type="button" data-rg-retry="' + sid + ':' + it.id + '" data-testid="rg-pdf-retry">Retry</button>' : '')
                + (it.status !== 'uploading' && it.status !== 'reading' ? '<button type="button" data-rg-remove="' + sid + ':' + it.id + '" aria-label="Remove ' + esc(it.file.name) + '">Remove</button>' : '')
                + (it.status === 'uploading' ? '<div class="rg-pdf-bar"><div style="width:' + it.pct + '%"></div></div>' : '')
                + '<span class="stt ' + (it.status === 'error' ? 'err' : it.status === 'done' ? 'ok' : '') + '" data-testid="rg-pdf-status">' + esc(statusText(it)) + '</span></div>';
        }).join('');
        var btn = document.querySelector('[data-rg-read="' + sid + '"]');
        var todo = S.files.filter(function (f) { return f.status === 'queued' || (f.status === 'error' && !f.noRetry); }).length;
        var busy = S.files.some(function (f) { return f.status === 'uploading' || f.status === 'reading'; });
        if (btn) { btn.disabled = busy || !todo; btn.textContent = busy ? 'Reading…' : 'Read ' + (todo || '') + ' PDF' + (todo === 1 ? '' : 's'); }
    }
    function readOne(sid, it) {
        return new Promise(function (resolve) {
            var fields = REPORT_FIELDS.filter(function (f) { return f.type !== 'calc' && !f.readonly && !PROFILE_FIELDS[f.id]; }).map(function (f) {
                var label = (!isOffhireSection(sid) && ONHIRE_LABEL_OVERRIDES[f.id]) ? ONHIRE_LABEL_OVERRIDES[f.id] : f.label;
                return { id: f.id, label: label, type: f.type, options: f.options || [], precision: f.precision };
            }).concat((SECTION_EXTRA_FIELDS[sid] || []).map(function (f) { return { id: f.id, label: f.label, type: 'text' }; }));
            var fd = new FormData();
            fd.append('action', 'extract'); fd.append('section', sid); fd.append('fields', JSON.stringify(fields)); fd.append('file', it.file);
            var xhr = new XMLHttpRequest();
            xhr.__ysmsOwnOverlay = true; // progress shown on the file row
            xhr.open('POST', 'ajax/report_pdf.php');
            xhr.setRequestHeader('X-CSRF-Token', window.RG_CSRF || '');
            it.status = 'uploading'; it.pct = 0; it.error = ''; renderList(sid);
            xhr.upload.onprogress = function (e) { if (e.lengthComputable) { it.pct = Math.floor(e.loaded / e.total * 100); renderList(sid); } };
            xhr.upload.onload = function () { it.status = 'reading'; renderList(sid); };
            xhr.onload = function () {
                var d = null; try { d = JSON.parse(xhr.responseText); } catch (e) {}
                if (d && d.success) { it.status = 'done'; it.doc = d.doc; }
                else { it.status = 'error'; it.error = (d && d.message) || 'The server could not read this PDF (' + xhr.status + ').'; it.noRetry = d && d.retry === false; }
                renderList(sid); resolve();
            };
            xhr.onerror = function () { it.status = 'error'; it.error = 'No connection — nothing was lost, press Retry.'; renderList(sid); resolve(); };
            xhr.send(fd);
        });
    }
    function readAll(sid) {
        var S = st(sid);
        var todo = S.files.filter(function (f) { return f.status === 'queued' || (f.status === 'error' && !f.noRetry); });
        todo.reduce(function (p, it) { return p.then(function () { return readOne(sid, it); }); }, Promise.resolve()).then(function () { return merge(sid); });
    }

    // ------------------------------------------------------------ merging
    var normNum = function (v) { var n = String(v).replace(/,/g, '').trim(); return /^-?\d+(\.\d+)?$/.test(n) ? String(+n) : null; };
    var normKey = function (v) { var n = normNum(v); return n !== null ? 'n:' + n : 't:' + String(v).toLowerCase().replace(/[^a-z0-9]+/g, ''); };
    function srcLabel(c) { return c.calc ? c.calc : esc(c.file) + (c.page ? ' p.' + c.page : ''); }

    function mergeTanks(sid) {
        var S = st(sid), out = {}, clash = [];
        Object.keys(FUELS).forEach(function (fu) {
            out[fu] = []; var byName = {};
            S.files.forEach(function (it) {
                if (it.status !== 'done') return;
                it.doc.tanks[fu].forEach(function (row) {
                    var vals = {}; Object.keys(row).forEach(function (c) { vals[c] = row[c].v; });
                    var key = String(vals.name || '').toUpperCase().replace(/\s+/g, ' ').trim();
                    if (key && byName[key]) {
                        if (JSON.stringify(byName[key]) !== JSON.stringify(vals)) clash.push(FUELS[fu] + ' ' + key);
                        return;
                    }
                    if (key) byName[key] = vals;
                    out[fu].push(vals);
                });
            });
        });
        return { tanks: out, clash: clash };
    }

    function merge(sid) {
        var S = st(sid), done = S.files.filter(function (f) { return f.status === 'done'; });
        if (!done.length) return Promise.resolve();
        var mt = mergeTanks(sid), anyTanks = Object.keys(mt.tanks).some(function (k) { return mt.tanks[k].length; });
        var calcP = anyTanks ? fetch('ajax/report_pdf.php', { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': window.RG_CSRF || '' },
            body: (function () { var fd = new FormData(); fd.append('action', 'calc'); fd.append('section', sid); fd.append('tanks', JSON.stringify(mt.tanks)); return fd; })() })
            .then(function (r) { return r.json(); }).catch(function () { return null; }) : Promise.resolve(null);
        return calcP.then(function (calc) {
            var notes = [];
            if (mt.clash.length) notes.push('Same tank with different readings in two PDFs: ' + mt.clash.join(', ') + ' — tank totals not calculated for that fuel.');
            if (calc && calc.problems) Object.keys(calc.problems).forEach(function (fu) { notes.push(FUELS[fu] + ' tank total not calculated: ' + calc.problems[fu].length + ' reading(s) missing or not in the Excel format\'s precision — check the sounding sheet.'); });
            S.tankNote = notes.join(' ');
            var fv = sectionState[sid].formValues, ask = [];
            var ids = REPORT_FIELDS.filter(function (f) { return f.type !== 'calc' && !f.readonly && !PROFILE_FIELDS[f.id]; }).map(function (f) { return f.id; });
            ids.forEach(function (id) {
                var cands = [];
                done.forEach(function (it) {
                    var c = it.doc.fields[id];
                    if (c) cands.push({ v: c.v, status: c.status, reason: c.reason || '', raw: c.raw || '', page: c.page, file: it.file.name, fresh: !it.merged });
                    var m = /^SURVEY_(VLSFO|HSFO|LSMGO)$/.exec(id), fu = m && m[1].toLowerCase();
                    if (fu && it.doc.printed_totals[fu] != null && !(c && normNum(c.v) === normNum(it.doc.printed_totals[fu])))
                        cands.push({ v: it.doc.printed_totals[fu], status: 'ok', page: null, file: it.file.name + ' (total under the sounding table)', fresh: !it.merged });
                });
                var m2 = /^SURVEY_(VLSFO|HSFO|LSMGO)$/.exec(id), fu2 = m2 && m2[1].toLowerCase();
                if (fu2 && calc && calc.totals && calc.totals[fu2] != null && !mt.clash.some(function (c) { return c.indexOf(FUELS[fu2] + ' ') === 0; })) {
                    cands.push({ v: Number(calc.totals[fu2]).toFixed(3), status: 'calc', fresh: done.some(function (it) { return !it.merged && it.doc.tanks[fu2].length; }), calc: 'calculated from ' + calc.counts[fu2] + ' tank reading' + (calc.counts[fu2] === 1 ? '' : 's') + ' with the approved ' + esc(calc.format) + ' Excel formulas' });
                }
                if (!cands.length) return;
                var usable = cands.filter(function (c) { return c.v !== ''; }), keys = {};
                usable.forEach(function (c) { (keys[normKey(c.v)] = keys[normKey(c.v)] || []).push(c); });
                var distinct = Object.keys(keys), meta = { src: 'pdf', cands: cands };
                var proposed = '';
                if (!usable.length) { meta.status = 'review'; meta.reason = cands[0].reason; meta.raw = cands[0].raw; meta.from = cands[0]; }
                else if (distinct.length > 1) { meta.status = 'conflict'; meta.options = distinct.map(function (k) { return keys[k]; }); }
                else {
                    var group = keys[distinct[0]];
                    proposed = group[0].v;
                    var okOne = group.filter(function (c) { return c.status === 'ok'; })[0];
                    meta.from = okOne || group[0];
                    meta.status = okOne ? 'ok' : group[0].status === 'calc' ? 'calc' : 'review';
                    meta.reason = okOne ? '' : group[0].reason;
                    meta.agree = group.length;
                }
                var cur = fv[id] || '', old = S.meta[id];
                // The user's own value (typed, chosen from a conflict, or loaded from Excel) is
                // never replaced silently. Ask only when a PDF read just now says something else —
                // not again for PDFs whose value the user already saw and changed.
                var ownValue = cur !== '' && (!old || old.src !== 'pdf');
                if (ownValue) {
                    var freshDiff = usable.filter(function (c) { return c.fresh && normKey(c.v) !== normKey(cur); })[0];
                    if (freshDiff) ask.push({ id: id, v: freshDiff.v, meta: Object.assign({}, meta, { status: freshDiff.status === 'calc' ? 'calc' : freshDiff.status, from: freshDiff, reason: freshDiff.reason }), cur: cur });
                    else if (old && old.src !== 'pdf') { /* already reviewed by the user: leave as is */ }
                    else if (keys[normKey(cur)]) S.meta[id] = Object.assign(meta, { status: 'ok', confirmedOwn: true, from: keys[normKey(cur)][0], agree: keys[normKey(cur)].length });
                    return;
                }
                fv[id] = proposed; // '' for a conflict: the user chooses
                S.meta[id] = meta;
            });
            var finish = function () {
                done.forEach(function (it) { it.merged = true; });
                if (fv.HOLDS) fv.HOLDS_ALPHA = NUMS_TO_WORDS[fv.HOLDS] || '';
                calculateConsumption(sid);
                renderGroupedGrids(sid);
                // show what was read: open "2. Filled Details" (collapsed by default) for review
                var filled = document.getElementById('filledSection_' + sid), arrow = document.getElementById('arrow2_' + sid);
                if (filled && filled.classList.contains('collapsed')) { filled.classList.remove('collapsed'); if (arrow) arrow.innerText = '▲'; }
                var note = document.querySelector('[data-rg-note="' + sid + '"]');
                if (note) {
                    var c = Object.keys(S.meta).reduce(function (a, k) { var m = S.meta[k]; a[m.status] = (a[m.status] || 0) + 1; return a; }, {});
                    note.innerHTML = 'From ' + done.length + ' PDF' + (done.length === 1 ? '' : 's') + ': <b>' + (c.ok || 0) + '</b> found, <b>' + ((c.review || 0) + (c.calc || 0)) + '</b> to check, <b>' + (c.conflict || 0) + '</b> conflicting. '
                        + 'Fields not in the PDFs stay empty under "Missing Details" — fill them by hand.' + (S.tankNote ? '<br><span style="color:#92400e">' + esc(S.tankNote) + '</span>' : '');
                }
            };
            if (!ask.length) return finish();
            var list = ask.map(function (a) { return '<li><b>' + esc(label(a.id)) + '</b>: yours "' + esc(a.cur) + '" — PDF "' + esc(a.v) + '" (' + srcLabel(a.meta.from || {}) + ')</li>'; }).join('');
            return Swal.fire({ icon: 'question', title: 'Replace your values?', html: 'These fields already have a value that differs from the PDFs:<ul style="text-align:left;font-size:13px">' + list + '</ul>',
                showDenyButton: true, confirmButtonText: 'Use PDF values', denyButtonText: 'Keep mine', allowOutsideClick: false }).then(function (r) {
                ask.forEach(function (a) {
                    if (r.isConfirmed) { fv[a.id] = a.v; S.meta[a.id] = a.meta; }
                    else { var keep = S.meta[a.id] || { src: 'manual' }; keep.suggest = a; S.meta[a.id] = keep; }
                });
                finish();
            });
        });
    }
    function label(id) { var f = REPORT_FIELDS.filter(function (x) { return x.id === id; })[0]; return f ? f.label : id; }

    // ------------------------------------------------------------ per-field badge (called from renderGroupedGrids)
    window.rgPdfBadge = function (sid, id) {
        var S = P[sid];
        if (!S) return '';
        var m = S.meta[id], fv = sectionState[sid].formValues;
        var anyDone = S.files.some(function (f) { return f.status === 'done'; });
        if (!m) return S.source === 'pdf' && anyDone && !(fv[id] || '').trim() ? '<span class="rg-b rg-b-none" data-testid="rg-badge-missing">Not found in the PDFs — enter by hand</span>' : '';
        var h = '';
        if (m.src === 'manual' && m.edited && !m.suggest) h = '<span class="rg-b rg-b-edit" data-testid="rg-badge-edited">✎ Changed by you</span>';
        else if (m.src === 'manual' && m.edited) h = '';
        else if (m.status === 'ok') h = '<span class="rg-b rg-b-ok" data-testid="rg-badge-ok">✓ ' + (m.chosen ? 'Chosen by you' : m.confirmedOwn ? 'Matches the PDF' : 'From PDF') + ' · ' + srcLabel(m.from) + (m.agree > 1 ? ' (+' + (m.agree - 1) + ' more agree)' : '') + '</span>';
        else if (m.status === 'calc') h = '<span class="rg-b rg-b-calc" data-testid="rg-badge-calc">🧮 ' + srcLabel(m.from) + ' — no printed total found, check</span>';
        else if (m.status === 'review') h = '<span class="rg-b rg-b-review" data-testid="rg-badge-review">⚠ Check: ' + esc(m.reason || 'needs review') + (m.from && m.from.file ? ' · ' + srcLabel(m.from) : '') + '</span>';
        else if (m.status === 'conflict') {
            h = '<span class="rg-b rg-b-conflict" data-testid="rg-badge-conflict">⚠ The documents differ' + (m.keep ? ' (kept yours)' : '') + ' — choose:<br>'
                + m.options.map(function (g, i) { return '<button type="button" data-rg-choose="' + sid + ':' + id + ':' + i + '">' + esc(g[0].v) + ' — ' + g.map(srcLabel).join('; ') + '</button>'; }).join('') + '</span>';
        }
        if (m.suggest) h += '<span class="rg-b rg-b-review" data-testid="rg-badge-suggest">PDF says "' + esc(m.suggest.v) + '" (' + srcLabel(m.suggest.meta.from || {}) + ') <button type="button" data-rg-use="' + sid + ':' + id + '">Use</button></span>';
        return h;
    };
    // a value typed / chosen by the user: never replaced by a later PDF read without asking
    window.rgPdfManual = function (sid, id) {
        var S = P[sid];
        if (!S || !S.meta[id] || (S.meta[id].src === 'manual' && S.meta[id].edited)) return;
        S.meta[id] = { src: 'manual', edited: true, cands: S.meta[id].cands };
        var box = document.getElementById('container_' + sid + '_' + id);
        if (box) { box.querySelectorAll('.rg-b').forEach(function (b) { b.remove(); }); box.insertAdjacentHTML('beforeend', window.rgPdfBadge(sid, id)); }
    };

    // ------------------------------------------------------------ events
    document.addEventListener('click', function (e) {
        var t = e.target.closest('[data-rg-src],[data-rg-read],[data-rg-retry],[data-rg-remove],[data-rg-choose],[data-rg-use]');
        if (!t) return;
        if (t.hasAttribute('data-rg-src')) { setSource(t.getAttribute('data-sid'), t.getAttribute('data-rg-src')); return; }
        if (t.hasAttribute('data-rg-read')) { readAll(t.getAttribute('data-rg-read')); return; }
        if (t.hasAttribute('data-rg-retry')) {
            var r = t.getAttribute('data-rg-retry').split(':'), it = st(r[0]).files.filter(function (f) { return f.id === +r[1]; })[0];
            if (it) readOne(r[0], it).then(function () { merge(r[0]); });
            return;
        }
        if (t.hasAttribute('data-rg-remove')) {
            var q = t.getAttribute('data-rg-remove').split(':'), S = st(q[0]);
            S.files = S.files.filter(function (f) { if (f.id === +q[1]) URL.revokeObjectURL(f.url); return f.id !== +q[1]; });
            renderList(q[0]); renderGroupedGrids(q[0]);
            return;
        }
        if (t.hasAttribute('data-rg-choose')) {
            var c = t.getAttribute('data-rg-choose').split(':'), m = st(c[0]).meta[c[1]], g = m.options[+c[2]];
            sectionState[c[0]].formValues[c[1]] = g[0].v;
            st(c[0]).meta[c[1]] = { src: 'chosen', status: 'ok', from: g[0], agree: g.length, chosen: true, cands: m.cands };
            calculateConsumption(c[0]); renderGroupedGrids(c[0]);
            return;
        }
        if (t.hasAttribute('data-rg-use')) {
            var u = t.getAttribute('data-rg-use').split(':'), mm = st(u[0]).meta[u[1]];
            sectionState[u[0]].formValues[u[1]] = mm.suggest.v;
            st(u[0]).meta[u[1]] = Object.assign(mm.suggest.meta, { src: 'chosen' });
            calculateConsumption(u[0]); renderGroupedGrids(u[0]);
        }
    });
    document.addEventListener('change', function (e) {
        var sid = e.target.getAttribute && e.target.getAttribute('data-rg-files');
        if (!sid) return;
        addFiles(sid, e.target.files); e.target.value = '';
    });
    ['dragover', 'dragleave', 'drop'].forEach(function (ev) {
        document.addEventListener(ev, function (e) {
            var z = e.target.closest && e.target.closest('[data-rg-drop]');
            if (!z) return;
            e.preventDefault();
            z.classList.toggle('drag', ev === 'dragover');
            if (ev === 'drop' && e.dataTransfer) addFiles(z.getAttribute('data-rg-drop'), e.dataTransfer.files);
        });
    });
})();
