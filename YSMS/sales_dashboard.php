<?php
/**
 * Sales dashboard (Sales role, includes/sales.php):
 *  - highlighted: Total Clients, Total Vessels Completed, Total Recovery
 *  - the month's targets (surveys, new clients) set by the Admin (admin_sales.php)
 *  - vessels completed and recovery month-wise (12 months), client-wise, vessels country-wise
 *    — the Admin dashboard's rules (salesCompletionStats)
 *  - the survey requests this person made
 * No invoice or payment data.
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
$st = salesCompletionStats($db, $ym);
$monthName = date('F Y', strtotime($ym . '-01'));

$req = $db->prepare("SELECT s.vessel_name, s.report_number, s.status, s.appointment_date, c.company_name, p.port_name, r.created_at
    FROM sales_requests r JOIN surveys s ON s.id = r.survey_id LEFT JOIN clients c ON c.id = s.client_id LEFT JOIN ports p ON p.id = s.port_id
    WHERE r.user_id = ? ORDER BY r.created_at DESC LIMIT 8");
$req->execute([$uid]);
$req = $req->fetchAll(PDO::FETCH_ASSOC);

$months = [];
for ($i = 0; $i < 12; $i++) $months[] = date('Y-m', strtotime(date('Y-m-01') . ' -' . $i . ' month'));
$pill = ['Pending Vessel' => 'pv', 'Pending Report' => 'pr', 'Completed' => 'co', 'Cancelled' => 'ca'];
$mt = fn($v) => number_format((float)$v, 3);
$maxV = max(1, max(array_column($st['months'], 'vessels')));
$maxR = max(0.001, max(array_column($st['months'], 'recovery')));
$m12v = array_sum(array_column($st['months'], 'vessels'));
$m12r = array_sum(array_column($st['months'], 'recovery'));
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
            <div class="sl-sub">Sales dashboard · <?= $monthName ?></div></div>
        <div class="sl-actions">
            <select class="sl-month" onchange="location.href='sales_dashboard.php?m='+this.value" aria-label="Month" data-testid="sales-month">
                <?php foreach ($months as $mo): ?><option value="<?= $mo ?>"<?= $mo === $ym ? ' selected' : '' ?>><?= date('F Y', strtotime($mo . '-01')) ?></option><?php endforeach; ?>
            </select>
            <a class="sl-btn pri" href="assign_vessel.php" data-testid="sales-request"><i class="fa-solid fa-ship"></i> Request Survey</a>
            <a class="sl-btn alt" href="sales_clients.php?add=1" data-testid="sales-add-client"><i class="fa-solid fa-user-plus"></i> Add Client</a>
        </div>
    </div>

    <div class="sl-hl" data-testid="sales-highlights">
        <a class="sl-hl-i c1" href="sales_clients.php"><i class="fa-solid fa-building"></i><div><div class="l">Total Clients</div><div class="v" data-testid="sales-total-clients"><?= number_format($st['total_clients']) ?></div><div class="s"><?= $m['new_clients'] ?> new in <?= $monthName ?></div></div></a>
        <div class="sl-hl-i c2"><i class="fa-solid fa-ship"></i><div><div class="l">Total Vessels Completed</div><div class="v" data-testid="sales-total-vessels"><?= number_format($st['vessels']) ?></div><div class="s"><?= $st['month_vessels'] ?> in <?= $monthName ?></div></div></div>
        <div class="sl-hl-i c3"><i class="fa-solid fa-gas-pump"></i><div><div class="l">Total Recovery</div><div class="v" data-testid="sales-total-recovery"><?= $mt($st['recovery']) ?> <small>MT</small></div><div class="s"><?= $mt($st['month_recovery']) ?> MT in <?= $monthName ?></div></div></div>
    </div>

    <div class="sl-targets">
        <?= $target('Surveys target — ' . $monthName, $m['surveys'], $t['surveys'], '', 'sales-t-surveys') ?>
        <?= $target('New clients target — ' . $monthName, $m['new_clients'], $t['new_clients'], 'alt', 'sales-t-clients') ?>
    </div>

    <div class="sl-card"><h2><i class="fa-solid fa-calendar-days"></i> Month-wise <small>vessels completed and recovery, by Survey Completed Date</small></h2>
        <div class="sl-t-wrap"><table class="sl-t sl-mw" data-testid="sales-monthwise"><thead><tr><th>Month</th><th>Vessels completed</th><th>Recovery (MT)</th></tr></thead><tbody>
        <?php foreach (array_reverse($st['months']) as $r): ?><tr class="<?= $r['ym'] === $ym ? 'cur' : '' ?>" data-ym="<?= $r['ym'] ?>">
            <td><b><?= date('M Y', strtotime($r['ym'] . '-01')) ?></b></td>
            <td><div class="sl-cellbar"><span class="sl-bar"><i style="width:<?= round($r['vessels'] / $maxV * 100) ?>%"></i></span><b class="num" data-c="v"><?= $r['vessels'] ?></b></div></td>
            <td><div class="sl-cellbar alt"><span class="sl-bar"><i style="width:<?= round($r['recovery'] / $maxR * 100) ?>%"></i></span><b class="num" data-c="r"><?= $mt($r['recovery']) ?></b></div></td>
        </tr><?php endforeach; ?></tbody>
        <tfoot><tr><td>Last 12 months</td><td class="num" data-testid="sales-12m-vessels"><?= $m12v ?></td><td class="num"><?= $mt($m12r) ?></td></tr>
            <tr class="all"><td>Total (all time)</td><td class="num"><?= number_format($st['vessels']) ?></td><td class="num"><?= $mt($st['recovery']) ?></td></tr></tfoot></table></div>
    </div>

    <div class="sl-grid2">
        <div class="sl-card"><h2><i class="fa-solid fa-building"></i> Client-wise <small>vessels completed and recovery</small></h2>
            <?php if (!$st['clients']): ?><div class="sl-empty">No completed vessels yet.</div><?php else: ?>
            <div class="sl-t-wrap sl-scroll"><table class="sl-t" data-testid="sales-clientwise"><thead><tr><th>Client</th><th class="num"><?= date('M Y', strtotime($ym . '-01')) ?><br><small>vessels</small></th><th class="num">Total<br><small>vessels</small></th><th class="num"><?= date('M Y', strtotime($ym . '-01')) ?><br><small>recovery MT</small></th><th class="num">Total<br><small>recovery MT</small></th></tr></thead><tbody>
            <?php foreach ($st['clients'] as $c): ?><tr><td><?= sanitize($c['name']) ?></td><td class="num"><?= $c['mn'] ?: '<span class="sl-muted">0</span>' ?></td><td class="num"><b><?= $c['n'] ?></b></td><td class="num"><?= $c['mr'] ? $mt($c['mr']) : '<span class="sl-muted">0.000</span>' ?></td><td class="num"><b><?= $mt($c['r']) ?></b></td></tr><?php endforeach; ?>
            </tbody><tfoot><tr><td>Total</td><td class="num"><?= $st['month_vessels'] ?></td><td class="num"><?= number_format($st['vessels']) ?></td><td class="num"><?= $mt($st['month_recovery']) ?></td><td class="num"><?= $mt($st['recovery']) ?></td></tr></tfoot></table></div><?php endif; ?>
        </div>
        <div class="sl-card"><h2><i class="fa-solid fa-earth-asia"></i> Country-wise <small>vessels completed</small></h2>
            <?php if (!$st['countries']): ?><div class="sl-empty">No completed vessels yet.</div><?php else: ?>
            <div class="sl-t-wrap sl-scroll"><table class="sl-t" data-testid="sales-countrywise"><thead><tr><th>Country</th><th class="num"><?= date('M Y', strtotime($ym . '-01')) ?></th><th class="num">Total</th></tr></thead><tbody>
            <?php foreach ($st['countries'] as $c): ?><tr><td><?= sanitize($c['name']) ?></td><td class="num"><?= $c['mn'] ?: '<span class="sl-muted">0</span>' ?></td><td class="num"><b><?= $c['n'] ?></b></td></tr><?php endforeach; ?>
            </tbody><tfoot><tr><td>Total</td><td class="num"><?= $st['month_vessels'] ?></td><td class="num"><?= number_format($st['vessels']) ?></td></tr></tfoot></table></div><?php endif; ?>
        </div>
    </div>

    <div class="sl-card"><h2><i class="fa-solid fa-paper-plane"></i> My survey requests <small><span data-testid="sales-my-requests"><?= $m['my_requests'] ?></span> in <?= $monthName ?> · latest below</small></h2>
        <?php if (!$req): ?><div class="sl-empty">No requests yet. Use <a href="assign_vessel.php">Request Survey</a> to send one to the office.</div><?php else: ?>
        <div class="sl-t-wrap"><table class="sl-t" data-testid="sales-requests"><thead><tr><th>Vessel</th><th>Client</th><th>Port</th><th>Appointment</th><th>Status</th><th>Requested</th></tr></thead><tbody>
        <?php foreach ($req as $r): ?><tr><td><b><?= sanitize($r['vessel_name']) ?></b><br><small class="sl-muted"><?= sanitize($r['report_number']) ?></small></td><td><?= sanitize($r['company_name'] ?? '') ?></td><td><?= sanitize($r['port_name'] ?? '') ?></td>
            <td><?= $r['appointment_date'] ? date('d M Y', strtotime($r['appointment_date'])) : '—' ?></td><td><span class="sl-pill <?= $pill[$r['status']] ?? 'pv' ?>"><?= sanitize($r['status']) ?></span></td><td><?= date('d M Y', strtotime($r['created_at'])) ?></td></tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
    </div>
</div>
</div>
<?php include 'includes/nav.php'; include 'includes/footer.php'; ?>
