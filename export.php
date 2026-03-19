<?php
// export.php – genera y descarga el CSV para PrestaShop

require_once __DIR__ . '/includes/config_base.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/exporter.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/historial.php';

Auth::check();

$ids       = array_map('intval', $_POST['ids']      ?? []);
$impId     = (int)($_POST['imp_id']    ?? 0);
$exportAll = !empty($_POST['export_all']);

if (!$impId) {
    flash('No hay productos seleccionados.', 'error');
    redirect('dashboard.php');
}

if ($exportAll) {
    // Exportar TODOS los productos completos de la importación (ignora paginación)
    $prods = DB::rows(
        "SELECT * FROM productos WHERE importacion_id=? AND estado='ok' ORDER BY nombre",
        [$impId]
    );
} else {
    if (!$ids) {
        flash('No hay productos seleccionados.', 'error');
        redirect("productos.php?imp={$impId}");
    }
    $in    = implode(',', $ids);
    $prods = DB::rows(
        "SELECT * FROM productos WHERE id IN ($in) AND importacion_id=? AND estado='ok'",
        [$impId]
    );
}

if (!$prods) {
    flash('Los productos seleccionados no están completos o no pertenecen a esta importación.', 'warning');
    redirect("productos.php?imp={$impId}");
}

// Marcar como exportados y registrar en historial
foreach ($prods as $p) {
    DB::update('productos', ['exportado' => 1], 'id=?', [$p['id']]);
    Historial::registrar($p['id'], Auth::uid(), 'exportado', 'Exportado al CSV de PrestaShop');
}

// Importación para heredar operador
$importRow = DB::row('SELECT * FROM importaciones WHERE id=?', [$impId]) ?: [];

// Generar CSV
$csv      = Exporter::exportarCSV($prods, $importRow);
$filename = 'prestashop_aesan_' . date('Ymd_His') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

echo "\xEF\xBB\xBF"; // BOM para Excel en Windows
echo $csv;
exit;
