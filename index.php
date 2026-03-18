<?php
// index.php – Login / logout
require_once __DIR__ . '/includes/config_base.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Cargar config si existe
if (file_exists(__DIR__ . '/includes/config.php')) {
    require_once __DIR__ . '/includes/config.php';
}

Auth::start();

if (isset($_GET['logout'])) { Auth::logout(); }

// Instalar si no hay config
if (!file_exists(__DIR__ . '/includes/config.php')) {
    header('Location: ' . BASE_URL . '/install/');
    exit;
}

// Ya logado
if (!empty($_SESSION['uid'])) {
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password']  ?? '';
    if (Auth::login($email, $pass)) {
        header('Location: ' . BASE_URL . '/dashboard.php');
        exit;
    }
    $error = 'Email o contraseña incorrectos.';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>AESAN Checker — Acceso</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app.css">
</head>
<body>
<div class="login-wrap">
  <div class="login-card">
    <div class="text-center mb-4">
      <i class="bi bi-shield-check" style="font-size:3rem;color:#1A5276"></i>
      <h4 class="mt-2 fw-bold">AESAN Checker</h4>
      <p class="text-muted small mb-0">Validación etiquetado alimentario online</p>
    </div>
    <?php if ($error): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
    <?php endif; ?>
    <?php if (isset($_GET['msg'])): ?>
    <div class="alert alert-warning">
      <?= $_GET['msg']==='session' ? 'Sesión expirada. Vuelve a entrar.' : 'Acceso no permitido.' ?>
    </div>
    <?php endif; ?>
    <form method="post">
      <div class="mb-3">
        <label class="form-label fw-semibold">Email</label>
        <input type="email" name="email" class="form-control form-control-lg" required autofocus
               value="<?= h($_POST['email'] ?? '') ?>">
      </div>
      <div class="mb-4">
        <label class="form-label fw-semibold">Contraseña</label>
        <input type="password" name="password" class="form-control form-control-lg" required>
      </div>
      <button type="submit" class="btn btn-primary w-100 fw-bold py-2">
        <i class="bi bi-box-arrow-in-right"></i> Entrar
      </button>
    </form>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
