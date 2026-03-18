<?php
// includes/layout.php – Layout principal con rutas dinámicas

require_once __DIR__ . '/config_base.php';

function layout_start(string $titulo = 'AESAN Checker'): void {
    require_once __DIR__ . '/auth.php';
    require_once __DIR__ . '/functions.php';
    Auth::check();
    $flash      = getFlash();
    $activePage = basename($_SERVER['PHP_SELF'], '.php');
    $base       = BASE_URL;
    ?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($titulo) ?> — AESAN Checker</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="<?= $base ?>/assets/css/app.css">
  <style>
    /* Fallback inline por si el CSS externo tarda en cargar */
    body{margin:0;font-family:'Segoe UI',sans-serif;background:#f0f2f5}
    .sidebar{width:230px;min-height:100vh;background:#1A5276;color:#fff;position:fixed;top:0;left:0;z-index:100;display:flex;flex-direction:column;overflow-y:auto}
    .sidebar .brand{padding:1.2rem 1rem;font-size:1.05rem;font-weight:700;border-bottom:1px solid rgba(255,255,255,.15);display:flex;align-items:center;gap:.5rem}
    .sidebar .nav-link{color:rgba(255,255,255,.8);padding:.5rem 1rem;border-radius:6px;margin:2px 8px;display:block;text-decoration:none;font-size:.9rem}
    .sidebar .nav-link:hover,.sidebar .nav-link.active{background:rgba(255,255,255,.18);color:#fff}
    .sidebar .nav-section{font-size:.68rem;text-transform:uppercase;letter-spacing:1px;color:rgba(255,255,255,.4);padding:.8rem 1rem .2rem}
    .main-content{margin-left:230px;min-height:100vh}
    .topbar{background:#fff;border-bottom:1px solid #dee2e6;padding:.7rem 1.5rem;display:flex;align-items:center;justify-content:space-between}
    .page-body{padding:1.5rem}
  </style>
</head>
<body>

<nav class="sidebar">
  <div class="brand">
    <i class="bi bi-shield-check"></i> AESAN Checker
  </div>
  <div class="flex-grow-1 pt-2 pb-3">
    <div class="nav-section">Principal</div>
    <a class="nav-link <?= $activePage==='dashboard'?'active':'' ?>" href="<?= $base ?>/dashboard.php">
      <i class="bi bi-house-door"></i> Dashboard
    </a>
    <a class="nav-link <?= $activePage==='import'?'active':'' ?>" href="<?= $base ?>/import.php">
      <i class="bi bi-upload"></i> Importar Excel
    </a>

    <div class="nav-section">Gestión</div>
    <a class="nav-link <?= $activePage==='productos'?'active':'' ?>" href="<?= $base ?>/productos.php">
      <i class="bi bi-table"></i> Mis importaciones
    </a>

    <?php if (Auth::isAdmin()): ?>
    <div class="nav-section">Administración</div>
    <a class="nav-link <?= $activePage==='users'?'active':'' ?>" href="<?= $base ?>/users.php">
      <i class="bi bi-people"></i> Usuarios
    </a>
    <?php endif; ?>
  </div>

  <div class="p-3" style="border-top:1px solid rgba(255,255,255,.15);font-size:.82rem;color:rgba(255,255,255,.65)">
    <div class="d-flex align-items-center gap-2 mb-1">
      <i class="bi bi-person-circle fs-5"></i>
      <div>
        <div class="fw-semibold text-white"><?= h(Auth::nombre()) ?></div>
        <span class="badge bg-light text-dark" style="font-size:.65rem"><?= h(Auth::rol()) ?></span>
      </div>
    </div>
    <a href="<?= $base ?>/index.php?logout=1" class="text-white-50 text-decoration-none small">
      <i class="bi bi-box-arrow-left"></i> Cerrar sesión
    </a>
  </div>
</nav>

<div class="main-content">
  <div class="topbar">
    <h5 class="mb-0 fw-bold text-dark"><?= h($titulo) ?></h5>
    <span class="text-muted small">Plan Coordinado AESAN 2026</span>
  </div>

  <?php if ($flash): ?>
  <div class="flash-msg">
    <div class="alert alert-<?= $flash['tipo']==='error'?'danger':$flash['tipo'] ?> alert-dismissible shadow">
      <?= h($flash['msg']) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  </div>
  <?php endif; ?>

  <div class="page-body">
<?php
}

function layout_end(): void {
    $base = BASE_URL;
    ?>
  </div><!-- /page-body -->
</div><!-- /main-content -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= $base ?>/assets/js/app.js"></script>
</body>
</html>
<?php
}
