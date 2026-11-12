<?php
/**
 * Make an uploaded PDF smaller, in place, with Ghostscript (pdfwrite).
 *
 * Pictures and scanned pages are brought down to 150 dpi (black-and-white scans to 300 dpi),
 * fonts are subset and compressed, repeated images are stored once. Text and lines stay vector,
 * so the document reads the same; scans stay clear on a phone and in print.
 *
 * The original is kept when:
 *   - Ghostscript is not installed, fails, or takes too long;
 *   - the PDF carries a digital signature (rewriting the file would break the signature);
 *   - the result is not a whole PDF, or would not be at least 5% smaller.
 * Never throws: a PDF that cannot be made smaller is stored as uploaded.
 */

const PDFC_TIMEOUT = 120;          // seconds for one file
const PDFC_MIN_SAVING = 0.05;      // keep the new file only when it is at least 5% smaller

function pdfcGs(): ?string {
    static $gs = false;
    if ($gs !== false) return $gs;
    $gs = null;
    foreach (['/usr/bin/gs', '/usr/local/bin/gs', '/opt/homebrew/bin/gs'] as $p) {
        if (is_file($p) && is_executable($p)) { $gs = $p; break; }
    }
    return $gs;
}

/** True when the PDF has a digital signature field with signed bytes. */
function pdfcSigned(string $path): bool {
    $h = @fopen($path, 'rb');
    if (!$h) return false;
    $found = false;
    $tail = '';
    while (!feof($h)) {
        $chunk = $tail . (string)fread($h, 1 << 20);
        if (strpos($chunk, '/ByteRange') !== false && preg_match('~/Type\s*/Sig\b|/SubFilter\s*/(adbe|ETSI)~', $chunk)) { $found = true; break; }
        $tail = substr($chunk, -64);
    }
    fclose($h);
    return $found;
}

/** "1.2 MB" / "640 KB" */
function pdfcSizeLabel(int $bytes): string {
    return $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : max(1, (int)round($bytes / 1024)) . ' KB';
}

/**
 * Compress the PDF at $path in place.
 * @return array{compressed: bool, before: int, after: int, reason: string}
 */
function pdfCompress(string $path): array {
    clearstatcache(true, $path);
    $before = (int)@filesize($path);
    $res = ['compressed' => false, 'before' => $before, 'after' => $before, 'reason' => ''];
    if ($before <= 0) { $res['reason'] = 'empty'; return $res; }
    $gs = pdfcGs();
    if ($gs === null) { $res['reason'] = 'no ghostscript'; return $res; }
    if (pdfcSigned($path)) { $res['reason'] = 'digitally signed'; return $res; }

    $out = $path . '.c' . bin2hex(random_bytes(4)) . '.pdf';
    $cmd = [
        $gs, '-q', '-dNOPAUSE', '-dBATCH', '-dSAFER', '-sDEVICE=pdfwrite',
        '-dCompatibilityLevel=1.5', '-dPDFSETTINGS=/ebook',
        '-dDetectDuplicateImages=true', '-dCompressFonts=true', '-dSubsetFonts=true',
        '-dDownsampleColorImages=true', '-dColorImageDownsampleType=/Bicubic', '-dColorImageResolution=150', '-dColorImageDownsampleThreshold=1.2',
        '-dDownsampleGrayImages=true', '-dGrayImageDownsampleType=/Bicubic', '-dGrayImageResolution=150', '-dGrayImageDownsampleThreshold=1.2',
        '-dDownsampleMonoImages=true', '-dMonoImageDownsampleType=/Subsample', '-dMonoImageResolution=300',
        '-sOutputFile=' . $out, '--', $path,
    ];
    if (is_file('/usr/bin/timeout')) array_unshift($cmd, '/usr/bin/timeout', (string)PDFC_TIMEOUT);

    $code = -1;
    $p = @proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    if (is_resource($p)) $code = proc_close($p);

    clearstatcache(true, $out);
    $after = is_file($out) ? (int)filesize($out) : 0;
    $ok = $code === 0 && $after > 0 && pdfcWhole($out);
    if (!$ok) { @unlink($out); $res['reason'] = 'ghostscript failed (' . $code . ')'; return $res; }
    if ($after > $before * (1 - PDFC_MIN_SAVING)) { @unlink($out); $res['reason'] = 'already small'; return $res; }
    if (!@rename($out, $path)) { @unlink($out); $res['reason'] = 'could not replace'; return $res; }
    @chmod($path, 0644);
    $res['compressed'] = true;
    $res['after'] = $after;
    return $res;
}

/** The file starts like a PDF and ends with %%EOF. */
function pdfcWhole(string $path): bool {
    $h = @fopen($path, 'rb');
    if (!$h) return false;
    $head = (string)fread($h, 5);
    fseek($h, -1024, SEEK_END);
    $tail = (string)fread($h, 1024);
    fclose($h);
    return $head === '%PDF-' && strpos($tail, '%%EOF') !== false;
}
