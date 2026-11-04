<?php
/**
 * Vessels Data → Import (Admin). Updates EXISTING vessel records from an Excel sheet — the
 * Vessels Data export (or the template from export_vessels_data.php?template=1). New vessels
 * are still added with Assign Vessel / Bulk Assign, so an import can never create a duplicate.
 *
 *   1. vdImportPreview(): reads the sheet, matches every row by Record ID, checks each cell
 *      with the same rules as the grid (vdValidate) and lists, per row, the changes it would
 *      make or why it fails. Nothing is written. The plan is kept in the session (30 min).
 *   2. vdImportApply(): after the Admin confirms, writes the planned changes in ONE transaction
 *      with change history (source = import). A record changed by someone else after the
 *      preview stops the import (nothing is written) instead of overwriting that change.
 *
 * Blank cells mean "leave as is" (clear a value in the grid). Status, recovery and the other
 * read-only export columns are ignored.
 */
require_once __DIR__ . '/vessels_data.php';

const VD_IMPORT_MAX_ROWS = 2000;
const VD_IMPORT_MAX_BYTES = 10 * 1024 * 1024;

/** Sheet column → grid field (headers as the export writes them; a few common spellings too). */
function vdImportHeaderMap(): array {
    return [
        'recordid' => 'id', 'id' => 'id',
        'vesselname' => 'vessel', 'vessel' => 'vessel',
        'imonumber' => 'imo', 'imo' => 'imo', 'imono' => 'imo',
        'reportnumber' => 'report_no', 'reportno' => 'report_no',
        'client' => 'client', 'clientname' => 'client',
        'agent' => 'agent', 'agentname' => 'agent',
        'surveytype' => 'type',
        'port' => 'port', 'portname' => 'port',
        'surveyor' => 'surveyor', 'surveyors' => 'surveyor',
        'assigneddate' => 'assigned',
        'appointmentdate' => 'appointment',
        'surveycompleteddate' => 'completed',
        'reportuploadeddate' => 'report_date',
        'remarks' => 'remarks',
        'notes' => 'notes',
    ];
}

function vdImportNorm(string $h): string { return preg_replace('/[^a-z0-9]/', '', strtolower($h)); }

/** A date cell → [Y-m-d, H:i or ''] or null when it is not a date. Excel serials are converted without PhpSpreadsheet's Date helper. */
function vdImportDate($v): ?array {
    if ($v instanceof DateTimeInterface) return [$v->format('Y-m-d'), $v->format('H:i')];
    if (is_int($v) || is_float($v) || (is_string($v) && preg_match('/^\d+(\.\d+)?$/', trim($v)) && (float)$v > 20000 && (float)$v < 80000)) {
        $f = (float)$v;
        if ($f < 20000 || $f > 80000) return null;
        $ts = (int)round(($f - 25569) * 86400);
        $d = gmdate('Y-m-d', $ts);
        $t = gmdate('H:i', $ts);
        return [$d, $t === '00:00' && floor($f) == $f ? '' : $t];
    }
    $s = trim((string)$v);
    if ($s === '') return null;
    foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d\TH:i', 'Y-m-d', 'd-m-Y H:i', 'd-m-Y', 'd/m/Y H:i', 'd/m/Y', 'd.m.Y', 'd M Y H:i', 'd M Y', 'j M Y', 'd F Y', 'j F Y'] as $fmt) {
        $d = DateTime::createFromFormat('!' . $fmt, $s);
        if ($d && $d->format($fmt) === $s) return [$d->format('Y-m-d'), strpos($fmt, 'H') !== false ? $d->format('H:i') : ''];
    }
    return null;
}

/** Cell text → the value the grid would send for that field, or an error string (prefixed "!"). */
function vdImportValue(PDO $db, array $s, string $key, $cell, array $lk): string {
    $txt = trim(is_scalar($cell) ? (string)$cell : '');
    $one = function (array $map, string $name, string $what) {
        $k = mb_strtolower(trim($name));
        if (!isset($map[$k])) return '!' . $what . ' "' . $name . '" not found';
        if ($map[$k] === false) return '!' . $what . ' "' . $name . '" matches more than one record — change it in the grid';
        return (string)$map[$k];
    };
    switch ($key) {
        case 'client': return $one($lk['clients'], $txt, 'Client');
        case 'port': return $one($lk['ports'], $txt, 'Port');
        case 'type':
        case 'surveyor':
            if ($key === 'surveyor' && mb_strtolower($txt) === mb_strtolower(VD_OUTSOURCING_LABEL)) return '';
            $ids = [];
            foreach (preg_split('/\s*[+,]\s*/', $txt) as $n) {
                if ($n === '') continue;
                $r = $one($key === 'type' ? $lk['types'] : $lk['surveyors'], $n, $key === 'type' ? 'Survey type' : 'Surveyor');
                if ($r[0] === '!') return $r;
                $ids[] = $r;
            }
            return implode(',', $ids);
        case 'assigned':
        case 'appointment':
        case 'completed':
        case 'report_date':
            $d = vdImportDate($cell);
            if ($d === null) return '!' . VD_FIELDS[$key][1] . ' "' . $txt . '" is not a date (use dd-mm-yyyy or yyyy-mm-dd)';
            if ($key !== 'assigned') return $d[0];
            if ($d[1] === '') { // no time in the cell: same day keeps the stored time
                $cur = vdCurrent($db, $s, 'assigned');
                return $cur !== '' && strpos($cur, $d[0]) === 0 ? $cur : $d[0] . 'T00:00';
            }
            return $d[0] . 'T' . $d[1];
        case 'imo': return is_float($cell) ? (string)(int)$cell : $txt;
        default: return is_scalar($cell) ? trim((string)$cell) : '';
    }
}

/** Name → id lookups (lower-case names; false = the name is not unique). */
function vdImportLookups(PDO $db): array {
    $mk = function (array $rows) { $m = []; foreach ($rows as [$id, $name]) { $k = mb_strtolower(trim((string)$name)); $m[$k] = isset($m[$k]) ? false : (int)$id; } return $m; };
    return [
        'clients' => $mk($db->query('SELECT id, company_name FROM clients')->fetchAll(PDO::FETCH_NUM)),
        'ports' => $mk($db->query('SELECT id, port_name FROM ports')->fetchAll(PDO::FETCH_NUM)),
        'types' => $mk($db->query('SELECT id, type_name FROM survey_types')->fetchAll(PDO::FETCH_NUM)),
        'surveyors' => $mk($db->query("SELECT u.id, u.full_name FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'Surveyor' AND u.id <> " . VD_UNASSIGNED_SURVEYOR)->fetchAll(PDO::FETCH_NUM)),
    ];
}

function vdImportPreview(PDO $db, array $file): array {
    $fail = fn($m) => ['ok' => false, 'error' => $m];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return $fail('Choose the Excel file to import.');
    $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($ext, ['xlsx', 'xls', 'csv'], true)) return $fail('Upload an Excel file (.xlsx, .xls) or .csv.');
    if ((int)($file['size'] ?? 0) <= 0 || (int)$file['size'] > VD_IMPORT_MAX_BYTES) return $fail('The file must be smaller than 10 MB.');
    @include_once dirname(__DIR__) . '/vendor/autoload.php';
    if (!class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) return $fail('Excel reading is not available on this server.');
    try {
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader($ext === 'csv' ? 'Csv' : ($ext === 'xls' ? 'Xls' : 'Xlsx'));
        if (method_exists($reader, 'setReadDataOnly')) $reader->setReadDataOnly(true);
        $sheet = $reader->load((string)$file['tmp_name'])->getActiveSheet();
        $data = $sheet->toArray(null, false, false, false);
    } catch (Throwable $e) {
        error_log('vessels_data import read: ' . get_class($e));
        return $fail('The file could not be read. Save it as .xlsx and try again.');
    }
    // header row: the first row (of the first 10) that has a Record ID column
    $map = vdImportHeaderMap();
    $head = null; $cols = []; $ignored = [];
    foreach (array_slice($data, 0, 10, true) as $ri => $row) {
        $c = [];
        foreach ($row as $ci => $h) { $n = vdImportNorm((string)$h); if (isset($map[$n]) && !in_array($map[$n], $c, true)) $c[$ci] = $map[$n]; }
        if (in_array('id', $c, true)) { $head = $ri; $cols = $c; foreach ($row as $ci => $h) if (trim((string)$h) !== '' && !isset($c[$ci])) $ignored[] = trim((string)$h); break; }
    }
    if ($head === null) return $fail('No "Record ID" column found. Use the Vessels Data export or the import template (the column names must stay as they are).');
    if (count($cols) < 2) return $fail('The sheet has a Record ID column but no column that can be imported (e.g. Vessel Name, Agent, Remarks).');

    $lk = vdImportLookups($db);
    $out = []; $plan = []; $seen = [];
    $count = ['rows' => 0, 'changes' => 0, 'unchanged' => 0, 'failed' => 0];
    foreach (array_slice($data, $head + 1, null, true) as $ri => $row) {
        $vals = [];
        foreach ($cols as $ci => $k) $vals[$k] = $row[$ci] ?? null;
        $blank = true;
        foreach ($vals as $v) if (trim(is_scalar($v) ? (string)$v : '') !== '') { $blank = false; break; }
        if ($blank) continue;
        if (++$count['rows'] > VD_IMPORT_MAX_ROWS) return $fail('At most ' . VD_IMPORT_MAX_ROWS . ' rows can be imported at a time.');
        $line = ['row' => $ri + 1, 'id' => null, 'vessel' => '', 'state' => 'error', 'changes' => [], 'errors' => []];
        $idTxt = trim((string)($vals['id'] ?? ''));
        if (!ctype_digit($idTxt)) {
            $line['errors'][] = $idTxt === '' ? 'Record ID is empty — import only updates existing vessels (add new ones with Assign Vessel / Bulk Assign).' : 'Record ID "' . $idTxt . '" is not a number.';
        } elseif (isset($seen[(int)$idTxt])) {
            $line['id'] = (int)$idTxt;
            $line['errors'][] = 'Record ID ' . $idTxt . ' is also on row ' . $seen[(int)$idTxt] . ' — each vessel once per file.';
        } else {
            $id = (int)$idTxt; $seen[$id] = $ri + 1; $line['id'] = $id;
            $s = vdLoad($db, $id);
            if (!$s) {
                $line['errors'][] = 'No vessel with Record ID ' . $id . '.';
            } else {
                $line['vessel'] = (string)$s['vessel_name'];
                $sim = $s; $changes = [];
                foreach ($vals as $k => $cell) {
                    if ($k === 'id' || trim(is_scalar($cell) ? (string)$cell : '') === '') continue; // blank = no change
                    $want = vdImportValue($db, $sim, $k, $cell, $lk);
                    if ($want !== '' && $want[0] === '!') { $line['errors'][] = substr($want, 1) . '.'; continue; }
                    $cur = vdCurrent($db, $sim, $k);
                    $res = vdValidate($db, $sim, $k, $want);
                    if (!$res['ok']) { $line['errors'][] = $res['message']; continue; }
                    if (vdSameValue($k, $res['value'], $cur)) continue;
                    $changes[$k] = ['value' => $res['value'], 'orig' => vdCurrent($db, $s, $k)];
                    $line['changes'][] = ['field' => VD_FIELDS[$k][1], 'old' => vdDisplay($db, $k, $cur), 'new' => vdDisplay($db, $k, $res['value'])];
                    foreach ($res['set'] as $col => $v) $sim[$col] = $v; // later cells see this change (date order checks)
                }
                if (!$line['errors']) {
                    $line['state'] = $changes ? 'change' : 'same';
                    if ($changes) $plan[$id] = $changes;
                }
            }
        }
        if ($line['state'] === 'error') $count['failed']++; elseif ($line['state'] === 'change') $count['changes']++; else $count['unchanged']++;
        $out[] = $line;
    }
    if (!$count['rows']) return $fail('The sheet has no rows under the column names.');
    $token = bin2hex(random_bytes(12));
    $_SESSION['vd_import'] = ['token' => $token, 'at' => time(), 'plan' => $plan, 'name' => basename((string)$file['name'])];
    return ['ok' => true, 'token' => $plan ? $token : null, 'counts' => $count, 'rows' => $out, 'ignored' => array_values(array_unique($ignored)),
        'file' => basename((string)$file['name'])];
}

function vdImportApply(PDO $db, string $token): array {
    $p = $_SESSION['vd_import'] ?? null;
    if (!$p || $token === '' || !hash_equals((string)$p['token'], $token)) return ['ok' => false, 'error' => 'This import preview has expired. Upload the file again.'];
    if (time() - (int)$p['at'] > 1800) { unset($_SESSION['vd_import']); return ['ok' => false, 'error' => 'This import preview is older than 30 minutes. Upload the file again.']; }
    $done = 0; $records = 0;
    $db->beginTransaction();
    try {
        foreach ($p['plan'] as $id => $changes) {
            foreach ($changes as $k => $c) {
                $s = vdLoad($db, (int)$id, true);
                if (!$s) throw new DomainException('Vessel #' . $id . ' no longer exists.');
                $r = vdSaveField($db, $s, $k, (string)$c['value'], (string)$c['orig'], 'import');
                if (!$r['ok']) throw new DomainException('#' . $id . ' ' . $s['vessel_name'] . ': ' . $r['message']);
                if (!empty($r['changed'])) $done++;
            }
            $records++;
        }
        $db->commit();
    } catch (DomainException $e) {
        $db->rollBack();
        return ['ok' => false, 'error' => $e->getMessage() . ' Nothing was imported — upload the file again to see the latest values.'];
    }
    unset($_SESSION['vd_import']);
    return ['ok' => true, 'records' => $records, 'changes' => $done, 'message' => $done . ' change' . ($done === 1 ? '' : 's') . ' saved on ' . $records . ' vessel' . ($records === 1 ? '' : 's') . '.'];
}
