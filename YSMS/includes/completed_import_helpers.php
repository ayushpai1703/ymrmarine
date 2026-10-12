<?php
/**
 * Bulk import of already-completed vessels from Excel (Admin only).
 *
 * Accepts the same layout export_completed.php produces (country banner
 * rows + repeated header rows), or any sheet with recognisable headers.
 * Rows are inserted straight into surveys with status = 'Completed'.
 * No emails / notifications are sent and no files are attached.
 *
 * Reuses the lookups and date parser from bulk_assign_helpers.php.
 */

require_once __DIR__ . '/bulk_assign_helpers.php';
require_once __DIR__ . '/report_number.php';

function completedImportAliases(): array {
    return [
        'vessel_name'    => ['vessel name', 'vessel', 'ship name', 'ship'],
        'client'         => ['client', 'client name', 'company', 'company name'],
        'agent'          => ['agent', 'agent name', 'agents'],
        'port'           => ['port', 'port name', 'survey place', 'place'],
        'country'        => ['country'],
        'survey_type'    => ['survey type', 'survey types', 'type of survey'],
        'surveyor'       => ['surveyor', 'surveyors', 'surveyor name'],
        'recovery'       => ['recovery mt', 'recovery', 'total recovery', 'total recovery mt'],
        'vlsfo'          => ['vlsfo', 'vlsfo mt', 'vlsfo recovery'],
        'hsfo'           => ['hsfo', 'hsfo mt', 'hsfo recovery'],
        'lsmgo'          => ['lsmgo', 'lsmgo mt', 'lsmgo recovery'],
        'report_date'    => ['report uploaded date', 'report upload date', 'report date', 'uploaded date', 'upload date'],
        'completed_date' => ['survey completed date', 'completed date', 'completion date'],
        'assign_date'    => ['assign date', 'assigned date', 'assignment date'],
        'report_number'  => ['report no', 'report number', 'report num', 'ref no'],
        'imo'            => ['imo', 'imo number', 'imo no'],
        'remarks'        => ['remarks', 'remark', 'notes'],
    ];
}

function completedImportRequiredFields(): array {
    return ['vessel_name' => 'Vessel Name', 'client' => 'Client', 'port' => 'Port',
            'survey_type' => 'Survey Type', 'surveyor' => 'Surveyor', 'report_date' => 'Report Uploaded Date'];
}

function completedImportNormHeader($h): string {
    $h = strtolower(trim((string)$h));
    $h = preg_replace('/[^a-z0-9]+/', ' ', $h);
    return trim($h);
}

/** Field key for a header cell, or '' when not recognised. */
function completedImportFieldFor($h): string {
    $n = completedImportNormHeader($h);
    if ($n === '') return '';
    foreach (completedImportAliases() as $field => $aliases) {
        if (in_array($n, $aliases, true)) return $field;
    }
    return '';
}

/**
 * Reads every sheet and returns the data rows as field => raw value arrays.
 * Header rows are any row whose cells include "Vessel Name"; a later header
 * row overrides earlier positions only where its cell is non-blank (the
 * export leaves VLSFO/HSFO/LSMGO blank on repeated headers). Rows with a
 * single non-empty cell are country banners and are skipped.
 *
 * @return array{rows: array<int,array>, missing: array<int,string>, header_found: bool}
 */
function completedImportReadRows($spreadsheet): array {
    $out = [];
    $headerFound = false;
    $seenFields = [];
    foreach ($spreadsheet->getAllSheets() as $sheet) {
        $sheetName = $sheet->getTitle();
        $map = [];
        // formatted = false -> numbers and date serials come through raw
        $rows = $sheet->toArray(null, true, false, false);
        foreach ($rows as $i => $cells) {
            $nonEmpty = 0;
            foreach ($cells as $c) { if (trim((string)$c) !== '') $nonEmpty++; }
            if ($nonEmpty === 0) continue;

            $isHeader = false;
            foreach ($cells as $c) {
                if (completedImportFieldFor($c) === 'vessel_name') { $isHeader = true; break; }
            }
            if ($isHeader) {
                $headerFound = true;
                foreach ($cells as $idx => $c) {
                    $f = completedImportFieldFor($c);
                    if ($f === '') continue;
                    foreach ($map as $k => $v) { if ($v === $idx) unset($map[$k]); }
                    $map[$f] = $idx;
                    $seenFields[$f] = true;
                }
                continue;
            }
            if (empty($map) || $nonEmpty < 2) continue; // before first header, or a country banner

            $row = ['_sheet' => $sheetName, '_row' => $i + 1];
            foreach ($map as $f => $idx) {
                $v = $cells[$idx] ?? '';
                $row[$f] = is_string($v) ? trim($v) : $v;
            }
            if (trim((string)($row['vessel_name'] ?? '')) === '') continue;
            $out[] = $row;
        }
    }
    $missing = [];
    if ($headerFound) {
        foreach (completedImportRequiredFields() as $f => $label) {
            if (empty($seenFields[$f])) $missing[] = $label;
        }
    }
    return ['rows' => $out, 'missing' => $missing, 'header_found' => $headerFound];
}

/** Parses a recovery cell. Returns [ok(bool), value(float|null)]. */
function completedImportParseQty($raw): array {
    if ($raw === null) return [true, null];
    if (is_int($raw) || is_float($raw)) return [true, round((float)$raw, 3)];
    $s = trim((string)$raw);
    if ($s === '' || preg_match('/^(n\/?a|na|nil|-+|—|–)\b/i', $s)) return [true, null];
    $s = preg_replace('/\s*(mt|m\.t\.?)$/i', '', $s);
    $s = str_replace(',', '', $s);
    if (!is_numeric($s)) return [false, null];
    return [true, round((float)$s, 3)];
}

/** Any surveyor (active or not — historical data) by id / email / name. */
function completedImportFindSurveyor(PDO $db, string $raw): ?array {
    $raw = trim($raw);
    if ($raw === '') return null;
    $found = bulkAssignFindSurveyor($db, $raw); // active surveyors first
    if ($found) return $found;
    $stmt = $db->prepare("SELECT id, full_name, email FROM users WHERE role_id = 2 AND LOWER(TRIM(full_name)) = LOWER(?) LIMIT 2");
    $stmt->execute([$raw]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return count($rows) === 1 ? $rows[0] : null;
}

/** @return array{id:int,port_name:string,status:string}|null  status: exact|partial|ambiguous */
function completedImportFindPort(PDO $db, string $raw): ?array {
    $raw = trim($raw);
    if ($raw === '') return null;
    $stmt = $db->prepare("SELECT id, port_name FROM ports WHERE LOWER(TRIM(port_name)) = LOWER(?) LIMIT 1");
    $stmt->execute([$raw]);
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) return $row + ['status' => 'exact'];
    $stmt = $db->prepare("SELECT id, port_name FROM ports WHERE port_name LIKE ? LIMIT 2");
    $stmt->execute(['%' . $raw . '%']);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) === 1) return $rows[0] + ['status' => 'partial'];
    if (count($rows) > 1) return ['id' => 0, 'port_name' => '', 'status' => 'ambiguous'];
    return null;
}

/** Splits "Offhire + Draft Survey" / "A + B" combinations. */
function completedImportSplitCombo(string $raw): array {
    $parts = preg_split('/\s*\+\s*/', trim($raw)) ?: [];
    return array_values(array_filter(array_map('trim', $parts), fn($p) => $p !== ''));
}

function completedImportFindExisting(PDO $db, string $vessel, int $clientId, int $portId, string $reportDate, string $reportNumber): ?array {
    if ($reportNumber !== '') {
        $stmt = $db->prepare("SELECT id, report_number FROM surveys WHERE report_number = ? LIMIT 1");
        $stmt->execute([$reportNumber]);
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) return $row;
    }
    if ($clientId <= 0 || $portId <= 0) return null;
    $stmt = $db->prepare("
        SELECT id, report_number FROM surveys
        WHERE status = 'Completed' AND LOWER(TRIM(vessel_name)) = LOWER(?)
          AND client_id = ? AND port_id = ? AND DATE(report_uploaded_date) = ?
        LIMIT 1
    ");
    $stmt->execute([trim($vessel), $clientId, $portId, substr($reportDate, 0, 10)]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Resolves one row against the database. Never writes.
 * status: ready | duplicate | error. 'warnings' are informational only.
 */
function completedImportValidateRow(PDO $db, array $r, array &$seenKeys): array {
    $str = fn($k) => trim((string)($r[$k] ?? ''));
    $res = [
        'sheet' => $r['_sheet'] ?? '', 'row' => (int)($r['_row'] ?? 0),
        'vessel_name' => $str('vessel_name'), 'client_raw' => $str('client'), 'agent_name' => $str('agent'),
        'port_raw' => $str('port'), 'country' => $str('country'), 'survey_type_raw' => $str('survey_type'),
        'surveyor_raw' => $str('surveyor'), 'remarks' => $str('remarks'),
        'imo_number' => bulkAssignNormalizeImo($str('imo')), 'report_number' => $str('report_number'),
        'client_id' => 0, 'client_name' => '', 'port_id' => 0, 'port_name' => '', 'create_port' => false,
        'survey_type_ids' => [], 'survey_type_names' => [], 'surveyor_ids' => [], 'surveyor_names' => [],
        'recovery_amount' => null, 'vlsfo' => null, 'hsfo' => null, 'lsmgo' => null, 'recovery_na' => false,
        'report_date' => null, 'completed_date' => null, 'assign_date' => null,
        'errors' => [], 'warnings' => [], 'status' => 'ready', 'existing_id' => 0,
    ];
    $err = function (string $m) use (&$res) { $res['errors'][] = $m; };

    if ($res['vessel_name'] === '') $err('Vessel name is empty.');

    if ($res['client_raw'] === '') {
        $err('Client is empty.');
    } elseif ($c = bulkAssignFindClient($db, $res['client_raw'])) {
        $res['client_id'] = (int)$c['id'];
        $res['client_name'] = (string)$c['company_name'];
    } else {
        $err('Client "' . $res['client_raw'] . '" not found — add the client first.');
    }

    if ($res['port_raw'] === '') {
        $err('Port is empty.');
    } else {
        $p = completedImportFindPort($db, $res['port_raw']);
        if ($p && $p['status'] === 'ambiguous') {
            $err('Port "' . $res['port_raw'] . '" matches more than one port — use the exact port name.');
        } elseif ($p) {
            $res['port_id'] = (int)$p['id'];
            $res['port_name'] = (string)$p['port_name'];
            if ($p['status'] === 'partial') $res['warnings'][] = 'Port matched to "' . $p['port_name'] . '".';
        } else {
            $res['create_port'] = true;
            $res['port_name'] = $res['port_raw'];
            $res['warnings'][] = 'New port "' . $res['port_raw'] . '"' . ($res['country'] !== '' ? ' (' . $res['country'] . ')' : '') . ' will be created.';
        }
    }

    $types = completedImportSplitCombo($res['survey_type_raw']);
    if (!$types) $err('Survey type is empty.');
    foreach ($types as $t) {
        if ($st = bulkAssignFindSurveyType($db, $t)) {
            if (!in_array((int)$st['id'], $res['survey_type_ids'], true)) {
                $res['survey_type_ids'][] = (int)$st['id'];
                $res['survey_type_names'][] = (string)$st['type_name'];
            }
        } else {
            $err('Survey type "' . $t . '" not found.');
        }
    }

    $surveyors = completedImportSplitCombo($res['surveyor_raw']);
    if (!$surveyors) $err('Surveyor is empty.');
    foreach ($surveyors as $s) {
        if ($u = completedImportFindSurveyor($db, $s)) {
            if (!in_array((int)$u['id'], $res['surveyor_ids'], true)) {
                $res['surveyor_ids'][] = (int)$u['id'];
                $res['surveyor_names'][] = (string)$u['full_name'];
            }
        } else {
            $err('Surveyor "' . $s . '" not found.');
        }
    }

    $res['report_date'] = bulkAssignParseDate($r['report_date'] ?? '');
    if ($res['report_date'] === null) {
        $err(trim((string)($r['report_date'] ?? '')) === '' ? 'Report uploaded date is empty.' : 'Report uploaded date "' . $r['report_date'] . '" is not a valid date.');
    }
    foreach (['completed_date', 'assign_date'] as $k) {
        $raw = $r[$k] ?? '';
        if (trim((string)$raw) === '') continue;
        $res[$k] = bulkAssignParseDate($raw);
        if ($res[$k] === null) $err(ucfirst(str_replace('_', ' ', $k)) . ' "' . $raw . '" is not a valid date.');
    }
    $res['completed_date'] = $res['completed_date'] ?? $res['report_date'];
    $res['assign_date'] = $res['assign_date'] ?? $res['completed_date'];

    if ($res['survey_type_ids']) {
        $res['recovery_na'] = isRecoveryNotApplicable($db, implode(',', $res['survey_type_ids']));
    }
    if (!$res['recovery_na']) {
        foreach (['recovery' => 'recovery_amount', 'vlsfo' => 'vlsfo', 'hsfo' => 'hsfo', 'lsmgo' => 'lsmgo'] as $src => $dst) {
            [$ok, $val] = completedImportParseQty($r[$src] ?? null);
            if (!$ok) { $err(($src === 'recovery' ? 'Recovery' : strtoupper($src)) . ' value "' . $r[$src] . '" is not a number.'); continue; }
            $res[$dst] = ($val !== null && $val != 0.0) ? $val : null;
        }
        if ($res['recovery_amount'] === null && ($res['vlsfo'] !== null || $res['hsfo'] !== null || $res['lsmgo'] !== null)) {
            $res['recovery_amount'] = round((float)$res['vlsfo'] + (float)$res['hsfo'] + (float)$res['lsmgo'], 3);
        }
    }

    if ($res['errors']) {
        $res['status'] = 'error';
        return $res;
    }

    $key = strtolower($res['vessel_name']) . '|' . $res['client_id'] . '|' . strtolower($res['port_name']) . '|' . substr((string)$res['report_date'], 0, 10);
    if (isset($seenKeys[$key])) {
        $res['status'] = 'duplicate';
        $res['warnings'][] = 'Same vessel/client/port/date as row ' . $seenKeys[$key] . ' in this file.';
        return $res;
    }
    $seenKeys[$key] = $res['row'];

    $existing = completedImportFindExisting($db, $res['vessel_name'], $res['client_id'], $res['port_id'], (string)$res['report_date'], $res['report_number']);
    if ($existing) {
        $res['status'] = 'duplicate';
        $res['existing_id'] = (int)$existing['id'];
        $res['warnings'][] = 'Already in Completed' . (!empty($existing['report_number']) ? ' (' . $existing['report_number'] . ')' : '') . '.';
    }
    return $res;
}

/** Next YMR/{SHORT}/{YYYY}/{MM}/{NNNN} using the report's own month, not today's. */
function completedImportNextReportNumber(PDO $db, string $short, string $date): string {
    $ts = strtotime($date) ?: time();
    $prefix = 'YMR/' . $short . '/' . date('Y', $ts) . '/' . date('m', $ts) . '/';
    $stmt = $db->prepare("SELECT report_number FROM surveys WHERE report_number LIKE ?");
    $stmt->execute([$prefix . '%']);
    $max = 0;
    while ($rn = $stmt->fetchColumn()) {
        if (preg_match('#/(\d{4})$#', (string)$rn, $m)) $max = max($max, (int)$m[1]);
    }
    return $prefix . str_pad((string)($max + 1), 4, '0', STR_PAD_LEFT);
}

function completedImportSurveyColumns(PDO $db): array {
    static $cols = null;
    if ($cols === null) {
        $cols = [];
        foreach ($db->query("SHOW COLUMNS FROM surveys")->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $cols[strtolower($c['Field'])] = true;
        }
    }
    return $cols;
}

/** Inserts one validated ('ready') row. @return array{ok:bool,survey_id:int,report_number:string,error:string} */
function completedImportInsertRow(PDO $db, array $v, string $actorName): array {
    $cols = completedImportSurveyColumns($db);
    // Both of these may run DDL (ALTER TABLE), which would implicitly commit
    // an open transaction — so they must run before beginTransaction().
    static $columnWidened = false;
    if (!$columnWidened) { ensureReportNumberColumnWide($db); $columnWidened = true; }
    $short = $v['report_number'] === '' ? ensureClientHasShortCode($db, (int)$v['client_id']) : '';
    for ($attempt = 0; $attempt < 5; $attempt++) {
        try {
            $db->beginTransaction();

            $portId = (int)$v['port_id'];
            if ($v['create_port']) {
                $chk = $db->prepare("SELECT id FROM ports WHERE LOWER(TRIM(port_name)) = LOWER(?) LIMIT 1");
                $chk->execute([$v['port_name']]);
                $portId = (int)($chk->fetchColumn() ?: 0);
                if ($portId <= 0) {
                    $db->prepare("INSERT INTO ports (port_name, country) VALUES (?, ?)")
                       ->execute([$v['port_name'], $v['country'] !== '' ? $v['country'] : 'India']);
                    $portId = (int)$db->lastInsertId();
                }
            }

            $reportNumber = $v['report_number'] !== '' ? $v['report_number'] : completedImportNextReportNumber($db, $short, (string)$v['report_date']);
            $firstSurveyor = (int)$v['surveyor_ids'][0];
            $data = [
                'vessel_name'           => $v['vessel_name'],
                'report_number'         => $reportNumber,
                'client_id'             => (int)$v['client_id'],
                'agent_name'            => $v['agent_name'] !== '' ? $v['agent_name'] : 'N/A',
                'surveyor_id'           => $firstSurveyor,
                'survey_type_id'        => (int)$v['survey_type_ids'][0],
                'survey_type_ids'       => implode(',', $v['survey_type_ids']),
                'port_id'               => $portId,
                'assign_date'           => $v['assign_date'],
                'survey_completed_date' => $v['completed_date'],
                'report_uploaded_date'  => $v['report_date'],
                'status'                => 'Completed',
                'remarks'               => $v['remarks'] !== '' ? $v['remarks'] : null,
                'notes'                 => 'Imported from Excel by ' . $actorName . ' on ' . date('d-m-Y') . '.',
                'imo_number'            => $v['imo_number'] !== '' ? $v['imo_number'] : null,
                'recovery_amount'       => $v['recovery_amount'],
                'vlsfo_recovery'        => $v['vlsfo'],
                'hsfo_recovery'         => $v['hsfo'],
                'lsmgo_recovery'        => $v['lsmgo'],
                'status_updated_by'     => $firstSurveyor,
                'status_updated_at'     => $v['report_date'],
                'client_submitted'      => 0,
            ];
            $data = array_filter($data, fn($k) => isset($cols[$k]), ARRAY_FILTER_USE_KEY);
            $names = array_keys($data);
            $sql = 'INSERT INTO surveys (`' . implode('`, `', $names) . '`) VALUES (' . implode(', ', array_fill(0, count($names), '?')) . ')';
            $db->prepare($sql)->execute(array_values($data));
            $surveyId = (int)$db->lastInsertId();

            $ins = $db->prepare("INSERT IGNORE INTO survey_surveyors (survey_id, surveyor_id) VALUES (?, ?)");
            foreach ($v['surveyor_ids'] as $sid) {
                $ins->execute([$surveyId, (int)$sid]);
            }

            $db->commit();
            return ['ok' => true, 'survey_id' => $surveyId, 'report_number' => $reportNumber, 'error' => ''];
        } catch (PDOException $e) {
            if ($db->inTransaction()) $db->rollBack();
            $dup = $e->getCode() == 23000 || stripos($e->getMessage(), 'Duplicate') !== false;
            if ($dup && $v['report_number'] === '') { usleep(30000); continue; } // generated number raced — retry
            error_log('completedImportInsertRow: ' . $e->getMessage());
            return ['ok' => false, 'survey_id' => 0, 'report_number' => '',
                    'error' => $dup ? 'Report number "' . $v['report_number'] . '" is already used.' : 'Database error while saving this row.'];
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('completedImportInsertRow: ' . $e->getMessage());
            return ['ok' => false, 'survey_id' => 0, 'report_number' => '', 'error' => 'Unexpected error while saving this row.'];
        }
    }
    return ['ok' => false, 'survey_id' => 0, 'report_number' => '', 'error' => 'Could not generate a unique report number.'];
}
