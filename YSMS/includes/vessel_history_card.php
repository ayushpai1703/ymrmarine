<?php
/**
 * "Previous surveys of this vessel" card (vessel_detail.php). Expects $vesselHistoryRows from
 * vesselHistory(); renders nothing when the vessel has never been surveyed before.
 */
if (empty($vesselHistoryRows)) return;
$vhDate = fn($v) => (!empty($v) && strpos((string)$v, '0000') !== 0) ? date('d M Y', strtotime((string)$v)) : '—';
$vhIcon = fn($name) => match (strtolower(pathinfo((string)$name, PATHINFO_EXTENSION))) {
    'pdf' => 'fa-file-pdf', 'xlsx', 'xls', 'xlsm' => 'fa-file-excel', 'doc', 'docx' => 'fa-file-word',
    'jpg', 'jpeg', 'png' => 'fa-file-image', default => 'fa-file',
};
?>
<style>
.vh-wrap { margin: 16px 12px 0; background: #fff; border: 1px solid var(--border-color, #e2e8f0); border-radius: 16px; overflow: hidden; }
.vh-head { display: flex; align-items: center; gap: 10px; padding: 14px 16px; border-bottom: 1px solid #f1f5f9; }
.vh-head-icon { width: 36px; height: 36px; border-radius: 10px; background: #FFF7ED; color: #C2410C; display: flex; align-items: center; justify-content: center; flex: none; }
.vh-title { font-weight: 800; font-size: 15px; color: #0f172a; }
.vh-sub { font-size: 12px; color: #64748b; }
.vh-item { padding: 12px 16px; border-top: 1px solid #f1f5f9; }
.vh-item:first-of-type { border-top: 0; }
.vh-row1 { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 10px; }
.vh-date { font-weight: 800; color: #0f172a; font-size: 13.5px; }
.vh-pill { font-size: 11px; font-weight: 700; padding: 2px 9px; border-radius: 999px; background: #EEF2FF; color: #3730A3; }
.vh-pill.c { background: #DCFCE7; color: #166534; } .vh-pill.p { background: #FEF3C7; color: #92400E; }
.vh-meta { font-size: 12.5px; color: #334155; margin-top: 4px; line-height: 1.55; }
.vh-meta b { color: #0f172a; }
.vh-files { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
.vh-file { display: inline-flex; align-items: center; gap: 6px; max-width: 100%; border: 1px solid #e2e8f0; border-radius: 10px; padding: 5px 6px 5px 10px; font-size: 12px; background: #f8fafc; }
.vh-file span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 220px; }
.vh-file a { display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px; border-radius: 8px; color: #1e3a8a; text-decoration: none; }
.vh-file a:hover { background: #e0e7ff; }
.vh-nofiles { font-size: 12px; color: #94a3b8; margin-top: 6px; }
@media (min-width: 992px) { .vh-wrap { margin: 18px 24px 0; } }
</style>
<div class="vh-wrap" data-testid="vessel-history">
    <div class="vh-head">
        <div class="vh-head-icon"><i class="fa-solid fa-clock-rotate-left"></i></div>
        <div>
            <div class="vh-title">Previous surveys of this vessel (<?= count($vesselHistoryRows) ?>)</div>
            <div class="vh-sub">For reference — when, where and for whom it was surveyed before, with the documents uploaded then.</div>
        </div>
    </div>
    <?php foreach ($vesselHistoryRows as $h): ?>
    <div class="vh-item" data-testid="vessel-history-item" data-survey-id="<?= (int)$h['id'] ?>">
        <div class="vh-row1">
            <span class="vh-date"><?= $vhDate($h['survey_completed_date'] ?: $h['assign_date']) ?></span>
            <span class="vh-pill <?= $h['status'] === 'Completed' ? 'c' : 'p' ?>"><?= $h['status'] === 'Completed' ? 'Completed' : 'Report Pending' ?></span>
            <?php if (!empty($h['report_number'])): ?><span class="vh-sub"><?= sanitize($h['report_number']) ?></span><?php endif; ?>
        </div>
        <div class="vh-meta">
            <b><?= sanitize($h['vessel_name']) ?></b> · <?= sanitize($h['types'] ?: '—') ?><br>
            <i class="fa-solid fa-location-dot"></i> <?= sanitize(trim(($h['port_name'] ?? '') . ((string)($h['country'] ?? '') !== '' ? ', ' . $h['country'] : ''), ', ') ?: '—') ?>
            · <i class="fa-solid fa-building"></i> <?= sanitize($h['company_name'] ?: '—') ?>
            · <i class="fa-solid fa-user"></i> <?= sanitize($h['surveyors'] ?: '—') ?>
            <?php if ($h['status'] === 'Completed'): ?> · Report <?= $vhDate($h['report_uploaded_date']) ?><?php endif; ?>
        </div>
        <?php if ($h['files']): ?>
        <div class="vh-files">
            <?php foreach ($h['files'] as $f): $fname = preg_replace('/^[0-9]+_/', '', (string)$f['file_name']); ?>
            <div class="vh-file" title="<?= sanitize($f['file_type'] . ' · ' . $fname) ?>">
                <i class="fa-solid <?= $vhIcon($fname) ?>"></i><span><?= sanitize($fname) ?></span>
                <a href="download.php?upload_id=<?= (int)$f['id'] ?>&inline=1" target="_blank" rel="noopener" title="View" aria-label="View <?= sanitize($fname) ?>" data-testid="vessel-history-view"><i class="fa-solid fa-eye"></i></a>
                <a href="download.php?upload_id=<?= (int)$f['id'] ?>" title="Download" aria-label="Download <?= sanitize($fname) ?>"><i class="fa-solid fa-download"></i></a>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="vh-nofiles">No documents uploaded on this survey.</div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
