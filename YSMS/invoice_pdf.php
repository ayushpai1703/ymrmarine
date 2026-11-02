<?php
/**
 * Invoices → Generate Invoice: a form pre-filled from the vessel's survey record, a live PDF
 * preview and the PDF download. Admin only. Template: includes/invoice_pdf_template.php.
 * Reads survey/client data only; nothing is written.
 */
require_once 'config/config.php';
require_once 'includes/invoice_helpers.php';
require_once 'includes/invoice_pdf_template.php';
checkAuth();
if (!invoiceCanManage()) { header('Location: index.php'); exit; }

$db = getDB();
invoiceEnsureTable($db);
$id = (int)($_REQUEST['id'] ?? 0);
$st = $db->prepare(invoiceRowSql() . ' WHERE i.id = ?');
$st->execute([$id]);
$inv = $st->fetch(PDO::FETCH_ASSOC);
if (!$inv) { http_response_code(404); exit('Invoice not found.'); }

$addr1 = $addr2 = '';
if (!empty($inv['sid'])) {
    try { // address columns are added by invoice_generator.php / migration_client_address.sql
        $a = $db->prepare('SELECT c.address_line1, c.address_line2 FROM surveys s JOIN clients c ON c.id = s.client_id WHERE s.id = ?');
        $a->execute([(int)$inv['sid']]);
        if ($ar = $a->fetch(PDO::FETCH_ASSOC)) { $addr1 = (string)$ar['address_line1']; $addr2 = (string)$ar['address_line2']; }
    } catch (Throwable $e) { /* no address columns yet */ }
}
$vessel = (string)($inv['s_vessel_name'] ?? $inv['vessel_name'] ?? '');
$type = !empty($inv['sid']) ? getCombinedSurveyTypeNames($db, $inv['survey_type_ids'] ?? '', $inv['type_name'] ?? '') : '';
$completed = !empty($inv['survey_completed_date']) && strpos((string)$inv['survey_completed_date'], '0000') !== 0 ? date('d M Y', strtotime($inv['survey_completed_date'])) : '';
$reportNo = (string)($inv['s_report_number'] ?? $inv['report_number'] ?? '');

$defaults = [
    'invoice_no' => (string)($inv['invoice_no'] ?? ''),
    'invoice_date' => (string)($inv['invoice_date'] ?? '') ?: date('Y-m-d'),
    'client' => (string)($inv['company_name'] ?? ''),
    'client_addr1' => $addr1,
    'client_addr2' => $addr2,
    'supplier_ref' => $reportNo,
    'other_ref' => '',
    'vessel' => $vessel,
    'survey_type' => $type,
    'port' => (string)($inv['port_name'] ?? ''),
    'survey_completed' => $completed,
    'report_no' => $reportNo,
    'description' => trim($type . ' of ' . $vessel . ($inv['port_name'] ? ' at ' . $inv['port_name'] : '') . ($completed ? ' on ' . $completed : '')),
    'rate' => (string)($inv['invoice_amount'] ?? ''),
    'quantity' => '1',
];

// ---------- PDF (POST from the form: preview in the frame, or download) ----------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $d = [];
    foreach ($defaults as $k => $v) $d[$k] = mb_substr(trim((string)($_POST[$k] ?? $v)), 0, $k === 'description' ? 2000 : 255);
    $d['rate'] = is_numeric(str_replace(',', '', $d['rate'])) ? (float)str_replace(',', '', $d['rate']) : 0;
    $d['quantity'] = is_numeric($d['quantity']) ? (float)$d['quantity'] : 1;
    if ($d['invoice_date'] !== '' && !DateTime::createFromFormat('!Y-m-d', $d['invoice_date'])) $d['invoice_date'] = '';
    @include_once __DIR__ . '/vendor/autoload.php';
    if (!class_exists(\Dompdf\Dompdf::class)) {
        http_response_code(500);
        exit('PDF engine (dompdf) is not installed on this server.');
    }
    $opt = new \Dompdf\Options();
    $opt->set('isRemoteEnabled', false);
    $opt->set('defaultFont', 'DejaVu Sans');
    $pdf = new \Dompdf\Dompdf($opt);
    $pdf->loadHtml(invoicePdfHtml($d), 'UTF-8');
    $pdf->setPaper('A4', 'portrait');
    $pdf->render();
    $name = preg_replace('/[^A-Za-z0-9 ._\-]/', '_', trim(($d['invoice_no'] !== '' ? $d['invoice_no'] . ' ' : '') . $d['vessel'] . ' - Invoice')) . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . (($_POST['mode'] ?? '') === 'download' ? 'attachment' : 'inline') . '; filename="' . $name . '"');
    header('Cache-Control: private, no-store');
    echo $pdf->output();
    exit;
}

$page_title = 'Generate Invoice';
include 'includes/header.php';
?>
<style>
.ig-wrap { display: grid; grid-template-columns: minmax(300px, 420px) 1fr; gap: 16px; padding: 16px; }
.ig-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 16px; box-shadow: 0 1px 2px rgba(15,23,42,.04); }
.ig-card h2 { font-size: 17px; font-weight: 800; margin: 0 0 2px; }
.ig-sub { font-size: 12px; color: #64748b; margin-bottom: 12px; }
.ig-card label { font-size: 11.5px; font-weight: 700; color: #475569; margin: 8px 0 3px; display: block; }
.ig-card .form-control { font-size: 13px; }
.ig-row { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
.ig-actions { display: flex; gap: 8px; margin-top: 14px; flex-wrap: wrap; }
.ig-btn { border: 0; border-radius: 10px; padding: 9px 14px; font-weight: 700; font-size: 13px; display: inline-flex; gap: 7px; align-items: center; text-decoration: none; }
.ig-btn.pv { background: #6C4CF1; color: #fff; } .ig-btn.dl { background: #00A3DF; color: #fff; } .ig-btn.bk { background: #f1f5f9; color: #334155; }
.ig-frame { width: 100%; height: calc(100vh - 140px); min-height: 520px; border: 1px solid #e2e8f0; border-radius: 12px; background: #f8fafc; }
@media (max-width: 991.98px) { .ig-wrap { grid-template-columns: 1fr; padding: 12px; padding-top: 84px; } .ig-frame { height: 70vh; } }
</style>
<div class="scroll-content" data-testid="invoice-generate-page">
<?php $back_url = 'invoices.php'; $page_testid = 'invoice-generate'; include 'includes/top_app_bar.php'; ?>
<div class="ig-wrap">
    <form class="ig-card" method="post" action="invoice_pdf.php?id=<?= (int)$id ?>" target="igPreview" id="igForm" data-testid="ig-form">
        <?= csrf_field() ?>
        <input type="hidden" name="mode" value="preview" id="igMode">
        <h2><i class="fa-solid fa-file-invoice-dollar" style="color:#6C4CF1"></i> Generate Invoice</h2>
        <div class="ig-sub"><?= sanitize($vessel) ?><?= $defaults['port'] ? ' · ' . sanitize($defaults['port']) : '' ?> — details are filled from the vessel record; change anything before downloading.</div>
        <div class="ig-row">
            <div><label>Invoice No.</label><input class="form-control" name="invoice_no" value="<?= sanitize($defaults['invoice_no']) ?>" data-testid="ig-invoice-no"></div>
            <div><label>Invoice Date</label><input class="form-control" type="date" name="invoice_date" value="<?= sanitize($defaults['invoice_date']) ?>"></div>
        </div>
        <label>Client</label><input class="form-control" name="client" value="<?= sanitize($defaults['client']) ?>" data-testid="ig-client">
        <label>Client address</label>
        <input class="form-control mb-1" name="client_addr1" value="<?= sanitize($addr1) ?>" placeholder="Address line 1">
        <input class="form-control" name="client_addr2" value="<?= sanitize($addr2) ?>" placeholder="Address line 2">
        <div class="ig-row">
            <div><label>Supplier's Ref.</label><input class="form-control" name="supplier_ref" value="<?= sanitize($defaults['supplier_ref']) ?>"></div>
            <div><label>Other Reference(s)</label><input class="form-control" name="other_ref" value=""></div>
        </div>
        <div class="ig-row">
            <div><label>Vessel</label><input class="form-control" name="vessel" value="<?= sanitize($vessel) ?>" data-testid="ig-vessel"></div>
            <div><label>Survey Type</label><input class="form-control" name="survey_type" value="<?= sanitize($type) ?>"></div>
        </div>
        <div class="ig-row">
            <div><label>Port</label><input class="form-control" name="port" value="<?= sanitize($defaults['port']) ?>"></div>
            <div><label>Survey Completed</label><input class="form-control" name="survey_completed" value="<?= sanitize($completed) ?>"></div>
        </div>
        <input type="hidden" name="report_no" value="<?= sanitize($reportNo) ?>">
        <label>Description of Services</label><textarea class="form-control" name="description" rows="3"><?= sanitize($defaults['description']) ?></textarea>
        <div class="ig-row">
            <div><label>Unit (rate)</label><input class="form-control" name="rate" inputmode="decimal" value="<?= sanitize($defaults['rate']) ?>" data-testid="ig-rate"></div>
            <div><label>Quantity</label><input class="form-control" name="quantity" inputmode="decimal" value="1"></div>
        </div>
        <div class="ig-actions">
            <button type="submit" class="ig-btn pv" data-testid="ig-preview"><i class="fa-solid fa-eye"></i> Preview</button>
            <button type="button" class="ig-btn dl" id="igDownload" data-testid="ig-download"><i class="fa-solid fa-file-arrow-down"></i> Download PDF</button>
            <a href="invoices.php" class="ig-btn bk"><i class="fa-solid fa-arrow-left"></i> Back</a>
        </div>
    </form>
    <div class="ig-card" style="padding:10px"><iframe name="igPreview" class="ig-frame" title="Invoice preview" data-testid="ig-frame"></iframe></div>
</div>
</div>
<script>
(function () {
  var f = document.getElementById('igForm'), mode = document.getElementById('igMode');
  document.getElementById('igDownload').addEventListener('click', function () {
    mode.value = 'download'; f.target = '_self'; f.submit();
    setTimeout(function () { mode.value = 'preview'; f.target = 'igPreview'; }, 300);
  });
  f.submit(); // first preview straight away
})();
</script>
<?php include 'includes/nav.php'; include 'includes/footer.php'; ?>
