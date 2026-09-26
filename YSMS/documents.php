<?php
require_once 'config/config.php';
checkAuth();

// Client-only page — lists every uploaded report file (Field Report /
// Formal Report PDF/Excel/Word) across ALL of this client's surveys in one
// place, scoped by their own clients.id the same way reports.php/
// completed.php already do. Not useful for Admin/Surveyor (they already see
// every survey's files on its own detail page), so this page is gated to
// Client the same way add_client.php is gated to Admin/Super Admin.
if (($_SESSION['role'] ?? '') !== 'Client') {
    header('Location: index.php');
    exit;
}

$db = getDB();
$user_id = $_SESSION['user_id'];
$client_id = getClientIdForUser($db, $user_id);

$stmt = $db->prepare("
    SELECT up.id, up.file_name, up.file_type, up.file_size, up.uploaded_at,
           s.id AS survey_id, s.vessel_name, s.status,
           p.port_name, st.type_name
    FROM uploads up
    JOIN surveys s ON s.id = up.survey_id
    LEFT JOIN ports p ON s.port_id = p.id
    LEFT JOIN survey_types st ON s.survey_type_id = st.id
    WHERE s.client_id = ?
    ORDER BY up.uploaded_at DESC, up.id DESC
");
$stmt->execute([$client_id]);
$documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

include 'includes/header.php';
?>
<style>
    .doc-card {
        background: #fff; border: 1px solid var(--border-color); border-radius: 14px;
        padding: 14px; margin: 0 15px 12px; box-shadow: 0 2px 8px rgba(15,23,42,.04);
        display: flex; align-items: center; justify-content: space-between; gap: 12px;
    }
    .doc-card-left { display: flex; align-items: center; gap: 12px; min-width: 0; }
    .doc-icon {
        width: 42px; height: 42px; border-radius: 10px; flex: none;
        display: flex; align-items: center; justify-content: center; font-size: 18px;
        background: #f1f5f9;
    }
    .doc-meta { min-width: 0; }
    .doc-name { font-weight: 700; font-size: 13.5px; color: var(--text-dark); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .doc-sub { font-size: 11.5px; color: var(--text-muted); margin-top: 2px; }
    .doc-actions { display: flex; gap: 8px; flex: none; }
    .doc-actions a {
        display: inline-flex; align-items: center; gap: 6px; padding: 8px 12px; border-radius: 10px;
        font-size: 12.5px; font-weight: 650; text-decoration: none; border: 1px solid var(--border-color);
        color: #3b32b3; background: #fff; white-space: nowrap;
    }
    .doc-actions a:hover { background: #3b32b3; color: #fff; border-color: #3b32b3; }
    @media (min-width: 992px) {
        .doc-list-wrap { max-width: 900px; margin: 0 auto; padding: 0 16px; }
        .doc-card { margin: 0 0 12px; }
    }
</style>
<div class="scroll-content" data-testid="documents-page">
    <?php $page_title = 'Documents'; $back_url = 'index.php'; $page_testid = 'documents'; include 'includes/top_app_bar.php'; ?>

    <div class="doc-list-wrap">
        <?php if (empty($documents)): ?>
            <div class="text-center py-5 px-3 mx-3 mt-3 bg-white rounded-4 border border-dashed shadow-sm" data-testid="documents-empty-state">
                <i class="fa-solid fa-folder-open d-block mb-2 text-muted" style="font-size:32px;"></i>
                <h5 class="mb-1">No documents yet</h5>
                <p class="text-muted small mb-0">Reports and files will appear here once your surveyor uploads them.</p>
            </div>
        <?php else: foreach ($documents as $doc):
            $icon = 'fa-file-lines'; $iconColor = '#64748b';
            $ftype = $doc['file_type'] ?? '';
            if (str_contains($ftype, 'PDF')) { $icon = 'fa-file-pdf'; $iconColor = '#dc2626'; }
            elseif (str_contains($ftype, 'Excel')) { $icon = 'fa-file-excel'; $iconColor = '#16a34a'; }
            elseif (str_contains($ftype, 'Word')) { $icon = 'fa-file-word'; $iconColor = '#2563eb'; }
            $clean_name = preg_replace('/^[0-9]+_/', '', $doc['file_name']);
            $detail_page = ($doc['status'] === 'Completed') ? 'completed_detail.php' : 'report_detail.php';
            $uploaded_disp = !empty($doc['uploaded_at']) ? date('d M Y', strtotime($doc['uploaded_at'])) : '';
        ?>
        <div class="doc-card" data-testid="document-row-<?= (int)$doc['id'] ?>">
            <div class="doc-card-left">
                <div class="doc-icon"><i class="fa-solid <?= $icon ?>" style="color:<?= $iconColor ?>;"></i></div>
                <div class="doc-meta">
                    <div class="doc-name" title="<?= sanitize($clean_name) ?>"><?= sanitize($clean_name) ?></div>
                    <div class="doc-sub">
                        <?= sanitize($doc['vessel_name'] ?: 'Vessel') ?>
                        <?php if (!empty($doc['type_name'])): ?> · <?= sanitize($doc['type_name']) ?><?php endif; ?>
                        <?php if (!empty($doc['port_name'])): ?> · <?= sanitize($doc['port_name']) ?><?php endif; ?>
                        <?php if ($uploaded_disp): ?> · <?= sanitize($uploaded_disp) ?><?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="doc-actions">
                <a href="<?= sanitize($detail_page) ?>?id=<?= (int)$doc['survey_id'] ?>" data-testid="document-view-report-<?= (int)$doc['id'] ?>"><i class="fa-solid fa-eye"></i> View Report</a>
                <a href="download.php?upload_id=<?= (int)$doc['id'] ?>" data-testid="document-download-<?= (int)$doc['id'] ?>"><i class="fa-solid fa-download"></i> Download</a>
            </div>
        </div>
        <?php endforeach; endif; ?>
    </div>
</div>
<?php include 'includes/nav.php'; include 'includes/footer.php'; ?>
