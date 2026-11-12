<?php
/**
 * Survey reports now open in survey_report_public.php (one page, with or without signing in).
 * This address is kept so old links and bookmarks still work.
 */
$id = (int)($_GET['id'] ?? 0);
header('Location: survey_report_public.php' . ($id > 0 ? '?id=' . $id : ''), true, 302);
exit;
