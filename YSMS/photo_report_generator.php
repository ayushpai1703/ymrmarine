<?php
require_once 'config/config.php';
checkAuth();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$vesselName = '';
$surveyDate = date('Y-m-d');
$reportNumber = '';
$backUrl = 'reports.php';

if ($id > 0) {
    $db = getDB();
    try {
        $stmt = $db->prepare("SELECT vessel_name, survey_completed_date, report_number FROM surveys WHERE id = ?");
        $stmt->execute([$id]);
        $survey = $stmt->fetch();
    } catch (Exception $e) {
        // report_number column may not exist yet on older DBs
        $stmt = $db->prepare("SELECT vessel_name, survey_completed_date FROM surveys WHERE id = ?");
        $stmt->execute([$id]);
        $survey = $stmt->fetch();
    }
    if ($survey) {
        $vesselName = $survey['vessel_name'] ?? '';
        if (!empty($survey['survey_completed_date']) && $survey['survey_completed_date'] !== '0000-00-00') {
            $surveyDate = date('Y-m-d', strtotime($survey['survey_completed_date']));
        }
        if (!empty($survey['report_number'])) {
            $reportNumber = $survey['report_number'];
        } else {
            $reportNumber = 'YMR-' . date('Y') . '-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT);
        }
        $backUrl = 'report_detail.php?id=' . $id;
    }
}

include 'includes/header.php';
?>

<style>
.prg-card {
    background: #fff;
    border: 1px solid var(--border-color);
    border-radius: 14px;
    box-shadow: 0 4px 16px rgba(15, 23, 42, 0.06);
    margin: 12px 12px 0;
    overflow: hidden;
}
.prg-card-header {
    background: linear-gradient(90deg, #0b1e46, #1e3a8a);
    color: #fff;
    font-weight: 600;
    font-size: 13px;
    padding: 10px 14px;
}
.prg-card-body { padding: 14px; }
.prg-upload-zone {
    border: 2px dashed #a0c4d8;
    border-radius: 12px;
    background: #e8f4f8;
    padding: 1.1rem;
    text-align: center;
    cursor: pointer;
}
.prg-upload-zone:hover,
.prg-upload-zone.dragover {
    border-color: #00b4d8;
    background: #d6f0f8;
}
.prg-photo-item {
    background: #fff;
    border: 1px solid #e0eaf0;
    border-radius: 12px;
    padding: .7rem;
    margin-bottom: .55rem;
}
.prg-photo-item.dragging { opacity: .45; border: 2px dashed #00b4d8; }
/* Section · Hold N · Subsection · Position — surfaces which report
   section/category_code a photo (manually picked) will sort into. */
.prg-classify-info {
    font-size: 10.5px; color: #475569; margin-top: 3px; padding-left: 1px;
}
.prg-classify-info.empty { display: none; }
.prg-classify-info i { color: #94a3b8; margin-right: 3px; }
/* Structure picker — horizontal multi-level cascading button-group nav:
   Level 1 (Category) is an always-visible row of pill buttons; picking
   one that has children reveals its own Level 2 (Sub Category) row
   directly below, and picking a Level 2 item that has its own children
   reveals a Level 3 (Sub-Sub Category) row below that. Only the active
   branch's row is shown at each level — the hierarchy never expands as
   one long vertical list. Each row WRAPS onto as many lines as needed
   (never horizontal scroll) so every option stays visible on screen at
   once, just as small pills. The actual pick is only committed once a
   final (leaf) button is clicked, via onHoldNestPick. */
.prg-cat-picker { display: flex; flex-direction: column; gap: 2px; margin-top: .35rem; }
.prg-cat-level { display: flex; align-items: flex-start; gap: 6px; margin-top: 4px; }
.prg-cat-label { font-size: 10.5px; font-weight: 650; color: #64748b; padding-top: 6px; white-space: nowrap; }
.prg-cat-scroll {
  display: flex; flex-wrap: wrap;
  gap: 5px; flex: 1 1 auto; min-width: 0;
}
.prg-cat-btn {
  display: inline-flex; align-items: center; gap: 4px; flex: 0 0 auto;
  font-size: 11px; font-weight: 700; padding: 5px 11px; border-radius: 999px;
  border: 1.5px solid #2563eb; background: #fff; color: #1e3a8a;
  cursor: pointer; line-height: 1.2; white-space: nowrap;
  transition: background .15s ease, color .15s ease, border-color .15s ease;
}
.prg-cat-btn:hover { background: #eff4ff; }
.prg-cat-btn.active { background: #1e3a8a; border-color: #1e3a8a; color: #fff; }
.prg-cat-btn.active:hover { background: #1e3a8a; }
.prg-cat-panel { display: none; }
/* AI auto-select status (ajax/gemini_describe.php classification mode) */
.prg-ai-tag { display: inline-flex; align-items: center; gap: 4px; font-size: 10.5px; font-weight: 700; padding: 1px 8px; border-radius: 999px; margin-left: 6px; }
.prg-ai-tag.run { background: #eff6ff; color: #1e40af; }
.prg-ai-tag.ok { background: #ecfdf5; color: #047857; }
.prg-ai-tag.low { background: #fff7ed; color: #9a3412; }
.prg-ai-tag.err { background: #fef2f2; color: #b91c1c; }
.prg-ai-btn { border-color: #2563eb; color: #1e3a8a; }
.prg-ai-btn:hover { background: #1e3a8a; color: #fff; }
.prg-cat-panel.show { display: flex; }

.prg-photo-thumb {
    width: 90px;
    height: 68px;
    object-fit: cover;
    border-radius: 8px;
    cursor: zoom-in;
    transition: box-shadow .15s ease;
}
.prg-photo-thumb:hover {
    box-shadow: 0 0 0 2px #00b4d8;
}
/* Hover zoom preview — large floating image for easy description writing */
.prg-zoom-preview {
    position: fixed;
    z-index: 9999;
    pointer-events: none;
    display: none;
    background: #0f172a;
    border: 3px solid #00b4d8;
    border-radius: 12px;
    box-shadow: 0 16px 40px rgba(15,23,42,.45);
    overflow: hidden;
    max-width: min(420px, 92vw);
    max-height: min(320px, 70vh);
}
.prg-zoom-preview img {
    display: block;
    width: 100%;
    height: 100%;
    max-width: 420px;
    max-height: 320px;
    object-fit: contain;
    background: #0f172a;
}
.prg-zoom-preview.show { display: block; }
.prg-template-ok {
    background: #e8f6f0;
    border-left: 4px solid #28a745;
    border-radius: 8px;
    padding: .55rem 1rem;
    font-size: .88rem;
}
.prg-cat-section {
    border: 1px solid #d0e0ec;
    border-radius: 12px;
    padding: 0;
    margin-bottom: .85rem;
    background: #f8fcfe;
    overflow: visible;
}
.prg-cat-section:not(.open) { overflow: hidden; }
.prg-cat-body { overflow: visible; }
.prg-photo-item { overflow: visible; }
.prg-cat-head {
    display: flex;
    align-items: center;
    gap: 8px;
    width: 100%;
    border: 0;
    background: #eef6fb;
    color: #0b1e46;
    font-weight: 700;
    font-size: 13px;
    padding: .7rem .85rem;
    cursor: pointer;
    text-align: left;
}
.prg-cat-head:hover { background: #e2f0f8; }
.prg-cat-head .prg-cat-chevron {
    font-size: 11px;
    color: #64748b;
    transition: transform .18s ease;
    margin-left: auto;
}
.prg-cat-section:not(.open) .prg-cat-chevron { transform: rotate(-90deg); }
.prg-cat-section:not(.open) .prg-cat-body { display: none; }
.prg-cat-body { padding: .65rem .85rem .85rem; }
.prg-cat-badge { font-size: .72rem; font-weight: 600; }
.prg-generate-wrap { padding: 16px 12px 24px; text-align: center; }
.prg-btn-marine {
    background: linear-gradient(135deg, #0b1e46, #1e3a8a);
    border: none;
    color: #fff;
    font-weight: 600;
    border-radius: 10px;
    padding: .7rem 1.5rem;
    width: 100%;
    max-width: 360px;
}
.prg-btn-marine:hover { color: #fff; opacity: .92; }
.prg-btn-marine:disabled { opacity: .7; }
/* Full-screen progress overlay for upload / generate */
.prg-progress-overlay {
    position: fixed; inset: 0; z-index: 2000;
    background: rgba(11, 30, 70, 0.55);
    display: none; align-items: center; justify-content: center;
    padding: 16px;
}
.prg-progress-overlay.show { display: flex; }
.prg-progress-card {
    background: #fff; border-radius: 14px; padding: 1.25rem 1.4rem;
    width: 100%; max-width: 420px; box-shadow: 0 12px 40px rgba(0,0,0,.25);
}
.prg-progress-card .prg-prog-title { font-weight: 700; font-size: 15px; color: #0b1e46; }
.prg-progress-card .prg-prog-sub { font-size: 12.5px; color: #64748b; margin-top: 4px; }
.prg-progress-card .progress { height: 12px; border-radius: 8px; background: #e2e8f0; margin-top: 12px; }
.prg-progress-card .progress-bar {
    background: linear-gradient(90deg, #0b1e46, #1e3a8a, #00b4d8);
    border-radius: 8px; transition: width .2s ease;
}
.prg-progress-card .prg-prog-pct { font-size: 12px; font-weight: 600; color: #0b1e46; margin-top: 8px; text-align: right; }
</style>

<div class="scroll-content">
    <?php
    $page_title = 'Photo Report Generator';
    $back_url = $backUrl;
    $page_testid = 'photo-report-generator';
    include 'includes/top_app_bar.php';
    ?>

    <!-- Report Details -->
    <div class="prg-card">
        <div class="prg-card-header"><i class="fa-solid fa-ship me-1"></i> Report Details</div>
        <div class="prg-card-body">
            <div class="row g-3">
                <div class="col-12 col-md-4">
                    <label class="form-label small fw-semibold mb-1">Vessel Name</label>
                    <input type="text" class="form-control form-control-sm" id="vesselName" placeholder="Ocean Explorer" value="<?= sanitize($vesselName) ?>"/>
                </div>
                <div class="col-6 col-md-4">
                    <label class="form-label small fw-semibold mb-1">Survey Date</label>
                    <input type="date" class="form-control form-control-sm" id="surveyDate" value="<?= sanitize($surveyDate) ?>"/>
                </div>
                <div class="col-6 col-md-4">
                    <label class="form-label small fw-semibold mb-1">Report Number</label>
                    <input type="text" class="form-control form-control-sm" id="reportNumber" placeholder="YMR-2026-10142" value="<?= sanitize($reportNumber) ?>"/>
                </div>
            </div>
        </div>
    </div>

    <!-- Template — loaded automatically from Admin upload (no surveyor upload) -->
    <div class="prg-card">
        <div class="prg-card-header"><i class="fa-solid fa-file-word me-1"></i> Word Template</div>
        <div class="prg-card-body">
            <div id="templateLoading" class="text-muted small py-2">
                <span class="spinner-border spinner-border-sm me-1"></span> Loading admin template…
            </div>
            <div id="templateStatus" class="mt-0" style="display:none">
                <div class="prg-template-ok d-flex justify-content-between align-items-center">
                    <span><i class="fa-solid fa-circle-check text-success me-1"></i><span id="templateName"></span>
                        <span class="text-muted small ms-1" id="templateMeta"></span>
                    </span>
                </div>
            </div>
            <div id="templateMissing" class="alert alert-warning mb-0 py-2" style="display:none; font-size:13px;">
                <i class="fa-solid fa-triangle-exclamation me-1"></i>
                <strong>Photo Report template not uploaded.</strong><br>
                Please <strong>contact Admin</strong> to upload the YMR Photo Report template (Admin Controls → Word / Photo Templates).
            </div>
        </div>
    </div>

    <!-- Category + Upload -->
    <div class="prg-card">
        <div class="prg-card-header d-flex justify-content-between align-items-center">
            <span><i class="fa-regular fa-images me-1"></i> Upload Photos by Category</span>
            <span class="badge bg-info" id="photoCount">0</span>
        </div>
        <div class="prg-card-body">
            <div class="row g-2 mb-3 align-items-end">
                <div class="col-12 col-md-5">
                    <label class="form-label small fw-semibold mb-1">Select Category</label>
                    <select class="form-select form-select-sm" id="categorySelect">
                        <option value="Ship Side">Ship Side</option>
                        <option value="Hold No. 1">Hold No. 1</option>
                        <option value="Hold No. 2">Hold No. 2</option>
                        <option value="Hold No. 3">Hold No. 3</option>
                        <option value="Hold No. 4">Hold No. 4</option>
                        <option value="Hold No. 5">Hold No. 5</option>
                        <option value="Hold No. 6">Hold No. 6</option>
                        <option value="Hold No. 7">Hold No. 7</option>
                        <option value="Hold No. 8">Hold No. 8</option>
                        <option value="Hold No. 9">Hold No. 9</option>
                        <option value="Deck Side">Deck Side</option>
                        <option value="Others">Others</option>
                    </select>
                </div>
                <div class="col-12 col-md-7">
                    <div class="prg-upload-zone py-3" id="photoZone">
                        <i class="fa-regular fa-images fa-lg text-primary"></i>
                        <p class="mb-0 small fw-semibold">Click or drop photos for selected category</p>
                        <input type="file" id="photoInput" accept="image/*" multiple class="d-none"/>
                    </div>
                </div>
            </div>
            <div id="photoList"></div>
            <div id="photoControls" class="mt-2" style="display:none">
                <button type="button" class="btn btn-outline-primary btn-sm me-1" onclick="sortPhotosForReport(); renderPhotos(); prgMarkDirty(); toast('Photos sorted by Ship / Hold / Deck structure order');"><i class="fa-solid fa-arrow-down-short-wide me-1"></i>Auto-sort order</button>
                <?php if (AI_PHOTO_DESCRIPTION_ENABLED): ?>
                <button type="button" class="btn btn-outline-primary btn-sm me-1" onclick="prgAiSelectUnpicked()" data-testid="prg-ai-all" title="AI looks at each photo and selects Category / Sub Category / Sub-Sub Category (photos not yet picked)"><i class="fa-solid fa-wand-magic-sparkles me-1"></i>AI auto-select</button>
                <?php endif; ?>
                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="clearAllPhotos()"><i class="fa-solid fa-trash me-1"></i>Clear All</button>
            </div>
            <!-- Live estimated Word file size — Original / Compressed tabs -->
            <div id="prgSizeEstimate" class="mt-3 p-2 rounded border" style="display:none; background:#f0f7fb; border-color:#c5dce8 !important; font-size:12.5px;">
                <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                    <strong><i class="fa-solid fa-file-word text-primary me-1"></i>Estimated Word file size</strong>
                    <span id="prgSizeBadge" class="badge bg-secondary">—</span>
                </div>
                <div class="btn-group btn-group-sm mb-2" role="group">
                    <button type="button" class="btn btn-primary" id="prgTabOriginal" onclick="setPrgPreviewMode('original')">Original</button>
                    <button type="button" class="btn btn-outline-primary" id="prgTabCompressed" onclick="setPrgPreviewMode('compressed')">Compressed</button>
                </div>
                <div id="prgSizeDetail" class="text-muted" style="line-height:1.5;"></div>
                <div class="progress mt-2" style="height:8px;">
                    <div id="prgSizeBar" class="progress-bar" role="progressbar" style="width:0%; background:#0b1e46;"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="prg-generate-wrap">
        <div class="d-flex flex-wrap justify-content-center gap-2 mb-2">
            <button type="button" class="btn prg-btn-marine" id="generateBtnOriginal" onclick="generateReport('original')">
                <i class="fa-solid fa-download me-1"></i> Generate Original
            </button>
            <button type="button" class="btn prg-btn-marine" id="generateBtnCompressed" onclick="generateReport('compressed')">
                <i class="fa-solid fa-file-zipper me-1"></i> Generate Compressed
            </button>
        </div>
        <div class="text-muted mb-1" style="font-size:11.5px;">
            Original = high quality (80–100 photos look sharp) · Compressed = target ~5–7 MB for email
        </div>
        <div id="prgAutoSaveStatus" class="text-muted mt-1" style="font-size:11.5px;" data-testid="photo-report-autosave-status">
            <i class="fa-solid fa-cloud me-1"></i> Auto-save every 10s — draft restores if you leave and come back
        </div>
    </div>
</div>

<!-- Progress overlay: upload + generate -->
<div id="prgProgressOverlay" class="prg-progress-overlay" aria-live="polite">
    <div class="prg-progress-card">
        <div class="prg-prog-title" id="prgProgTitle">Working…</div>
        <div class="prg-prog-sub" id="prgProgSub">Please wait</div>
        <div class="progress">
            <div class="progress-bar" id="prgProgBar" role="progressbar" style="width:0%"></div>
        </div>
        <div class="prg-prog-pct" id="prgProgPct">0%</div>
    </div>
</div>

<div class="position-fixed bottom-0 end-0 p-3" style="z-index:1100">
    <div id="toast" class="toast align-items-center text-bg-dark border-0">
        <div class="d-flex">
            <div class="toast-body" id="toastBody"></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>

<?php include 'includes/nav.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/jszip@3.10.1/dist/jszip.min.js"></script>
<script>
/* ===== Draft key scoped to this survey (or standalone session) ===== */
var PRG_SURVEY_ID = <?= (int)$id ?>;
var PRG_DRAFT_KEY = 'prg_draft_v1_' + (PRG_SURVEY_ID > 0 ? String(PRG_SURVEY_ID) : 'standalone');
var PRG_AUTOSAVE_MS = 10000;

var PRG_VESSEL_NAME = <?= json_encode($vesselName, JSON_UNESCAPED_UNICODE) ?>;
var PRG_AI_ENABLED = <?= AI_PHOTO_DESCRIPTION_ENABLED ? 'true' : 'false' ?>;
var PRG_AI_AUTO = <?= (AI_PHOTO_DESCRIPTION_ENABLED && AI_AUTO_DESCRIPTION) ? 'true' : 'false' ?>;
var PRG_AI_CONCURRENCY = <?= (int)AI_MAX_CONCURRENT ?>;

/* ===== Default description options (first = default selected) ===== */
var DESC_OPTIONS = [
  "General condition of structure",
  "Corrosion / wastage observed",
  "Coating condition",
  "No visible defects",
  "Damage / deformation noted",
  "Other (see remarks)"
];

/* ===== Hold No.1–9 Description main/sub options (auto "View of …") ===== */
/* Order here = report order (Hatch entrance → … → Bilge tanks) */
var HOLD_MAIN_OPTIONS = [
  "Hatch Entrance",
  "Hatch Covers",
  "Coamings",
  "Bulkheads",
  "Bulk Frames",
  "Hoppers",
  "Ladders",
  "Tank Top",
  "Bilge Tanks",
  "Drain Channel"
];
var HOLD_SUB_MAP = {
  "Hatch Entrance": ["Top"],
  "Hatch Covers": ["Forward", "Port Side", "Starboard Side", "Aft"],
  "Coamings": ["Forward", "Port Side", "Starboard Side", "Aft"],
  "Bulkheads": ["Forward", "Port Side", "Starboard Side", "Aft"],
  "Bulk Frames": ["Port Side", "Starboard Side"],
  "Hoppers": {
    "Lower": ["Port Side", "Starboard Side"],
    "Upper": ["Port Side", "Starboard Side"]
  },
  "Ladders": ["Australian", "Vertical", "Inclination"],
  "Tank Top": [],
  "Bilge Tanks": ["Port Side", "Starboard Side"],
  "Drain Channel": ["Port Side", "Starboard Side", "Aft", "Forward"]
};
/* Singular noun used in auto description text */
var HOLD_SINGULAR = {
  "Hatch Entrance": "Hatch Entrance",
  "Hatch Covers": "Hatch Cover",
  "Coamings": "Coaming",
  "Bulkheads": "Bulkhead",
  "Bulk Frames": "Bulk Frame",
  "Hoppers": "Hopper",
  "Ladders": "Ladder",
  "Tank Top": "Tank Top",
  "Bilge Tanks": "Bilge Tank",
  "Drain Channel": "Drain Channel"
};

/* ===== Ship Side structure (dropdown + sub-menu) ===== */
/* Order = report auto-sort order */
var SHIP_MAIN_OPTIONS = [
  "Forward Section",
  "Bulbous Bow",
  "Hull",
  "Draft Marks",
  "Weather Deck",
  "Mooring Station",
  "Forward Mooring Winches",
  "Anchor Chain",
  "Windlass",
  "Life Boat",
  "Forward Cross Deck",
  "Aft Cross Deck",
  "Aft Mooring Winches",
  "Aft Section",
  "Rudder"
];
/* Array = 2-level; object = 3-level (side → section); [] = no sub */
var SHIP_SUB_MAP = {
  "Forward Section": [],
  "Bulbous Bow": [],
  "Hull": {
    "Port Side": ["At Bow Section", "At Midship", "At Aft Section"],
    "Starboard Side": ["At Bow Section", "At Midship", "At Aft Section"]
  },
  "Draft Marks": {
    "Port Side": ["Forward", "Midship", "Aft"],
    "Starboard Side": ["Forward", "Midship", "Aft"]
  },
  "Weather Deck": ["Port Side", "Starboard Side"],
  "Mooring Station": ["Forecastle Deck", "Poop Deck"],
  "Forward Mooring Winches": ["Port Side", "Starboard Side"],
  "Anchor Chain": [],
  "Windlass": ["Port Side", "Starboard Side"],
  "Life Boat": ["Port Side", "Starboard Side"],
  "Forward Cross Deck": [],
  "Aft Cross Deck": [],
  "Aft Mooring Winches": ["Port Side", "Starboard Side"],
  "Aft Section": [],
  "Rudder": []
};
var SHIP_SINGULAR = {
  "Forward Section": "Forward Section",
  "Bulbous Bow": "Bulbous Bow",
  "Hull": "Hull",
  "Draft Marks": "Draft Marks",
  "Weather Deck": "Weather Deck",
  "Mooring Station": "Mooring Station",
  "Forward Mooring Winches": "Forward Mooring Winches",
  "Anchor Chain": "Anchor Chain",
  "Windlass": "Windlass",
  "Life Boat": "Life Boat",
  "Forward Cross Deck": "Forward Cross Deck",
  "Aft Cross Deck": "Aft Cross Deck",
  "Aft Mooring Winches": "Aft Mooring Winches",
  "Aft Section": "Aft Section",
  "Rudder": "Rudder"
};

/* ===== Deck Side structure (dropdown + sub-menu) ===== */
/* Order = report auto-sort order */
var DECK_MAIN_OPTIONS = [
  "Main Deck",
  "Forward Main Deck Railing",
  "Aft Main Deck Railing"
];
var DECK_SUB_MAP = {
  "Main Deck": {
    "Port Side": ["From Aft Side", "From Forward Side"],
    "Starboard Side": ["From Aft Side", "From Forward Side"]
  },
  "Forward Main Deck Railing": ["Port Side", "Starboard Side"],
  "Aft Main Deck Railing": ["Port Side", "Starboard Side"]
};
var DECK_SINGULAR = {
  "Main Deck": "Main Deck",
  "Forward Main Deck Railing": "Forward Main Deck Railing",
  "Aft Main Deck Railing": "Aft Main Deck Railing"
};

/** Structure config for Hold / Ship Side / Deck Side (shared nest UI) */
function structCfg(cat) {
  if (cat === 'Ship Side') {
    return { main: SHIP_MAIN_OPTIONS, sub: SHIP_SUB_MAP, singular: SHIP_SINGULAR, kind: 'ship' };
  }
  if (cat === 'Deck Side') {
    return { main: DECK_MAIN_OPTIONS, sub: DECK_SUB_MAP, singular: DECK_SINGULAR, kind: 'deck' };
  }
  return { main: HOLD_MAIN_OPTIONS, sub: HOLD_SUB_MAP, singular: HOLD_SINGULAR, kind: 'hold' };
}
function isHoldCategory(cat) {
  return /^Hold No\. [1-9]$/.test(cat || '');
}
function isShipSideCategory(cat) {
  return (cat || '') === 'Ship Side';
}
function isDeckSideCategory(cat) {
  return (cat || '') === 'Deck Side';
}
function isStructureCategory(cat) {
  return isHoldCategory(cat) || isShipSideCategory(cat) || isDeckSideCategory(cat);
}

/** Normalize SUB_MAP entry: array (2-level) or object (3-level mid → leaves) */
function holdSubIsNested(main, cat) {
  var map = structCfg(cat).sub;
  var s = map[main];
  return s && !Array.isArray(s) && typeof s === 'object';
}
function holdMidKeys(main, cat) {
  if (!holdSubIsNested(main, cat)) return [];
  return Object.keys(structCfg(cat).sub[main] || {});
}
function holdLeafSubs(main, mid, cat) {
  var s = structCfg(cat).sub[main];
  if (!s) return [];
  if (Array.isArray(s)) return s;
  if (mid && s[mid]) return s[mid];
  return [];
}
/** Flat list of selectable leaf labels for sorting / display */
function holdAllLeafLabels(main, cat) {
  var s = structCfg(cat).sub[main];
  if (!s) return [];
  if (Array.isArray(s)) return s.slice();
  var out = [];
  Object.keys(s).forEach(function (mid) {
    (s[mid] || []).forEach(function (leaf) {
      out.push(mid + ' › ' + leaf);
    });
  });
  return out;
}

/** Preferred structure rank for auto-sort (lower = earlier in report) */
function holdMainRank(main, cat) {
  var i = structCfg(cat).main.indexOf(main || '');
  return i >= 0 ? i : 999;
}
function holdSubRank(main, sub, cat) {
  if (!sub) return 998;
  var leaves = holdAllLeafLabels(main, cat);
  var i = leaves.indexOf(sub);
  if (i >= 0) return i;
  // fuzzy: ignore case / extra spaces
  var norm = String(sub).toLowerCase().replace(/\s+/g, ' ').trim();
  for (var k = 0; k < leaves.length; k++) {
    if (String(leaves[k]).toLowerCase().replace(/\s+/g, ' ').trim() === norm) return k;
  }
  var mids = holdMidKeys(main, cat);
  for (var j = 0; j < mids.length; j++) {
    if (String(mids[j]).toLowerCase() === norm) return j * 10;
  }
  // partial match (e.g. sub is only "Port Side")
  for (var n = 0; n < leaves.length; n++) {
    if (String(leaves[n]).toLowerCase().indexOf(norm) >= 0) return n;
  }
  return 999;
}

/**
 * If holdMain is empty, try to recover structure from description / nest label text.
 * Keeps auto-sort working even when only description was filled.
 */
function prgInferStructure(p) {
  if (!p || !isStructureCategory(p.category)) return;
  if (p.holdMain) return; // already set
  var cfg = structCfg(p.category);
  var text = String(p.desc || '').toLowerCase();
  if (!text) return;
  // Prefer longer main names first to avoid partial collisions
  var mains = cfg.main.slice().sort(function (a, b) { return b.length - a.length; });
  var foundMain = '';
  for (var i = 0; i < mains.length; i++) {
    if (text.indexOf(String(mains[i]).toLowerCase()) >= 0) {
      foundMain = mains[i];
      break;
    }
  }
  // "Stern Section" isn't its own dropdown option — it's the same place as
  // the existing "Aft Section", just a different word surveyors also use.
  if (!foundMain && p.category === 'Ship Side' && text.indexOf('stern') >= 0) {
    foundMain = 'Aft Section';
  }
  if (!foundMain) return;
  p.holdMain = foundMain;
  // Try match a leaf / sub label
  var leaves = holdAllLeafLabels(foundMain, p.category);
  for (var j = 0; j < leaves.length; j++) {
    var leaf = leaves[j];
    var parts = leaf.split(' › ');
    var ok = true;
    for (var pi = 0; pi < parts.length; pi++) {
      if (text.indexOf(String(parts[pi]).toLowerCase()) < 0) { ok = false; break; }
    }
    if (ok) {
      p.holdSub = leaf;
      return;
    }
  }
  // Simple 2-level sub from SUB_MAP array
  var subs = cfg.sub[foundMain];
  if (Array.isArray(subs)) {
    for (var s = 0; s < subs.length; s++) {
      if (text.indexOf(String(subs[s]).toLowerCase()) >= 0) {
        p.holdSub = subs[s];
        return;
      }
    }
  }
}

/** Keeps p.categoryCode / p.categoryName / p.holdNumber in sync with the
 *  photo's current (category, holdMain, holdSub) — the "SAVE CATEGORY
 *  CODE" step. Safe to call any time holdMain/holdSub may have changed
 *  (manual dropdown pick, direction toggle); also called unconditionally
 *  on every photo right before sorting, so the codes used for report
 *  order are always current regardless of which code path last touched
 *  the photo.
 *
 *  category_name is NEVER taken from the code table's own (internal-only)
 *  label — it is built fresh every time from this exact photo's own
 *  p.holdMain / p.holdSub, which are themselves always one of the existing
 *  dropdown's real values (HOLD/SHIP/DECK_MAIN_OPTIONS + their sub-maps).
 *  That guarantees category_name can never drift into a renamed,
 *  abbreviated, or invented value — only category_code (an internal sort
 *  key, not a category name) is table-driven. */
function prgUpdateCategoryCode(p) {
  if (!p) return;
  var row = prgLookupCategoryCode(p);
  p.categoryCode = row ? row.code : '';
  var hm = isHoldCategory(p.category) ? (String(p.category).match(/\d+/) || [])[0] : '';
  p.holdNumber = hm ? parseInt(hm, 10) : null;
  if (p.holdMain) {
    var namePath = p.holdMain + (p.holdSub ? (' - ' + p.holdSub) : '');
    p.categoryName = p.holdNumber ? ('Hold ' + p.holdNumber + ' - ' + namePath) : namePath;
  } else {
    p.categoryName = '';
  }
}

/**
 * Master walk-around order for "Auto-sort order" — a single sequence that
 * spans Ship Side, Deck Side and the Holds together (not grouped into
 * separate blocks), matching how a surveyor actually walks the vessel:
 *
 *  Forward Section → Bulbous Bow → Hull → Draft Marks → Rudder → Aft Section
 *  → Mooring Station (Forecastle) → Forward Mooring Winches → Anchor Chain
 *  → Windlass → Life Boat → Weather Deck
 *  → Main Deck (from Forward) → Forward Main Deck Railing → Forward Cross Deck
 *  → Hold No.1 … Hold No.9 (each: Hatch Entrance → Hatch Covers → Coamings
 *    Forward/Port/Starboard/Aft → Bulkheads Forward/Port/Starboard →
 *    Bulk Frames → Hoppers → Tank Top → Bilge Tanks Port/Starboard →
 *    Ladders Vertical/Australian/Inclination → Bulkheads Aft → Drain Channel)
 *  → Main Deck (from Aft) → Aft Main Deck Railing → Aft Cross Deck
 *  → Mooring Station (Poop) → Aft Mooring Winches
 *  → Others (unclassified, always last)
 */
var PRG_MASTER_ORDER = (function () {
  var rules = [];
  function r(cat, main, sub) { rules.push({ cat: cat, main: main, sub: sub || null }); }

  r('Ship Side', 'Forward Section');
  r('Ship Side', 'Bulbous Bow');
  r('Ship Side', 'Hull');
  r('Ship Side', 'Draft Marks');
  r('Ship Side', 'Rudder');
  r('Ship Side', 'Aft Section');

  r('Ship Side', 'Mooring Station', 'Forecastle Deck');
  r('Ship Side', 'Forward Mooring Winches', 'Port Side');
  r('Ship Side', 'Forward Mooring Winches', 'Starboard Side');
  r('Ship Side', 'Forward Mooring Winches'); // fallback: sub not chosen yet
  r('Ship Side', 'Anchor Chain');
  r('Ship Side', 'Windlass');
  r('Ship Side', 'Life Boat');
  r('Ship Side', 'Weather Deck', 'Port Side');
  r('Ship Side', 'Weather Deck', 'Starboard Side');
  r('Ship Side', 'Weather Deck'); // fallback: sub not chosen yet

  r('Deck Side', 'Main Deck', 'From Forward Side');
  r('Deck Side', 'Forward Main Deck Railing');
  r('Ship Side', 'Forward Cross Deck');

  for (var h = 1; h <= 9; h++) {
    var cat = 'Hold No. ' + h;
    r(cat, 'Hatch Entrance');
    r(cat, 'Hatch Covers');
    r(cat, 'Coamings', 'Forward');
    r(cat, 'Coamings', 'Port Side');
    r(cat, 'Coamings', 'Starboard Side');
    r(cat, 'Coamings', 'Aft');
    r(cat, 'Coamings'); // fallback: sub not chosen yet
    r(cat, 'Bulkheads', 'Forward');
    r(cat, 'Bulkheads', 'Port Side');
    r(cat, 'Bulkheads', 'Starboard Side');
    r(cat, 'Bulkheads'); // fallback: sub not chosen yet (placed with the above, not with "Aft" below)
    r(cat, 'Bulk Frames');
    r(cat, 'Hoppers');
    r(cat, 'Tank Top');
    r(cat, 'Bilge Tanks', 'Port Side');
    r(cat, 'Bilge Tanks', 'Starboard Side');
    r(cat, 'Bilge Tanks');
    r(cat, 'Ladders', 'Vertical');
    r(cat, 'Ladders', 'Australian');
    r(cat, 'Ladders', 'Inclination');
    r(cat, 'Ladders');
    r(cat, 'Bulkheads', 'Aft'); // deliberately after Tank Top / Bilge Tanks / Ladders
    r(cat, 'Drain Channel');
  }

  r('Deck Side', 'Main Deck', 'From Aft Side');
  r('Deck Side', 'Aft Main Deck Railing');
  r('Ship Side', 'Aft Cross Deck'); // symmetric with Forward Cross Deck, which likewise follows its Main Deck + Railing block
  r('Ship Side', 'Mooring Station', 'Poop Deck');
  r('Ship Side', 'Aft Mooring Winches', 'Port Side');
  r('Ship Side', 'Aft Mooring Winches', 'Starboard Side');
  r('Ship Side', 'Aft Mooring Winches'); // fallback: sub not chosen yet

  return rules;
})();

var PRG_MASTER_CAT_ORDER = ["Ship Side","Hold No. 1","Hold No. 2","Hold No. 3","Hold No. 4","Hold No. 5","Hold No. 6","Hold No. 7","Hold No. 8","Hold No. 9","Deck Side","Others"];

/* ==========================================================================
   🌟 MASTER CATEGORY CODE CONFIGURATION — single source of truth for the
   fixed report order (F01, FM02, H04-07, ...). This is the formal, explicit
   version of the walk-around order above: every report section gets a
   stable, human-readable code and an explicit numeric sort_order, and the
   final report is sorted by THAT number, never by upload order.

   Nothing produces this code directly — a photo's (category, holdMain,
   holdSub) is set by the manual structure-dropdown pick, and THIS table
   then maps that already-known combination onto one of the fixed codes
   below. "CODE sorts. REPORT renders."

   Numbering follows the requested scheme (distinct codes 10 apart; each
   hold a 20-wide block with its 15 codes at +1..+15) but holds are based
   at 1000+ instead of 500+ so that all 9 holds (up to 1175) fit cleanly
   before Main Deck From Aft, which the request's own abbreviated 3-hold
   example would otherwise collide with once extended to 9 holds. Relative
   order is identical to the request; only the illustrative numbers differ.
   ========================================================================== */
// Label fragments below are built ONLY from the exact HOLD_MAIN_OPTIONS /
// HOLD_SUB_MAP strings (main option, plus its exact sub value where one
// applies) — never a paraphrase, abbreviation, or renamed version of the
// real dropdown text. These labels are for internal code-table reference
// only; the per-photo category_name a user actually sees is always derived
// straight from that photo's own p.holdMain/p.holdSub (see
// prgUpdateCategoryCode), never from this table.
var PRG_HOLD_INTERNAL_CODES = [
  // [code suffix, label fragment, main option, sub-match substring or null]
  ['01', 'Hatch Entrance',                 'Hatch Entrance', null],
  ['02', 'Coamings - Forward',             'Coamings', 'Forward'],
  ['03', 'Coamings - Port Side',           'Coamings', 'Port Side'],
  ['04', 'Coamings - Starboard Side',      'Coamings', 'Starboard Side'],
  ['05', 'Coamings - Aft',                 'Coamings', 'Aft'],
  ['06', 'Bulkheads - Forward',            'Bulkheads', 'Forward'],
  ['07', 'Bulkheads - Port Side',          'Bulkheads', 'Port Side'],
  ['08', 'Bulkheads - Starboard Side',     'Bulkheads', 'Starboard Side'],
  ['09', 'Tank Top',                       'Tank Top', null],
  ['10', 'Bilge Tanks - Port Side',        'Bilge Tanks', 'Port Side'],
  ['11', 'Bilge Tanks - Starboard Side',   'Bilge Tanks', 'Starboard Side'],
  ['12', 'Ladders - Vertical',             'Ladders', 'Vertical'],
  ['13', 'Ladders - Australian',           'Ladders', 'Australian'],
  ['14', 'Bulkheads - Aft',                'Bulkheads', 'Aft'],
  ['15', 'Drain Channel',                  'Drain Channel', null]
];

// Extra hold-level dropdown values that exist in HOLD_MAIN_OPTIONS/HOLD_SUB_MAP
// but aren't named in the 15-step spec above. Not given their own numbered
// code letters (01-15 are fixed and must keep their exact mandated numbers),
// so each gets a distinct code suffix and a fractional sort offset placed
// at its correct historical position (per the pre-existing walk-around
// order: Hatch Entrance -> Hatch Covers -> Coamings..., and
// ...Bulkheads-Starboard -> Bulk Frames -> Hoppers -> Tank Top..., and
// ...Australian Ladder -> Ladders-Inclination -> Bulkheads-Aft...).
var PRG_HOLD_EXTRA_CODES = [
  // [code suffix, label fragment, main option, sub-match substring or null, offset]
  ['HC', 'Hatch Covers',     'Hatch Covers', null,          1.5],
  ['BF', 'Bulk Frames',      'Bulk Frames',  null,          8.3],
  ['HO', 'Hoppers',          'Hoppers',      null,          8.6],
  ['LI', 'Ladders - Inclination', 'Ladders', 'Inclination', 13.5]
];

var PRG_CATEGORY_CODE_TABLE = (function () {
  var rows = [];
  function add(code, name, sortOrder, cat, main, subContains) {
    rows.push({ code: code, name: name, sort_order: sortOrder, cat: cat, main: main, subContains: subContains || null });
  }

  add('F01', 'Forward Section', 100, 'Ship Side', 'Forward Section', null);
  add('F02', 'Bulbous Bow', 110, 'Ship Side', 'Bulbous Bow', null);
  add('F03', 'Hull', 120, 'Ship Side', 'Hull', null);
  add('F03D', 'Draft Marks', 125, 'Ship Side', 'Draft Marks', null); // existing dropdown value, not named in the spec; kept between Hull and Rudder per the proven walk-around order
  add('F04', 'Rudder', 130, 'Ship Side', 'Rudder', null);
  add('F05', 'Aft Section', 140, 'Ship Side', 'Aft Section', null);

  add('FM01', 'Mooring Station - Forecastle Deck', 200, 'Ship Side', 'Mooring Station', 'Forecastle Deck');
  add('FM02', 'Forward Mooring Winches - Port Side', 210, 'Ship Side', 'Forward Mooring Winches', 'Port Side');
  add('FM03', 'Forward Mooring Winches - Starboard Side', 220, 'Ship Side', 'Forward Mooring Winches', 'Starboard Side');
  add('FM04', 'Anchor Chain', 230, 'Ship Side', 'Anchor Chain', null);
  add('FMW1', 'Windlass', 231, 'Ship Side', 'Windlass', null); // existing dropdown value, not named in the spec; kept between Anchor Chain and Weather Deck per the proven walk-around order
  add('FML1', 'Life Boat', 232, 'Ship Side', 'Life Boat', null); // existing dropdown value, not named in the spec; same reasoning as Windlass above

  add('WD01', 'Weather Deck - Port Side', 300, 'Ship Side', 'Weather Deck', 'Port Side');
  add('WD02', 'Weather Deck - Starboard Side', 310, 'Ship Side', 'Weather Deck', 'Starboard Side');

  add('MD01', 'Main Deck - From Forward Side', 400, 'Deck Side', 'Main Deck', 'From Forward Side');
  add('MD02', 'Forward Main Deck Railing', 410, 'Deck Side', 'Forward Main Deck Railing', null);
  add('MD03', 'Forward Cross Deck', 420, 'Ship Side', 'Forward Cross Deck', null);

  for (var h = 1; h <= 9; h++) {
    var cat = 'Hold No. ' + h;
    var base = 1000 + (h - 1) * 20;
    PRG_HOLD_INTERNAL_CODES.forEach(function (row, idx) {
      add('H0' + h + '-' + row[0], 'Hold ' + h + ' - ' + row[1], base + idx + 1, cat, row[2], row[3]);
    });
    PRG_HOLD_EXTRA_CODES.forEach(function (row) {
      add('H0' + h + '-' + row[0], 'Hold ' + h + ' - ' + row[1], base + row[4], cat, row[2], row[3]);
    });
  }

  add('MA01', 'Main Deck - From Aft Side', 1200, 'Deck Side', 'Main Deck', 'From Aft Side');
  add('MA02', 'Aft Main Deck Railing', 1210, 'Deck Side', 'Aft Main Deck Railing', null);
  add('MA03', 'Aft Cross Deck', 1220, 'Ship Side', 'Aft Cross Deck', null); // not named in the spec, but symmetric with MD03 (Forward Cross Deck) — same existing dropdown value, must not be dropped

  add('AM01', 'Mooring Station - Poop Deck', 1300, 'Ship Side', 'Mooring Station', 'Poop Deck');
  add('AM02', 'Aft Mooring Winches - Port Side', 1310, 'Ship Side', 'Aft Mooring Winches', 'Port Side');
  add('AM03', 'Aft Mooring Winches - Starboard Side', 1320, 'Ship Side', 'Aft Mooring Winches', 'Starboard Side');

  return rows;
})();

// Mains that are genuinely ambiguous between two non-symmetric codes
// (forward vs. aft) when no side has been chosen yet — e.g. "Mooring
// Station" alone could mean Forecastle (FM01) or Poop (AM01). Unlike a
// Port/Starboard pair (safe to default to Port until confirmed), these
// must NOT silently guess; prgLookupCategoryCode returns no match instead,
// leaving the photo to the safety-net fallback (see prgReportSortOrder)
// until a side is actually chosen, exactly like the proven walk-around
// order above (which likewise has no bare fallback rule for either).
var PRG_CODE_NO_BLIND_FALLBACK_MAINS = ['Mooring Station', 'Main Deck'];

/** Looks up the single fixed category_code row for a photo's CURRENT
 *  (category, holdMain, holdSub) — never invents a code. Returns null
 *  when nothing in the table matches yet (not
 *  a structure category, holdMain not set, or an ambiguous forward/aft
 *  main with no side chosen yet). */
function prgLookupCategoryCode(p) {
  if (!p || !isStructureCategory(p.category) || !p.holdMain) return null;
  var matches = PRG_CATEGORY_CODE_TABLE.filter(function (row) {
    return row.cat === p.category && row.main === p.holdMain;
  });
  if (!matches.length) return null;
  var subLower = p.holdSub ? String(p.holdSub).toLowerCase() : '';
  for (var i = 0; i < matches.length; i++) {
    if (matches[i].subContains && subLower.indexOf(matches[i].subContains.toLowerCase()) >= 0) return matches[i];
  }
  if (matches.length > 1 && PRG_CODE_NO_BLIND_FALLBACK_MAINS.indexOf(p.holdMain) !== -1) return null;
  matches = matches.slice().sort(function (a, b) { return a.sort_order - b.sort_order; });
  return matches[0];
}

/**
 * Rank of one photo in PRG_MASTER_ORDER — most specific rule wins:
 *  1) exact category + main + a sub the photo's holdSub contains
 *  2) category + main only (no sub restriction on the rule)
 *  3) category only (keeps a reasonable block position for anything
 *     unmatched — e.g. a category/main this list doesn't know about)
 */
function masterRankOf(p) {
  var cat = p.category, main = p.holdMain, sub = p.holdSub ? String(p.holdSub).toLowerCase() : '';
  var i;
  for (i = 0; i < PRG_MASTER_ORDER.length; i++) {
    var ru = PRG_MASTER_ORDER[i];
    if (ru.cat === cat && ru.main === main && ru.sub && sub && sub.indexOf(String(ru.sub).toLowerCase()) >= 0) return i;
  }
  for (i = 0; i < PRG_MASTER_ORDER.length; i++) {
    var ru2 = PRG_MASTER_ORDER[i];
    if (ru2.cat === cat && ru2.main === main && !ru2.sub) return i;
  }
  var ci = PRG_MASTER_CAT_ORDER.indexOf(cat || '');
  return PRG_MASTER_ORDER.length + (ci >= 0 ? ci : 999) * 1000;
}

/** PRIMARY report ordering key: the fixed numeric sort_order from the
 *  category_code table (section 7/13 of the spec — "the only primary
 *  ordering field must be category_code"). Falls back to the already-
 *  proven walk-around rank (pushed well past every real code) only for
 *  the rare case prgLookupCategoryCode can't yet resolve — an ambiguous
 *  forward/aft main with no side chosen, or a category this table
 *  doesn't cover — so nothing is ever silently misplaced mid-report. */
function prgReportSortOrder(p) {
  var row = prgLookupCategoryCode(p);
  if (row) return row.sort_order;
  return 100000 + masterRankOf(p);
}

function sortPhotosForReport() {
  // Infer structure fields before ranking
  photos.forEach(function (p) { prgInferStructure(p); prgUpdateCategoryCode(p); });

  var decorated = photos.map(function (p, i) { return { p: p, i: i, rank: prgReportSortOrder(p) }; });
  decorated.sort(function (a, b) {
    if (a.rank !== b.rank) return a.rank - b.rank;
    return a.i - b.i;
  });
  photos = decorated.map(function (d) { return d.p; });
}

function buildHoldAutoDesc(main, sub, cat) {
  if (!main) return '';
  var cfg = structCfg(cat);
  var singular = (cfg.singular && cfg.singular[main]) || main;
  // Ship Side descriptions
  if (cfg.kind === 'ship') {
    if (!sub) return 'View of ' + singular;
    if (sub.indexOf(' › ') >= 0) {
      var parts = sub.split(' › ');
      var side = parts[0] || '';
      var section = parts[1] || '';
      if (main === 'Hull') return 'View of ' + side + ' Hull ' + section;
      if (main === 'Draft Marks') return 'View of ' + side + ' ' + section + ' Draft Marks';
      return 'View of ' + side + ' ' + section + ' ' + singular;
    }
    if (main === 'Mooring Station') return 'View of Mooring Station at ' + sub;
    return 'View of ' + sub + ' ' + singular;
  }
  // Deck Side descriptions
  if (cfg.kind === 'deck') {
    if (!sub) return 'View of ' + singular;
    if (sub.indexOf(' › ') >= 0) {
      var dp = sub.split(' › ');
      var dSide = dp[0] || '';
      var dFrom = dp[1] || '';
      // Main Deck: "View of Port Side Main Deck from Aft Side"
      return 'View of ' + dSide + ' ' + singular + ' ' + dFrom.toLowerCase();
    }
    // Main Deck Railing: "View of Port Side Main Deck Railing"
    return 'View of ' + sub + ' ' + singular;
  }
  // Hold descriptions (existing behaviour)
  if (main === 'Tank Top') return 'View of Tank Top';
  if (!sub) return 'View of ' + singular;
  if (sub.indexOf(' › ') >= 0) {
    var hp = sub.split(' › ');
    var mid = (hp[0] || '').toLowerCase();
    var leaf = hp[1] || '';
    return 'View of ' + leaf + ' ' + mid + ' ' + singular;
  }
  return 'View of ' + sub + ' ' + singular;
}

/**
 * Word report description line:
 *  - Holds: keep "Hold No. X – …" (unchanged)
 *  - Ship Side / Deck Side / Others / rest: fixed "View of …" prefix (no category name)
 */
function reportDescText(p) {
  var cat = (p && p.category) ? String(p.category) : '';
  var desc = (p && p.desc) ? String(p.desc).trim() : '';
  if (/^Hold No\.\s*\d+/i.test(cat)) {
    return cat + ' \u2013 ' + desc;
  }
  if (/^View of\s/i.test(desc)) return desc;
  if (!desc) return 'View of';
  return 'View of ' + desc;
}

var photos = [];
var templateAB = null;
var templateFileName = '';
var dragSrc = null;
var _prgDirty = false;
var _prgSaving = false;
var _prgDbPromise = null;
var _prgRestoring = false;
var _prgLastSavedAt = null;

(function () {
  var d = document.getElementById('surveyDate');
  if (d && !d.value) d.valueAsDate = new Date();
})();

/* ========== IndexedDB auto-save (every 10s + on leave) ========== */
function prgOpenDb() {
  if (_prgDbPromise) return _prgDbPromise;
  _prgDbPromise = new Promise(function (resolve, reject) {
    if (!window.indexedDB) {
      reject(new Error('IndexedDB not available'));
      return;
    }
    var req = indexedDB.open('ysms_photo_report_drafts', 1);
    req.onupgradeneeded = function (e) {
      var db = e.target.result;
      if (!db.objectStoreNames.contains('drafts')) {
        db.createObjectStore('drafts', { keyPath: 'key' });
      }
    };
    req.onsuccess = function (e) { resolve(e.target.result); };
    req.onerror = function (e) { reject(e.target.error || new Error('IDB open failed')); };
  });
  return _prgDbPromise;
}

function prgMarkDirty() {
  if (_prgRestoring) return;
  _prgDirty = true;
  var el = document.getElementById('prgAutoSaveStatus');
  if (el && !_prgSaving) {
    el.innerHTML = '<i class="fa-solid fa-pen me-1"></i> Unsaved changes — auto-saving soon…';
  }
}

function prgFormatTime(d) {
  if (!d) return '';
  var h = d.getHours(), m = d.getMinutes(), s = d.getSeconds();
  var ampm = h >= 12 ? 'PM' : 'AM';
  h = h % 12; if (h === 0) h = 12;
  return h + ':' + String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0') + ' ' + ampm;
}

function prgUpdateStatus(msg, icon) {
  var el = document.getElementById('prgAutoSaveStatus');
  if (!el) return;
  el.innerHTML = '<i class="fa-solid ' + (icon || 'fa-cloud') + ' me-1"></i> ' + msg;
}

function prgSerializePhotos() {
  return photos.map(function (p) {
    return {
      ab: p.ab,
      fileName: p.fileName || (p.file && p.file.name) || '',
      desc: p.desc || '',
      category: p.category || '',
      w: p.w || PHOTO_FIXED_PX_W,
      h: p.h || PHOTO_FIXED_PX_H,
      ext: p.ext || 'jpeg',
      holdMain: p.holdMain || '',
      holdSub: p.holdSub || '',
      descManual: !!p.descManual,
      categoryCode: p.categoryCode || '',
      categoryName: p.categoryName || '',
      holdNumber: (typeof p.holdNumber === 'number') ? p.holdNumber : null
    };
  });
}

async function prgSaveDraft(force) {
  if (_prgSaving) return;
  if (!_prgDirty && !force) return;
  if (_prgRestoring) return;
  _prgSaving = true;
  prgUpdateStatus('Saving draft…', 'fa-spinner fa-spin');
  try {
    var db = await prgOpenDb();
    var payload = {
      key: PRG_DRAFT_KEY,
      savedAt: Date.now(),
      surveyId: PRG_SURVEY_ID,
      vesselName: (document.getElementById('vesselName') || {}).value || '',
      surveyDate: (document.getElementById('surveyDate') || {}).value || '',
      reportNumber: (document.getElementById('reportNumber') || {}).value || '',
      category: (document.getElementById('categorySelect') || {}).value || '',
      catOpen: _prgCatOpen || {},
      photos: prgSerializePhotos(),
      templateAB: null,
      templateName: ''
    };
    await new Promise(function (resolve, reject) {
      var tx = db.transaction('drafts', 'readwrite');
      tx.objectStore('drafts').put(payload);
      tx.oncomplete = function () { resolve(); };
      tx.onerror = function () { reject(tx.error || new Error('save failed')); };
    });
    _prgDirty = false;
    _prgLastSavedAt = new Date();
    prgUpdateStatus('Draft saved at ' + prgFormatTime(_prgLastSavedAt) + ' · ' + photos.length + ' photo(s)', 'fa-cloud-check');
  } catch (err) {
    console.warn('Photo report auto-save failed:', err);
    prgUpdateStatus('Auto-save failed (storage full or blocked). Work is still in this tab only.', 'fa-triangle-exclamation');
  } finally {
    _prgSaving = false;
  }
}

async function prgLoadDraft() {
  try {
    var db = await prgOpenDb();
    var draft = await new Promise(function (resolve, reject) {
      var tx = db.transaction('drafts', 'readonly');
      var req = tx.objectStore('drafts').get(PRG_DRAFT_KEY);
      req.onsuccess = function () { resolve(req.result || null); };
      req.onerror = function () { reject(req.error); };
    });
    if (!draft || !Array.isArray(draft.photos) || draft.photos.length === 0) {
      // Still restore form fields if present without photos
      if (!draft) return false;
      if (!draft.vesselName && !draft.photos) return false;
    }
    _prgRestoring = true;
    if (draft.vesselName) {
      var vn = document.getElementById('vesselName');
      if (vn && !vn.value) vn.value = draft.vesselName;
      else if (vn && draft.vesselName) vn.value = draft.vesselName;
    }
    if (draft.surveyDate) {
      var sd = document.getElementById('surveyDate');
      if (sd) sd.value = draft.surveyDate;
    }
    if (draft.reportNumber) {
      var rn = document.getElementById('reportNumber');
      if (rn) rn.value = draft.reportNumber;
    }
    if (draft.category) {
      var cs = document.getElementById('categorySelect');
      if (cs) cs.value = draft.category;
    }
    if (draft.catOpen && typeof draft.catOpen === 'object') {
      _prgCatOpen = draft.catOpen;
    }
    // Template always comes from Admin upload — ignore draft template
    if (Array.isArray(draft.photos) && draft.photos.length) {
      photos.forEach(function (p) { if (p.url) try { URL.revokeObjectURL(p.url); } catch (e) {} });
      photos = [];
      for (var i = 0; i < draft.photos.length; i++) {
        var sp = draft.photos[i];
        var ab = sp.ab;
        if (!ab) continue;
        var blob = new Blob([ab], { type: (sp.ext === 'png' ? 'image/png' : 'image/jpeg') });
        photos.push({
          file: blob,
          fileName: sp.fileName || '',
          url: URL.createObjectURL(blob),
          ab: ab,
          desc: sp.desc || '',
          category: sp.category || 'Others',
          w: sp.w || PHOTO_FIXED_PX_W,
          h: sp.h || PHOTO_FIXED_PX_H,
          ext: sp.ext || 'jpeg',
          holdMain: sp.holdMain || '',
          holdSub: sp.holdSub || '',
          descManual: !!sp.descManual,
          categoryCode: sp.categoryCode || '',
          categoryName: sp.categoryName || '',
          holdNumber: (typeof sp.holdNumber === 'number') ? sp.holdNumber : null
        });
      }
      renderPhotos();
    }
    _prgLastSavedAt = draft.savedAt ? new Date(draft.savedAt) : null;
    _prgDirty = false;
    _prgRestoring = false;
    var when = _prgLastSavedAt ? prgFormatTime(_prgLastSavedAt) : '';
    prgUpdateStatus('Draft restored' + (when ? ' (saved ' + when + ')' : '') + ' · ' + photos.length + ' photo(s)', 'fa-rotate-left');
    if (photos.length) {
      toast('Previous work restored — ' + photos.length + ' photo(s)');
    }
    return true;
  } catch (err) {
    console.warn('Photo report draft restore failed:', err);
    _prgRestoring = false;
    return false;
  }
}

async function prgClearDraft() {
  try {
    var db = await prgOpenDb();
    await new Promise(function (resolve, reject) {
      var tx = db.transaction('drafts', 'readwrite');
      tx.objectStore('drafts').delete(PRG_DRAFT_KEY);
      tx.oncomplete = function () { resolve(); };
      tx.onerror = function () { reject(tx.error); };
    });
  } catch (e) { /* ignore */ }
  _prgDirty = false;
  prgUpdateStatus('Draft cleared — auto-save every 10s', 'fa-cloud');
}

// Mark dirty when form fields change
['vesselName', 'surveyDate', 'reportNumber', 'categorySelect'].forEach(function (fid) {
  var el = document.getElementById(fid);
  if (el) {
    el.addEventListener('input', prgMarkDirty);
    el.addEventListener('change', prgMarkDirty);
  }
});

setInterval(function () { prgSaveDraft(false); }, PRG_AUTOSAVE_MS);

document.addEventListener('visibilitychange', function () {
  if (document.visibilityState === 'hidden') prgSaveDraft(true);
});
window.addEventListener('pagehide', function () { prgSaveDraft(true); });
window.addEventListener('beforeunload', function () {
  if (_prgDirty) prgSaveDraft(true);
});

// Restore after DOM + constants (PHOTO_FIXED_*) are ready — deferred to end of script via prgInitDraft
function prgInitDraft() {
  prgLoadDraft();
}

function toast(msg, err) {
  var el = document.getElementById('toast');
  document.getElementById('toastBody').textContent = msg;
  el.classList.toggle('text-bg-danger', !!err);
  el.classList.toggle('text-bg-dark', !err);
  new bootstrap.Toast(el, {delay: 4000}).show();
}
function esc(s) {
  return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
function xmlEsc(s) {
  return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

/* Template — auto-load from Admin (ajax/photo_report_template.php) */
var templateReady = false;

function setGenerateEnabled(on) {
  var btnO = document.getElementById('generateBtnOriginal');
  var btnC = document.getElementById('generateBtnCompressed');
  if (btnO) btnO.disabled = !on;
  if (btnC) btnC.disabled = !on;
}

function showTemplateMissing() {
  templateAB = null;
  templateFileName = '';
  templateReady = false;
  var loadEl = document.getElementById('templateLoading');
  var okEl = document.getElementById('templateStatus');
  var missEl = document.getElementById('templateMissing');
  if (loadEl) loadEl.style.display = 'none';
  if (okEl) okEl.style.display = 'none';
  if (missEl) missEl.style.display = 'block';
  setGenerateEnabled(false);
}

function showTemplateOk(name, metaText) {
  templateReady = true;
  var loadEl = document.getElementById('templateLoading');
  var okEl = document.getElementById('templateStatus');
  var missEl = document.getElementById('templateMissing');
  var nameEl = document.getElementById('templateName');
  var metaEl = document.getElementById('templateMeta');
  if (loadEl) loadEl.style.display = 'none';
  if (missEl) missEl.style.display = 'none';
  if (okEl) okEl.style.display = 'block';
  if (nameEl) nameEl.textContent = name || 'Photo Report Template.docx';
  if (metaEl) metaEl.textContent = metaText || '';
  setGenerateEnabled(true);
}

async function loadAdminPhotoTemplate() {
  setGenerateEnabled(false);
  var loadEl = document.getElementById('templateLoading');
  if (loadEl) loadEl.style.display = 'block';
  try {
    var infoRes = await fetch('ajax/photo_report_template.php?action=info', { credentials: 'same-origin' });
    var info = await infoRes.json();
    if (!info || !info.ok) {
      showTemplateMissing();
      return;
    }
    var fileRes = await fetch('ajax/photo_report_template.php?action=file', { credentials: 'same-origin' });
    if (!fileRes.ok) {
      showTemplateMissing();
      return;
    }
    templateAB = await fileRes.arrayBuffer();
    if (!templateAB || templateAB.byteLength < 1000) {
      showTemplateMissing();
      return;
    }
    templateFileName = info.name || 'YMR_Photo_Report_Template.docx';
    var sizeKb = info.size ? (info.size / 1024).toFixed(1) + ' KB' : '';
    var when = info.updated_at ? ' · ' + info.updated_at : '';
    showTemplateOk(templateFileName, sizeKb + when);
  } catch (err) {
    console.error(err);
    showTemplateMissing();
  }
}

// Start loading template as soon as script runs
loadAdminPhotoTemplate();

/* Photos */
var pz = document.getElementById('photoZone');
pz.onclick = function () { document.getElementById('photoInput').click(); };
pz.ondragover = function (e) { e.preventDefault(); this.classList.add('dragover'); };
pz.ondragleave = function () { this.classList.remove('dragover'); };
pz.ondrop = function (e) {
  e.preventDefault();
  this.classList.remove('dragover');
  addPhotos(e.dataTransfer.files);
};
document.getElementById('photoInput').onchange = function (e) {
  addPhotos(e.target.files);
  e.target.value = '';
};

/**
 * Uniform photo box — every image (portrait / landscape / any size)
 * is center-cropped + scaled into the SAME fixed frame so 2 photos/page
 * look identical.
 *
 * Frame ≈ 6.05" × 4.35" — fills page with tight margins; exactly 2/page.
 * Upload uses HIGH quality (~240 dpi). Generate only re-compresses when
 * total images would push the DOCX over ~7 MB — so few photos = full quality.
 */
var PHOTO_FIXED_W_IN = 6.05;         // inches in Word
var PHOTO_FIXED_H_IN = 4.35;         // inches in Word
/** High quality for ~80–100 photos: sharp on screen/print */
var PHOTO_FIXED_PX_W = 1452;         // 6.05 * 240
var PHOTO_FIXED_PX_H = 1044;         // 4.35 * 240
var PHOTO_MAX_BYTES = 82 * 1024;     // ~82 KB soft target → ~100 photos ≈ 8 MB original
var PHOTO_QUALITY_START = 0.82;      // high quality at upload
var PHOTO_QUALITY_MIN = 0.62;        // upload floor — keep looking sharp
/** Compressed generate: images budget so DOCX lands ~5–7 MB */
var DOCX_IMAGE_BUDGET_BYTES = 6.2 * 1024 * 1024;
/** Which estimate tab is active: 'original' | 'compressed' */
var prgPreviewMode = 'original';

function canvasToBlob(canvas, quality) {
  return new Promise(function (resolve) {
    canvas.toBlob(function (b) { resolve(b); }, 'image/jpeg', quality);
  });
}

/** Draw image into fixed box with cover-crop (fills box, center crop, no letterbox). */
function drawCover(ctx, img, cw, ch) {
  var iw = img.naturalWidth || 1;
  var ih = img.naturalHeight || 1;
  var scale = Math.max(cw / iw, ch / ih);
  var sw = cw / scale;
  var sh = ch / scale;
  var sx = (iw - sw) / 2;
  var sy = (ih - sh) / 2;
  ctx.fillStyle = '#ffffff';
  ctx.fillRect(0, 0, cw, ch);
  ctx.drawImage(img, sx, sy, sw, sh, 0, 0, cw, ch);
}

function compressImage(file) {
  return new Promise(function (resolve) {
    var url = URL.createObjectURL(file);
    var img = new Image();
    img.onload = async function () {
      try {
        var cw = PHOTO_FIXED_PX_W;
        var ch = PHOTO_FIXED_PX_H;
        var quality = PHOTO_QUALITY_START;
        var best = null;

        for (var attempt = 0; attempt < 8; attempt++) {
          var canvas = document.createElement('canvas');
          canvas.width = cw;
          canvas.height = ch;
          var ctx = canvas.getContext('2d');
          drawCover(ctx, img, cw, ch);
          var blob = await canvasToBlob(canvas, quality);
          if (!blob) break;
          best = { blob: blob, w: cw, h: ch, quality: quality };
          if (blob.size <= PHOTO_MAX_BYTES) break;
          // Upload: prefer quality steps only — keep full resolution for best look
          if (quality > PHOTO_QUALITY_MIN + 0.02) {
            quality = Math.max(PHOTO_QUALITY_MIN, quality - 0.06);
          } else {
            // Soft fall-back only if still huge (rare)
            cw = Math.max(1000, Math.round(cw * 0.92));
            ch = Math.max(720, Math.round(ch * 0.92));
            quality = Math.max(PHOTO_QUALITY_MIN, quality - 0.02);
          }
        }

        URL.revokeObjectURL(url);
        if (!best || !best.blob) {
          var ab0 = await file.arrayBuffer();
          resolve({ ab: ab0, w: PHOTO_FIXED_PX_W, h: PHOTO_FIXED_PX_H, ext: 'jpeg', url: URL.createObjectURL(file), blob: file, origSize: file.size, newSize: file.size });
          return;
        }
        var ab = await best.blob.arrayBuffer();
        resolve({
          ab: ab,
          w: best.w,
          h: best.h,
          ext: 'jpeg',
          url: URL.createObjectURL(best.blob),
          blob: best.blob,
          origSize: file.size,
          newSize: best.blob.size
        });
      } catch (err) {
        URL.revokeObjectURL(url);
        var abErr = await file.arrayBuffer();
        resolve({ ab: abErr, w: PHOTO_FIXED_PX_W, h: PHOTO_FIXED_PX_H, ext: 'jpeg', url: URL.createObjectURL(file), blob: file, origSize: file.size, newSize: file.size });
      }
    };
    img.onerror = async function () {
      URL.revokeObjectURL(url);
      var ab = await file.arrayBuffer();
      resolve({ ab: ab, w: PHOTO_FIXED_PX_W, h: PHOTO_FIXED_PX_H, ext: 'jpeg', url: URL.createObjectURL(file), blob: file, origSize: file.size, newSize: file.size });
    };
    img.src = url;
  });
}

function photosTotalBytes() {
  var t = 0;
  for (var i = 0; i < photos.length; i++) t += (photos[i].ab && photos[i].ab.byteLength) || 0;
  return t;
}

/** Progress overlay helpers */
function prgShowProgress(title, sub) {
  var ov = document.getElementById('prgProgressOverlay');
  if (!ov) return;
  document.getElementById('prgProgTitle').textContent = title || 'Working…';
  document.getElementById('prgProgSub').textContent = sub || '';
  document.getElementById('prgProgBar').style.width = '0%';
  document.getElementById('prgProgPct').textContent = '0%';
  ov.classList.add('show');
}
function prgUpdateProgress(done, total, sub) {
  var pct = total > 0 ? Math.min(100, Math.round((done / total) * 100)) : 0;
  var bar = document.getElementById('prgProgBar');
  var pctEl = document.getElementById('prgProgPct');
  var subEl = document.getElementById('prgProgSub');
  if (bar) bar.style.width = pct + '%';
  if (pctEl) pctEl.textContent = pct + '%';
  if (sub && subEl) subEl.textContent = sub;
}
function prgHideProgress() {
  var ov = document.getElementById('prgProgressOverlay');
  if (ov) ov.classList.remove('show');
}

/** Template + XML overhead roughly constant for photo reports */
var PRG_TEMPLATE_OVERHEAD = 220 * 1024; // ~0.2 MB

function formatMb(bytes) {
  return (bytes / (1024 * 1024)).toFixed(1);
}

function setPrgPreviewMode(mode) {
  prgPreviewMode = (mode === 'compressed') ? 'compressed' : 'original';
  var tabO = document.getElementById('prgTabOriginal');
  var tabC = document.getElementById('prgTabCompressed');
  if (tabO && tabC) {
    if (prgPreviewMode === 'original') {
      tabO.className = 'btn btn-primary';
      tabC.className = 'btn btn-outline-primary';
    } else {
      tabO.className = 'btn btn-outline-primary';
      tabC.className = 'btn btn-primary';
    }
  }
  updateSizeEstimate();
}

/**
 * Live estimate — tab controls what is shown:
 *  Original  = current upload quality size (no extra compress)
 *  Compressed = size after fitting ~5–7 MB budget (+ quality note)
 */
function updateSizeEstimate() {
  var box = document.getElementById('prgSizeEstimate');
  if (!box) return;
  var n = photos.length;
  if (!n) {
    box.style.display = 'none';
    return;
  }
  box.style.display = 'block';

  var imgBytes = photosTotalBytes();
  var avgKb = Math.round(imgBytes / n / 1024);
  var originalDocx = imgBytes + PRG_TEMPLATE_OVERHEAD;
  var compressedImg = Math.min(imgBytes, DOCX_IMAGE_BUDGET_BYTES);
  var compressedDocx = compressedImg + PRG_TEMPLATE_OVERHEAD;
  var needsCompress = imgBytes > DOCX_IMAGE_BUDGET_BYTES;

  var isCompressedTab = (prgPreviewMode === 'compressed');
  var shown = isCompressedTab ? compressedDocx : originalDocx;
  var shownMb = parseFloat(formatMb(shown));

  var badge = document.getElementById('prgSizeBadge');
  var detail = document.getElementById('prgSizeDetail');
  var bar = document.getElementById('prgSizeBar');

  var cls = 'bg-success';
  if (shownMb > 12) cls = 'bg-danger';
  else if (shownMb > 7.5) cls = 'bg-warning text-dark';
  else cls = 'bg-success';
  badge.className = 'badge ' + cls;
  badge.textContent = formatMb(shown) + ' MB';

  var lines = [];
  lines.push(n + ' photo(s) · avg ~' + avgKb + ' KB/photo (upload quality)');

  if (isCompressedTab) {
    lines.push('<strong>Compressed</strong> Word ≈ <strong>' + formatMb(compressedDocx) + ' MB</strong> (target 5–7 MB)');
    if (needsCompress) {
      var perKb = Math.round((DOCX_IMAGE_BUDGET_BYTES / n) / 1024);
      lines.push('Quality: optimized for email · ~' + perKb + ' KB/photo after compress');
      lines.push('<span class="text-muted">Original would be ~' + formatMb(originalDocx) + ' MB</span>');
    } else {
      lines.push('Quality: same as original (already under budget)');
    }
  } else {
    lines.push('<strong>Original</strong> Word ≈ <strong>' + formatMb(originalDocx) + ' MB</strong>');
    lines.push('Quality: high (sharp for ~80–100 photos)');
    if (needsCompress) {
      lines.push('<span class="text-muted">Compressed option ≈ ' + formatMb(compressedDocx) + ' MB</span>');
    } else {
      lines.push('<span class="text-muted">Already within 5–7 MB range</span>');
    }
  }
  detail.innerHTML = lines.join('<br>');

  var pct = Math.min(150, Math.round((shown / (7 * 1024 * 1024)) * 100));
  bar.style.width = pct + '%';
  if (shownMb <= 7.5) bar.style.background = '#198754';
  else if (shownMb <= 12) bar.style.background = '#ffc107';
  else bar.style.background = '#dc3545';
}

/**
 * Quality-first re-compress: prefers lowering JPEG quality before resolution.
 * opts: { maxBytes, minQuality, maxW, maxH }
 * Used only when total size exceeds DOCX budget (many photos).
 */
function recompressPhotoAb(ab, opts) {
  opts = opts || {};
  return new Promise(function (resolve) {
    var blob0 = new Blob([ab], { type: 'image/jpeg' });
    var url = URL.createObjectURL(blob0);
    var img = new Image();
    img.onload = async function () {
      try {
        var maxW = opts.maxW || PHOTO_FIXED_PX_W;
        var maxH = opts.maxH || PHOTO_FIXED_PX_H;
        var cw = maxW;
        var ch = maxH;
        var quality = 0.82;
        var minQ = (typeof opts.minQuality === 'number') ? opts.minQuality : 0.50;
        var best = null;
        var target = Math.max(14 * 1024, opts.maxBytes || PHOTO_MAX_BYTES);

        for (var attempt = 0; attempt < 12; attempt++) {
          var canvas = document.createElement('canvas');
          canvas.width = cw;
          canvas.height = ch;
          var ctx = canvas.getContext('2d');
          drawCover(ctx, img, cw, ch);
          var blob = await canvasToBlob(canvas, quality);
          if (!blob) break;
          best = { blob: blob, w: cw, h: ch, quality: quality };
          if (blob.size <= target) break;
          // Prefer quality steps first; only shrink resolution after min quality
          if (quality > minQ + 0.03) {
            quality = Math.max(minQ, quality - 0.06);
          } else if (cw > 720) {
            cw = Math.max(720, Math.round(cw * 0.90));
            ch = Math.max(516, Math.round(ch * 0.90));
            quality = Math.max(minQ, quality - 0.02);
          } else {
            quality = Math.max(0.38, quality - 0.05);
            if (quality <= 0.38 && blob.size > target) {
              cw = Math.max(560, Math.round(cw * 0.88));
              ch = Math.max(400, Math.round(ch * 0.88));
            }
          }
        }
        URL.revokeObjectURL(url);
        if (!best || !best.blob) {
          resolve({ ab: ab, w: PHOTO_FIXED_PX_W, h: PHOTO_FIXED_PX_H, size: ab.byteLength });
          return;
        }
        var outAb = await best.blob.arrayBuffer();
        resolve({ ab: outAb, w: best.w, h: best.h, size: outAb.byteLength });
      } catch (err) {
        URL.revokeObjectURL(url);
        resolve({ ab: ab, w: PHOTO_FIXED_PX_W, h: PHOTO_FIXED_PX_H, size: ab.byteLength });
      }
    };
    img.onerror = function () {
      URL.revokeObjectURL(url);
      resolve({ ab: ab, w: PHOTO_FIXED_PX_W, h: PHOTO_FIXED_PX_H, size: ab.byteLength });
    };
    img.src = url;
  });
}

/**
 * Build image payloads for DOCX without mutating photos[] (so Original
 * stays high quality even after Compressed generate).
 * mode: 'original' | 'compressed'
 * Returns { items: [{ab,ext}], totalBytes }
 */
async function buildPhotoPayload(mode) {
  var n = photos.length;
  var items = [];
  var total = 0;

  if (mode !== 'compressed') {
    for (var i = 0; i < n; i++) {
      var p = photos[i];
      var ab = p.ab;
      items.push({ ab: ab, ext: (p.ext === 'png' ? 'png' : 'jpeg') });
      total += (ab && ab.byteLength) || 0;
    }
    return { items: items, totalBytes: total };
  }

  // Compressed path — only re-encode copies when over budget
  total = photosTotalBytes();
  if (total <= DOCX_IMAGE_BUDGET_BYTES) {
    for (var j = 0; j < n; j++) {
      var p0 = photos[j];
      items.push({ ab: p0.ab, ext: 'jpeg' });
    }
    return { items: items, totalBytes: total };
  }

  var perPhoto = Math.max(16 * 1024, Math.floor((DOCX_IMAGE_BUDGET_BYTES * 0.92) / n));
  var minQ, maxW, maxH;
  if (n <= 60) {
    minQ = 0.72; maxW = PHOTO_FIXED_PX_W; maxH = PHOTO_FIXED_PX_H;
  } else if (n <= 100) {
    minQ = 0.65; maxW = 1320; maxH = 948;   // 80–100: still decent
  } else if (n <= 150) {
    minQ = 0.52; maxW = 1089; maxH = 783;
  } else {
    minQ = 0.42; maxW = 960; maxH = 690;
  }

  total = 0;
  for (var k = 0; k < n; k++) {
    var pk = photos[k];
    if (!pk.ab) {
      items.push({ ab: new ArrayBuffer(0), ext: 'jpeg' });
      prgUpdateProgress(k + 1, n, 'Optimizing photos ' + (k + 1) + ' / ' + n);
      continue;
    }
    if (pk.ab.byteLength <= perPhoto) {
      items.push({ ab: pk.ab, ext: 'jpeg' });
      total += pk.ab.byteLength;
      prgUpdateProgress(k + 1, n, 'Optimizing photos ' + (k + 1) + ' / ' + n);
      continue;
    }
    var result = await recompressPhotoAb(pk.ab, {
      maxBytes: perPhoto,
      minQuality: minQ,
      maxW: maxW,
      maxH: maxH
    });
    items.push({ ab: result.ab, ext: 'jpeg' });
    total += result.ab.byteLength;
    prgUpdateProgress(k + 1, n, 'Optimizing photos ' + (k + 1) + ' / ' + n);
  }
  return { items: items, totalBytes: total };
}

async function addPhotos(list) {
  var files = Array.from(list).filter(function (f) { return f.type.indexOf('image/') === 0; });
  if (!files.length) { toast('No images', true); return; }
  var cat = document.getElementById('categorySelect').value;
  var defaultDesc = isStructureCategory(cat) ? '' : DESC_OPTIONS[0];
  var saved = 0;
  var totalFiles = files.length;
  var added = [];
  prgShowProgress('Uploading & compressing photos', '0 / ' + totalFiles + ' · please wait…');
  try {
    for (var i = 0; i < files.length; i++) {
      var file = files[i];
      var compressed = await compressImage(file);
      if (compressed.origSize && compressed.newSize && compressed.newSize < compressed.origSize) {
        saved += (compressed.origSize - compressed.newSize);
      }
      photos.push({
        file: compressed.blob || file,
        fileName: file.name || ('photo_' + (photos.length + 1) + '.jpg'),
        url: compressed.url,
        ab: compressed.ab,
        desc: defaultDesc,
        category: cat,
        w: compressed.w,
        h: compressed.h,
        ext: compressed.ext || 'jpeg',
        holdMain: '',
        holdSub: '',
        descManual: false,
        categoryCode: '',
        categoryName: '',
        holdNumber: isHoldCategory(cat) ? parseInt((String(cat).match(/\d+/) || [])[0], 10) : null
      });
      added.push(photos[photos.length - 1]);
      // Update every photo (or every few for very large batches stays responsive enough)
      prgUpdateProgress(i + 1, totalFiles, (i + 1) + ' / ' + totalFiles + ' compressed · ' + cat);
    }
  } finally {
    prgHideProgress();
  }
  // Expand the category just uploaded into; collapse others so list stays short
  Object.keys(_prgCatOpen).forEach(function (k) { _prgCatOpen[k] = false; });
  _prgCatOpen[cat] = true;
  renderPhotos();
  prgMarkDirty();
  var totalMb = (photosTotalBytes() / (1024 * 1024)).toFixed(1);
  var msg = files.length + ' photo(s) added to "' + cat + '" · total images ~' + totalMb + ' MB';
  if (saved > 0) msg += ' (saved ~' + Math.round(saved / 1024) + ' KB)';
  if (photosTotalBytes() > DOCX_IMAGE_BUDGET_BYTES) {
    msg += ' — use Generate Compressed for ~5–7 MB';
  } else {
    msg += ' — Original ≈ Compressed (under 7 MB)';
  }
  toast(msg);
  updateSizeEstimate();
  if (PRG_AI_AUTO && isStructureCategory(cat)) prgAiEnqueue(added, false);
}


/** In-place update of one photo's category pills + description field —
 *  avoids re-rendering the whole (potentially 100+ photo) list on every
 *  edit, which is what keeps bulk editing from freezing the page. */
function updatePhotoCardUI(idx) {
  var p = photos[idx];
  if (!p) return;
  if (isStructureCategory(p.category)) {
    var pillGroupEl = document.getElementById('holdPillGroup_' + idx);
    if (pillGroupEl) pillGroupEl.outerHTML = holdPillGroupHtml(p, idx);
    var descEl = document.getElementById('holdDesc_' + idx);
    if (descEl && document.activeElement !== descEl) descEl.value = p.desc || '';
    var classifyInfoEl = document.getElementById('classifyInfo_' + idx);
    if (classifyInfoEl) classifyInfoEl.outerHTML = structureInfoHtml(p, idx);
  } else {
    var othersInp = document.getElementById('othersDesc_' + idx);
    if (othersInp && document.activeElement !== othersInp) othersInp.value = p.desc || '';
  }
}

/** "Others" category: quick-pick dropdown + an editable text field underneath
 *  (same pattern as the Hold/Ship/Deck structure categories) — needed so a
 *  freely-typed sentence (which won't match any of the fixed DESC_OPTIONS)
 *  has somewhere to actually show up and be edited. */
function descDropdownHtml(selected, idx) {
  var opts = '<option value="">— Quick pick —</option>';
  for (var i = 0; i < DESC_OPTIONS.length; i++) {
    var sel = (DESC_OPTIONS[i] === selected) ? ' selected' : '';
    opts += '<option value="' + esc(DESC_OPTIONS[i]) + '"' + sel + '>' + esc(DESC_OPTIONS[i]) + '</option>';
  }
  return (
    '<select class="form-select form-select-sm" onchange="onOthersDescSelect(' + idx + ', this.value)">' + opts + '</select>' +
    '<input type="text" class="form-control form-control-sm mt-1" id="othersDesc_' + idx + '" value="' + esc(selected || '') + '" ' +
      'placeholder="Description" oninput="onOthersDescInput(' + idx + ', this.value)" aria-label="Description"/>'
  );
}
function onOthersDescSelect(idx, val) {
  var p = photos[idx];
  if (!p) return;
  if (val === '') return; // "— Quick pick —" placeholder, not a real choice
  p.desc = val;
  p.descManual = false;
  var inp = document.getElementById('othersDesc_' + idx);
  if (inp) inp.value = val;
  updatePhotoCardUI(idx);
  prgMarkDirty();
}
function onOthersDescInput(idx, val) {
  var p = photos[idx];
  if (!p) return;
  p.desc = val;
  p.descManual = true;
  updatePhotoCardUI(idx);
  prgMarkDirty();
}

/** Hold No.1–9: main + optional sub button group + editable Description text */

/** Compact "Main › Sub" caption — just the photo's own exact p.holdMain /
 *  p.holdSub, in the same order as the Category/Sub Category/Sub-Sub
 *  Category pills above (no section code or section-name clutter).
 *  Shown only once there's something to report; blank for a not-yet-
 *  picked photo rather than cluttering it with an empty line. */
function structureInfoHtml(p, idx) {
  var bits = [];
  if (p.holdMain) {
    bits.push(p.holdMain + (p.holdSub ? (' › ' + p.holdSub) : ''));
  }
  var ai = prgAiTagHtml(p);
  // Always render the element (even empty) so updatePhotoCardUI can refresh it
  // in place by id; CSS hides it until there's something to show.
  return '<div class="prg-classify-info' + (bits.length || ai ? '' : ' empty') + '" id="classifyInfo_' + idx + '" title="Report section this photo will sort into — use the dropdown above to correct it">' +
    (bits.length ? ('<i class="fa-solid fa-diagram-project"></i> ' + esc(bits.join(' · '))) : '') + ai +
  '</div>';
}

/** Horizontal multi-level cascading button-group version of the structure
 *  picker (replaces the old vertical accordion/nested-pill layout) — same
 *  exact existing dropdown values (structCfg), same commit semantics as
 *  before (a pick only takes effect when a leaf-level button is clicked;
 *  clicking a main/mid that has children just reveals its row below,
 *  same as the old accordion toggle did), just laid out as Level 1/2/3
 *  sibling rows so only one row per level is ever visible at a time. */
function holdPillGroupHtml(p, idx) {
  var cat = p.category || '';
  var cfg = structCfg(cat);
  var mainList = cfg.main;
  var level1 = '';
  var panels = '';
  for (var i = 0; i < mainList.length; i++) {
    var main = mainList[i];
    var mainActive = (p.holdMain === main);

    if (holdSubIsNested(main, cat)) {
      // 3-level: e.g. Hull → Port Side → At Bow Section / Hoppers → Lower → Port
      level1 +=
        '<button type="button" class="prg-cat-btn' + (mainActive ? ' active' : '') + '" data-main="' + esc(main) + '" ' +
        'onclick="onCatMainPick(' + idx + ', this)">' + esc(main) + '</button>';

      var mids = holdMidKeys(main, cat);
      var midHtml = '';
      for (var m = 0; m < mids.length; m++) {
        var mid = mids[m];
        var midActive = mainActive && p.holdSub && p.holdSub.indexOf(mid + ' ›') === 0;
        midHtml +=
          '<button type="button" class="prg-cat-btn' + (midActive ? ' active' : '') + '" data-main="' + esc(main) + '" data-mid="' + esc(mid) + '" ' +
          'onclick="onCatMidPick(' + idx + ', this)">' + esc(mid) + '</button>';
      }
      panels +=
        '<div class="prg-cat-level prg-cat-panel' + (mainActive ? ' show' : '') + '" data-main="' + esc(main) + '">' +
          '<span class="prg-cat-label">Sub Category:</span>' +
          '<div class="prg-cat-scroll">' + midHtml + '</div>' +
        '</div>';

      for (var m2 = 0; m2 < mids.length; m2++) {
        var mid2 = mids[m2];
        var midActive2 = mainActive && p.holdSub && p.holdSub.indexOf(mid2 + ' ›') === 0;
        var leaves = holdLeafSubs(main, mid2, cat);
        var leafHtml = '';
        for (var L = 0; L < leaves.length; L++) {
          var leaf = leaves[L];
          var full = mid2 + ' › ' + leaf;
          var lAct = (mainActive && p.holdSub === full) ? ' active' : '';
          leafHtml +=
            '<button type="button" class="prg-cat-btn' + lAct + '" data-main="' + esc(main) + '" data-sub="' + esc(full) + '" ' +
            'onclick="onHoldNestPick(' + idx + ', this)">' + esc(leaf) + '</button>';
        }
        panels +=
          '<div class="prg-cat-level prg-cat-panel' + (midActive2 ? ' show' : '') + '" data-main="' + esc(main) + '" data-mid="' + esc(mid2) + '">' +
            '<span class="prg-cat-label">Sub-Sub Category:</span>' +
            '<div class="prg-cat-scroll">' + leafHtml + '</div>' +
          '</div>';
      }
    } else {
      var subs = cfg.sub[main] || [];
      if (!subs || subs.length === 0) {
        level1 +=
          '<button type="button" class="prg-cat-btn' + (mainActive ? ' active' : '') + '" data-main="' + esc(main) + '" data-sub="" ' +
          'onclick="onHoldNestPick(' + idx + ', this)">' + esc(main) + '</button>';
      } else {
        level1 +=
          '<button type="button" class="prg-cat-btn' + (mainActive ? ' active' : '') + '" data-main="' + esc(main) + '" ' +
          'onclick="onCatMainPick(' + idx + ', this)">' + esc(main) + '</button>';

        var subHtml = '';
        for (var j = 0; j < subs.length; j++) {
          var sub = subs[j];
          var sAct = (mainActive && p.holdSub === sub) ? ' active' : '';
          subHtml +=
            '<button type="button" class="prg-cat-btn' + sAct + '" data-main="' + esc(main) + '" data-sub="' + esc(sub) + '" ' +
            'onclick="onHoldNestPick(' + idx + ', this)">' + esc(sub) + '</button>';
        }
        panels +=
          '<div class="prg-cat-level prg-cat-panel' + (mainActive ? ' show' : '') + '" data-main="' + esc(main) + '">' +
            '<span class="prg-cat-label">Sub Category:</span>' +
            '<div class="prg-cat-scroll">' + subHtml + '</div>' +
          '</div>';
      }
    }
  }
  return (
    '<div class="prg-cat-picker" id="holdPillGroup_' + idx + '">' +
      '<div class="prg-cat-level"><span class="prg-cat-label">Category:</span><div class="prg-cat-scroll">' + level1 + '</div></div>' +
      panels +
    '</div>'
  );
}

function holdDescControlsHtml(p, idx) {
  return (
    holdPillGroupHtml(p, idx) +
    '<input type="text" class="form-control form-control-sm mt-1" id="holdDesc_' + idx + '" value="' + esc(p.desc || '') + '" ' +
      'placeholder="Description (auto or type)" oninput="onHoldDescInput(' + idx + ', this.value)" aria-label="Description"/>' +
    structureInfoHtml(p, idx)
  );
}

/** Shows the Level 2 (sub category) row for the clicked Level 1 main
 *  button — pure display nav, never touches the photo's actual
 *  holdMain/holdSub (the pick only commits when a leaf-level button is
 *  clicked, via onHoldNestPick). Switching main also hides any Level 3
 *  (sub-sub category) row until a new sub category is picked. */
function onCatMainPick(idx, btn) {
  var group = btn.closest('.prg-cat-picker');
  if (!group) return;
  var main = btn.getAttribute('data-main') || '';
  var level1Row = group.querySelector(':scope > .prg-cat-level:not(.prg-cat-panel)');
  if (level1Row) {
    level1Row.querySelectorAll('.prg-cat-btn').forEach(function (b) {
      b.classList.toggle('active', b === btn);
    });
  }
  group.querySelectorAll(':scope > .prg-cat-panel').forEach(function (panel) {
    var show = panel.getAttribute('data-main') === main && !panel.hasAttribute('data-mid');
    panel.classList.toggle('show', show);
  });
}

/** Same idea as onCatMainPick, one level down — shows the Level 3
 *  (sub-sub category) row for the clicked Level 2 sub category button. */
function onCatMidPick(idx, btn) {
  var subPanel = btn.closest('.prg-cat-panel');
  var group = btn.closest('.prg-cat-picker');
  if (!subPanel || !group) return;
  var main = btn.getAttribute('data-main') || '';
  var mid = btn.getAttribute('data-mid') || '';
  subPanel.querySelectorAll('.prg-cat-btn').forEach(function (b) {
    b.classList.toggle('active', b === btn);
  });
  group.querySelectorAll(':scope > .prg-cat-panel[data-mid]').forEach(function (panel) {
    var show = panel.getAttribute('data-main') === main && panel.getAttribute('data-mid') === mid;
    panel.classList.toggle('show', show);
  });
}

function onHoldNestPick(idx, btn) {
  var main = btn.getAttribute('data-main') || '';
  var sub = btn.getAttribute('data-sub') || '';
  var p = photos[idx];
  if (!p) return;
  p.holdMain = main;
  p.holdSub = sub;
  p.aiState = ''; // a manual pick replaces the AI's
  // Every pill click (Category/Sub Category/Sub-Sub Category) regenerates
  // the Description to match the new selection — even correcting an
  // earlier pick refreshes it, instead of leaving stale text from the
  // previous selection. The field stays freely editable afterward; typing
  // directly into it (onHoldDescInput) is what actually "locks" it until
  // the next pill click.
  p.desc = buildHoldAutoDesc(main, sub, p.category);
  p.descManual = false;
  prgUpdateCategoryCode(p); // category_code/sort_order must reflect the corrected value
  updatePhotoCardUI(idx);
  prgMarkDirty();
}

/* ===== AI auto-select: Category / Sub Category / Sub-Sub Category from the photo =====
   Uses ajax/gemini_describe.php in classification mode: the photo plus this section's
   own pill values as one numbered list ("Hull › Port Side › At Bow Section", …); the
   server returns the list number it picked, so only an existing option can ever be set.
   Upload runs it on photos with no pick yet; the AI button re-runs it on one photo. */
function prgAiOptions(cat) {
  var cfg = structCfg(cat), out = [];
  cfg.main.forEach(function (main) {
    var s = cfg.sub[main];
    if (!s || (Array.isArray(s) && !s.length)) {
      out.push({ label: main, main: main, sub: '' });
    } else if (Array.isArray(s)) {
      s.forEach(function (sub) { out.push({ label: main + ' › ' + sub, main: main, sub: sub }); });
    } else {
      Object.keys(s).forEach(function (mid) {
        (s[mid] || []).forEach(function (leaf) {
          out.push({ label: main + ' › ' + mid + ' › ' + leaf, main: main, sub: mid + ' › ' + leaf });
        });
      });
    }
  });
  return out;
}

/** Photo as base64 JPEG, longest side 1024 px — plenty for recognising the structure. */
function prgAiImageB64(p) {
  return new Promise(function (resolve, reject) {
    var img = new Image();
    img.onload = function () {
      var k = Math.min(1, 1024 / Math.max(img.naturalWidth, img.naturalHeight));
      var c = document.createElement('canvas');
      c.width = Math.max(1, Math.round(img.naturalWidth * k));
      c.height = Math.max(1, Math.round(img.naturalHeight * k));
      c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
      resolve(c.toDataURL('image/jpeg', 0.85).split(',')[1]);
    };
    img.onerror = function () { reject(new Error('Photo could not be read')); };
    img.src = p.url;
  });
}

function prgAiTagHtml(p) {
  if (!PRG_AI_ENABLED || !p.aiState) return '';
  if (p.aiState === 'queued' || p.aiState === 'running') {
    return '<span class="prg-ai-tag run"><i class="fa-solid fa-spinner fa-spin"></i> AI analysing…</span>';
  }
  if (p.aiState === 'done') {
    var low = p.aiConfidence !== null && p.aiConfidence < 60;
    return '<span class="prg-ai-tag ' + (low ? 'low' : 'ok') + '" title="' + esc('Selected by AI' + (p.aiEvidence ? ': ' + p.aiEvidence : '') + (low ? ' — please check' : '')) + '">' +
      '<i class="fa-solid fa-wand-magic-sparkles"></i> AI' + (p.aiConfidence !== null ? ' ' + p.aiConfidence + '%' : '') + (low ? ' · check' : '') + '</span>';
  }
  if (p.aiState === 'error') {
    return '<span class="prg-ai-tag err" title="' + esc(p.aiError || '') + '"><i class="fa-solid fa-triangle-exclamation"></i> AI failed — click AI to retry</span>';
  }
  return '';
}

function prgAiRefresh(p) {
  var idx = photos.indexOf(p);
  if (idx >= 0) updatePhotoCardUI(idx);
}

var _prgAiQueue = [];
var _prgAiActive = 0;

/** force = false: leave photos the surveyor already picked untouched. */
function prgAiEnqueue(list, force) {
  if (!PRG_AI_ENABLED) return;
  list.forEach(function (p) {
    if (!p || !isStructureCategory(p.category)) return;
    if (p.aiState === 'queued' || p.aiState === 'running') return;
    if (!force && p.holdMain) return;
    p.aiState = 'queued';
    p.aiForce = !!force;
    _prgAiQueue.push(p);
    prgAiRefresh(p);
  });
  prgAiPump();
}

function prgAiPump() {
  while (_prgAiActive < PRG_AI_CONCURRENCY && _prgAiQueue.length) {
    var p = _prgAiQueue.shift();
    if (photos.indexOf(p) < 0) continue; // deleted while waiting
    _prgAiActive++;
    prgAiClassify(p).then(function () {
      _prgAiActive--;
      prgAiPump();
    });
  }
}

async function prgAiClassify(p) {
  var opts = prgAiOptions(p.category);
  p.aiState = 'running';
  prgAiRefresh(p);
  try {
    var b64 = await prgAiImageB64(p);
    var body = JSON.stringify({
      image: b64, mime: 'image/jpeg', survey_id: PRG_SURVEY_ID,
      context: { category: p.category, vessel_name: PRG_VESSEL_NAME || '' },
      options: opts.map(function (o) { return o.label; })
    });
    for (var attempt = 0; attempt < 3; attempt++) {
      var res = await fetch('ajax/gemini_describe.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': <?= json_encode(csrf_token()) ?> },
        body: body
      });
      var data = await res.json().catch(function () { return { success: false, message: 'Unexpected server response (HTTP ' + res.status + ')' }; });
      if (data.success) {
        var pick = opts[(data.index | 0) - 1];
        if (!pick) throw new Error('AI returned an unknown option');
        if (!p.aiForce && p.holdMain) { p.aiState = ''; prgAiRefresh(p); return; } // picked by hand meanwhile
        p.holdMain = pick.main;
        p.holdSub = pick.sub;
        if (!p.descManual) p.desc = buildHoldAutoDesc(pick.main, pick.sub, p.category);
        p.aiState = 'done';
        p.aiConfidence = (typeof data.confidence === 'number') ? data.confidence : null;
        p.aiEvidence = data.evidence || '';
        prgUpdateCategoryCode(p);
        prgAiRefresh(p);
        prgMarkDirty();
        return;
      }
      if (res.status === 429 || res.status >= 500) {
        await new Promise(function (r) { setTimeout(r, Math.min(30, data.retry_after || (attempt + 1) * 4) * 1000); });
        continue;
      }
      throw new Error(data.message || ('AI request failed (HTTP ' + res.status + ')'));
    }
    throw new Error('AI is busy — please try again in a minute');
  } catch (e) {
    p.aiState = 'error';
    p.aiError = e && e.message ? e.message : 'AI failed';
    prgAiRefresh(p);
  }
}

function prgAiOne(idx) {
  prgAiEnqueue([photos[idx]], true);
}

function prgAiSelectUnpicked() {
  var list = photos.filter(function (p) { return isStructureCategory(p.category) && !p.holdMain; });
  if (!list.length) { toast('Every Ship / Hold / Deck photo already has a category — use the AI button on a photo to redo it'); return; }
  prgAiEnqueue(list, false);
  toast('AI is selecting categories for ' + list.length + ' photo(s)…');
}




function closeAllHoldMenus(exceptIdx) {
  document.querySelectorAll(".hold-dd-menu").forEach(function (el) {
    var id = el.id || "";
    var ix = id.replace("holdMenu_", "");
    if (exceptIdx !== undefined && String(ix) === String(exceptIdx)) return;
    el.style.display = "none";
    var wrap = el.closest(".hold-dd-wrap");
    if (wrap) {
      var btn = wrap.querySelector(".hold-dd-toggle");
      if (btn) btn.setAttribute("aria-expanded", "false");
    }
  });
}

function toggleHoldMenu(idx, e) {
  if (e) { e.preventDefault(); e.stopPropagation(); }
  var menu = document.getElementById("holdMenu_" + idx);
  if (!menu) return;
  var open = menu.style.display === "block";
  closeAllHoldMenus();
  if (!open) {
    menu.style.display = "block";
    // Clicks inside menu must not bubble to document (which closes menus)
    if (!menu._prgStopProp) {
      menu._prgStopProp = true;
      menu.addEventListener("click", function (ev) { ev.stopPropagation(); });
      menu.addEventListener("mousedown", function (ev) { ev.stopPropagation(); });
    }
    var wrap = document.getElementById("holdDd_" + idx);
    if (wrap) {
      var btn = wrap.querySelector(".hold-dd-toggle");
      if (btn) btn.setAttribute("aria-expanded", "true");
    }
    var searchEl = document.getElementById("holdSearch_" + idx);
    if (searchEl) searchEl.value = "";
    buildHoldMenuBody(idx);
    if (searchEl) setTimeout(function () { searchEl.focus(); }, 30);
  }
}

function toggleHoldSubmenu(e, btn) {
  if (e) { e.preventDefault(); e.stopPropagation(); }
  var group = btn.closest(".hold-menu-group");
  if (!group) return;
  var menu = group.closest(".hold-dd-menu");
  if (menu) {
    menu.querySelectorAll(".hold-menu-group").forEach(function (g) {
      if (g !== group) g.classList.remove("open");
    });
  }
  group.classList.toggle("open");
}

function onHoldMenuPick(idx, btn) {
  var main = btn.getAttribute("data-main") || "";
  var sub = btn.getAttribute("data-sub") || "";
  var p = photos[idx];
  if (!p) return;
  p.holdMain = main;
  p.holdSub = sub;
  if (!p.descManual) {
    p.desc = buildHoldAutoDesc(main, sub, p.category);
  }
  closeAllHoldMenus();
  var wrap = document.getElementById("holdDd_" + idx);
  if (wrap) {
    var labelEl = wrap.querySelector(".hold-dd-label");
    if (labelEl) {
      if (main === "Tank Top") labelEl.textContent = "Tank Top";
      else if (main && sub) labelEl.textContent = main + " › " + sub;
      else labelEl.textContent = main || "— Select structure —";
    }
  }
  var descEl = document.getElementById("holdDesc_" + idx);
  if (descEl && !p.descManual) descEl.value = p.desc || "";
  p.descManual = true; // an explicit structure pick is a manual correction
  updatePhotoCardUI(idx);
  prgMarkDirty();
}

function onHoldDescInput(idx, val) {
  var p = photos[idx];
  if (!p) return;
  p.desc = val;
  p.descManual = true;
  if (String(val).trim() === "") {
    p.descManual = false;
    if (p.holdMain) {
      p.desc = buildHoldAutoDesc(p.holdMain, p.holdSub, p.category);
      var descEl = document.getElementById("holdDesc_" + idx);
      if (descEl) descEl.value = p.desc || "";
    }
  }
  updatePhotoCardUI(idx);
  prgMarkDirty();
}

function descControlsHtml(p, idx) {
  if (isStructureCategory(p.category)) {
    return holdDescControlsHtml(p, idx);
  }
  return descDropdownHtml(p.desc, idx);
}

document.addEventListener("click", function (e) {
  if (!e.target.closest(".hold-dd-wrap")) closeAllHoldMenus();
});

/* Large hover preview so description can be written while viewing the photo */
var _zoomEl = null;
function ensureZoomPreview() {
  if (_zoomEl) return _zoomEl;
  _zoomEl = document.createElement('div');
  _zoomEl.className = 'prg-zoom-preview';
  _zoomEl.innerHTML = '<img alt="preview"/>';
  document.body.appendChild(_zoomEl);
  return _zoomEl;
}
function showZoomPreview(imgEl, e) {
  var z = ensureZoomPreview();
  var zImg = z.querySelector('img');
  zImg.src = imgEl.src;
  z.classList.add('show');
  positionZoomPreview(e);
}
function positionZoomPreview(e) {
  if (!_zoomEl) return;
  var pad = 16;
  var w = _zoomEl.offsetWidth || 360;
  var h = _zoomEl.offsetHeight || 260;
  var x = e.clientX + 18;
  var y = e.clientY + 18;
  if (x + w + pad > window.innerWidth) x = e.clientX - w - 12;
  if (y + h + pad > window.innerHeight) y = e.clientY - h - 12;
  if (x < pad) x = pad;
  if (y < pad) y = pad;
  _zoomEl.style.left = x + 'px';
  _zoomEl.style.top = y + 'px';
}
function hideZoomPreview() {
  if (_zoomEl) _zoomEl.classList.remove('show');
}

/* Which category panels are expanded (survives re-render) */
var _prgCatOpen = {};

function renderPhotos() {
  var list = document.getElementById('photoList');
  list.innerHTML = '';
  document.getElementById('photoCount').textContent = photos.length;
  document.getElementById('photoControls').style.display = photos.length ? 'block' : 'none';
  updateSizeEstimate();

  var byCat = {};
  photos.forEach(function (p, i) {
    if (!byCat[p.category]) byCat[p.category] = [];
    byCat[p.category].push({p: p, i: i});
  });
  // Safety: ensure items inside each structure category follow holdMain/sub rank
  Object.keys(byCat).forEach(function (cat) {
    if (!isStructureCategory(cat)) return;
    byCat[cat].sort(function (a, b) {
      var ma = holdMainRank(a.p.holdMain, cat), mb = holdMainRank(b.p.holdMain, cat);
      if (ma !== mb) return ma - mb;
      var sa = holdSubRank(a.p.holdMain, a.p.holdSub, cat);
      var sb = holdSubRank(b.p.holdMain, b.p.holdSub, cat);
      if (sa !== sb) return sa - sb;
      return a.i - b.i;
    });
  });

  var catOrder = ["Ship Side","Hold No. 1","Hold No. 2","Hold No. 3","Hold No. 4","Hold No. 5","Hold No. 6","Hold No. 7","Hold No. 8","Hold No. 9","Deck Side","Others"];
  var selectedCat = (document.getElementById('categorySelect') || {}).value || '';
  catOrder.forEach(function (cat) {
    if (!byCat[cat] || !byCat[cat].length) return;
    // Default: open selected category (or first cat if none remembered); others collapsed
    if (_prgCatOpen[cat] === undefined) {
      _prgCatOpen[cat] = (cat === selectedCat);
    }
    var isOpen = !!_prgCatOpen[cat];
    var sec = document.createElement('div');
    sec.className = 'prg-cat-section' + (isOpen ? ' open' : '');
    sec.dataset.cat = cat;
    var head = document.createElement('button');
    head.type = 'button';
    head.className = 'prg-cat-head';
    head.innerHTML =
      '<i class="fa-regular fa-folder-open"></i>' +
      '<span>' + esc(cat) + '</span>' +
      '<span class="badge bg-secondary">' + byCat[cat].length + '</span>' +
      '<i class="fa-solid fa-chevron-down prg-cat-chevron"></i>';
    head.addEventListener('click', function () {
      _prgCatOpen[cat] = !sec.classList.contains('open');
      sec.classList.toggle('open');
    });
    var body = document.createElement('div');
    body.className = 'prg-cat-body';
    sec.appendChild(head);
    sec.appendChild(body);
    byCat[cat].forEach(function (item) {
      var p = item.p, i = item.i;
      var div = document.createElement('div');
      div.className = 'prg-photo-item';
      div.draggable = true;
      div.dataset.i = i;
      div.innerHTML =
        '<div class="d-flex gap-2 align-items-start">' +
          '<div class="position-relative flex-shrink-0">' +
            '<img src="' + p.url + '" class="prg-photo-thumb"/>' +
            '<span class="position-absolute top-0 start-0 translate-middle badge rounded-pill bg-primary" style="font-size:.65rem">' + (i + 1) + '</span>' +
          '</div>' +
          '<div class="flex-grow-1">' +
            '<label class="form-label small mb-0">Description</label>' +
            descControlsHtml(p, i) +
            '<div class="mt-1 d-flex gap-1 flex-wrap align-items-center">' +
              '<button type="button" class="btn btn-sm btn-outline-secondary" onclick="moveP(' + i + ',-1)"' + (i === 0 ? ' disabled' : '') + ' title="Move up"><i class="fa-solid fa-arrow-up"></i></button>' +
              '<button type="button" class="btn btn-sm btn-outline-secondary" onclick="moveP(' + i + ',1)"' + (i === photos.length - 1 ? ' disabled' : '') + ' title="Move down"><i class="fa-solid fa-arrow-down"></i></button>' +
              '<button type="button" class="btn btn-sm btn-outline-danger" onclick="delP(' + i + ')" title="Delete"><i class="fa-solid fa-trash"></i></button>' +
              (PRG_AI_ENABLED && isStructureCategory(p.category) ? '<button type="button" class="btn btn-sm prg-ai-btn" onclick="prgAiOne(' + i + ')" data-testid="prg-ai-one" title="AI: select Category / Sub Category / Sub-Sub Category from this photo"><i class="fa-solid fa-wand-magic-sparkles"></i> AI</button>' : '') +
              '<span class="badge prg-cat-badge bg-light text-dark border">' + esc(p.category) + '</span>' +
              (function () { var fn = p.fileName || (p.file && p.file.name) || ''; return fn ? '<span class="text-muted small ms-auto text-truncate" style="max-width:42%">' + esc(fn) + '</span>' : ''; })() +
            '</div>' +
          '</div>' +
        '</div>';
      div.addEventListener('dragstart', function () { dragSrc = i; div.classList.add('dragging'); });
      div.addEventListener('dragend', function () { div.classList.remove('dragging'); });
      div.addEventListener('dragover', function (e) { e.preventDefault(); });
      div.addEventListener('drop', function (e) {
        e.preventDefault();
        var t = +div.dataset.i;
        if (dragSrc !== null && dragSrc !== t) {
          var m = photos.splice(dragSrc, 1)[0];
          photos.splice(t, 0, m);
          renderPhotos();
          prgMarkDirty();
        }
        dragSrc = null;
      });
      var thumbImg = div.querySelector('.prg-photo-thumb');
      if (thumbImg) {
        thumbImg.addEventListener('mouseenter', function (ev) { showZoomPreview(thumbImg, ev); });
        thumbImg.addEventListener('mousemove', function (ev) { positionZoomPreview(ev); });
        thumbImg.addEventListener('mouseleave', hideZoomPreview);
        thumbImg.addEventListener('touchstart', function (ev) {
          if (ev.touches && ev.touches[0]) showZoomPreview(thumbImg, { clientX: ev.touches[0].clientX, clientY: ev.touches[0].clientY });
        }, { passive: true });
        thumbImg.addEventListener('touchend', hideZoomPreview);
      }
      body.appendChild(div);
    });
    list.appendChild(sec);
  });
}

function moveP(i, d) {
  var j = i + d;
  if (j < 0 || j >= photos.length) return;
  var t = photos[i]; photos[i] = photos[j]; photos[j] = t;
  renderPhotos();
  prgMarkDirty();
}
function delP(i) {
  URL.revokeObjectURL(photos[i].url);
  photos.splice(i, 1);
  renderPhotos();
  prgMarkDirty();
}
function clearAllPhotos() {
  photos.forEach(function (p) { URL.revokeObjectURL(p.url); });
  photos = [];
  renderPhotos();
  prgClearDraft();
  toast('All photos cleared');
}

/* XML helpers */
function fixSplits(xml) {
  // {{NAME</w:t> ... <w:t...>}}
  xml = xml.replace(/\{\{([A-Z0-9_]+)<\/w:t>([\s\S]*?)<w:t[^>]*>\}\}/g, '{{$1}}');
  xml = xml.replace(/\{\{([A-Z0-9_]+)\s*<\/w:t>([\s\S]*?)<w:t[^>]*>\s*\}\}/g, '{{$1}}');
  // {{</w:t>...<w:t>NAME}}</w:t>
  xml = xml.replace(/\{\{\s*<\/w:t>([\s\S]*?)<w:t[^>]*>\s*([A-Z0-9_]+)\s*\}\}/g, '{{$2}}');
  // Residual split braces still left as plain text nodes
  xml = xml.replace(/\{\{([A-Z0-9_]+)\}\}(?![^<]*<\/w:t>)/g, function (m) { return m; });
  return xml;
}
function applyMap(xml, map) {
  xml = fixSplits(xml);
  var keys = Object.keys(map).sort(function (a, b) { return b.length - a.length; });
  for (var i = 0; i < keys.length; i++) xml = xml.split(keys[i]).join(map[keys[i]]);
  return xml;
}
/** Inner XML of <w:hdr> / <w:ftr> (tables + paragraphs) for body injection.
 *  Safe cleanup: zero bottom spacing + drop truly empty trailing <w:p>
 *  so header sits flush above photos. Never touches tables or drawings.
 */
function extractHdrFtrInner(xml, tag) {
  var re = new RegExp('<w:' + tag + '\\b[^>]*>([\\s\\S]*)</w:' + tag + '>', 'i');
  var m = xml.match(re);
  if (!m) return '';
  var inner = m[1];

  // Zero w:after on self-closing <w:spacing .../>
  inner = inner.replace(/<w:spacing\b([^>]*?)\/>/g, function (full, attrs) {
    attrs = attrs.replace(/\s*w:after="[^"]*"/g, '');
    return '<w:spacing' + attrs + ' w:after="0"/>';
  });

  // Remove empty paragraphs from the end only.
  // Match real <w:p ...> tags only (not <w:pPr>, <w:pgSz>, etc.)
  for (var guard = 0; guard < 30; guard++) {
    var lastIdx = -1;
    var tagRe = /<w:p(?:\s[^>]*)?>/g, tm;
    while ((tm = tagRe.exec(inner)) !== null) lastIdx = tm.index;
    if (lastIdx < 0) break;
    var chunk = inner.slice(lastIdx);
    if (!/^<w:p(?:\s[^>]*)?\/>\s*$/.test(chunk) && !/^<w:p(?:\s[^>]*)?>[\s\S]*<\/w:p>\s*$/.test(chunk)) break;
    var opens = (chunk.match(/<w:p(?:\s[^>]*)?>/g) || []).length;
    var closes = (chunk.match(/<\/w:p>/g) || []).length;
    if (opens !== 1 || (closes !== 1 && !/^<w:p(?:\s[^>]*)?\/>\s*$/.test(chunk))) break;
    if (/<w:tbl[\s>]/.test(chunk) || /<w:drawing[\s>]/.test(chunk)) break;
    var hasText = false;
    var tRe = /<w:t\b[^>]*>([\s\S]*?)<\/w:t>/g, ttm;
    while ((ttm = tRe.exec(chunk)) !== null) {
      if (ttm[1].replace(/\s+/g, '').length > 0) { hasText = true; break; }
    }
    if (hasText) break;
    inner = inner.slice(0, lastIdx);
  }
  return inner;
}
/** Map header/footer image rIds into document.xml.rels; rewrite embeds in inner XML */
function linkHdrFtrImages(innerXml, hdrRelsXml, docRelsRef) {
  if (!innerXml || !hdrRelsXml) return { xml: innerXml || '', rels: docRelsRef.rels, rid: docRelsRef.rid };
  var ridMap = {};
  var re = /Id="(rId\d+)"[^>]*Target="([^"]+)"/g, m;
  while ((m = re.exec(hdrRelsXml)) !== null) {
    var oldId = m[1], target = m[2];
    if (target.indexOf('media/') < 0) continue;
    // Reuse existing doc rel to same target if any
    var exist = docRelsRef.rels.match(new RegExp('Id="(rId\\d+)"[^>]*Target="' + target.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '"'));
    var newId;
    if (exist) {
      newId = exist[1];
    } else {
      newId = 'rId' + docRelsRef.rid;
      docRelsRef.rid++;
      docRelsRef.rels = docRelsRef.rels.replace(
        '</Relationships>',
        '<Relationship Id="' + newId + '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="' + target + '"/></Relationships>'
      );
    }
    ridMap[oldId] = newId;
  }
  Object.keys(ridMap).forEach(function (oldId) {
    innerXml = innerXml.split('r:embed="' + oldId + '"').join('r:embed="' + ridMap[oldId] + '"');
    innerXml = innerXml.split('r:id="' + oldId + '"').join('r:id="' + ridMap[oldId] + '"');
  });
  return { xml: innerXml, rels: docRelsRef.rels, rid: docRelsRef.rid };
}
function nextRid(rels) {
  var max = 0, re = /Id="rId(\d+)"/g, m;
  while ((m = re.exec(rels)) !== null) {
    var n = parseInt(m[1], 10);
    if (n > max) max = n;
  }
  return max + 1;
}
function imageDrawing(rId, cx, cy, name) {
  return '<w:r><w:rPr/><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0">' +
    '<wp:extent cx="' + cx + '" cy="' + cy + '"/><wp:effectExtent l="0" t="0" r="0" b="0"/>' +
    '<wp:docPr id="' + Math.floor(Math.random() * 90000 + 1) + '" name="' + name + '" descr="' + name + '"/>' +
    '<wp:cNvGraphicFramePr><a:graphicFrameLocks xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" noChangeAspect="1"/></wp:cNvGraphicFramePr>' +
    '<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">' +
    '<pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">' +
    '<pic:nvPicPr><pic:cNvPr id="0" name="' + name + '"/><pic:cNvPicPr><a:picLocks noChangeAspect="1"/></pic:cNvPicPr></pic:nvPicPr>' +
    '<pic:blipFill><a:blip r:embed="' + rId + '"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>' +
    '<pic:spPr bwMode="auto"><a:xfrm><a:off x="0" y="0"/><a:ext cx="' + cx + '" cy="' + cy + '"/></a:xfrm>' +
    '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom><a:ln><a:noFill/></a:ln></pic:spPr></pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r>';
}

/** Extract top-level <w:tbl>...</w:tbl> blocks (handles nested tables). */
function extractTopLevelTables(xml) {
  var tables = [], i = 0;
  while (i < xml.length) {
    var start = xml.indexOf('<w:tbl', i);
    if (start < 0) break;
    if (!/^<w:tbl[\s>]/.test(xml.substring(start, start + 10))) {
      i = start + 5;
      continue;
    }
    var depth = 0, j = start;
    while (j < xml.length) {
      var open = xml.indexOf('<w:tbl', j);
      var close = xml.indexOf('</w:tbl>', j);
      if (close < 0) break;
      if (open >= 0 && open < close && /^<w:tbl[\s>]/.test(xml.substring(open, open + 10))) {
        depth++;
        j = open + 5;
      } else {
        depth--;
        j = close + 8;
        if (depth === 0) {
          tables.push({ start: start, end: j, xml: xml.substring(start, j) });
          i = j;
          break;
        }
      }
    }
    if (depth !== 0) break;
  }
  return tables;
}

/**
 * Rebuild document for N photos (2 per page).
 * Supports:
 *  A) Separate body tables: header tbl + photo tbls  (YMR_Photo_Index_Template)
 *  B) Single table with photo rows + optional Word header/footer injection
 */
function rebuildPhotoRows(docXml, nPhotos, headerInner, footerInner) {
  headerInner = headerInner || '';
  footerInner = footerInner || '';

  var bodyMatch = docXml.match(/<w:body([^>]*)>([\s\S]*)<\/w:body>/);
  if (!bodyMatch) return docXml;
  var bodyAttrs = bodyMatch[1];
  var body = bodyMatch[2];
  var sectPr = '';
  var sectIdx = body.lastIndexOf('<w:sectPr');
  if (sectIdx >= 0) {
    sectPr = body.substring(sectIdx);
    body = body.substring(0, sectIdx);
  }

  var tables = extractTopLevelTables(body);
  if (!tables.length) return docXml;

  var headerTables = [];
  var photoTableXml = null;
  for (var t = 0; t < tables.length; t++) {
    var tx = tables[t].xml;
    if (/\{\{PHOTO_\d+\}\}|PHOTO_\d+/.test(tx)) {
      if (!photoTableXml) photoTableXml = tx;
    } else if (/\{\{VESSEL\}\}|\{\{REPORT_NO\}\}|\{\{DATE\}\}|PHOTO INDEX/i.test(tx)) {
      headerTables.push(tx);
    }
  }

  var pageBreakP = '<w:p><w:pPr><w:spacing w:before="0" w:after="0"/></w:pPr><w:r><w:br w:type="page"/></w:r></w:p>';
  // Minimal spacer — avoid visible gap between the 2 photos on a page
  var spacer = '<w:p><w:pPr><w:spacing w:before="0" w:after="0" w:line="20" w:lineRule="auto"/></w:pPr></w:p>';
  var tightGap = '<w:p><w:pPr><w:spacing w:before="40" w:after="40" w:line="20" w:lineRule="auto"/></w:pPr></w:p>';

  // Style A: separate header table + photo tables (YMR Photo Index Template)
  // Build ONE table with 1–2 photo rows per page so no inter-table gap appears.
  if (photoTableXml && headerTables.length) {
    function makePhotoRow(n) {
      var rowMatch = photoTableXml.match(/<w:tr[\s\S]*?<\/w:tr>/);
      if (!rowMatch) return '';
      var row = rowMatch[0];
      row = row.replace(/PHOTO_\d+/g, 'PHOTO_' + n);
      row = row.replace(/NUM_\d+/g, 'NUM_' + n);
      row = row.replace(/DESC_\d+/g, 'DESC_' + n);
      return tightenPhotoRow(row);
    }
    function makePhotoTableForPage(n1, n2) {
      // Reuse tblPr / tblGrid from template photo table
      var headMatch = photoTableXml.match(/<w:tbl>[\s\S]*?(?=<w:tr\b)/);
      var tblHead = headMatch ? headMatch[0] : '<w:tbl>';
      var rows = makePhotoRow(n1);
      if (n2) rows += makePhotoRow(n2);
      return tblHead + rows + '</w:tbl>';
    }
    // Header flush against photos; footer flush under photos (no spacers)
    var headerBlock = tightenHeaderBlock(headerTables.join(''));
    if (headerInner) headerBlock = tightenHeaderBlock(headerInner) + headerBlock;
    var out = '';
    var page = 0;
    for (var n = 1; n <= nPhotos; n += 2) {
      if (page > 0) out += pageBreakP;
      out += headerBlock;
      var n2 = (n + 1 <= nPhotos) ? (n + 1) : 0;
      out += makePhotoTableForPage(n, n2);
      if (footerInner) out += tightenHeaderBlock(footerInner);
      page++;
    }
    return docXml.replace(/<w:body[^>]*>[\s\S]*<\/w:body>/, '<w:body' + bodyAttrs + '>' + out + sectPr + '</w:body>');
  }

  // Style B: single table with photo rows (older template)
  var mainTbl = tables[0].xml;
  for (var ti = 0; ti < tables.length; ti++) {
    if (/\{\{PHOTO_/.test(tables[ti].xml)) { mainTbl = tables[ti].xml; break; }
  }

  var rowRe = /<w:tr\b[\s\S]*?<\/w:tr>/g, rows = [], m;
  while ((m = rowRe.exec(mainTbl)) !== null) rows.push(m[0]);
  var firstTr = mainTbl.search(/<w:tr\b/);
  var tblHead = firstTr >= 0 ? mainTbl.substring(0, firstTr) : '<w:tbl>';
  if (tblHead.indexOf('w:tblBorders') < 0) {
    var borders =
      '<w:tblBorders>' +
      '<w:top w:val="single" w:sz="4" w:space="0" w:color="auto"/>' +
      '<w:left w:val="single" w:sz="4" w:space="0" w:color="auto"/>' +
      '<w:bottom w:val="single" w:sz="4" w:space="0" w:color="auto"/>' +
      '<w:right w:val="single" w:sz="4" w:space="0" w:color="auto"/>' +
      '<w:insideH w:val="single" w:sz="4" w:space="0" w:color="auto"/>' +
      '<w:insideV w:val="single" w:sz="4" w:space="0" w:color="auto"/>' +
      '</w:tblBorders>';
    if (tblHead.indexOf('<w:tblPr>') >= 0) {
      tblHead = tblHead.replace('<w:tblPr>', '<w:tblPr>' + borders);
    } else {
      tblHead = tblHead.replace(/<w:tbl([^>]*)>/, '<w:tbl$1><w:tblPr>' + borders + '</w:tblPr>');
    }
  }

  var photoRow = null, headerRows = '';
  for (var r = 0; r < rows.length; r++) {
    if (/PHOTO_|NUM_|DESC_|\{\{PHOTO_/.test(rows[r])) {
      if (!photoRow) photoRow = rows[r];
    } else {
      headerRows += rows[r];
    }
  }
  if (!photoRow) return docXml;

  function makeRow(n) {
    return tightenPhotoRow(photoRow
      .replace(/PHOTO_\d+/g, 'PHOTO_' + n)
      .replace(/NUM_\d+/g, 'NUM_' + n)
      .replace(/DESC_\d+/g, 'DESC_' + n));
  }

  var outB = '';
  var pageB = 0;
  for (var n2 = 1; n2 <= nPhotos; n2 += 2) {
    if (pageB > 0) outB += pageBreakP;
    if (headerInner) outB += tightenHeaderBlock(headerInner);
    var pageRows = tightenHeaderBlock(headerRows) + makeRow(n2);
    if (n2 + 1 <= nPhotos) pageRows += makeRow(n2 + 1);
    outB += tblHead + pageRows + '</w:tbl>';
    if (footerInner) outB += tightenHeaderBlock(footerInner);
    pageB++;
  }
  return docXml.replace(/<w:body[^>]*>[\s\S]*<\/w:body>/, '<w:body' + bodyAttrs + '>' + outB + sectPr + '</w:body>');
}

/** Page margins — minimal so header sits high, footer low; more room for 2 large photos. */
function fixSectionMargins(docXml) {
  docXml = docXml.replace(
    /<w:pgMar\b([^>]*)\/>/g,
    function (full, attrs) {
      // ~0.2" top/bottom, ~0.35" left/right; header/footer distance near zero
      attrs = attrs.replace(/\s*w:header="[^"]*"/, ' w:header="36"');
      attrs = attrs.replace(/\s*w:footer="[^"]*"/, ' w:footer="36"');
      if (!/w:header=/.test(attrs)) attrs += ' w:header="36"';
      if (!/w:footer=/.test(attrs)) attrs += ' w:footer="36"';
      attrs = attrs.replace(/\s*w:top="[^"]*"/, ' w:top="288"');
      attrs = attrs.replace(/\s*w:bottom="[^"]*"/, ' w:bottom="288"');
      attrs = attrs.replace(/\s*w:left="[^"]*"/, ' w:left="504"');
      attrs = attrs.replace(/\s*w:right="[^"]*"/, ' w:right="504"');
      if (!/w:top=/.test(attrs)) attrs += ' w:top="288"';
      if (!/w:bottom=/.test(attrs)) attrs += ' w:bottom="288"';
      if (!/w:left=/.test(attrs)) attrs += ' w:left="504"';
      if (!/w:right=/.test(attrs)) attrs += ' w:right="504"';
      return '<w:pgMar' + attrs + '/>';
    }
  );
  return docXml;
}

/** Tighten photo table rows: zero paragraph spacing + minimal cell margins (fills visual gaps). */
function tightenPhotoRow(rowXml) {
  if (!rowXml) return rowXml;
  // Paragraph spacing → before/after 0
  rowXml = rowXml.replace(/<w:spacing\b([^>]*?)\/>/g, function (full, attrs) {
    attrs = attrs.replace(/\s*w:before="[^"]*"/g, '');
    attrs = attrs.replace(/\s*w:after="[^"]*"/g, '');
    return '<w:spacing' + attrs + ' w:before="0" w:after="0"/>';
  });
  // Ensure pPr has spacing 0 even when no spacing element existed
  rowXml = rowXml.replace(/<w:pPr>(?!\s*<w:spacing)/g, '<w:pPr><w:spacing w:before="0" w:after="0"/>');
  // Cell margins → very tight (20 twips ≈ 0.03")
  rowXml = rowXml.replace(/<w:tcMar>[\s\S]*?<\/w:tcMar>/g,
    '<w:tcMar><w:top w:w="20" w:type="dxa"/><w:left w:w="40" w:type="dxa"/><w:bottom w:w="20" w:type="dxa"/><w:right w:w="40" w:type="dxa"/></w:tcMar>');
  // Fixed row height large enough for 4.35" image (~6264 twips) + small padding
  if (/<w:trHeight\b/.test(rowXml)) {
    rowXml = rowXml.replace(/<w:trHeight\b[^>]*\/>/g, '<w:trHeight w:val="6400" w:hRule="atLeast"/>');
  } else if (/<w:trPr>/.test(rowXml)) {
    rowXml = rowXml.replace('<w:trPr>', '<w:trPr><w:trHeight w:val="6400" w:hRule="atLeast"/>');
  } else {
    rowXml = rowXml.replace(/<w:tr(\b[^>]*)>/, '<w:tr$1><w:trPr><w:trHeight w:val="6400" w:hRule="atLeast"/></w:trPr>');
  }
  return rowXml;
}

/** Zero spacing on header body tables so no gap under logo/title row. */
function tightenHeaderBlock(xml) {
  if (!xml) return xml;
  xml = xml.replace(/<w:spacing\b([^>]*?)\/>/g, function (full, attrs) {
    attrs = attrs.replace(/\s*w:before="[^"]*"/g, '');
    attrs = attrs.replace(/\s*w:after="[^"]*"/g, '');
    return '<w:spacing' + attrs + ' w:before="0" w:after="0"/>';
  });
  xml = xml.replace(/<w:tcMar>[\s\S]*?<\/w:tcMar>/g,
    '<w:tcMar><w:top w:w="20" w:type="dxa"/><w:left w:w="40" w:type="dxa"/><w:bottom w:w="20" w:type="dxa"/><w:right w:w="40" w:type="dxa"/></w:tcMar>');
  return xml;
}

function findRunStart(doc, tPos) {
  var searchFrom = tPos;
  while (searchFrom > 0) {
    var cand = doc.lastIndexOf('<w:r', searchFrom - 1);
    if (cand < 0) return -1;
    var ch = doc.charAt(cand + 4);
    if (ch === '>' || ch === ' ' || ch === '\t' || ch === '\n' || ch === '\r') return cand;
    searchFrom = cand;
  }
  return -1;
}

async function generateReport(mode) {
  mode = (mode === 'compressed') ? 'compressed' : 'original';
  var vessel = document.getElementById('vesselName').value.trim();
  var dateVal = document.getElementById('surveyDate').value;
  var reportNo = document.getElementById('reportNumber').value.trim();
  if (!vessel || !dateVal || !reportNo) { toast('Fill all Report Details', true); return; }
  if (!templateAB || !templateReady) {
    toast('Photo Report template missing — please contact Admin', true);
    showTemplateMissing();
    return;
  }
  if (!photos.length) { toast('Upload at least 1 photo', true); return; }
  if (typeof JSZip === 'undefined') { toast('JSZip not loaded', true); return; }

  // Auto-order: categories, then Hold structures (Hatch Entrance → … → Bilge Tanks)
  sortPhotosForReport();
  renderPhotos();

  var btnO = document.getElementById('generateBtnOriginal');
  var btnC = document.getElementById('generateBtnCompressed');
  var btn = (mode === 'compressed') ? btnC : btnO;
  var origO = btnO ? btnO.innerHTML : '';
  var origC = btnC ? btnC.innerHTML : '';
  if (btnO) { btnO.disabled = true; }
  if (btnC) { btnC.disabled = true; }
  if (btn) btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Generating...';

  var modeTitle = (mode === 'compressed') ? 'Generating Compressed report' : 'Generating Original report';
  prgShowProgress(modeTitle, 'Preparing…');

  try {
    prgUpdateProgress(5, 100, mode === 'compressed' ? 'Optimizing photos for size…' : 'Loading photos…');
    var payload = await buildPhotoPayload(mode);
    var imgBytes = payload.totalBytes;
    var mediaItems = payload.items;
    prgUpdateProgress(35, 100, 'Building Word document…');
    var dateFmt = new Date(dateVal + 'T00:00:00').toLocaleDateString('en-GB', {day: '2-digit', month: 'short', year: 'numeric'});
    var zip = await JSZip.loadAsync(templateAB);
    if (!zip.file('word/document.xml')) throw new Error('Invalid template');

    var map = {
      '{{VESSEL}}': xmlEsc(vessel),
      '{{REPORT_NO}}': xmlEsc(reportNo),
      '{{DATE}}': xmlEsc(dateFmt)
    };
    for (var i = 0; i < photos.length; i++) {
      var n = i + 1;
      map['{{NUM_' + n + '}}'] = String(n);
      map['{{DESC_' + n + '}}'] = xmlEsc(reportDescText(photos[i]));
    }
    for (var n = photos.length + 1; n <= 50; n++) {
      map['{{NUM_' + n + '}}'] = '';
      map['{{DESC_' + n + '}}'] = '';
      map['{{PHOTO_' + n + '}}'] = '';
    }

    var rels = await zip.file('word/_rels/document.xml.rels').async('string');
    var ct = await zip.file('[Content_Types].xml').async('string');
    var rid = nextRid(rels);
    var docRelsRef = { rels: rels, rid: rid };

    // ---- Header / footer: inject into BODY so they show on mobile Word too ----
    var headerInner = '', footerInner = '';
    if (zip.file('word/header1.xml')) {
      var hdrXml = applyMap(await zip.file('word/header1.xml').async('string'), map);
      headerInner = extractHdrFtrInner(hdrXml, 'hdr');
      var hdrRels = zip.file('word/_rels/header1.xml.rels')
        ? await zip.file('word/_rels/header1.xml.rels').async('string') : '';
      var linked = linkHdrFtrImages(headerInner, hdrRels, docRelsRef);
      headerInner = linked.xml;
      docRelsRef.rels = linked.rels;
      docRelsRef.rid = linked.rid;
      // Clear section header so desktop does not double-print it
      zip.file('word/header1.xml', hdrXml.replace(
        /(<w:hdr\b[^>]*>)[\s\S]*(<\/w:hdr>)/,
        '$1<w:p><w:pPr/><w:r><w:t></w:t></w:r></w:p>$2'
      ));
    }
    if (zip.file('word/footer1.xml')) {
      var ftrXml = applyMap(await zip.file('word/footer1.xml').async('string'), map);
      footerInner = extractHdrFtrInner(ftrXml, 'ftr');
      var ftrRels = zip.file('word/_rels/footer1.xml.rels')
        ? await zip.file('word/_rels/footer1.xml.rels').async('string') : '';
      linked = linkHdrFtrImages(footerInner, ftrRels, docRelsRef);
      footerInner = linked.xml;
      docRelsRef.rels = linked.rels;
      docRelsRef.rid = linked.rid;
      zip.file('word/footer1.xml', ftrXml.replace(
        /(<w:ftr\b[^>]*>)[\s\S]*(<\/w:ftr>)/,
        '$1<w:p><w:pPr/><w:r><w:t></w:t></w:r></w:p>$2'
      ));
    }
    rels = docRelsRef.rels;
    rid = docRelsRef.rid;

    var docXml = await zip.file('word/document.xml').async('string');
    docXml = rebuildPhotoRows(docXml, photos.length, headerInner, footerInner);
    docXml = fixSectionMargins(docXml);
    docXml = applyMap(docXml, map);

    var xmlFiles = Object.keys(zip.files).filter(function (p) {
      return p.endsWith('.xml') && p.indexOf('word/media/') !== 0
        && p !== 'word/document.xml'
        && p !== 'word/header1.xml'
        && p !== 'word/footer1.xml';
    });
    for (var fi = 0; fi < xmlFiles.length; fi++) {
      var content = await zip.file(xmlFiles[fi]).async('string');
      zip.file(xmlFiles[fi], applyMap(content, map));
    }

    // Uniform frame 5.8" × 4.0" — exactly 2 photos/page; header flush (no top gap)
    var EMU = 914400;
    var fixedCx = Math.round(PHOTO_FIXED_W_IN * EMU);
    var fixedCy = Math.round(PHOTO_FIXED_H_IN * EMU);

    prgUpdateProgress(45, 100, 'Embedding ' + photos.length + ' photo(s)…');
    for (var i = 0; i < photos.length; i++) {
      var n = i + 1;
      var mi = mediaItems[i] || { ab: photos[i].ab, ext: 'jpeg' };
      var ext = (mi.ext === 'png') ? 'png' : 'jpg';
      var mediaName = 'photo_' + n + '.' + ext;
      zip.file('word/media/' + mediaName, mi.ab);
      var rId = 'rId' + rid; rid++;
      rels = rels.replace('</Relationships>',
        '<Relationship Id="' + rId + '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/' + mediaName + '"/></Relationships>');
      var mime = ext === 'png' ? 'image/png' : 'image/jpeg';
      if (ct.indexOf('Extension="' + ext + '"') < 0)
        ct = ct.replace('</Types>', '<Default Extension="' + ext + '" ContentType="' + mime + '"/></Types>');
      var cx = fixedCx, cy = fixedCy;
      var drawing = imageDrawing(rId, cx, cy, 'Photo' + n);
      var token = '{{PHOTO_' + n + '}}';
      var tPos = docXml.indexOf(token);
      if (tPos >= 0) {
        var runStart = findRunStart(docXml, tPos);
        var runEnd = docXml.indexOf('</w:r>', tPos);
        if (runStart >= 0 && runEnd >= 0) {
          runEnd += 6;
          if (runEnd - runStart > 0 && runEnd - runStart < 2500)
            docXml = docXml.substring(0, runStart) + drawing + docXml.substring(runEnd);
        }
        docXml = docXml.split(token).join('');
      }
      if (i % 10 === 0 || i === photos.length - 1) {
        // 45% → 75% while embedding
        var embPct = 45 + Math.round(((i + 1) / photos.length) * 30);
        prgUpdateProgress(embPct, 100, 'Embedding photos ' + (i + 1) + ' / ' + photos.length);
      }
    }

    zip.file('word/_rels/document.xml.rels', rels);
    zip.file('[Content_Types].xml', ct);
    zip.file('word/document.xml', docXml);

    prgUpdateProgress(78, 100, 'Packing Word file…');
    var blob = await zip.generateAsync(
      {
        type: 'blob',
        mimeType: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        compression: 'DEFLATE',
        compressionOptions: { level: 9 }
      },
      function (meta) {
        // JSZip reports 0–100 for packing
        var p = 78 + Math.round((meta.percent || 0) * 0.20);
        prgUpdateProgress(Math.min(98, p), 100, 'Packing Word file… ' + Math.round(meta.percent || 0) + '%');
      }
    );
    prgUpdateProgress(100, 100, 'Download starting…');
    var safe = vessel.replace(/[\\/:*?"<>|]/g, '_').trim() || 'Vessel';
    var suffix = (mode === 'compressed') ? ' Photo Report (Compressed).docx' : ' Photo Report.docx';
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = safe + suffix;
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(function () { URL.revokeObjectURL(a.href); }, 8000);
    var sizeMb = (blob.size / (1024 * 1024)).toFixed(1);
    var imgMb = (imgBytes / (1024 * 1024)).toFixed(1);
    var modeLabel = (mode === 'compressed') ? 'compressed' : 'original high quality';
    toast('Downloaded: ' + a.download + ' (' + photos.length + ' photos, ' + sizeMb + ' MB · ' + modeLabel + ' · images ~' + imgMb + ' MB)');
    // Successful generate — clear stored draft so a fresh open starts clean
    prgClearDraft();
  } catch (e) {
    console.error(e);
    toast('Error: ' + (e.message || e), true);
  } finally {
    prgHideProgress();
    if (btnO) { btnO.disabled = false; btnO.innerHTML = origO; }
    if (btnC) { btnC.disabled = false; btnC.innerHTML = origC; }
  }
}

// Restore any previous draft after all helpers/constants exist
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', function () { prgInitDraft(); });
} else {
  prgInitDraft();
}
</script>

<?php include 'includes/footer.php'; ?>
