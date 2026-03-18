<?php
// share.php – Gestión de colaboradores de importación

require_once __DIR__ . '/includes/config_base.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

Auth::check();
header('Content-Type: application/json');

$action = $_POST['action'] ?? '';
$impId  = (int)($_POST['imp_id'] ?? 0);
$uid    = Auth::uid();

if (!$impId) { echo json_encode(['ok' => false, 'error' => 'No import']); exit; }

// Verificar que el usuario es propietario o admin
$imp = DB::row('SELECT * FROM importaciones WHERE id = ?', [$impId]);
if (!$imp) { echo json_encode(['ok' => false, 'error' => 'Import not found']); exit; }

$esPropietario = ((int)$imp['usuario_id'] === $uid) || Auth::isAdmin();

try {
    if ($action === 'list') {
        $colaboradores = DB::rows(
            'SELECT u.id, u.nombre, u.email
             FROM importacion_colaboradores ic
             JOIN usuarios u ON u.id = ic.usuario_id
             WHERE ic.importacion_id = ?
             ORDER BY u.nombre', [$impId]
        );
        $propietario = DB::row('SELECT id, nombre, email FROM usuarios WHERE id = ?', [$imp['usuario_id']]);
        // Todos los usuarios activos que no son el propietario ni ya colaboradores
        $colaboradoresIds = array_column($colaboradores, 'id');
        $colaboradoresIds[] = (int)$imp['usuario_id'];
        $disponibles = DB::rows(
            'SELECT id, nombre, email FROM usuarios WHERE activo = 1 ORDER BY nombre'
        );
        echo json_encode([
            'ok'            => true,
            'propietario'   => $propietario,
            'colaboradores' => $colaboradores,
            'disponibles'   => $disponibles,
            'es_propietario'=> $esPropietario,
        ]);

    } elseif ($action === 'add' && $esPropietario) {
        $targetUid = (int)($_POST['usuario_id'] ?? 0);
        if (!$targetUid || $targetUid === (int)$imp['usuario_id']) {
            echo json_encode(['ok' => false, 'error' => 'Invalid user']); exit;
        }
        DB::q('INSERT IGNORE INTO importacion_colaboradores (importacion_id, usuario_id) VALUES (?, ?)',
              [$impId, $targetUid]);
        echo json_encode(['ok' => true]);

    } elseif ($action === 'remove' && $esPropietario) {
        $targetUid = (int)($_POST['usuario_id'] ?? 0);
        DB::q('DELETE FROM importacion_colaboradores WHERE importacion_id = ? AND usuario_id = ?',
              [$impId, $targetUid]);
        echo json_encode(['ok' => true]);

    } else {
        echo json_encode(['ok' => false, 'error' => 'No permitido']);
    }
} catch (\Exception $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
