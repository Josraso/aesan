<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>AESAN Checker — Instalación</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
  <style>
    body{background:linear-gradient(135deg,#1A5276 0%,#154360 100%);min-height:100vh;
      display:flex;align-items:center;justify-content:center;font-family:'Segoe UI',sans-serif;padding:1rem}
    .card{max-width:540px;width:100%;border-radius:16px;overflow:hidden;box-shadow:0 8px 40px rgba(0,0,0,.3)}
    .card-header{background:#1A5276;color:#fff;padding:1.5rem 2rem}
    .card-body{padding:2rem}
    .step-bar{display:flex;gap:4px;margin-bottom:1.5rem}
    .step-bar div{flex:1;height:6px;border-radius:99px;background:#e9ecef}
    .step-bar div.done{background:#1E8449}
    .step-bar div.active{background:#1A5276}
    .check-item{display:flex;align-items:center;gap:.7rem;padding:.3rem 0;font-size:.9rem}
    .ok{color:#1E8449;font-size:1.1rem} .err{color:#C0392B;font-size:1.1rem}
    .success-icon{font-size:4rem;color:#1E8449}
  </style>
</head>
<body>
<?php
session_start();

// Calcular rutas
$appRoot   = dirname(__DIR__);
$configFile = $appRoot . '/includes/config.php';

// Redirigir si ya instalado
if (file_exists($configFile)) {
    $appUrl = rtrim(str_replace('\\','/',dirname(dirname($_SERVER['SCRIPT_NAME']))),'/');
    echo '<div class="card"><div class="card-body text-center py-5">
        <div class="success-icon">✅</div>
        <h4 class="mt-3 text-success">Ya instalado</h4>
        <p class="text-muted">La aplicación ya está configurada y lista.</p>
        <a href="'.$appUrl.'/index.php" class="btn btn-primary">Ir a la aplicación →</a>
    </div></div>';
    exit;
}

$paso = (int)($_GET['paso'] ?? 1);
$err  = '';

// ── PASO 2: guardar BD ───────────────────────────────────────────────────────
if ($paso === 2 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $host = trim($_POST['db_host'] ?? 'localhost');
    $name = trim($_POST['db_name'] ?? '');
    $user = trim($_POST['db_user'] ?? '');
    $pass = $_POST['db_pass'] ?? '';

    if (!$name || !$user) {
        $err = 'El nombre de la base de datos y el usuario son obligatorios.';
    } else {
        try {
            $pdo = new PDO("mysql:host={$host};charset=utf8mb4", $user, $pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `{$name}`");

            // Ejecutar SQL de instalación
            $sql = file_get_contents(__DIR__ . '/install.sql');
            // Dividir por ; pero ignorar los SET y comentarios
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
                if ($stmt && !preg_match('/^--/', $stmt)) $pdo->exec($stmt);
            }

            $_SESSION['install'] = [
                'host' => $host, 'name' => $name,
                'user' => $user, 'pass' => $pass
            ];
            header('Location: ?paso=3');
            exit;
        } catch (PDOException $e) {
            $err = 'Error de conexión MySQL: ' . htmlspecialchars($e->getMessage());
        }
    }
}

// ── PASO 3: crear admin y generar config ─────────────────────────────────────
if ($paso === 3 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $db     = $_SESSION['install'] ?? null;
    if (!$db) { header('Location: ?paso=2'); exit; }

    $nombre = trim($_POST['nombre']    ?? '');
    $email  = trim($_POST['email']     ?? '');
    $pass   = $_POST['password']       ?? '';
    $pass2  = $_POST['password2']      ?? '';
    $mFrom  = trim($_POST['mail_from'] ?? '');
    $mAdmin = trim($_POST['mail_admin']?? '');

    if (!$nombre || !$email || !$pass) {
        $err = 'Nombre, email y contraseña son obligatorios.';
    } elseif ($pass !== $pass2) {
        $err = 'Las contraseñas no coinciden.';
    } elseif (strlen($pass) < 6) {
        $err = 'La contraseña debe tener al menos 6 caracteres.';
    } else {
        try {
            $pdo = new PDO(
                "mysql:host={$db['host']};dbname={$db['name']};charset=utf8mb4",
                $db['user'], $db['pass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            $pdo->prepare('INSERT INTO usuarios (nombre,email,password,rol) VALUES (?,?,?,?)')
                ->execute([$nombre, $email, $hash, 'admin']);

            // Calcular APP_URL dinámicamente
            $appUrl = rtrim(str_replace('\\','/',dirname(dirname($_SERVER['SCRIPT_NAME']))),'/');
            // Sanear email
            $mFrom  = filter_var($mFrom,  FILTER_VALIDATE_EMAIL) ? $mFrom  : '';
            $mAdmin = filter_var($mAdmin, FILTER_VALIDATE_EMAIL) ? $mAdmin : '';

            // Generar config.php
            $cfg = "<?php\n"
                . "// AESAN Checker — config generada el " . date('Y-m-d H:i:s') . "\n"
                . "define('DB_HOST',       '" . addslashes($db['host']) . "');\n"
                . "define('DB_NAME',       '" . addslashes($db['name']) . "');\n"
                . "define('DB_USER',       '" . addslashes($db['user']) . "');\n"
                . "define('DB_PASS',       '" . addslashes($db['pass']) . "');\n"
                . "define('APP_URL',       '" . $appUrl . "');\n"
                . "define('MAIL_FROM',     '" . $mFrom  . "');\n"
                . "define('MAIL_FROM_NAME','AESAN Checker');\n"
                . "define('MAIL_ADMIN',    '" . $mAdmin . "');\n";

            file_put_contents($configFile, $cfg);

            // Crear carpetas protegidas
            foreach (['uploads','exports'] as $d) {
                $path = $appRoot . '/' . $d;
                if (!is_dir($path)) mkdir($path, 0755, true);
                if (!file_exists($path.'/.htaccess'))
                    file_put_contents($path.'/.htaccess', "Options -Indexes\nDeny from all\n");
                if (!file_exists($path.'/index.php'))
                    file_put_contents($path.'/index.php', "<?php header('Location: ../index.php');");
            }

            unset($_SESSION['install']);
            header('Location: ?paso=4');
            exit;
        } catch (Exception $e) {
            $err = 'Error: ' . htmlspecialchars($e->getMessage());
        }
    }
}
?>

<div class="card">
  <div class="card-header">
    <h4 class="mb-0 fw-bold">🛡 AESAN Checker — Instalación</h4>
    <p class="mb-0 mt-1 opacity-75 small">Paso <?= min($paso,3) ?> de 3</p>
  </div>
  <div class="card-body">

    <!-- Barra de pasos -->
    <div class="step-bar">
      <?php for ($s=1;$s<=3;$s++): ?>
      <div class="<?= $paso>$s?'done':($paso===$s?'active':'') ?>"></div>
      <?php endfor; ?>
    </div>

    <?php if ($err): ?>
    <div class="alert alert-danger"><?= $err ?></div>
    <?php endif; ?>

    <?php if ($paso === 1): // ─── REQUISITOS ─── ?>
    <h5 class="mb-3 fw-bold"><i class="bi bi-check2-square"></i> Comprobación del sistema</h5>
    <?php
    $checks = [
        'PHP 7.4 o superior'         => version_compare(PHP_VERSION,'7.4.0','>='),
        'Extensión PDO'               => extension_loaded('pdo'),
        'Extensión PDO MySQL'         => extension_loaded('pdo_mysql'),
        'Extensión mbstring'          => extension_loaded('mbstring'),
        'Extensión zip'               => extension_loaded('zip'),
        'Carpeta includes/ escribible'=> is_writable(dirname(__DIR__).'/includes') || !is_dir(dirname(__DIR__).'/includes'),
    ];
    $allOk = !in_array(false, $checks);
    foreach ($checks as $lbl => $ok): ?>
    <div class="check-item">
      <span class="<?= $ok?'ok':'err' ?>"><?= $ok?'✔':'✘' ?></span>
      <span><?= htmlspecialchars($lbl) ?></span>
      <?php if (!$ok): ?><span class="text-danger small ms-auto fw-bold">Requerido</span><?php endif; ?>
    </div>
    <?php endforeach; ?>
    <p class="text-muted small mt-3 mb-3">PHP <?= PHP_VERSION ?></p>
    <?php if ($allOk): ?>
    <a href="?paso=2" class="btn btn-primary w-100 fw-bold py-2">Continuar →</a>
    <?php else: ?>
    <div class="alert alert-danger small mt-3">Resuelve los errores marcados antes de continuar.</div>
    <?php endif; ?>

    <?php elseif ($paso === 2): // ─── BASE DE DATOS ─── ?>
    <h5 class="mb-3 fw-bold"><i class="bi bi-database"></i> Base de datos MySQL</h5>
    <form method="post">
      <div class="mb-3">
        <label class="form-label fw-semibold">Servidor MySQL</label>
        <input type="text" name="db_host" class="form-control" value="localhost" required>
        <div class="form-text">Normalmente es <code>localhost</code></div>
      </div>
      <div class="mb-3">
        <label class="form-label fw-semibold">Nombre de la base de datos</label>
        <input type="text" name="db_name" class="form-control" placeholder="aesan_checker" required>
        <div class="form-text">Se creará automáticamente si no existe.</div>
      </div>
      <div class="row g-2 mb-4">
        <div class="col-6">
          <label class="form-label fw-semibold">Usuario MySQL</label>
          <input type="text" name="db_user" class="form-control" required>
        </div>
        <div class="col-6">
          <label class="form-label fw-semibold">Contraseña MySQL</label>
          <input type="password" name="db_pass" class="form-control">
        </div>
      </div>
      <button type="submit" class="btn btn-primary w-100 fw-bold py-2">
        Crear tablas y continuar →
      </button>
    </form>

    <?php elseif ($paso === 3): // ─── ADMIN ─── ?>
    <h5 class="mb-3 fw-bold"><i class="bi bi-person-circle"></i> Crear administrador</h5>
    <form method="post">
      <div class="mb-3">
        <label class="form-label fw-semibold">Nombre completo</label>
        <input type="text" name="nombre" class="form-control" required
               value="<?= htmlspecialchars($_POST['nombre'] ?? '') ?>">
      </div>
      <div class="mb-3">
        <label class="form-label fw-semibold">Email (usuario de acceso)</label>
        <input type="email" name="email" class="form-control" required
               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
      </div>
      <div class="row g-2 mb-3">
        <div class="col-6">
          <label class="form-label fw-semibold">Contraseña</label>
          <input type="password" name="password" class="form-control" required minlength="6">
        </div>
        <div class="col-6">
          <label class="form-label fw-semibold">Repetir contraseña</label>
          <input type="password" name="password2" class="form-control" required minlength="6">
        </div>
      </div>
      <hr>
      <p class="small text-muted fw-semibold mb-2">
        Notificaciones por email <span class="fw-normal">(opcional)</span>
      </p>
      <div class="row g-2 mb-4">
        <div class="col-6">
          <label class="form-label small">Email remitente</label>
          <input type="email" name="mail_from" class="form-control form-control-sm"
                 placeholder="noreply@tudominio.com">
        </div>
        <div class="col-6">
          <label class="form-label small">Email para alertas admin</label>
          <input type="email" name="mail_admin" class="form-control form-control-sm"
                 placeholder="admin@tudominio.com">
        </div>
      </div>
      <button type="submit" class="btn btn-success w-100 fw-bold py-2">
        ✔ Finalizar instalación
      </button>
    </form>

    <?php elseif ($paso === 4): // ─── FIN ─── ?>
    <div class="text-center py-3">
      <div class="success-icon">✅</div>
      <h4 class="mt-3 fw-bold text-success">¡Instalación completada!</h4>
      <p class="text-muted mb-1">La aplicación está lista para usar.</p>
      <div class="alert alert-warning small text-start mt-3">
        <strong>⚠ IMPORTANTE:</strong> Elimina la carpeta <code>install/</code> del servidor
        antes de poner en producción para evitar que alguien reinstale la app.
        <br><br>
        <code>rm -rf /ruta/a/aesan/install/</code>
      </div>
      <?php
        $appUrl2 = rtrim(str_replace('\\','/',dirname(dirname($_SERVER['SCRIPT_NAME']))),'/');
      ?>
      <a href="<?= $appUrl2 ?>/index.php" class="btn btn-primary btn-lg w-100 mt-2">
        Ir a la aplicación →
      </a>
    </div>
    <?php endif; ?>

  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
