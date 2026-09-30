<?php
/**
 * Floating Notes API
 * GET  ?action=get          -> { ok: true, note: "..." }
 * POST action=save&note=... -> { ok: true }
 *
 * One row per user (see ensureFloatingNotesTable() in config/config.php) so
 * the same note shows up regardless of which device/browser the user opens
 * it from. Restricted to the same roles as the widget itself (Admin, Super
 * Admin, Surveyor) and always scoped to the calling session's own user_id —
 * there is no way to read or write anyone else's note through this endpoint.
 */
require_once __DIR__ . '/../config/config.php';
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['Admin', 'Super Admin', 'Surveyor'], true)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$userId = (int)$_SESSION['user_id'];
$db = getDB();
ensureFloatingNotesTable($db);

$action = $_GET['action'] ?? $_POST['action'] ?? 'get';

try {
    if ($action === 'save') {
        $note = (string)($_POST['note'] ?? '');
        if (strlen($note) > 200000) {
            $note = substr($note, 0, 200000);
        }
        $stmt = $db->prepare("INSERT INTO floating_notes (user_id, note_text) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE note_text = VALUES(note_text)");
        $stmt->execute([$userId, $note]);
        echo json_encode(['ok' => true]);
        exit;
    }

    $stmt = $db->prepare("SELECT note_text FROM floating_notes WHERE user_id = ?");
    $stmt->execute([$userId]);
    $note = $stmt->fetchColumn();
    echo json_encode(['ok' => true, 'note' => $note !== false ? $note : '']);
} catch (Throwable $e) {
    error_log('ajax/floating_notes.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error']);
}
