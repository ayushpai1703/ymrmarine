<?php
/**
 * Login Activity / Access Monitoring — Admin & Super Admin only.
 *
 * Audit-log page: view/search/filter/export only, no edit or delete from
 * this UI (see includes/login_activity_helpers.php for the underlying
 * table and recording logic). Every AJAX endpoint it calls
 * (ajax/login_activity_list.php, _summary.php, _user_history.php,
 * _export.php) independently re-checks the Admin/Super Admin role itself —
 * this page-level redirect is a convenience, not the real gate.
 */
require_once 'config/config.php';
require_once 'includes/login_activity_helpers.php';
checkAuth();

$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['Admin', 'Super Admin'], true)) {
    header('Location: index.php');
    exit;
}

$db = getDB();
ensureLoginActivityTable($db);

// Dynamic country list for the filter dropdown — only countries actually
// seen in recorded logins, so the list never shows options with zero results.
$countryOptions = [];
try {
    $countryOptions = $db->query("SELECT DISTINCT country FROM login_activity
        WHERE country IS NOT NULL AND country != '' ORDER BY country ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    error_log('login_activity.php country list: ' . $e->getMessage());
}

include 'includes/header.php';
?>
<style>
    .la-wrap { padding: 14px 16px 90px; }
    .la-cards { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; margin-bottom: 18px; }
    @media (min-width: 720px) { .la-cards { grid-template-columns: repeat(4, 1fr); } }
    .la-card {
        background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 14px;
        padding: 14px; display: flex; flex-direction: column; gap: 4px;
    }
    .la-card .la-card-label { font-size: 11.5px; color: var(--text-muted); font-weight: 600; }
    .la-card .la-card-val { font-size: 22px; font-weight: 700; color: var(--text-dark); }
    .la-card .la-card-hint { font-size: 10.5px; color: var(--text-muted); }

    .la-section-title { font-size: 14px; font-weight: 700; color: var(--text-dark); margin: 18px 0 10px; }

    .la-latest-scroll { overflow-x: auto; margin-bottom: 20px; }
    .la-table { width: 100%; border-collapse: collapse; font-size: 12.5px; min-width: 640px; }
    .la-table th, .la-table td { padding: 9px 10px; border-bottom: 1px solid var(--border-color); text-align: left; white-space: nowrap; }
    .la-table th { color: var(--text-muted); font-weight: 650; font-size: 11.5px; background: #f8fafc; }
    .la-table tbody tr:hover { background: #f8fafc; cursor: pointer; }
    .la-badge { display: inline-block; padding: 2px 9px; border-radius: 999px; font-size: 10.5px; font-weight: 650; }
    .la-badge.online { background: #dcfce7; color: #16a34a; }
    .la-badge.offline { background: #f1f5f9; color: var(--text-muted); }
    .la-badge.success { background: #dcfce7; color: #16a34a; }
    .la-badge.failed { background: #fee2e2; color: #dc2626; }

    .la-filters { background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 14px; padding: 12px; margin-bottom: 14px; }
    .la-filters-row { display: flex; flex-wrap: wrap; gap: 8px; }
    .la-filters-row select, .la-filters-row input {
        border: 1px solid var(--border-color); border-radius: 9px; padding: 8px 10px; font-size: 12.5px;
        background: #f8fafc; outline: none; flex: 1 1 140px; min-width: 120px;
    }
    .la-filters-row select:focus, .la-filters-row input:focus { border-color: var(--accent-blue); background: #fff; }
    .la-custom-dates { display: none; gap: 8px; margin-top: 8px; }
    .la-custom-dates.show { display: flex; }

    .la-export-row { display: flex; gap: 8px; margin-bottom: 14px; flex-wrap: wrap; }
    .la-export-row a {
        border: 1px solid var(--border-color); border-radius: 9px; padding: 8px 14px; font-size: 12.5px;
        font-weight: 650; color: var(--text-dark); background: #fff; text-decoration: none; display: inline-flex;
        align-items: center; gap: 6px;
    }
    .la-export-row a:hover { border-color: var(--accent-blue); color: var(--accent-blue); }

    .la-history-scroll { overflow-x: auto; }
    .la-pager { display: flex; justify-content: space-between; align-items: center; margin-top: 12px; font-size: 12px; color: var(--text-muted); }
    .la-pager button {
        border: 1px solid var(--border-color); background: #fff; border-radius: 8px; padding: 6px 12px; font-size: 12px; font-weight: 650;
    }
    .la-pager button:disabled { opacity: .4; }
    .la-empty-row td { text-align: center; color: var(--text-muted); padding: 24px 10px; }
    .la-loading-row td { text-align: center; color: var(--text-muted); padding: 18px 10px; }
</style>

<div class="scroll-content">
    <div class="la-wrap">
        <div class="la-section-title" style="margin-top:0;">Login Activity &amp; Access Monitoring</div>

        <div class="la-cards">
            <div class="la-card">
                <span class="la-card-label">TOTAL USERS</span>
                <span class="la-card-val" id="laTotalUsers">—</span>
            </div>
            <div class="la-card">
                <span class="la-card-label">LOGGED IN TODAY</span>
                <span class="la-card-val" id="laLoggedInToday">—</span>
            </div>
            <div class="la-card">
                <span class="la-card-label">ACTIVE SESSIONS</span>
                <span class="la-card-val" id="laActiveSessions">—</span>
                <span class="la-card-hint">Approximate — last 2 hrs, no logout recorded</span>
            </div>
            <div class="la-card">
                <span class="la-card-label">FAILED LOGINS TODAY</span>
                <span class="la-card-val" id="laFailedToday">—</span>
            </div>
        </div>

        <div class="la-section-title">Latest Login by User</div>
        <div class="la-latest-scroll">
            <table class="la-table">
                <thead>
                    <tr>
                        <th>Name</th><th>Role</th><th>Status</th><th>Last Login</th>
                        <th>Country</th><th>Device</th><th>IP Address</th>
                    </tr>
                </thead>
                <tbody id="laLatestBody">
                    <tr class="la-loading-row"><td colspan="7">Loading…</td></tr>
                </tbody>
            </table>
        </div>

        <div class="la-section-title">Login History</div>

        <div class="la-filters">
            <div class="la-filters-row">
                <input type="text" id="laSearch" placeholder="Search name, email or user ID…" style="flex: 2 1 220px;">
                <select id="laFilterRole">
                    <option value="All">All Roles</option>
                    <option value="Super Admin">Super Admin</option>
                    <option value="Admin">Admin</option>
                    <option value="Surveyor">Surveyor</option>
                    <option value="Client">Client</option>
                </select>
                <select id="laFilterStatus">
                    <option value="All">All Status</option>
                    <option value="Successful">Successful</option>
                    <option value="Failed">Failed</option>
                </select>
                <select id="laFilterDevice">
                    <option value="All">All Devices</option>
                    <option value="Desktop">Desktop</option>
                    <option value="Mobile">Mobile</option>
                    <option value="Tablet">Tablet</option>
                </select>
                <select id="laFilterCountry">
                    <option value="All">All Countries</option>
                    <?php foreach ($countryOptions as $c): ?>
                        <option value="<?= htmlspecialchars($c, ENT_QUOTES) ?>"><?= htmlspecialchars($c, ENT_QUOTES) ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="laFilterDateRange">
                    <option value="all">All Time</option>
                    <option value="today">Today</option>
                    <option value="yesterday">Yesterday</option>
                    <option value="last7">Last 7 Days</option>
                    <option value="last30">Last 30 Days</option>
                    <option value="custom">Custom Range</option>
                </select>
            </div>
            <div class="la-custom-dates" id="laCustomDates">
                <input type="date" id="laDateFrom">
                <input type="date" id="laDateTo">
            </div>
        </div>

        <div class="la-export-row">
            <a href="#" id="laExportXlsx"><i class="fa-solid fa-file-excel"></i> Excel</a>
            <a href="#" id="laExportCsv"><i class="fa-solid fa-file-csv"></i> CSV</a>
            <a href="#" id="laExportPdf" target="_blank"><i class="fa-solid fa-file-pdf"></i> PDF</a>
        </div>

        <div class="la-history-scroll">
            <table class="la-table">
                <thead>
                    <tr>
                        <th>Name</th><th>Role</th><th>Date &amp; Time</th><th>Country</th>
                        <th>Device</th><th>Browser / OS</th><th>IP Address</th><th>Status</th><th>Duration</th>
                    </tr>
                </thead>
                <tbody id="laHistoryBody">
                    <tr class="la-loading-row"><td colspan="9">Loading…</td></tr>
                </tbody>
            </table>
        </div>
        <div class="la-pager">
            <span id="laPagerInfo">—</span>
            <div>
                <button id="laPagerPrev" type="button">&larr; Prev</button>
                <button id="laPagerNext" type="button">Next &rarr;</button>
            </div>
        </div>
    </div>
</div>

<!-- User Detail modal -->
<div class="modal fade" id="laUserModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable modal-dialog-centered modal-lg">
        <div class="modal-content" style="border-radius: 16px; overflow: hidden;">
            <div class="modal-header">
                <h5 class="modal-title" id="laUserModalTitle">User Login Detail</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" style="font-size: 13px;">
                <div id="laUserModalLoading" class="text-center text-muted py-4">Loading…</div>
                <div id="laUserModalContent" style="display:none;">
                    <div class="row mb-3" id="laUserSummaryGrid" style="font-size:12.5px;"></div>
                    <div class="la-history-scroll">
                        <table class="la-table">
                            <thead>
                                <tr>
                                    <th>Date &amp; Time</th><th>IP</th><th>Country</th><th>Device</th>
                                    <th>Browser / OS</th><th>Status</th><th>Logout</th><th>Duration</th>
                                </tr>
                            </thead>
                            <tbody id="laUserHistoryBody"></tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
const CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;
</script>

<?php include 'includes/nav.php'; ?>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
$(document).ready(function () {
    $.ajaxSetup({ headers: { 'X-CSRF-Token': CSRF_TOKEN } });

    let currentPage = 1;
    const perPage = 25;
    let searchDebounce = null;

    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function currentFilters() {
        const dateRange = $('#laFilterDateRange').val();
        const params = {
            search: $('#laSearch').val().trim(),
            role: $('#laFilterRole').val(),
            status: $('#laFilterStatus').val(),
            device: $('#laFilterDevice').val(),
            country: $('#laFilterCountry').val(),
            date_range: dateRange === 'all' ? '' : dateRange
        };
        if (dateRange === 'custom') {
            params.date_from = $('#laDateFrom').val();
            params.date_to = $('#laDateTo').val();
        }
        return params;
    }

    function exportUrl(base) {
        const qs = $.param(currentFilters());
        return base + '?' + qs;
    }

    function refreshExportLinks() {
        $('#laExportXlsx').attr('href', exportUrl('ajax/login_activity_export.php') + '&format=xlsx');
        $('#laExportCsv').attr('href', exportUrl('ajax/login_activity_export.php') + '&format=csv');
        $('#laExportPdf').attr('href', exportUrl('login_activity_print.php'));
    }

    function loadSummary() {
        $.get('ajax/login_activity_summary.php').done(function (data) {
            if (!data.success) return;
            $('#laTotalUsers').text(data.summary.total_users);
            $('#laLoggedInToday').text(data.summary.logged_in_today);
            $('#laActiveSessions').text(data.summary.active_sessions);
            $('#laFailedToday').text(data.summary.failed_today);

            const body = $('#laLatestBody').empty();
            if (!data.latest_logins || !data.latest_logins.length) {
                body.append('<tr class="la-empty-row"><td colspan="7">No users found.</td></tr>');
                return;
            }
            data.latest_logins.forEach(function (row) {
                const statusBadge = row.login_time
                    ? '<span class="la-badge ' + (row.is_online ? 'online' : 'offline') + '">' + (row.is_online ? 'Online' : 'Offline') + '</span>'
                    : '<span class="la-badge offline">Never logged in</span>';
                const tr = $('<tr>' +
                    (row.user_id ? ' style="cursor:pointer;" data-user-id="' + row.user_id + '"' : '') +
                    '></tr>');
                tr.html(
                    '<td>' + escapeHtml(row.user_name) + '</td>' +
                    '<td>' + escapeHtml(row.role) + '</td>' +
                    '<td>' + statusBadge + '</td>' +
                    '<td>' + escapeHtml(row.login_time || 'Never') + '</td>' +
                    '<td>' + (row.country_flag || '') + ' ' + escapeHtml(row.country || 'Unknown') + '</td>' +
                    '<td>' + escapeHtml(row.device_type || '—') + '</td>' +
                    '<td>' + escapeHtml(row.ip_address || '—') + '</td>'
                );
                if (row.user_id) {
                    tr.on('click', function () { openUserModal(row.user_id, row.user_name); });
                }
                body.append(tr);
            });
        }).fail(function () {
            $('#laLatestBody').html('<tr class="la-empty-row"><td colspan="7">Could not load data.</td></tr>');
        });
    }

    function loadHistory() {
        const params = Object.assign({ page: currentPage, per_page: perPage }, currentFilters());
        $('#laHistoryBody').html('<tr class="la-loading-row"><td colspan="9">Loading…</td></tr>');
        $.get('ajax/login_activity_list.php', params).done(function (data) {
            const body = $('#laHistoryBody').empty();
            if (!data.success) {
                body.append('<tr class="la-empty-row"><td colspan="9">' + escapeHtml(data.message || 'Could not load login activity.') + '</td></tr>');
                return;
            }
            if (!data.rows.length) {
                body.append('<tr class="la-empty-row"><td colspan="9">No records match the selected filters.</td></tr>');
            } else {
                data.rows.forEach(function (row) {
                    const statusBadge = row.login_status === 'SUCCESS'
                        ? '<span class="la-badge success">Successful</span>'
                        : '<span class="la-badge failed">Failed</span>';
                    const tr = $('<tr>' + (row.user_id ? ' style="cursor:pointer;"' : '') + '></tr>');
                    tr.html(
                        '<td>' + escapeHtml(row.user_name || row.username || 'Unknown') + '</td>' +
                        '<td>' + escapeHtml(row.role || '—') + '</td>' +
                        '<td>' + escapeHtml(row.login_time) + '</td>' +
                        '<td>' + (row.country_flag || '') + ' ' + escapeHtml(row.country || 'Unknown') + '</td>' +
                        '<td>' + escapeHtml(row.device_type || '—') + '</td>' +
                        '<td>' + escapeHtml(row.browser || '—') + ' / ' + escapeHtml(row.operating_system || '—') + '</td>' +
                        '<td>' + escapeHtml(row.ip_address || '—') + '</td>' +
                        '<td>' + statusBadge + (row.failure_reason ? ' <div style="color:#dc2626;font-size:10.5px;">' + escapeHtml(row.failure_reason) + '</div>' : '') + '</td>' +
                        '<td>' + escapeHtml(row.session_duration_label) + '</td>'
                    );
                    if (row.user_id) {
                        tr.on('click', function () { openUserModal(row.user_id, row.user_name); });
                    }
                    body.append(tr);
                });
            }

            const totalPages = Math.max(1, Math.ceil(data.total / perPage));
            $('#laPagerInfo').text('Page ' + data.page + ' of ' + totalPages + ' · ' + data.total + ' record(s)');
            $('#laPagerPrev').prop('disabled', data.page <= 1);
            $('#laPagerNext').prop('disabled', data.page >= totalPages);
        }).fail(function () {
            $('#laHistoryBody').html('<tr class="la-empty-row"><td colspan="9">Could not load login activity.</td></tr>');
        });
        refreshExportLinks();
    }

    function openUserModal(userId, userName) {
        const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('laUserModal'));
        $('#laUserModalTitle').text('Login Detail — ' + (userName || ''));
        $('#laUserModalLoading').show();
        $('#laUserModalContent').hide();
        modal.show();

        $.get('ajax/login_activity_user_history.php', { user_id: userId }).done(function (data) {
            $('#laUserModalLoading').hide();
            if (!data.success) {
                Swal.fire({ icon: 'error', title: data.message || 'Could not load user detail.', confirmButtonColor: '#3b32b3' });
                return;
            }
            const u = data.user;
            $('#laUserSummaryGrid').html(
                '<div class="col-6 col-md-4 mb-2"><strong>Email</strong><br>' + escapeHtml(u.user_email || '—') + '</div>' +
                '<div class="col-6 col-md-4 mb-2"><strong>Role</strong><br>' + escapeHtml(u.role || '—') + '</div>' +
                '<div class="col-6 col-md-4 mb-2"><strong>Successful Logins</strong><br>' + u.total_success + '</div>' +
                '<div class="col-6 col-md-4 mb-2"><strong>Failed Attempts</strong><br>' + u.total_failed + '</div>' +
                '<div class="col-6 col-md-4 mb-2"><strong>First Login</strong><br>' + escapeHtml(u.first_login || '—') + '</div>' +
                '<div class="col-6 col-md-4 mb-2"><strong>Latest Login</strong><br>' + escapeHtml(u.latest_login || 'Never') + '</div>' +
                '<div class="col-6 col-md-4 mb-2"><strong>Latest IP</strong><br>' + escapeHtml(u.latest_ip || '—') + '</div>' +
                '<div class="col-6 col-md-4 mb-2"><strong>Latest Country</strong><br>' + (u.latest_country_flag || '') + ' ' + escapeHtml(u.latest_country || 'Unknown') + '</div>' +
                '<div class="col-6 col-md-4 mb-2"><strong>Latest Device</strong><br>' + escapeHtml(u.latest_device || '—') + '</div>'
            );
            const histBody = $('#laUserHistoryBody').empty();
            if (!data.history.length) {
                histBody.append('<tr class="la-empty-row"><td colspan="8">No login history recorded.</td></tr>');
            } else {
                data.history.forEach(function (h) {
                    const statusBadge = h.login_status === 'SUCCESS'
                        ? '<span class="la-badge success">Successful</span>'
                        : '<span class="la-badge failed">Failed</span>';
                    histBody.append('<tr>' +
                        '<td>' + escapeHtml(h.login_time) + '</td>' +
                        '<td>' + escapeHtml(h.ip_address || '—') + '</td>' +
                        '<td>' + (h.country_flag || '') + ' ' + escapeHtml(h.country || 'Unknown') + '</td>' +
                        '<td>' + escapeHtml(h.device_type || '—') + '</td>' +
                        '<td>' + escapeHtml(h.browser || '—') + ' / ' + escapeHtml(h.operating_system || '—') + '</td>' +
                        '<td>' + statusBadge + '</td>' +
                        '<td>' + escapeHtml(h.logout_time || 'Not recorded') + '</td>' +
                        '<td>' + escapeHtml(h.session_duration_label) + '</td>' +
                        '</tr>');
                });
            }
            $('#laUserModalContent').show();
        }).fail(function () {
            $('#laUserModalLoading').hide();
            Swal.fire({ icon: 'error', title: 'Could not load user detail.', confirmButtonColor: '#3b32b3' });
        });
    }

    $('#laSearch').on('input', function () {
        clearTimeout(searchDebounce);
        searchDebounce = setTimeout(function () { currentPage = 1; loadHistory(); }, 400);
    });
    $('#laFilterRole, #laFilterStatus, #laFilterDevice, #laFilterCountry').on('change', function () {
        currentPage = 1; loadHistory();
    });
    $('#laFilterDateRange').on('change', function () {
        $('#laCustomDates').toggleClass('show', $(this).val() === 'custom');
        if ($(this).val() !== 'custom') { currentPage = 1; loadHistory(); }
    });
    $('#laDateFrom, #laDateTo').on('change', function () {
        if ($('#laFilterDateRange').val() === 'custom') { currentPage = 1; loadHistory(); }
    });
    $('#laPagerPrev').on('click', function () { if (currentPage > 1) { currentPage--; loadHistory(); } });
    $('#laPagerNext').on('click', function () { currentPage++; loadHistory(); });

    loadSummary();
    loadHistory();
});
</script>

<?php include 'includes/footer.php'; ?>
