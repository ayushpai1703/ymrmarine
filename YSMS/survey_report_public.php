<?php
/**
 * Survey report without signing in — a page of its own (no YSMS menu). Rules and limits:
 * includes/survey_report_guest.php; API: ajax/survey_report_public.php; editor: assets/js/survey-report.js
 * (the same wizard as survey_report.php). The preview is not a side panel here: the Preview button
 * opens it in a window (modal) with a Close button.
 *   survey_report_public.php            choose the report type → a new report
 *   survey_report_public.php?t=<token>  that report (the secret link; keep it to continue later)
 */
require_once 'config/config.php';
require_once 'includes/survey_report_guest.php';
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer'); // the secret link never leaves in a Referer header

$token = srgToken($_GET['t'] ?? '');
$kinds = [];
$notFound = false;
try {
    $db = getDB();
    if ($token !== '') { if (!srgReport($db, $token)) { $notFound = true; $token = ''; } }
    if ($token === '') $kinds = srgKinds($db);
} catch (Throwable $e) {
    error_log('survey_report_public: ' . $e->getMessage());
}
$v = fn(string $f) => @filemtime(__DIR__ . '/' . $f);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Survey Report — <?= sanitize(SITE_NAME) ?></title>
    <link rel="icon" href="assets/images/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/survey-report.css?v=<?= $v('assets/css/survey-report.css') ?>">
    <style>
        body.sr-public { margin: 0; background: #f4f7fb; font-family: 'Lexend', system-ui, sans-serif; color: #0f172a; }
        .srp-top { display: flex; align-items: center; gap: 10px; padding: 10px 18px; background: #fff; border-bottom: 1px solid #e5e7eb; }
        .srp-top img { height: 34px; width: auto; }
        .srp-top b { font-size: 15px; letter-spacing: .02em; }
        .srp-top span { font-size: 12px; color: #64748b; display: block; }
        .sr-public .sr-wrap { margin: 0 auto; padding-bottom: 60px; }
        /* the preview is never a side panel here: the Preview button opens it as a window */
        .sr-public .sr-v4 .sr-split { display: block; }
        .sr-public .sr-pv-fab, .sr-public .sr-pv-exp { display: none !important; }
        .sr-public .sr-pv { display: none; }
        body.sr-public.sr-pv-open .sr-v4 .sr-pv { display: flex; flex-direction: column; position: fixed; z-index: 2000; background: #fff; inset: 0; top: 0; height: auto; border: 0; border-radius: 0; }
        .sr-public .sr-pv-x { display: inline-flex !important; }
        .srp-pv-btn { height: 38px; padding: 0 16px; border: 0; border-radius: 10px; font: 700 13.5px 'Lexend', system-ui, sans-serif; color: #fff;
            background: linear-gradient(135deg, #00A3DF, #6C4CF1); box-shadow: 0 6px 16px rgba(108,76,241,.28); display: inline-flex; align-items: center; gap: 8px; cursor: pointer; }
        @media (min-width: 992px) {
            body.sr-public.sr-pv-open::after { content: ''; position: fixed; inset: 0; background: rgba(15,23,42,.55); z-index: 1999; }
            body.sr-public.sr-pv-open .sr-v4 .sr-pv { inset: 3vh auto 3vh 50%; transform: translateX(-50%); width: min(980px, 94vw); border-radius: 14px; overflow: hidden; box-shadow: 0 24px 60px rgba(15,23,42,.35); }
        }
        @media (max-width: 991.98px) { .srp-pv-btn { position: fixed; right: 16px; bottom: calc(16px + env(safe-area-inset-bottom, 0px)); z-index: 1040; height: 46px; border-radius: 999px; } }
        .srp-link { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; background: #eef5ff; border: 1px solid #cfe0fb; color: #1e3a8a; border-radius: 12px; padding: 10px 14px; font-size: 13px; margin-bottom: 14px; }
        .srp-link input { flex: 1 1 260px; min-width: 0; height: 34px; border: 1px solid #cbd5e1; border-radius: 8px; padding: 0 10px; font-size: 12.5px; color: #334155; background: #fff; }
        .srp-link button { height: 34px; border: 1px solid #0b63e5; background: #fff; color: #0b63e5; border-radius: 8px; font-weight: 700; font-size: 12.5px; padding: 0 12px; }
        .srp-new { max-width: 720px; margin: 24px auto; }
    </style>
</head>
<body class="sr-public">
<header class="srp-top">
    <img src="assets/images/logo.png" alt="">
    <div><b>YMR MARINE</b><span>Survey Report</span></div>
</header>
<?php if ($token === ''): ?>
<div class="sr-wrap sr-v4">
    <section class="sr-card sr-newrep srp-new" data-testid="srp-new">
        <h3><i class="fa-solid fa-file-circle-plus"></i> New Survey Report</h3>
        <?php if ($notFound): ?>
            <div class="sr-note err" data-testid="srp-not-found">This report link is not valid. Start a new report below, or use the link you saved.</div>
        <?php endif; ?>
        <?php if (!$kinds): ?>
            <div class="sr-note warn" data-testid="srp-no-kinds">Survey reports are not available right now.</div>
        <?php else: ?>
            <p class="sr-muted">Choose the report type. Your report is saved as you type; keep its link to continue later.</p>
            <div class="sr-newrep-k">
                <?php foreach ($kinds as $k => $l): ?>
                    <button type="button" class="sr-btn" data-new-kind="<?= sanitize($k) ?>" data-testid="srp-new-<?= sanitize($k) ?>"><i class="fa-solid fa-file-lines"></i> <?= sanitize($l) ?></button>
                <?php endforeach; ?>
            </div>
            <div class="sr-note" data-last hidden data-testid="srp-last"><i class="fa-solid fa-clock-rotate-left"></i> <a href="#" data-last-link>Continue your last report on this device</a></div>
            <div class="sr-note err" data-new-err hidden data-testid="srp-new-err"></div>
        <?php endif; ?>
    </section>
</div>
<script>
(function () {
  var csrf = <?= json_encode(csrf_token()) ?>, busy = false;
  try { var last = localStorage.getItem('ysms_srp_last'); if (last && /^[0-9a-f]{64}$/.test(last)) { var l = document.querySelector('[data-last]'); if (l) { l.hidden = false; l.querySelector('a').href = '?t=' + last; } } } catch (e) {}
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('[data-new-kind]'); if (!b || busy) return;
    busy = true; b.disabled = true;
    var f = new FormData(); f.append('csrf_token', csrf); f.append('a', 'new'); f.append('kind', b.getAttribute('data-new-kind'));
    fetch('ajax/survey_report_public.php', { method: 'POST', body: f, credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf } })
      .then(function (r) { return r.json(); })
      .then(function (j) { if (j && j.ok && j.token) { location.replace('?t=' + j.token); return; } throw new Error(j && j.error || 'Could not make the report.'); })
      .catch(function (err) { busy = false; b.disabled = false; var m = document.querySelector('[data-new-err]'); if (m) { m.hidden = false; m.textContent = err.message || 'No connection.'; } });
  });
})();
</script>
<?php else: ?>
<div class="sr-wrap sr-v4" data-testid="survey-report-page">
    <div class="sr-head">
        <div class="sr-crumbs"><a href="survey_report_public.php" class="sr-back" data-testid="sr-back" title="New report"><i class="fa-solid fa-arrow-left"></i></a>
            <span class="sr-crumb"><span data-kindcrumb>Survey</span> Report <i>/</i> <b data-crumb>Vessel Details</b></span>
            <h1 data-title hidden>Survey report</h1><span data-subtitle hidden></span></div>
        <div class="sr-head-r">
            <span class="sr-chip-h"><span>Vessel:</span> <b data-vchip data-testid="sr-vchip">—</b></span>
            <span class="sr-chip-h"><span>Report:</span> <b data-kchip>—</b></span>
            <span class="sr-chip-h"><i class="fa-regular fa-calendar"></i> <span>Date:</span> <b data-dchip>—</b></span>
            <span class="sr-net" data-net data-testid="sr-net"></span>
            <span class="sr-save" data-save-state data-testid="sr-save-state"></span>
            <span class="sr-live" data-live hidden data-testid="sr-live"></span>
            <button type="button" class="srp-pv-btn" data-pv="open" data-testid="srp-preview-open"><i class="fa-solid fa-eye"></i> Preview</button>
            <button type="button" class="sr-pv-fab" data-pv="open" data-testid="sr-pv-open" hidden></button>
        </div>
    </div>
    <div class="srp-link" data-testid="srp-link"><i class="fa-solid fa-link"></i> <span>Keep this link to continue your report later:</span>
        <input type="text" readonly data-srp-url aria-label="Report link" data-testid="srp-link-url">
        <button type="button" data-srp-copy data-testid="srp-link-copy"><i class="fa-regular fa-copy"></i> Copy</button></div>
    <div class="sr-split">
        <div class="sr-split-l">
            <div data-banners></div>
            <div data-form data-testid="sr-form"></div>
        </div>
        <aside class="sr-pv" data-testid="sr-preview" aria-label="Report preview" role="dialog" aria-modal="true">
            <div class="sr-pv-tabs">
                <button type="button" class="sr-pv-tab" data-pv="load" data-pv-tab="pdf" data-testid="sr-preview-report"><i class="fa-regular fa-file-lines"></i> Report Preview</button>
                <button type="button" class="sr-pv-tab" data-pv="excel" data-pv-tab="excel" data-testid="sr-excel-view"><i class="fa-solid fa-table"></i> Excel View</button>
                <span class="sr-pv-sp"></span>
                <button type="button" class="sr-pv-b sr-pv-x" data-pv="close" title="Close" aria-label="Close preview" data-testid="sr-pv-close"><i class="fa-solid fa-xmark"></i> Close</button>
            </div>
            <div class="sr-pv-bar" data-pv-bar hidden>
                <span class="sr-pv-st" data-pv-status data-testid="sr-pv-status"></span>
                <span class="sr-pv-sp"></span>
                <button type="button" class="sr-pv-b" data-pv="prev" title="Previous page" aria-label="Previous page" data-testid="sr-pv-prev"><i class="fa-solid fa-chevron-left"></i></button>
                <span class="sr-pv-n" data-pv-page data-testid="sr-pv-pageno">– / –</span>
                <button type="button" class="sr-pv-b" data-pv="next" title="Next page" aria-label="Next page" data-testid="sr-pv-next"><i class="fa-solid fa-chevron-right"></i></button>
                <button type="button" class="sr-pv-b" data-pv="out" title="Zoom out" aria-label="Zoom out" data-testid="sr-pv-out"><i class="fa-solid fa-magnifying-glass-minus"></i></button>
                <button type="button" class="sr-pv-b sr-pv-z" data-pv="fit" title="Fit width" data-pv-zoomv data-testid="sr-pv-zoom">100%</button>
                <button type="button" class="sr-pv-b" data-pv="in" title="Zoom in" aria-label="Zoom in" data-testid="sr-pv-in"><i class="fa-solid fa-magnifying-glass-plus"></i></button>
            </div>
            <div class="sr-pv-c" data-pv-canvas data-testid="sr-pv-canvas"><div class="sr-pv-empty" data-testid="sr-pv-empty"><i class="fa-regular fa-file-lines"></i><b>Report preview</b><span>The report as it will print, made from the saved values.</span></div></div>
            <div class="sr-pv-note">Preview only — nothing is stored.</div>
        </aside>
    </div>
</div>
<script>
(function () {
  var url = location.origin + location.pathname + '?t=' + <?= json_encode($token) ?>;
  try { localStorage.setItem('ysms_srp_last', <?= json_encode($token) ?>); } catch (e) {}
  var inp = document.querySelector('[data-srp-url]'); inp.value = url;
  document.querySelector('[data-srp-copy]').addEventListener('click', function () {
    var b = this, done = function () { b.innerHTML = '<i class="fa-solid fa-check"></i> Copied'; setTimeout(function () { b.innerHTML = '<i class="fa-regular fa-copy"></i> Copy'; }, 2000); };
    if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(url).then(done, function () { inp.select(); document.execCommand('copy'); done(); });
    else { inp.select(); document.execCommand('copy'); done(); }
  });
})();
</script>
<script>window.SR_PAGE = <?= json_encode(['id' => $token, 'guest' => true, 'csrf' => csrf_token(), 'api' => 'ajax/survey_report_public.php',
    'pdfjs' => 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js', 'pdfjsWorker' => 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES) ?>;</script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.14.5/dist/sweetalert2.all.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
<script src="assets/js/report-calc.js?v=<?= $v('assets/js/report-calc.js') ?>"></script>
<script src="assets/js/survey-report.js?v=<?= $v('assets/js/survey-report.js') ?>"></script>
<?php endif; ?>
</body>
</html>
