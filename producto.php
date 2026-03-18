<?php
// producto.php – Editor wizard con historial, sugerencias contextuales y bloqueo de edición

require_once __DIR__ . '/includes/config_base.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/validator.php';
require_once __DIR__ . '/includes/exporter.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/historial.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/sugerencias.php';

$prodId = (int)($_GET['id']  ?? 0);
$impId  = (int)($_GET['imp'] ?? 0);
if (!$prodId) redirect('dashboard.php');

$prod = DB::row('SELECT * FROM productos WHERE id=?', [$prodId]);
if (!$prod) { flash('Producto no encontrado.','error'); redirect('dashboard.php'); }

$campos = json_decode($prod['campos_json'] ?? '{}', true) ?: [];
$paso   = max(1, min(5, (int)($_GET['paso'] ?? 1)));

// ── AJAX: preview bloque AESAN ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='preview') {
    header('Content-Type: application/json');
    $c    = $_POST;
    $tipo = $prod['tipo_validado'] ?? 'otro';
    $html = Exporter::generarBloqueAesan($c, $tipo);
    echo json_encode(['html' => $html]);
    exit;
}

// ── GUARDAR ──────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='save') {

    $camposAntes = $campos;

    $nuevos = $_POST;
    unset($nuevos['action'], $nuevos['paso'], $nuevos['_token'], $nuevos['solo_guardar']);

    $tipoNuevo    = $nuevos['tipo_validado']    ?? $prod['tipo_validado'];
    $usarSugerida = (int)($nuevos['desc_corta_usar']    ?? 0);
    $descSugerida = $nuevos['desc_corta_sugerida']       ?? $prod['desc_corta_sugerida'];
    unset($nuevos['tipo_validado'], $nuevos['desc_corta_usar'], $nuevos['desc_corta_sugerida']);

    foreach ($nuevos as $k => $v) {
        if ($v !== '' && $v !== null) $campos[$k] = $v;
    }

    // Normalizar origen_pais
    if (empty($campos['origen_pais'])) {
        $campos['origen_pais'] = $campos['origen_nacido']
            ?? $campos['origen_cria']
            ?? $campos['origen_sacrificado']
            ?? '';
    }

    // nutricional: marcar como cubierto si hay valor energético
    if (empty($campos['nutricional']) && (!empty($campos['energia_kcal']) || !empty($campos['energia_kj']))) {
        $campos['nutricional'] = 'ok';
    }

    $prodData    = ['tipo_validado'=>$tipoNuevo,'tipo_detectado'=>$tipoNuevo,'campos_json'=>json_encode($campos)];
    $val         = Validator::validar($prodData);
    $estadoNuevo = $val['estado'];

    DB::update('productos', [
        'tipo_validado'       => $tipoNuevo,
        'campos_json'         => json_encode($campos),
        'estado'              => $estadoNuevo,
        'desc_corta_usar'     => $usarSugerida,
        'desc_corta_sugerida' => $descSugerida,
    ], 'id=?', [$prodId]);

    $detalle = Historial::describir($camposAntes, $campos);
    $accion  = $estadoNuevo === 'ok' ? 'completado' : 'editado';
    Historial::registrar($prodId, Auth::uid(), $accion, "Paso {$paso}: {$detalle}");

    if ($estadoNuevo === 'ok' && $prod['estado'] !== 'ok') {
        $imp    = DB::row('SELECT * FROM importaciones WHERE id=?', [$impId]);
        $admins = DB::rows("SELECT email FROM usuarios WHERE rol='admin' AND activo=1");
        foreach ($admins as $admin) {
            Mailer::notificarCompletado(Auth::nombre(), $imp['nombre_archivo'] ?? '', 1, $admin['email']);
        }
    }

    if ($impId) {
        $cOk  = DB::row('SELECT COUNT(*) c FROM productos WHERE importacion_id=? AND estado="ok"',  [$impId])['c'];
        $cInc = DB::row('SELECT COUNT(*) c FROM productos WHERE importacion_id=? AND estado!="ok"', [$impId])['c'];
        DB::update('importaciones', ['ok'=>$cOk,'incompletos'=>$cInc], 'id=?', [$impId]);
    }

    if (isset($_POST['solo_guardar'])) {
        flash('Cambios guardados.', 'success');
        redirect("producto.php?id={$prodId}&imp={$impId}&paso={$paso}");
    }

    $siguiente = $paso + 1;
    if ($siguiente > 5) {
        // Liberar bloqueo al finalizar
        try { DB::q("DELETE FROM producto_bloqueos WHERE producto_id=? AND usuario_id=?", [$prodId, Auth::uid()]); } catch(\Exception $e) {}
        flash('Producto guardado correctamente.', 'success');
        redirect("productos.php?imp={$impId}");
    }
    redirect("producto.php?id={$prodId}&imp={$impId}&paso={$siguiente}");
}

// ── BLOQUEO DE EDICIÓN ────────────────────────────────────────────────────────
$bloqueado    = false;
$bloqueadoPor = null;
try {
    DB::q("DELETE FROM producto_bloqueos WHERE TIMESTAMPDIFF(SECOND, updated_at, NOW()) > 90");
    $lockRow = DB::row('SELECT * FROM producto_bloqueos WHERE producto_id=?', [$prodId]);
    if ($lockRow && (int)$lockRow['usuario_id'] !== Auth::uid()) {
        $bloqueado    = true;
        $bloqueadoPor = $lockRow['usuario_nombre'];
    } else {
        DB::q("INSERT INTO producto_bloqueos (producto_id, usuario_id, usuario_nombre, updated_at)
               VALUES (?,?,?,NOW())
               ON DUPLICATE KEY UPDATE
                 usuario_id=VALUES(usuario_id),
                 usuario_nombre=VALUES(usuario_nombre),
                 updated_at=NOW()",
              [$prodId, Auth::uid(), Auth::nombre()]);
    }
} catch (\Exception $e) { /* tabla no existe aún → ignorar */ }

// ── Recargar producto ─────────────────────────────────────────────────────────
$prod   = DB::row('SELECT * FROM productos WHERE id=?', [$prodId]);
$campos = json_decode($prod['campos_json'] ?? '{}', true) ?: [];
$tipo   = $prod['tipo_validado'] ?? 'otro';
$reqs   = Validator::getCamposRequeridos($tipo);
$val    = Validator::validar($prod);

$pasosConError = [];
foreach ($reqs as $key => $info) {
    if (empty($campos[$key]) && $info['critico']) $pasosConError[$info['paso']] = true;
}

$historial   = Historial::obtener($prodId, 10);
$alergenosJS = json_encode(Validator::ALERGENOS);

$sugerencia = '';
if (in_array($tipo, ['carne_picada','preparado_carne'])) {
    $d  = $campos['denominacion']    ?? '';
    $lg = $campos['limite_grasa']    ?? '';
    $lc = $campos['limite_colageno'] ?? '';
    if ($d) $sugerencia = $d . ($lg||$lc ? " (≤{$lg}% grasa" . ($lc?", ≤{$lc}% colágeno/prot.":"") . ")" : "");
}

// Sugerencias JS
$sugerenciasJS = Sugerencias::toJson();

layout_start('Editar — ' . $prod['nombre']);
?>
<script>
window.ALERGENOS   = <?= $alergenosJS ?>;
window.SUGERENCIAS = <?= $sugerenciasJS ?>;
window.PROD_ID     = <?= $prodId ?>;
window.BASE_URL    = '<?= BASE_URL ?>';
</script>

<nav class="mb-3"><ol class="breadcrumb small mb-0">
  <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/dashboard.php">Dashboard</a></li>
  <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/productos.php?imp=<?= $impId ?>">Importación #<?= $impId ?></a></li>
  <li class="breadcrumb-item active"><?= h($prod['nombre']) ?></li>
</ol></nav>

<?php if ($bloqueado): ?>
<div class="alert alert-warning d-flex align-items-center gap-2 mb-3" role="alert">
  <i class="bi bi-lock-fill fs-5"></i>
  <div>
    <strong>Modo solo lectura</strong> — <strong><?= h($bloqueadoPor) ?></strong> está editando este producto en este momento.
    El formulario está desactivado para evitar conflictos.
  </div>
  <?php if (Auth::isAdmin()): ?>
  <button type="button" class="btn btn-sm btn-outline-warning ms-auto" id="btn-forzar-edicion">
    <i class="bi bi-unlock"></i> Forzar edición (admin)
  </button>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3">
  <div>
    <h5 class="mb-1 fw-bold"><?= h($prod['nombre']) ?></h5>
    <span class="text-muted small">Ref: <code><?= h($prod['referencia'] ?? '–') ?></code></span>
    <span class="ms-2"><?= estadoBadge($prod['estado']) ?></span>
    <span class="ms-1"><?= tipoBadge($tipo) ?></span>
  </div>
  <?php if ($val['faltan_criticos']): ?>
  <div>
    <?php foreach (array_slice($val['faltan_criticos'],0,4) as $f): ?>
    <span class="badge bg-danger me-1 mb-1"><i class="bi bi-exclamation-triangle"></i> <?= h($f) ?></span>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<!-- Wizard steps -->
<?php $stepLabels = [1=>'Identificación',2=>'Origen',3=>'Ingredientes',4=>'Conservación',5=>'Nutricional']; ?>
<div class="wizard-steps mb-4">
  <?php for ($s=1; $s<=5; $s++):
    $cls = $s===$paso ? 'active' : (isset($pasosConError[$s]) ? 'has-error' : ($s<$paso ? 'done' : ''));
  ?>
  <a href="<?= BASE_URL ?>/producto.php?id=<?= $prodId ?>&imp=<?= $impId ?>&paso=<?= $s ?>"
     class="wizard-step <?= $cls ?>" style="text-decoration:none">
    <?= $s ?>. <?= $stepLabels[$s] ?>
    <?= isset($pasosConError[$s]) ? ' ⚠' : ($s<$paso ? ' ✔' : '') ?>
  </a>
  <?php endfor; ?>
</div>

<div class="row g-4">
  <!-- Formulario principal -->
  <div class="col-lg-8">
    <form method="post" id="wizard-form" <?= $bloqueado ? 'style="pointer-events:none;opacity:.75"' : '' ?>>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="paso"   value="<?= $paso ?>">
      <input type="hidden" name="tipo_validado" id="tipo_validado_hidden" value="<?= h($tipo) ?>">

      <div class="wizard-card">

      <?php if ($paso===1): // ═══ PASO 1: Identificación ════════════════════ ?>
      <h5 class="mb-3"><i class="bi bi-tag"></i> Identificación del producto</h5>
      <div class="row g-3">

        <div class="col-md-6">
          <label class="form-label fw-semibold text-primary">Tipo de producto *
            <i class="bi bi-info-circle small" data-bs-toggle="tooltip"
               title="Detectado automáticamente. Cámbialo si es incorrecto."></i>
          </label>
          <select name="tipo_validado" id="tipo_validado" class="form-select">
            <?php foreach (Validator::TIPOS as $k=>$v): ?>
            <option value="<?= $k ?>" <?= $tipo===$k?'selected':'' ?>><?= h($v) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="form-text">Detectado: <strong><?= Validator::labelTipo($prod['tipo_detectado']) ?></strong></div>
        </div>

        <div class="col-md-6 campo-aesan critico">
          <label class="form-label">Especie animal *</label>
          <select name="especie" id="especie" class="form-select">
            <option value="">— Selecciona —</option>
            <?php foreach ([
              'vacuno' =>'Vacuno (vaca, buey, ternera, wagyu…)',
              'porcino'=>'Porcino (cerdo, ibérico…)',
              'aves'   =>'Aves (pollo, pavo, pato…)',
              'ovino'  =>'Ovino / Caprino (cordero, cabra…)',
              'otro'   =>'Otro',
            ] as $k=>$v): ?>
            <option value="<?= $k ?>" <?= ($campos['especie']??'')===$k?'selected':'' ?>><?= $v ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-12 campo-aesan critico">
          <label class="form-label">Denominación legal del alimento *</label>
          <input type="text" name="denominacion" id="denominacion" class="form-control"
                 value="<?= h($campos['denominacion'] ?? '') ?>"
                 placeholder="Ej: Carne fresca de vacuno. Lomo alto madurado.">
          <div class="form-text text-muted">Denominación tal como debe aparecer en la ficha del producto.</div>
          <!-- Sugerencias de denominación (JS dinámico) -->
          <div class="mt-2" id="wrap-denom-sugerencias">
            <div class="d-flex align-items-center gap-2 mb-1">
              <span class="badge bg-primary-subtle text-primary border border-primary-subtle" style="font-size:.72rem">
                <i class="bi bi-stars"></i> Denominaciones legales sugeridas
              </span>
              <span class="text-muted" style="font-size:.75rem">Clic para rellenar</span>
            </div>
            <div id="denom-pills" class="d-flex flex-wrap gap-1"></div>
          </div>
        </div>

        <!-- Límites grasa / colágeno (carne_picada y preparado_carne) -->
        <div class="col-md-4" data-tipo="carne_picada,preparado_carne">
          <label class="form-label campo-aesan critico">Límite máx. grasa (%) *</label>
          <input type="number" name="limite_grasa" id="limite_grasa" class="form-control"
                 min="0" max="60" step="0.1" value="<?= h($campos['limite_grasa'] ?? '') ?>" placeholder="Ej: 20">
          <div class="form-text">Vacuno ≤20% | Porcino ≤30% | Aves ≤15%</div>
          <!-- Quickfill legal por especie -->
          <div id="pills-grasa" class="mt-1 d-flex flex-wrap gap-1"></div>
        </div>

        <div class="col-md-4" data-tipo="carne_picada,preparado_carne">
          <label class="form-label campo-aesan critico">Límite colágeno/proteína (%) *</label>
          <input type="number" name="limite_colageno" id="limite_colageno" class="form-control"
                 min="0" max="30" step="0.1" value="<?= h($campos['limite_colageno'] ?? '') ?>" placeholder="Ej: 15">
          <div class="form-text">Vacuno/Ovino ≤15% | Porcino ≤18% | Aves ≤10%</div>
          <!-- Quickfill legal por especie -->
          <div id="pills-colageno" class="mt-1 d-flex flex-wrap gap-1"></div>
        </div>

        <div class="col-md-4">
          <label class="form-label">Peso neto / presentación</label>
          <input type="text" name="peso_unidad" id="peso_unidad" class="form-control"
                 value="<?= h($campos['peso_unidad'] ?? '') ?>" placeholder="Ej: 400 g, 1 kg">
          <!-- Quickfill pesos comunes -->
          <div class="mt-1 d-flex flex-wrap gap-1">
            <?php foreach (Sugerencias::$pesos as $p): ?>
            <button type="button" class="btn btn-xs btn-outline-secondary"
                    style="font-size:.7rem;padding:1px 6px"
                    onclick="fillField('peso_unidad','<?= $p ?>')"><?= $p ?></button>
            <?php endforeach; ?>
          </div>
        </div>

        <?php if ($sugerencia && $sugerencia !== $prod['desc_corta_original']): ?>
        <div class="col-12">
          <div class="alert alert-warning">
            <strong><i class="bi bi-lightbulb"></i> Sugerencia para descripción corta</strong>
            <div class="border rounded p-2 bg-white mt-2 font-monospace small"><?= h($sugerencia) ?></div>
            <div class="form-check mt-2">
              <input class="form-check-input" type="checkbox" name="desc_corta_usar"
                     id="usar-sugerida" value="1" <?= $prod['desc_corta_usar']?'checked':'' ?>>
              <label class="form-check-label" for="usar-sugerida">
                Usar esta descripción corta en la exportación
              </label>
            </div>
            <input type="hidden" name="desc_corta_sugerida" value="<?= h($sugerencia) ?>">
          </div>
        </div>
        <?php endif; ?>

      </div>

      <?php elseif ($paso===2): // ═══ PASO 2: Origen ════════════════════════ ?>
      <?php $especie = $campos['especie'] ?? ''; ?>
      <h5 class="mb-1"><i class="bi bi-geo-alt"></i> Origen del producto</h5>
      <p class="text-muted small mb-3">
        Las menciones de origen deben figurar <strong>de forma expresa</strong> en la ficha.
      </p>
      <input type="hidden" name="especie" value="<?= h($especie) ?>">

      <!-- Atajo: mismo país para todo -->
      <div class="mb-3" id="wrap-mismo-pais">
        <div class="input-group input-group-sm" style="max-width:340px">
          <span class="input-group-text bg-light"><i class="bi bi-lightning-fill text-warning"></i></span>
          <input type="text" id="mismo-pais" class="form-control"
                 placeholder="Rellenar todos con el mismo país…">
          <button type="button" class="btn btn-outline-secondary" id="btn-mismo-pais">Aplicar a todos</button>
        </div>
        <div class="form-text">Útil cuando nacido, criado y sacrificado es en el mismo país.</div>
        <!-- Países más comunes -->
        <div class="mt-1 d-flex flex-wrap gap-1">
          <?php foreach (Sugerencias::$paises as $pais): ?>
          <button type="button" class="btn btn-xs btn-outline-primary"
                  style="font-size:.72rem;padding:2px 8px"
                  onclick="document.getElementById('mismo-pais').value='<?= $pais ?>'; document.getElementById('btn-mismo-pais').click()">
            <?= $pais ?>
          </button>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="row g-3">

        <?php if ($especie==='vacuno' || !$especie): ?>
        <div class="col-12 <?= $especie!=='vacuno'?'origen-vacuno':'' ?>">
          <div class="alert alert-info py-2 small">
            <strong>Carne de vacuno</strong> — Reglamento (CE) 1760/2000:<br>
            Se requieren <em>Nacido en / Criado en / Sacrificado en</em>.
          </div>
        </div>
        <?php foreach (['origen_nacido'=>'Nacido en','origen_criado'=>'Criado en','origen_sacrificado'=>'Sacrificado en'] as $k=>$lbl): ?>
        <div class="col-md-4 campo-aesan critico origen-vacuno">
          <label class="form-label"><?= $lbl ?> *</label>
          <input type="text" name="<?= $k ?>" id="<?= $k ?>" class="form-control origen-field"
                 value="<?= h($campos[$k] ?? '') ?>" placeholder="España">
          <!-- País quickfill -->
          <div class="mt-1 d-flex flex-wrap gap-1">
            <?php foreach (['España','Francia','Alemania','Italia','Irlanda','Polonia'] as $pais): ?>
            <button type="button" class="btn btn-xs btn-outline-secondary"
                    style="font-size:.68rem;padding:1px 5px"
                    onclick="fillField('<?= $k ?>','<?= $pais ?>')"><?= $pais ?></button>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>

        <?php if (in_array($especie,['porcino','aves','ovino']) || !$especie): ?>
        <div class="col-12 origen-otros">
          <div class="alert alert-info py-2 small">
            <strong>Porcino / Aves / Ovino</strong> — Reglamento (UE) 1337/2013:<br>
            Se requieren <em>País de cría</em> y <em>País de sacrificio</em>.
          </div>
        </div>
        <?php foreach (['origen_cria'=>'País de cría','origen_sacrificado'=>'País de sacrificio'] as $k=>$lbl): ?>
        <div class="col-md-6 campo-aesan critico origen-otros">
          <label class="form-label"><?= $lbl ?> *</label>
          <input type="text" name="<?= $k ?>" id="<?= $k ?>" class="form-control origen-field"
                 value="<?= h($campos[$k] ?? '') ?>" placeholder="España">
          <div class="mt-1 d-flex flex-wrap gap-1">
            <?php foreach (['España','Francia','Alemania','Italia','Países Bajos','Polonia','Dinamarca'] as $pais): ?>
            <button type="button" class="btn btn-xs btn-outline-secondary"
                    style="font-size:.68rem;padding:1px 5px"
                    onclick="fillField('<?= $k ?>','<?= $pais ?>')"><?= $pais ?></button>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>

        <div class="col-md-6 campo-aesan critico origen-generico"
             <?= in_array($especie,['vacuno','porcino','aves','ovino'])?'style="display:none"':'' ?>>
          <label class="form-label">País de origen *</label>
          <input type="text" name="origen_pais" id="origen_pais" class="form-control origen-field"
                 value="<?= h($campos['origen_pais'] ?? '') ?>" placeholder="España">
          <div class="mt-1 d-flex flex-wrap gap-1">
            <?php foreach (Sugerencias::$paises as $pais): ?>
            <button type="button" class="btn btn-xs btn-outline-secondary"
                    style="font-size:.68rem;padding:1px 5px"
                    onclick="fillField('origen_pais','<?= $pais ?>')"><?= $pais ?></button>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <?php elseif ($paso===3): // ═══ PASO 3: Ingredientes ══════════════════ ?>
      <?php $algsGuardados = $campos['alergenos_lista'] ? explode(',', $campos['alergenos_lista']) : []; ?>
      <h5 class="mb-1"><i class="bi bi-list-ul"></i> Ingredientes y alérgenos</h5>
      <p class="text-muted small mb-3">Los alérgenos se detectan automáticamente al escribir y se resaltarán en el HTML exportado.</p>
      <div class="row g-3">

        <?php
        $plantillaIngredientes = Sugerencias::getIngredientes($tipo, $campos['especie'] ?? '_');
        ?>
        <?php if ($plantillaIngredientes): ?>
        <div class="col-12">
          <div class="alert alert-primary py-2 d-flex align-items-center justify-content-between gap-2">
            <div>
              <i class="bi bi-stars text-primary"></i>
              <strong>Plantilla legal para <?= Validator::labelTipo($tipo) ?></strong>
              <div class="small text-muted mt-1 font-monospace"><?= h($plantillaIngredientes) ?></div>
            </div>
            <button type="button" class="btn btn-sm btn-primary flex-shrink-0 fill-empty-pill"
                    data-campo="ingredientes" data-val="<?= h($plantillaIngredientes) ?>">
              <i class="bi bi-magic"></i> Aplicar plantilla
            </button>
          </div>
        </div>
        <?php endif; ?>

        <div class="col-12 campo-aesan critico">
          <label class="form-label">Lista de ingredientes *</label>
          <textarea name="ingredientes" id="ingredientes" class="form-control" rows="4"
            placeholder="Ej: Carne de vaca (99,75%), sal (0,15%), conservante: sulfito sódico (E221)…"><?= h($campos['ingredientes'] ?? '') ?></textarea>
        </div>

        <div class="col-12">
          <label class="form-label fw-semibold">
            Alérgenos presentes
            <span class="text-muted fw-normal small">(detección automática — puedes marcar/desmarcar manualmente)</span>
          </label>
          <div class="mb-2">
            <?php foreach (Validator::ALERGENOS as $key => $terms): ?>
            <span class="alg-tag <?= in_array($key,$algsGuardados)?'active':'' ?>" data-alg="<?= $key ?>">
              <?= Validator::labelAlergeno($key) ?>
            </span>
            <?php endforeach; ?>
          </div>
          <div id="alg-hint" class="small fw-semibold text-warning mb-2"></div>
          <input type="hidden" name="alergenos_lista" id="alergenos_lista"
                 value="<?= h($campos['alergenos_lista'] ?? '') ?>">
        </div>

        <div class="col-12" data-tipo="preparado_carne,producto_carnico,carne_picada">
          <label class="form-label">Aditivos utilizados</label>
          <input type="text" name="aditivos" class="form-control"
                 value="<?= h($campos['aditivos'] ?? '') ?>"
                 placeholder="Ej: Conservante E221, Colorante E120…">
          <!-- Aditivos comunes -->
          <div class="mt-1 d-flex flex-wrap gap-1">
            <?php foreach ([
              'Conservante: nitrito sódico (E250)',
              'Antioxidante: ascorbato sódico (E301)',
              'Conservante: sulfito sódico (E221)',
              'Colorante: cochinilla (E120)',
              'Potenciador del sabor: glutamato monosódico (E621)',
              'Conservante: sorbato potásico (E202)',
            ] as $aditivo): ?>
            <button type="button" class="btn btn-xs btn-outline-secondary append-pill"
                    style="font-size:.7rem;padding:2px 6px"
                    data-campo="aditivos" data-val="<?= h($aditivo) ?>">
              + <?= h($aditivo) ?>
            </button>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <?php elseif ($paso===4): // ═══ PASO 4: Conservación ══════════════════ ?>
      <?php
        $sugsConserv  = Sugerencias::getConservacion($tipo);
        $sugsInstruc  = Sugerencias::getInstrucciones($tipo);
        $estadoProd   = $campos['estado_producto'] ?? 'fresco';
      ?>
      <h5 class="mb-1"><i class="bi bi-thermometer-half"></i> Conservación e instrucciones</h5>
      <p class="text-muted small mb-3">Información obligatoria antes de la compra para todos los productos cárnicos.</p>
      <div class="row g-3">

        <div class="col-md-10 campo-aesan critico">
          <label class="form-label">Condiciones de conservación *</label>
          <input type="text" name="conservacion" id="conservacion" class="form-control"
                 value="<?= h($campos['conservacion'] ?? '') ?>"
                 placeholder="Ej: Conservar refrigerado entre 0 y 4 ºC">
          <!-- Sugerencias específicas por tipo -->
          <div class="mt-1 d-flex flex-wrap gap-1" id="pills-conservacion">
            <?php foreach ($sugsConserv as $sug): ?>
            <button type="button" class="btn btn-xs btn-outline-secondary sugerencia-txt"
                    data-txt="<?= h($sug) ?>" data-campo="conservacion"
                    style="font-size:.72rem;padding:2px 8px;white-space:normal;text-align:left;max-width:100%">
              <?= h($sug) ?>
            </button>
            <?php endforeach; ?>
            <?php foreach (Sugerencias::$conservacionCongelado as $sug): ?>
            <button type="button" class="btn btn-xs btn-outline-info sugerencia-txt"
                    data-txt="<?= h($sug) ?>" data-campo="conservacion"
                    style="font-size:.72rem;padding:2px 8px;white-space:normal;text-align:left;max-width:100%">
              <i class="bi bi-snow2"></i> <?= h($sug) ?>
            </button>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="col-md-10 campo-aesan critico">
          <label class="form-label">Instrucciones de uso / cocinado *</label>
          <input type="text" name="instruccion_uso" id="instruccion_uso" class="form-control"
                 value="<?= h($campos['instruccion_uso'] ?? '') ?>"
                 placeholder="Ej: Cocinar completamente antes de su consumo. Temperatura mínima 70 ºC.">
          <!-- Instrucciones específicas por tipo -->
          <div class="mt-1 d-flex flex-wrap gap-1">
            <?php foreach ($sugsInstruc as $sug): ?>
            <button type="button" class="btn btn-xs btn-outline-secondary sugerencia-txt"
                    data-txt="<?= h($sug) ?>" data-campo="instruccion_uso"
                    style="font-size:.72rem;padding:2px 8px;white-space:normal;text-align:left;max-width:100%">
              <?= h($sug) ?>
            </button>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="col-12">
          <label class="form-label fw-semibold">Estado del producto</label>
          <div class="d-flex gap-3 flex-wrap mb-2">
            <?php foreach (['fresco'=>'Fresco / Refrigerado','congelado'=>'Congelado','descongelado'=>'Descongelado (indicar en ficha)'] as $k=>$v): ?>
            <div class="form-check">
              <input class="form-check-input" type="radio" name="estado_producto"
                     value="<?= $k ?>" id="sp-<?= $k ?>"
                     <?= ($estadoProd)===$k?'checked':'' ?>>
              <label class="form-check-label" for="sp-<?= $k ?>"><?= $v ?></label>
            </div>
            <?php endforeach; ?>
          </div>

          <div id="wrap-fecha-cong" <?= $estadoProd==='congelado'?'':'style="display:none"' ?>>
            <label class="form-label fw-semibold">Fecha de congelación *
              <i class="bi bi-info-circle small" data-bs-toggle="tooltip"
                 title="Obligatoria (Anexo X, punto 3, Reglamento UE 1169/2011)"></i>
            </label>
            <input type="date" name="fecha_congelacion" class="form-control" style="max-width:220px"
                   value="<?= h($campos['fecha_congelacion'] ?? '') ?>">
            <div class="form-text text-warning"><i class="bi bi-exclamation-triangle"></i> Obligatoria y se mostrará en la ficha.</div>
          </div>

          <div id="wrap-descongelado-alert" <?= $estadoProd==='descongelado'?'':'style="display:none"' ?>>
            <div class="alert alert-warning py-2 small mt-2">
              <i class="bi bi-exclamation-triangle-fill"></i>
              <strong>DESCONGELADO</strong> — Se añadirá automáticamente:
              <em>"DESCONGELADO. Una vez descongelado no volver a congelar."</em>
            </div>
          </div>
        </div>
      </div>
      <script>
      document.querySelectorAll('[name="estado_producto"]').forEach(r => {
        r.addEventListener('change', () => {
          document.getElementById('wrap-fecha-cong').style.display       = r.value==='congelado'    ? '' : 'none';
          document.getElementById('wrap-descongelado-alert').style.display = r.value==='descongelado' ? '' : 'none';
        });
      });
      </script>

      <?php elseif ($paso===5): // ═══ PASO 5: Nutricional ═══════════════════ ?>
      <?php
        $nutRef  = Sugerencias::getNutricionalRef($tipo, $campos['especie'] ?? '_');
        $nutEtiq = [
          'energia_kj'=>'kJ','energia_kcal'=>'kcal','grasas'=>'Grasas totales (g)',
          'grasas_saturadas'=>'Saturadas (g)','hidratos'=>'H. carbono (g)',
          'azucares'=>'Azúcares (g)','proteinas'=>'Proteínas (g)','sal'=>'Sal (g)',
        ];
      ?>
      <h5 class="mb-1"><i class="bi bi-bar-chart"></i> Información nutricional</h5>
      <p class="text-muted small mb-3">Obligatoria para preparados y productos cárnicos. Las kcal se calculan automáticamente.</p>
      <?php if ($tipo==='carne_fresca'): ?>
      <div class="alert alert-info small"><i class="bi bi-info-circle"></i>
        La carne fresca sin aditivos está <strong>exenta</strong> de tabla nutricional. Puedes rellenarla de forma voluntaria.
      </div>
      <?php endif; ?>

      <?php if ($nutRef): ?>
      <div class="alert alert-success py-2 mb-3">
        <div class="d-flex align-items-center justify-content-between gap-2">
          <div>
            <i class="bi bi-stars"></i>
            <strong>Valores orientativos de referencia</strong>
            <span class="text-muted small ms-1">por 100 g · Fuente: BEDCA/USDA — verifica antes de publicar</span>
          </div>
          <button type="button" class="btn btn-sm btn-success flex-shrink-0" id="btn-aplicar-nutri">
            <i class="bi bi-magic"></i> Usar como base
          </button>
        </div>
        <div class="row g-1 mt-2">
          <?php foreach ($nutRef as $campo => $val): ?>
          <div class="col-auto">
            <span class="badge bg-light text-dark border" style="font-size:.75rem">
              <?= $nutEtiq[$campo] ?? $campo ?>: <strong><?= $val ?></strong>
            </span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <script>
      document.getElementById('btn-aplicar-nutri')?.addEventListener('click', () => {
        const ref = <?= json_encode($nutRef) ?>;
        Object.entries(ref).forEach(([k, v]) => {
          const el = document.querySelector(`[name="${k}"]`);
          if (el && !el.value) el.value = v;
        });
        // Trigger recalculo kcal
        document.getElementById('grasas')?.dispatchEvent(new Event('input'));
      });
      </script>
      <?php endif; ?>

      <div class="row g-3">
        <div class="col-md-8">
          <label class="form-label fw-semibold">Valor energético</label>
          <div class="input-group">
            <input type="number" name="energia_kj" id="energia_kj" class="form-control"
                   placeholder="kJ" value="<?= h($campos['energia_kj'] ?? '') ?>" min="0" step="1">
            <span class="input-group-text">kJ</span>
            <input type="number" name="energia_kcal" id="energia_kcal" class="form-control"
                   placeholder="kcal" value="<?= h($campos['energia_kcal'] ?? '') ?>" min="0" step="1">
            <span class="input-group-text">kcal</span>
          </div>
          <div class="form-text">Se calcula automáticamente al rellenar grasas, HC y proteínas.</div>
        </div>
        <?php foreach ([
          ['grasas',           'id="grasas"',    'Grasas totales (g)'],
          ['grasas_saturadas', '',               '&nbsp;&nbsp;de las cuales saturadas (g)'],
          ['hidratos',         'id="hidratos"',  'Hidratos de carbono (g)'],
          ['azucares',         '',               '&nbsp;&nbsp;de los cuales azúcares (g)'],
          ['proteinas',        'id="proteinas"', 'Proteínas (g)'],
          ['sal',              '',               'Sal (g)'],
        ] as [$campo, $attr, $lbl]): ?>
        <div class="col-md-4 col-6">
          <label class="form-label fw-semibold"><?= $lbl ?></label>
          <input type="number" name="<?= $campo ?>" <?= $attr ?> class="form-control"
                 step="0.1" min="0" value="<?= h($campos[$campo] ?? '') ?>">
        </div>
        <?php endforeach; ?>
      </div>

      <!-- Preview AESAN -->
      <div class="mt-4">
        <button type="button" class="btn btn-outline-secondary btn-sm mb-2" id="btn-preview">
          <i class="bi bi-eye"></i> Vista previa del bloque AESAN
        </button>
        <div class="preview-aesan d-none" id="aesan-preview"></div>
      </div>

      <?php endif; // fin pasos ?>

      <!-- Navegación -->
      <div class="d-flex justify-content-between mt-4 pt-3 border-top">
        <?php if ($paso>1): ?>
        <a href="<?= BASE_URL ?>/producto.php?id=<?= $prodId ?>&imp=<?= $impId ?>&paso=<?= $paso-1 ?>"
           class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Anterior</a>
        <?php else: ?>
        <a href="<?= BASE_URL ?>/productos.php?imp=<?= $impId ?>" class="btn btn-outline-secondary">
          <i class="bi bi-x"></i> Cancelar
        </a>
        <?php endif; ?>
        <div class="d-flex gap-2">
          <?php if (!$bloqueado): ?>
          <button type="submit" name="solo_guardar" value="1" class="btn btn-outline-primary">
            <i class="bi bi-floppy"></i> Guardar
          </button>
          <button type="submit" class="btn btn-primary">
            <?= $paso<5 ? '<i class="bi bi-arrow-right"></i> Siguiente' : '<i class="bi bi-check2-circle"></i> Finalizar' ?>
          </button>
          <?php else: ?>
          <span class="text-muted small align-self-center"><i class="bi bi-lock"></i> Solo lectura</span>
          <?php endif; ?>
        </div>
      </div>
      </div><!-- /wizard-card -->
    </form>
  </div>

  <!-- Panel lateral: info + historial -->
  <div class="col-lg-4">

    <!-- Resumen de campos -->
    <div class="card shadow-sm mb-3">
      <div class="card-header bg-white fw-semibold small">
        <i class="bi bi-clipboard-check"></i> Estado de campos
      </div>
      <div class="card-body p-2">
        <?php foreach (Validator::getCamposRequeridos($tipo) as $key => $info): ?>
        <?php
        if ($key === 'origen_pais') {
            $cubierto = !empty($campos['origen_pais'])
                || !empty($campos['origen_nacido'])
                || !empty($campos['origen_cria'])
                || !empty($campos['origen_sacrificado']);
        } elseif ($key === 'nutricional') {
            $cubierto = !empty($campos['nutricional'])
                || !empty($campos['energia_kcal'])
                || !empty($campos['energia_kj']);
        } else {
            $cubierto = !empty($campos[$key]);
        }
        ?>
        <div class="d-flex align-items-center gap-2 py-1 border-bottom" style="font-size:.82rem">
          <i class="bi bi-<?= $cubierto?'check-circle text-success':'x-circle text-danger' ?>"></i>
          <span class="<?= $cubierto?'':'text-danger' ?>"><?= h($info['label']) ?></span>
          <?php if (!$info['critico']): ?>
          <span class="badge bg-light text-dark border ms-auto" style="font-size:.7rem">Opcional</span>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Descripción original -->
    <div class="card shadow-sm mb-3">
      <div class="card-header bg-white fw-semibold small d-flex justify-content-between">
        <span><i class="bi bi-file-text"></i> Descripción original</span>
        <button class="btn btn-xs btn-link p-0 small" type="button"
                data-bs-toggle="collapse" data-bs-target="#desc-orig">Ver/Ocultar</button>
      </div>
      <div class="collapse" id="desc-orig">
        <div class="card-body p-2 small" style="max-height:200px;overflow-y:auto;font-size:.8rem">
          <?= $prod['desc_larga_original'] ?: '<em class="text-muted">Sin descripción</em>' ?>
        </div>
      </div>
    </div>

    <!-- Historial -->
    <?php if ($historial): ?>
    <div class="card shadow-sm">
      <div class="card-header bg-white fw-semibold small">
        <i class="bi bi-clock-history"></i> Historial de cambios
      </div>
      <div class="list-group list-group-flush" style="max-height:240px;overflow-y:auto">
        <?php foreach ($historial as $h): ?>
        <div class="list-group-item py-2 px-3" style="font-size:.8rem">
          <div class="d-flex justify-content-between">
            <span><?= Historial::icono($h['accion']) ?> <?= h($h['usuario_nombre']) ?></span>
            <span class="text-muted"><?= date('d/m H:i', strtotime($h['created_at'])) ?></span>
          </div>
          <?php if ($h['detalle']): ?>
          <div class="text-muted mt-1"><?= h($h['detalle']) ?></div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<script>
// ── Helpers ───────────────────────────────────────────────────────────────────
function fillField(name, value) {
  const el = document.querySelector(`[name="${name}"]`);
  if (el) { el.value = value; el.dispatchEvent(new Event('input')); }
}
function fillFieldIfEmpty(name, value) {
  const el = document.querySelector(`[name="${name}"]`);
  if (el && !el.value.trim()) { el.value = value; el.dispatchEvent(new Event('input')); }
}
function appendToField(name, value) {
  const el = document.querySelector(`[name="${name}"]`);
  if (!el) return;
  el.value = el.value ? el.value.trim() + ', ' + value : value;
  el.dispatchEvent(new Event('input'));
}

// ── Sync tipo select → hidden ─────────────────────────────────────────────────
document.getElementById('tipo_validado')?.addEventListener('change', function() {
  document.getElementById('tipo_validado_hidden').value = this.value;
  updateDenomSugerencias();
  updateLimitesPills();
});
document.getElementById('especie')?.addEventListener('change', function() {
  updateDenomSugerencias();
  updateLimitesPills();
});

// ── Denominaciones dinámicas (paso 1) ─────────────────────────────────────────
function updateDenomSugerencias() {
  const tipo    = document.getElementById('tipo_validado')?.value || 'otro';
  const especie = document.getElementById('especie')?.value || '_';
  const S       = window.SUGERENCIAS;
  if (!S) return;
  const m     = S.denominaciones[tipo] || S.denominaciones['otro'] || {};
  const lista = m[especie] || m['_'] || [];
  const box   = document.getElementById('denom-pills');
  if (!box) return;
  box.innerHTML = lista.map(s => {
    const v = s.replace(/&/g,'&amp;').replace(/"/g,'&quot;');
    return `<button type="button" class="btn btn-sm btn-outline-primary fill-pill"
      style="font-size:.75rem" data-campo="denominacion" data-val="${v}">${v}</button>`;
  }).join('');
}

// ── Límites grasa/colágeno pills (paso 1) ─────────────────────────────────────
function updateLimitesPills() {
  const especie = document.getElementById('especie')?.value || '_';
  const S = window.SUGERENCIAS;
  if (!S) return;
  const lims = S.limites[especie] || S.limites['_'] || {};

  const pgr = document.getElementById('pills-grasa');
  if (pgr) pgr.innerHTML = (lims.grasa || []).map(v =>
    `<button type="button" class="btn btn-xs btn-outline-warning"
       style="font-size:.7rem;padding:1px 6px"
       onclick="fillField('limite_grasa','${v}')">≤${v}%</button>`
  ).join('');

  const pcol = document.getElementById('pills-colageno');
  if (pcol) pcol.innerHTML = (lims.colageno || []).map(v =>
    `<button type="button" class="btn btn-xs btn-outline-warning"
       style="font-size:.7rem;padding:1px 6px"
       onclick="fillField('limite_colageno','${v}')">≤${v}%</button>`
  ).join('');
}

// Inicializar en paso 1
updateDenomSugerencias();
updateLimitesPills();

// ── Handler universal de pills (fill-pill / fill-empty-pill / append-pill) ────
// Evita el problema de json_encode/JSON.stringify en atributos onclick HTML
document.addEventListener('click', e => {
  const f = e.target.closest('.fill-pill');
  if (f) { fillField(f.dataset.campo, f.dataset.val); return; }
  const ef = e.target.closest('.fill-empty-pill');
  if (ef) { fillFieldIfEmpty(ef.dataset.campo, ef.dataset.val); return; }
  const ap = e.target.closest('.append-pill');
  if (ap) { appendToField(ap.dataset.campo, ap.dataset.val); return; }
});

// ── Sugerencias rápidas de texto (conservación/instrucción) ───────────────────
document.querySelectorAll('.sugerencia-txt').forEach(a => {
  a.addEventListener('click', e => {
    e.preventDefault();
    const campo = document.querySelector(`[name="${a.dataset.campo}"]`);
    if (campo) campo.value = a.dataset.txt;
  });
});

// ── Paso 2: helper "mismo país para todo" ─────────────────────────────────────
const btnMismoPais   = document.getElementById('btn-mismo-pais');
const inputMismoPais = document.getElementById('mismo-pais');
if (btnMismoPais && inputMismoPais) {
  btnMismoPais.addEventListener('click', () => {
    const pais = inputMismoPais.value.trim();
    if (!pais) return;
    document.querySelectorAll('.origen-field').forEach(f => {
      const col = f.closest('[class*="col-"]');
      if (col && col.style.display === 'none') return;
      f.value = pais;
    });
  });
  inputMismoPais.addEventListener('keydown', e => {
    if (e.key === 'Enter') { e.preventDefault(); btnMismoPais.click(); }
  });
}

// ── Bloqueo: heartbeat cada 30 s ─────────────────────────────────────────────
<?php if (!$bloqueado): ?>
const _lockInterval = setInterval(() => {
  const fd = new FormData();
  fd.append('action', 'heartbeat');
  fd.append('producto_id', window.PROD_ID);
  fetch(`${window.BASE_URL}/lock.php`, { method:'POST', body:fd }).catch(()=>{});
}, 30000);

// Liberar al salir de la página
window.addEventListener('beforeunload', () => {
  const fd = new FormData();
  fd.append('action', 'release');
  fd.append('producto_id', window.PROD_ID);
  navigator.sendBeacon(`${window.BASE_URL}/lock.php`, fd);
});
<?php endif; ?>

// ── Admin: forzar edición ─────────────────────────────────────────────────────
document.getElementById('btn-forzar-edicion')?.addEventListener('click', () => {
  const fd = new FormData();
  fd.append('action', 'release');
  fd.append('producto_id', window.PROD_ID);
  fetch(`${window.BASE_URL}/lock.php`, { method:'POST', body:fd })
    .then(() => location.reload());
});
</script>

<?php layout_end(); ?>
