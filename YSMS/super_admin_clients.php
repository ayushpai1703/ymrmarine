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

    <div class="admin-search-row">
        <input type="text" id="clientsSearchInput" placeholder="Search clients..." data-testid="sac-clients-search">
        <button type="button" class="admin-add-btn" id="clientsAddBtn" data-testid="sac-clients-add"><i class="fa-solid fa-plus"></i> Add</button>
    </div>
    <div class="admin-list-container" id="clientsListContainer" data-testid="sac-clients-list">
        <div class="admin-loading">Loading...</div>
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
    let currentEditId = null;
    let currentRows = [];

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
            renderRows(currentRows);
        }).fail(function() {
            $('#clientsListContainer').html('<div class="admin-empty-state">Network error while loading data.</div>');
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
            const title = escapeHtml(row[CLIENTS_MOD.name_field] || '');
            let subParts = [];
            CLIENTS_MOD.fields.forEach(function(f) {
                if (f.name === CLIENTS_MOD.name_field || f.type === 'password') return;
                if (row[f.name]) subParts.push(f.label + ': ' + escapeHtml(row[f.name]));
            });
            const sub = subParts.join(' &middot; ');
            html += '<div class="admin-item-card" data-testid="sac-client-item-' + row[CLIENTS_MOD.id_field] + '">' +
                '<div class="admin-item-main">' +
                    '<p class="admin-item-title">' + title + '</p>' +
                    (sub ? '<p class="admin-item-sub">' + sub + '</p>' : '') +
                '</div>' +
                '<div class="admin-item-actions">' +
                    '<button type="button" class="edit-btn" data-id="' + row[CLIENTS_MOD.id_field] + '" data-testid="sac-client-edit-' + row[CLIENTS_MOD.id_field] + '"><i class="fa-solid fa-pen"></i></button>' +
                    '<button type="button" class="delete-btn" data-id="' + row[CLIENTS_MOD.id_field] + '" data-testid="sac-client-delete-' + row[CLIENTS_MOD.id_field] + '"><i class="fa-solid fa-trash"></i></button>' +
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

    loadClients('');
});
</script>
<?php include 'includes/footer.php'; ?>
