<?php
// productos.php – tabla de resultados con paginación y preview

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
        // Actualizar contadores de la importación
        $cOk  = (int)DB::row('SELECT COUNT(*) c FROM productos WHERE importacion_id=? AND estado="ok"',   [$delImpId])['c'];
        $cInc = (int)DB::row('SELECT COUNT(*) c FROM productos WHERE importacion_id=? AND estado!="ok"',  [$delImpId])['c'];
        $tot  = (int)DB::row('SELECT COUNT(*) c FROM productos WHERE importacion_id=?', [$delImpId])['c'];
        DB::update('importaciones', ['total'=>$tot,'ok'=>$cOk,'incompletos'=>$cInc], 'id=?', [$delImpId]);
        flash('Producto eliminado.', 'success');
    }
    redirect("productos.php?imp={$delImpId}");
}

$impId = (int)($_GET['imp'] ?? 0);
if (!$impId) redirect('dashboard.php');

$imp = DB::row('SELECT i.*, u.nombre AS unom FROM importaciones i JOIN usuarios u ON u.id=i.usuario_id WHERE i.id=?', [$impId]);
if (!$imp) { flash('Importación no encontrada.','error'); redirect('dashboard.php'); }

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

// Preview descripción final via AJAX
if (isset($_GET['preview_id'])) {
    $p = DB::row('SELECT * FROM productos WHERE id=? AND importacion_id=?', [(int)$_GET['preview_id'], $impId]);
    if ($p) {
        $campos = json_decode($p['campos_json'] ?? '{}', true) ?: [];
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
  <div class="d-flex gap-2">
    <?php if ($isAdmin = Auth::isAdmin()): ?>
    <span class="badge bg-light text-dark border">Usuario: <?= h($imp['unom']) ?></span>
    <?php endif; ?>
    <span class="badge bg-light text-dark border"><?= date('d/m/Y H:i', strtotime($imp['fecha'])) ?></span>
    <a href="<?= BASE_URL ?>/import.php" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-upload"></i> Nueva importación
    </a>
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

<!-- Stats rápidos -->
<div class="row g-2 mb-3">
  <?php foreach ([
    ['Completos',   $cOk,   'success', 'estado=ok'],
    ['Incompletos', $cInc,  'danger',  'estado=incompleto'],
    ['Pendientes',  $cPend, 'secondary','estado=pendiente'],
    ['Exportados',  $cExp,  'info',    ''],
  ] as [$lbl, $n, $color, $qp]): ?>
  <div class="col-6 col-md-3">
    <<?= $qp ? "a href=\"?imp={$impId}&{$qp}\"" : "div" ?> class="stat-card text-center p-2 text-decoration-none <?= $color==='success'?'verde':($color==='danger'?'rojo':($color==='warning'?'nara':'')) ?>">
      <div class="fw-bold fs-4 text-<?= $color ?>"><?= $n ?></div>
      <div class="small text-muted"><?= $lbl ?></div>
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

<!-- Tabla + exportación -->
<form id="form-exportar" method="post" action="<?= BASE_URL ?>/export.php">
  <input type="hidden" name="imp_id" value="<?= $impId ?>">
  <div class="card shadow-sm">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
      <div class="d-flex align-items-center gap-2">
        <input type="checkbox" id="sel-all" class="form-check-input">
        <label for="sel-all" class="form-check-label fw-semibold mb-0">Seleccionar todo</label>
        <span class="text-muted small ms-1">(<?= $totalFiltrados ?> resultados)</span>
      </div>
      <button type="submit" id="btn-exportar" class="btn btn-success btn-sm" disabled>
        <i class="bi bi-download"></i> Exportar seleccionados
      </button>
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
            <th width="100" class="text-center">Acciones</th>
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
          ?>
          <tr class="<?= $trClass ?>">
            <td>
              <?php if ($p['estado']==='ok'): ?>
              <input type="checkbox" class="form-check-input sel-producto"
                     name="ids[]" value="<?= $p['id'] ?>">
              <?php else: ?>
              <input type="checkbox" class="form-check-input" disabled
                     title="Completa el producto para poder exportarlo">
              <?php endif; ?>
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
                   class="btn btn-outline-primary" title="Editar">
                  <i class="bi bi-pencil"></i>
                </a>
                <?php if ($p['estado']==='ok'): ?>
                <button type="button" class="btn btn-outline-secondary btn-preview-desc"
                        data-id="<?= $p['id'] ?>" data-imp="<?= $impId ?>"
                        title="Vista previa descripción final">
                  <i class="bi bi-eye"></i>
                </button>
                <?php endif; ?>
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

<!-- Formulario oculto para borrar producto -->
<form id="form-delete-prod" method="post" style="display:none">
  <input type="hidden" name="action"  value="delete_product">
  <input type="hidden" name="imp_id"  value="<?= $impId ?>">
  <input type="hidden" name="prod_id" id="del-prod-id" value="">
</form>

<script>
const BASE_URL = '<?= BASE_URL ?>';

// Borrar producto
document.querySelectorAll('.btn-delete-prod').forEach(btn => {
  btn.addEventListener('click', () => {
    const nombre = btn.dataset.nombre;
    if (!confirm(`¿Eliminar el producto "${nombre}"?\nEsta acción no se puede deshacer.`)) return;
    document.getElementById('del-prod-id').value = btn.dataset.id;
    document.getElementById('form-delete-prod').submit();
  });
});

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
</script>

<?php layout_end(); ?>
