/* Survey report calculation (ASTM Table 54B), the same steps and rounding as the report
   template's own TABLES 54B formulas — used for the on-screen preview and the offline Excel.
   The constants and decimals come from the template (includes/report_templates.php →
   rtCalcConfig); when the template's formulas were not recognised there is no config and
   nothing is calculated in the browser.

     ρ    = ROUND(density / 2 × 1000, 0) × 2                     (kg/m³, even number)
     α    = ROUND(K0 / ρ² + K1 / ρ, alpha_dp)
     VCF  = ROUND(EXP(−α·Δt·(1 + 0.8·α·Δt)), vcf_dp)            Δt = temp − base
     GSV  = ROUND(volume × VCF, gsv_dp)
     MT   = ROUND(GSV × (density − wcf), mt_dp)
   A row without a temperature has no result (the template's IF(G="","",...)). */
(function (root) {
  'use strict';
  // Excel ROUND: half away from zero, on the decimal value (not the binary float)
  function xround(v, d) {
    if (!isFinite(v)) return NaN;
    var s = v < 0 ? -1 : 1, a = Math.abs(v);
    var r = Number(Math.round(Number(a + 'e' + d)) + 'e-' + d);
    return s * r;
  }
  function num(v) {
    if (v === null || v === undefined) return null;
    var t = String(v).replace(/,/g, '').trim();
    return t !== '' && !isNaN(t) ? parseFloat(t) : null;
  }
  function tank(row, c) {
    var out = { vcf: null, gsv: null, mt: null };
    if (!c) return out;
    var temp = num(row.temp);
    if (row.temp === undefined || String(row.temp).trim() === '' || temp === null) return out;
    var F = num(row.density) || 0, E = num(row.volume) || 0;
    var rho = xround(F / 2 * 1000, 0) * 2;
    if (!rho) return out;
    var alpha = xround(c.k0 / (rho * rho) + (c.k1 / rho), c.alpha_dp);
    var dt = temp - c.base;
    out.vcf = xround(Math.exp(-alpha * dt * (1 + 0.8 * alpha * dt)), c.vcf_dp);
    out.gsv = xround(E * out.vcf, c.gsv_dp);
    out.mt = xround(out.gsv * (F - c.wcf), c.mt_dp);
    return out;
  }
  /** {tanks: {section: [{vcf, gsv, mt}]}, totals: {section: {gsv, mt}}} */
  function all(data, sections, c) {
    var res = { tanks: {}, totals: {} };
    sections.forEach(function (s) {
      var rows = (data.tanks && data.tanks[s]) || [], g = 0, m = 0;
      res.tanks[s] = rows.map(function (r) { var t = tank(r, c); if (t.gsv !== null) g += t.gsv; if (t.mt !== null) m += t.mt; return t; });
      res.totals[s] = { gsv: xround(g, 6), mt: xround(m, 6) };
    });
    return res;
  }
  var api = { tank: tank, all: all, round: xround, num: num };
  if (typeof module !== 'undefined' && module.exports) module.exports = api; else root.ReportCalc = api;
})(typeof self !== 'undefined' ? self : this);
