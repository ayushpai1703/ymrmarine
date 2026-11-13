/* Tank Details on a computer (≥992px): an Excel-style sheet — top bar, ribbon, formula bar, worksheet tabs,
   columns A–I. Loaded by survey-report.js only on a computer screen; a phone never loads this file and keeps
   its tank cards.

   The cells are the page's own tank inputs (data-t="grade:row:column"), so typing, the checks, auto-save
   (version-checked, kept on this device when offline), paste from Excel, the keyboard moves and the report
   all stay the ones of survey-report.js: the same report data as the phone. This file draws the sheet
   around them and adds the Excel tools. Formatting (bold, colours, borders …) is on this screen only and
   is not saved; V.C.F. / G.S.V. / MT are worked out by report-calc.js (the template's own formulas) and
   cannot be typed into. */
(function () {
  'use strict';
  var I = window.SR_INT; if (!I) return;
  var S = I.S, esc = I.esc, $ = I.$, $$ = I.$$;
  var LET = 'ABCDEFGHI';
  var X = {
    sheet: null, tab: 'home', fmt: {}, dec: { vcf: 4, gsv: 3, mt: 3 }, zoom: 100, freeze: true, grid: true, calcCols: true,
    formulas: false, filter: false, q: '', hideEmpty: false, ullage: false, sel: null, act: null, drag: false, wrap: {}
  };

  // ---------- helpers ----------
  function grades() { return I.chosen(); }
  function isGrade(id) { return grades().indexOf(id) >= 0; }
  function cur() { var list = sheets(); if (!list.some(function (s) { return s.id === X.sheet; })) X.sheet = list[0].id; return X.sheet; }
  function real(g) { return S.data.tanks[g].filter(function (r) { return !I.blankRow(r); }).length; }
  function sheets() {
    return grades().map(function (g) { var n = real(g); return { id: g, label: I.gradeInfo(g).short + ' (' + n + ' Tank' + (n === 1 ? '' : 's') + ')' }; })
      .concat([{ id: 'summary', label: 'Summary' }, { id: 'logbook', label: 'Logbook' }, { id: 'calc', label: 'Calculations' }]);
  }
  function cols() {
    return [['name', 'Tank Name', '', 0], ['ref_height', 'Reference Height', '(m)', 1], X.ullage ? ['ullage', 'Ullage', '(m)', 1] : ['sounding', 'Sounding', '(m)', 1],
      ['volume', 'Volume', '(m³)', 1], ['density', 'Density', '@15°C', 1], ['temp', 'Temperature', '(°C)', 1],
      ['vcf', 'V.C.F.', '', 2], ['gsv', 'G.S.V.', '(m³ @15°C)', 2], ['mt', 'MT', '(in Air)', 2]];
  }
  function fixed(v, d, comma) {
    if (v === null || v === undefined || v === '' || isNaN(v)) return '';
    return comma ? (+v).toLocaleString('en-US', { minimumFractionDigits: d, maximumFractionDigits: d }) : (+v).toFixed(d);
  }
  function calcText(t, c) { return c === 'vcf' ? fixed(t.vcf, X.dec.vcf) : fixed(t[c], X.dec[c], true); }
  /** The formula of a worked-out cell (the template's Table 54B steps, report-calc.js), with this row's cells. */
  function formula(c, n) {
    var k = S.cfg && S.cfg.calc; if (!k) return 'No formula: the template\'s formulas were not recognised';
    if (c === 'vcf') return '=ROUND(EXP(-α×(F' + n + '−' + k.base + ')×(1+0.8×α×(F' + n + '−' + k.base + '))), ' + k.vcf_dp + ')   α from E' + n + ' (Table 54B)';
    if (c === 'gsv') return '=ROUND(D' + n + '×G' + n + ', ' + k.gsv_dp + ')';
    return '=ROUND(H' + n + '×(E' + n + '−' + k.wcf + '), ' + k.mt_dp + ')';
  }
  function styleOf(r, c) {
    var f = r && X.fmt[r.id + ':' + c]; if (!f) return '';
    var st = [];
    if (f.b) st.push('font-weight:800'); if (f.i) st.push('font-style:italic'); if (f.u) st.push('text-decoration:underline');
    if (f.al) st.push('text-align:' + f.al); if (f.bg) st.push('background:' + f.bg); if (f.fg) st.push('color:' + f.fg);
    if (f.ff) st.push('font-family:' + f.ff); if (f.fs) st.push('font-size:' + f.fs + 'px');
    if (f.bd === 'all') st.push('box-shadow:inset 0 0 0 1px #0f172a'); if (f.bd === 'thick') st.push('box-shadow:inset 0 0 0 2px #0f172a');
    if (f.bd === 'bottom') st.push('box-shadow:inset 0 -2px 0 #0f172a');
    if (f.wrap) st.push('white-space:normal');
    return st.join(';');
  }
  function styleAttr(r, c) { var s = styleOf(r, c); return s ? ' style="' + esc(s) + '"' : ''; }

  // ---------- the sheet ----------
  function row(g, r, i) {
    var key = g + ':' + i, t = r ? I.calc(r) : {}, n = i + 1;
    var tds = cols().map(function (c) {
      if (c[3] === 2) {
        if (!X.calcCols && c[0] !== 'mt') return '';
        var v = X.formulas && r ? formula(c[0], n) : calcText(t, c[0]);
        return '<td class="xl-c xc x-' + c[0] + (X.formulas && r ? ' fx' : '') + '" tabindex="-1" data-col="' + c[0] + '"' + styleAttr(r, c[0]) + '><b data-rc="' + key + ':' + c[0] + '">' + esc(v) + '</b></td>';
      }
      var v2 = r ? I.shown(r, c[0]) : '', st = styleOf(r, c[0]);
      return '<td class="xi xl-c x-' + c[0] + '" data-col="' + c[0] + '"' + (st ? ' style="' + esc(st) + '"' : '') + '><label class="sr-f sr-cellf" data-tw="' + key + ':' + c[0] + '"><input data-t="' + key + ':' + c[0] +
        '" data-testid="sr-t-' + g + '-' + i + '-' + c[0] + '" value="' + esc(v2) + '"' + (c[3] ? ' inputmode="decimal"' : '') + (S.canEdit ? '' : ' disabled') +
        ' autocomplete="off" spellcheck="false"' + (/^GAUGE$/i.test(v2) ? ' class="gauge"' : '') + (st ? ' style="' + esc(st.replace(/background:[^;]*;?|box-shadow:[^;]*;?/g, '')) + '"' : '') +
        '><em data-terr="' + key + ':' + c[0] + '"></em></label></td>';
    }).join('');
    var hide = X.filter && r && ((X.q && String(r.name || '').toUpperCase().indexOf(X.q.toUpperCase()) < 0) || (X.hideEmpty && I.blankRow(r))) || (X.filter && !r && (X.q || X.hideEmpty));
    return '<tr class="' + (r ? 'sr-row' : 'sr-spare') + ' xl-row"' + (hide ? ' hidden' : '') + ' data-tank="' + key + '" data-testid="sr-' + (r ? 'tank' : 'spare') + '-' + g + '-' + i + '">' +
      '<th class="xl-rh" scope="row">' + n + '</th>' + tds +
      '<td class="xd">' + (S.canEdit ? '<button type="button" data-del="' + key + '"' + (r ? '' : ' data-spare') + ' title="Delete row" aria-label="Delete row" data-testid="sr-del-' + g + '-' + i + '"><i class="fa-regular fa-trash-can"></i></button>' : '') + '</td></tr>';
  }
  function gradeSheet(g) {
    var rows = S.data.tanks[g], spare = I.xSpare(g), body = '', tot = I.gradeTotals(g), cs = cols().filter(function (c) { return X.calcCols || c[3] !== 2 || c[0] === 'mt'; });
    rows.forEach(function (r, i) { body += row(g, r, i); });
    for (var k = 0; k < spare; k++) body += row(g, null, rows.length + k);
    var letters = cs.map(function (c, j) { return '<th class="xl-ch' + (c[3] === 2 ? ' ro' : '') + '" data-col="' + c[0] + '">' + LET[cols().map(function (x) { return x[0]; }).indexOf(c[0])] + '</th>'; }).join('');
    var heads = cs.map(function (c) {
      var lab = c[0] === 'sounding' || c[0] === 'ullage'
        ? '<span class="xl-su">' + (S.canEdit || true ? '<button type="button" data-xl="su:sounding" class="' + (X.ullage ? '' : 'on') + '">Sounding</button>/<button type="button" data-xl="su:ullage" class="' + (X.ullage ? 'on' : '') + '">Ullage</button>' : '') + '</span>'
        : esc(c[1]);
      return '<th class="xl-hd x-' + c[0] + (c[3] === 2 ? ' ro' : '') + '">' + lab + (c[2] ? '<small>' + esc(c[2]) + '</small>' : '') + '</th>';
    }).join('');
    var span = cs.length - 1;
    return '<section class="sr-xsec xl-sec" data-testid="sr-tanks-' + g + '">' +
      '<div class="xl-grid' + (X.freeze ? ' frz' : '') + (X.grid ? '' : ' nogrid') + '" style="zoom:' + (X.zoom / 100) + '">' +
      '<table class="sr-xg xl-t" data-g="' + g + '" data-testid="sr-tgrid' + (grades()[0] === g ? '' : '-' + g) + '">' +
      '<thead><tr class="xl-letters"><th class="xl-corner"></th>' + letters + '<th class="xd"></th></tr>' +
      '<tr class="xl-heads"><th class="xl-rh"></th>' + heads + '<th class="xd"></th></tr>' +
      (X.filter ? '<tr class="xl-filter"><th class="xl-rh"><i class="fa-solid fa-filter"></i></th><th colspan="' + (cs.length + 1) + '"><input data-xl-q value="' + esc(X.q) + '" placeholder="Filter tank name…" data-testid="xl-filter">' +
        '<label><input type="checkbox" data-xl-hideempty' + (X.hideEmpty ? ' checked' : '') + '> Hide empty rows</label></th></tr>' : '') +
      '</thead><tbody>' + (body || '<tr><td colspan="' + (cs.length + 2) + '" class="xe">No rows — Insert ▸ Add rows.</td></tr>') + '</tbody>' +
      '<tfoot><tr class="xl-tot"><th class="xl-rh">Σ</th><td class="xl-totl" colspan="' + (span - (X.calcCols ? 1 : 0)) + '">TOTAL ' + esc(I.gradeInfo(g).short) + '</td>' +
      (X.calcCols ? '<td class="xl-c xc"><b data-ggsv="' + g + '">' + I.f3c(tot.gsv) + '</b></td>' : '') +
      '<td class="xl-c xc mt"><b data-gmt="' + g + '">' + I.f3c(tot.mt) + '</b></td><td class="xd"></td></tr></tfoot></table></div>' +
      (S.canEdit ? '<div class="sr-xadd xl-add"><button type="button" data-more="' + g + '" data-testid="sr-add-' + g + '"><i class="fa-solid fa-plus"></i> Add more tanks</button></div>' : '') +
      '<b hidden data-testid="sr-total-' + g + '">' + I.f3(tot.mt) + '</b><b hidden data-testid="sr-total-gsv-' + g + '">' + I.f3(tot.gsv) + '</b></section>';
  }
  function summarySheet() {
    var all = I.allTotals(), rows = grades().map(function (g) {
      var t = I.gradeTotals(g);
      return '<tr><td><b>' + esc(I.gradeInfo(g).short) + '</b></td><td>' + t.n + '</td><td>' + I.f3c(t.gsv) + '</td><td class="mt"><b>' + I.f3c(t.mt) + '</b></td>' +
        '<td><button type="button" class="xl-link" data-xl-sheet="' + g + '">Open sheet</button></td></tr>';
    }).join('');
    var cmp = I.summaryRows().map(function (r) {
      return '<tr><td><b>' + esc(r.short) + '</b></td><td>' + I.f3(r.completed) + '</td><td>' + I.f3(r.logbook) + '</td><td class="' + (r.diff < 0 ? 'neg' : r.diff > 0 ? 'pos' : '') + '">' + (r.diff === null ? '' : (r.diff > 0 ? '+' : '') + I.f3(r.diff)) + '</td></tr>';
    }).join('');
    return '<div class="xl-doc" data-testid="xl-summary"><h3>Tank totals</h3><table class="xl-plain"><thead><tr><th>Grade</th><th>Tanks</th><th>G.S.V. (m³ @15°C)</th><th>MT (in Air)</th><th></th></tr></thead><tbody>' + rows +
      '</tbody><tfoot><tr><th>TOTAL</th><th data-testid="sr-tot-tanks">' + all.n + '</th><th>' + I.f3c(all.gsv) + '</th><th class="mt" data-testid="sr-tot-mt">' + I.f3c(all.mt) + ' MT</th><th></th></tr></tfoot></table>' +
      (cmp ? '<h3>ROB comparison</h3><table class="xl-plain"><thead><tr><th>Grade</th><th>Survey Completed</th><th>Log Book</th><th>Difference</th></tr></thead><tbody>' + cmp + '</tbody></table>' +
        '<p class="xl-note">Survey Completed = sum of MT in Air of the tanks. Log Book = ROB at survey start (Logbook sheet). Difference = Survey Completed − Log Book.</p>' : '') + '</div>';
  }
  function logbookSheet() {
    var dis = S.canEdit ? '' : ' disabled';
    var cell = function (k) { return '<td><label class="sr-f xl-lf" data-fw="' + k + '"><input data-f="' + k + '" data-testid="sr-f-' + k + '" value="' + esc(I.val(k)) + '" inputmode="decimal" autocomplete="off"' + dis + '><em data-err="' + k + '"></em></label></td>'; };
    return '<div class="xl-doc" data-testid="xl-logbook"><h3>Log book ROB <small>MT — the same boxes as Survey Details</small></h3><table class="xl-plain xl-lb"><thead><tr><th>Grade</th><th>ROB at FWE</th><th>Log book ROB at survey start</th></tr></thead><tbody>' +
      grades().map(function (g) { return '<tr><td><b>' + esc(I.gradeInfo(g).short) + '</b></td>' + cell('brob_fwe_' + g) + cell('brob_started_' + g) + '</tr>'; }).join('') + '</tbody></table></div>';
  }
  function ribbon() {
    var T = function (id, l) { return '<button type="button" role="tab" class="' + (X.tab === id ? 'on' : '') + '" data-xl="tab:' + id + '" aria-selected="' + (X.tab === id) + '">' + l + '</button>'; };
    var B = function (act, ic, l, extra) { return '<button type="button" class="xl-b' + (extra || '') + '" data-xl="' + act + '" title="' + esc(l) + '">' + (ic === 'sigma' ? '<i class="xl-sig">Σ</i>' : '<i class="' + ic + '"></i>') + '<span>' + esc(l) + '</span></button>'; };
    var b = function (act, ic, l, on) { return '<button type="button" class="xl-s' + (on ? ' on' : '') + '" data-xl="' + act + '" title="' + esc(l) + '" aria-label="' + esc(l) + '"><i class="' + ic + '"></i></button>'; };
    var G = function (name, inner) { return '<div class="xl-g"><div class="xl-gi">' + inner + '</div><div class="xl-gn">' + name + '</div></div>'; };
    var f = activeFmt();
    var fonts = ['Lexend', 'Calibri', 'Arial', 'Times New Roman', 'Courier New'].map(function (x) { return '<option' + (f.ff === x ? ' selected' : '') + '>' + x + '</option>'; }).join('');
    var sizes = [10, 11, 12, 13, 14, 16, 18, 20].map(function (x) { return '<option' + (+f.fs === x ? ' selected' : '') + '>' + x + '</option>'; }).join('');
    var tabs = '<div class="xl-tabs" role="tablist">' + T('file', 'File') + T('home', 'Home') + T('insert', 'Insert') + T('formulas', 'Formulas') + T('data', 'Data') + T('view', 'View') + '</div>';
    var body;
    if (X.tab === 'file') body = G('Report', B('save', 'fa-solid fa-floppy-disk', 'Save now') + B('export', 'fa-solid fa-file-export', 'Export Excel') + B('import', 'fa-solid fa-file-import', 'Import Excel') + B('generate', 'fa-solid fa-file-pdf', 'Generate Report'));
    else if (X.tab === 'insert') body = G('Rows', B('addrows', 'fa-solid fa-plus', 'Add rows') + B('copyrow', 'fa-regular fa-copy', 'Copy tank') + B('delrow', 'fa-regular fa-trash-can', 'Delete row'));
    else if (X.tab === 'formulas') body = G('Function', B('sum', 'sigma', 'AutoSum')) + G('Auditing', B('showfx', 'fa-solid fa-code', X.formulas ? 'Show values' : 'Show formulas') + B('recalc', 'fa-solid fa-rotate', 'Calculate now')) +
      G('Template', '<div class="xl-calcinfo">' + calcInfo() + '</div>');
    else if (X.tab === 'data') body = G('Get data', B('import', 'fa-solid fa-file-import', 'Import Excel') + B('export', 'fa-solid fa-file-export', 'Export Excel') + (S.canEdit && S.prev && Object.keys(S.prev.tanks || {}).length ? B('prev', 'fa-regular fa-clone', 'Copy from Previous') : '')) +
      G('Sort & Filter', B('sort:asc', 'fa-solid fa-arrow-down-a-z', 'Sort A→Z') + B('sort:desc', 'fa-solid fa-arrow-down-z-a', 'Sort Z→A') + B('filter', 'fa-solid fa-filter', X.filter ? 'Clear filter' : 'Filter')) +
      G('Readings', b('su:sounding', 'fa-solid fa-arrow-up', 'Show sounding', !X.ullage) + b('su:ullage', 'fa-solid fa-arrow-down-long', 'Show ullage', X.ullage));
    else if (X.tab === 'view') body = G('Window', B('freeze', 'fa-solid fa-lock', X.freeze ? 'Unfreeze panes' : 'Freeze panes') + B('gridlines', 'fa-solid fa-border-all', X.grid ? 'Hide gridlines' : 'Show gridlines') + B('calccols', 'fa-solid fa-table-columns', X.calcCols ? 'Hide V.C.F. & G.S.V.' : 'Show V.C.F. & G.S.V.')) +
      G('Zoom', b('zoom:-', 'fa-solid fa-magnifying-glass-minus', 'Zoom out') + '<span class="xl-zv">' + X.zoom + '%</span>' + b('zoom:+', 'fa-solid fa-magnifying-glass-plus', 'Zoom in') + b('zoom:100', 'fa-solid fa-expand', '100%'));
    else body = G('Clipboard', B('paste', 'fa-solid fa-paste', 'Paste', ' big') + '<div class="xl-col">' + b('cut', 'fa-solid fa-scissors', 'Cut') + b('copy', 'fa-regular fa-copy', 'Copy') + '</div>') +
      G('Font', '<div class="xl-col"><div class="xl-row"><select data-xl-set="ff" title="Font">' + fonts + '</select><select data-xl-set="fs" title="Font size" class="sm">' + sizes + '</select></div>' +
        '<div class="xl-row">' + b('fmt:b', 'fa-solid fa-bold', 'Bold (Ctrl+B)', f.b) + b('fmt:i', 'fa-solid fa-italic', 'Italic (Ctrl+I)', f.i) + b('fmt:u', 'fa-solid fa-underline', 'Underline (Ctrl+U)', f.u) +
        '<select data-xl-set="bd" title="Borders" class="md"><option value="">Borders</option><option value="all">All borders</option><option value="bottom">Bottom border</option><option value="thick">Thick box</option><option value="none">No border</option></select>' +
        '<label class="xl-clr" title="Fill colour"><i class="fa-solid fa-fill-drip"></i><input type="color" data-xl-set="bg" value="' + (f.bg || '#fff59d') + '"></label>' +
        '<label class="xl-clr" title="Font colour"><i class="fa-solid fa-font"></i><input type="color" data-xl-set="fg" value="' + (f.fg || '#dc2626') + '"></label></div></div>') +
      G('Alignment', '<div class="xl-row">' + b('al:left', 'fa-solid fa-align-left', 'Align left', f.al === 'left') + b('al:center', 'fa-solid fa-align-center', 'Center', f.al === 'center') + b('al:right', 'fa-solid fa-align-right', 'Align right', f.al === 'right') + '</div>' +
        '<div class="xl-row">' + b('wrap', 'fa-solid fa-paragraph', 'Wrap text', f.wrap) + b('clearfmt', 'fa-solid fa-eraser', 'Clear formats') + '</div>') +
      G('Number', '<div class="xl-row"><select data-xl-set="num" title="Number format (worked-out columns)"><option value="">Number format</option><option value="vcf">V.C.F. 0.0000</option><option value="3">G.S.V. / MT 0.000</option><option value="2">G.S.V. / MT 0.00</option></select></div>' +
        '<div class="xl-row">' + b('dec:+', 'fa-solid fa-plus', 'Increase decimals') + b('dec:-', 'fa-solid fa-minus', 'Decrease decimals') + '</div>') +
      G('Editing', B('sum', 'sigma', 'AutoSum') + '<div class="xl-col">' + b('sort:asc', 'fa-solid fa-arrow-down-a-z', 'Sort A→Z') + b('filter', 'fa-solid fa-filter', 'Filter', X.filter) + '</div>' +
        B('freeze', 'fa-solid fa-lock', X.freeze ? 'Unfreeze' : 'Freeze panes'));
    return '<div class="xl-ribbon" data-testid="xl-ribbon">' + tabs + '<div class="xl-rb">' + body + '</div></div>';
  }
  function calcInfo() {
    var k = S.cfg && S.cfg.calc; if (!k) return 'No template formulas';
    return 'Table 54B · base ' + k.base + '°C · W.C.F. ' + k.wcf + '<br>V.C.F. ' + k.vcf_dp + ' dp · G.S.V. ' + k.gsv_dp + ' dp · MT ' + k.mt_dp + ' dp';
  }
  function html() {
    var id = cur(), g = isGrade(id);
    var body = g ? gradeSheet(id) : id === 'summary' ? summarySheet() : id === 'logbook' ? logbookSheet() : '<div class="xl-doc" data-testid="xl-calc">' + I.calcHtml() + '</div>';
    var kind = (S.report && S.report.kind_label) || 'Bunker Survey';
    return '<div class="xl" data-testid="xl">' +
      '<div class="xl-top"><div class="xl-app"><i class="fa-solid fa-table"></i></div><div class="xl-ttl"><b>' + esc(kind) + '</b><small>' + esc(I.val('vessel_name') || (S.report && S.report.vessel) || '') +
        (S.report && S.report.id && /^\d+$/.test(String(S.report.id)) ? ' · Report #' + esc(S.report.id) : '') + '</small></div>' +
        '<span class="xl-save" data-xl-save></span><span class="xl-sp"></span>' +
        '<button type="button" class="xl-tb" data-xl="import" data-testid="xl-import"><i class="fa-solid fa-file-import"></i> Import Excel</button>' +
        '<button type="button" class="xl-tb" data-xl="export" data-testid="xl-export"><i class="fa-solid fa-file-export"></i> Export Excel</button>' +
        '<button type="button" class="xl-tb pri" data-goto="generate" data-testid="xl-generate"><i class="fa-solid fa-file-pdf"></i> Generate Report</button>' +
        '<input type="file" hidden data-import-file accept=".xlsx,.xlsm,.xls,.csv"></div>' +
      ribbon() +
      '<div class="xl-fbar"><span class="xl-ref" data-xl-ref data-testid="xl-ref">' + (g ? 'A1' : '') + '</span><span class="xl-fxi"><i>f</i>x</span>' +
        '<input class="xl-fx" data-xl-fx data-testid="xl-fx" autocomplete="off" spellcheck="false"' + (g && S.canEdit ? '' : ' readonly') + ' placeholder="' + (g ? 'Select a cell' : '') + '"></div>' +
      '<div class="xl-body">' + body + '</div>' +
      '<div class="xl-sheets" role="tablist" data-testid="xl-sheets">' + sheets().map(function (s) {
        return '<button type="button" role="tab" class="' + (s.id === id ? 'on' : '') + '" data-xl-sheet="' + s.id + '" data-testid="xl-sheet-' + s.id + '" aria-selected="' + (s.id === id) + '">' + esc(s.label) + '</button>';
      }).join('') + '<span class="xl-sp"></span><span class="xl-stat" data-xl-stat></span></div>' +
      '</div>';
  }

  // ---------- selection ----------
  function table() { return $('.xl table.xl-t'); }
  function rowsEl() { var t = table(); return t ? $$('tbody tr.xl-row', t) : []; }
  function cellAt(ri, ci) { var tr = rowsEl()[ri]; return tr ? $$('td.xl-c', tr)[ci] : null; }
  function posOf(td) { var tr = td.closest('tr.xl-row'); if (!tr) return null; return { r: rowsEl().indexOf(tr), c: $$('td.xl-c', tr).indexOf(td) }; }
  function colKeys() { return cols().filter(function (c) { return X.calcCols || c[3] !== 2 || c[0] === 'mt'; }).map(function (c) { return c[0]; }); }
  function rangeCells() {
    var s = X.sel; if (!s) return [];
    var out = [];
    for (var r = Math.min(s.r0, s.r1); r <= Math.max(s.r0, s.r1); r++) for (var c = Math.min(s.c0, s.c1); c <= Math.max(s.c0, s.c1); c++) { var td = cellAt(r, c); if (td && !td.closest('tr').hidden) out.push(td); }
    return out;
  }
  function multi() { var s = X.sel; return !!s && (s.r0 !== s.r1 || s.c0 !== s.c1); }
  function paintSel() {
    $$('.xl td.xl-sel, .xl td.xl-act').forEach(function (td) { td.classList.remove('xl-sel', 'xl-act'); });
    $$('.xl .xl-ch.on, .xl .xl-rh.on').forEach(function (e) { e.classList.remove('on'); });
    var cells = rangeCells(); cells.forEach(function (td) { td.classList.add('xl-sel'); });
    var a = X.sel && cellAt(X.sel.r1, X.sel.c1); if (a) a.classList.add('xl-act');
    if (X.sel) {
      var t = table(), letters = t ? $$('.xl-letters th.xl-ch', t) : [];
      for (var c = Math.min(X.sel.c0, X.sel.c1); c <= Math.max(X.sel.c0, X.sel.c1); c++) if (letters[c]) letters[c].classList.add('on');
      for (var r = Math.min(X.sel.r0, X.sel.r1); r <= Math.max(X.sel.r0, X.sel.r1); r++) { var tr = rowsEl()[r], h = tr && $('.xl-rh', tr); if (h) h.classList.add('on'); }
    }
    status(); fbar();
  }
  function refOf(ri, ci) { var k = colKeys()[ci]; return LET[cols().map(function (x) { return x[0]; }).indexOf(k)] + (ri + 1); }
  function fbar() {
    var ref = $('[data-xl-ref]'), fx = $('[data-xl-fx]'); if (!ref || !fx || !X.sel) return;
    var s = X.sel, td = cellAt(s.r1, s.c1);
    ref.textContent = multi() ? refOf(Math.min(s.r0, s.r1), Math.min(s.c0, s.c1)) + ':' + refOf(Math.max(s.r0, s.r1), Math.max(s.c0, s.c1)) : refOf(s.r1, s.c1);
    if (!td) { fx.value = ''; return; }
    var inp = $('input[data-t]', td);
    if (document.activeElement === fx) return;
    if (inp) { fx.value = inp.value; fx.readOnly = !S.canEdit; fx.classList.remove('ro'); }
    else { var tr = td.closest('tr'), real = tr.classList.contains('sr-row'); fx.value = real ? formula(colKeys()[s.c1], s.r1 + 1) : ''; fx.readOnly = true; fx.classList.add('ro'); }
  }
  function nums() {
    return rangeCells().map(function (td) { var i = $('input', td), v = i ? i.value : td.textContent; return I.num(String(v).replace(/,/g, '')); }).filter(function (n) { return n !== null; });
  }
  function status() {
    var el = $('[data-xl-stat]'); if (!el) return;
    var n = nums(), cells = rangeCells().length;
    if (cells > 1 && n.length) {
      var sum = n.reduce(function (a, b) { return a + b; }, 0);
      el.innerHTML = 'Average: <b>' + fixed(sum / n.length, 3, true) + '</b> Count: <b>' + cells + '</b> Sum: <b data-testid="xl-sum">' + fixed(sum, 3, true) + '</b>';
    } else el.innerHTML = cells > 1 ? 'Count: <b>' + cells + '</b>' : 'Ready';
  }
  function select(td, extend) {
    var p = posOf(td); if (!p) return;
    if (extend && X.sel) { X.sel.r1 = p.r; X.sel.c1 = p.c; } else X.sel = { r0: p.r, c0: p.c, r1: p.r, c1: p.c };
    paintSel();
  }
  function restoreSel() { if (!X.sel) return; var td = cellAt(X.sel.r1, X.sel.c1); if (!td) { X.sel = null; return; } paintSel(); }

  // ---------- formatting (this screen only) ----------
  function activeFmt() {
    if (!X.sel || !isGrade(X.sheet)) return {};
    var tr = rowsEl()[X.sel.r1], r = tr && rowData(tr); return r ? X.fmt[r.id + ':' + colKeys()[X.sel.c1]] || {} : {};
  }
  function rowData(tr) { var k = tr.getAttribute('data-tank').split(':'); return S.data.tanks[k[0]] && S.data.tanks[k[0]][+k[1]]; }
  function applyFmt(fn) {
    var cells = rangeCells(); if (!cells.length) { I.toast('info', 'Select cells first'); return; }
    cells.forEach(function (td) {
      var r = rowData(td.closest('tr')); if (!r) return;
      var k = r.id + ':' + td.getAttribute('data-col'), f = X.fmt[k] = X.fmt[k] || {}; fn(f);
      if (!Object.keys(f).some(function (x) { return f[x]; })) delete X.fmt[k];
    });
    I.render();
  }
  function toggleFmt(p) { var on = !activeFmt()[p]; applyFmt(function (f) { f[p] = on; }); }

  // ---------- clipboard ----------
  function tsv() {
    var s = X.sel, out = [];
    for (var r = Math.min(s.r0, s.r1); r <= Math.max(s.r0, s.r1); r++) {
      var line = [];
      for (var c = Math.min(s.c0, s.c1); c <= Math.max(s.c0, s.c1); c++) { var td = cellAt(r, c), i = td && $('input', td); line.push(i ? i.value : td ? td.textContent.trim() : ''); }
      out.push(line.join('\t'));
    }
    return out.join('\n');
  }
  function clearRange() {
    if (!S.canEdit) return;
    rangeCells().forEach(function (td) { var i = $('input[data-t]', td); if (i && i.value !== '') { i.value = ''; i.dispatchEvent(new Event('input', { bubbles: true })); } });
    status(); fbar();
  }
  function writeClip(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) return navigator.clipboard.writeText(text).catch(function () {});
    var ta = document.createElement('textarea'); ta.value = text; document.body.appendChild(ta); ta.select(); try { document.execCommand('copy'); } catch (e) {} ta.remove(); return Promise.resolve();
  }
  function activeInput() { var td = X.sel && cellAt(Math.min(X.sel.r0, X.sel.r1), Math.min(X.sel.c0, X.sel.c1)); return td && $('input[data-t]', td); }
  function paste() {
    var inp = activeInput();
    if (!inp || !S.canEdit) { I.toast('info', 'Select an input cell first'); return; }
    if (!navigator.clipboard || !navigator.clipboard.readText) { I.toast('info', 'Press Ctrl+V to paste'); inp.focus(); return; }
    navigator.clipboard.readText().then(function (text) {
      if (!I.gridPaste(inp, text)) { inp.value = text.replace(/[\r\n]+$/, ''); inp.dispatchEvent(new Event('input', { bubbles: true })); inp.dispatchEvent(new Event('change', { bubbles: true })); }
    }, function () { I.toast('info', 'Press Ctrl+V to paste'); inp.focus(); });
  }

  // ---------- Excel export (ExcelJS, loaded only when asked) ----------
  var EXCELJS = 'https://cdnjs.cloudflare.com/ajax/libs/exceljs/4.4.0/exceljs.min.js';
  function loadExcelJS() {
    if (window.ExcelJS) return Promise.resolve(window.ExcelJS);
    return new Promise(function (ok, bad) { var s = document.createElement('script'); s.src = EXCELJS; s.onload = function () { ok(window.ExcelJS); }; s.onerror = bad; document.head.appendChild(s); });
  }
  function exportXlsx() {
    loadExcelJS().then(function (EJ) {
      var wb = new EJ.Workbook(), head = ['Tank Name', 'Reference Height (m)', 'Sounding (m)', 'Ullage (m)', 'Volume (m³)', 'Density @15°C', 'Temperature (°C)', 'V.C.F.', 'G.S.V. (m³)', 'MT (in Air)'];
      var n = function (v) { var x = I.num(v); return x === null ? (v || '') : x; };
      grades().forEach(function (g) {
        var ws = wb.addWorksheet(I.gradeInfo(g).short), t = I.gradeTotals(g);
        ws.addRow([(S.report && S.report.kind_label || 'Survey') + ' — ' + (I.val('vessel_name') || S.report.vessel || '') + ' — ' + I.gradeInfo(g).name]).font = { bold: true, size: 13 };
        var h = ws.addRow(head); h.font = { bold: true }; h.eachCell(function (c) { c.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FFE8F1FC' } }; c.border = { bottom: { style: 'thin' } }; });
        S.data.tanks[g].filter(function (r) { return !I.blankRow(r); }).forEach(function (r) {
          var c = I.calc(r), sd = I.shown(r, 'sounding'), ul = I.shown(r, 'ullage');
          ws.addRow([r.name, n(r.ref_height), n(sd), n(ul), n(r.volume), n(r.density), n(r.temp), c.vcf, c.gsv, c.mt]);
        });
        var tr = ws.addRow(['TOTAL', '', '', '', '', '', '', '', t.gsv, t.mt]); tr.font = { bold: true };
        ws.columns.forEach(function (c, i) { c.width = i === 0 ? 18 : 15; });
        [8].forEach(function (i) { ws.getColumn(i).numFmt = '0.0000'; }); [9, 10].forEach(function (i) { ws.getColumn(i).numFmt = '#,##0.000'; });
        ws.views = [{ state: 'frozen', xSplit: 1, ySplit: 2 }];
      });
      var sm = wb.addWorksheet('Summary'); sm.addRow(['Grade', 'Tanks', 'G.S.V. (m³)', 'MT (in Air)', 'Log Book ROB', 'Difference']).font = { bold: true };
      I.summaryRows().forEach(function (r) { var t = I.gradeTotals(r.g); sm.addRow([r.short, t.n, t.gsv, t.mt, r.logbook, r.diff]); });
      sm.columns.forEach(function (c) { c.width = 16; });
      return wb.xlsx.writeBuffer();
    }).then(function (buf) {
      var name = String(I.val('vessel_name') || (S.report && S.report.vessel) || 'survey').replace(/[^\w\- ]+/g, '').trim().replace(/\s+/g, '_') + '_tanks.xlsx';
      var a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([buf], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' })); a.download = name;
      document.body.appendChild(a); a.click(); setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 4000);
      I.toast('success', 'Excel downloaded');
    }).catch(function () { I.toast('error', 'Could not make the Excel (check the internet connection).'); });
  }

  // ---------- actions ----------
  function selRow() { if (!X.sel) return null; var tr = rowsEl()[X.sel.r1]; return tr && tr.classList.contains('sr-row') ? tr.getAttribute('data-tank') : null; }
  function act(a) {
    var p = a.split(':');
    switch (p[0]) {
      case 'tab': X.tab = p[1]; I.render(); return;
      case 'save': $('[data-save-now]') ? $('[data-save-now]').click() : I.saveNow(); return;
      case 'export': exportXlsx(); return;
      case 'import': if (!S.canEdit) return; var fi = $('.xl [data-import-file]'); if (fi) { fi.value = ''; fi.click(); } return;
      case 'generate': var gb = $('[data-goto=generate]'); if (gb) gb.click(); return;
      case 'addrows': if (isGrade(X.sheet) && S.canEdit) I.moreRows(X.sheet); return;
      case 'copyrow': var k = selRow(); if (k && S.canEdit) I.copyTank(k); else I.toast('info', 'Select a tank row first'); return;
      case 'delrow': var k2 = selRow(); if (k2 && S.canEdit) I.delTank(k2); else I.toast('info', 'Select a tank row first'); return;
      case 'sum': {
        var n = nums(); if (!n.length) { I.toast('info', 'Select number cells to add up'); return; }
        var s = n.reduce(function (x, y) { return x + y; }, 0), fx = $('[data-xl-fx]');
        if (fx) { fx.value = '=SUM(' + $('[data-xl-ref]').textContent + ') = ' + fixed(s, 3, true); fx.classList.add('ro'); }
        I.toast('success', 'Sum: ' + fixed(s, 3, true)); return;
      }
      case 'showfx': X.formulas = !X.formulas; I.render(); return;
      case 'recalc': I.render(); I.toast('success', 'Calculated'); return;
      case 'prev': var pb = document.createElement('button'); pb.setAttribute('data-prev-tanks', ''); pb.hidden = true; document.body.appendChild(pb); pb.click(); pb.remove(); return;
      case 'sort': {
        if (!isGrade(X.sheet) || !S.canEdit) return;
        var g = X.sheet, dir = p[1] === 'desc' ? -1 : 1;
        var go = function () {
          S.data.tanks[g] = S.data.tanks[g].slice().sort(function (x, y) { return dir * String(x.name || '').localeCompare(String(y.name || ''), undefined, { numeric: true, sensitivity: 'base' }); });
          X.sel = null; I.changed(); I.render(); I.toast('success', 'Sorted');
        };
        if (window.Swal) Swal.fire({ icon: 'question', title: 'Sort the tanks by name?', text: 'The tanks keep this order in the report too.', showCancelButton: true, confirmButtonText: 'Sort' }).then(function (x) { if (x.isConfirmed) go(); });
        else go();
        return;
      }
      case 'filter': X.filter = !X.filter; if (!X.filter) { X.q = ''; X.hideEmpty = false; } I.render(); if (X.filter) { var q = $('[data-xl-q]'); if (q) q.focus(); } return;
      case 'su': X.ullage = p[1] === 'ullage'; I.render(); return;
      case 'freeze': X.freeze = !X.freeze; I.render(); return;
      case 'gridlines': X.grid = !X.grid; I.render(); return;
      case 'calccols': X.calcCols = !X.calcCols; X.sel = null; I.render(); return;
      case 'zoom': X.zoom = p[1] === '100' ? 100 : Math.max(60, Math.min(160, X.zoom + (p[1] === '+' ? 10 : -10))); I.render(); return;
      case 'cut': if (X.sel) { writeClip(tsv()).then(clearRange); } return;
      case 'copy': if (X.sel) writeClip(tsv()).then(function () { I.toast('success', 'Copied'); }); return;
      case 'paste': paste(); return;
      case 'fmt': toggleFmt(p[1]); return;
      case 'al': applyFmt(function (f) { f.al = f.al === p[1] ? '' : p[1]; }); return;
      case 'wrap': toggleFmt('wrap'); return;
      case 'clearfmt': applyFmt(function (f) { Object.keys(f).forEach(function (k) { delete f[k]; }); }); return;
      case 'dec': {
        var keys = rangeCells().map(function (td) { return td.getAttribute('data-col'); }).filter(function (c) { return c in X.dec; });
        if (!keys.length) keys = ['gsv', 'mt'];
        keys.forEach(function (c) { X.dec[c] = Math.max(0, Math.min(6, X.dec[c] + (p[1] === '+' ? 1 : -1))); });
        I.render(); return;
      }
    }
  }

  // ---------- events ----------
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest && ev.target.closest('.xl [data-xl], .xl [data-xl-sheet]'); if (!b) return;
    if (b.hasAttribute('data-xl-sheet')) { X.sheet = b.getAttribute('data-xl-sheet'); X.sel = null; I.render(); return; }
    act(b.getAttribute('data-xl'));
  });
  document.addEventListener('change', function (ev) {
    var el = ev.target;
    if (el.matches('.xl [data-xl-set]')) {
      var k = el.getAttribute('data-xl-set'), v = el.value;
      if (k === 'num') { if (v === 'vcf') X.dec.vcf = 4; else if (v) { X.dec.gsv = +v; X.dec.mt = +v; } I.render(); return; }
      if (k === 'bd') { applyFmt(function (f) { f.bd = v === 'none' ? '' : v; }); return; }
      applyFmt(function (f) { f[k] = v; }); return;
    }
    if (el.matches('.xl [data-xl-hideempty]')) { X.hideEmpty = el.checked; I.render(); }
  });
  document.addEventListener('input', function (ev) {
    var el = ev.target;
    if (el.matches('.xl [data-xl-q]')) {
      X.q = el.value;
      rowsEl().forEach(function (tr) { var r = rowData(tr); tr.hidden = r ? ((X.q && String(r.name || '').toUpperCase().indexOf(X.q.toUpperCase()) < 0) || (X.hideEmpty && I.blankRow(r))) : !!(X.q || X.hideEmpty); });
      return;
    }
    if (el.matches('.xl [data-xl-fx]')) { var inp = activeInput(); if (inp && !el.readOnly) { inp.value = el.value; inp.dispatchEvent(new Event('input', { bubbles: true })); } return; }
    if (el.matches('.xl input[data-t]')) { var fx = $('[data-xl-fx]'); if (fx && document.activeElement !== fx) fx.value = el.value; }
  });
  document.addEventListener('keydown', function (ev) {
    var el = ev.target;
    if (el.matches && el.matches('.xl [data-xl-fx]')) {
      if (ev.key === 'Enter' || ev.key === 'Escape') { ev.preventDefault(); var inp = activeInput(); if (inp) { inp.dispatchEvent(new Event('change', { bubbles: true })); inp.focus(); } }
      return;
    }
    if (!el.closest || !el.closest('.xl')) return;
    var mod = ev.ctrlKey || ev.metaKey;
    if (mod && /^[biu]$/i.test(ev.key) && X.sel) { ev.preventDefault(); toggleFmt(ev.key.toLowerCase()); return; }
    if (multi() && (ev.key === 'Delete' || ev.key === 'Backspace')) { ev.preventDefault(); clearRange(); return; }
    if (mod && ev.key.toLowerCase() === 'a' && el.matches('input[data-t]') && el.selectionStart === 0 && el.selectionEnd === el.value.length) { // all cells of the sheet
      ev.preventDefault(); var rs = rowsEl(); if (rs.length) { X.sel = { r0: 0, c0: 0, r1: rs.length - 1, c1: colKeys().length - 1 }; paintSel(); } return;
    }
    if (ev.shiftKey && /^Arrow/.test(ev.key) && X.sel && el.matches('input[data-t]') && el.selectionStart === 0 && el.selectionEnd === el.value.length) { // grow the selection
      ev.preventDefault(); ev.stopImmediatePropagation();
      var s = X.sel, d = { ArrowUp: [-1, 0], ArrowDown: [1, 0], ArrowLeft: [0, -1], ArrowRight: [0, 1] }[ev.key];
      s.r1 = Math.max(0, Math.min(rowsEl().length - 1, s.r1 + d[0])); s.c1 = Math.max(0, Math.min(colKeys().length - 1, s.c1 + d[1])); paintSel();
    }
  }, true);
  document.addEventListener('copy', function (ev) { if (multi() && ev.target.closest && ev.target.closest('.xl')) { ev.preventDefault(); ev.clipboardData.setData('text/plain', tsv()); I.toast('success', 'Copied'); } });
  document.addEventListener('cut', function (ev) { if (multi() && S.canEdit && ev.target.closest && ev.target.closest('.xl')) { ev.preventDefault(); ev.clipboardData.setData('text/plain', tsv()); clearRange(); } });
  // selecting cells: click, Shift+click, drag
  document.addEventListener('mousedown', function (ev) {
    var td = ev.target.closest && ev.target.closest('.xl td.xl-c'); if (!td || ev.button !== 0) return;
    if (ev.shiftKey && X.sel) { ev.preventDefault(); select(td, true); return; }
    X.drag = true; select(td, false);
    if (td.classList.contains('xc')) td.focus();
  }, true);
  document.addEventListener('mouseover', function (ev) { if (!X.drag) return; var td = ev.target.closest && ev.target.closest('.xl td.xl-c'); if (td) { var p = posOf(td); if (p && X.sel && (p.r !== X.sel.r1 || p.c !== X.sel.c1)) { X.sel.r1 = p.r; X.sel.c1 = p.c; paintSel(); } } });
  document.addEventListener('mouseup', function () { X.drag = false; });
  document.addEventListener('focusin', function (ev) {
    var td = ev.target.closest && ev.target.closest('.xl td.xl-c'); if (!td || X.drag) return;
    var p = posOf(td); if (!p) return;
    if (!X.sel || multi() && !rangeCells().some(function (x) { return x === td; }) || !multi()) { X.sel = { r0: p.r, c0: p.c, r1: p.r, c1: p.c }; }
    paintSel();
  });
  // the page's own save state, shown in the sheet's title bar
  function mirrorSave() {
    var src = $('[data-save-state]'), dst = $('[data-xl-save]'); if (!src || !dst) return;
    var cls = src.className, t = src.textContent || '';
    dst.className = 'xl-save ' + (/err/.test(cls) || /fail/i.test(t) ? 'err' : /dirty/.test(cls) ? 'busy' : 'ok');
    dst.innerHTML = '<i class="fa-solid ' + (/err/.test(cls) || /fail/i.test(t) ? 'fa-triangle-exclamation' : /dirty/.test(cls) ? 'fa-rotate fa-spin' : 'fa-cloud') + '"></i> ' + esc(t || 'Saved');
  }
  var src0 = $('[data-save-state]'); if (src0 && window.MutationObserver) new MutationObserver(mirrorSave).observe(src0, { childList: true, characterData: true, subtree: true, attributes: true });

  window.SRXL = {
    html: html,
    row: function (g, r, i) { return row(g, r, i); },
    /** a typed reading: the worked-out cells in this sheet's number format, the sheet tab's tank count, the selection sums */
    updated: function (g, i, t) {
      ['vcf', 'gsv', 'mt'].forEach(function (c) { var e = $('.xl [data-rc="' + g + ':' + i + ':' + c + '"]'); if (e && !X.formulas) e.textContent = calcText(t, c); });
      var tab = $('.xl [data-xl-sheet="' + g + '"]'); if (tab) { var n = real(g); tab.textContent = I.gradeInfo(g).short + ' (' + n + ' Tank' + (n === 1 ? '' : 's') + ')'; }
      status();
    },
    state: X
  };
  // after each redraw of the step: the selection and the save state come back
  var form = $('[data-form]');
  if (form && window.MutationObserver) new MutationObserver(function () { if ($('.xl')) { mirrorSave(); restoreSel(); } }).observe(form, { childList: true });
})();
