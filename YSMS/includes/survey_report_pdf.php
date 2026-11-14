<?php
/**
 * Survey report PDF in YMR's own format (no company Excel layout): the YMR letterhead
 * (assets/images/survey-letterhead.png) on every page, then the values typed on the survey report
 * page: vessel particulars, survey dates and place, drafts, bunkers ROB as per log book, the
 * tank readings of each grade with V.C.F., G.S.V. and metric tons, the ROB summary, remarks and
 * signatures. Rendered by dompdf (installed by the Docker image, as for the invoices).
 *
 * The calculation methodology is not changed: V.C.F., G.S.V. and MT come from the report
 * template's own formulas (srBuildBook + srResults), exactly as in the Excel kept for the Word
 * report. Only the printed layout is YMR's.
 */
require_once __DIR__ . '/survey_reports.php';

/** dompdf ready on this server? */
function srOwnPdfAvailable(): bool {
    @include_once dirname(__DIR__) . '/vendor/autoload.php';
    return class_exists(\Dompdf\Dompdf::class);
}

/** Make the PDF of a report into $dest. → ['ok' => true] or ['ok' => false, 'error']. */
function srOwnPdf(array $tpl, array $report, string $dest): array {
    if (!srOwnPdfAvailable()) return ['ok' => false, 'error' => 'PDF engine (dompdf) is not installed on this server.'];
    try {
        [$book, $eff, , $where] = srBuildBook($tpl, $report['data']);
        $results = srResults($book, $eff, $report['data'], $where);
        $book->disconnectWorksheets();
        $html = srOwnPdfHtml($eff, $report, $tpl, $results);
        $opt = new \Dompdf\Options();
        $opt->set('isRemoteEnabled', false);
        $opt->set('isPhpEnabled', false);
        $opt->set('defaultFont', 'DejaVu Sans');
        $fontTmp = sys_get_temp_dir() . '/ysms-dompdf-fonts';
        if (!is_dir($fontTmp)) @mkdir($fontTmp, 0775, true);
        if (is_dir($fontTmp) && is_writable($fontTmp)) $opt->setFontCache($fontTmp);
        $pdf = new \Dompdf\Dompdf($opt);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4', 'portrait');
        $pdf->render();
        $c = $pdf->getCanvas(); // page numbers in the footer
        $c->page_text(520, 818, 'Page {PAGE_NUM} of {PAGE_COUNT}', $pdf->getFontMetrics()->getFont('DejaVu Sans'), 7, [0.39, 0.45, 0.55]);
        if (@file_put_contents($dest, $pdf->output()) === false) return ['ok' => false, 'error' => 'The PDF could not be saved.'];
        return ['ok' => true];
    } catch (\Throwable $e) {
        error_log('survey report pdf: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'The PDF could not be made. Please try again.'];
    }
}

function srPdfE($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function srPdfNum($v, int $dec = 3): string { return $v === null || $v === '' || !is_numeric($v) ? '—' : number_format((float)$v, $dec, '.', ','); }
/** A tank reading as it is shown on the report (0.000 m, 0.0000, 00.0 °C), text such as GAUGE as typed. */
function srPdfReading(string $col, string $v): string {
    $n = str_replace(',', '', $v);
    if ($v === '') return '—';
    if (!is_numeric($n)) return strtoupper($v);
    $dec = ['ref_height' => 3, 'sounding' => 3, 'volume' => 3, 'density' => 4, 'temp' => 1][$col] ?? null;
    return $dec === null ? $v : number_format((float)$n, $dec, '.', ',');
}

/** The HTML dompdf prints. */
function srOwnPdfHtml(array $eff, array $report, array $tpl, array $results): string {
    $data = $report['data'];
    $e = 'srPdfE';
    $val = fn($k) => srVal($data, $k);
    $label = fn($k) => $eff['fields'][$k]['label'] ?? (srExtraFieldDefs()[$k][0] ?? (SF_FIELDS[$k][0] ?? $k));
    $when = function (string $p) use ($val) {
        $d = $val($p . '_date'); $t = $val($p . '_time');
        $s = trim(($d !== '' ? sfDateText($d) : '') . ($t !== '' ? '  ' . $t . ' Hrs LT' : ''));
        return $s;
    };
    $kv = function (array $rows) use ($e) { // [[label, value], …] in two columns
        $rows = array_values(array_filter($rows, fn($r) => trim((string)$r[1]) !== ''));
        if (!$rows) return '';
        $h = '<table class="kv">';
        for ($i = 0; $i < count($rows); $i += 2) {
            $h .= '<tr>';
            foreach ([$rows[$i], $rows[$i + 1] ?? null] as $r) $h .= $r ? '<th>' . $e($r[0]) . '</th><td>' . $e($r[1]) . '</td>' : '<th></th><td></td>';
            $h .= '</tr>';
        }
        return $h . '</table>';
    };
    $sec = fn(string $title, string $body, bool $keep = true) => $body === '' ? '' : '<div class="sec' . ($keep ? ' keep' : '') . '"><h2>' . srPdfE($title) . '</h2>' . $body . '</div>'; // a short section never splits over two pages
    $grades = srChosenGrades($eff, $data);

    // vessel
    $vesselKeys = ['vessel_name', 'imo', 'flag', 'port_of_registry', 'callsign', 'class', 'grt', 'nrt', 'summer_dwt', 'summer_draft', 'loa', 'breadth', 'depth', 'holds', 'builder', 'keel_month', 'keel_year', 'voyage_no'];
    $rows = [];
    foreach ($vesselKeys as $k) {
        $v = $val($k);
        if ($k === 'keel_month' || $k === 'keel_year') continue;
        $rows[] = [rtrim(preg_replace('/\s*\((m)\)$/', ' (m)', $label($k))), $v];
    }
    $keel = trim($val('keel_month') . ' ' . $val('keel_year'));
    if ($keel !== '') $rows[] = ['Keel laid', $keel];
    $vessel = $kv($rows);
    $parties = $kv(array_map(fn($k) => [$label($k), $val($k)], ['owners', 'operator', 'charterers', 'next_charterer']));
    // survey
    $survey = $kv([['Port', $val('port_name')], ['Place of survey', $val('place')], ['FWE', $when('fwe')], ['Alongside', $val('alongside')],
        ['Survey started', $when('started')], ['Survey completed', $when('completed')],
        ['Place of redelivery', $val('redelivery_place')], ['Redelivery', trim(($val('redelivery_date') !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $val('redelivery_date')) ? sfDateText($val('redelivery_date')) : $val('redelivery_date')) . ' ' . $val('redelivery_time'))]]);
    // drafts
    $fa = is_numeric($val('draft_aft')) ? (float)$val('draft_aft') : null; $ff = is_numeric($val('draft_fwd')) ? (float)$val('draft_fwd') : null;
    $drafts = $kv([['Draft forward (m)', $ff === null ? '' : srPdfNum($ff)], ['Draft aft (m)', $fa === null ? '' : srPdfNum($fa)],
        ['Mean draft (m)', $fa !== null && $ff !== null ? srPdfNum(round(($fa + $ff) / 2, 3)) : ''], ['Trim (m)', $fa !== null && $ff !== null ? srPdfNum(round($fa - $ff, 3)) : ''],
        ['List (degrees)', is_numeric($val('list')) ? srPdfNum($val('list'), 2) : '']]);
    // bunkers ROB as per log book
    $brob = '';
    $brows = '';
    foreach ($grades as $g) {
        $a = $val('brob_fwe_' . $g); $b = $val('brob_started_' . $g);
        if ($a === '' && $b === '') continue;
        $brows .= '<tr><td class="l">' . $e(SR_GRADES[$g][0]) . '</td><td>' . srPdfNum($a) . '</td><td>' . srPdfNum($b) . '</td></tr>';
    }
    if ($brows !== '') $brob = '<table class="grid"><thead><tr><th class="l">Grade</th><th>ROB at FWE (MT)</th><th>ROB at survey start (MT)</th></tr></thead><tbody>' . $brows . '</tbody></table>';
    // tanks
    $tanks = '';
    foreach ($grades as $g) {
        $rowsG = srTankRows($data, $g);
        if (!$rowsG) continue;
        $res = $results['tanks'][$g] ?? []; $tot = $results['grades'][$g] ?? [];
        $h = '<table class="grid tk"><thead><tr><th>#</th><th class="l">Tank</th><th>Ref. Ht<br><small>(m)</small></th><th>Sounding<br><small>(m)</small></th><th>Volume<br><small>(m³)</small></th><th>Density<br><small>@15°C</small></th><th>Temp<br><small>(°C)</small></th><th>V.C.F.</th><th>G.S.V.<br><small>(m³ @15°C)</small></th><th>MT<br><small>(in air)</small></th></tr></thead><tbody>';
        foreach ($rowsG as $i => $r) {
            $x = $res[$i] ?? [];
            $h .= '<tr><td>' . ($i + 1) . '</td><td class="l">' . $e(strtoupper($r['name'])) . '</td>';
            foreach (['ref_height', 'sounding', 'volume', 'density', 'temp'] as $c) $h .= '<td>' . $e(srPdfReading($c, $r[$c])) . '</td>';
            $h .= '<td>' . srPdfNum($x['vcf'] ?? null, 4) . '</td><td>' . srPdfNum($x['gsv'] ?? null) . '</td><td class="b">' . srPdfNum($x['mt'] ?? null) . '</td></tr>';
        }
        $h .= '</tbody><tfoot><tr><td colspan="8" class="r">TOTAL ' . $e(SR_GRADES[$g][0]) . '</td><td>' . srPdfNum($tot['gsv'] ?? null) . '</td><td>' . srPdfNum($tot['completed'] ?? null) . '</td></tr></tfoot></table>';
        $tanks .= '<div class="grade"><h3>' . $e(SR_GRADES[$g][1]) . ' (' . $e(SR_GRADES[$g][0]) . ')</h3>' . $h . '</div>';
    }
    // ROB summary
    $sum = '';
    $srows = '';
    foreach ($grades as $g) {
        if (!isset($results['grades'][$g]) || !srTankRows($data, $g)) continue;
        $x = $results['grades'][$g];
        $d = $x['difference'];
        $srows .= '<tr><td class="l">' . $e(SR_GRADES[$g][0]) . '</td><td>' . srPdfNum($x['completed']) . '</td><td>' . srPdfNum($x['logbook']) . '</td><td class="b">' . ($d === null ? '—' : ($d > 0 ? '+' : '') . srPdfNum($d)) . '</td></tr>';
    }
    if ($srows !== '') {
        $td = $results['total_difference'] ?? 0;
        $sum = '<table class="grid sum"><thead><tr><th class="l">Fuel grade</th><th>Survey completed ROB (MT)</th><th>Log book ROB (MT)</th><th>Difference (MT)</th></tr></thead><tbody>' . $srows . '</tbody>' .
            '<tfoot><tr><td colspan="3" class="r">TOTAL DIFFERENCE</td><td>' . ($td > 0 ? '+' : '') . srPdfNum($td) . '</td></tr></tfoot></table>' .
            '<p class="note">Survey completed ROB = sum of the metric tons (in air) of the tanks of each grade. Log book ROB = ROB at the survey start as per the engine-room log book. Difference = Survey completed − Log book.</p>';
    }
    // remarks
    $rem = '';
    foreach (['remarks', 'offhire_remark'] as $k) if ($val($k) !== '') $rem .= '<p class="rem">' . nl2br($e($val($k))) . '</p>';
    // signatures
    $sig = '<table class="sig"><tr>' .
        '<td><div class="ln"></div><b>The Master</b><br>' . ($val('master') !== '' ? 'Capt. ' . $e($val('master')) : '&nbsp;') . '<br><small>' . $e(strtoupper($val('vessel_name'))) . '</small></td>' .
        '<td><div class="ln"></div><b>The Chief Engineer</b><br>' . ($val('chief_engineer') !== '' ? 'Mr. ' . $e($val('chief_engineer')) : '&nbsp;') . '<br><small>&nbsp;</small></td>' .
        '<td><div class="ln"></div><b>For YMR MARINE SOLUTIONS LLP</b><br>' . ($val('surveyor_name') !== '' ? 'Mr. ' . $e($val('surveyor_name')) : '&nbsp;') . '<br><small>Independent Marine Surveyor</small></td>' .
        '</tr></table>';

    $kind = RT_KINDS[$tpl['kind']] ?? $tpl['kind'];
    $title = 'BUNKER SURVEY REPORT';
    $sub = strtoupper($kind) . ($val('vessel_name') !== '' ? ' — ' . strtoupper($val('vessel_name')) : '');
    $date = $val('completed_date') !== '' ? sfDateText($val('completed_date')) : sfDateText(date('Y-m-d'));
    $lh = is_file(SR_LETTERHEAD) ? 'data:image/png;base64,' . base64_encode((string)file_get_contents(SR_LETTERHEAD)) : '';

    return '<!doctype html><html><head><meta charset="utf-8"><style>
@page { margin: 47mm 12mm 16mm 12mm; }
body { font-family: "DejaVu Sans", sans-serif; font-size: 8.6pt; color: #0f172a; }
#lh { position: fixed; top: -43mm; left: 0; right: 0; text-align: center; }
#lh img { width: 172mm; }
#ft { position: fixed; bottom: -11mm; left: 0; right: 0; font-size: 7pt; color: #64748b; border-top: 0.5pt solid #cbd5e1; padding-top: 3pt; }
.ttl { text-align: center; margin: 0 0 8pt; }
.ttl h1 { font-size: 14pt; margin: 0; color: #0b3c7a; letter-spacing: 1pt; }
.ttl div { font-size: 9.5pt; font-weight: bold; color: #1e3a8a; margin-top: 2pt; }
.meta { width: 100%; font-size: 8pt; color: #475569; margin-bottom: 6pt; }
.meta td.r { text-align: right; }
.sec { margin: 0 0 9pt; }
.keep { page-break-inside: avoid; }
h2, h3 { page-break-after: avoid; }
h2 { font-size: 9.5pt; color: #fff; background: #3d8bd6; margin: 0 0 4pt; padding: 3pt 6pt; text-transform: uppercase; letter-spacing: .5pt; }
h3 { font-size: 9pt; color: #0b3c7a; margin: 6pt 0 3pt; }
table { border-collapse: collapse; width: 100%; }
.kv th { width: 19%; text-align: left; font-weight: bold; color: #334155; background: #f1f6fc; border: 0.5pt solid #cbd5e1; padding: 3pt 5pt; vertical-align: top; }
.kv td { width: 31%; border: 0.5pt solid #cbd5e1; padding: 3pt 5pt; vertical-align: top; }
.grid th { background: #e3eefb; color: #0b3c7a; font-weight: bold; border: 0.5pt solid #94a3b8; padding: 3pt 3pt; text-align: center; font-size: 7.8pt; }
.grid th small { font-weight: normal; color: #475569; font-size: 6.8pt; }
.grid td { border: 0.5pt solid #cbd5e1; padding: 2.6pt 4pt; text-align: right; }
.grid .l { text-align: left; } .grid .r { text-align: right; } .grid .b { font-weight: bold; }
.grid tbody tr:nth-child(even) td { background: #f8fafc; }
.grid tfoot td { font-weight: bold; background: #e8f3fd; border: 0.5pt solid #94a3b8; }
.tk td:first-child { text-align: center; color: #64748b; width: 4%; }
.grade { page-break-inside: auto; }
.sum td, .sum th { font-size: 8.6pt; }
.note { font-size: 7pt; color: #64748b; font-style: italic; margin: 3pt 0 0; }
.rem { margin: 0 0 4pt; line-height: 1.45; text-align: justify; }
.sig { margin-top: 26pt; page-break-inside: avoid; }
.sig td { width: 33.3%; padding: 0 8pt; vertical-align: top; font-size: 8.4pt; }
.sig .ln { border-top: 0.6pt solid #0f172a; margin: 0 0 3pt; height: 1pt; }
.sig small { color: #64748b; }
</style></head><body>' .
        ($lh ? '<div id="lh"><img src="' . $lh . '" alt="YMR Marine Solutions"></div>' : '') .
        '<div id="ft">' . $e($sub) . ' · Generated by YMR Marine YSMS on ' . $e(date('d M Y H:i')) . '</div>' .
        '<div class="ttl"><h1>' . $title . '</h1><div>' . $e($sub) . '</div></div>' .
        '<table class="meta"><tr><td>Report type: <b>' . $e($kind) . '</b></td><td class="r">Date: <b>' . $e($date) . '</b></td></tr></table>' .
        $sec('Vessel particulars', $vessel) . $sec('Owners & charterers', $parties) . $sec('Survey details', $survey) .
        $sec('Drafts & list', $drafts) . $sec('Bunkers ROB as per log book', $brob) .
        $sec('Tank soundings & quantities (Table 54B)', $tanks, false) . $sec('ROB summary', $sum) . $sec('Remarks', $rem) . $sig .
        '</body></html>';
}
