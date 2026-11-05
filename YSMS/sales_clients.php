<?php
/**
 * Clients for the Sales role (includes/sales.php): every client with its contact details and
 * survey counts; add and edit (ajax/sales.php a=client_save). No delete, no client logins.
 */
require_once 'config/config.php';
require_once 'includes/sales.php';
checkAuth();
if (!salesIsSales()) { header('Location: index.php'); exit; }
$db = getDB();
salesEnsure($db);
[$from, $to] = salesMonthRange(date('Y-m'));
$q = $db->prepare("SELECT c.id, c.company_name, c.short_code, c.contact_person, c.mobile, c.email, c.address_line1, c.address_line2, c.created_at,
        COUNT(s.id) AS total, SUM(s.assign_date >= ? AND s.assign_date < ?) AS this_month, MAX(s.assign_date) AS last_survey
    FROM clients c LEFT JOIN surveys s ON s.client_id = c.id AND s.status <> 'Cancelled'
    GROUP BY c.id ORDER BY c.company_name");
$q->execute([$from, $to]);
$clients = $q->fetchAll(PDO::FETCH_ASSOC);
$page_title = 'Clients';
include 'includes/header.php';
?>
<link rel="stylesheet" href="assets/css/sales.css?v=<?= @filemtime(__DIR__ . '/assets/css/sales.css') ?>">
<div class="scroll-content" data-testid="sales-clients-page">
<?php $back_url = 'sales_dashboard.php'; $page_testid = 'sales-clients'; include 'includes/top_app_bar.php'; ?>
<div class="sl-wrap">
    <div class="sl-head">
        <div><h1><i class="fa-solid fa-building"></i> Clients</h1><div class="sl-sub"><?= count($clients) ?> clients · surveys counted without cancelled ones</div></div>
        <div class="sl-actions"><button type="button" class="sl-btn pri" data-add data-testid="sales-client-add"><i class="fa-solid fa-plus"></i> Add Client</button></div>
    </div>
    <div class="sl-card">
        <div class="sl-filters"><input type="search" placeholder="Search company, contact, phone, email…" data-q data-testid="sales-client-search" aria-label="Search clients"></div>
        <div class="sl-t-wrap"><table class="sl-t" data-testid="sales-client-table"><thead><tr><th>Company</th><th>Code</th><th>Contact person</th><th>Phone</th><th>Email</th><th class="num">Surveys</th><th class="num">This month</th><th>Last survey</th><th></th></tr></thead><tbody>
        <?php foreach ($clients as $c): $j = ['id' => (int)$c['id']] + array_map(fn($v) => (string)$v, array_intersect_key($c, array_flip(['company_name', 'short_code', 'contact_person', 'mobile', 'email', 'address_line1', 'address_line2']))); ?>
            <tr data-row data-search="<?= sanitize(strtolower($c['company_name'] . ' ' . $c['contact_person'] . ' ' . $c['mobile'] . ' ' . $c['email'] . ' ' . $c['short_code'])) ?>" data-testid="sales-client-<?= (int)$c['id'] ?>">
                <td><b><?= sanitize($c['company_name']) ?></b><?= $c['created_at'] && strtotime($c['created_at']) >= strtotime($from) ? ' <span class="sl-pill on">New</span>' : '' ?></td>
                <td><?= sanitize((string)$c['short_code']) ?: '<span class="sl-muted">—</span>' ?></td>
                <td><?= sanitize((string)$c['contact_person']) ?: '<span class="sl-muted">—</span>' ?></td>
                <td><?= $c['mobile'] ? '<a href="tel:' . sanitize(preg_replace('/[^0-9+]/', '', $c['mobile'])) . '">' . sanitize($c['mobile']) . '</a>' : '<span class="sl-muted">—</span>' ?></td>
                <td><?= $c['email'] ? '<a href="mailto:' . sanitize($c['email']) . '">' . sanitize($c['email']) . '</a>' : '<span class="sl-muted">—</span>' ?></td>
                <td class="num"><?= (int)$c['total'] ?></td><td class="num"><?= (int)$c['this_month'] ?></td>
                <td><?= $c['last_survey'] ? date('d M Y', strtotime($c['last_survey'])) : '<span class="sl-muted">—</span>' ?></td>
                <td><button type="button" class="sl-btn sm" data-edit='<?= htmlspecialchars(json_encode($j), ENT_QUOTES, 'UTF-8') ?>' data-testid="sales-client-edit-<?= (int)$c['id'] ?>"><i class="fa-solid fa-pen"></i> Edit</button></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>
        <div class="sl-empty" data-none hidden>No client matches.</div>
    </div>
</div>
</div>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.14.5/dist/sweetalert2.all.min.js"></script>
<script>
(function () {
  var CSRF = <?= json_encode(csrf_token()) ?>;
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  var q = document.querySelector('[data-q]'), rows = Array.prototype.slice.call(document.querySelectorAll('[data-row]'));
  q.addEventListener('input', function () {
    var t = q.value.trim().toLowerCase(), n = 0;
    rows.forEach(function (r) { var on = !t || r.getAttribute('data-search').indexOf(t) >= 0; r.hidden = !on; if (on) n++; });
    document.querySelector('[data-none]').hidden = n > 0;
  });
  var F = [['company_name', 'Company name *'], ['short_code', 'Short code (for report / invoice numbers, e.g. BB)'], ['contact_person', 'Contact person'], ['mobile', 'Phone'], ['email', 'Email'], ['address_line1', 'Address line 1'], ['address_line2', 'Address line 2']];
  function form(c) {
    c = c || {};
    Swal.fire({ title: c.id ? 'Edit client' : 'Add client', width: 560, showCancelButton: true, confirmButtonText: 'Save', confirmButtonColor: '#00A3DF', showLoaderOnConfirm: true,
      html: '<form class="sl-form" onsubmit="return false">' + F.map(function (f) { return '<label>' + f[1] + '<input id="slf_' + f[0] + '" value="' + esc(c[f[0]]) + '" data-testid="sales-cf-' + f[0] + '"' + (f[0] === 'short_code' ? ' maxlength="10"' : '') + '></label>'; }).join('') + '</form>',
      preConfirm: function () {
        var d = new FormData(); d.append('a', 'client_save'); d.append('id', c.id || ''); d.append('csrf_token', CSRF);
        F.forEach(function (f) { d.append(f[0], document.getElementById('slf_' + f[0]).value); });
        if (!document.getElementById('slf_company_name').value.trim()) { Swal.showValidationMessage('Company name is required.'); return false; }
        return fetch('ajax/sales.php', { method: 'POST', body: d, credentials: 'same-origin', headers: { 'X-CSRF-Token': CSRF } }).then(function (r) { return r.json(); })
          .then(function (res) { if (!res.ok) { Swal.showValidationMessage(res.error || 'Not saved.'); return false; } return res; })
          .catch(function () { Swal.showValidationMessage('Network error — please try again.'); return false; });
      }, allowOutsideClick: function () { return !Swal.isLoading(); }
    }).then(function (r) { if (r.isConfirmed && r.value) Swal.fire({ icon: 'success', title: r.value.created ? 'Client added' : 'Client saved', timer: 1300, showConfirmButton: false }).then(function () { location.href = 'sales_clients.php'; }); });
  }
  document.querySelector('[data-add]').addEventListener('click', function () { form(); });
  document.addEventListener('click', function (ev) { var b = ev.target.closest('[data-edit]'); if (b) form(JSON.parse(b.getAttribute('data-edit'))); });
  if (/[?&]add=1/.test(location.search)) form();
})();
</script>
<?php include 'includes/nav.php'; include 'includes/footer.php'; ?>
