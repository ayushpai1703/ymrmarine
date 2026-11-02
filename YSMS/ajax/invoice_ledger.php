<?php
/**
 * Invoice Airtable actions (invoices_airtable.php). Admin only; POSTs need the CSRF token
 * (csrf_require() via config.php; the page sends X-CSRF-Token).
 *
 *   GET  a=list     filters (includes/invoice_ledger.php ledgerFilters), sort (JSON), page, per
 *                   → rows, total, pages, summary (over the filtered rows)
 *   GET  a=detail   key=<invoice id>-<invoice|recovery> → document, payments, activity
 *   GET  a=receipt  payment_id, mode=view|download → the stored receipt
 *   POST a=update   key, invoice_date, due_date, amount, remarks (only the fields sent)
 *   POST a=add_payment  key, payment_date, amount, payment_mode, reference, transaction_id,
 *                   remarks, receipt (file, optional), request_id, allow_overpay
 *   POST a=void_payment payment_id, reason
 *   POST a=cancel   key, cancelled (1|0), reason
 *   POST a=prefs    prefs (JSON: order, hidden, widths, sort)
 *
 * Money is DECIMAL in the database and summed there; amounts posted are validated here.
 * Survey / vessel workflow tables are only read.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/invoice_ledger.php';
checkAuth();

function ledJson(int $code, array $data): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data);
    exit;
}
if (!ledgerCanManage()) ledJson(403, ['ok' => false, 'error' => 'Only Admin can view or change invoice payments.']);

$db = getDB();
ledgerEnsureTables($db);
$action = (string)($_REQUEST['a'] ?? '');
$uid = (int)($_SESSION['user_id'] ?? 0);
$today = date('Y-m-d');
$base = ledgerBaseSql($db, $today);

/** A money value from the form: float with at most 2 decimals, or null. */
function ledMoney($v): ?float {
    $v = str_replace([',', ' ', '$', '$'], '', trim((string)$v));
    if ($v === '' || !preg_match('/^\d{1,12}(\.\d{1,2})?$/', $v)) return null;
    return round((float)$v, 2);
}
function ledDate($v): ?string {
    $v = trim((string)$v);
    $d = DateTime::createFromFormat('!Y-m-d', $v);
    return ($d && $d->format('Y-m-d') === $v && $v >= '2000-01-01' && $v <= '2100-12-31') ? $v : null;
}
$loadDoc = function (int $iid, string $kind) use ($db, $base): ?array {
    $st = $db->prepare("SELECT x.* FROM $base WHERE x.invoice_id = ? AND x.kind = ?");
    $st->execute([$iid, $kind]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
};
$keyDoc = function () use ($loadDoc): array {
    $k = ledgerParseKey((string)($_REQUEST['key'] ?? ''));
    if (!$k) ledJson(400, ['ok' => false, 'error' => 'Unknown invoice.']);
    $doc = $loadDoc($k[0], $k[1]);
    if (!$doc) ledJson(404, ['ok' => false, 'error' => 'Invoice not found.']);
    return [$k[0], $k[1], $doc];
};

// ---------- reads ----------
if ($action === 'list') {
    [$f, $where, $params] = ledgerFilters($_GET);
    $per = (int)($_GET['per'] ?? 50);
    if (!in_array($per, [25, 50, 100, 200], true)) $per = 50;
    $cnt = $db->prepare("SELECT COUNT(*) FROM $base WHERE $where");
    $cnt->execute($params);
    $total = (int)$cnt->fetchColumn();
    $pages = max(1, (int)ceil($total / $per));
    $page = min(max(1, (int)($_GET['page'] ?? 1)), $pages);
    $sort = json_decode((string)($_GET['sort'] ?? '[]'), true);
    $st = $db->prepare("SELECT x.* FROM $base WHERE $where ORDER BY " . ledgerOrderSql($sort) . ' LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per));
    $st->execute($params);
    ledJson(200, ['ok' => true, 'rows' => array_map('ledgerRowJson', $st->fetchAll(PDO::FETCH_ASSOC)),
        'total' => $total, 'page' => $page, 'pages' => $pages, 'per' => $per, 'today' => $today,
        'summary' => ledgerSummary($db, $base, $where, $params)]);
}

if ($action === 'detail') {
    [$iid, $kind, $doc] = $keyDoc();
    $p = $db->prepare("SELECT p.*, u.full_name AS by_name, v.full_name AS void_name FROM invoice_payments p
        LEFT JOIN users u ON u.id = p.created_by LEFT JOIN users v ON v.id = p.voided_by
        WHERE p.invoice_id = ? AND p.kind = ? ORDER BY p.payment_date DESC, p.id DESC");
    $p->execute([$iid, $kind]);
    $payments = array_map(fn($r) => [
        'id' => (int)$r['id'], 'date' => $r['payment_date'], 'amount' => round((float)$r['amount'], 2), 'mode' => $r['payment_mode'],
        'reference' => (string)$r['reference'], 'transaction_id' => (string)$r['transaction_id'], 'remarks' => (string)$r['remarks'],
        'has_receipt' => ledgerReceiptAbs($r['receipt_path']) !== null, 'receipt_name' => (string)$r['receipt_name'],
        'status' => $r['status'], 'void_reason' => (string)$r['void_reason'], 'by' => (string)$r['by_name'],
        'void_by' => (string)$r['void_name'], 'created_at' => $r['created_at'],
    ], $p->fetchAll(PDO::FETCH_ASSOC));
    $a = $db->prepare("SELECT a.action, a.details, a.created_at, u.full_name FROM invoice_activity a LEFT JOIN users u ON u.id = a.user_id
        WHERE a.invoice_id = ? AND a.kind = ? ORDER BY a.id DESC LIMIT 100");
    $a->execute([$iid, $kind]);
    $acct = $db->prepare('SELECT cancelled_reason FROM invoice_accounts WHERE invoice_id = ? AND kind = ?');
    $acct->execute([$iid, $kind]);
    ledJson(200, ['ok' => true, 'row' => ledgerRowJson($doc), 'payments' => $payments,
        'activity' => $a->fetchAll(PDO::FETCH_ASSOC), 'cancelled_reason' => (string)$acct->fetchColumn(), 'modes' => LEDGER_PAY_MODES]);
}

if ($action === 'receipt') {
    $st = $db->prepare('SELECT receipt_path, receipt_name FROM invoice_payments WHERE id = ?');
    $st->execute([(int)($_GET['payment_id'] ?? 0)]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    $abs = $r ? ledgerReceiptAbs($r['receipt_path']) : null;
    if (!$abs) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); exit('No receipt for this payment.'); }
    $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
    $name = preg_replace('/[^A-Za-z0-9 ._()\-]/', '_', (string)($r['receipt_name'] ?: 'receipt.' . $ext));
    header('Content-Type: ' . ($ext === 'pdf' ? 'application/pdf' : ($ext === 'png' ? 'image/png' : 'image/jpeg')));
    header('Content-Disposition: ' . (($_GET['mode'] ?? 'view') === 'download' ? 'attachment' : 'inline') . '; filename="' . $name . '"');
    header('Content-Length: ' . filesize($abs));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    readfile($abs);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') ledJson(405, ['ok' => false, 'error' => 'POST required.']);

// ---------- writes ----------
switch ($action) {
    case 'update': {
        [$iid, $kind, $doc] = $keyDoc();
        $set = []; $log = [];
        if (array_key_exists('invoice_date', $_POST)) {
            $v = trim((string)$_POST['invoice_date']) === '' ? null : ledDate($_POST['invoice_date']);
            if (trim((string)$_POST['invoice_date']) !== '' && !$v) ledJson(422, ['ok' => false, 'error' => 'Invoice date is not valid.']);
            $set['date'] = $v;
        }
        if (array_key_exists('due_date', $_POST)) {
            $v = trim((string)$_POST['due_date']) === '' ? null : ledDate($_POST['due_date']);
            if (trim((string)$_POST['due_date']) !== '' && !$v) ledJson(422, ['ok' => false, 'error' => 'Due date is not valid.']);
            $set['due'] = $v;
        }
        if (array_key_exists('amount', $_POST)) {
            $v = trim((string)$_POST['amount']) === '' ? null : ledMoney($_POST['amount']);
            if (trim((string)$_POST['amount']) !== '' && ($v === null || $v <= 0)) ledJson(422, ['ok' => false, 'error' => 'Invoice amount must be a number above 0 (up to 2 decimals).']);
            $set['amount'] = $v;
        }
        if (array_key_exists('remarks', $_POST)) {
            $v = mb_substr(trim((string)$_POST['remarks']), 0, 1000);
            $set['remarks'] = $v !== '' ? $v : null;
        }
        if (!$set) ledJson(422, ['ok' => false, 'error' => 'Nothing to save.']);
        try {
            $db->beginTransaction();
            ledgerEnsureAccount($db, $iid, $kind);
            $db->prepare('SELECT id FROM invoice_accounts WHERE invoice_id = ? AND kind = ? FOR UPDATE')->execute([$iid, $kind]);
            $rc = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM invoice_payments WHERE invoice_id = ? AND kind = ? AND status = 'success'");
            $rc->execute([$iid, $kind]);
            $received = round((float)$rc->fetchColumn(), 2);
            if (array_key_exists('amount', $set) && $received > 0 && ($set['amount'] === null || $set['amount'] + 0.001 < $received)) {
                $db->rollBack();
                ledJson(422, ['ok' => false, 'error' => 'Invoice amount cannot be less than the amount already received ($' . number_format($received, 2) . ').']);
            }
            $old = ['date' => $doc['doc_date'], 'due' => $doc['due_date'], 'amount' => $doc['amount'] !== null ? round((float)$doc['amount'], 2) : null, 'remarks' => $doc['remarks']];
            $labels = ['date' => 'Invoice date', 'due' => 'Due date', 'amount' => 'Invoice amount', 'remarks' => 'Remarks'];
            $dateCol = $kind === 'recovery' ? 'rec_invoice_date' : 'invoice_date';
            $amtCol = $kind === 'recovery' ? 'rec_invoice_amount' : 'invoice_amount';
            foreach ($set as $k => $v) {
                if ((string)$old[$k] === (string)$v) continue;
                if ($k === 'date') $db->prepare("UPDATE invoices SET $dateCol = ? WHERE id = ?")->execute([$v, $iid]);
                if ($k === 'amount') $db->prepare("UPDATE invoices SET $amtCol = ? WHERE id = ?")->execute([$v, $iid]);
                if ($k === 'due') $db->prepare('UPDATE invoice_accounts SET due_date = ?, updated_by = ? WHERE invoice_id = ? AND kind = ?')->execute([$v, $uid, $iid, $kind]);
                if ($k === 'remarks') $db->prepare('UPDATE invoice_accounts SET remarks = ?, updated_by = ? WHERE invoice_id = ? AND kind = ?')->execute([$v, $uid, $iid, $kind]);
                $fmt = fn($x) => $x === null || $x === '' ? '—' : ($k === 'amount' ? '$' . number_format((float)$x, 2) : (string)$x);
                $log[] = $labels[$k] . ': ' . ($k === 'remarks' ? 'changed' : $fmt($old[$k]) . ' → ' . $fmt($v));
            }
            if ($log) ledgerLog($db, $iid, $kind, 'edit', implode('; ', $log));
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('invoice ledger update: ' . $e->getMessage());
            ledJson(500, ['ok' => false, 'error' => 'Could not save. Please try again.']);
        }
        ledJson(200, ['ok' => true, 'changed' => count($log), 'row' => ledgerRowJson($loadDoc($iid, $kind))]);
    }

    case 'add_payment': {
        [$iid, $kind, $doc] = $keyDoc();
        $date = ledDate($_POST['payment_date'] ?? '');
        $amount = ledMoney($_POST['amount'] ?? '');
        $mode = (string)($_POST['payment_mode'] ?? '');
        $ref = mb_substr(trim((string)($_POST['reference'] ?? '')), 0, 120);
        $txn = mb_substr(trim((string)($_POST['transaction_id'] ?? '')), 0, 120);
        $remarks = mb_substr(trim((string)($_POST['remarks'] ?? '')), 0, 500);
        $req = preg_match('/^[a-f0-9]{32}$/', (string)($_POST['request_id'] ?? '')) ? (string)$_POST['request_id'] : null;
        $allowOver = ($_POST['allow_overpay'] ?? '') === '1';
        $errs = [];
        if (!$date) $errs[] = 'Payment date';
        elseif ($date > $today) $errs[] = 'Payment date cannot be in the future';
        if ($amount === null || $amount <= 0) $errs[] = 'Amount received (a number above 0, up to 2 decimals)';
        if (!in_array($mode, LEDGER_PAY_MODES, true)) $errs[] = 'Payment mode';
        if ($errs) ledJson(422, ['ok' => false, 'error' => 'Please check: ' . implode(', ', $errs) . '.']);
        if ((int)$doc['cancelled'] === 1) ledJson(422, ['ok' => false, 'error' => 'This invoice is cancelled — restore it before adding a payment.']);
        if ($doc['amount'] === null || (float)$doc['amount'] <= 0) ledJson(422, ['ok' => false, 'error' => 'Enter the invoice amount first (Invoice details above).']);
        if ($req) { // the same form sent twice → the first save stands
            $dup = $db->prepare('SELECT id FROM invoice_payments WHERE request_id = ?');
            $dup->execute([$req]);
            if ($dup->fetchColumn()) ledJson(200, ['ok' => true, 'duplicate' => true, 'row' => ledgerRowJson($loadDoc($iid, $kind))]);
        }
        $rel = null; $rname = null;
        if (!empty($_FILES['receipt']) && ($_FILES['receipt']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $err = null;
            $ext = ledgerValidateReceipt($_FILES['receipt'], $err);
            if (!$ext) ledJson(422, ['ok' => false, 'error' => $err]);
            $dir = invoiceStorageDir();
            $rel = INVOICE_DIR . '/rcpt' . $iid . '_' . bin2hex(random_bytes(12)) . '.' . $ext;
            if (!is_dir($dir) || !move_uploaded_file($_FILES['receipt']['tmp_name'], dirname(__DIR__) . '/' . $rel)) ledJson(500, ['ok' => false, 'error' => 'The receipt could not be stored. Please try again.']);
            $rname = mb_substr(basename((string)$_FILES['receipt']['name']), 0, 255);
        }
        try {
            $db->beginTransaction();
            ledgerEnsureAccount($db, $iid, $kind);
            // one payment at a time per invoice: lock its account row, then re-check the balance
            $db->prepare('SELECT id FROM invoice_accounts WHERE invoice_id = ? AND kind = ? FOR UPDATE')->execute([$iid, $kind]);
            $am = $db->prepare('SELECT ' . ($kind === 'recovery' ? 'rec_invoice_amount' : 'invoice_amount') . ' FROM invoices WHERE id = ?');
            $am->execute([$iid]);
            $invAmt = round((float)$am->fetchColumn(), 2);
            $rc = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM invoice_payments WHERE invoice_id = ? AND kind = ? AND status = 'success'");
            $rc->execute([$iid, $kind]);
            $outstanding = round($invAmt - (float)$rc->fetchColumn(), 2);
            if ($amount > $outstanding + 0.001 && !$allowOver) {
                $db->rollBack();
                if ($rel) @unlink(dirname(__DIR__) . '/' . $rel);
                ledJson(422, ['ok' => false, 'code' => 'overpay', 'outstanding' => max(0, $outstanding),
                    'error' => 'Amount is more than the outstanding balance ($' . number_format(max(0, $outstanding), 2) . ').']);
            }
            $db->prepare('INSERT INTO invoice_payments (invoice_id, kind, payment_date, amount, payment_mode, reference, transaction_id, remarks,
                    receipt_name, receipt_path, request_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
               ->execute([$iid, $kind, $date, number_format($amount, 2, '.', ''), $mode, $ref !== '' ? $ref : null, $txn !== '' ? $txn : null,
                   $remarks !== '' ? $remarks : null, $rname, $rel, $req, $uid ?: null]);
            $pid = (int)$db->lastInsertId();
            ledgerLog($db, $iid, $kind, 'payment', 'Payment #' . $pid . ': $' . number_format($amount, 2) . ' on ' . $date . ' by ' . $mode
                . ($ref !== '' ? ', ref ' . $ref : '') . ($txn !== '' ? ', txn ' . $txn : '') . ($amount > $outstanding + 0.001 ? ' (overpayment authorised)' : ''));
            $db->commit();
        } catch (PDOException $e) {
            if ($db->inTransaction()) $db->rollBack();
            if ($rel) @unlink(dirname(__DIR__) . '/' . $rel);
            if ($e->getCode() === '23000') {
                if ($req) {
                    $dup = $db->prepare('SELECT id FROM invoice_payments WHERE request_id = ?');
                    $dup->execute([$req]);
                    if ($dup->fetchColumn()) ledJson(200, ['ok' => true, 'duplicate' => true, 'row' => ledgerRowJson($loadDoc($iid, $kind))]);
                }
                ledJson(409, ['ok' => false, 'error' => 'A payment with this Transaction ID is already recorded for this invoice.']);
            }
            error_log('invoice ledger payment: ' . $e->getMessage());
            ledJson(500, ['ok' => false, 'error' => 'The payment could not be saved. Please try again.']);
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            if ($rel) @unlink(dirname(__DIR__) . '/' . $rel);
            error_log('invoice ledger payment: ' . $e->getMessage());
            ledJson(500, ['ok' => false, 'error' => 'The payment could not be saved. Please try again.']);
        }
        ledJson(200, ['ok' => true, 'payment_id' => $pid, 'row' => ledgerRowJson($loadDoc($iid, $kind))]);
    }

    case 'void_payment': {
        $pid = (int)($_POST['payment_id'] ?? 0);
        $reason = mb_substr(trim((string)($_POST['reason'] ?? '')), 0, 500);
        if ($reason === '') ledJson(422, ['ok' => false, 'error' => 'Give a reason for voiding this payment.']);
        try {
            $db->beginTransaction();
            $st = $db->prepare("SELECT invoice_id, kind, amount, payment_date, status FROM invoice_payments WHERE id = ? FOR UPDATE");
            $st->execute([$pid]);
            $p = $st->fetch(PDO::FETCH_ASSOC);
            if (!$p || $p['status'] !== 'success') { $db->rollBack(); ledJson(404, ['ok' => false, 'error' => 'Payment not found or already voided.']); }
            $db->prepare("UPDATE invoice_payments SET status = 'void', void_reason = ?, voided_by = ?, voided_at = NOW() WHERE id = ?")->execute([$reason, $uid ?: null, $pid]);
            ledgerLog($db, (int)$p['invoice_id'], $p['kind'], 'void', 'Payment #' . $pid . ' ($' . number_format((float)$p['amount'], 2) . ' on ' . $p['payment_date'] . ') voided: ' . $reason);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('invoice ledger void: ' . $e->getMessage());
            ledJson(500, ['ok' => false, 'error' => 'Could not void the payment.']);
        }
        ledJson(200, ['ok' => true, 'row' => ledgerRowJson($loadDoc((int)$p['invoice_id'], $p['kind']))]);
    }

    case 'cancel': {
        [$iid, $kind, $doc] = $keyDoc();
        $on = ($_POST['cancelled'] ?? '') === '1';
        $reason = mb_substr(trim((string)($_POST['reason'] ?? '')), 0, 500);
        if ($on && $reason === '') ledJson(422, ['ok' => false, 'error' => 'Give a reason for cancelling this invoice.']);
        ledgerEnsureAccount($db, $iid, $kind);
        $db->prepare('UPDATE invoice_accounts SET cancelled = ?, cancelled_reason = ?, updated_by = ? WHERE invoice_id = ? AND kind = ?')
           ->execute([$on ? 1 : 0, $on ? $reason : null, $uid ?: null, $iid, $kind]);
        ledgerLog($db, $iid, $kind, $on ? 'cancel' : 'restore', $on ? 'Cancelled: ' . $reason : 'Restored');
        ledJson(200, ['ok' => true, 'row' => ledgerRowJson($loadDoc($iid, $kind))]);
    }

    case 'prefs': {
        $p = json_decode((string)($_POST['prefs'] ?? ''), true);
        if (!is_array($p)) ledJson(422, ['ok' => false, 'error' => 'Bad layout.']);
        $cols = ['invoice_no', 'invoice_date', 'client', 'vessel', 'port', 'survey_ref', 'amount', 'received', 'outstanding', 'due_date', 'last_paid', 'status', 'document', 'remarks', 'actions'];
        $clean = [
            'order' => array_values(array_intersect(array_map('strval', (array)($p['order'] ?? [])), $cols)),
            'hidden' => array_values(array_intersect(array_map('strval', (array)($p['hidden'] ?? [])), $cols)),
            'widths' => [],
            'sort' => [],
            'per' => in_array((int)($p['per'] ?? 50), [25, 50, 100, 200], true) ? (int)$p['per'] : 50,
        ];
        foreach ((array)($p['widths'] ?? []) as $k => $w) if (in_array($k, $cols, true)) $clean['widths'][$k] = max(60, min(600, (int)$w));
        foreach (array_slice((array)($p['sort'] ?? []), 0, 3) as $s) {
            if (is_array($s) && isset(LEDGER_SORTS[$s['f'] ?? ''])) $clean['sort'][] = ['f' => $s['f'], 'd' => ($s['d'] ?? '') === 'desc' ? 'desc' : 'asc'];
        }
        $db->prepare('INSERT INTO invoice_grid_prefs (user_id, prefs) VALUES (?, ?) ON DUPLICATE KEY UPDATE prefs = VALUES(prefs)')->execute([$uid, json_encode($clean)]);
        ledJson(200, ['ok' => true, 'prefs' => $clean]);
    }
}
ledJson(400, ['ok' => false, 'error' => 'Unknown action.']);
