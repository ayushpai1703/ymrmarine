<?php
/**
 * PDF text extraction for email attachments, using the free/open-source
 * smalot/pdfparser (added to composer.json — no paid API, no OCR
 * requirement for the core workflow). Scanned/image-only PDFs simply
 * return null text — the caller falls back to "Enter Manually" per spec.
 */
require_once __DIR__ . '/../vendor/autoload.php';

/** Returns extracted text, or null if extraction failed/produced nothing usable. */
function extractPdfText(string $absoluteFilePath): ?string {
    if (!is_file($absoluteFilePath) || !is_readable($absoluteFilePath)) {
        return null;
    }
    if (!class_exists('\Smalot\PdfParser\Parser')) {
        error_log('extractPdfText: smalot/pdfparser not installed — run composer install in YSMS/.');
        return null;
    }
    try {
        $parser = new \Smalot\PdfParser\Parser();
        $pdf = $parser->parseFile($absoluteFilePath);
        $text = trim((string)$pdf->getText());
        return $text !== '' ? $text : null;
    } catch (\Throwable $e) {
        error_log('extractPdfText failed for ' . basename($absoluteFilePath) . ': ' . $e->getMessage());
        return null;
    }
}

/** Extracts text directly from raw PDF bytes (email attachment already in memory) via a temp file. */
function extractPdfTextFromBytes(string $bytes): ?string {
    if ($bytes === '') return null;
    $tmp = tempnam(sys_get_temp_dir(), 'ysms_pdf_');
    if ($tmp === false) return null;
    try {
        file_put_contents($tmp, $bytes);
        return extractPdfText($tmp);
    } finally {
        @unlink($tmp);
    }
}
