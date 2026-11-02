<?php
/**
 * Invoices module actions (invoices.php). Admin only; POSTs need the CSRF token
 * (csrf_require() in config.php; the page's fetch() sends X-CSRF-Token).
 *
 *   POST a=status  id, status            → invoice_status only
 *   POST a=upload  id, invoice_pdf, meta  → first upload or replacement of the PDF
 *   POST a=meta    id, invoice_no, invoice_date, invoice_amount, invoice_notes
 *   POST a=delete  id                    → removes the invoice PDFs (vessel + recovery) and their
 *                                          details; the row stays and keeps its invoice number.
 *                                          A Completed invoice goes back to the pending list
 *                                          (Pending for CP Prices) so it can be made again.
 *   GET  a=file    id, mode=view|download, which=recovery (optional) → the stored PDF
 *
 * Nothing here reads or writes survey status, report fields or the survey's own uploads.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/invoice_helpers.php';
checkAuth();

function invJson(int $code, array $data): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data);
    exit;
}

if (!invoiceCanManage()) invJson(403, ['ok' => false, 'error' => 'Only Admin can manage invoices.']);

$db = getDB();
invoiceEnsureTable($db);
$action = (string)($_REQUEST['a'] ?? '');
$id = (int)($_REQUEST['id'] ?? 0);

$load = function (int $id) use ($db): ?array {
    $st = $db->prepare(invoiceRowSql() . ' WHERE i.id = ?');
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
};
$row = $id > 0 ? $load($id) : null;
if (!$row) invJson(404, ['ok' => false, 'error' => 'Invoice not found.']);

// ---------- file (GET) ----------
if ($action === 'file') {
    $rec = ($_GET['which'] ?? '') === 'recovery';
    $abs = invoiceFileAbs($row[$rec ? 'rec_file_path' : 'file_path'] ?? null);
    if (!$abs) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); exit($rec ? 'No recovery invoice for this vessel.' : 'No invoice PDF uploaded for this vessel.'); }
    $name = preg_replace('/[^A-Za-z0-9 ._()\-]/', '_', (string)($row[$rec ? 'rec_file_name' : 'file_name'] ?: ($rec ? 'recovery-invoice.pdf' : 'invoice.pdf')));
    if (strtolower(substr($name, -4)) !== '.pdf') $name .= '.pdf';
    $inline = ($_GET['mode'] ?? 'view') !== 'download';
    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $name . '"');
    header('Content-Length: ' . filesize($abs));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    readfile($abs);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') invJson(405, ['ok' => false, 'error' => 'POST required.']);
$uid = (int)($_SESSION['user_id'] ?? 0);

/** Optional invoice details from the form: [ok, values or error]. */
$meta = function (): array {
    $no = mb_substr(trim((string)($_POST['invoice_no'] ?? '')), 0, 100);
    $date = trim((string)($_POST['invoice_date'] ?? ''));
    if ($date !== '') {
        $d = DateTime::createFromFormat('!Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) return [false, 'Invoice date is not valid.'];
    }
    $amt = str_replace([',', ' '], '', trim((string)($_POST['invoice_amount'] ?? '')));
    if ($amt !== '' && (!is_numeric($amt) || (float)$amt < 0 || (float)$amt > 999999999999)) return [false, 'Amount must be a number.'];
    $notes = mb_substr(trim((string)($_POST['invoice_notes'] ?? '')), 0, 500);
    return [true, [$no !== '' ? $no : null, $date !== '' ? $date : null, $amt !== '' ? round((float)$amt, 2) : null, $notes !== '' ? $notes : null]];
};

switch ($action) {
    case 'status':
        $status = (string)($_POST['status'] ?? '');
        if (!in_array($status, INVOICE_STATUSES, true)) invJson(422, ['ok' => false, 'error' => 'Unknown status.']);
        $db->prepare('UPDATE invoices SET invoice_status = ?, status_updated_by = ?, status_updated_at = NOW() WHERE id = ?')
           ->execute([$status, $uid, $id]);
        invJson(200, ['ok' => true, 'row' => invoiceRowJson($db, $load($id))]);

    case 'meta':
        [$ok, $m] = $meta();
        if (!$ok) invJson(422, ['ok' => false, 'error' => $m]);
        $db->prepare('UPDATE invoices SET invoice_no = ?, invoice_date = ?, invoice_amount = ?, invoice_notes = ? WHERE id = ?')
           ->execute([$m[0], $m[1], $m[2], $m[3], $id]);
        invJson(200, ['ok' => true, 'row' => invoiceRowJson($db, $load($id))]);

    case 'upload':
        $err = null;
        if (!invoiceValidatePdf($_FILES['invoice_pdf'] ?? [], $err)) invJson(422, ['ok' => false, 'error' => $err]);
        [$ok, $m] = $meta();
        if (!$ok) invJson(422, ['ok' => false, 'error' => $m]);
        $dir = invoiceStorageDir();
        $rel = INVOICE_DIR . '/inv' . $id . '_' . bin2hex(random_bytes(12)) . '.pdf';
        if (!is_dir($dir) || !move_uploaded_file($_FILES['invoice_pdf']['tmp_name'], dirname(__DIR__) . '/' . $rel)) {
            invJson(500, ['ok' => false, 'error' => 'The file could not be stored. Please try again.']);
        }
        $orig = mb_substr(basename((string)$_FILES['invoice_pdf']['name']), 0, 255);
        $old = $row['file_path'] ?? null;
        try {
            $db->beginTransaction();
            $db->prepare('UPDATE invoices SET file_name = ?, file_path = ?, file_size = ?, uploaded_by = ?, uploaded_at = NOW(),
                    invoice_no = COALESCE(?, invoice_no), invoice_date = COALESCE(?, invoice_date),
                    invoice_amount = COALESCE(?, invoice_amount), invoice_notes = COALESCE(?, invoice_notes) WHERE id = ?')
               ->execute([$orig, $rel, (int)$_FILES['invoice_pdf']['size'], $uid, $m[0], $m[1], $m[2], $m[3], $id]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            @unlink(dirname(__DIR__) . '/' . $rel);
            error_log('invoices upload: ' . $e->getMessage());
            invJson(500, ['ok' => false, 'error' => 'The invoice could not be saved. Please try again.']);
        }
        // The previous PDF goes only after the new one is saved, and only if no other row uses it.
        if ($old && $old !== $rel) invoiceRemoveFileIfUnused($db, $old);
        invJson(200, ['ok' => true, 'replaced' => (bool)$old, 'row' => invoiceRowJson($db, $load($id))]);

    case 'delete':
        $old = [$row['file_path'] ?? null, $row['rec_file_path'] ?? null];
        $wasDone = $row['invoice_status'] === 'Completed';
        // MySQL applies SET left to right, so invoice_status is changed last.
        // The invoice number is kept (numbers are never reused); generating again prints the same one.
        $db->prepare("UPDATE invoices SET file_name = NULL, file_path = NULL, file_size = NULL, uploaded_by = NULL, uploaded_at = NULL,
                invoice_date = NULL, invoice_amount = NULL, invoice_notes = NULL,
                gen_data = NULL, rec_gen_data = NULL, rec_file_name = NULL, rec_file_path = NULL, rec_generated_at = NULL, recovery_pending = 0,
                rec_invoice_date = NULL, rec_invoice_amount = NULL,
                status_updated_by = IF(invoice_status = 'Completed', ?, status_updated_by),
                status_updated_at = IF(invoice_status = 'Completed', NOW(), status_updated_at),
                invoice_status = IF(invoice_status = 'Completed', ?, invoice_status) WHERE id = ?")
           ->execute([$uid, INVOICE_DEFAULT_STATUS, $id]);
        foreach ($old as $o) invoiceRemoveFileIfUnused($db, $o);
        invJson(200, ['ok' => true, 'moved' => $wasDone, 'row' => invoiceRowJson($db, $load($id))]);
}
invJson(400, ['ok' => false, 'error' => 'Unknown action.']);
