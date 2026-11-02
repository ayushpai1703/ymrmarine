<?php
/**
 * Invoice PDF template (Invoices module → Generate Invoice), rendered by dompdf.
 * A separate template: survey report templates and the Excel invoice templates
 * (Admin → Invoice Templates) are not touched. Company, bank and signatory details are the
 * ones printed on the existing "YMR Standard Invoice" Excel template.
 *
 * invoicePdfHtml($d) — $d keys: invoice_no, invoice_date (Y-m-d), client, client_addr1,
 * client_addr2, supplier_ref, other_ref, vessel, survey_type, port, survey_completed,
 * report_no, description, rate, quantity.
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

function invoicePdfMoney(float $v): string {
    return number_format($v, 2, '.', ',');
}

function invoicePdfHtml(array $d): string {
    $h = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
    $rate = (float)$d['rate'];
    $qty = (float)$d['quantity'];
    $amount = round($rate * $qty, 2);
    $date = $d['invoice_date'] !== '' && ($t = strtotime($d['invoice_date'])) ? strtoupper(date('d F Y', $t)) : '';
    $logo = __DIR__ . '/../assets/images/invoice-header.png';
    $logoSrc = is_file($logo) ? 'data:image/png;base64,' . base64_encode((string)file_get_contents($logo)) : '';
    $co = INVOICE_COMPANY;
    $bank = '';
    foreach ($co['bank'] as $k => $v) $bank .= '<tr><td class="bk">' . $h($k) . '</td><td class="bc">:</td><td>' . $h($v) . '</td></tr>';
    $vesselRows = '';
    foreach ([['Vessel', $d['vessel']], ['Survey Type', $d['survey_type']], ['Port', $d['port']], ['Survey Completed', $d['survey_completed']], ['Report No.', $d['report_no']]] as [$k, $v]) {
        if (trim((string)$v) !== '') $vesselRows .= '<tr><td class="vk">' . $h($k) . '</td><td>' . $h($v) . '</td></tr>';
    }
    $qtyTxt = rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.');
    return '<!doctype html><html><head><meta charset="utf-8"><style>
        @page { margin: 22px 28px 26px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10.5px; color: #0f172a; }
        .banner { width: 100%; }
        h1 { text-align: center; font-size: 16px; letter-spacing: 3px; margin: 10px 0 10px; }
        table { border-collapse: collapse; width: 100%; }
        .box td { border: 1px solid #94a3b8; padding: 6px 8px; vertical-align: top; }
        .lbl { font-size: 9px; color: #475569; text-transform: uppercase; letter-spacing: .5px; }
        .val { font-size: 11px; font-weight: bold; margin-top: 2px; }
        .co b { font-size: 11.5px; }
        .vd td { padding: 2px 0; } .vk { width: 110px; color: #475569; }
        .items th { background: #e6f6fc; border: 1px solid #94a3b8; padding: 6px; font-size: 10px; text-align: left; }
        .items td { border: 1px solid #94a3b8; padding: 8px 6px; vertical-align: top; }
        .num { text-align: right; white-space: nowrap; }
        .tot td { font-weight: bold; background: #f8fafc; }
        .bank td { padding: 1.5px 0; font-size: 9.5px; } .bk { width: 120px; font-weight: bold; } .bc { width: 10px; }
        .small { font-size: 8.5px; color: #475569; }
    </style></head><body>'
        . ($logoSrc ? '<img class="banner" src="' . $logoSrc . '">' : '')
        . '<h1>TAX INVOICE</h1>'
        . '<table class="box"><tr><td rowspan="2" style="width:52%" class="co"><b>' . $h($co['name']) . '</b><br>' . implode('<br>', array_map($h, $co['address'])) . '</td>'
        . '<td><div class="lbl">Invoice No.</div><div class="val">' . $h($d['invoice_no']) . '</div></td><td><div class="lbl">Dated</div><div class="val">' . $h($date) . '</div></td></tr>'
        . '<tr><td><div class="lbl">Supplier\'s Ref.</div><div class="val">' . $h($d['supplier_ref']) . '</div></td><td><div class="lbl">Other Reference(s)</div><div class="val">' . $h($d['other_ref']) . '</div></td></tr>'
        . '<tr><td><div class="lbl">Client</div><div class="val" style="font-size:12.5px">' . $h($d['client']) . '</div>'
        . ($d['client_addr1'] !== '' ? $h($d['client_addr1']) . '<br>' : '') . ($d['client_addr2'] !== '' ? $h($d['client_addr2']) : '') . '</td>'
        . '<td colspan="2"><div class="lbl">Vessel details</div><table class="vd">' . $vesselRows . '</table></td></tr></table>'
        . '<br><table class="items"><thead><tr><th style="width:28px">Sl.</th><th>Description of Services</th><th class="num" style="width:80px">Unit</th><th class="num" style="width:60px">Quantity</th><th class="num" style="width:95px">Amount</th></tr></thead><tbody>'
        . '<tr style="height:150px"><td>1</td><td>' . nl2br($h($d['description'])) . '</td><td class="num">' . invoicePdfMoney($rate) . '</td><td class="num">' . $h($qtyTxt) . '</td><td class="num">' . invoicePdfMoney($amount) . '</td></tr>'
        . '<tr class="tot"><td></td><td>Total</td><td></td><td></td><td class="num">' . invoicePdfMoney($amount) . '</td></tr></tbody></table>'
        . '<div class="small" style="text-align:right;margin-top:4px">E. &amp; O.E</div>'
        . '<p style="margin:14px 0 4px"><b><u>BANK DETAILS</u></b></p><table class="bank">' . $bank . '</table>'
        . '<p class="small" style="margin-top:14px">*We declare that this invoice shows the actual price of the goods described and that all particulars are true and correct.</p>'
        . '<table style="margin-top:10px"><tr><td><b>' . $h($co['for']) . '</b></td><td style="text-align:right;width:220px"><br><br><b>' . $h($co['signatory']) . '</b><br>Authorised Signatory</td></tr></table>'
        . '<p class="small" style="text-align:center;margin-top:16px">This is a Computer Generated Invoice</p>'
        . '</body></html>';
}
