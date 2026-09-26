<?php
require_once 'config/config.php';
checkAuth();
if (($_SESSION['role'] ?? '') !== 'Super Admin') {
    header('Location: index.php');
    exit;
}

$db = getDB();

// 🌟 TEMPORARY PAGE — built only to let Super Admin wipe test data before
// go-live (see conversation). Not linked anywhere except the Super Admin
// sidebar/FAB, and gated to Super Admin only, same as the rest of the app's
// role checks. Remove this file (and its nav.php / footer.php links) once
// go-live testing is done and this is no longer needed.

$delete_targets = [
    'pending_vessel' => ['status' => 'Pending Vessel', 'label' => 'Pending Vessels'],
    'pending_report' => ['status' => 'Pending Report', 'label' => 'Pending Reports'],
    'cancelled'       => ['status' => 'Cancelled',      'label' => 'Cancelled Vessels'],
    'completed'       => ['status' => 'Completed',      'label' => 'Completed Vessels'],
];

$messages = [];
$errors = [];
$mergeMessages = [];
$mergeErrors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['merge_action'])) {
    $normName = trim((string)($_POST['group_norm_name'] ?? ''));
    $keepId = (int)($_POST['keep_id'] ?? 0);
    if ($normName === '' || $keepId <= 0) {
        $mergeErrors[$normName] = 'Invalid request.';
    } elseif (trim($_POST['confirm_text'] ?? '') !== 'MERGE') {
        $mergeErrors[$normName] = 'Please type MERGE exactly to confirm.';
    } else {
        try {
            // Re-fetch the group fresh from the DB rather than trusting the
            // posted row list, in case it changed since the page was loaded.
            $stmt = $db->prepare("SELECT id, user_id FROM clients WHERE LOWER(TRIM(company_name)) = ?");
            $stmt->execute([$normName]);
            $groupRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $groupIds = array_map(fn($r) => (int)$r['id'], $groupRows);

            if (count($groupRows) < 2 || !in_array($keepId, $groupIds, true)) {
                $mergeErrors[$normName] = 'This duplicate group has changed — please refresh and try again.';
            } else {
                $withLogin = array_filter($groupRows, fn($r) => !empty($r['user_id']));
                if (count($withLogin) > 1) {
                    $mergeErrors[$normName] = 'More than one row in this group already has a login attached — merging could disconnect a client, so this was skipped. Please resolve manually.';
                } else {
                    $db->beginTransaction();

                    $keepRow = null; $otherIds = [];
                    foreach ($groupRows as $r) {
                        if ((int)$r['id'] === $keepId) { $keepRow = $r; } else { $otherIds[] = (int)$r['id']; }
                    }
                    // If the kept row has no login but a duplicate does, adopt it.
                    if (empty($keepRow['user_id'])) {
                        foreach ($groupRows as $r) {
                            if ((int)$r['id'] !== $keepId && !empty($r['user_id'])) {
                                $upd = $db->prepare('UPDATE clients SET user_id = ? WHERE id = ?');
                                $upd->execute([$r['user_id'], $keepId]);
                                break;
                            }
                        }
                    }
                    $in = implode(',', array_fill(0, count($otherIds), '?'));
                    $reassign = $db->prepare("UPDATE surveys SET client_id = ? WHERE client_id IN ($in)");
                    $reassign->execute(array_merge([$keepId], $otherIds));
                    $del = $db->prepare("DELETE FROM clients WHERE id IN ($in)");
                    $del->execute($otherIds);

                    $db->commit();
                    $mergeMessages[$normName] = 'Merged into client #' . $keepId . '. Its vessels/surveys and login (if any) are now on this one row.';
                }
            }
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('super_admin_controls merge clients: ' . $e->getMessage());
            $mergeErrors[$normName] = 'Could not merge these records. Please try again.';
        }
    }
}

// ── Client Data Diagnostic (read-only) — traces username → user_id →
// client_id → company → per-status survey counts, using the exact same
// getClientIdForUser() helper the real dashboard/reports pages call. If the
// counts shown here are 0, the real dashboard's counts will be 0 for the
// same reason — this exists so a Super Admin can run it directly against
// whichever environment (staging or production) they're actually logged
// into, rather than guessing from the code alone. No writes; nothing is
// hardcoded — the username is typed in each time.
$diag = null;
$diagUsername = trim((string)($_GET['diag_username'] ?? ''));
if ($diagUsername !== '') {
    $diag = ['username' => $diagUsername];
    try {
        $uStmt = $db->prepare("SELECT u.id, u.full_name, r.name AS role_name FROM users u LEFT JOIN roles r ON r.id = u.role_id WHERE u.username = ? LIMIT 1");
        $uStmt->execute([$diagUsername]);
        $uRow = $uStmt->fetch(PDO::FETCH_ASSOC);
        if (!$uRow) {
            $diag['error'] = 'No user found with that exact username in this database.';
        } else {
            $diag['user_id'] = (int)$uRow['id'];
            $diag['full_name'] = $uRow['full_name'];
            $diag['role'] = $uRow['role_name'];
            if ($diag['role'] === 'Client') {
                $clientId = getClientIdForUser($db, $diag['user_id']);
                $diag['client_id'] = $clientId;
                if ($clientId <= 0) {
                    $diag['error'] = 'This login has role Client but no clients row has user_id = ' . $diag['user_id'] . '. The account exists but is not linked to any company, so every dashboard query returns zero — this is the duplicate/unlinked-client issue; check the Duplicate Client Companies section below, or re-run Add Client with the exact same company name to link it.';
                } else {
                    $cStmt = $db->prepare('SELECT company_name, contact_person FROM clients WHERE id = ?');
                    $cStmt->execute([$clientId]);
                    $cRow = $cStmt->fetch(PDO::FETCH_ASSOC);
                    $diag['company_name'] = $cRow['company_name'] ?? '';
                    $diag['contact_person'] = $cRow['contact_person'] ?? '';

                    $countsStmt = $db->prepare('SELECT status, COUNT(*) AS cnt FROM surveys WHERE client_id = ? GROUP BY status');
                    $countsStmt->execute([$clientId]);
                    $diag['status_counts'] = $countsStmt->fetchAll(PDO::FETCH_KEY_PAIR);

                    $totalStmt = $db->prepare('SELECT COUNT(*) FROM surveys WHERE client_id = ?');
                    $totalStmt->execute([$clientId]);
                    $diag['total_surveys'] = (int)$totalStmt->fetchColumn();

                    // Zero surveys on the linked row could also mean the real
                    // data sits under a differently-spelled clients row for
                    // the same company (e.g. "Norden" vs "Norden A/S") — the
                    // exact-match duplicate scan below wouldn't catch that.
                    // Surface any similarly-named row so it can be confirmed
                    // and fixed by hand rather than guessed at automatically.
                    if ($diag['total_surveys'] === 0) {
                        $firstWord = trim((string)strtok((string)$diag['company_name'], " \t"));
                        if (mb_strlen($firstWord) >= 3) {
                            $simStmt = $db->prepare("
                                SELECT c.id, c.company_name, c.user_id, u.username,
                                       (SELECT COUNT(*) FROM surveys s WHERE s.client_id = c.id) AS survey_count
                                FROM clients c
                                LEFT JOIN users u ON u.id = c.user_id
                                WHERE c.company_name LIKE ? AND c.id != ?
                                ORDER BY c.id
                            ");
                            $simStmt->execute(['%' . $firstWord . '%', $clientId]);
                            $diag['similar_clients'] = $simStmt->fetchAll(PDO::FETCH_ASSOC);
                        }
                    }
                }
            } else {
                $diag['note'] = 'This login\'s role is "' . $diag['role'] . '", not Client — the client-dashboard filter doesn\'t apply to it.';
            }
        }
    } catch (Throwable $e) {
        error_log('super_admin_controls client diagnostic: ' . $e->getMessage());
        $diag['error'] = 'Diagnostic query failed — see server error log for details.';
    }
}

$dbIdentityRow = null;
try {
    $dbIdentityRow = $db->query('SELECT DATABASE() AS db_name')->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) { /* non-fatal, just skips the "connected DB" label */ }

$dupClientGroups = [];
try {
    $dupStmt = $db->query("
        SELECT LOWER(TRIM(company_name)) AS norm_name, COUNT(*) AS cnt
        FROM clients
        GROUP BY LOWER(TRIM(company_name))
        HAVING COUNT(*) > 1
    ");
    foreach ($dupStmt->fetchAll(PDO::FETCH_ASSOC) as $g) {
        $rowsStmt = $db->prepare("
            SELECT c.id, c.company_name, c.contact_person, c.user_id, u.username,
                   (SELECT COUNT(*) FROM surveys s WHERE s.client_id = c.id) AS survey_count
            FROM clients c
            LEFT JOIN users u ON u.id = c.user_id
            WHERE LOWER(TRIM(c.company_name)) = ?
            ORDER BY c.id
        ");
        $rowsStmt->execute([$g['norm_name']]);
        $dupClientGroups[$g['norm_name']] = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    error_log('super_admin_controls dup client scan: ' . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_target'])) {
    $target = (string)$_POST['delete_target'];
    if (!isset($delete_targets[$target])) {
        $errors[$target] = 'Unknown delete target.';
    } elseif (trim($_POST['confirm_text'] ?? '') !== 'DELETE') {
        $errors[$target] = 'Please type DELETE exactly to confirm.';
    } else {
        try {
            $status = $delete_targets[$target]['status'];
            $countStmt = $db->prepare("SELECT COUNT(*) FROM surveys WHERE status = ?");
            $countStmt->execute([$status]);
            $count = (int)$countStmt->fetchColumn();

            $delStmt = $db->prepare("DELETE FROM surveys WHERE status = ?");
            $delStmt->execute([$status]);

            $messages[$target] = $count . ' ' . strtolower($delete_targets[$target]['label']) . ' record(s) permanently deleted.';
        } catch (Throwable $e) {
            error_log('super_admin_controls delete all (' . $target . '): ' . $e->getMessage());
            $errors[$target] = 'Could not delete ' . strtolower($delete_targets[$target]['label']) . '. Please try again.';
        }
    }
}

include 'includes/header.php';
?>
<style>
    .sac-banner {
        margin: 16px 16px 0;
        padding: 12px 14px;
        border-radius: 12px;
        background: #fffbeb;
        border: 1px solid #fde68a;
        color: #92400e;
        font-size: 12.5px;
        font-weight: 600;
        display: flex;
        align-items: flex-start;
        gap: 10px;
    }
    .sac-banner i { margin-top: 1px; }
    .admin-danger-zone {
        margin: 16px 16px 24px;
        padding: 16px;
        border: 1px solid rgba(239,68,68,.3);
        background: rgba(239,68,68,.05);
        border-radius: 14px;
    }
    .admin-danger-zone-title { color: #dc2626; font-weight: 700; font-size: 13px; margin-bottom: 10px; }
    .admin-danger-zone-row { display: flex; align-items: center; justify-content: space-between; gap: 14px; flex-wrap: wrap; }
    .admin-danger-zone-label { font-weight: 650; font-size: 13.5px; color: var(--text-dark); }
    .admin-danger-zone-copy { font-size: 12px; color: var(--text-muted); margin-top: 2px; max-width: 480px; }
    .admin-danger-zone-btn {
        flex: 0 0 auto; background: #dc2626; color: #fff; border: none; border-radius: 10px;
        padding: 10px 16px; font-weight: 650; font-size: 13px; white-space: nowrap;
    }
    @media (min-width: 992px) {
        .sac-banner, .admin-danger-zone, .admin-merge-zone { max-width: 900px; margin-left: auto; margin-right: auto; }
    }
    .admin-merge-zone {
        margin: 16px 16px 24px;
        padding: 16px;
        border: 1px solid rgba(59,50,179,.25);
        background: rgba(59,50,179,.04);
        border-radius: 14px;
    }
    .admin-merge-zone-title { color: #3b32b3; font-weight: 700; font-size: 13px; margin-bottom: 8px; }
    .admin-merge-zone-copy { font-size: 12px; color: var(--text-muted); margin-bottom: 14px; max-width: 640px; }
    .amz-group { border: 1px solid var(--border-color); border-radius: 10px; padding: 12px; margin-bottom: 10px; background: #fff; }
    .amz-company { font-weight: 700; font-size: 13px; margin-bottom: 8px; }
    .amz-row { display: flex; gap: 8px; align-items: flex-start; font-size: 12.5px; margin-bottom: 8px; cursor: pointer; }
    .amz-row input { margin-top: 3px; }
    .amz-confirm { display: flex; gap: 8px; margin-top: 8px; flex-wrap: wrap; }
    .amz-confirm input { max-width: 160px; }
    .amz-btn {
        flex: 0 0 auto; background: #3b32b3; color: #fff; border: none; border-radius: 10px;
        padding: 10px 16px; font-weight: 650; font-size: 13px; white-space: nowrap;
    }
    .admin-diag-zone {
        margin: 16px 16px 24px;
        padding: 16px;
        border: 1px solid rgba(29,111,224,.3);
        background: rgba(29,111,224,.04);
        border-radius: 14px;
    }
    .admin-diag-zone-title { color: #1d6fe0; font-weight: 700; font-size: 13px; margin-bottom: 8px; }
    .admin-diag-zone-copy { font-size: 12px; color: var(--text-muted); margin-bottom: 14px; max-width: 640px; }
    .diag-form { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-bottom: 14px; }
    .diag-form input[type=text] { max-width: 260px; }
    .diag-btn {
        flex: 0 0 auto; background: #1d6fe0; color: #fff; border: none; border-radius: 10px;
        padding: 10px 16px; font-weight: 650; font-size: 13px; white-space: nowrap;
    }
    .diag-result { border: 1px solid var(--border-color); border-radius: 10px; padding: 12px; background: #fff; font-size: 12.5px; line-height: 1.9; }
    @media (min-width: 992px) {
        .admin-diag-zone { max-width: 900px; margin-left: auto; margin-right: auto; }
    }
</style>

<div class="scroll-content">
    <?php $page_title = 'Super Admin — Data Cleanup'; $back_url = 'index.php'; $page_testid = 'super-admin-controls'; include 'includes/top_app_bar.php'; ?>

    <div class="sac-banner" data-testid="sac-temporary-banner">
        <i class="fa-solid fa-triangle-exclamation"></i>
        <span>Temporary tool — for clearing test data before go-live only. Each action below permanently deletes every record in that category, with no way to undo it. Remove this page once testing is complete.</span>
    </div>

    <div class="admin-diag-zone" data-testid="sac-client-diagnostic-section">
        <div class="admin-diag-zone-title"><i class="fa-solid fa-magnifying-glass-chart"></i> Client Data Diagnostic (read-only)</div>
        <div class="admin-diag-zone-copy">
            Type a client's login username to trace exactly what <b>this running server</b> sees for that account — which database it's connected to, the resolved client record, and live vessel/report counts by status. Nothing here changes any data. Run this directly on whichever site (staging or production) is showing the problem — the answer only means anything for the environment you're actually logged into right now.
        </div>
        <form method="GET" class="diag-form">
            <input type="text" name="diag_username" class="form-control" placeholder="e.g. bainbridge_login" value="<?= sanitize($diagUsername) ?>" autocomplete="off">
            <button type="submit" class="diag-btn"><i class="fa-solid fa-magnifying-glass"></i> Diagnose</button>
            <?php if ($dbIdentityRow): ?>
                <span class="text-muted d-flex align-items-center" style="font-size:12px;">Connected database: <b style="margin-left:4px;"><?= sanitize($dbIdentityRow['db_name']) ?></b></span>
            <?php endif; ?>
        </form>
        <?php if ($diag): ?>
        <div class="diag-result" data-testid="sac-client-diagnostic-result">
            <?php if (!empty($diag['error'])): ?>
                <div class="alert alert-danger py-2 mb-2"><?= sanitize($diag['error']) ?></div>
            <?php endif; ?>
            <?php if (!empty($diag['user_id'])): ?>
                <div><b>Username:</b> <?= sanitize($diag['username']) ?></div>
                <div><b>user_id:</b> <?= (int)$diag['user_id'] ?></div>
                <div><b>Full name:</b> <?= sanitize($diag['full_name'] ?? '') ?></div>
                <div><b>Role:</b> <?= sanitize($diag['role'] ?? '') ?></div>
                <?php if (!empty($diag['note'])): ?><div class="text-muted"><?= sanitize($diag['note']) ?></div><?php endif; ?>
                <?php if (isset($diag['client_id'])): ?>
                    <div><b>Resolved client_id:</b> <?= $diag['client_id'] > 0 ? (int)$diag['client_id'] : '— none (unlinked) —' ?></div>
                <?php endif; ?>
                <?php if (!empty($diag['company_name'])): ?>
                    <div><b>Company:</b> <?= sanitize($diag['company_name']) ?><?php if (!empty($diag['contact_person'])): ?> (<?= sanitize($diag['contact_person']) ?>)<?php endif; ?></div>
                <?php endif; ?>
                <?php if (isset($diag['total_surveys'])): ?>
                    <div><b>Total surveys with this client_id:</b> <?= (int)$diag['total_surveys'] ?></div>
                    <?php if (!empty($diag['status_counts'])): ?>
                        <div style="margin-top:4px;"><b>By status:</b>
                            <?php foreach ($diag['status_counts'] as $st => $cnt): ?>
                                <span class="badge bg-light text-dark border me-1"><?= sanitize($st) ?>: <?= (int)$cnt ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php elseif ((int)$diag['total_surveys'] === 0): ?>
                        <div class="text-muted mt-1">No surveys exist anywhere in <b><?= sanitize($dbIdentityRow['db_name'] ?? 'this database') ?></b> with client_id = <?= (int)$diag['client_id'] ?>. Nothing has actually been assigned to "<?= sanitize($diag['company_name'] ?? '') ?>" in THIS environment yet — check Assign Vessel / Pending Vessels here, not in a different environment (staging and production are separate databases).</div>
                        <?php if (!empty($diag['similar_clients'])): ?>
                        <div class="mt-2" style="border-top:1px dashed var(--border-color); padding-top:8px;">
                            <b>Similarly-named client rows found</b> — the real data may be sitting under one of these instead (different spelling of the same company name, so the automatic duplicate check above didn't group them together):
                            <?php foreach ($diag['similar_clients'] as $sc): ?>
                                <div class="mt-1">
                                    Client #<?= (int)$sc['id'] ?> — "<?= sanitize($sc['company_name']) ?>":
                                    <b><?= (int)$sc['survey_count'] ?></b> survey(s)<?= $sc['username'] ? ', login: ' . sanitize($sc['username']) : ', no login' ?>
                                </div>
                            <?php endforeach; ?>
                            <div class="text-muted mt-1">If one of these clearly has the real vessels, rename it (Admin Controls → Clients) to exactly match "<?= sanitize($diag['company_name'] ?? '') ?>" — the Duplicate Client Companies tool below will then pick it up for a safe Merge, without touching data by hand.</div>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($dupClientGroups): ?>
    <div class="admin-merge-zone" data-testid="sac-merge-clients-section">
        <div class="admin-merge-zone-title"><i class="fa-solid fa-code-merge"></i> Duplicate Client Companies Found</div>
        <div class="admin-merge-zone-copy">
            The same company name appears more than once in Clients. This happens when "Add Client" created a new login for a company that already existed — the login then points at an empty duplicate while the real vessels stay on the original row, so that client sees nothing after logging in. Pick which row to <b>keep</b> (usually the one with vessels/surveys attached) and merge — the other row's login (if any) moves onto the kept row, its vessels are reassigned, and the empty duplicate is removed.
        </div>
        <?php foreach ($dupClientGroups as $norm => $rows):
            $defaultKeepId = $rows[0]['id'];
            $bestCount = -1;
            foreach ($rows as $r) { if ((int)$r['survey_count'] > $bestCount) { $bestCount = (int)$r['survey_count']; $defaultKeepId = $r['id']; } }
        ?>
        <div class="amz-group" data-testid="sac-merge-group-<?= sanitize(md5($norm)) ?>">
            <?php if (!empty($mergeMessages[$norm])): ?><div class="alert alert-success py-2 mb-2"><?= sanitize($mergeMessages[$norm]) ?></div><?php endif; ?>
            <?php if (!empty($mergeErrors[$norm])): ?><div class="alert alert-danger py-2 mb-2"><?= sanitize($mergeErrors[$norm]) ?></div><?php endif; ?>
            <div class="amz-company"><?= sanitize($rows[0]['company_name']) ?> (<?= count($rows) ?> rows)</div>
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="merge_action" value="1">
                <input type="hidden" name="group_norm_name" value="<?= sanitize($norm) ?>">
                <?php foreach ($rows as $r): ?>
                    <label class="amz-row">
                        <input type="radio" name="keep_id" value="<?= (int)$r['id'] ?>" <?= ((int)$r['id'] === (int)$defaultKeepId) ? 'checked' : '' ?>>
                        <span>
                            Client #<?= (int)$r['id'] ?><?= $r['contact_person'] ? ' — ' . sanitize($r['contact_person']) : '' ?><br>
                            <span class="text-muted"><?= (int)$r['survey_count'] ?> vessel(s)/survey(s) attached<?= $r['username'] ? ' · has login: ' . sanitize($r['username']) : ' · no login' ?></span>
                        </span>
                    </label>
                <?php endforeach; ?>
                <div class="amz-confirm">
                    <input type="text" name="confirm_text" class="form-control" placeholder="Type MERGE" required autocomplete="off">
                    <button type="submit" class="amz-btn"><i class="fa-solid fa-code-merge"></i> Merge Selected</button>
                </div>
            </form>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php foreach ($delete_targets as $key => $cfg): ?>
    <div class="admin-danger-zone" data-testid="sac-danger-zone-<?= sanitize($key) ?>">
        <div class="admin-danger-zone-title"><i class="fa-solid fa-triangle-exclamation"></i> Danger Zone — <?= sanitize($cfg['label']) ?></div>
        <?php if (!empty($messages[$key])): ?>
            <div class="alert alert-success py-2 mb-2"><?= sanitize($messages[$key]) ?></div>
        <?php endif; ?>
        <?php if (!empty($errors[$key])): ?>
            <div class="alert alert-danger py-2 mb-2"><?= sanitize($errors[$key]) ?></div>
        <?php endif; ?>
        <div class="admin-danger-zone-row">
            <div>
                <div class="admin-danger-zone-label">Delete all <?= sanitize($cfg['label']) ?></div>
                <div class="admin-danger-zone-copy">Permanently deletes every record currently in <?= sanitize($cfg['label']) ?>, along with any uploaded survey files and reports linked to them. This cannot be undone.</div>
            </div>
            <button type="button" class="admin-danger-zone-btn" data-bs-toggle="modal" data-bs-target="#sacDeleteModal-<?= sanitize($key) ?>" data-testid="sac-delete-all-<?= sanitize($key) ?>-btn">
                <i class="fa-solid fa-trash"></i> Delete All
            </button>
        </div>
    </div>

    <div class="modal fade" id="sacDeleteModal-<?= sanitize($key) ?>" tabindex="-1" data-testid="sac-delete-modal-<?= sanitize($key) ?>">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border-radius: 16px; border: none;">
                <div class="modal-header" style="border-bottom: 1px solid var(--border-color);">
                    <h5 class="modal-title fw-bold text-danger" style="font-size: 15px;"><i class="fa-solid fa-triangle-exclamation me-1"></i> Delete all <?= sanitize($cfg['label']) ?>?</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST"><?= csrf_field() ?>
                    <input type="hidden" name="delete_target" value="<?= sanitize($key) ?>">
                    <div class="modal-body">
                        <p class="text-muted" style="font-size:13px;">This permanently deletes every <?= sanitize(strtolower($cfg['label'])) ?> record and its uploaded files. This action cannot be undone.</p>
                        <label class="form-label" style="font-size:12px;font-weight:650;">Type <b>DELETE</b> to confirm</label>
                        <input type="text" name="confirm_text" class="form-control" placeholder="DELETE" required autocomplete="off">
                    </div>
                    <div class="modal-footer" style="border-top: 1px solid var(--border-color);">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger"><i class="fa-solid fa-trash me-1"></i> Delete All</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php include 'includes/nav.php'; include 'includes/footer.php'; ?>
