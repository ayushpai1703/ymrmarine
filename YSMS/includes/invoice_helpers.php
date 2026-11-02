<?php
/**
 * Invoices module (invoices.php, ajax/invoices.php, invoice_pdf.php).
 *
 * One `invoices` row per survey (surveys.id). In this schema one `surveys` row is both the
 * vessel's assignment and its report, so the "vessel_id / report_id" reference is stored once
 * as `survey_id`. The row is created when the survey's documents are submitted and it moves to
 * Pending Reports (ajax/upload_handler.php), and is never removed or changed by the survey
 * workflow afterwards: invoice status, file and dates live only here.
 *
 * Files are stored under uploads/invoices/ — on Cloud Run that folder is the
 * ymrmarine-ysms-uploads Cloud Storage bucket (cloudbuild.yaml volume mount), the same storage
 * every other YSMS upload uses. They are served only through ajax/invoices.php?a=file (Admin).
 */

const INVOICE_STATUSES = ['Pending for CP Prices', 'Pending for Cost', 'Pending for Other Reason', 'Completed'];
const INVOICE_DEFAULT_STATUS = 'Pending for CP Prices';
const INVOICE_DIR = 'uploads/invoices';
const INVOICE_MAX_MB_DEFAULT = 20;

/** Only Admin works with invoices (same rule as the existing invoice_generator.php). */
function invoiceCanManage(): bool {
    return ($_SESSION['role'] ?? '') === 'Admin';
}

/** Largest accepted invoice PDF in bytes (env YSMS_INVOICE_MAX_MB, default 20 MB). */
function invoiceMaxBytes(): int {
    $mb = (int)(getenv('YSMS_INVOICE_MAX_MB') ?: INVOICE_MAX_MB_DEFAULT);
    return max(1, min(100, $mb)) * 1024 * 1024;
}

/** Creates the table when missing (same statement as database/migration_invoices.sql). */
function invoiceEnsureTable(PDO $db): void {
    static $done = false;
    if ($done) return;
    $db->exec("CREATE TABLE IF NOT EXISTS `invoices` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `survey_id` int(11) NOT NULL COMMENT 'surveys.id (vessel + report record)',
        `vessel_name` varchar(255) DEFAULT NULL COMMENT 'copy at registration, shown if the survey is ever deleted',
        `report_number` varchar(50) DEFAULT NULL COMMENT 'copy at registration',
        `invoice_status` enum('Pending for CP Prices','Pending for Cost','Pending for Other Reason','Completed') NOT NULL DEFAULT 'Pending for CP Prices',
        `status_updated_by` int(11) DEFAULT NULL,
        `status_updated_at` datetime DEFAULT NULL,
        `invoice_no` varchar(100) DEFAULT NULL,
        `invoice_date` date DEFAULT NULL,
        `invoice_amount` decimal(14,2) DEFAULT NULL,
        `invoice_notes` varchar(500) DEFAULT NULL,
        `file_name` varchar(255) DEFAULT NULL COMMENT 'original file name',
        `file_path` varchar(255) DEFAULT NULL COMMENT 'uploads/invoices/… (Cloud Storage bucket)',
        `file_size` int(11) DEFAULT NULL,
        `uploaded_by` int(11) DEFAULT NULL,
        `uploaded_at` datetime DEFAULT NULL,
        `registered_via` varchar(20) NOT NULL DEFAULT 'upload' COMMENT 'upload | backfill',
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_invoices_survey` (`survey_id`),
        KEY `idx_invoices_status` (`invoice_status`),
        KEY `idx_invoices_created` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    invoiceEnsureGenColumns($db);
    $done = true;
}

/**
 * Columns for generated invoices (added to an existing table once; same as
 * database/migration_invoices.sql):
 *   gen_data / rec_gen_data   — the Generate Invoice / Recovery Invoice form as last downloaded
 *                               (JSON), so Modify re-opens it filled in
 *   rec_file_*                — the stored Recovery Invoice PDF (the vessel invoice uses file_*)
 *   rec_invoice_date / _amount — the recovery invoice's date and total (payment tracking)
 *   recovery_pending          — 1 when the Admin chose "Recovery invoice too": the invoice is
 *                               Completed only once the recovery invoice is downloaded as well
 */
function invoiceEnsureGenColumns(PDO $db): void {
    $have = array_flip(array_map('strtolower', $db->query('SHOW COLUMNS FROM invoices')->fetchAll(PDO::FETCH_COLUMN)));
    $add = [
        'gen_data' => 'MEDIUMTEXT NULL',
        'rec_gen_data' => 'MEDIUMTEXT NULL',
        'rec_file_name' => 'VARCHAR(255) NULL',
        'rec_file_path' => 'VARCHAR(255) NULL',
        'rec_generated_at' => 'DATETIME NULL',
        'recovery_pending' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'rec_invoice_date' => 'DATE NULL',          // recovery invoice's own date and total
        'rec_invoice_amount' => 'DECIMAL(14,2) NULL', // (Invoice Airtable payment tracking)
    ];
    foreach ($add as $col => $def) {
        if (!isset($have[$col])) $db->exec("ALTER TABLE invoices ADD COLUMN `$col` $def");
    }
}

/** Stores a generated PDF under uploads/invoices/ and returns its relative path (null on failure). */
function invoiceStoreGenerated(int $invoiceId, string $pdf, string $tag): ?string {
    $dir = invoiceStorageDir();
    $rel = INVOICE_DIR . '/inv' . $invoiceId . '_' . $tag . '_' . bin2hex(random_bytes(10)) . '.pdf';
    if (!is_dir($dir) || @file_put_contents(dirname(__DIR__) . '/' . $rel, $pdf) !== strlen($pdf)) {
        @unlink(dirname(__DIR__) . '/' . $rel);
        return null;
    }
    return $rel;
}

/** Removes a stored invoice file when no invoice row (vessel or recovery) still points to it. */
function invoiceRemoveFileIfUnused(PDO $db, ?string $rel): void {
    if (!$rel || !($abs = invoiceFileAbs($rel))) return;
    $c = $db->prepare('SELECT COUNT(*) FROM invoices WHERE file_path = ? OR rec_file_path = ?');
    $c->execute([$rel, $rel]);
    if ((int)$c->fetchColumn() === 0) @unlink($abs);
}

/**
 * Registers the survey in Invoices once (INSERT IGNORE on the unique survey_id): a repeated
 * upload or a second call changes nothing. Returns true when a new row was added.
 */
function invoiceRegister(PDO $db, int $surveyId, string $via = 'upload'): bool {
    if ($surveyId <= 0) return false;
    invoiceEnsureTable($db);
    $st = $db->prepare("INSERT IGNORE INTO invoices (survey_id, vessel_name, report_number, invoice_status, registered_via)
        SELECT s.id, s.vessel_name, s.report_number, ?, ? FROM surveys s WHERE s.id = ?");
    $st->execute([INVOICE_DEFAULT_STATUS, $via === 'backfill' ? 'backfill' : 'upload', $surveyId]);
    return $st->rowCount() > 0;
}

/**
 * Repeatable backfill: every survey now in Pending Reports that has no invoice row yet (a
 * vessel that reached Pending Reports some other way than the document upload). Vessels
 * already Completed are not added. Survey rows are only read. Returns the number added.
 */
function invoiceBackfill(PDO $db): int {
    invoiceEnsureTable($db);
    invoiceCleanupCompletedBackfill($db);
    $st = $db->prepare("INSERT IGNORE INTO invoices (survey_id, vessel_name, report_number, invoice_status, registered_via)
        SELECT s.id, s.vessel_name, s.report_number, ?, 'backfill' FROM surveys s
        WHERE s.status = 'Pending Report'");
    $st->execute([INVOICE_DEFAULT_STATUS]);
    return $st->rowCount();
}

/**
 * Runs once: the first version of the backfill also added vessels whose survey was already
 * Completed. Those rows are taken out again, but only when nobody has worked on them — added
 * by the backfill, survey Completed on or before the row was added, still the default status,
 * no invoice number, file, recovery invoice or payment. Survey rows are only read.
 */
function invoiceCleanupCompletedBackfill(PDO $db): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $db->exec("CREATE TABLE IF NOT EXISTS `invoice_meta` (`k` varchar(64) NOT NULL, `v` varchar(255) DEFAULT NULL,
        `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (`k`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if ($db->query("SELECT COUNT(*) FROM invoice_meta WHERE k = 'cleanup_completed_backfill'")->fetchColumn()) return;
    $hasPay = (bool)$db->query("SHOW TABLES LIKE 'invoice_payments'")->fetchColumn();
    $sql = "DELETE i FROM invoices i JOIN surveys s ON s.id = i.survey_id
        WHERE i.registered_via = 'backfill' AND s.status = 'Completed'
          AND (s.report_uploaded_date IS NULL OR YEAR(s.report_uploaded_date) = 0 OR DATE(s.report_uploaded_date) <= DATE(i.created_at))
          AND i.invoice_status = " . $db->quote(INVOICE_DEFAULT_STATUS) . "
          AND (i.invoice_no IS NULL OR i.invoice_no = '') AND i.file_path IS NULL AND i.rec_file_path IS NULL"
        . ($hasPay ? " AND NOT EXISTS (SELECT 1 FROM invoice_payments p WHERE p.invoice_id = i.id)" : '');
    $n = $db->exec($sql);
    $db->prepare("INSERT IGNORE INTO invoice_meta (k, v) VALUES ('cleanup_completed_backfill', ?)")->execute([date('Y-m-d H:i:s') . ' removed ' . (int)$n]);
}

/** Absolute path of a stored invoice file, only when it is inside uploads/invoices/. */
function invoiceFileAbs(?string $rel): ?string {
    if (!$rel || !preg_match('#^uploads/invoices/[A-Za-z0-9_\-]+\.pdf$#', $rel)) return null;
    $base = realpath(dirname(__DIR__) . '/' . INVOICE_DIR);
    $abs = realpath(dirname(__DIR__) . '/' . $rel);
    return ($base && $abs && strpos($abs, $base . DIRECTORY_SEPARATOR) === 0 && is_file($abs)) ? $abs : null;
}

/** uploads/invoices/ with a deny-all .htaccess (files are only served by ajax/invoices.php). */
function invoiceStorageDir(): string {
    $dir = dirname(__DIR__) . '/' . INVOICE_DIR;
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    if (is_dir($dir) && !is_file($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess', "Require all denied\nOptions -Indexes\n");
    }
    return $dir;
}

/** True when the upload is a real PDF (extension, size, MIME sniffed on the server, %PDF header). */
function invoiceValidatePdf(array $f, ?string &$err): bool {
    $err = null;
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $err = ($f['error'] ?? 0) === UPLOAD_ERR_INI_SIZE || ($f['error'] ?? 0) === UPLOAD_ERR_FORM_SIZE ? 'The file is too large.' : 'No file was received.';
        return false;
    }
    if (strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION)) !== 'pdf') { $err = 'Only PDF files can be uploaded.'; return false; }
    if ((int)$f['size'] <= 0 || (int)$f['size'] > invoiceMaxBytes()) { $err = 'The PDF must be smaller than ' . (invoiceMaxBytes() / 1048576) . ' MB.'; return false; }
    $mime = function_exists('finfo_open') ? (string)finfo_file(finfo_open(FILEINFO_MIME_TYPE), $f['tmp_name']) : '';
    $head = (string)@file_get_contents($f['tmp_name'], false, null, 0, 5);
    if ($mime !== 'application/pdf' || $head !== '%PDF-') { $err = 'This file is not a valid PDF.'; return false; }
    return true;
}

/** One invoice row joined with what the list shows (vessel, client, type, port, dates). */
function invoiceRowSql(): string {
    return "SELECT i.*, s.id AS sid, s.vessel_name AS s_vessel_name, s.report_number AS s_report_number, s.agent_name,
               s.survey_type_ids, s.survey_completed_date, s.appointment_date, s.report_uploaded_date, s.status AS survey_status,
               c.company_name, st.type_name, p.port_name
        FROM invoices i
        LEFT JOIN surveys s ON s.id = i.survey_id
        LEFT JOIN clients c ON c.id = s.client_id
        LEFT JOIN survey_types st ON st.id = s.survey_type_id
        LEFT JOIN ports p ON p.id = s.port_id";
}

/** The row as JSON for the page (what the row's cells and buttons need). */
function invoiceRowJson(PDO $db, array $r): array {
    $done = !empty($r['survey_completed_date']) && strpos((string)$r['survey_completed_date'], '0000') !== 0;
    return [
        'id' => (int)$r['id'],
        'status' => (string)$r['invoice_status'],
        'has_file' => invoiceFileAbs($r['file_path'] ?? null) !== null,
        'has_rec' => invoiceFileAbs($r['rec_file_path'] ?? null) !== null,
        'rec_file_name' => (string)($r['rec_file_name'] ?? ''),
        'recovery_pending' => !empty($r['recovery_pending']),
        'generated' => !empty($r['gen_data']),
        'file_name' => (string)($r['file_name'] ?? ''),
        'uploaded_at' => !empty($r['uploaded_at']) ? date('d M Y H:i', strtotime($r['uploaded_at'])) : '',
        'invoice_no' => (string)($r['invoice_no'] ?? ''),
        'invoice_date' => (string)($r['invoice_date'] ?? ''),
        'invoice_amount' => $r['invoice_amount'] !== null ? (string)$r['invoice_amount'] : '',
        'invoice_notes' => (string)($r['invoice_notes'] ?? ''),
        'survey_completed' => $done ? date('d M Y', strtotime($r['survey_completed_date'])) : '',
    ];
}

/** One Invoices grid row: invoiceRowJson() plus the display columns. */
function invoiceGridRow(PDO $db, array $r): array {
    $j = invoiceRowJson($db, $r);
    $j['vessel'] = (string)($r['s_vessel_name'] ?? $r['vessel_name'] ?? '');
    $j['report_no'] = (string)($r['s_report_number'] ?? $r['report_number'] ?? '');
    $j['client'] = (string)($r['company_name'] ?? '');
    $j['type'] = !empty($r['sid']) ? (string)getCombinedSurveyTypeNames($db, $r['survey_type_ids'] ?? '', $r['type_name'] ?? '') : '';
    $j['port'] = (string)($r['port_name'] ?? '');
    $j['survey_deleted'] = empty($r['sid']);
    $j['survey_completed_ymd'] = !empty($r['survey_completed_date']) && strpos((string)$r['survey_completed_date'], '0000') !== 0 ? substr((string)$r['survey_completed_date'], 0, 10) : '';
    $j['amount'] = $r['invoice_amount'] !== null ? round((float)$r['invoice_amount'], 2) : null;
    return $j;
}

/** Bootstrap badge colour for a status. */
function invoiceStatusClass(string $s): string {
    return ['Pending for CP Prices' => 'cp', 'Pending for Cost' => 'cost', 'Pending for Other Reason' => 'other', 'Completed' => 'done'][$s] ?? 'other';
}

/**
 * Invoices list filters from the query string (invoices.php and export_invoices.php):
 *   view   — 'pending' (default: every status except Completed) or 'completed'
 *   status — one pending status (pending view only), q, type, month (survey completed, YYYY-MM)
 * Returns [state, whereSql, params]; the SQL uses the aliases of invoiceRowSql().
 */
function invoiceListFilters(array $g): array {
    $st = [
        'view' => in_array($g['view'] ?? '', ['completed', 'all'], true) ? (string)$g['view'] : 'pending',
        'q' => mb_substr(trim((string)($g['q'] ?? '')), 0, 100),
        'status' => '',
        'type' => mb_substr(trim((string)($g['type'] ?? '')), 0, 100),
        'month' => preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)($g['month'] ?? '')) ? (string)$g['month'] : '',
        'client' => max(0, (int)($g['client'] ?? 0)),
        'port' => max(0, (int)($g['port'] ?? 0)),
        'vessel' => mb_substr(trim((string)($g['vessel'] ?? '')), 0, 100),
        'scd_from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($g['scd_from'] ?? '')) ? (string)$g['scd_from'] : '',
        'scd_to' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($g['scd_to'] ?? '')) ? (string)$g['scd_to'] : '',
        'ids' => [],
    ];
    foreach (explode(',', (string)($g['ids'] ?? '')) as $x) if (ctype_digit(trim($x))) $st['ids'][] = (int)trim($x);
    $st['ids'] = array_slice(array_values(array_unique($st['ids'])), 0, 5000);
    if ($st['view'] === 'pending' && in_array($g['status'] ?? '', INVOICE_STATUSES, true) && $g['status'] !== 'Completed') $st['status'] = (string)$g['status'];
    $where = [$st['view'] === 'completed' ? "i.invoice_status = 'Completed'" : ($st['view'] === 'all' ? '1=1' : "i.invoice_status <> 'Completed'")];
    $params = [];
    if ($st['q'] !== '') {
        $like = '%' . addcslashes($st['q'], '%_\\') . '%';
        $where[] = '(COALESCE(s.vessel_name, i.vessel_name) LIKE ? OR c.company_name LIKE ? OR p.port_name LIKE ? OR COALESCE(s.report_number, i.report_number) LIKE ? OR i.invoice_no LIKE ?)';
        array_push($params, $like, $like, $like, $like, $like);
    }
    if ($st['status'] !== '') { $where[] = 'i.invoice_status = ?'; $params[] = $st['status']; }
    if ($st['type'] !== '') { $where[] = listFilterTypeSql([$st['type']], $params); }
    if ($st['month'] !== '') {
        $where[] = 's.survey_completed_date >= ? AND s.survey_completed_date < ?';
        $params[] = $st['month'] . '-01';
        $params[] = date('Y-m-d', strtotime($st['month'] . '-01 +1 month'));
    }
    if ($st['client'] > 0) { $where[] = 's.client_id = ?'; $params[] = $st['client']; }
    if ($st['port'] > 0) { $where[] = 's.port_id = ?'; $params[] = $st['port']; }
    if ($st['vessel'] !== '') { $where[] = 'COALESCE(s.vessel_name, i.vessel_name) LIKE ?'; $params[] = '%' . addcslashes($st['vessel'], '%_\\') . '%'; }
    if ($st['scd_from'] !== '') { $where[] = 's.survey_completed_date >= ?'; $params[] = $st['scd_from']; }
    if ($st['scd_to'] !== '') { $where[] = 's.survey_completed_date < ?'; $params[] = date('Y-m-d', strtotime($st['scd_to'] . ' +1 day')); }
    if ($st['ids']) { $where[] = 'i.id IN (' . implode(',', $st['ids']) . ')'; }
    return [$st, implode(' AND ', $where), $params];
}

/** The filters without view / status (for the per-status counters). */
function invoiceListFiltersNoStatus(array $g): array {
    $g['view'] = 'pending'; $g['status'] = '';
    [$st, $where, $params] = invoiceListFilters($g);
    // drop the leading view condition ("i.invoice_status <> 'Completed'")
    $where = preg_replace("/^i\.invoice_status <> 'Completed'( AND )?/", '', $where);
    return [$st, $where !== '' ? $where : '1=1', $params];
}

/** Sortable columns of the Invoices grid → SQL (aliases of invoiceRowSql()). */
const INVOICE_LIST_SORTS = [
    'invoice_no' => "CAST(SUBSTRING_INDEX(i.invoice_no, '-', -1) AS UNSIGNED)",
    'vessel' => 'COALESCE(s.vessel_name, i.vessel_name)',
    'report_no' => 'COALESCE(s.report_number, i.report_number)',
    'client' => 'c.company_name',
    'type' => 'st.type_name',
    'port' => 'p.port_name',
    'survey_completed' => '(CASE WHEN YEAR(s.survey_completed_date) > 0 THEN s.survey_completed_date END)',
    'status' => "FIELD(i.invoice_status, 'Pending for CP Prices', 'Pending for Cost', 'Pending for Other Reason', 'Completed')",
    'invoice_date' => 'i.invoice_date',
    'amount' => 'i.invoice_amount',
];

/** ORDER BY from [{f, d}] (up to 3 levels, empty values last); falls back to the view's default order. */
function invoiceGridOrderSql($sorts, string $view): string {
    $parts = [];
    foreach (array_slice(is_array($sorts) ? $sorts : [], 0, 3) as $x) {
        $f = is_array($x) ? (string)($x['f'] ?? '') : '';
        if (!isset(INVOICE_LIST_SORTS[$f])) continue;
        $e = INVOICE_LIST_SORTS[$f];
        $dir = strtolower((string)($x['d'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
        $parts[] = "($e IS NULL) ASC, $e $dir";
        if ($f === 'invoice_no') $parts[] = "i.invoice_no $dir";
    }
    return $parts ? implode(', ', $parts) . ', i.id DESC' : invoiceListOrderSql($view);
}

/**
 * ORDER BY for the list. Pending: newest Survey Completed Date first, vessels without one last.
 * Completed: by invoice number (the running number at the end of YMR-<code>-<n>), invoices
 * without a number last.
 */
function invoiceListOrderSql(string $view = 'pending'): string {
    if ($view === 'completed') {
        return "(i.invoice_no IS NULL OR i.invoice_no = '') ASC, CAST(SUBSTRING_INDEX(i.invoice_no, '-', -1) AS UNSIGNED) ASC, i.invoice_no ASC, i.id ASC";
    }
    $scd = '(CASE WHEN YEAR(s.survey_completed_date) > 0 THEN s.survey_completed_date END)';
    return "($scd IS NULL) ASC, $scd DESC, i.id DESC";
}

/* ---------- Invoice numbers: YMR-<client short code>-<n>, n from 1000, +1 per invoice ----------
   A vessel's number is fixed the first time its invoice is downloaded (preview never uses a
   number up) and kept on its invoices row; generating again reuses it. Its Recovery Invoice
   is the same number with "B" at the end. Client short codes are the ones the report
   numbers already use (clients.short_code, includes/report_number.php). */
const INVOICE_FIRST_NUMBER = 1000;

function invoiceEnsureSequence(PDO $db): void {
    static $done = false;
    if ($done) return;
    $db->exec("CREATE TABLE IF NOT EXISTS `invoice_number_seq` (
        `id` tinyint NOT NULL, `next_no` int NOT NULL, PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec('INSERT IGNORE INTO invoice_number_seq (id, next_no) VALUES (1, ' . INVOICE_FIRST_NUMBER . ')');
    $done = true;
}

/** The client's short code for invoice numbers (e.g. BB for Bainbridge). */
function invoiceClientCode(PDO $db, int $surveyId): string {
    require_once __DIR__ . '/report_number.php';
    $q = $db->prepare('SELECT client_id FROM surveys WHERE id = ?');
    $q->execute([$surveyId]);
    $cid = (int)$q->fetchColumn();
    return $cid > 0 ? preg_replace('/[^A-Z0-9]/', '', strtoupper(ensureClientHasShortCode($db, $cid))) ?: 'XX' : 'XX';
}

/** The number the vessel's invoice has, or the one it would get now (not reserved). */
function invoicePeekNumber(PDO $db, array $inv): string {
    if (trim((string)($inv['invoice_no'] ?? '')) !== '') return (string)$inv['invoice_no'];
    invoiceEnsureSequence($db);
    $n = (int)$db->query('SELECT next_no FROM invoice_number_seq WHERE id = 1')->fetchColumn();
    return 'YMR-' . invoiceClientCode($db, (int)$inv['survey_id']) . '-' . max(INVOICE_FIRST_NUMBER, $n);
}

/** Gives the invoice its number if it has none yet (one transaction) and returns it. */
function invoiceAllocateNumber(PDO $db, int $invoiceId): string {
    invoiceEnsureSequence($db);
    $s = $db->prepare('SELECT survey_id FROM invoices WHERE id = ?');
    $s->execute([$invoiceId]);
    $code = invoiceClientCode($db, (int)$s->fetchColumn()); // before the transaction (may save a missing client code)
    $db->beginTransaction();
    try {
        $q = $db->prepare('SELECT invoice_no FROM invoices WHERE id = ? FOR UPDATE');
        $q->execute([$invoiceId]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('Invoice not found.');
        if (trim((string)$row['invoice_no']) !== '') { $db->commit(); return (string)$row['invoice_no']; }
        $n = max(INVOICE_FIRST_NUMBER, (int)$db->query('SELECT next_no FROM invoice_number_seq WHERE id = 1 FOR UPDATE')->fetchColumn());
        $no = 'YMR-' . $code . '-' . $n;
        $db->prepare('UPDATE invoices SET invoice_no = ? WHERE id = ?')->execute([$no, $invoiceId]);
        $db->prepare('UPDATE invoice_number_seq SET next_no = ? WHERE id = 1')->execute([$n + 1]);
        $db->commit();
        return $no;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}
