<?php
// lock.php – Gestión de bloqueos de edición (heartbeat y release)

require_once __DIR__ . '/includes/config_base.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

Auth::check();
header('Content-Type: application/json');

$action  = $_POST['action'] ?? '';
$prodId  = (int)($_POST['producto_id'] ?? 0);
$uid     = Auth::uid();

if (!$prodId) { echo json_encode(['ok' => false]); exit; }

try {
    // Limpiar bloqueos expirados (> 90 s sin heartbeat)
    DB::q("DELETE FROM producto_bloqueos WHERE TIMESTAMPDIFF(SECOND, updated_at, NOW()) > 90");

    if ($action === 'heartbeat') {
        DB::q("UPDATE producto_bloqueos SET updated_at = NOW()
               WHERE producto_id = ? AND usuario_id = ?", [$prodId, $uid]);
        echo json_encode(['ok' => true]);

    } elseif ($action === 'release') {
        DB::q("DELETE FROM producto_bloqueos WHERE producto_id = ? AND usuario_id = ?",
              [$prodId, $uid]);
        echo json_encode(['ok' => true]);

    } else {
        echo json_encode(['ok' => false, 'error' => 'unknown action']);
    }
} catch (\Exception $e) {
    echo json_encode(['ok' => false]);
}
