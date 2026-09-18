<?php
/**
 * Photo Report Generator — system template API
 *
 * GET  ?action=info  → JSON { ok, name, size, updated_at }
 * GET  ?action=file  → binary .docx (auth required)
 * POST action=upload (Admin) → multipart template_file
 * POST action=delete (Admin)
 */
require_once dirname(__DIR__) . '/config/config.php';
checkAuth();

// 🌟 STORAGE FIX: moved under uploads/, the only directory mounted to a persistent
// Cloud Storage bucket (see cloudbuild.yaml). The old photo_report_templates/ dir
// was container-local disk, so an uploaded template was lost on every recycle.
$dir = dirname(__DIR__) . '/uploads/photo_report_templates';
if (!is_dir($dir)) {
    @mkdir($dir, 0755, true);
}
// $filePath/$metaPath always point to the new persistent location and are the
// ONLY paths ever written to (upload/delete). For reads (info/file), fall back to
// the legacy container-local file below only if nothing has been uploaded yet
// to the new location, so an already-uploaded template isn't hidden mid-transition.
$filePath = $dir . '/YMR_Photo_Report_Template.docx';
$metaPath = $dir . '/meta.json';
$legacyDir = dirname(__DIR__) . '/photo_report_templates';
$readFilePath = $filePath;
$readMetaPath = $metaPath;
if (!is_file($filePath) && is_file($legacyDir . '/YMR_Photo_Report_Template.docx')) {
    $readFilePath = $legacyDir . '/YMR_Photo_Report_Template.docx';
    $readMetaPath = $legacyDir . '/meta.json';
}

function prg_tpl_read_meta($metaPath) {
    if (!is_file($metaPath)) return [];
    $raw = @file_get_contents($metaPath);
    $data = $raw ? json_decode($raw, true) : null;
    return is_array($data) ? $data : [];
}

function prg_tpl_write_meta($metaPath, $data) {
    @file_put_contents($metaPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'info';
$role = $_SESSION['role'] ?? '';

// ── Upload (Admin only) ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'upload') {
    header('Content-Type: application/json; charset=utf-8');
    if ($role !== 'Admin') {
        echo json_encode(['ok' => false, 'error' => 'Admin only']);
        exit;
    }
    if (!isset($_FILES['template_file']) || $_FILES['template_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['ok' => false, 'error' => 'Please select a .docx file']);
        exit;
    }
    $orig = basename($_FILES['template_file']['name']);
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if ($ext !== 'docx') {
        echo json_encode(['ok' => false, 'error' => 'Only .docx allowed']);
        exit;
    }
    if (!move_uploaded_file($_FILES['template_file']['tmp_name'], $filePath)) {
        echo json_encode(['ok' => false, 'error' => 'Upload failed']);
        exit;
    }
    $meta = [
        'name' => $orig,
        'uploaded_by' => (int)($_SESSION['user_id'] ?? 0),
        'uploaded_by_name' => $_SESSION['name'] ?? $_SESSION['username'] ?? 'Admin',
        'updated_at' => date('Y-m-d H:i:s'),
        'size' => filesize($filePath),
    ];
    prg_tpl_write_meta($metaPath, $meta);
    echo json_encode(['ok' => true, 'name' => $orig, 'size' => $meta['size'], 'updated_at' => $meta['updated_at']]);
    exit;
}

// ── Delete (Admin only) ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete') {
    header('Content-Type: application/json; charset=utf-8');
    if ($role !== 'Admin') {
        echo json_encode(['ok' => false, 'error' => 'Admin only']);
        exit;
    }
    if (is_file($filePath)) @unlink($filePath);
    if (is_file($metaPath)) @unlink($metaPath);
    echo json_encode(['ok' => true]);
    exit;
}

// ── Info ─────────────────────────────────────────────────────────
if ($action === 'info') {
    header('Content-Type: application/json; charset=utf-8');
    if (!is_file($readFilePath)) {
        echo json_encode(['ok' => false, 'error' => 'not_found', 'is_admin' => ($role === 'Admin')]);
        exit;
    }
    $meta = prg_tpl_read_meta($readMetaPath);
    $name = $meta['name'] ?? basename($readFilePath);
    $size = filesize($readFilePath);
    $updated = $meta['updated_at'] ?? date('Y-m-d H:i:s', filemtime($readFilePath));
    echo json_encode([
        'ok' => true,
        'name' => $name,
        'size' => $size,
        'updated_at' => $updated,
        'is_admin' => ($role === 'Admin'),
    ]);
    exit;
}

// ── File download (binary for JSZip) ─────────────────────────────
if ($action === 'file') {
    if (!is_file($readFilePath)) {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'not_found']);
        exit;
    }
    $meta = prg_tpl_read_meta($readMetaPath);
    $name = $meta['name'] ?? 'YMR_Photo_Report_Template.docx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Length: ' . filesize($readFilePath));
    header('Content-Disposition: inline; filename="' . rawurlencode($name) . '"');
    header('Cache-Control: private, max-age=60');
    readfile($readFilePath);
    exit;
}

http_response_code(400);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => false, 'error' => 'Unknown action']);
