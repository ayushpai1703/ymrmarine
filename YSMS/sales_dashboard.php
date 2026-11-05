<?php
/**
 * Sales dashboard (Sales role, includes/sales.php): this month's surveys and new clients against
 * the targets the Admin set (admin_sales.php), completed / open surveys, the last 6 months,
 * top clients of the month and the survey requests this person made. No invoice or payment data.
 */
require_once 'config/config.php';
require_once 'includes/sales.php';
checkAuth();
if (!salesIsSales()) { header('Location: index.php'); exit; }
$db = getDB();
salesEnsure($db);
$uid = (int)$_SESSION['user_id'];
$ym = salesMonth($_GET['m'] ?? null);
$m = salesMetrics($db, $ym, $uid);
$t = salesTarget($db, $uid, $ym);
[$from, $to] = salesMonthRange($ym);

// last 6 months (ending with the chosen month)
$trend = [];
for ($i = 5; $i >= 0; $i--) {
    $mm = date('Y-m', strtotime($ym . '-01 -' . $i . ' month'));
    [$f2, $t2] = salesMonthRange($mm);
    $q = $db->prepare("SELECT COUNT(*) FROM surveys WHERE assign_date >= ? AND assign_date < ? AND status <> 'Cancelled'");
    $q->execute([$f2, $t2]);
    $trend[] = ['ym' => $mm, 'n' => (int)$q->fetchColumn(), 'target' => salesTarget($db, $uid, $mm)['surveys']];
}
$trendMax = max(1, max(array_map(fn($r) => max($r['n'], (int)$r['target']), $trend)));

$top = $db->prepare("SELECT c.company_name, COUNT(*) n FROM surveys s JOIN clients c ON c.id = s.client_id
    WHERE s.assign_date >= ? AND s.assign_date < ? AND s.status <> 'Cancelled' GROUP BY c.id, c.company_name ORDER BY n DESC, c.company_name LIMIT 6");
$top->execute([$from, $to]);
$top = $top->fetchAll(PDO::FETCH_ASSOC);

$req = $db->prepare("SELECT s.vessel_name, s.report_number, s.status, s.appointment_date, c.company_name, p.port_name, r.created_at
    FROM sales_requests r JOIN surveys s ON s.id = r.survey_id LEFT JOIN clients c ON c.id = s.client_id LEFT JOIN ports p ON p.id = s.port_id
    WHERE r.user_id = ? ORDER BY r.created_at DESC LIMIT 8");
$req->execute([$uid]);
$req = $req->fetchAll(PDO::FETCH_ASSOC);

$months = [];
for ($i = 0; $i < 12; $i++) $months[] = date('Y-m', strtotime(date('Y-m-01') . ' -' . $i . ' month'));
$pill = ['Pending Vessel' => 'pv', 'Pending Report' => 'pr', 'Completed' => 'co', 'Cancelled' => 'ca'];
$target = function (string $label, int $got, ?int $goal, string $cls, string $tid) {
    $pct = $goal ? min(100, (int)round($got / max(1, $goal) * 100)) : 0;
    $left = $goal !== null ? max(0, $goal - $got) : null;
    return '<div class="sl-target ' . $cls . '" data-testid="' . $tid . '"><div class="l"><span>' . $label . '</span><span>' . ($goal !== null ? $pct . '%' : '') . '</span></div>' .
        '<div class="v"><span data-testid="' . $tid . '-got">' . $got . '</span>' . ($goal !== null ? ' <small>/ <span data-testid="' . $tid . '-goal">' . $goal . '</span></small>' : '') . '</div>' .
        '<div class="sl-bar"><i class="' . ($goal !== null && $got >= $goal ? 'done' : '') . '" style="width:' . ($goal ? $pct : 0) . '%"></i></div>' .
        '<div class="s">' . ($goal === null ? 'No target set for this month yet (the Admin sets it).' : ($left === 0 ? '<b class="ok"><i class="fa-solid fa-circle-check"></i> Target reached</b>' : '<b>' . $left . '</b> more to reach the target')) . '</div></div>';
};
$page_title = 'Sales Dashboard';
include 'includes/header.php';
?>
<link rel="stylesheet" href="assets/css/sales.css?v=<?= @filemtime(__DIR__ . '/assets/css/sales.css') ?>">
<div class="scroll-content" data-testid="sales-dashboard-page">
<?php $back_url = ''; $page_testid = 'sales-dashboard'; include 'includes/top_app_bar.php'; ?>
<div class="sl-wrap">
    <div class="sl-head">
        <div><h1><i class="fa-solid fa-chart-line"></i> <?= sanitize(getGreeting()) ?>, <?= sanitize(explode(' ', (string)($_SESSION['full_name'] ?? ''))[0] ?: 'there') ?></h1>
            <div class="sl-sub">Sales dashboard · <?= date('F Y', strtotime($ym . '-01')) ?></div></div>
        <div class="sl-actions">
            <select class="sl-month" onchange="location.href='sales_dashboard.php?m='+this.value" aria-label="Month" data-testid="sales-month">
                <?php foreach ($months as $mo): ?><option value="<?= $mo ?>"<?= $mo === $ym ? ' selected' : '' ?>><?= date('F Y', strtotime($mo . '-01')) ?></option><?php endforeach; ?>
            </select>
            <a class="sl-btn pri" href="assign_vessel.php" data-testid="sales-request"><i class="fa-solid fa-ship"></i> Request Survey</a>
            <a class="sl-btn alt" href="sales_clients.php?add=1" data-testid="sales-add-client"><i class="fa-solid fa-user-plus"></i> Add Client</a>
        </div>
    </div>

    <div class="sl-targets">
        <?= $target('Surveys this month', $m['surveys'], $t['surveys'], '', 'sales-t-surveys') ?>
        <?= $target('New clients this month', $m['new_clients'], $t['new_clients'], 'alt', 'sales-t-clients') ?>
    </div>

    <div class="sl-stats">
        <div class="sl-stat"><div class="l">Completed</div><div class="v" data-testid="sales-completed"><?= $m['completed'] ?></div><div class="s">survey completed in the month</div></div>
        <div class="sl-stat"><div class="l">My requests</div><div class="v" data-testid="sales-my-requests"><?= $m['my_requests'] ?></div><div class="s">surveys you requested in the month</div></div>
        <div class="sl-stat"><div class="l">Pending Vessels</div><div class="v"><?= $m['pending_vessel'] ?></div><div class="s">open now</div></div>
        <div class="sl-stat"><div class="l">Pending Reports</div><div class="v"><?= $m['pending_report'] ?></div><div class="s">open now</div></div>
    </div>

    <div class="sl-grid2">
        <div class="sl-card"><h2><i class="fa-solid fa-chart-column"></i> Surveys — last 6 months <small>bar = surveys, line = target</small></h2>
            <div class="sl-trend" data-testid="sales-trend">
            <?php foreach ($trend as $r): ?>
                <div class="sl-trend-row"><span class="m"><?= date('M Y', strtotime($r['ym'] . '-01')) ?></span>
                    <div class="sl-bar"><i class="<?= $r['target'] !== null && $r['n'] >= $r['target'] ? 'done' : '' ?>" style="width:<?= round($r['n'] / $trendMax * 100) ?>%"></i><?php if ($r['target'] !== null): ?><em style="left:<?= round($r['target'] / $trendMax * 100) ?>%" title="Target <?= (int)$r['target'] ?>"></em><?php endif; ?></div>
                    <span class="t"><?= $r['n'] ?><?= $r['target'] !== null ? ' <span class="sl-muted">/ ' . (int)$r['target'] . '</span>' : '' ?></span></div>
            <?php endforeach; ?>
            </div>
        </div>
        <div class="sl-card"><h2><i class="fa-solid fa-trophy"></i> Top clients <small><?= date('F Y', strtotime($ym . '-01')) ?></small></h2>
            <?php if (!$top): ?><div class="sl-empty">No surveys this month yet.</div><?php else: ?>
            <table class="sl-t" data-testid="sales-top"><tbody>
            <?php foreach ($top as $i => $c): ?><tr><td><?= $i + 1 ?>. <?= sanitize($c['company_name']) ?></td><td class="num"><b><?= (int)$c['n'] ?></b> survey<?= $c['n'] == 1 ? '' : 's' ?></td></tr><?php endforeach; ?>
            </tbody></table><?php endif; ?>
        </div>
    </div>

    <div class="sl-card"><h2><i class="fa-solid fa-paper-plane"></i> My survey requests <small>latest</small></h2>
        <?php if (!$req): ?><div class="sl-empty">No requests yet. Use <a href="assign_vessel.php">Request Survey</a> to send one to the office.</div><?php else: ?>
        <div class="sl-t-wrap"><table class="sl-t" data-testid="sales-requests"><thead><tr><th>Vessel</th><th>Client</th><th>Port</th><th>Appointment</th><th>Status</th><th>Requested</th></tr></thead><tbody>
        <?php foreach ($req as $r): ?><tr><td><b><?= sanitize($r['vessel_name']) ?></b><br><small class="sl-muted"><?= sanitize($r['report_number']) ?></small></td><td><?= sanitize($r['company_name'] ?? '') ?></td><td><?= sanitize($r['port_name'] ?? '') ?></td>
            <td><?= $r['appointment_date'] ? date('d M Y', strtotime($r['appointment_date'])) : '—' ?></td><td><span class="sl-pill <?= $pill[$r['status']] ?? 'pv' ?>"><?= sanitize($r['status']) ?></span></td><td><?= date('d M Y', strtotime($r['created_at'])) ?></td></tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
    </div>
</div>
</div>
<?php include 'includes/nav.php'; include 'includes/footer.php'; ?>
