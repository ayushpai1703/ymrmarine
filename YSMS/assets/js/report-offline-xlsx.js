/* Survey report Excel made in the browser (works offline) with ExcelJS, from the same template
   workbook and the same layout data the server uses (includes/survey_reports.php →
   srEditorConfig().offline). Mirrors rtBuild(): values into their cells, extra tank rows inserted
   inside their section (row formatting copied), the template's tank formulas on every tank row,
   section totals as SUM over the section, references from other sheets moved with the rows.
   Calculated results are stored with the formulas (report-calc.js) and Excel recalculates on open.

   Not kept by ExcelJS (shown to the user): drawing shapes such as the arrows on SHIP DETAILS,
   printer driver settings, defined names that point at #REF!. */
(function (root) {
  'use strict';
  var MONTHS = ['JANUARY', 'FEBRUARY', 'MARCH', 'APRIL', 'MAY', 'JUNE', 'JULY', 'AUGUST', 'SEPTEMBER', 'OCTOBER', 'NOVEMBER', 'DECEMBER'];
  function dateText(ymd) { // "2026-10-01" → "1st OCTOBER 2026" (the template's own style, as sfDateText)
    var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(ymd || ''); if (!m) return ymd;
    var d = +m[3], suf = (d % 100 >= 11 && d % 100 <= 13) ? 'th' : ['th', 'st', 'nd', 'rd', 'th', 'th', 'th', 'th', 'th', 'th'][d % 10];
    return d + suf + ' ' + MONTHS[+m[2] - 1] + ' ' + m[1];
  }
  function excelDate(ymd) { var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(ymd); return m ? (Date.UTC(+m[1], +m[2] - 1, +m[3]) / 86400000) + 25569 : null; }
  function colNum(c) { var n = 0; for (var i = 0; i < c.length; i++) n = n * 26 + c.charCodeAt(i) - 64; return n; }
  function splitAddr(a) { var m = /^([A-Z]{1,3})(\d+)$/.exec(a); return { col: m[1], row: +m[2] }; }
  function quoteSheet(n) { return "'" + n.replace(/'/g, "''") + "'"; }

  /** Move row numbers >= at by n in a formula; only references into `target` (unqualified ones too when `same`). Text in quotes is left alone. */
  function shiftFormula(f, target, same, at, n) {
    var parts = f.split('"');
    for (var i = 0; i < parts.length; i += 2) {
      parts[i] = parts[i].replace(/((?:'(?:[^']|'')+'|[A-Za-z0-9_.]+)!)?(\$?)([A-Z]{1,3})(\$?)(\d+)(?![\d(])/g, function (all, sheet, d1, col, d2, row) {
        var name = sheet ? sheet.slice(0, -1).replace(/^'|'$/g, '').replace(/''/g, "'") : null;
        var hit = name ? name === target : same;
        if (!hit || +row < at) return all;
        return (sheet || '') + d1 + col + d2 + (+row + n);
      });
    }
    return parts.join('"');
  }

  function insertRows(wb, ws, at, n) {
    var name = ws.name;
    // merges at or below the insert point move down
    var moved = [];
    Object.keys(ws._merges).forEach(function (k) {
      var r = ws._merges[k].model; if (r.top >= at) { moved.push(r); }
    });
    moved.forEach(function (r) { ws.unMergeCells(r.top, r.left, r.bottom, r.right); });
    var blank = []; for (var i = 0; i < n; i++) blank.push([]);
    ws.spliceRows.apply(ws, [at, 0].concat(blank));
    moved.forEach(function (r) { ws.mergeCells(r.top + n, r.left, r.bottom + n, r.right); });
    // formatting of the new rows = the row above (the last tank row)
    var src = ws.getRow(at - 1);
    for (var k = 0; k < n; k++) {
      var row = ws.getRow(at + k); row.height = src.height;
      src.eachCell({ includeEmpty: true }, function (c, col) { row.getCell(col).style = JSON.parse(JSON.stringify(c.style || {})); });
    }
    // formulas everywhere
    wb.worksheets.forEach(function (w) {
      w.eachRow({ includeEmpty: false }, function (r) {
        r.eachCell({ includeEmpty: false }, function (c) {
          if (c.type !== 6 /* Formula */) return;
          var nf = shiftFormula(c.formula, name, w === ws, at, n);
          if (nf !== c.formula) c.value = { formula: nf, result: c.result };
        });
      });
    });
    // print area
    if (ws.pageSetup && ws.pageSetup.printArea) {
      ws.pageSetup.printArea = ws.pageSetup.printArea.replace(/(\$?[A-Z]{1,3}\$?)(\d+)/g, function (a, c, r) { return +r >= at ? c + (+r + n) : a; });
    }
    // images anchored below
    (ws.getImages() || []).forEach(function (im) {
      ['tl', 'br'].forEach(function (p) { var a = im.range[p]; if (a && a.nativeRow !== undefined && a.nativeRow >= at - 1) a.nativeRow += n; });
    });
  }

  /** Results of simple formulas (a reference, A−B, CONCATENATE of text and references) so viewers that don't recalculate show them. */
  function settle(wb) {
    var val = function (w, addr) { var c = w.getCell(addr); var v = c.type === 6 ? c.result : c.value; return v && v.richText ? v.richText.map(function (t) { return t.text; }).join('') : v; };
    var ref = function (w, s) {
      var m = /^(?:('(?:[^']|'')+'|[A-Za-z0-9_.]+)!)?\$?([A-Z]{1,3})\$?(\d+)$/.exec(s.trim()); if (!m) return undefined;
      var ws = m[1] ? wb.getWorksheet(m[1].replace(/^'|'$/g, '').replace(/''/g, "'")) : w; if (!ws) return undefined;
      return val(ws, m[2] + m[3]);
    };
    for (var pass = 0; pass < 3; pass++) wb.worksheets.forEach(function (w) {
      w.eachRow({ includeEmpty: false }, function (r) {
        r.eachCell({ includeEmpty: false }, function (c) {
          if (c.type !== 6) return;
          var f = c.formula.replace(/^=/, ''), res, m;
          if ((res = ref(w, f)) !== undefined) {}
          else if ((m = /^([^-+*/(),]+)-([^-+*/(),]+)$/.exec(f))) { var a = ref(w, m[1]), b = ref(w, m[2]); if (typeof a === 'number' && typeof b === 'number') res = Math.round((a - b) * 1e9) / 1e9; }
          else if ((m = /^CONCATENATE\((.*)\)$/i.exec(f))) {
            var args = m[1].match(/"(?:[^"]|"")*"|[^,]+/g) || [], out = '';
            for (var i = 0; i < args.length; i++) {
              var x = args[i].trim();
              if (x[0] === '"') out += x.slice(1, -1).replace(/""/g, '"');
              else { var v = ref(w, x); if (v === undefined || v === null || /#REF!/.test(x)) { out = undefined; break; } out += String(v); }
            }
            res = out;
          } else return;
          if (res !== undefined && res !== null) c.value = { formula: c.formula, result: res };
        });
      });
    });
  }

  function build(buf, off, data, calcCfg, sectionKeys) {
    var wb = new root.ExcelJS.Workbook();
    return wb.xlsx.load(buf).then(function () {
      // shared formulas → each cell its own formula (rows are inserted below)
      wb.worksheets.forEach(function (w) { w.eachRow(function (r) { r.eachCell(function (c) { if (c.type === 6 && c.value.sharedFormula) c.value = { formula: c.formula, result: c.result }; }); }); });
      var t = wb.getWorksheet(off.tables_sheet);
      var tanks = {}; // the same rows the server keeps: empty rows dropped
      sectionKeys.forEach(function (s) { tanks[s] = ((data.tanks || {})[s] || []).filter(function (r) { return Object.keys(r).some(function (k) { return String(r[k] || '').trim() !== ''; }); }); });
      var calc = root.ReportCalc.all({ tanks: tanks }, sectionKeys, calcCfg);
      var secs = sectionKeys.map(function (k) { return { key: k, s: off.sections[k] }; }).sort(function (a, b) { return b.s.first - a.s.first; });
      var inserted = [];
      var gsvC = off.sections[sectionKeys[0]].calc_cols.gsv, mtC = off.sections[sectionKeys[0]].calc_cols.mt;
      secs.forEach(function (x) {
        var sec = x.s, rows = tanks[x.key], first = sec.first, cap = sec.last - first + 1, add = Math.max(0, rows.length - cap);
        if (add) { insertRows(wb, t, sec.last + 1, add); inserted.push([sec.last + 1, add]); }
        var last = first + Math.max(cap, rows.length) - 1;
        for (var r = first; r <= last; r++) {
          var row = rows[r - first] || {}, res = (calc.tanks[x.key] || [])[r - first] || {};
          Object.keys(sec.cols).forEach(function (k) {
            var v = String(row[k] == null ? '' : row[k]).trim(), cell = t.getCell(sec.cols[k] + r);
            cell.value = v === '' ? null : (k !== 'name' && /^-?\d+(\.\d+)?$/.test(v) ? parseFloat(v) : v);
          });
          Object.keys(off.pattern).forEach(function (col) {
            var f = off.pattern[col].replace(/\{r\}/g, r).replace(/^=/, ''), result;
            if (col === sec.calc_cols.vcf) result = res.vcf; else if (col === sec.calc_cols.gsv) result = res.gsv; else if (col === sec.calc_cols.mt) result = res.mt;
            t.getCell(col + r).value = { formula: f, result: result === null || result === undefined ? '' : result };
          });
        }
        var tot = calc.totals[x.key] || { gsv: 0, mt: 0 };
        t.getCell(gsvC + (sec.gsv_total + add)).value = { formula: 'SUM(' + gsvC + first + ':' + gsvC + last + ')', result: tot.gsv };
        t.getCell(mtC + (sec.mt_total + add)).value = { formula: 'SUM(' + mtC + first + ':' + mtC + last + ')', result: tot.mt };
      });
      Object.keys(off.fields).forEach(function (k) {
        var f = off.fields[k], ws = wb.getWorksheet(f.sheet); if (!ws) return;
        var a = splitAddr(f.cell), row = a.row;
        if (ws === t) inserted.forEach(function (ins) { if (a.row >= ins[0]) row += ins[1]; });
        var cell = ws.getCell(a.col + row), v = String((data.fields || {})[k] == null ? '' : data.fields[k]).trim();
        if (v === '') { cell.value = null; return; }
        if (f.type === 'date') cell.value = dateText(v);
        else if (f.type === 'xdate') cell.value = excelDate(v);
        else if (f.type === 'time') { var hm = v.split(':'); cell.value = (+hm[0] * 60 + +hm[1]) / 1440; if (!/[hms]/i.test(cell.numFmt || '')) cell.numFmt = 'h:mm'; }
        else if (f.type === 'int' || f.type === 'imo') cell.value = parseInt(v, 10);
        else if (/^num:/.test(f.type)) cell.value = parseFloat(v);
        else cell.value = v;
      });
      settle(wb);
      wb.calcProperties = wb.calcProperties || {};
      wb.calcProperties.fullCalcOnLoad = true;
      return wb.xlsx.writeBuffer();
    }).then(function (out) { return new Blob([out], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' }); });
  }

  root.ReportOfflineXlsx = { build: build, shiftFormula: shiftFormula, dateText: dateText };
})(typeof self !== 'undefined' ? self : this);
