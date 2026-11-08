<?php
/**
 * Small helpers for the app-style mobile detail pages (vessel_detail.php, report_detail.php,
 * completed_detail.php; styles in assets/css/detail-app.css). Output is escaped here.
 */
require_once __DIR__ . '/vessel_image.php';

/** [src, isReal] of the vessel's picture (AI-found photo, or the YMR ship). */
function daPhoto(PDO $db, string $name): array {
    try {
        $st = viStates($db, [$name]);
        $s = $st[viKey($name)] ?? null;
        if ($s && $s['status'] === 'found') return [$s['img'], true];
    } catch (Throwable $e) { error_log('detail picture: ' . $e->getMessage()); }
    return [VI_DEFAULT, false];
}

/** [label, chip class] of a survey status. */
function daStatus(string $status): array {
    return match ($status) {
        'Pending Vessel' => ['Pending Survey', 'st-pending'],
        'Pending Report' => ['Pending Report', 'st-report'],
        'Completed' => ['Completed', 'st-done'],
        default => [$status !== '' ? $status : 'N/A', 'st-other'],
    };
}

/** One field: icon, label (+ the Admin's inline-edit pencil), value HTML (already escaped). */
function daField(string $icon, string $label, string $valueHtml, string $aie = '', string $cls = '', string $testid = ''): string {
    return '<div class="dapp-f ' . $cls . '"' . ($testid !== '' ? ' data-testid="' . htmlspecialchars($testid, ENT_QUOTES) . '"' : '') . '><i class="' . htmlspecialchars($icon, ENT_QUOTES) . '"></i><div>'
        . '<span class="dapp-l">' . htmlspecialchars($label) . $aie . '</span>' . $valueHtml . '</div></div>';
}

function daVal(?string $v, string $cls = ''): string {
    $v = trim((string)$v);
    return '<b class="dapp-v ' . $cls . '">' . ($v !== '' ? htmlspecialchars($v) : '—') . '</b>';
}

/** People chips with initials: "Ashok Janapala + Prakash Bommali" → two chips. */
function daPeople(string $names): string {
    $list = array_values(array_filter(array_map('trim', preg_split('/\s*(?:\+|,|&)\s*/', $names))));
    if (!$list) return daVal('');
    $out = '<div class="dapp-people">';
    foreach ($list as $n) {
        $ini = '';
        foreach (array_slice(preg_split('/\s+/', $n), 0, 2) as $w) $ini .= mb_strtoupper(mb_substr($w, 0, 1));
        $out .= '<span class="dapp-p"><em>' . htmlspecialchars($ini) . '</em>' . htmlspecialchars($n) . '</span>';
    }
    return $out . '</div>';
}

/** Recovery box: total + VLSFO / LSMGO / HSFO (already formatted values with "MT"), optional Edit button. */
function daRecovery(string $total, string $vlsfo, string $lsmgo, string $hsfo, string $editTarget = '', string $testid = ''): string {
    $short = fn($v) => htmlspecialchars(preg_replace('/\s*MT$/', '', $v));
    return '<div class="dapp-rec"' . ($testid !== '' ? ' data-testid="' . htmlspecialchars($testid, ENT_QUOTES) . '"' : '') . '>'
        . '<div class="dapp-rec-t"><i class="fa-solid fa-droplet"></i> Recovery <span class="tot">Total ' . htmlspecialchars($total) . '</span></div>'
        . '<div class="dapp-rec-p"><div>VLSFO<b>' . $short($vlsfo) . '</b></div><div>LSMGO<b>' . $short($lsmgo) . '</b></div><div>HSFO<b>' . $short($hsfo) . '</b></div></div>'
        . ($editTarget !== '' ? '<button type="button" class="dapp-rec-edit" data-dapp-toggle="' . htmlspecialchars($editTarget, ENT_QUOTES) . '" data-testid="recovery-edit-open"><i class="fa-solid fa-pen"></i> Edit Recovery</button>' : '')
        . '</div>';
}
