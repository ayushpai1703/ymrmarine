<?php
require_once 'config/config.php';
require_once 'includes/bulk_assign_helpers.php';
checkAuth();
if (($_SESSION['role'] ?? '') !== 'Admin') {
    header('Location: index.php');
    exit;
}
$db = getDB();
ensureBulkAssignmentTables($db);
$batches = bulkAssignListBatches($db, null, 100);

include 'includes/header.php';
?>
<style>
    .ba-hero {
        position: relative; overflow: hidden; border-radius: 22px;
        background: linear-gradient(120deg, #0f172a 0%, #1E1B6B 48%, #4B2FD6 100%);
        padding: 22px 24px; margin: 12px 12px 16px;
        display: flex; align-items: center; gap: 16px;
        box-shadow: 0 14px 34px rgba(30,27,107,.22);
    }
    .ba-hero-icon-bg { position: absolute; right: -22px; top: -34px; font-size: 150px; color: rgba(255,255,255,.07); pointer-events: none; line-height: 1; }
    .ba-hero-ico { width: 50px; height: 50px; border-radius: 15px; flex: none; position: relative; background: rgba(255,255,255,.12); border: 1px solid rgba(255,255,255,.18); display: flex; align-items: center; justify-content: center; color: #fff; font-size: 21px; }
    .ba-hero-title { font-size: 20px; font-weight: 800; color: #fff; letter-spacing: -.01em; position: relative; }
    .ba-hero-sub { font-size: 12px; color: rgba(255,255,255,.66); margin-top: 3px; font-weight: 500; position: relative; }

    .ba-toolbar { display: flex; flex-wrap: wrap; gap: 8px; margin: 0 12px 16px; }
    .ba-pill { display: inline-flex; align-items: center; gap: 7px; padding: 9px 16px; border-radius: 999px; font-size: 12.5px; font-weight: 700; text-decoration: none; border: 1.5px solid #e2e8f0; color: #334155; background: #fff; transition: all .15s ease; }
    .ba-pill:hover { border-color: #A78BFA; color: #4B2FD6; }
    .ba-pill.active { background: linear-gradient(120deg, #1E1B6B, #4B2FD6); color: #fff; border-color: transparent; box-shadow: 0 6px 16px rgba(75,47,214,.28); }

    .ba-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 18px; padding: 0; margin: 0 12px 16px; box-shadow: 0 4px 20px rgba(15,23,42,.05); overflow: hidden; }
    .ba-batch-row { padding: 16px 20px; border-bottom: 1px solid #f1f5f9; cursor: pointer; }
    .ba-batch-row:last-child { border-bottom: none; }
    .ba-batch-row:hover { background: #F8FAFC; }
    .ba-batch-top { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
    .ba-batch-file { font-size: 13.5px; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 8px; }
    .ba-batch-file i { color: #4B2FD6; }
    .ba-batch-meta { font-size: 11.5px; color: #94a3b8; margin-top: 3px; }
    .ba-batch-stats { display: flex; gap: 8px; flex-wrap: wrap; }
    .ba-mini-stat { font-size: 11px; font-weight: 700; padding: 4px 10px; border-radius: 999px; }
    .ba-mini-stat.ok { background: #F0FDF4; color: #166534; }
    .ba-mini-stat.warn { background: #FFFBEB; color: #B45309; }
    .ba-mini-stat.err { background: #FEF2F2; color: #B91C1C; }
    .ba-batch-detail { display: none; margin-top: 12px; }
    .ba-batch-detail .ba-table-wrap { overflow-x: auto; border: 1px solid #e2e8f0; border-radius: 12px; max-height: 360px; overflow-y: auto; }
    table.ba-table { width: 100%; border-collapse: collapse; font-size: 12px; }
    table.ba-table thead th { position: sticky; top: 0; background: #F8FAFC; text-align: left; padding: 8px 10px; font-weight: 700; color: #64748b; font-size: 10.5px; text-transform: uppercase; border-bottom: 1px solid #e2e8f0; white-space: nowrap; }
    table.ba-table tbody td { padding: 8px 10px; border-bottom: 1px solid #f1f5f9; color: #334155; white-space: nowrap; }
    .ba-status-badge { display: inline-flex; align-items: center; gap: 5px; padding: 3px 9px; border-radius: 999px; font-size: 10px; font-weight: 700; white-space: nowrap; }
    .ba-status-badge.s-valid, .ba-status-badge.s-assigned { background: #F0FDF4; color: #166534; }
    .ba-status-badge.s-dup, .ba-status-badge.s-already { background: #FFFBEB; color: #B45309; }
    .ba-status-badge.s-err { background: #FEF2F2; color: #B91C1C; }
    .ba-empty { text-align: center; padding: 48px 20px; color: #94a3b8; }
    .ba-empty i { font-size: 32px; margin-bottom: 10px; display: block; opacity: .5; }
    .ba-dl-link { font-size: 11.5px; font-weight: 700; color: #4B2FD6; text-decoration: none; }
    .ba-dl-link:hover { text-decoration: underline; }

    @media (min-width: 992px) {
        .top-app-bar { display: none !important; }
        .scroll-content { max-width: 1200px; margin: 0 auto; padding: 8px 20px 40px; }
        .ba-hero, .ba-toolbar, .ba-card { margin-left: 0; margin-right: 0; }
    }
</style>

<?php
$page_title = 'Assignment History';
$back_url = 'assign_vessel.php';
$page_testid = 'bulk-history';
include 'includes/top_app_bar.php';
?>

<div class="scroll-content">
    <div class="ba-hero">
        <i class="fa-solid fa-clock-rotate-left ba-hero-icon-bg" aria-hidden="true"></i>
        <div class="ba-hero-ico"><i class="fa-solid fa-clock-rotate-left"></i></div>
        <div>
            <div class="ba-hero-title">Assignment History</div>
            <div class="ba-hero-sub">Every bulk Excel upload, with its results and any error report</div>
        </div>
    </div>

    <div class="ba-toolbar">
        <a href="assign_vessel.php" class="ba-pill" data-testid="ba-nav-assign"><i class="fa-solid fa-plus"></i> Assign Vessel</a>
        <a href="bulk_assign_vessels.php" class="ba-pill" data-testid="ba-nav-bulk"><i class="fa-solid fa-file-excel"></i> Bulk Upload Excel</a>
        <a href="bulk_assignment_history.php" class="ba-pill active" data-testid="ba-nav-history"><i class="fa-solid fa-clock-rotate-left"></i> Assignment History</a>
    </div>

    <div class="ba-card">
        <?php if (empty($batches)): ?>
            <div class="ba-empty">
                <i class="fa-solid fa-file-excel"></i>
                No bulk uploads yet. <a href="bulk_assign_vessels.php">Upload an Excel file</a> to get started.
            </div>
        <?php else: foreach ($batches as $b):
            $alreadyTotal = (int)$b['duplicate_rows'] + (int)$b['already_assigned_rows'];
            $when = !empty($b['created_at']) ? date('d M Y, h:i A', strtotime($b['created_at'])) : '';
        ?>
            <div class="ba-batch-row" data-batch-id="<?= (int)$b['id'] ?>" data-testid="ba-batch-row-<?= (int)$b['id'] ?>">
                <div class="ba-batch-top">
                    <div>
                        <div class="ba-batch-file"><i class="fa-solid fa-file-excel"></i> <?= sanitize($b['original_filename']) ?></div>
                        <div class="ba-batch-meta"><?= sanitize($when) ?> · by <?= sanitize($b['uploaded_by_name'] ?: 'Admin') ?> · <?= (int)$b['total_rows'] ?> rows · Status: <?= sanitize(ucfirst($b['status'])) ?></div>
                    </div>
                    <div class="ba-batch-stats">
                        <span class="ba-mini-stat ok"><?= (int)$b['inserted_rows'] ?> assigned</span>
                        <?php if ($alreadyTotal > 0): ?><span class="ba-mini-stat warn"><?= $alreadyTotal ?> already assigned</span><?php endif; ?>
                        <?php if ((int)$b['error_rows'] > 0): ?><span class="ba-mini-stat err"><?= (int)$b['error_rows'] ?> errors</span><?php endif; ?>
                    </div>
                </div>
                <div class="ba-batch-detail" id="baDetail<?= (int)$b['id'] ?>">
                    <div class="ba-table-wrap"><table class="ba-table"><thead><tr>
                        <th>Row</th><th>Vessel</th><th>IMO</th><th>Surveyor</th><th>Client</th><th>Status</th><th>Message</th>
                    </tr></thead><tbody></tbody></table></div>
                    <div class="mt-2"><a href="#" class="ba-dl-link" data-batch-id="<?= (int)$b['id'] ?>"><i class="fa-solid fa-file-arrow-down me-1"></i> Download Error Excel</a></div>
                </div>
            </div>
        <?php endforeach; endif; ?>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>
$(document).on('click', '.ba-batch-row', function (e) {
    if ($(e.target).closest('.ba-dl-link').length) return;
    const id = $(this).data('batch-id');
    const $detail = $('#baDetail' + id);
    if ($detail.is(':visible')) { $detail.slideUp(150); return; }
    if ($detail.data('loaded')) { $detail.slideDown(150); return; }
    $.get('ajax/bulk_assign_batch_summary.php', { batch_id: id, with_rows: '1' }, function (res) {
        if (!res || !res.success) return;
        const rowsHtml = (res.rows || []).map(function (r) {
            let cls = 's-err';
            if (r.status === 'VALID' || r.status === 'ASSIGNED') cls = 's-valid';
            else if (r.status === 'DUPLICATE') cls = 's-dup';
            else if (r.status === 'ALREADY ASSIGNED' || r.status === 'ALREADY ASSIGNED TO OTHER SURVEYOR') cls = 's-already';
            const esc = function (s) { return $('<div>').text(s === null || s === undefined ? '' : String(s)).html(); };
            return '<tr><td>' + r.excel_row_number + '</td><td>' + esc(r.vessel_name) + '</td><td>' + esc(r.imo_number) + '</td><td>' + esc(r.surveyor_label) + '</td><td>' + esc(r.client_label) + '</td>' +
                '<td><span class="ba-status-badge ' + cls + '">' + esc(r.status) + '</span></td><td>' + esc(r.message) + '</td></tr>';
        }).join('') || '<tr><td colspan="7" style="text-align:center;color:#94a3b8;">No row details recorded.</td></tr>';
        $detail.find('tbody').html(rowsHtml);
        $detail.data('loaded', true);
        $detail.slideDown(150);
    }, 'json');
}).on('click', '.ba-dl-link', function (e) {
    e.preventDefault(); e.stopPropagation();
    window.location = 'ajax/bulk_assign_error_excel.php?batch_id=' + $(this).data('batch-id');
});
</script>

<?php include 'includes/nav.php'; include 'includes/footer.php'; ?>
