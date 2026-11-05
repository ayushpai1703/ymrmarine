<?php
/**
 * Sales Team (Admin, includes/sales.php): add sales persons (Sales role), activate / deactivate,
 * reset the password, set monthly targets (surveys, new clients) and see what was achieved.
 * Actions: ajax/sales.php.
 */
require_once 'config/config.php';
require_once 'includes/sales.php';
checkAuth();
if (!salesIsAdmin()) { header('Location: index.php'); exit; }
$db = getDB();
salesEnsure($db);
$users = salesUsers($db);
$sel = (int)($_GET['user'] ?? 0);
$ids = array_map(fn($u) => (int)$u['id'], $users);
if (!in_array($sel, $ids, true)) $sel = $ids[0] ?? 0;
$ym = salesMonth($_GET['m'] ?? null);
$hist = [];
if ($sel) for ($i = 5; $i >= 0; $i--) {
    $mm = date('Y-m', strtotime(date('Y-m-01') . ' -' . $i . ' month'));
    $hist[] = ['ym' => $mm, 'm' => salesMetrics($db, $mm, $sel), 't' => salesTarget($db, $sel, $mm)];
}
$cur = $sel ? salesTarget($db, $sel, $ym) : ['surveys' => null, 'new_clients' => null];
$months = [];
for ($i = -3; $i < 12; $i++) $months[] = date('Y-m', strtotime(date('Y-m-01') . ' ' . ($i <= 0 ? '+' . (-$i) : '-' . $i) . ' month'));
$months = array_values(array_unique($months)); rsort($months);
$cell = function (int $got, ?int $goal) {
    if ($goal === null) return $got . ' <span class="sl-muted">/ —</span>';
    return '<b style="color:' . ($got >= $goal ? '#15803d' : '#b45309') . '">' . $got . '</b> <span class="sl-muted">/ ' . $goal . '</span>';
};
$page_title = 'Sales Team';
include 'includes/header.php';
?>
<link rel="stylesheet" href="assets/css/sales.css?v=<?= @filemtime(__DIR__ . '/assets/css/sales.css') ?>">
<div class="scroll-content" data-testid="sales-admin-page">
<?php $back_url = 'index.php'; $page_testid = 'sales-admin'; include 'includes/top_app_bar.php'; ?>
<div class="sl-wrap">
    <div class="sl-head">
        <div><h1><i class="fa-solid fa-user-tie"></i> Sales Team</h1><div class="sl-sub">Sales persons log in with the Sales role: dashboard with targets, clients (add / edit), surveys (view only) and Request Survey. No invoice data.</div></div>
        <div class="sl-actions"><button type="button" class="sl-btn pri" data-add-user data-testid="sales-user-add"><i class="fa-solid fa-user-plus"></i> Add sales person</button></div>
    </div>

    <div class="sl-card"><h2><i class="fa-solid fa-users"></i> Sales persons</h2>
        <?php if (!$users): ?><div class="sl-empty" data-testid="sales-no-users">No sales person yet — add one.</div><?php else: ?>
        <div class="sl-t-wrap"><table class="sl-t" data-testid="sales-users"><thead><tr><th>Name</th><th>Username</th><th>Email</th><th>Phone</th><th>Status</th><th></th></tr></thead><tbody>
        <?php foreach ($users as $u): ?><tr data-testid="sales-user-<?= (int)$u['id'] ?>"><td><b><?= sanitize($u['full_name']) ?></b></td><td><?= sanitize($u['username']) ?></td><td><?= sanitize((string)$u['email']) ?></td><td><?= sanitize((string)$u['phone']) ?></td>
            <td><span class="sl-pill <?= $u['status'] === 'Active' ? 'on' : 'off' ?>"><?= sanitize($u['status']) ?></span></td>
            <td style="white-space:nowrap"><a class="sl-btn sm" href="admin_sales.php?user=<?= (int)$u['id'] ?>&m=<?= $ym ?>"><i class="fa-solid fa-bullseye"></i> Targets</a>
                <button type="button" class="sl-btn sm" data-pw="<?= (int)$u['id'] ?>" data-name="<?= sanitize($u['full_name']) ?>"><i class="fa-solid fa-key"></i> Password</button>
                <button type="button" class="sl-btn sm <?= $u['status'] === 'Active' ? 'danger' : '' ?>" data-status="<?= (int)$u['id'] ?>" data-to="<?= $u['status'] === 'Active' ? 'Inactive' : 'Active' ?>" data-testid="sales-user-status-<?= (int)$u['id'] ?>"><?= $u['status'] === 'Active' ? 'Deactivate' : 'Activate' ?></button></td></tr>
        <?php endforeach; ?></tbody></table></div><?php endif; ?>
    </div>

    <?php if ($sel): $su = array_values(array_filter($users, fn($u) => (int)$u['id'] === $sel))[0]; ?>
    <div class="sl-card" data-testid="sales-targets-card"><h2><i class="fa-solid fa-bullseye"></i> Monthly targets <small><?= sanitize($su['full_name']) ?></small></h2>
        <form class="sl-inline" data-target-form>
            <input type="hidden" name="user_id" value="<?= $sel ?>">
            <label>Month<select name="ym" onchange="location.href='admin_sales.php?user=<?= $sel ?>&m='+this.value" data-testid="sales-target-month"><?php foreach ($months as $mo): ?><option value="<?= $mo ?>"<?= $mo === $ym ? ' selected' : '' ?>><?= date('F Y', strtotime($mo . '-01')) ?></option><?php endforeach; ?></select></label>
            <label>Surveys target<input name="surveys" inputmode="numeric" value="<?= $cur['surveys'] ?? '' ?>" placeholder="e.g. 40" data-testid="sales-target-surveys"></label>
            <label>New clients target<input name="new_clients" inputmode="numeric" value="<?= $cur['new_clients'] ?? '' ?>" placeholder="e.g. 3" data-testid="sales-target-clients"></label>
            <button type="submit" class="sl-btn pri" data-testid="sales-target-save"><i class="fa-solid fa-floppy-disk"></i> Save target</button>
        </form>
        <p class="sl-sub" style="margin-top:10px">Surveys = surveys assigned in the month (all clients, not cancelled). New clients = clients added in the month. Leave a box empty for no target.</p>
        <div class="sl-t-wrap"><table class="sl-t" data-testid="sales-history"><thead><tr><th>Month</th><th class="num">Surveys (achieved / target)</th><th class="num">New clients (achieved / target)</th><th class="num">Completed</th><th class="num">Requests made</th></tr></thead><tbody>
        <?php foreach (array_reverse($hist) as $h): ?><tr><td><?= date('F Y', strtotime($h['ym'] . '-01')) ?></td><td class="num"><?= $cell($h['m']['surveys'], $h['t']['surveys']) ?></td><td class="num"><?= $cell($h['m']['new_clients'], $h['t']['new_clients']) ?></td><td class="num"><?= $h['m']['completed'] ?></td><td class="num"><?= $h['m']['my_requests'] ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </div>
    <?php endif; ?>
</div>
</div>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.14.5/dist/sweetalert2.all.min.js"></script>
<script>
(function () {
  var CSRF = <?= json_encode(csrf_token()) ?>;
  function post(d) {
    var f = new FormData(); Object.keys(d).forEach(function (k) { f.append(k, d[k]); }); f.append('csrf_token', CSRF);
    return fetch('ajax/sales.php', { method: 'POST', body: f, credentials: 'same-origin', headers: { 'X-CSRF-Token': CSRF } }).then(function (r) { return r.json(); });
  }
  function ask(title, html, collect) {
    return Swal.fire({ title: title, html: html, width: 520, showCancelButton: true, confirmButtonText: 'Save', confirmButtonColor: '#00A3DF', showLoaderOnConfirm: true,
      preConfirm: function () { return post(collect()).then(function (res) { if (!res.ok) { Swal.showValidationMessage(res.error || 'Not saved.'); return false; } return res; }); },
      allowOutsideClick: function () { return !Swal.isLoading(); } });
  }
  var v = function (id) { return document.getElementById(id).value; };
  var addBtn = document.querySelector('[data-add-user]');
  addBtn.addEventListener('click', function () {
    ask('Add sales person', '<form class="sl-form" onsubmit="return false"><label>Full name *<input id="su_n" data-testid="sales-u-name"></label><label>Username *<input id="su_u" autocomplete="off" data-testid="sales-u-username"></label>' +
      '<div class="two"><label>Email<input id="su_e" type="email" data-testid="sales-u-email"></label><label>Phone<input id="su_p"></label></div><label>Password * (8+ characters)<input id="su_w" type="password" autocomplete="new-password" data-testid="sales-u-password"></label></form>',
      function () { return { a: 'user_add', full_name: v('su_n'), username: v('su_u'), email: v('su_e'), phone: v('su_p'), password: v('su_w') }; })
      .then(function (r) { if (r.isConfirmed) location.href = 'admin_sales.php?user=' + r.value.id; });
  });
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-status]');
    if (b) post({ a: 'user_status', id: b.getAttribute('data-status'), status: b.getAttribute('data-to') }).then(function (res) { if (res.ok) location.reload(); else Swal.fire({ icon: 'error', text: res.error }); });
    var p = ev.target.closest('[data-pw]');
    if (p) ask('New password — ' + p.getAttribute('data-name'), '<form class="sl-form" onsubmit="return false"><label>New password (8+ characters)<input id="su_np" type="password" autocomplete="new-password"></label></form>',
      function () { return { a: 'user_password', id: p.getAttribute('data-pw'), password: v('su_np') }; }).then(function (r) { if (r.isConfirmed) Swal.fire({ icon: 'success', title: 'Password changed', timer: 1300, showConfirmButton: false }); });
  });
  var tf = document.querySelector('[data-target-form]');
  if (tf) tf.addEventListener('submit', function (ev) {
    ev.preventDefault();
    var d = { a: 'target_save' }; new FormData(tf).forEach(function (val, k) { d[k] = val; });
    post(d).then(function (res) { if (!res.ok) { Swal.fire({ icon: 'error', text: res.error }); return; } Swal.fire({ icon: 'success', title: 'Target saved', timer: 1100, showConfirmButton: false }).then(function () { location.reload(); }); });
  });
})();
</script>
<?php include 'includes/nav.php'; include 'includes/footer.php'; ?>
