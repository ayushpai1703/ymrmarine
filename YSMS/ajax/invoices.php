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
 *   POST a=remove  id, reason            → deletes a wrong entry from Invoices: any entry in a
 *                                          pending status with no stored invoice PDF and no
 *                                          payment. The row is kept (deleted_at), with its invoice
 *                                          number if it has one, so the vessel is not registered
 *                                          again and Restore brings it back as it was.
 *   POST a=restore id                    → brings a deleted entry back to the pending list
 *   GET  a=file    id, mode=view|download, which=recovery (optional) → the stored PDF
 *   GET  a=list    view, status, q, type, month, client, port, vessel, scd_from, scd_to, sort
 *                  (JSON), page, per → grid rows, total, pages, per-status counts
 *   POST a=prefs   prefs (JSON: order, hidden, widths, sort, per) → this user's grid layout
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

// ---------- grid (no invoice id) ----------
function invPrefsTable(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS `invoice_list_prefs` (`user_id` int(11) NOT NULL, `prefs` text NOT NULL,
        `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (`user_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
if ($action === 'list') {
    require_once __DIR__ . '/../includes/list_filters.php';
    [$f, $where, $params] = invoiceListFilters($_GET);
    $from = "FROM invoices i LEFT JOIN surveys s ON s.id = i.survey_id LEFT JOIN clients c ON c.id = s.client_id
             LEFT JOIN survey_types st ON st.id = s.survey_type_id LEFT JOIN ports p ON p.id = s.port_id";
    $per = (int)($_GET['per'] ?? 50);
    if (!in_array($per, [25, 50, 100, 200], true)) $per = 50;
    $cnt = $db->prepare("SELECT COUNT(*) $from WHERE $where");
    $cnt->execute($params);
    $total = (int)$cnt->fetchColumn();
    $pages = max(1, (int)ceil($total / $per));
    $page = min(max(1, (int)($_GET['page'] ?? 1)), $pages);
    $order = invoiceGridOrderSql(json_decode((string)($_GET['sort'] ?? '[]'), true), $f['view']);
    $st = $db->prepare(invoiceRowSql() . " WHERE $where ORDER BY $order LIMIT $per OFFSET " . (($page - 1) * $per));
    $st->execute($params);
    $rows = array_map(fn($r) => invoiceGridRow($db, $r), $st->fetchAll(PDO::FETCH_ASSOC));
    // counters per status for the same search / filters
    [, $w2, $p2] = invoiceListFiltersNoStatus($_GET);
    $c = $db->prepare("SELECT i.invoice_status, COUNT(*) n $from WHERE $w2 GROUP BY i.invoice_status");
    $c->execute($p2);
    $counts = array_fill_keys(INVOICE_STATUSES, 0);
    foreach ($c->fetchAll(PDO::FETCH_ASSOC) as $r) $counts[$r['invoice_status']] = (int)$r['n'];
    $dq = $db->prepare("SELECT COUNT(*) $from WHERE " . str_replace('i.deleted_at IS NULL', 'i.deleted_at IS NOT NULL', $w2));
    $dq->execute($p2);
    invJson(200, ['ok' => true, 'rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'per' => $per, 'view' => $f['view'],
        'counts' => $counts, 'pending' => $counts['Pending for CP Prices'] + $counts['Pending for Cost'] + $counts['Pending for Other Reason'], 'deleted' => (int)$dq->fetchColumn()]);
}
if ($action === 'prefs' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $p = json_decode((string)($_POST['prefs'] ?? ''), true);
    if (!is_array($p)) invJson(422, ['ok' => false, 'error' => 'Bad layout.']);
    $cols = ['vessel', 'report_no', 'client', 'type', 'port', 'survey_completed', 'status', 'actions', 'document', 'invoice_date', 'amount'];
    $clean = [
        'order' => array_values(array_intersect(array_map('strval', (array)($p['order'] ?? [])), $cols)),
        'hidden' => array_values(array_intersect(array_map('strval', (array)($p['hidden'] ?? [])), $cols)),
        'widths' => [], 'sort' => [],
        'per' => in_array((int)($p['per'] ?? 50), [25, 50, 100, 200], true) ? (int)$p['per'] : 50,
    ];
    foreach ((array)($p['widths'] ?? []) as $k => $w) if (in_array($k, $cols, true) || $k === 'invoice_no') $clean['widths'][$k] = max(60, min(600, (int)$w));
    foreach (array_slice((array)($p['sort'] ?? []), 0, 3) as $x) {
        if (is_array($x) && isset(INVOICE_LIST_SORTS[$x['f'] ?? ''])) $clean['sort'][] = ['f' => $x['f'], 'd' => ($x['d'] ?? '') === 'desc' ? 'desc' : 'asc'];
    }
    invPrefsTable($db);
    $db->prepare('INSERT INTO invoice_list_prefs (user_id, prefs) VALUES (?, ?) ON DUPLICATE KEY UPDATE prefs = VALUES(prefs)')
       ->execute([(int)($_SESSION['user_id'] ?? 0), json_encode($clean)]);
    invJson(200, ['ok' => true, 'prefs' => $clean]);
}

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

if (!empty($row['deleted_at']) && $action !== 'restore') invJson(409, ['ok' => false, 'error' => 'This entry was deleted from Invoices — restore it first.']);

switch ($action) {
    case 'remove': {
        $why = invoiceRemoveBlocker($row);
        if ($why === '' && $db->query("SHOW TABLES LIKE 'invoice_payments'")->fetchColumn()) {
            $q = $db->prepare('SELECT COUNT(*) FROM invoice_payments WHERE invoice_id = ?');
            $q->execute([$id]);
            if ((int)$q->fetchColumn() > 0) $why = 'Payments are recorded for this entry, so it cannot be deleted.';
        }
        if ($why !== '') invJson(409, ['ok' => false, 'error' => $why]);
        $reason = mb_substr(trim((string)($_POST['reason'] ?? '')), 0, 255);
        // only while still pending with no invoice made (checked again in the UPDATE itself)
        $u = $db->prepare("UPDATE invoices SET deleted_at = NOW(), deleted_by = ?, delete_reason = ?
            WHERE id = ? AND deleted_at IS NULL AND invoice_status <> 'Completed' AND file_path IS NULL AND rec_file_path IS NULL");
        $u->execute([$uid ?: null, $reason !== '' ? $reason : null, $id]);
        if (!$u->rowCount()) invJson(409, ['ok' => false, 'error' => 'This entry changed meanwhile — reload the page.']);
        invJson(200, ['ok' => true, 'row' => invoiceGridRow($db, $load($id))]);
    }
    case 'restore':
        if (empty($row['deleted_at'])) invJson(409, ['ok' => false, 'error' => 'This entry is not deleted.']);
        $db->prepare('UPDATE invoices SET deleted_at = NULL, deleted_by = NULL, delete_reason = NULL WHERE id = ?')->execute([$id]);
        invJson(200, ['ok' => true, 'row' => invoiceGridRow($db, $load($id))]);

    case 'status':
        $status = (string)($_POST['status'] ?? '');
        if (!in_array($status, INVOICE_STATUSES, true)) invJson(422, ['ok' => false, 'error' => 'Unknown status.']);
        $db->prepare('UPDATE invoices SET invoice_status = ?, status_updated_by = ?, status_updated_at = NOW() WHERE id = ?')
           ->execute([$status, $uid, $id]);
        invJson(200, ['ok' => true, 'row' => invoiceGridRow($db, $load($id))]);

    case 'meta':
        [$ok, $m] = $meta();
        if (!$ok) invJson(422, ['ok' => false, 'error' => $m]);
        $db->prepare('UPDATE invoices SET invoice_no = ?, invoice_date = ?, invoice_amount = ?, invoice_notes = ? WHERE id = ?')
           ->execute([$m[0], $m[1], $m[2], $m[3], $id]);
        invJson(200, ['ok' => true, 'row' => invoiceGridRow($db, $load($id))]);

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
        invJson(200, ['ok' => true, 'replaced' => (bool)$old, 'row' => invoiceGridRow($db, $load($id))]);

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
        invJson(200, ['ok' => true, 'moved' => $wasDone, 'row' => invoiceGridRow($db, $load($id))]);
}
invJson(400, ['ok' => false, 'error' => 'Unknown action.']);
