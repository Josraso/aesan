<?php
// import.php – Importar Excel (usa SimpleXLSX, sin Composer)

require_once __DIR__ . '/includes/config_base.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/validator.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/SimpleXLSX.php';
require_once __DIR__ . '/includes/SimpleXLS.php';

// ── Descarga plantilla CSV de ejemplo ────────────────────────────────────────
if (isset($_GET['plantilla'])) {
    generarPlantilla();
    exit;
}

// ── Procesar subida ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['excel']['name'])) {

    $file = $_FILES['excel'];
    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if (!in_array($ext, ['xlsx','xls','csv'])) {
        flash('Formato no permitido. Usa .xlsx, .xls o .csv', 'error');
        redirect('import.php');
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        flash('Error al subir el archivo (código ' . $file['error'] . ').', 'error');
        redirect('import.php');
    }

    $destDir = __DIR__ . '/uploads/';
    if (!is_dir($destDir)) mkdir($destDir, 0755, true);
    $destName = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $file['name']);
    $destPath = $destDir . $destName;
    move_uploaded_file($file['tmp_name'], $destPath);

    try {
        $rawRows = match($ext) {
            'csv'  => leerCSV($destPath),
            'xls'  => leerXLS($destPath),
            default=> leerXLSX($destPath),
        };

        if (!$rawRows) {
            flash('El archivo está vacío o no se pudo leer.', 'error');
            redirect('import.php');
        }

        [$headerIdx, $colMap] = detectarCabecera($rawRows);

        if (!isset($colMap['nombre'])) {
            flash('No se encontró la columna "Nombre" en el archivo. Revisa el formato o usa la plantilla.', 'error');
            redirect('import.php');
        }

        $impId = DB::insert('importaciones', [
            'usuario_id'     => Auth::uid(),
            'nombre_archivo' => $file['name'],
            'total'          => 0, 'ok' => 0, 'incompletos' => 0,
        ]);

        $total = $ok = $inc = 0;

        foreach ($rawRows as $ri => $row) {
            if ($ri <= $headerIdx) continue;
            $nombre = trim((string)($row[$colMap['nombre']] ?? ''));
            if (!$nombre) continue;

            $psId      = trim((string)($row[$colMap['ps_id']]     ?? ''));
            $ref       = trim((string)($row[$colMap['referencia']] ?? ''));
            $descCorta = trim((string)($row[$colMap['desc_corta']] ?? ''));
            $descLarga = trim((string)($row[$colMap['desc_larga']] ?? ''));

            $tipoDetect = Validator::detectarTipo($nombre, $descCorta, $descLarga);
            $especieDet = Validator::detectarEspecie($nombre, $descCorta . ' ' . strip_tags($descLarga));

            $campos = ['especie' => $especieDet];
            $textoPlano = strtolower(strip_tags($descLarga . ' ' . $descCorta));

            if (preg_match('/(conservar\s+(?:entre\s+)?[\+\-]?\d+[^.<]{0,60})/i', $textoPlano, $m))
                $campos['conservacion'] = ucfirst(trim($m[1]));

            if (preg_match('/ingredientes\s*:\s*([^<\n]{10,400})/i', strip_tags($descLarga), $m)) {
                $campos['ingredientes']    = trim($m[1]);
                $campos['alergenos_lista'] = implode(',', Validator::detectarAlergenos($campos['ingredientes']));
            }

            $prodData = ['tipo_validado'=>$tipoDetect,'tipo_detectado'=>$tipoDetect,'campos_json'=>json_encode($campos)];
            $val    = Validator::validar($prodData);
            $estado = $val['estado'];

            DB::insert('productos', [
                'importacion_id'      => $impId,
                'ps_id'               => $psId,
                'referencia'          => $ref,
                'nombre'              => $nombre,
                'tipo_detectado'      => $tipoDetect,
                'tipo_validado'       => $tipoDetect,
                'desc_corta_original' => $descCorta,
                'desc_larga_original' => $descLarga,
                'estado'              => $estado,
                'campos_json'         => json_encode($campos),
            ]);

            $total++;
            $estado === 'ok' ? $ok++ : $inc++;
        }

        DB::update('importaciones', ['total'=>$total,'ok'=>$ok,'incompletos'=>$inc], 'id=?', [$impId]);
        flash("Importados {$total} productos. {$ok} completos, {$inc} requieren revisión.", 'success');
        redirect("productos.php?imp={$impId}");

    } catch (Exception $e) {
        flash('Error al procesar: ' . $e->getMessage(), 'error');
        redirect('import.php');
    }
}

// ── Funciones lectura ────────────────────────────────────────────────────────
function leerXLSX(string $path): array {
    $xlsx = SimpleXLSX::parse($path);
    if (!$xlsx) throw new Exception('No se pudo leer XLSX: ' . SimpleXLSX::parseError());
    $rows = [];
    foreach ($xlsx->rows(0) as $i => $row) $rows[$i] = array_values($row);
    return $rows;
}
function leerXLS(string $path): array {
    $xls = SimpleXLS::parse($path);
    if (!$xls) throw new Exception('No se pudo leer XLS.');
    $rows = [];
    foreach ($xls->rows(0) as $i => $row) $rows[$i] = array_values($row);
    return $rows;
}
function leerCSV(string $path): array {
    $sample = file_get_contents($path, false, null, 0, 2048);
    $delim  = substr_count($sample, ';') >= substr_count($sample, ',') ? ';' : ',';
    $rows   = [];
    $handle = fopen($path, 'r');
    $i = 0;
    // Saltar BOM
    $bom = fread($handle, 3);
    if ($bom !== "\xef\xbb\xbf") rewind($handle);
    while (($row = fgetcsv($handle, 0, $delim)) !== false) $rows[$i++] = array_values($row);
    fclose($handle);
    return $rows;
}
function detectarCabecera(array $rows): array {
    $mapa = [
        'ps_id'      => ['id','id_producto','id producto','product id','id ps'],
        'referencia' => ['referencia','ref','sku','reference','código','codigo','codi'],
        'nombre'     => ['nombre','name','producto','product name','nom'],
        'desc_corta' => ['descripcion corta','descripción corta','short description','resumen','summary'],
        'desc_larga' => ['descripcion','descripcion larga','descripción larga','description','long description','contenido','desc'],
    ];
    foreach ($rows as $ri => $row) {
        $colMap = [];
        foreach ($row as $ci => $cell) {
            $val = strtolower(trim(strip_tags((string)$cell)));
            foreach ($mapa as $campo => $variantes) {
                if (!isset($colMap[$campo]) && in_array($val, $variantes)) $colMap[$campo] = $ci;
            }
        }
        if (isset($colMap['nombre'])) return [$ri, $colMap];
    }
    return [0, ['ps_id'=>0,'referencia'=>1,'nombre'=>2,'desc_corta'=>3,'desc_larga'=>4]];
}
function generarPlantilla(): void {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="plantilla_aesan_prestashop.csv"');
    echo "\xEF\xBB\xBF";
    $f = fopen('php://output','w');
    fputcsv($f,['ID','Referencia','Nombre','Descripcion corta','Descripcion larga'],';');
    fputcsv($f,['123','BURG-001','Burger Meat de Chuletón','Hamburguesa de vacuno viejo madurado','<p>Auténtica hamburguesa de chuletón.</p>'],';');
    fputcsv($f,['124','CHUL-001','Chuletón de Vaca Frisona','Chuletón de vaca vieja madurada. ORIGEN: ESPAÑA','<p>Selección especial de vaca frisona.</p>'],';');
    fputcsv($f,['125','SOL-001','Solomillo de Vaca Pepechu','Solomillo de vaca vieja selección extra','<p>Solomillo de Vaca Vieja, Selección Pepechu.</p>'],';');
    fclose($f);
}

layout_start('Importar Excel');
$recientes = DB::rows(
    "SELECT i.* FROM importaciones i WHERE i.usuario_id=? ORDER BY i.fecha DESC LIMIT 5",
    [Auth::uid()]
);
?>

<div class="row g-4">
  <div class="col-lg-7">
    <div class="card shadow-sm mb-4">
      <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <span class="fw-bold"><i class="bi bi-upload"></i> Subir archivo</span>
        <a href="?plantilla=1" class="btn btn-sm btn-outline-success">
          <i class="bi bi-file-earmark-arrow-down"></i> Descargar plantilla CSV
        </a>
      </div>
      <div class="card-body">
        <p class="text-muted small mb-3">
          Sube el Excel exportado desde PrestaShop o usa la plantilla descargable.
          <strong>No requiere instalar nada</strong> en el servidor.
          Formatos: <strong>.xlsx &nbsp;.xls &nbsp;.csv</strong>
        </p>
        <form method="post" enctype="multipart/form-data">
          <div id="dropzone" class="dropzone mb-3">
            <i class="bi bi-file-earmark-excel d-block mb-2" style="font-size:2.5rem;color:#adb5bd"></i>
            <span class="dz-label fw-semibold">Arrastra tu archivo aquí o haz clic</span>
            <p class="text-muted small mt-1 mb-0">Máximo 10 MB</p>
            <input type="file" id="excel-input" name="excel" accept=".xlsx,.xls,.csv" class="d-none" required>
          </div>
          <button type="submit" class="btn btn-primary w-100 fw-bold py-2">
            <i class="bi bi-play-circle"></i> Procesar e importar
          </button>
        </form>
      </div>
    </div>

    <?php if ($recientes): ?>
    <div class="card shadow-sm">
      <div class="card-header bg-white fw-semibold"><i class="bi bi-clock-history"></i> Últimas importaciones</div>
      <div class="list-group list-group-flush">
        <?php foreach ($recientes as $r): ?>
        <a href="<?= BASE_URL ?>/productos.php?imp=<?= $r['id'] ?>"
           class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
          <div>
            <i class="bi bi-file-earmark-excel text-success"></i>
            <strong class="ms-1"><?= h($r['nombre_archivo']) ?></strong>
            <span class="text-muted small ms-2"><?= date('d/m/Y H:i', strtotime($r['fecha'])) ?></span>
          </div>
          <div>
            <span class="badge bg-success me-1"><?= $r['ok'] ?> ✔</span>
            <span class="badge bg-danger"><?= $r['incompletos'] ?> ✘</span>
          </div>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <div class="col-lg-5">
    <div class="card shadow-sm mb-3">
      <div class="card-header bg-white fw-semibold"><i class="bi bi-info-circle text-primary"></i> Exportar desde PrestaShop</div>
      <div class="card-body small">
        <ol class="ps-3 mb-0">
          <li class="mb-1">Ve a <strong>Catálogo → Productos</strong></li>
          <li class="mb-1">Clic en <strong>Exportar</strong> (arriba a la derecha)</li>
          <li class="mb-1">Incluye: <code>ID, Reference, Name, Short description, Description</code></li>
          <li class="mb-1">Descarga el <strong>.xlsx</strong> o <strong>.csv</strong></li>
          <li>Súbelo aquí directamente</li>
        </ol>
        <hr class="my-2">
        <p class="mb-0 text-muted">O usa la plantilla CSV de ejemplo si quieres empezar desde cero.</p>
      </div>
    </div>
    <div class="card shadow-sm">
      <div class="card-header bg-white fw-semibold"><i class="bi bi-table text-success"></i> Nombres de columna reconocidos</div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0 small">
          <thead class="table-light"><tr><th>Campo</th><th>Variantes aceptadas</th></tr></thead>
          <tbody>
            <tr><td><code>ID</code></td><td>ID, id_producto, Product ID</td></tr>
            <tr><td><code>Referencia</code></td><td>Referencia, Ref, SKU, Reference</td></tr>
            <tr><td><code>Nombre</code></td><td>Nombre, Name, Producto, Product name</td></tr>
            <tr><td><code>Desc. corta</code></td><td>Descripcion corta, Short description, Resumen</td></tr>
            <tr><td><code>Desc. larga</code></td><td>Descripcion, Descripcion larga, Description</td></tr>
          </tbody>
        </table>
      </div>
      <div class="card-footer text-muted small">Sin cabecera reconocida: A=ID, B=Ref, C=Nombre, D=Desc.corta, E=Desc.larga</div>
    </div>
  </div>
</div>

<?php layout_end(); ?>
