<?php
require_once __DIR__ . '/includes/config_base.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/validator.php';

layout_start('Dashboard');

$uid     = Auth::uid();
$isAdmin = Auth::isAdmin();
$wh      = $isAdmin ? '' : 'AND i.usuario_id = ?';
$p       = $isAdmin ? [] : [$uid];

$stats = DB::row("
    SELECT
      COUNT(DISTINCT i.id)                                   AS total_imps,
      COUNT(p.id)                                            AS total_prods,
      SUM(p.estado='ok')                                     AS total_ok,
      SUM(p.estado='incompleto')                             AS total_inc,
      SUM(p.exportado=1)                                     AS total_exp
    FROM importaciones i
    LEFT JOIN productos p ON p.importacion_id = i.id
    WHERE 1=1 $wh", $p
);

$importaciones = DB::rows(
    "SELECT i.*, u.nombre AS unom
     FROM importaciones i JOIN usuarios u ON u.id=i.usuario_id
     WHERE 1=1 $wh ORDER BY i.fecha DESC LIMIT 10", $p
);

// Productos más recientes con incumplimientos
$pendientes = DB::rows(
    "SELECT p.id, p.nombre, p.referencia, p.tipo_validado, p.estado,
            p.importacion_id, i.nombre_archivo
     FROM productos p JOIN importaciones i ON i.id=p.importacion_id
     WHERE p.estado='incompleto' $wh
     ORDER BY p.updated_at DESC LIMIT 8", $p
);
?>

<!-- Stats -->
<div class="row g-3 mb-4">
  <?php
  $cards = [
    ['Importaciones',     $stats['total_imps'],  'bi-cloud-upload',    ''],
    ['Productos totales', $stats['total_prods'],  'bi-box-seam',        ''],
    ['Completos ✔',       $stats['total_ok'],     'bi-check-circle',    'verde'],
    ['Incompletos ✘',     $stats['total_inc'],    'bi-exclamation-circle','rojo'],
    ['Exportados',        $stats['total_exp'],    'bi-download',        'nara'],
  ];
  foreach ($cards as [$label, $val, $ico, $cls]): ?>
  <div class="col-6 col-xl-<?= $isAdmin ? 2 : 3 ?>">
    <div class="stat-card <?= $cls ?> d-flex align-items-center gap-3">
      <i class="bi <?= $ico ?>" style="font-size:1.8rem;opacity:.5"></i>
      <div>
        <div class="stat-num"><?= (int)$val ?></div>
        <div class="text-muted small"><?= $label ?></div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="row g-4">
  <!-- Importaciones recientes -->
  <div class="col-lg-7">
    <div class="card shadow-sm h-100">
      <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <span class="fw-bold"><i class="bi bi-clock-history"></i> Importaciones recientes</span>
        <a href="<?= BASE_URL ?>/import.php" class="btn btn-primary btn-sm">
          <i class="bi bi-upload"></i> Nueva importación
        </a>
      </div>
      <?php if (!$importaciones): ?>
      <div class="card-body text-muted text-center py-5">
        <i class="bi bi-inbox" style="font-size:3rem;opacity:.3"></i>
        <p class="mt-2">Todavía no hay importaciones.<br>
          <a href="<?= BASE_URL ?>/import.php">Sube tu primer archivo</a>.
        </p>
      </div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table table-hover mb-0 small">
          <thead class="table-light">
            <tr>
              <th>Archivo</th>
              <?php if ($isAdmin): ?><th>Usuario</th><?php endif; ?>
              <th>Fecha</th>
              <th class="text-center">Total</th>
              <th class="text-center text-success">✔</th>
              <th class="text-center text-danger">✘</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($importaciones as $imp): ?>
          <tr>
            <td><i class="bi bi-file-earmark-excel text-success"></i> <?= h($imp['nombre_archivo']) ?></td>
            <?php if ($isAdmin): ?><td><?= h($imp['unom']) ?></td><?php endif; ?>
            <td><?= date('d/m/Y H:i', strtotime($imp['fecha'])) ?></td>
            <td class="text-center"><strong><?= $imp['total'] ?></strong></td>
            <td class="text-center text-success fw-bold"><?= $imp['ok'] ?></td>
            <td class="text-center text-danger fw-bold"><?= $imp['incompletos'] ?></td>
            <td>
              <a href="<?= BASE_URL ?>/productos.php?imp=<?= $imp['id'] ?>" class="btn btn-xs btn-outline-primary btn-sm">
                Ver
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Pendientes de completar -->
  <div class="col-lg-5">
    <div class="card shadow-sm h-100">
      <div class="card-header bg-white fw-bold">
        <i class="bi bi-exclamation-triangle text-danger"></i> Pendientes de completar
      </div>
      <?php if (!$pendientes): ?>
      <div class="card-body text-center text-muted py-4">
        <i class="bi bi-check2-all text-success" style="font-size:2.5rem"></i>
        <p class="mt-2 mb-0">¡Todo completado!</p>
      </div>
      <?php else: ?>
      <div class="list-group list-group-flush" style="max-height:360px;overflow-y:auto">
        <?php foreach ($pendientes as $pp): ?>
        <a href="<?= BASE_URL ?>/producto.php?id=<?= $pp['id'] ?>&imp=<?= $pp['importacion_id'] ?>"
           class="list-group-item list-group-item-action py-2">
          <div class="d-flex justify-content-between align-items-start">
            <div>
              <strong><?= h($pp['nombre']) ?></strong>
              <br>
              <span class="text-muted small"><?= h($pp['nombre_archivo']) ?></span>
            </div>
            <?= tipoBadge($pp['tipo_validado']) ?>
          </div>
        </a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php layout_end(); ?>
