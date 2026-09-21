<?php
/**
 * Read-only view of one stored Recent Vessel Email — Admin only. Renders
 * the body as plain text only (HTML stripped, not sanitized-and-rendered)
 * so nothing from an external email can execute in the browser. Never
 * touches the real mailbox.
 */
require_once 'config/config.php';
checkAuth();
if (($_SESSION['role'] ?? '') !== 'Admin') {
    header('Location: index.php');
    exit;
}

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
$stmt = $db->prepare("SELECT * FROM emails WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$email = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$email) { die('Email not found.'); }

$astmt = $db->prepare("SELECT * FROM email_attachments WHERE email_id = ? ORDER BY id ASC");
$astmt->execute([$id]);
$attachments = $astmt->fetchAll(PDO::FETCH_ASSOC);

$dstmt = $db->prepare("SELECT * FROM email_extracted_data WHERE email_id = ? LIMIT 1");
$dstmt->execute([$id]);
$extracted = $dstmt->fetch(PDO::FETCH_ASSOC) ?: [];

// HTML is deliberately never rendered as HTML — only its stripped text, to
// avoid any risk of an external email's markup/script executing here.
$displayBody = trim((string)$email['body_text']) !== ''
    ? (string)$email['body_text']
    : strip_tags((string)$email['body_html']);

include 'includes/header.php';
?>
<style>
    .vve-box { background:#fff; margin:15px 20px; border-radius:16px; padding:20px; border:1px solid var(--border-color); }
    .vve-meta-row { font-size:12.5px; color:var(--text-muted); margin-bottom:4px; }
    .vve-meta-row b { color: var(--text-dark); }
    .vve-body { white-space:pre-wrap; font-size:13px; color:var(--text-dark); background:#f8fafc; border-radius:10px; padding:14px; margin-top:14px; max-height:420px; overflow-y:auto; }
    .vve-att { display:flex; align-items:center; justify-content:space-between; background:#f8fafc; border:1px solid var(--border-color); border-radius:10px; padding:8px 12px; margin-bottom:6px; font-size:12.5px; }
    @media (min-width: 992px) { .vve-box { max-width: 780px; margin: 20px auto; } }
</style>
<div class="scroll-content">
    <?php $page_title = 'View Email'; $back_url = 'assign_vessel.php'; $page_testid = 'view-vessel-email'; include 'includes/top_app_bar.php'; ?>
    <div class="vve-box shadow-sm" data-testid="vve-box">
        <div class="vve-meta-row"><b>From:</b> <?= sanitize($email['sender_name'] ?: '') ?> &lt;<?= sanitize($email['sender_email'] ?: '') ?>&gt;</div>
        <div class="vve-meta-row"><b>Subject:</b> <?= sanitize($email['subject'] ?: '(no subject)') ?></div>
        <div class="vve-meta-row"><b>Received:</b> <?= $email['received_at'] ? sanitize(date('d M Y, h:i A', strtotime($email['received_at']))) : '—' ?></div>
        <div class="vve-meta-row"><b>Status:</b> <?= sanitize($email['processing_status']) ?></div>

        <?php if (!empty($extracted['vessel_name']) || !empty($extracted['imo_number'])): ?>
            <div class="vve-meta-row mt-2"><b>Extracted Vessel:</b> <?= sanitize($extracted['vessel_name'] ?: '—') ?><?php if (!empty($extracted['imo_number'])): ?> (IMO <?= sanitize($extracted['imo_number']) ?>)<?php endif; ?></div>
        <?php endif; ?>

        <div class="vve-body" data-testid="vve-body"><?= sanitize($displayBody ?: '(empty body)') ?></div>

        <?php if (!empty($attachments)): ?>
            <div class="fw-bold mt-3 mb-2" style="font-size:13px;">Attachments</div>
            <?php foreach ($attachments as $att): ?>
                <div class="vve-att">
                    <span><i class="fa-solid fa-paperclip text-primary me-1"></i><?= sanitize($att['file_name']) ?> <span class="text-muted">(<?= sanitize($att['extraction_status']) ?>)</span></span>
                    <?php if (!empty($att['file_path'])): ?>
                        <a href="<?= sanitize($att['file_path']) ?>" download class="text-primary"><i class="fa-solid fa-download"></i></a>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
<?php include 'includes/nav.php'; include 'includes/footer.php'; ?>
