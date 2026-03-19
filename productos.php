<?php
// productos.php – tabla de resultados con paginación, borrado masivo y colaboración

require_once __DIR__ . '/includes/config_base.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/validator.php';
require_once __DIR__ . '/includes/exporter.php';
require_once __DIR__ . '/includes/functions.php';

Auth::check();

// ── Eliminar producto individual ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_product') {
    $delProdId = (int)($_POST['prod_id'] ?? 0);
    $delImpId  = (int)($_POST['imp_id']  ?? 0);
    if ($delProdId && $delImpId) {
        DB::q('DELETE FROM productos WHERE id=? AND importacion_id=?', [$delProdId, $delImpId]);
        $cOk  = (int)DB::row('SELECT COUNT(*) c FROM productos WHERE importacion_id=? AND estado="ok"',   [$delImpId])['c'];
        $cInc = (int)DB::row('SELECT COUNT(*) c FROM productos WHERE importacion_id=? AND estado!="ok"',  [$delImpId])['c'];
        $tot  = (int)DB::row('SELECT COUNT(*) c FROM productos WHERE importacion_id=?', [$delImpId])['c'];
        DB::update('importaciones', ['total'=>$tot,'ok'=>$cOk,'incompletos'=>$cInc], 'id=?', [$delImpId]);
        flash('Producto eliminado.', 'success');
    }
    redirect("productos.php?imp={$delImpId}");
}

// ── Eliminar productos masivamente ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_bulk') {
    $delImpId = (int)($_POST['imp_id'] ?? 0);
    $ids      = array_filter(array_map('intval', (array)($_POST['ids'] ?? [])));
    if ($ids && $delImpId) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        DB::q("DELETE FROM productos WHERE id IN ($ph) AND importacion_id=?",
              array_merge($ids, [$delImpId]));
        $cOk  = (int)DB::row('SELECT COUNT(*) c FROM productos WHERE importacion_id=? AND estado="ok"',   [$delImpId])['c'];
        $cInc = (int)DB::row('SELECT COUNT(*) c FROM productos WHERE importacion_id=? AND estado!="ok"',  [$delImpId])['c'];
        $tot  = (int)DB::row('SELECT COUNT(*) c FROM productos WHERE importacion_id=?', [$delImpId])['c'];
        DB::update('importaciones', ['total'=>$tot,'ok'=>$cOk,'incompletos'=>$cInc], 'id=?', [$delImpId]);
        flash(count($ids) . ' producto(s) eliminado(s).', 'success');
    }
    redirect("productos.php?imp={$delImpId}");
}

$impId = (int)($_GET['imp'] ?? 0);
if (!$impId) redirect('dashboard.php');

// ── Guardar operador de importación ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_operador') {
    $saveImpId = (int)($_POST['imp_id'] ?? 0);
    if ($saveImpId) {
        try {
            DB::update('importaciones', [
                'operador_nombre'    => trim($_POST['operador_nombre']    ?? '') ?: null,
                'operador_direccion' => trim($_POST['operador_direccion'] ?? '') ?: null,
            ], 'id=?', [$saveImpId]);
            flash('Datos del operador guardados.', 'success');
        } catch (\Exception $e) { flash('Error al guardar el operador.', 'error'); }
    }
    redirect("productos.php?imp={$saveImpId}");
}

$imp = DB::row('SELECT i.*, u.nombre AS unom FROM importaciones i JOIN usuarios u ON u.id=i.usuario_id WHERE i.id=?', [$impId]);
if (!$imp) { flash('Importación no encontrada.','error'); redirect('dashboard.php'); }

// Verificar acceso: propietario, colaborador o admin
$uid = Auth::uid();
$isAdmin = Auth::isAdmin();
if (!$isAdmin && (int)$imp['usuario_id'] !== $uid) {
    try {
        $esColab = DB::row('SELECT 1 FROM importacion_colaboradores WHERE importacion_id=? AND usuario_id=?', [$impId, $uid]);
        if (!$esColab) { flash('Sin acceso a esta importación.','error'); redirect('dashboard.php'); }
    } catch(\Exception $e) { /* tabla no existe aún */ }
}
$esPropietario = ((int)$imp['usuario_id'] === $uid) || $isAdmin;

// ── Filtros ──────────────────────────────────────────────────────────────────
$filtroEstado = $_GET['estado'] ?? '';
$filtroTipo   = $_GET['tipo']   ?? '';
$busqueda     = trim($_GET['q'] ?? '');
$pagina       = max(1, (int)($_GET['pag'] ?? 1));
$porPagina    = 20;

$where  = 'WHERE p.importacion_id = ?';
$params = [$impId];
if ($filtroEstado) { $where .= ' AND p.estado = ?';        $params[] = $filtroEstado; }
if ($filtroTipo)   { $where .= ' AND p.tipo_validado = ?'; $params[] = $filtroTipo; }
if ($busqueda)     { $where .= ' AND (p.nombre LIKE ? OR p.referencia LIKE ?)';
                     $params[] = "%$busqueda%"; $params[] = "%$busqueda%"; }

$totalFiltrados = (int)DB::row("SELECT COUNT(*) c FROM productos p $where", $params)['c'];
$totalPaginas   = max(1, (int)ceil($totalFiltrados / $porPagina));
$offset         = ($pagina - 1) * $porPagina;

$productos = DB::rows(
    "SELECT * FROM productos p $where ORDER BY p.estado DESC, p.nombre ASC LIMIT $porPagina OFFSET $offset",
    $params
);

// Contadores
$cOk   = (int)DB::row('SELECT COUNT(*) c FROM productos WHERE importacion_id=? AND estado="ok"',         [$impId])['c'];
$cInc  = (int)DB::row('SELECT COUNT(*) c FROM productos WHERE importacion_id=? AND estado="incompleto"', [$impId])['c'];
$cPend = (int)DB::row('SELECT COUNT(*) c FROM productos WHERE importacion_id=? AND estado="pendiente"',  [$impId])['c'];
$cExp  = (int)DB::row('SELECT COUNT(*) c FROM productos WHERE importacion_id=? AND exportado=1',         [$impId])['c'];

// Bloqueos activos en esta importación
$bloqueos = [];
try {
    DB::q("DELETE FROM producto_bloqueos WHERE TIMESTAMPDIFF(SECOND, updated_at, NOW()) > 90");
    $lockRows = DB::rows('SELECT pb.producto_id, pb.usuario_nombre FROM producto_bloqueos pb
                          JOIN productos p ON p.id = pb.producto_id
                          WHERE p.importacion_id=? AND pb.usuario_id != ?', [$impId, $uid]);
    foreach ($lockRows as $lr) $bloqueos[$lr['producto_id']] = $lr['usuario_nombre'];
} catch(\Exception $e) {}

// Preview descripción final via AJAX
if (isset($_GET['preview_id'])) {
    $p = DB::row('SELECT * FROM productos WHERE id=? AND importacion_id=?', [(int)$_GET['preview_id'], $impId]);
    if ($p) {
        $campos = json_decode($p['campos_json'] ?? '{}', true) ?: [];
        $campos = Exporter::prepararCampos($campos, $imp);
        $tipo   = $p['tipo_validado'] ?? 'otro';
        $bloque = Exporter::generarBloqueAesan($campos, $tipo);
        $final  = Exporter::fusionarDescripcion($p['desc_larga_original'] ?? '', $bloque);
        header('Content-Type: application/json');
        echo json_encode(['html' => $final, 'nombre' => $p['nombre']]);
    }
    exit;
}

layout_start('Productos — ' . $imp['nombre_archivo']);
?>

<!-- Cabecera importación -->
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
  <div>
    <nav aria-label="breadcrumb"><ol class="breadcrumb small mb-0">
      <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/dashboard.php">Dashboard</a></li>
      <li class="breadcrumb-item active"><?= h($imp['nombre_archivo']) ?></li>
    </ol></nav>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <?php if ($isAdmin): ?>
    <span class="badge bg-light text-dark border">Usuario: <?= h($imp['unom']) ?></span>
    <?php endif; ?>
    <span class="badge bg-light text-dark border"><?= date('d/m/Y H:i', strtotime($imp['fecha'])) ?></span>
    <a href="<?= BASE_URL ?>/import.php" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-upload"></i> Nueva importación
    </a>
    <?php if ($esPropietario): ?>
    <button type="button" class="btn btn-sm btn-outline-primary" id="btn-compartir">
      <i class="bi bi-people"></i> Compartir
    </button>
    <?php endif; ?>
    <form method="post" action="<?= BASE_URL ?>/import.php" class="d-inline"
          onsubmit="return confirm('¿Eliminar esta importación y TODOS sus productos? No se puede deshacer.')">
      <input type="hidden" name="action" value="delete_import">
      <input type="hidden" name="imp_id" value="<?= $impId ?>">
      <button type="submit" class="btn btn-sm btn-outline-danger">
        <i class="bi bi-trash3"></i> Eliminar importación
      </button>
    </form>
  </div>
</div>

<!-- Operador responsable de la importación -->
<?php if ($esPropietario): ?>
<div class="card shadow-sm mb-3">
  <div class="card-body py-2 px-3 d-flex flex-wrap align-items-center gap-3">
    <div class="d-flex align-items-center gap-2 flex-grow-1">
      <i class="bi bi-building text-secondary"></i>
      <?php if (!empty($imp['operador_nombre'])): ?>
        <span class="fw-semibold small"><?= h($imp['operador_nombre']) ?></span>
        <?php if (!empty($imp['operador_direccion'])): ?>
          <span class="text-muted small">· <?= h($imp['operador_direccion']) ?></span>
        <?php endif; ?>
      <?php else: ?>
        <span class="text-warning small">
          <i class="bi bi-exclamation-triangle"></i>
          Operador responsable no configurado
          <span class="text-muted">(Art. 9.1.h Reg. UE 1169/2011 — requerido para exportación)</span>
        </span>
      <?php endif; ?>
    </div>
    <button class="btn btn-sm btn-outline-secondary" type="button"
            data-bs-toggle="collapse" data-bs-target="#form-operador">
      <i class="bi bi-pencil"></i> <?= empty($imp['operador_nombre']) ? 'Configurar operador' : 'Editar' ?>
    </button>
  </div>
  <div class="collapse" id="form-operador">
    <div class="card-body border-top py-3 px-3">
      <form method="post" class="row g-2 align-items-end">
        <input type="hidden" name="action"  value="save_operador">
        <input type="hidden" name="imp_id"  value="<?= $impId ?>">
        <div class="col-md-4">
          <label class="form-label small fw-semibold mb-1">Nombre del operador *</label>
          <input type="text" name="operador_nombre" class="form-control form-control-sm"
                 value="<?= h($imp['operador_nombre'] ?? '') ?>"
                 placeholder="Ej: Carnicería García S.L.">
        </div>
        <div class="col-md-5">
          <label class="form-label small fw-semibold mb-1">Dirección</label>
          <input type="text" name="operador_direccion" class="form-control form-control-sm"
                 value="<?= h($imp['operador_direccion'] ?? '') ?>"
                 placeholder="Ej: Calle Mayor 12, 28001 Madrid">
        </div>
        <div class="col-md-3">
          <button type="submit" class="btn btn-primary btn-sm w-100">
            <i class="bi bi-floppy"></i> Guardar operador
          </button>
        </div>
        <div class="col-12">
          <div class="form-text text-muted">
            Este operador se aplicará automáticamente a todos los productos de esta importación en la exportación.
          </div>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Stats rápidos -->
<div class="row g-3 mb-3">
  <?php foreach ([
    ['Completos',   $cOk,   'bi-check-circle',       'verde', 'estado=ok'],
    ['Incompletos', $cInc,  'bi-exclamation-circle',  'rojo',  'estado=incompleto'],
    ['Pendientes',  $cPend, 'bi-clock',               '',      'estado=pendiente'],
    ['Exportados',  $cExp,  'bi-download',            'nara',  ''],
  ] as [$lbl, $n, $ico, $cls, $qp]): ?>
  <div class="col-6 col-md-3">
    <<?= $qp ? "a href=\"?imp={$impId}&{$qp}\"" : "div" ?> class="stat-card <?= $cls ?> d-flex align-items-center gap-3 text-decoration-none">
      <i class="bi <?= $ico ?>" style="font-size:1.8rem;opacity:.5"></i>
      <div>
        <div class="stat-num"><?= $n ?></div>
        <div class="text-muted small"><?= $lbl ?></div>
      </div>
    </<?= $qp ? 'a' : 'div' ?>>
  </div>
  <?php endforeach; ?>
</div>

<!-- Filtros -->
<form class="row g-2 mb-3" method="get">
  <input type="hidden" name="imp" value="<?= $impId ?>">
  <div class="col-md-4">
    <input type="text" name="q" class="form-control form-control-sm"
           placeholder="🔍 Buscar por nombre o referencia…" value="<?= h($busqueda) ?>">
  </div>
  <div class="col-md-3">
    <select name="estado" class="form-select form-select-sm">
      <option value="">Todos los estados</option>
      <option value="ok"          <?= $filtroEstado==='ok'?'selected':'' ?>>✔ Completos</option>
      <option value="incompleto"  <?= $filtroEstado==='incompleto'?'selected':'' ?>>✘ Incompletos</option>
      <option value="pendiente"   <?= $filtroEstado==='pendiente'?'selected':'' ?>>⏳ Pendientes</option>
    </select>
  </div>
  <div class="col-md-3">
    <select name="tipo" class="form-select form-select-sm">
      <option value="">Todos los tipos</option>
      <?php foreach (Validator::TIPOS as $k => $v): ?>
      <option value="<?= $k ?>" <?= $filtroTipo===$k?'selected':'' ?>><?= h($v) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-2 d-flex gap-1">
    <button type="submit" class="btn btn-sm btn-primary flex-fill">Filtrar</button>
    <?php if ($busqueda || $filtroEstado || $filtroTipo): ?>
    <a href="?imp=<?= $impId ?>" class="btn btn-sm btn-outline-secondary">✕</a>
    <?php endif; ?>
  </div>
</form>

<!-- Exportar todos los completos (fuera de la paginación) -->
<?php if ($cOk > 0): ?>
<form method="post" action="<?= BASE_URL ?>/export.php" class="mb-2">
  <input type="hidden" name="imp_id"      value="<?= $impId ?>">
  <input type="hidden" name="export_all"  value="1">
  <button type="submit" class="btn btn-success">
    <i class="bi bi-download"></i>
    Exportar todos los completos
    <span class="badge bg-white text-success ms-1"><?= $cOk ?></span>
  </button>
  <span class="text-muted small ms-2">Exporta los <?= $cOk ?> producto(s) al 100% sin importar la página en que estén.</span>
</form>
<?php endif; ?>

<!-- Tabla + exportación por selección -->
<form id="form-exportar" method="post" action="<?= BASE_URL ?>/export.php">
  <input type="hidden" name="imp_id" value="<?= $impId ?>">
  <div class="card shadow-sm">
    <div class="card-header bg-white d-flex justify-content-between align-items-center gap-2 flex-wrap">
      <div class="d-flex align-items-center gap-2">
        <input type="checkbox" id="sel-all" class="form-check-input">
        <label for="sel-all" class="form-check-label fw-semibold mb-0">Seleccionar todo</label>
        <span class="text-muted small ms-1">(<?= $totalFiltrados ?> resultados)</span>
      </div>
      <div class="d-flex gap-2">
        <button type="submit" id="btn-exportar" class="btn btn-success btn-sm" disabled>
          <i class="bi bi-download"></i> Exportar seleccionados
        </button>
        <button type="button" id="btn-eliminar-sel" class="btn btn-danger btn-sm" disabled>
          <i class="bi bi-trash3"></i> Eliminar seleccionados
        </button>
      </div>
    </div>

    <div class="table-responsive">
      <table class="table table-hover tabla-productos mb-0 small">
        <thead>
          <tr>
            <th width="36"></th>
            <th>ID PS</th>
            <th>Referencia</th>
            <th>Nombre</th>
            <th>Tipo</th>
            <th style="min-width:130px">Completado</th>
            <th>Estado</th>
            <th width="110" class="text-center">Acciones</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$productos): ?>
          <tr><td colspan="8" class="text-center text-muted py-4">
            No hay productos con estos filtros.
          </td></tr>
          <?php endif; ?>
          <?php foreach ($productos as $p):
            $pct      = porcentajeCompletado($p);
            $pctClass = $pct < 40 ? 'bajo' : ($pct < 80 ? 'medio' : '');
            $trClass  = $p['estado']==='ok' ? 'estado-ok' : ($p['estado']==='incompleto'?'estado-incompleto':'');
            $val      = Validator::validar($p);
            $locked   = isset($bloqueos[(int)$p['id']]);
          ?>
          <tr class="<?= $trClass ?>">
            <td>
              <input type="checkbox" class="form-check-input sel-producto"
                     name="ids[]" value="<?= $p['id'] ?>">
            </td>
            <td class="text-muted"><?= h($p['ps_id'] ?? '–') ?></td>
            <td><code><?= h($p['referencia'] ?? '–') ?></code></td>
            <td>
              <strong><?= h($p['nombre']) ?></strong>
              <?php if ($p['exportado']): ?>
              <span class="badge bg-light text-dark border ms-1" title="Ya exportado">
                <i class="bi bi-check2-all"></i>
              </span>
              <?php endif; ?>
              <?php if ($locked): ?>
              <span class="badge bg-warning text-dark ms-1"
                    title="Editando: <?= h($bloqueos[(int)$p['id']]) ?>">
                <i class="bi bi-lock-fill"></i> <?= h($bloqueos[(int)$p['id']]) ?>
              </span>
              <?php endif; ?>
            </td>
            <td><?= tipoBadge($p['tipo_validado']) ?></td>
            <td>
              <div class="prog-wrap mb-1">
                <div class="prog-bar <?= $pctClass ?>" style="width:<?= $pct ?>%"></div>
              </div>
              <span class="text-muted"><?= $pct ?>%</span>
            </td>
            <td>
              <?= estadoBadge($p['estado']) ?>
              <?php foreach (array_slice($val['faltan_criticos'],0,2) as $f): ?>
              <div class="text-danger" style="font-size:.75rem">
                <i class="bi bi-exclamation-circle"></i> <?= h($f) ?>
              </div>
              <?php endforeach; ?>
              <?php if (count($val['faltan_criticos'])>2): ?>
              <div class="text-muted" style="font-size:.75rem">
                +<?= count($val['faltan_criticos'])-2 ?> más…
              </div>
              <?php endif; ?>
            </td>
            <td class="text-center">
              <div class="btn-group btn-group-sm">
                <a href="<?= BASE_URL ?>/producto.php?id=<?= $p['id'] ?>&imp=<?= $impId ?>"
                   class="btn btn-outline-primary" title="<?= $locked?'Ver (en edición)':'Editar' ?>">
                  <i class="bi bi-<?= $locked?'eye':'pencil' ?>"></i>
                </a>
                <button type="button" class="btn btn-outline-secondary btn-preview-desc"
                        data-id="<?= $p['id'] ?>" data-imp="<?= $impId ?>"
                        title="Vista previa descripción final">
                  <i class="bi bi-eye"></i>
                </button>
                <button type="button" class="btn btn-outline-danger btn-delete-prod"
                        data-id="<?= $p['id'] ?>" data-nombre="<?= h($p['nombre']) ?>"
                        title="Eliminar producto">
                  <i class="bi bi-trash3"></i>
                </button>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Paginación -->
    <?php if ($totalPaginas > 1): ?>
    <div class="card-footer bg-white d-flex justify-content-between align-items-center">
      <span class="text-muted small">
        Página <?= $pagina ?> de <?= $totalPaginas ?>
        (<?= $totalFiltrados ?> resultados)
      </span>
      <nav>
        <ul class="pagination pagination-sm mb-0">
          <?php
          $qs = http_build_query(['imp'=>$impId,'estado'=>$filtroEstado,'tipo'=>$filtroTipo,'q'=>$busqueda]);
          for ($pp=1; $pp<=$totalPaginas; $pp++):
            $active = $pp===$pagina ? 'active' : '';
          ?>
          <li class="page-item <?= $active ?>">
            <a class="page-link" href="?<?= $qs ?>&pag=<?= $pp ?>"><?= $pp ?></a>
          </li>
          <?php endfor; ?>
        </ul>
      </nav>
    </div>
    <?php endif; ?>
  </div>
</form>

<!-- Modal Preview Descripción Final -->
<div class="modal fade" id="modalPreview" tabindex="-1">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-eye"></i> Vista previa — <span id="preview-nombre"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <ul class="nav nav-tabs mb-3" id="previewTabs">
          <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-render">Vista</button></li>
          <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-html">HTML</button></li>
        </ul>
        <div class="tab-content">
          <div class="tab-pane fade show active" id="tab-render">
            <div id="preview-render" class="p-2 border rounded bg-white"></div>
          </div>
          <div class="tab-pane fade" id="tab-html">
            <textarea id="preview-html" class="form-control font-monospace" rows="20" readonly style="font-size:.8rem"></textarea>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal Compartir Importación -->
<div class="modal fade" id="modalCompartir" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-people"></i> Compartir importación</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted small mb-3">
          Los colaboradores pueden ver y editar todos los productos de esta importación.
        </p>
        <div id="share-propietario" class="mb-3"></div>
        <div id="share-lista" class="mb-3"></div>
        <hr>
        <label class="form-label fw-semibold">Añadir colaborador</label>
        <select id="share-select" class="form-select form-select-sm mb-2">
          <option value="">— Selecciona un usuario —</option>
        </select>
        <button type="button" class="btn btn-primary btn-sm" id="btn-share-add">
          <i class="bi bi-person-plus"></i> Añadir
        </button>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>

<!-- Formulario oculto para borrar producto -->
<form id="form-delete-prod" method="post" style="display:none">
  <input type="hidden" name="action"  value="delete_product">
  <input type="hidden" name="imp_id"  value="<?= $impId ?>">
  <input type="hidden" name="prod_id" id="del-prod-id" value="">
</form>

<!-- Formulario oculto para borrado masivo -->
<form id="form-delete-bulk" method="post" style="display:none">
  <input type="hidden" name="action"  value="delete_bulk">
  <input type="hidden" name="imp_id"  value="<?= $impId ?>">
  <div id="bulk-ids-container"></div>
</form>

<script>
const BASE_URL = '<?= BASE_URL ?>';
const IMP_ID   = <?= $impId ?>;

// ── Checkboxes → habilitar/deshabilitar botones ───────────────────────────────
const selAll       = document.getElementById('sel-all');
const btnExportar  = document.getElementById('btn-exportar');
const btnEliminar  = document.getElementById('btn-eliminar-sel');

function updateBulkButtons() {
  const checked = document.querySelectorAll('.sel-producto:checked');
  const n = checked.length;
  btnExportar.disabled = n === 0;
  btnEliminar.disabled = n === 0;
  if (n > 0) {
    btnEliminar.textContent = '';
    btnEliminar.innerHTML = `<i class="bi bi-trash3"></i> Eliminar ${n} seleccionado(s)`;
    btnExportar.innerHTML = `<i class="bi bi-download"></i> Exportar ${n} seleccionado(s)`;
  } else {
    btnEliminar.innerHTML = '<i class="bi bi-trash3"></i> Eliminar seleccionados';
    btnExportar.innerHTML = '<i class="bi bi-download"></i> Exportar seleccionados';
  }
}

document.querySelectorAll('.sel-producto').forEach(cb => {
  cb.addEventListener('change', updateBulkButtons);
});

selAll?.addEventListener('change', () => {
  document.querySelectorAll('.sel-producto').forEach(cb => cb.checked = selAll.checked);
  updateBulkButtons();
});

// ── Borrado masivo ────────────────────────────────────────────────────────────
btnEliminar?.addEventListener('click', () => {
  const checked = [...document.querySelectorAll('.sel-producto:checked')];
  if (!checked.length) return;
  if (!confirm(`¿Eliminar ${checked.length} producto(s)?\nEsta acción no se puede deshacer.`)) return;

  const container = document.getElementById('bulk-ids-container');
  container.innerHTML = '';
  checked.forEach(cb => {
    const inp = document.createElement('input');
    inp.type  = 'hidden';
    inp.name  = 'ids[]';
    inp.value = cb.value;
    container.appendChild(inp);
  });
  document.getElementById('form-delete-bulk').submit();
});

// ── Borrar producto individual ────────────────────────────────────────────────
document.querySelectorAll('.btn-delete-prod').forEach(btn => {
  btn.addEventListener('click', () => {
    if (!confirm(`¿Eliminar el producto "${btn.dataset.nombre}"?\nEsta acción no se puede deshacer.`)) return;
    document.getElementById('del-prod-id').value = btn.dataset.id;
    document.getElementById('form-delete-prod').submit();
  });
});

// ── Preview descripción final ─────────────────────────────────────────────────
document.querySelectorAll('.btn-preview-desc').forEach(btn => {
  btn.addEventListener('click', () => {
    const id  = btn.dataset.id;
    const imp = btn.dataset.imp;
    fetch(`${BASE_URL}/productos.php?imp=${imp}&preview_id=${id}`)
      .then(r => r.json())
      .then(d => {
        document.getElementById('preview-nombre').textContent = d.nombre;
        document.getElementById('preview-render').innerHTML   = d.html;
        document.getElementById('preview-html').value         = d.html;
        new bootstrap.Modal(document.getElementById('modalPreview')).show();
      });
  });
});

// ── Modal Compartir ───────────────────────────────────────────────────────────
function loadShareData() {
  const fd = new FormData();
  fd.append('action', 'list');
  fd.append('imp_id', IMP_ID);
  fetch(`${BASE_URL}/share.php`, { method:'POST', body:fd })
    .then(r => r.json())
    .then(d => {
      if (!d.ok) return;

      // Propietario
      document.getElementById('share-propietario').innerHTML =
        `<div class="d-flex align-items-center gap-2 p-2 bg-light rounded">
           <i class="bi bi-person-badge-fill text-primary"></i>
           <span><strong>Propietario:</strong> ${d.propietario?.nombre || '–'}</span>
         </div>`;

      // Lista de colaboradores
      const lista = document.getElementById('share-lista');
      if (!d.colaboradores.length) {
        lista.innerHTML = '<p class="text-muted small">Sin colaboradores por ahora.</p>';
      } else {
        lista.innerHTML = '<label class="form-label fw-semibold">Colaboradores actuales</label>' +
          d.colaboradores.map(u =>
            `<div class="d-flex align-items-center gap-2 py-1 border-bottom">
               <i class="bi bi-person text-success"></i>
               <span class="flex-grow-1">${u.nombre} <span class="text-muted small">${u.email}</span></span>
               ${d.es_propietario
                 ? `<button class="btn btn-xs btn-outline-danger" style="font-size:.7rem;padding:1px 6px"
                            onclick="removeColaborador(${u.id})"><i class="bi bi-x"></i></button>`
                 : ''}
             </div>`
          ).join('');
      }

      // Select de disponibles
      const sel = document.getElementById('share-select');
      const colaboradoresIds = d.colaboradores.map(u => u.id);
      const propId = d.propietario?.id;
      sel.innerHTML = '<option value="">— Selecciona un usuario —</option>' +
        d.disponibles
          .filter(u => u.id !== propId && !colaboradoresIds.includes(u.id))
          .map(u => `<option value="${u.id}">${u.nombre} (${u.email})</option>`)
          .join('');

      // Mostrar/ocultar botón añadir
      document.getElementById('btn-share-add').style.display = d.es_propietario ? '' : 'none';
    })
    .catch(() => {});
}

document.getElementById('btn-compartir')?.addEventListener('click', () => {
  loadShareData();
  new bootstrap.Modal(document.getElementById('modalCompartir')).show();
});

document.getElementById('btn-share-add')?.addEventListener('click', () => {
  const uid = document.getElementById('share-select').value;
  if (!uid) return;
  const fd = new FormData();
  fd.append('action', 'add');
  fd.append('imp_id', IMP_ID);
  fd.append('usuario_id', uid);
  fetch(`${BASE_URL}/share.php`, { method:'POST', body:fd })
    .then(r => r.json())
    .then(d => { if (d.ok) loadShareData(); });
});

function removeColaborador(uid) {
  const fd = new FormData();
  fd.append('action', 'remove');
  fd.append('imp_id', IMP_ID);
  fd.append('usuario_id', uid);
  fetch(`${BASE_URL}/share.php`, { method:'POST', body:fd })
    .then(r => r.json())
    .then(d => { if (d.ok) loadShareData(); });
}
</script>

<?php layout_end(); ?>
