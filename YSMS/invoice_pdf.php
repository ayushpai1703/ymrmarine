<?php
/**
 * Invoices → Generate Invoice / Recovery Invoice: a form pre-filled from the vessel's survey
 * record, a live PDF preview and the PDF download, on the YMR letterhead. Admin only.
 * Template: includes/invoice_pdf_template.php.
 *
 *   ?id=N                 vessel invoice: survey service, Travel Cost, Launch Cost, added services
 *   ?id=N&type=recovery   recovery invoice: description + "COMMISSION ON RECOVERY @<agreed %>% OF CP
 *                         PRICE FOR <fuel>" lines for VLSFO, HSFO (when not 0) and LSMGO; the % is only
 *                         wording, each amount is unit x qty
 *
 * Invoice number: YMR-<client short code>-<n> (includes/invoice_helpers.php), given to the
 * vessel the first time an invoice is downloaded and kept; the recovery invoice is that
 * number + "B". Downloading saves only that number; survey data is only read.
 */
require_once 'config/config.php';
require_once 'includes/invoice_helpers.php';
require_once 'includes/invoice_pdf_template.php';
checkAuth();
if (!invoiceCanManage()) { header('Location: index.php'); exit; }

$db = getDB();
invoiceEnsureTable($db);
$id = (int)($_REQUEST['id'] ?? 0);
$isRecovery = ($_REQUEST['type'] ?? '') === 'recovery';
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
if (!empty($inv['sid'])) {
    $rq = $db->prepare('SELECT vlsfo_recovery, hsfo_recovery, lsmgo_recovery FROM surveys WHERE id = ?');
    $rq->execute([(int)$inv['sid']]);
    $inv = array_merge($inv, $rq->fetch(PDO::FETCH_ASSOC) ?: []);
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
];
$serviceDesc = trim($type . ' of ' . $vessel . ($inv['port_name'] ? ' at ' . $inv['port_name'] : '') . ($completed ? ' on ' . $completed : ''));
$serviceRate = (string)($inv['invoice_amount'] ?? '');
$invoiceNo = invoicePeekNumber($db, $inv) . ($isRecovery ? 'B' : '');
$noFixed = trim((string)($inv['invoice_no'] ?? '')) !== '';
// Recovery quantities recorded on the vessel (MT, stored positive)
$recQty = [];
foreach (['vlsfo', 'hsfo', 'lsmgo'] as $fuel) {
    $v = $inv[$fuel . '_recovery'] ?? null;
    $recQty[$fuel] = $v !== null && $v !== '' ? rtrim(rtrim(number_format(abs((float)$v), 3, '.', ''), '0'), '.') : '';
}
const IG_FUELS = ['vlsfo' => 'VLSFO', 'hsfo' => 'HSFO', 'lsmgo' => 'LSMGO'];

/** A posted amount / quantity: float, or null when not a valid number ≥ 0. */
function igNum($v): ?float {
    $v = str_replace([',', ' '], '', trim((string)$v));
    return ($v !== '' && is_numeric($v) && (float)$v >= 0) ? (float)$v : null;
}

// ---------- PDF (POST from the form: preview in the frame, or download) ----------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $d = [];
    foreach ($defaults as $k => $v) $d[$k] = mb_substr(trim((string)($_POST[$k] ?? $v)), 0, 255);
    if ($d['invoice_date'] !== '' && !DateTime::createFromFormat('!Y-m-d', $d['invoice_date'])) $d['invoice_date'] = '';
    $errors = [];
    $items = [];
    if ($isRecovery) {
        // Description line (no amount), then commission on each fuel's recovery.
        $d['note'] = mb_substr(trim((string)($_POST['service_desc'] ?? '')), 0, 2000);
        $pct = igNum($_POST['recovery_pct'] ?? '');
        if ($pct === null || $pct <= 0 || $pct > 100) $errors[] = 'Agreed recovery %';
        $fuelRows = [];
        foreach (IG_FUELS as $k => $label) {
            $rate = igNum($_POST[$k . '_rate'] ?? '');
            $qty = igNum($_POST[$k . '_qty'] ?? '');
            if ($rate === null || $qty === null) { $errors[] = $label . ' unit and quantity (enter 0 if there is none)'; continue; }
            $fuelRows[$k] = ['rate' => $rate, 'qty' => $qty];
        }
        if (!$errors) {
            $pctTxt = rtrim(rtrim(number_format($pct, 2, '.', ''), '0'), '.');
            foreach ($fuelRows as $k => $r) {
                // HSFO is listed only when it was filled in (not 0); VLSFO and LSMGO always.
                if ($k === 'hsfo' && ($r['rate'] * $r['qty']) <= 0) continue;
                $items[] = ['desc' => 'COMMISSION ON RECOVERY @' . $pctTxt . '% OF CP PRICE FOR ' . IG_FUELS[$k], 'rate' => $r['rate'], 'qty' => $r['qty']]; // % is only part of the wording
            }
        }
    } else {
        // Services: the survey service, then Travel Cost and Launch Cost (always on the
        // invoice), then any services added on the form.
        $desc = mb_substr(trim((string)($_POST['service_desc'] ?? '')), 0, 2000);
        $items[] = ['desc' => $desc !== '' ? $desc : $serviceDesc, 'rate' => igNum($_POST['service_rate'] ?? '') ?? 0.0, 'qty' => igNum($_POST['service_qty'] ?? '') ?? 1.0];
        foreach (['travel' => 'Travel Cost', 'launch' => 'Launch Cost'] as $k => $label) {
            $rate = igNum($_POST[$k . '_rate'] ?? '');
            $qty = igNum($_POST[$k . '_qty'] ?? '');
            if ($rate === null || $qty === null) { $errors[] = $label . ' (enter 0 if there is none)'; continue; }
            $items[] = ['desc' => $label, 'rate' => $rate, 'qty' => $qty];
        }
        $ed = (array)($_POST['extra_desc'] ?? []);
        foreach (array_slice(array_keys($ed), 0, 30) as $i) {
            $xd = mb_substr(trim((string)$ed[$i]), 0, 500);
            $xr = igNum($_POST['extra_rate'][$i] ?? '');
            $xq = igNum($_POST['extra_qty'][$i] ?? '');
            if ($xd === '' && $xr === null) continue; // empty added row
            if ($xd === '' || $xr === null) { $errors[] = 'Added service (description and unit)'; continue; }
            $items[] = ['desc' => $xd, 'rate' => $xr, 'qty' => $xq ?? 1.0];
        }
    }
    if ($errors) {
        http_response_code(422);
        header('Content-Type: text/html; charset=utf-8');
        exit('<div style="font-family:sans-serif;padding:24px;color:#991b1b"><b>Please fill in:</b><ul><li>' . implode('</li><li>', array_map('sanitize', $errors)) . '</li></ul></div>');
    }
    // Number: the vessel's (fixed on its first download; preview shows the next free one)
    $download = ($_POST['mode'] ?? '') === 'download';
    try {
        $d['invoice_no'] = ($download ? invoiceAllocateNumber($db, $id) : invoicePeekNumber($db, $inv)) . ($isRecovery ? 'B' : '');
    } catch (Throwable $e) {
        error_log('invoice number: ' . $e->getMessage());
        http_response_code(500);
        exit('The invoice number could not be assigned. Please try again.');
    }
    $d['items'] = $items;
    @include_once __DIR__ . '/vendor/autoload.php';
    if (!class_exists(\Dompdf\Dompdf::class)) {
        http_response_code(500);
        exit('PDF engine (dompdf) is not installed on this server.');
    }
    $opt = new \Dompdf\Options();
    $opt->set('isRemoteEnabled', false);
    $opt->set('defaultFont', 'DejaVu Sans');
    $opt->setChroot(__DIR__); // the signature font lives under assets/fonts/signature/
    $fontTmp = sys_get_temp_dir() . '/ysms-dompdf-fonts';
    if (!is_dir($fontTmp)) @mkdir($fontTmp, 0775, true);
    if (is_dir($fontTmp) && is_writable($fontTmp)) $opt->setFontCache($fontTmp);
    $pdf = new \Dompdf\Dompdf($opt);
    $pdf->loadHtml(invoicePdfHtml($d), 'UTF-8');
    $pdf->setPaper('A4', 'portrait');
    $pdf->render();
    $name = preg_replace('/[^A-Za-z0-9 ._\-]/', '_', trim($d['invoice_no'] . ' ' . $d['vessel'] . ($isRecovery ? ' - Recovery Invoice' : ' - Invoice'))) . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $name . '"');
    header('X-Invoice-No: ' . $d['invoice_no']);
    header('Cache-Control: private, no-store');
    echo $pdf->output();
    exit;
}

$page_title = $isRecovery ? 'Recovery Invoice' : 'Generate Invoice';
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
.ig-svc { display: grid; gap: 8px; }
.ig-svc-row { display: grid; grid-template-columns: 1fr 170px; gap: 8px; align-items: start; background: #f8fafc; border: 1px solid #eef1f6; border-radius: 10px; padding: 8px; position: relative; }
.ig-svc-nums { display: grid; grid-template-columns: 1fr 64px; gap: 6px; }
.ig-svc-fixed { font-size: 13px; font-weight: 700; color: #334155; align-self: center; }
.ig-req { color: #dc2626; }
.ig-svc-row .ig-x { border: 0; background: #fff; color: #94a3b8; width: 24px; height: 24px; border-radius: 50%; position: absolute; right: -8px; top: -9px; box-shadow: 0 1px 3px rgba(15,23,42,.15); font-size: 11px; }
.ig-svc-row .ig-x:hover { background: #fee2e2; color: #dc2626; }
.ig-add { justify-self: start; border: 1.5px dashed #c4b5fd; background: #faf8ff; color: #6C4CF1; font-weight: 700; font-size: 12.5px; border-radius: 10px; padding: 7px 12px; }
.ig-total { text-align: right; font-size: 13px; color: #475569; } .ig-total b { font-size: 15px; color: #0f172a; margin-left: 6px; }
.ig-tabs { display: flex; gap: 6px; margin-bottom: 12px; background: #f1f5f9; padding: 4px; border-radius: 12px; }
.ig-tabs a { flex: 1; text-align: center; padding: 8px 10px; border-radius: 9px; font-size: 12.5px; font-weight: 700; color: #475569; text-decoration: none; }
.ig-tabs a.on { background: #fff; color: #6C4CF1; box-shadow: 0 1px 3px rgba(15,23,42,.12); }
.ig-hint { font-size: 10.5px; color: #94a3b8; font-weight: 500; margin-top: 2px; }
.ig-frame { width: 100%; height: calc(100vh - 140px); min-height: 520px; border: 1px solid #e2e8f0; border-radius: 12px; background: #f8fafc; }
@media (max-width: 991.98px) { .ig-wrap { grid-template-columns: 1fr; padding: 12px; padding-top: 84px; } .ig-frame { height: 70vh; } }
</style>
<div class="scroll-content" data-testid="invoice-generate-page">
<?php $back_url = 'invoices.php'; $page_testid = 'invoice-generate'; include 'includes/top_app_bar.php'; ?>
<div class="ig-wrap">
    <form class="ig-card" method="post" action="invoice_pdf.php?id=<?= (int)$id ?><?= $isRecovery ? '&amp;type=recovery' : '' ?>" target="igPreview" id="igForm" data-testid="ig-form">
        <?= csrf_field() ?>
        <input type="hidden" name="mode" value="preview" id="igMode">
        <div class="ig-tabs">
            <a href="invoice_pdf.php?id=<?= (int)$id ?>" class="<?= $isRecovery ? '' : 'on' ?>" data-testid="ig-tab-vessel"><i class="fa-solid fa-file-invoice-dollar"></i> Vessel Invoice</a>
            <a href="invoice_pdf.php?id=<?= (int)$id ?>&amp;type=recovery" class="<?= $isRecovery ? 'on' : '' ?>" data-testid="ig-tab-recovery"><i class="fa-solid fa-droplet"></i> Recovery Invoice</a>
        </div>
        <h2><?= $isRecovery ? 'Recovery Invoice' : 'Generate Invoice' ?></h2>
        <div class="ig-sub"><?= sanitize($vessel) ?><?= $defaults['port'] ? ' · ' . sanitize($defaults['port']) : '' ?> — details are filled from the vessel record; change anything before downloading.</div>
        <div class="ig-row">
            <div><label>Invoice No.</label><input class="form-control" value="<?= sanitize($invoiceNo) ?>" readonly data-testid="ig-invoice-no">
                <div class="ig-hint"><?= $noFixed ? 'This vessel\'s number' : 'Assigned when you download' ?><?= $isRecovery ? ' (+ B for recovery)' : '' ?></div></div>
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
<?php if ($isRecovery): ?>
        <label>Description</label>
        <textarea class="form-control" name="service_desc" rows="3" data-testid="ig-service-desc"><?= sanitize('Recovery of bunkers — ' . $serviceDesc) ?></textarea>
        <label>Agreed recovery % <span class="ig-req">*</span></label>
        <input class="form-control" name="recovery_pct" inputmode="decimal" placeholder="e.g. 8" required data-ig-pct data-testid="ig-recovery-pct" style="max-width:140px">
        <label>Recovery <span class="ig-hint">(unit = CP price, quantity = MT recovered)</span></label>
        <div class="ig-svc" data-testid="ig-recovery">
            <?php foreach (IG_FUELS as $k => $label): ?>
            <div class="ig-svc-row">
                <div class="ig-svc-fixed">Recovery of <?= $label ?> <span class="ig-req">*</span></div>
                <div class="ig-svc-nums"><input class="form-control" name="<?= $k ?>_rate" inputmode="decimal" placeholder="Unit" required data-ig-rate data-testid="ig-<?= $k ?>-rate"><input class="form-control" name="<?= $k ?>_qty" inputmode="decimal" placeholder="Qty" value="<?= sanitize($recQty[$k]) ?>" required data-ig-qty data-testid="ig-<?= $k ?>-qty"></div>
            </div>
            <?php endforeach; ?>
            <div class="ig-total">Total <b data-ig-total>0.00</b></div>
        </div>
<?php else: ?>
        <label>Services</label>
        <div class="ig-svc" data-testid="ig-services">
            <div class="ig-svc-row main">
                <textarea class="form-control" name="service_desc" rows="3" data-testid="ig-service-desc"><?= sanitize($serviceDesc) ?></textarea>
                <div class="ig-svc-nums"><input class="form-control" name="service_rate" inputmode="decimal" placeholder="Unit" value="<?= sanitize($serviceRate) ?>" data-ig-rate data-testid="ig-rate"><input class="form-control" name="service_qty" inputmode="decimal" placeholder="Qty" value="1" data-ig-qty></div>
            </div>
            <?php foreach (['travel' => 'Travel Cost', 'launch' => 'Launch Cost'] as $k => $label): ?>
            <div class="ig-svc-row">
                <div class="ig-svc-fixed"><?= $label ?> <span class="ig-req" title="Required">*</span></div>
                <div class="ig-svc-nums"><input class="form-control" name="<?= $k ?>_rate" inputmode="decimal" placeholder="Unit" required data-ig-rate data-testid="ig-<?= $k ?>-rate"><input class="form-control" name="<?= $k ?>_qty" inputmode="decimal" placeholder="Qty" value="1" required data-ig-qty></div>
            </div>
            <?php endforeach; ?>
            <div data-ig-extras style="display:grid;gap:8px"></div>
            <button type="button" class="ig-add" data-ig-add data-testid="ig-add-service"><i class="fa-solid fa-plus"></i> Add service</button>
            <div class="ig-total">Total <b data-ig-total>0.00</b></div>
        </div>
<?php endif; ?>
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
    if (!f.reportValidity()) return;
    mode.value = 'download'; f.target = '_self'; f.submit();
    setTimeout(function () { mode.value = 'preview'; f.target = 'igPreview'; }, 300);
  });
  // Services: added rows + live total
  var box = document.querySelector('[data-ig-extras]'), n = 0;
  function num(v) { v = String(v || '').replace(/[, ]/g, ''); return v === '' || isNaN(v) ? 0 : parseFloat(v); }
  function total() {
    var t = 0;
    document.querySelectorAll('.ig-svc-row').forEach(function (r) { var a = r.querySelector('[data-ig-rate]'), q = r.querySelector('[data-ig-qty]'); if (a) t += num(a.value) * (q && q.value !== '' ? num(q.value) : 1); });
    document.querySelector('[data-ig-total]').textContent = t.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }
  var add = document.querySelector('[data-ig-add]');
  if (add) add.addEventListener('click', function () {
    var r = document.createElement('div');
    r.className = 'ig-svc-row extra';
    r.innerHTML = '<input class="form-control" name="extra_desc[' + n + ']" placeholder="Service description" data-testid="ig-extra-desc">' +
      '<div class="ig-svc-nums"><input class="form-control" name="extra_rate[' + n + ']" inputmode="decimal" placeholder="Unit" data-ig-rate data-testid="ig-extra-rate"><input class="form-control" name="extra_qty[' + n + ']" inputmode="decimal" placeholder="Qty" value="1" data-ig-qty></div>' +
      '<button type="button" class="ig-x" title="Remove" aria-label="Remove service"><i class="fa-solid fa-xmark"></i></button>';
    n++; box.appendChild(r); r.querySelector('input').focus();
  });
  if (box) box.addEventListener('click', function (ev) { var x = ev.target.closest('.ig-x'); if (x) { x.closest('.ig-svc-row').remove(); total(); } });
  f.addEventListener('input', total);
  total();
  f.addEventListener('submit', function (ev) { if (!f.checkValidity()) { ev.preventDefault(); f.reportValidity(); } });
  f.submit(); // first preview straight away (shows what is still missing)
})();
</script>
<?php include 'includes/nav.php'; include 'includes/footer.php'; ?>
