<?php
require_once 'config/config.php';
checkAuth();
// Super Admin only — Admin already has full Clients management inside
// admin_controls.php (along with Ports/Survey Types/Agents/Surveyors and
// its own Danger Zone), none of which Super Admin needs access to here.
// This page reuses that same generic module config + ajax/admin_master.php
// backend for just the 'clients' module, so Add/Edit/Delete behave
// identically (same duplicate-name check, same fk_checks safety net that
// blocks deleting a client that still has surveys attached).
if (($_SESSION['role'] ?? '') !== 'Super Admin') {
    header('Location: index.php');
    exit;
}

$modules = require __DIR__ . '/config/admin_modules.php';
$clientsMod = $modules['clients'];

include 'includes/header.php';
?>
<style>
    .admin-search-row { display: flex; gap: 10px; padding: 14px 16px 4px; }
    .admin-search-row input {
        flex: 1;
        border: 1px solid var(--border-color);
        border-radius: 10px;
        padding: 10px 12px;
        font-size: 13px;
        background: #f8fafc;
        outline: none;
    }
    .admin-search-row input:focus { border-color: var(--accent-purple); background: #fff; }
    .admin-add-btn {
        flex: 0 0 auto;
        border: none;
        background: var(--accent-purple);
        color: #fff;
        border-radius: 10px;
        padding: 0 16px;
        font-size: 13px;
        font-weight: 650;
        white-space: nowrap;
    }
    .admin-list-container { padding: 14px 16px 24px; }
    .admin-item-card {
        background: #fff;
        border: 1px solid var(--border-color);
        border-radius: 14px;
        padding: 13px 14px;
        margin-bottom: 10px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
    }
    .admin-item-main { min-width: 0; }
    .admin-item-title { font-size: 13.5px; font-weight: 650; color: var(--text-dark); margin: 0; word-break: break-word; }
    .admin-item-sub { font-size: 11.5px; color: var(--text-muted); margin: 2px 0 0; word-break: break-word; }
    .admin-item-actions { flex: 0 0 auto; display: flex; gap: 6px; }
    .admin-item-actions button {
        width: 32px; height: 32px; border-radius: 8px; border: 1px solid var(--border-color);
        background: #f8fafc; color: var(--text-muted); font-size: 13px;
    }
    .admin-item-actions button.edit-btn:hover { color: var(--accent-purple); border-color: var(--accent-purple); }
    .admin-item-actions button.delete-btn:hover { color: #dc2626; border-color: #dc2626; }
    .admin-item-actions button.login-btn:hover { color: #0ea5b0; border-color: #0ea5b0; }
    .admin-item-actions button.login-btn.no-login { color: #b45309; border-color: #fcd34d; background: #fffbeb; }
    .sac-view-tabs { display: flex; gap: 8px; padding: 14px 16px 0; }
    .sac-view-tab {
        flex: 0 0 auto; padding: 9px 16px; border-radius: 999px; border: 1px solid var(--border-color);
        background: #fff; color: var(--text-muted); font-size: 12.5px; font-weight: 650; white-space: nowrap;
    }
    .sac-view-tab.active { background: var(--accent-purple); border-color: var(--accent-purple); color: #fff; }
    .sac-dup-badge {
        display: inline-block; margin-left: 8px; padding: 2px 8px; border-radius: 999px;
        background: #fef2f2; color: #dc2626; font-size: 10.5px; font-weight: 700; text-transform: uppercase;
        border: 1px solid #fecaca; vertical-align: middle;
    }
    .sac-login-delete-row { border-top: 1px dashed var(--border-color); margin-top: 4px; padding-top: 14px; }
    .sac-login-delete-btn { width: 100%; background: #fef2f2; color: #dc2626; border: 1px solid #fee2e2; border-radius: 10px; padding: 10px; font-weight: 650; font-size: 13px; }
    .admin-empty-state { text-align: center; padding: 30px 10px; color: var(--text-muted); font-size: 12.5px; }
    .admin-loading { text-align: center; padding: 20px; color: var(--text-muted); font-size: 12.5px; }
    .admin-field-group { margin-bottom: 14px; }
    .admin-field-group label { display: block; font-size: 12px; font-weight: 650; color: var(--text-muted); margin-bottom: 5px; }
    .admin-field-group input, .admin-field-group select {
        width: 100%; border: 1px solid var(--border-color); border-radius: 10px;
        padding: 10px 12px; font-size: 13.5px; background: #f8fafc; outline: none; color: var(--text-dark);
    }
    .admin-field-group input:focus, .admin-field-group select:focus { border-color: var(--accent-purple); background: #fff; }
    .admin-field-hint { font-size: 11px; color: var(--text-muted); margin-top: 4px; }
    @media (min-width: 992px) {
        .admin-search-row, .admin-list-container { max-width: 900px; margin-left: auto; margin-right: auto; }
    }
</style>

<div class="scroll-content">
    <?php $page_title = 'Manage Clients'; $back_url = 'index.php'; $page_testid = 'super-admin-clients'; include 'includes/top_app_bar.php'; ?>

    <div class="sac-view-tabs" data-testid="sac-view-tabs">
        <button type="button" class="sac-view-tab active" data-view="companies" data-testid="sac-view-tab-companies">Companies</button>
        <button type="button" class="sac-view-tab" data-view="logins" data-testid="sac-view-tab-logins">Client Logins</button>
    </div>

    <div id="companiesView">
        <div class="admin-search-row">
            <input type="text" id="clientsSearchInput" placeholder="Search clients..." data-testid="sac-clients-search">
            <button type="button" class="admin-add-btn" id="clientsAddBtn" data-testid="sac-clients-add"><i class="fa-solid fa-plus"></i> Add</button>
        </div>
        <div class="admin-list-container" id="clientsListContainer" data-testid="sac-clients-list">
            <div class="admin-loading">Loading...</div>
        </div>
    </div>

    <div id="loginsView" style="display:none;">
        <div class="admin-search-row">
            <input type="text" id="loginsSearchInput" placeholder="Search logins by company, username, email or phone..." data-testid="sac-logins-search">
        </div>
        <div class="admin-list-container" id="loginsListContainer" data-testid="sac-logins-list">
            <div class="admin-loading">Loading...</div>
        </div>
    </div>
</div>

<!-- Add / Edit Modal -->
<div class="modal fade" id="clientFormModal" tabindex="-1" data-testid="sac-client-form-modal">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: 16px; border: none;">
            <div class="modal-header" style="border-bottom: 1px solid var(--border-color);">
                <h5 class="modal-title fw-bold" id="clientFormModalTitle" style="font-size: 15px;">Add Client</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" data-testid="sac-client-form-close"></button>
            </div>
            <form id="clientForm" data-testid="sac-client-form">
                <div class="modal-body" id="clientFormFields"></div>
                <div class="modal-footer" style="border-top: 1px solid var(--border-color);">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn" style="background: var(--accent-purple); color:#fff; font-weight:650;" id="clientFormSubmitBtn" data-testid="sac-client-form-submit">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Manage Client Login Modal (username/password/contact/email/phone —
     separate from company details above, since these live on `users`,
     not `clients`) -->
<div class="modal fade" id="clientLoginModal" tabindex="-1" data-testid="sac-client-login-modal">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: 16px; border: none;">
            <div class="modal-header" style="border-bottom: 1px solid var(--border-color);">
                <h5 class="modal-title fw-bold" id="clientLoginModalTitle" style="font-size: 15px;">Manage Login</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" data-testid="sac-client-login-close"></button>
            </div>
            <form id="clientLoginForm" data-testid="sac-client-login-form">
                <div class="modal-body">
                    <div class="admin-field-group">
                        <label>Username *</label>
                        <input type="text" name="username" autocomplete="off" required data-testid="sac-login-field-username">
                    </div>
                    <div class="admin-field-group">
                        <label>Contact Person *</label>
                        <input type="text" name="full_name" required data-testid="sac-login-field-full_name">
                    </div>
                    <div class="admin-field-group">
                        <label>Email</label>
                        <input type="email" name="email" data-testid="sac-login-field-email">
                    </div>
                    <div class="admin-field-group">
                        <label>Phone</label>
                        <input type="text" name="phone" data-testid="sac-login-field-phone">
                    </div>
                    <div class="admin-field-group">
                        <label id="clientLoginPasswordLabel">Password *</label>
                        <input type="password" name="password" autocomplete="new-password" data-testid="sac-login-field-password">
                        <div class="admin-field-hint" id="clientLoginPasswordHint">Leave blank to keep the current password.</div>
                    </div>
                    <div class="sac-login-delete-row" id="clientLoginDeleteRow" style="display:none;">
                        <button type="button" class="sac-login-delete-btn" id="clientLoginDeleteBtn" data-testid="sac-client-login-delete"><i class="fa-solid fa-trash me-1"></i> Delete This Login</button>
                    </div>
                </div>
                <div class="modal-footer" style="border-top: 1px solid var(--border-color);">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn" style="background: #0ea5b0; color:#fff; font-weight:650;" id="clientLoginSubmitBtn" data-testid="sac-client-login-submit">Save Login</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const CLIENTS_MOD = <?= json_encode($clientsMod, JSON_HEX_TAG | JSON_HEX_APOS) ?>;
const CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;
</script>

<?php include 'includes/nav.php'; ?>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
$(document).ready(function() {
    $.ajaxSetup({ headers: { 'X-CSRF-Token': CSRF_TOKEN } });

    const clientFormModal = new bootstrap.Modal(document.getElementById('clientFormModal'));
    const clientLoginModal = new bootstrap.Modal(document.getElementById('clientLoginModal'));
    let currentEditId = null;
    let currentRows = [];
    let currentLogins = {};
    let currentLoginClientId = null;

    function escapeHtml(str) {
        return $('<div>').text(str == null ? '' : String(str)).html();
    }

    function loadClients(searchTerm) {
        $('#clientsListContainer').html('<div class="admin-loading">Loading...</div>');
        $.ajax({
            url: 'ajax/admin_master.php',
            method: 'GET',
            data: { module: 'clients', action: 'list', q: searchTerm || '' },
            dataType: 'json'
        }).done(function(res) {
            if (!res || !res.success) {
                $('#clientsListContainer').html('<div class="admin-empty-state">Unable to load data.</div>');
                return;
            }
            currentRows = res.rows || [];
            loadLogins(function() {
                if ($('.sac-view-tab[data-view="logins"]').hasClass('active')) {
                    renderLoginsView($('#loginsSearchInput').val());
                } else {
                    renderRows(currentRows);
                }
            });
        }).fail(function() {
            $('#clientsListContainer').html('<div class="admin-empty-state">Network error while loading data.</div>');
        });
    }

    function loadLogins(done) {
        $.ajax({
            url: 'ajax/super_admin_client_login.php',
            method: 'GET',
            data: { action: 'list_logins' },
            dataType: 'json'
        }).done(function(res) {
            currentLogins = (res && res.success) ? (res.logins || {}) : {};
            done();
        }).fail(function() {
            currentLogins = {};
            done();
        });
    }

    function renderRows(rows) {
        const $container = $('#clientsListContainer');
        if (!rows.length) {
            $container.html('<div class="admin-empty-state">No clients found.</div>');
            return;
        }
        let html = '';
        rows.forEach(function(row) {
            const id = row[CLIENTS_MOD.id_field];
            const title = escapeHtml(row[CLIENTS_MOD.name_field] || '');
            let subParts = [];
            CLIENTS_MOD.fields.forEach(function(f) {
                if (f.name === CLIENTS_MOD.name_field || f.type === 'password') return;
                if (row[f.name]) subParts.push(f.label + ': ' + escapeHtml(row[f.name]));
            });
            const login = currentLogins[id];
            subParts.push(login && login.user_id ? 'Login: ' + escapeHtml(login.username) : 'No login yet');
            const sub = subParts.join(' &middot; ');
            const loginBtnClass = (login && login.user_id) ? 'login-btn' : 'login-btn no-login';
            const loginTitle = (login && login.user_id) ? 'Manage Login' : 'Add Login';
            html += '<div class="admin-item-card" data-testid="sac-client-item-' + id + '">' +
                '<div class="admin-item-main">' +
                    '<p class="admin-item-title">' + title + '</p>' +
                    (sub ? '<p class="admin-item-sub">' + sub + '</p>' : '') +
                '</div>' +
                '<div class="admin-item-actions">' +
                    '<button type="button" class="' + loginBtnClass + '" title="' + loginTitle + '" data-id="' + id + '" data-testid="sac-client-login-btn-' + id + '"><i class="fa-solid fa-key"></i></button>' +
                    '<button type="button" class="edit-btn" data-id="' + id + '" data-testid="sac-client-edit-' + id + '"><i class="fa-solid fa-pen"></i></button>' +
                    '<button type="button" class="delete-btn" data-id="' + id + '" data-testid="sac-client-delete-' + id + '"><i class="fa-solid fa-trash"></i></button>' +
                '</div>' +
            '</div>';
        });
        $container.html(html);

        $container.find('.edit-btn').on('click', function() {
            const id = $(this).data('id');
            const row = currentRows.find(r => String(r[CLIENTS_MOD.id_field]) === String(id));
            openForm(row);
        });
        $container.find('.delete-btn').on('click', function() {
            const id = $(this).data('id');
            confirmDelete(id);
        });
        $container.find('.login-btn').on('click', function() {
            const id = $(this).data('id');
            const row = currentRows.find(r => String(r[CLIENTS_MOD.id_field]) === String(id));
            openLoginForm(id, row, currentLogins[id]);
        });
    }

    // ── Client Logins view: every client that HAS a login, in one flat
    // list, so a mistake like "gave the same client two separate logins"
    // (usually two clients rows for the same company, each with its own
    // login — see the Companies view's duplicate-name merge tool) is easy
    // to spot by eye instead of clicking into each company one at a time.
    function loginRowsWithData() {
        return currentRows
            .map(function(row) {
                const id = row[CLIENTS_MOD.id_field];
                const login = currentLogins[id];
                return (login && login.user_id) ? { id: id, row: row, login: login } : null;
            })
            .filter(Boolean);
    }

    function renderLoginsView(searchTerm) {
        const $container = $('#loginsListContainer');
        let entries = loginRowsWithData();

        // Flag possible duplicates: same normalized company name, OR same
        // login's phone/email, shared by 2+ different logins.
        const byCompany = {};
        const byContact = {};
        entries.forEach(function(e) {
            const nameKey = String(e.row[CLIENTS_MOD.name_field] || '').trim().toLowerCase();
            if (nameKey) { (byCompany[nameKey] = byCompany[nameKey] || []).push(e.id); }
            ['email', 'phone'].forEach(function(f) {
                const v = String(e.login[f] || '').trim().toLowerCase();
                if (v) { (byContact[f + ':' + v] = byContact[f + ':' + v] || []).push(e.id); }
            });
        });
        function isDup(e) {
            const nameKey = String(e.row[CLIENTS_MOD.name_field] || '').trim().toLowerCase();
            if (nameKey && byCompany[nameKey] && byCompany[nameKey].length > 1) return true;
            return ['email', 'phone'].some(function(f) {
                const v = String(e.login[f] || '').trim().toLowerCase();
                return v && byContact[f + ':' + v] && byContact[f + ':' + v].length > 1;
            });
        }

        const term = (searchTerm || '').toLowerCase().trim();
        if (term) {
            entries = entries.filter(function(e) {
                const hay = [e.row[CLIENTS_MOD.name_field], e.login.username, e.login.full_name, e.login.email, e.login.phone]
                    .map(function(v) { return String(v || '').toLowerCase(); }).join(' ');
                return hay.indexOf(term) !== -1;
            });
        }

        entries.sort(function(a, b) {
            return String(a.row[CLIENTS_MOD.name_field] || '').localeCompare(String(b.row[CLIENTS_MOD.name_field] || ''));
        });

        if (!entries.length) {
            $container.html('<div class="admin-empty-state">' + (term ? 'No matching logins found.' : 'No client logins exist yet.') + '</div>');
            return;
        }

        let html = '';
        entries.forEach(function(e) {
            const dupBadge = isDup(e) ? '<span class="sac-dup-badge">Possible duplicate</span>' : '';
            const subParts = [
                'Username: ' + escapeHtml(e.login.username || ''),
                'Contact: ' + escapeHtml(e.login.full_name || '')
            ];
            if (e.login.email) subParts.push('Email: ' + escapeHtml(e.login.email));
            if (e.login.phone) subParts.push('Phone: ' + escapeHtml(e.login.phone));
            html += '<div class="admin-item-card" data-testid="sac-login-row-' + e.id + '">' +
                '<div class="admin-item-main">' +
                    '<p class="admin-item-title">' + escapeHtml(e.row[CLIENTS_MOD.name_field] || '') + dupBadge + '</p>' +
                    '<p class="admin-item-sub">' + subParts.join(' &middot; ') + '</p>' +
                '</div>' +
                '<div class="admin-item-actions">' +
                    '<button type="button" class="login-btn" title="Edit Login" data-id="' + e.id + '" data-testid="sac-login-row-edit-' + e.id + '"><i class="fa-solid fa-pen"></i></button>' +
                    '<button type="button" class="delete-btn" title="Delete Login" data-id="' + e.id + '" data-testid="sac-login-row-delete-' + e.id + '"><i class="fa-solid fa-trash"></i></button>' +
                '</div>' +
            '</div>';
        });
        $container.html(html);

        $container.find('.login-btn').on('click', function() {
            const id = $(this).data('id');
            const row = currentRows.find(r => String(r[CLIENTS_MOD.id_field]) === String(id));
            openLoginForm(id, row, currentLogins[id]);
        });
        $container.find('.delete-btn').on('click', function() {
            const id = $(this).data('id');
            confirmDeleteLogin(id);
        });
    }

    $('.sac-view-tab').on('click', function() {
        const view = $(this).data('view');
        $('.sac-view-tab').removeClass('active');
        $(this).addClass('active');
        if (view === 'logins') {
            $('#companiesView').hide();
            $('#loginsView').show();
            renderLoginsView($('#loginsSearchInput').val());
        } else {
            $('#loginsView').hide();
            $('#companiesView').show();
        }
    });

    let loginsSearchTimer = null;
    $('#loginsSearchInput').on('input', function() {
        const term = $(this).val();
        clearTimeout(loginsSearchTimer);
        loginsSearchTimer = setTimeout(function() { renderLoginsView(term); }, 300);
    });

    function openLoginForm(clientId, clientRow, login) {
        currentLoginClientId = clientId;
        const hasLogin = !!(login && login.user_id);
        $('#clientLoginModalTitle').text('Manage Login — ' + (clientRow ? clientRow[CLIENTS_MOD.name_field] : ''));
        $('#clientLoginForm')[0].reset();
        $('[data-testid="sac-login-field-username"]').val(hasLogin ? (login.username || '') : '');
        $('[data-testid="sac-login-field-full_name"]').val(hasLogin ? (login.full_name || '') : (clientRow ? clientRow.contact_person || '' : ''));
        $('[data-testid="sac-login-field-email"]').val(hasLogin ? (login.email || '') : '');
        $('[data-testid="sac-login-field-phone"]').val(hasLogin ? (login.phone || '') : '');
        $('[data-testid="sac-login-field-password"]').prop('required', !hasLogin);
        $('#clientLoginPasswordLabel').text(hasLogin ? 'New Password' : 'Password *');
        $('#clientLoginPasswordHint').text(hasLogin ? 'Leave blank to keep the current password.' : 'Required to create this client\'s first login.');
        $('#clientLoginDeleteRow').toggle(hasLogin);
        clientLoginModal.show();
    }

    function confirmDeleteLogin(clientId) {
        Swal.fire({
            icon: 'warning',
            title: 'Delete this login?',
            text: 'The client\'s company record and vessels are NOT affected — only their username/password login is removed. They will no longer be able to sign in until a new login is created.',
            showCancelButton: true,
            confirmButtonText: 'Delete Login',
            confirmButtonColor: '#dc2626',
            cancelButtonColor: '#64748b'
        }).then(function(result) {
            if (!result.isConfirmed) return;
            $.ajax({
                url: 'ajax/super_admin_client_login.php',
                method: 'POST',
                data: { action: 'delete', client_id: clientId },
                dataType: 'json'
            }).done(function(res) {
                if (res && res.success) {
                    clientLoginModal.hide();
                    Swal.fire({ icon: 'success', title: 'Login Deleted', text: res.message, confirmButtonColor: '#3b32b3', timer: 1600, showConfirmButton: false });
                    loadClients($('#clientsSearchInput').val());
                } else {
                    Swal.fire({ icon: 'error', title: 'Cannot Delete', text: (res && res.message) ? res.message : 'Please try again.', confirmButtonColor: '#3b32b3' });
                }
            }).fail(function() {
                Swal.fire({ icon: 'error', title: 'Network Error', text: 'Please try again.', confirmButtonColor: '#3b32b3' });
            });
        });
    }

    function openForm(row) {
        currentEditId = row ? row[CLIENTS_MOD.id_field] : null;
        $('#clientFormModalTitle').text((row ? 'Edit ' : 'Add ') + CLIENTS_MOD.singular);
        let fieldsHtml = '';
        CLIENTS_MOD.fields.forEach(function(f) {
            const val = row && row[f.name] != null ? row[f.name] : '';
            fieldsHtml += '<div class="admin-field-group">';
            fieldsHtml += '<label>' + escapeHtml(f.label) + (f.required ? ' *' : '') + '</label>';
            fieldsHtml += '<input type="text" name="' + f.name + '" value="' + escapeHtml(val) + '" ' +
                (f.required ? 'required ' : '') + (f.readonly ? 'readonly ' : '') +
                'data-testid="sac-client-field-' + f.name + '">';
            fieldsHtml += '</div>';
        });
        $('#clientFormFields').html(fieldsHtml);
        clientFormModal.show();
    }

    function confirmDelete(id) {
        Swal.fire({
            icon: 'warning',
            title: 'Delete ' + CLIENTS_MOD.singular + '?',
            text: 'This action cannot be undone. A client that still has vessels/surveys assigned cannot be deleted.',
            showCancelButton: true,
            confirmButtonText: 'Delete',
            confirmButtonColor: '#dc2626',
            cancelButtonColor: '#64748b'
        }).then(function(result) {
            if (!result.isConfirmed) return;
            $.ajax({
                url: 'ajax/admin_master.php',
                method: 'POST',
                data: { module: 'clients', action: 'delete', id: id },
                dataType: 'json'
            }).done(function(res) {
                if (res && res.success) {
                    Swal.fire({ icon: 'success', title: 'Deleted', text: res.message, confirmButtonColor: '#3b32b3', timer: 1400, showConfirmButton: false });
                    loadClients($('#clientsSearchInput').val());
                } else {
                    Swal.fire({ icon: 'error', title: 'Cannot Delete', text: (res && res.message) ? res.message : 'Please try again.', confirmButtonColor: '#3b32b3' });
                }
            }).fail(function() {
                Swal.fire({ icon: 'error', title: 'Network Error', text: 'Please try again.', confirmButtonColor: '#3b32b3' });
            });
        });
    }

    $('#clientsAddBtn').on('click', function() { openForm(null); });

    let searchTimer = null;
    $('#clientsSearchInput').on('input', function() {
        const term = $(this).val();
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function() { loadClients(term); }, 300);
    });

    $('#clientForm').on('submit', function(e) {
        e.preventDefault();
        const action = currentEditId ? 'edit' : 'add';
        const formData = $(this).serializeArray();
        const postData = { module: 'clients', action: action };
        if (currentEditId) postData.id = currentEditId;
        formData.forEach(function(f) { postData[f.name] = f.value; });

        const $btn = $('#clientFormSubmitBtn');
        $btn.prop('disabled', true).text('Saving...');

        $.ajax({
            url: 'ajax/admin_master.php',
            method: 'POST',
            data: postData,
            dataType: 'json'
        }).done(function(res) {
            if (res && res.success) {
                clientFormModal.hide();
                Swal.fire({ icon: 'success', title: (action === 'add' ? CLIENTS_MOD.singular + ' Added' : CLIENTS_MOD.singular + ' Updated'), text: res.message, confirmButtonColor: '#3b32b3', timer: 1400, showConfirmButton: false });
                loadClients($('#clientsSearchInput').val());
            } else {
                Swal.fire({ icon: 'error', title: 'Could Not Save', text: (res && res.message) ? res.message : 'Please check the form and try again.', confirmButtonColor: '#3b32b3' });
            }
        }).fail(function() {
            Swal.fire({ icon: 'error', title: 'Network Error', text: 'Please try again.', confirmButtonColor: '#3b32b3' });
        }).always(function() {
            $btn.prop('disabled', false).text('Save');
        });
    });

    $('#clientLoginDeleteBtn').on('click', function() {
        if (currentLoginClientId != null) confirmDeleteLogin(currentLoginClientId);
    });

    $('#clientLoginForm').on('submit', function(e) {
        e.preventDefault();
        const postData = { action: 'save', client_id: currentLoginClientId };
        $(this).serializeArray().forEach(function(f) { postData[f.name] = f.value; });

        const $btn = $('#clientLoginSubmitBtn');
        $btn.prop('disabled', true).text('Saving...');

        $.ajax({
            url: 'ajax/super_admin_client_login.php',
            method: 'POST',
            data: postData,
            dataType: 'json'
        }).done(function(res) {
            if (res && res.success) {
                clientLoginModal.hide();
                Swal.fire({ icon: 'success', title: 'Login Saved', text: res.message, confirmButtonColor: '#3b32b3', timer: 1600, showConfirmButton: false });
                loadClients($('#clientsSearchInput').val());
            } else {
                Swal.fire({ icon: 'error', title: 'Could Not Save', text: (res && res.message) ? res.message : 'Please check the form and try again.', confirmButtonColor: '#3b32b3' });
            }
        }).fail(function() {
            Swal.fire({ icon: 'error', title: 'Network Error', text: 'Please try again.', confirmButtonColor: '#3b32b3' });
        }).always(function() {
            $btn.prop('disabled', false).text('Save Login');
        });
    });

    loadClients('');
});
</script>
<?php include 'includes/footer.php'; ?>
