<?php
/**
 * Surveys for the Sales role, view only (includes/sales.php): every survey with vessel, client,
 * port, survey type, status and dates — to tell a client where their survey stands. No
 * invoice data or report files; no links into the vessel pages.
 */
require_once 'config/config.php';
require_once 'includes/sales.php';
checkAuth();
if (!salesIsSales()) { header('Location: index.php'); exit; }
$db = getDB();
salesEnsure($db);
$STATUSES = ['Pending Vessel', 'Pending Report', 'Completed', 'Cancelled'];
$status = in_array($_GET['status'] ?? '', $STATUSES, true) ? (string)$_GET['status'] : '';
$client = max(0, (int)($_GET['client'] ?? 0));
$month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)($_GET['month'] ?? '')) ? (string)$_GET['month'] : '';
$qs = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 80);
$where = ['1=1']; $p = [];
if ($client) { $where[] = 's.client_id = ?'; $p[] = $client; }
if ($month) { [$f, $t] = salesMonthRange($month); $where[] = 's.assign_date >= ? AND s.assign_date < ?'; array_push($p, $f, $t); }
if ($qs !== '') { $like = '%' . addcslashes($qs, '%_\\') . '%'; $where[] = '(s.vessel_name LIKE ? OR s.report_number LIKE ? OR c.company_name LIKE ? OR pt.port_name LIKE ?)'; array_push($p, $like, $like, $like, $like); }
$from = 'FROM surveys s LEFT JOIN clients c ON c.id = s.client_id LEFT JOIN ports pt ON pt.id = s.port_id LEFT JOIN survey_types st ON st.id = s.survey_type_id';
$cnt = $db->prepare("SELECT s.status, COUNT(*) $from WHERE " . implode(' AND ', $where) . ' GROUP BY s.status');
$cnt->execute($p);
$counts = array_fill_keys($STATUSES, 0);
foreach ($cnt->fetchAll(PDO::FETCH_NUM) as [$st, $n]) $counts[$st] = (int)$n;
if ($status) { $where[] = 's.status = ?'; $p[] = $status; }
$total = $status ? $counts[$status] : array_sum($counts);
$per = 50; $pages = max(1, (int)ceil($total / $per)); $page = min(max(1, (int)($_GET['page'] ?? 1)), $pages);
$rows = $db->prepare("SELECT s.vessel_name, s.report_number, s.status, s.assign_date, s.appointment_date, s.survey_completed_date, s.survey_type_ids, st.type_name, c.company_name, pt.port_name
    $from WHERE " . implode(' AND ', $where) . ' ORDER BY s.assign_date DESC, s.id DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per));
$rows->execute($p);
$rows = $rows->fetchAll(PDO::FETCH_ASSOC);
$clients = $db->query('SELECT id, company_name FROM clients ORDER BY company_name')->fetchAll(PDO::FETCH_ASSOC);
$months = [];
for ($i = 0; $i < 18; $i++) $months[] = date('Y-m', strtotime(date('Y-m-01') . ' -' . $i . ' month'));
$pill = ['Pending Vessel' => 'pv', 'Pending Report' => 'pr', 'Completed' => 'co', 'Cancelled' => 'ca'];
$url = function (array $over) use ($status, $client, $month, $qs) {
    $a = array_filter(array_merge(['status' => $status, 'client' => $client ?: '', 'month' => $month, 'q' => $qs], $over), fn($v) => $v !== '' && $v !== null);
    return 'sales_surveys.php' . ($a ? '?' . http_build_query($a) : '');
};
$d = fn($v) => $v && strpos((string)$v, '0000') !== 0 ? date('d M Y', strtotime($v)) : '<span class="sl-muted">—</span>';
$page_title = 'Surveys';
include 'includes/header.php';
?>
<link rel="stylesheet" href="assets/css/sales.css?v=<?= @filemtime(__DIR__ . '/assets/css/sales.css') ?>">
<div class="scroll-content" data-testid="sales-surveys-page">
<?php $back_url = 'sales_dashboard.php'; $page_testid = 'sales-surveys'; include 'includes/top_app_bar.php'; ?>
<div class="sl-wrap">
    <div class="sl-head">
        <div><h1><i class="fa-solid fa-ship"></i> Surveys</h1><div class="sl-sub">Every client's surveys and where they stand (view only)</div></div>
        <div class="sl-actions"><a class="sl-btn pri" href="assign_vessel.php"><i class="fa-solid fa-ship"></i> Request Survey</a></div>
    </div>
    <div class="sl-card">
        <div class="sl-tabs" data-testid="sales-survey-tabs">
            <a href="<?= sanitize($url(['status' => '', 'page' => ''])) ?>" class="<?= $status === '' ? 'on' : '' ?>">All<span><?= array_sum($counts) ?></span></a>
            <?php foreach ($STATUSES as $s): ?><a href="<?= sanitize($url(['status' => $s, 'page' => ''])) ?>" class="<?= $status === $s ? 'on' : '' ?>" data-testid="sales-tab-<?= $pill[$s] ?>"><?= $s ?><span><?= $counts[$s] ?></span></a><?php endforeach; ?>
        </div>
        <form class="sl-filters" method="get" data-testid="sales-survey-filters">
            <?php if ($status): ?><input type="hidden" name="status" value="<?= sanitize($status) ?>"><?php endif; ?>
            <input type="search" name="q" value="<?= sanitize($qs) ?>" placeholder="Search vessel, report no, client, port…" aria-label="Search" data-testid="sales-survey-search">
            <select name="client" onchange="this.form.submit()" aria-label="Client" data-testid="sales-survey-client"><option value="">All clients</option><?php foreach ($clients as $c): ?><option value="<?= (int)$c['id'] ?>"<?= $client === (int)$c['id'] ? ' selected' : '' ?>><?= sanitize($c['company_name']) ?></option><?php endforeach; ?></select>
            <select name="month" onchange="this.form.submit()" aria-label="Assigned in month" data-testid="sales-survey-month"><option value="">Any month</option><?php foreach ($months as $mo): ?><option value="<?= $mo ?>"<?= $month === $mo ? ' selected' : '' ?>>Assigned <?= date('M Y', strtotime($mo . '-01')) ?></option><?php endforeach; ?></select>
            <button class="sl-btn" type="submit"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
            <?php if ($client || $month || $qs !== ''): ?><a class="sl-btn" href="<?= sanitize($url(['client' => '', 'month' => '', 'q' => '', 'page' => ''])) ?>">Clear</a><?php endif; ?>
        </form>
        <?php if (!$rows): ?><div class="sl-empty">No surveys match.</div><?php else: ?>
        <div class="sl-t-wrap"><table class="sl-t" data-testid="sales-survey-table"><thead><tr><th>Vessel</th><th>Client</th><th>Port</th><th>Survey type</th><th>Status</th><th>Assigned</th><th>Appointment</th><th>Survey completed</th></tr></thead><tbody>
        <?php foreach ($rows as $r): ?><tr>
            <td><b><?= sanitize($r['vessel_name']) ?></b><br><small class="sl-muted"><?= sanitize((string)$r['report_number']) ?></small></td>
            <td><?= sanitize((string)$r['company_name']) ?></td><td><?= sanitize((string)$r['port_name']) ?></td>
            <td><?= sanitize((string)getCombinedSurveyTypeNames($db, $r['survey_type_ids'] ?? '', $r['type_name'] ?? '')) ?></td>
            <td><span class="sl-pill <?= $pill[$r['status']] ?? 'pv' ?>"><?= sanitize($r['status']) ?></span></td>
            <td><?= $d($r['assign_date']) ?></td><td><?= $d($r['appointment_date']) ?></td><td><?= $d($r['survey_completed_date']) ?></td>
        </tr><?php endforeach; ?>
        </tbody></table></div>
        <div class="sl-pager"><span><?= number_format($total) ?> survey<?= $total === 1 ? '' : 's' ?> · page <?= $page ?> / <?= $pages ?></span>
            <span class="sl-actions"><?php if ($page > 1): ?><a class="sl-btn sm" href="<?= sanitize($url(['page' => $page - 1])) ?>">‹ Previous</a><?php endif; ?><?php if ($page < $pages): ?><a class="sl-btn sm" href="<?= sanitize($url(['page' => $page + 1])) ?>">Next ›</a><?php endif; ?></span></div>
        <?php endif; ?>
    </div>
</div>
</div>
<?php include 'includes/nav.php'; include 'includes/footer.php'; ?>
