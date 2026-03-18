<?php
// users.php – Gestión de usuarios (solo admin)

require_once __DIR__ . '/includes/config_base.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

Auth::check('admin');

// Acciones POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $nombre = trim($_POST['nombre'] ?? '');
        $email  = trim($_POST['email']  ?? '');
        $pass   = $_POST['password']    ?? '';
        $rol    = $_POST['rol']         ?? 'editor';

        if (!$nombre || !$email || !$pass) {
            flash('Todos los campos son obligatorios.', 'error');
        } elseif (DB::row('SELECT id FROM usuarios WHERE email=?', [$email])) {
            flash('Ya existe un usuario con ese email.', 'error');
        } else {
            DB::insert('usuarios', [
                'nombre'   => $nombre,
                'email'    => $email,
                'password' => password_hash($pass, PASSWORD_DEFAULT),
                'rol'      => in_array($rol,['admin','editor']) ? $rol : 'editor',
            ]);
            flash("Usuario {$nombre} creado correctamente.", 'success');
        }
        redirect('users.php');
    }

    if ($action === 'toggle') {
        $uid = (int)($_POST['uid'] ?? 0);
        if ($uid === Auth::uid()) {
            flash('No puedes desactivarte a ti mismo.', 'warning');
        } else {
            $u = DB::row('SELECT activo FROM usuarios WHERE id=?', [$uid]);
            if ($u) {
                DB::update('usuarios', ['activo' => $u['activo'] ? 0 : 1], 'id=?', [$uid]);
                flash('Estado del usuario actualizado.', 'success');
            }
        }
        redirect('users.php');
    }

    if ($action === 'reset_pass') {
        $uid  = (int)($_POST['uid']      ?? 0);
        $pass = $_POST['new_password']   ?? '';
        if ($uid && strlen($pass) >= 6) {
            DB::update('usuarios', ['password' => password_hash($pass, PASSWORD_DEFAULT)], 'id=?', [$uid]);
            flash('Contraseña actualizada.', 'success');
        } else {
            flash('La contraseña debe tener al menos 6 caracteres.', 'error');
        }
        redirect('users.php');
    }
}

$usuarios = DB::rows('SELECT * FROM usuarios ORDER BY id');
layout_start('Gestión de usuarios');
?>

<div class="row g-4">

  <!-- Lista usuarios -->
  <div class="col-lg-8">
    <div class="card shadow-sm">
      <div class="card-header bg-white fw-bold">
        <i class="bi bi-people"></i> Usuarios del sistema
      </div>
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead class="table-light">
            <tr><th>#</th><th>Nombre</th><th>Email</th><th>Rol</th><th>Estado</th><th>Acciones</th></tr>
          </thead>
          <tbody>
            <?php foreach ($usuarios as $u): ?>
            <tr>
              <td class="text-muted"><?= $u['id'] ?></td>
              <td><strong><?= h($u['nombre']) ?></strong></td>
              <td><?= h($u['email']) ?></td>
              <td>
                <span class="badge <?= $u['rol']==='admin' ? 'bg-dark' : 'bg-primary' ?>">
                  <?= h($u['rol']) ?>
                </span>
              </td>
              <td>
                <?php if ($u['activo']): ?>
                  <span class="badge bg-success">Activo</span>
                <?php else: ?>
                  <span class="badge bg-secondary">Inactivo</span>
                <?php endif; ?>
              </td>
              <td>
                <!-- Toggle activo/inactivo -->
                <?php if ($u['id'] !== Auth::uid()): ?>
                <form method="post" class="d-inline">
                  <input type="hidden" name="action" value="toggle">
                  <input type="hidden" name="uid" value="<?= $u['id'] ?>">
                  <button type="submit" class="btn btn-sm <?= $u['activo'] ? 'btn-outline-warning' : 'btn-outline-success' ?>"
                          data-bs-toggle="tooltip" title="<?= $u['activo'] ? 'Desactivar' : 'Activar' ?>">
                    <i class="bi bi-<?= $u['activo'] ? 'pause-circle' : 'play-circle' ?>"></i>
                  </button>
                </form>
                <?php endif; ?>
                <!-- Reset contraseña -->
                <button class="btn btn-sm btn-outline-secondary"
                        data-bs-toggle="modal" data-bs-target="#modalPass"
                        data-uid="<?= $u['id'] ?>" data-nombre="<?= h($u['nombre']) ?>"
                        title="Cambiar contraseña">
                  <i class="bi bi-key"></i>
                </button>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Crear usuario -->
  <div class="col-lg-4">
    <div class="card shadow-sm">
      <div class="card-header bg-white fw-bold">
        <i class="bi bi-person-plus"></i> Nuevo usuario
      </div>
      <div class="card-body">
        <form method="post">
          <input type="hidden" name="action" value="create">
          <div class="mb-3">
            <label class="form-label fw-semibold">Nombre</label>
            <input type="text" name="nombre" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Email</label>
            <input type="email" name="email" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Contraseña</label>
            <input type="password" name="password" class="form-control" required minlength="6">
          </div>
          <div class="mb-4">
            <label class="form-label fw-semibold">Rol</label>
            <select name="rol" class="form-select">
              <option value="editor">Editor</option>
              <option value="admin">Admin</option>
            </select>
          </div>
          <button type="submit" class="btn btn-primary w-100">
            <i class="bi bi-plus-circle"></i> Crear usuario
          </button>
        </form>
      </div>
    </div>
  </div>

</div>

<!-- Modal cambiar contraseña -->
<div class="modal fade" id="modalPass" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Cambiar contraseña</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="post">
        <input type="hidden" name="action" value="reset_pass">
        <input type="hidden" name="uid" id="modal-uid">
        <div class="modal-body">
          <p class="text-muted small">Usuario: <strong id="modal-nombre"></strong></p>
          <label class="form-label">Nueva contraseña</label>
          <input type="password" name="new_password" class="form-control" required minlength="6">
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-primary btn-sm">Guardar</button>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
document.getElementById('modalPass')?.addEventListener('show.bs.modal', e => {
  const btn = e.relatedTarget;
  document.getElementById('modal-uid').value    = btn.dataset.uid;
  document.getElementById('modal-nombre').textContent = btn.dataset.nombre;
});
</script>

<?php layout_end(); ?>
