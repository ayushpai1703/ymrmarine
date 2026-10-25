<?php
/**
 * Image → Bunker Survey Form. Photos of the ship's papers / sounding sheets are read by AI,
 * the surveyor checks and completes every value, and the approved Excel calculation format
 * is filled (includes/survey_form.php). Opened from the vessel details pages.
 *   ?survey_id=<id>  choose a format / resume a saved form
 *   ?draft=<id>      the form (Photos → Details → Tanks → Review)
 * Making the form or the Excel never completes a survey — that stays the existing upload step.
 */
require_once 'config/config.php';
checkAuth();
require_once 'includes/survey_form.php';

$db = getDB();
sfEnsureTable($db);
$draftId = (int)($_GET['draft'] ?? 0);
$draft = $draftId ? sfDraft($db, $draftId) : null;
$survey = sfSurvey($db, $draft ? (int)$draft['survey_id'] : (int)($_GET['survey_id'] ?? 0));
$allowed = $survey && sfCanAccess($db, $survey) && (!$draftId || $draft);
$backUrl = $survey ? (($survey['status'] === 'Pending Report' ? 'report_detail.php' : ($survey['status'] === 'Completed' ? 'completed_detail.php' : 'vessel_detail.php')) . '?id=' . (int)$survey['id']) : 'index.php';

include 'includes/header.php';
?>
<div class="scroll-content">
    <?php $page_title = 'Survey Form from Photos'; $back_url = $backUrl; $page_testid = 'survey-form'; include 'includes/top_app_bar.php'; ?>
<?php if (!$allowed): ?>
    <div class="sf-card sf-center" data-testid="sf-no-access">
        <i class="fa-solid fa-lock sf-big-icon"></i>
        <h2 class="sf-h2">Not available</h2>
        <p>This form is only for the vessel's assigned surveyor and admins.</p>
        <a href="index.php" class="sf-btn sf-btn-primary">Return home</a>
    </div>
<?php elseif (!$draft):
    $formats = sfAvailableFormats();
    $suggest = sfSuggestFormat((string)$survey['types']);
    $drafts = sfDraftsForSurvey($db, (int)$survey['id']);
    $canEdit = sfCanEdit($survey);
?>
    <div class="sf-wrap">
        <div class="sf-card">
            <div class="sf-vessel"><i class="fa-solid fa-ship"></i> <b><?= sanitize($survey['vessel_name']) ?></b></div>
            <div class="sf-muted"><?= sanitize($survey['types'] ?: '—') ?> · <?= sanitize($survey['port_name'] ?? '') ?> · <?= sanitize($survey['status']) ?></div>
        </div>
        <?php if ($drafts): ?>
        <div class="sf-card">
            <h2 class="sf-h2">Saved forms</h2>
            <?php foreach ($drafts as $d): ?>
            <a class="sf-draft" href="survey_form.php?draft=<?= (int)$d['id'] ?>" data-testid="sf-draft-link">
                <span><b><?= sanitize(SF_FORMATS[$d['format_key']]['label'] ?? $d['format_key']) ?></b>
                    <span class="sf-pill <?= $d['status'] === 'generated' ? 'sf-pill-ok' : '' ?>"><?= $d['status'] === 'generated' ? 'Excel made' : 'Draft' ?></span><br>
                    <span class="sf-muted"><?= (int)$d['image_count'] ?> photo<?= (int)$d['image_count'] === 1 ? '' : 's' ?> · saved <?= sanitize(date('d M Y H:i', strtotime($d['updated_at']))) ?><?= $d['updated_by_name'] ? ' by ' . sanitize($d['updated_by_name']) : '' ?></span></span>
                <i class="fa-solid fa-chevron-right"></i>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if ($canEdit): ?>
        <div class="sf-card">
            <h2 class="sf-h2"><?= $drafts ? 'Start another form' : 'Start a form' ?></h2>
            <?php if (!$formats): ?>
                <p class="sf-err">No bunker Excel format is available on the server (Formats page).</p>
            <?php else: ?>
            <p class="sf-muted">Choose the Excel format to fill. The vessel name<?= ($_SESSION['role'] ?? '') === 'Surveyor' ? ' and your name are' : ' is' ?> filled in for you.</p>
            <form id="sfStart">
                <?php foreach ($formats as $k => $label): ?>
                <label class="sf-radio"><input type="radio" name="format" value="<?= sanitize($k) ?>" <?= $k === ($suggest ?: array_key_first($formats)) ? 'checked' : '' ?> data-testid="sf-format-<?= sanitize($k) ?>"> <span><?= sanitize($label) ?><?= $k === $suggest ? ' <span class="sf-pill">matches survey type</span>' : '' ?></span></label>
                <?php endforeach; ?>
                <button type="submit" class="sf-btn sf-btn-primary sf-btn-block" data-testid="sf-start">Start form</button>
                <div class="sf-err" id="sfStartErr" hidden></div>
            </form>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="sf-card sf-muted">New forms can be started while the vessel is Pending Vessel or Pending Report.</div>
        <?php endif; ?>
    </div>
    <script>
    (function () {
        var f = document.getElementById('sfStart');
        if (!f) return;
        f.addEventListener('submit', function (e) {
            e.preventDefault();
            var fd = new FormData();
            fd.append('action', 'create');
            fd.append('survey_id', '<?= (int)$survey['id'] ?>');
            fd.append('format', (f.querySelector('input[name=format]:checked') || {}).value || '');
            fetch('ajax/survey_form_api.php', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-CSRF-Token': <?= json_encode(csrf_token()) ?> } })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d.success) { location.href = 'survey_form.php?draft=' + d.draft_id; return; }
                    var err = document.getElementById('sfStartErr'); err.textContent = d.message || 'Could not start the form.'; err.hidden = false;
                });
        });
    })();
    </script>
<?php else: ?>
    <div class="sf-wrap" id="sfApp" data-draft="<?= (int)$draft['id'] ?>">
        <div class="sf-card sf-head">
            <div class="sf-vessel"><i class="fa-solid fa-ship"></i> <b><?= sanitize($survey['vessel_name']) ?></b> <span class="sf-pill"><?= sanitize(SF_FORMATS[$draft['format_key']]['label'] ?? '') ?></span></div>
            <div class="sf-steps" role="tablist">
                <button type="button" class="sf-step" data-step="0" data-testid="sf-step-0"><span>1</span> Photos</button>
                <button type="button" class="sf-step" data-step="1" data-testid="sf-step-1"><span>2</span> Details</button>
                <button type="button" class="sf-step" data-step="2" data-testid="sf-step-2"><span>3</span> Tanks</button>
                <button type="button" class="sf-step" data-step="3" data-testid="sf-step-3"><span>4</span> Review</button>
            </div>
            <div class="sf-legend"><span class="sf-tag sf-tag-ai">AI</span> read from photo · <span class="sf-tag sf-tag-check">Check</span> AI not sure · <span class="sf-tag sf-tag-miss">Missing</span> needed for the report</div>
            <div class="sf-banner" id="sfReadonly" hidden>This vessel is no longer pending — the form is read-only.</div>
        </div>
        <div id="sfPanels">
            <section class="sf-panel" data-panel="0">
                <div class="sf-card">
                    <h2 class="sf-h2">Photos & scans</h2>
                    <p class="sf-muted">Sounding sheets, ullage report, engine log, ship particulars — one or more photos. The originals are kept unchanged.</p>
                    <div class="sf-upload-btns" data-edit-only>
                        <label class="sf-btn sf-btn-primary"><i class="fa-solid fa-camera"></i> Take photo<input type="file" accept="image/*" capture="environment" hidden data-sf-files data-testid="sf-camera"></label>
                        <label class="sf-btn"><i class="fa-solid fa-images"></i> Gallery / files<input type="file" accept="image/*,.heic,.heif,application/pdf" multiple hidden data-sf-files data-testid="sf-files"></label>
                    </div>
                    <div id="sfUploads"></div>
                    <div class="sf-thumbs" id="sfThumbs" data-testid="sf-thumbs"></div>
                </div>
                <div class="sf-card" data-edit-only>
                    <h2 class="sf-h2">Read with AI</h2>
                    <p class="sf-muted">Fills only empty fields with what is clearly readable. Values you already have are never replaced — a different reading is shown as a suggestion. Totals are always calculated by the Excel format, never by the AI.</p>
                    <button type="button" class="sf-btn sf-btn-primary sf-btn-block" id="sfExtract" data-testid="sf-extract"><i class="fa-solid fa-wand-magic-sparkles"></i> Read photos with AI</button>
                    <div id="sfExtractMsg" class="sf-note" hidden data-testid="sf-extract-msg"></div>
                </div>
            </section>
            <section class="sf-panel" data-panel="1"><div id="sfFields"></div></section>
            <section class="sf-panel" data-panel="2"><div id="sfTanks"></div></section>
            <section class="sf-panel" data-panel="3">
                <div id="sfReview" data-testid="sf-review"></div>
            </section>
        </div>
    </div>
    <div class="sf-bar" data-testid="sf-bar">
        <button type="button" class="sf-btn" id="sfBack" data-testid="sf-back" aria-label="Back"><i class="fa-solid fa-chevron-left"></i><span class="sf-label-wide"> Back</span></button>
        <button type="button" class="sf-btn" id="sfSave" data-testid="sf-save"><i class="fa-solid fa-floppy-disk"></i> Save draft</button>
        <button type="button" class="sf-btn sf-btn-primary" id="sfNext" data-testid="sf-next">Next <i class="fa-solid fa-chevron-right"></i></button>
        <div class="sf-savestate" id="sfSaveState" data-testid="sf-savestate"></div>
    </div>
    <script>window.SF_CSRF = <?= json_encode(csrf_token()) ?>;</script>
    <script src="assets/js/survey_form.js?v=<?= @filemtime(__DIR__ . '/assets/js/survey_form.js') ?: time() ?>"></script>
<?php endif; ?>
</div>
<style>
.sf-wrap { max-width: 760px; margin: 0 auto; padding: 12px 12px 150px; }
.sf-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 16px; margin-bottom: 12px; }
.sf-center { text-align: center; margin: 24px 16px; }
.sf-big-icon { font-size: 28px; color: #b91c1c; margin-bottom: 8px; }
.sf-h2 { font-size: 16px; font-weight: 800; color: #0f172a; margin: 0 0 8px; }
.sf-muted { color: #64748b; font-size: 13px; }
.sf-err { color: #b91c1c; font-size: 13px; font-weight: 600; margin-top: 8px; }
.sf-vessel { font-size: 15px; color: #0f172a; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.sf-pill { display: inline-block; font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 999px; background: #EEF2FF; color: #3730A3; }
.sf-pill-ok { background: #DCFCE7; color: #166534; }
.sf-draft { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 12px 4px; border-top: 1px solid #f1f5f9; color: #0f172a; text-decoration: none; min-height: 56px; }
.sf-draft:first-of-type { border-top: 0; }
.sf-radio { display: flex; align-items: center; gap: 12px; min-height: 52px; padding: 8px 12px; border: 1.5px solid #e2e8f0; border-radius: 12px; margin-bottom: 8px; font-size: 15px; font-weight: 600; }
.sf-radio input { width: 22px; height: 22px; }
.sf-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 48px; padding: 10px 16px; border-radius: 12px; border: 1.5px solid #cbd5e1; background: #fff; color: #0f172a; font-weight: 700; font-size: 15px; cursor: pointer; text-decoration: none; }
.sf-btn-primary { background: #1e3a8a; border-color: #1e3a8a; color: #fff; }
.sf-btn-block { width: 100%; margin-top: 8px; }
.sf-btn:disabled { opacity: .5; cursor: not-allowed; }
.sf-steps { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; margin: 12px 0 8px; }
.sf-step { min-height: 44px; border: 0; border-radius: 10px; background: #f1f5f9; color: #475569; font-size: 12.5px; font-weight: 700; display: flex; align-items: center; justify-content: center; gap: 6px; }
.sf-step span { width: 20px; height: 20px; border-radius: 50%; background: #cbd5e1; color: #fff; font-size: 11px; display: inline-flex; align-items: center; justify-content: center; }
.sf-step.active { background: #1e3a8a; color: #fff; }
.sf-step.active span { background: #fff; color: #1e3a8a; }
.sf-legend { font-size: 11.5px; color: #64748b; }
.sf-banner { margin-top: 10px; background: #FFF7ED; border: 1px solid #FDBA74; color: #9A3412; padding: 8px 12px; border-radius: 10px; font-size: 13px; }
.sf-panel { display: none; }
.sf-panel.active { display: block; }
.sf-upload-btns { display: grid; grid-template-columns: 1fr; gap: 8px; margin: 8px 0; }
@media (min-width: 480px) { .sf-upload-btns { grid-template-columns: 1fr 1fr; } }
.sf-upitem { font-size: 12.5px; margin: 8px 0; }
.sf-uptrack { height: 8px; border-radius: 999px; background: #e2e8f0; overflow: hidden; margin-top: 4px; }
.sf-upfill { height: 100%; width: 0; background: linear-gradient(90deg, #2563eb, #5b2dee); transition: width .2s; }
.sf-thumbs { display: grid; grid-template-columns: repeat(auto-fill, minmax(96px, 1fr)); gap: 8px; margin-top: 10px; }
.sf-thumb { position: relative; border-radius: 10px; overflow: hidden; border: 1px solid #e2e8f0; aspect-ratio: 1; background: #f8fafc; display: flex; align-items: center; justify-content: center; text-align: center; font-size: 11px; color: #475569; text-decoration: none; }
.sf-thumb img { width: 100%; height: 100%; object-fit: cover; }
.sf-note { margin-top: 10px; font-size: 13px; background: #EFF6FF; border: 1px solid #BFDBFE; color: #1e3a8a; padding: 10px 12px; border-radius: 10px; }
.sf-note.err { background: #FEF2F2; border-color: #FECACA; color: #991B1B; }
.sf-group-title { font-size: 13px; font-weight: 800; color: #1e3a8a; text-transform: uppercase; letter-spacing: .03em; margin: 4px 0 10px; }
.sf-field { margin-bottom: 14px; }
.sf-field label { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; font-size: 13.5px; font-weight: 700; color: #334155; margin-bottom: 5px; }
.sf-field input { width: 100%; min-height: 48px; font-size: 16px; padding: 10px 12px; border: 1.5px solid #cbd5e1; border-radius: 12px; background: #fff; }
.sf-field input:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,.15); }
.sf-field.is-ai input { background: #EFF6FF; border-color: #93C5FD; }
.sf-field.is-check input { background: #FFFBEB; border-color: #F59E0B; }
.sf-field.is-error input { border-color: #DC2626; background: #FEF2F2; }
.sf-field.is-missing label::after { content: 'Missing'; font-size: 10.5px; font-weight: 800; padding: 1px 7px; border-radius: 999px; background: #FEE2E2; color: #991B1B; }
.sf-tag { font-size: 10.5px; font-weight: 800; padding: 1px 7px; border-radius: 999px; }
.sf-tag-ai { background: #DBEAFE; color: #1e40af; }
.sf-tag-check { background: #FEF3C7; color: #92400E; }
.sf-tag-miss { background: #FEE2E2; color: #991B1B; }
.sf-fielderr { color: #b91c1c; font-size: 12.5px; font-weight: 600; margin-top: 4px; }
.sf-suggest { margin-top: 6px; font-size: 12.5px; background: #F5F3FF; border: 1px dashed #A78BFA; border-radius: 10px; padding: 6px 8px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.sf-suggest button { min-height: 36px; padding: 4px 12px; border-radius: 8px; border: 1px solid #A78BFA; background: #fff; font-weight: 700; font-size: 12.5px; }
.sf-tank { border: 1px solid #e2e8f0; border-radius: 14px; padding: 12px; margin-bottom: 10px; }
.sf-tank-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 8px; font-weight: 800; color: #0f172a; }
.sf-tank-del { min-height: 40px; min-width: 40px; border-radius: 10px; border: 1px solid #fecaca; background: #fff; color: #b91c1c; }
.sf-calc { font-size: 12.5px; color: #475569; background: #f8fafc; border-radius: 10px; padding: 8px 10px; font-variant-numeric: tabular-nums; }
.sf-sec-total { font-weight: 800; color: #0f172a; margin: 6px 0 4px; font-variant-numeric: tabular-nums; }
.sf-table { width: 100%; border-collapse: collapse; font-size: 13.5px; font-variant-numeric: tabular-nums; }
.sf-table th, .sf-table td { padding: 8px 6px; border-bottom: 1px solid #f1f5f9; text-align: right; }
.sf-table th:first-child, .sf-table td:first-child { text-align: left; }
.sf-issue { display: flex; gap: 8px; align-items: flex-start; padding: 8px 0; border-top: 1px solid #f1f5f9; font-size: 13.5px; }
.sf-issue a { color: #1e3a8a; font-weight: 700; }
.sf-bar { position: fixed; left: 0; right: 0; bottom: 76px; z-index: 50; background: rgba(255,255,255,.97); border-top: 1px solid #e2e8f0; box-shadow: 0 -6px 18px rgba(15,23,42,.08); padding: 8px 12px calc(8px + env(safe-area-inset-bottom)); display: grid; grid-template-columns: auto 1fr 1.3fr; gap: 8px; }
.sf-savestate { grid-column: 1 / -1; font-size: 11.5px; color: #64748b; text-align: center; min-height: 14px; }
@media (min-width: 992px) { .sf-bar { bottom: 0; left: 252px; padding-left: 24px; padding-right: 24px; } .sf-wrap { padding-bottom: 110px; } }
.sf-bar .sf-btn { white-space: nowrap; padding: 10px 12px; }
@media (max-width: 420px) { .sf-bar .sf-label-wide { display: none; } }
/* keep the app's floating Notes button clear of the Save / Next bar */
body:has(.sf-bar) .fnotes-fab, body:has(.sf-bar) .fnotes-min-bar { bottom: 172px !important; }
@media (min-width: 992px) { body:has(.sf-bar) .fnotes-fab, body:has(.sf-bar) .fnotes-min-bar { bottom: 96px !important; } }
.sf-results { display: grid; gap: 8px; }
.sf-res { border: 1px solid #e2e8f0; border-radius: 12px; padding: 10px 12px; font-variant-numeric: tabular-nums; }
.sf-res-head { display: flex; justify-content: space-between; font-weight: 800; color: #0f172a; margin-bottom: 4px; }
.sf-res-row { display: flex; justify-content: space-between; font-size: 13.5px; color: #334155; padding: 2px 0; }
</style>
<?php
include 'includes/nav.php';
include 'includes/footer.php';
