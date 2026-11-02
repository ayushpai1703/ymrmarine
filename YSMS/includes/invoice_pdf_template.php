<?php
/**
 * Invoice PDF template (Invoices module → Generate Invoice / Recovery Invoice), rendered by
 * dompdf on the YMR letterhead (assets/images/invoice-letterhead.jpg, full page). A separate
 * template: survey report templates and the Excel invoice templates (Admin → Invoice
 * Templates) are not touched. Company, bank and signatory details are the ones printed on the
 * existing "YMR Standard Invoice" Excel template.
 *
 * invoicePdfHtml($d) — $d keys: invoice_no, invoice_date (Y-m-d), client, client_addr1,
 * client_addr2, supplier_ref, other_ref, vessel, survey_type, port, survey_completed,
 * report_no, note (optional description line shown above the items, no amount),
 * items: [['desc' => …, 'rate' => float, 'qty' => float], …]; each amount is rate × qty.
 *
 * The signatory's name is also printed as a handwritten-style signature, in the font from
 * assets/fonts/signature/ (DannyBrassco.ttf/.otf when its licensed file is placed there,
 * otherwise the free Great Vibes — see the README in that folder).
 */

const INVOICE_COMPANY = [
    'name' => 'YMR MARINE SOLUTIONS',
    'address' => ['A2605 RUNWAL ELEGANTE', 'LOKHANDWALA, ANDHERI WEST', 'MUMBAI - 400 053'],
    'bank' => [
        'ACCOUNT NAME' => 'YMR MARINE SOLUTIONS LLP',
        'BANK NAME' => 'HDFC BANK',
        'BRANCH NAME' => 'JAWAHAR NAGAR BRANCH, GOREGAON WEST, MUMBAI',
        'CURRENT AC NO.' => '50200119071532',
        'IFSC NO.' => 'HDFC0000322',
        'SWIFT CODE' => 'HDFCINBBXXX',
        'COMPANY PAN' => 'AADFY9259Q',
        'GST NUMBER' => '27AADFY9259Q1Z1',
    ],
    'for' => 'FOR YMR MARINE SOLUTIONS LLP',
    'signatory' => 'Gaurav Yadav',
];

/** Signature font file (absolute path) — Danny Brassco when its licensed file is present. */
function invoicePdfSignatureFont(): ?string {
    $dir = dirname(__DIR__) . '/assets/fonts/signature/';
    foreach (['DannyBrassco.ttf', 'DannyBrassco.otf', 'GreatVibes-Regular.ttf'] as $f) {
        if (is_file($dir . $f)) return $dir . $f;
    }
    return null;
}

function invoicePdfMoney(float $v): string {
    return number_format($v, 2, '.', ',');
}

/** Amount of one invoice line. */
function invoicePdfLineAmount(array $it): float {
    return round((float)$it['rate'] * (float)$it['qty'], 2);
}

function invoicePdfHtml(array $d): string {
    $h = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
    $num = function (float $v, int $dec = 3) { return rtrim(rtrim(number_format($v, $dec, '.', ','), '0'), '.'); };
    $itemRows = '';
    $total = 0.0;
    if (trim((string)($d['note'] ?? '')) !== '') {
        $itemRows .= '<tr><td></td><td colspan="4" class="note">' . nl2br($h($d['note'])) . '</td></tr>';
    }
    foreach (array_values($d['items']) as $n => $it) {
        $amt = invoicePdfLineAmount($it);
        $total += $amt;
        $itemRows .= '<tr><td>' . ($n + 1) . '</td><td>' . nl2br($h($it['desc'])) . '</td><td class="num">' . invoicePdfMoney((float)$it['rate'])
            . '</td><td class="num">' . $h($num((float)$it['qty'])) . '</td><td class="num">' . invoicePdfMoney($amt) . '</td></tr>';
    }
    $sigFont = invoicePdfSignatureFont();
    $sigCss = $sigFont ? "@font-face { font-family: 'InvSign'; src: url('" . str_replace("'", '', $sigFont) . "') format('truetype'); font-weight: normal; font-style: normal; }" : '';
    $date = $d['invoice_date'] !== '' && ($t = strtotime($d['invoice_date'])) ? strtoupper(date('d F Y', $t)) : '';
    $lh = dirname(__DIR__) . '/assets/images/invoice-letterhead.jpg';
    $lhSrc = is_file($lh) ? 'data:image/jpeg;base64,' . base64_encode((string)file_get_contents($lh)) : '';
    $co = INVOICE_COMPANY;
    $bank = '';
    foreach ($co['bank'] as $k => $v) $bank .= '<tr><td class="bk">' . $h($k) . '</td><td class="bc">:</td><td>' . $h($v) . '</td></tr>';
    $vesselRows = '';
    foreach ([['Vessel', $d['vessel']], ['Survey Type', $d['survey_type']], ['Port', $d['port']], ['Survey Completed', $d['survey_completed']], ['Report No.', $d['report_no']]] as [$k, $v]) {
        if (trim((string)$v) !== '') $vesselRows .= '<tr><td class="vk">' . $h($k) . '</td><td>' . $h($v) . '</td></tr>';
    }
    // The letterhead fills the page; @page margins keep the text clear of its header (top),
    // ship / wave footer (bottom) and edges, on every page.
    return '<!doctype html><html><head><meta charset="utf-8"><style>' . $sigCss . '
        @page { margin: 46mm 14mm 30mm 14mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9.5px; color: #0f172a; }
        .lh { position: fixed; left: -14mm; top: -46mm; width: 210mm; height: 297mm; z-index: -1; }
        .sig { font-family: InvSign, DejaVu Sans, sans-serif; font-size: 28px; color: #1e3a8a; line-height: 1; margin: 0 0 2px; }
        h1 { text-align: center; font-size: 14px; letter-spacing: 3px; margin: 0 0 8px; color: #0f2b5b; }
        table { border-collapse: collapse; width: 100%; }
        .box td { border: 1px solid #94a3b8; padding: 5px 7px; vertical-align: top; background: rgba(255,255,255,.85); }
        .lbl { font-size: 8px; color: #475569; text-transform: uppercase; letter-spacing: .5px; }
        .val { font-size: 10px; font-weight: bold; margin-top: 2px; }
        .co b { font-size: 10.5px; }
        .vd td { padding: 1.5px 0; border: 0 !important; background: none !important; } .vk { width: 100px; color: #475569; }
        .items th { background: #e6f6fc; border: 1px solid #94a3b8; padding: 5px; font-size: 9px; text-align: left; }
        .items td { border: 1px solid #94a3b8; padding: 6px 5px; vertical-align: top; background: rgba(255,255,255,.85); }
        .items td.note { font-style: italic; color: #334155; }
        .num { text-align: right; white-space: nowrap; }
        .tot td { font-weight: bold; background: #f1f5f9; }
        .bank td { padding: 1px 0; font-size: 8.5px; } .bk { width: 110px; font-weight: bold; } .bc { width: 10px; }
        .small { font-size: 8px; color: #475569; }
    </style></head><body>'
        . ($lhSrc ? '<img class="lh" src="' . $lhSrc . '">' : '')
        . '<h1>' . $h($d['title'] ?? 'TAX INVOICE') . '</h1>'
        . '<table class="box"><tr><td rowspan="2" style="width:52%" class="co"><b>' . $h($co['name']) . '</b><br>' . implode('<br>', array_map($h, $co['address'])) . '</td>'
        . '<td><div class="lbl">Invoice No.</div><div class="val">' . $h($d['invoice_no']) . '</div></td><td><div class="lbl">Dated</div><div class="val">' . $h($date) . '</div></td></tr>'
        . '<tr><td><div class="lbl">Supplier\'s Ref.</div><div class="val">' . $h($d['supplier_ref']) . '</div></td><td><div class="lbl">Other Reference(s)</div><div class="val">' . $h($d['other_ref']) . '</div></td></tr>'
        . '<tr><td><div class="lbl">Client</div><div class="val" style="font-size:11.5px">' . $h($d['client']) . '</div>'
        . ($d['client_addr1'] !== '' ? $h($d['client_addr1']) . '<br>' : '') . ($d['client_addr2'] !== '' ? $h($d['client_addr2']) : '') . '</td>'
        . '<td colspan="2"><div class="lbl">Vessel details</div><table class="vd">' . $vesselRows . '</table></td></tr></table>'
        . '<br><table class="items"><thead><tr><th style="width:24px">Sl.</th><th>Description of Services</th><th class="num" style="width:75px">Unit (USD)</th><th class="num" style="width:55px">Quantity</th><th class="num" style="width:85px">Amount (USD)</th></tr></thead><tbody>'
        . $itemRows
        . '<tr class="tot"><td></td><td>Total (USD)</td><td></td><td></td><td class="num">$' . invoicePdfMoney($total) . '</td></tr></tbody></table>'
        . '<div class="small" style="text-align:right;margin-top:3px">E. &amp; O.E</div>'
        . '<p style="margin:8px 0 3px"><b><u>BANK DETAILS</u></b></p><table class="bank">' . $bank . '</table>'
        . '<p class="small" style="margin-top:8px">*We declare that this invoice shows the actual price of the goods described and that all particulars are true and correct.</p>'
        . '<table style="margin-top:4px;page-break-inside:avoid"><tr><td style="vertical-align:bottom"><b>' . $h($co['for']) . '</b></td><td style="text-align:right;width:230px"><div class="sig">' . $h($co['signatory']) . '</div><b>' . $h($co['signatory']) . '</b><br>Authorised Signatory</td></tr></table>'
        . '<p class="small" style="text-align:center;margin-top:8px">This is a Computer Generated Invoice</p>'
        . '</body></html>';
}
