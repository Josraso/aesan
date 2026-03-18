<?php
// producto.php – Editor wizard con historial de cambios y notificaciones

require_once __DIR__ . '/includes/config_base.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/validator.php';
require_once __DIR__ . '/includes/exporter.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/historial.php';
require_once __DIR__ . '/includes/mailer.php';

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

    $camposAntes = $campos; // para historial

    $nuevos = $_POST;
    unset($nuevos['action'], $nuevos['paso'], $nuevos['_token'], $nuevos['solo_guardar']);

    $tipoNuevo    = $nuevos['tipo_validado']    ?? $prod['tipo_validado'];
    $usarSugerida = (int)($nuevos['desc_corta_usar']    ?? 0);
    $descSugerida = $nuevos['desc_corta_sugerida']       ?? $prod['desc_corta_sugerida'];
    unset($nuevos['tipo_validado'], $nuevos['desc_corta_usar'], $nuevos['desc_corta_sugerida']);

    // Merge: actualizar solo campos no vacíos (permite guardar parcialmente)
    foreach ($nuevos as $k => $v) {
        if ($v !== '' && $v !== null) $campos[$k] = $v;
    }

    $prodData = ['tipo_validado'=>$tipoNuevo,'tipo_detectado'=>$tipoNuevo,'campos_json'=>json_encode($campos)];
    $val      = Validator::validar($prodData);
    $estadoNuevo = $val['estado'];

    DB::update('productos', [
        'tipo_validado'       => $tipoNuevo,
        'campos_json'         => json_encode($campos),
        'estado'              => $estadoNuevo,
        'desc_corta_usar'     => $usarSugerida,
        'desc_corta_sugerida' => $descSugerida,
    ], 'id=?', [$prodId]);

    // Historial
    $detalle = Historial::describir($camposAntes, $campos);
    $accion  = $estadoNuevo === 'ok' ? 'completado' : 'editado';
    Historial::registrar($prodId, Auth::uid(), $accion, "Paso {$paso}: {$detalle}");

    // Email si se completó por primera vez
    if ($estadoNuevo === 'ok' && $prod['estado'] !== 'ok') {
        $imp    = DB::row('SELECT * FROM importaciones WHERE id=?', [$impId]);
        $admins = DB::rows("SELECT email FROM usuarios WHERE rol='admin' AND activo=1");
        foreach ($admins as $admin) {
            Mailer::notificarCompletado(Auth::nombre(), $imp['nombre_archivo'] ?? '', 1, $admin['email']);
        }
    }

    // Actualizar contadores de la importación
    if ($impId) {
        $cOk  = DB::row('SELECT COUNT(*) c FROM productos WHERE importacion_id=? AND estado="ok"',         [$impId])['c'];
        $cInc = DB::row('SELECT COUNT(*) c FROM productos WHERE importacion_id=? AND estado!="ok"',        [$impId])['c'];
        DB::update('importaciones', ['ok'=>$cOk,'incompletos'=>$cInc], 'id=?', [$impId]);
    }

    if (isset($_POST['solo_guardar'])) {
        flash('Cambios guardados.', 'success');
        redirect("producto.php?id={$prodId}&imp={$impId}&paso={$paso}");
    }

    $siguiente = $paso + 1;
    if ($siguiente > 5 || $estadoNuevo === 'ok') {
        flash('Producto guardado correctamente.', 'success');
        redirect("productos.php?imp={$impId}");
    }
    redirect("producto.php?id={$prodId}&imp={$impId}&paso={$siguiente}");
}

// Recargar
$prod   = DB::row('SELECT * FROM productos WHERE id=?', [$prodId]);
$campos = json_decode($prod['campos_json'] ?? '{}', true) ?: [];
$tipo   = $prod['tipo_validado'] ?? 'otro';
$reqs   = Validator::getCamposRequeridos($tipo);
$val    = Validator::validar($prod);

// Qué pasos tienen errores
$pasosConError = [];
foreach ($reqs as $key => $info) {
    if (empty($campos[$key]) && $info['critico']) $pasosConError[$info['paso']] = true;
}

// Historial
$historial = Historial::obtener($prodId, 10);

// JS: alérgenos
$alergenosJS = json_encode(Validator::ALERGENOS);

// Sugerencia desc corta
$sugerencia = '';
if (in_array($tipo, ['carne_picada','preparado_carne'])) {
    $d  = $campos['denominacion']    ?? '';
    $lg = $campos['limite_grasa']    ?? '';
    $lc = $campos['limite_colageno'] ?? '';
    if ($d) $sugerencia = $d . ($lg||$lc ? " (≤{$lg}% grasa" . ($lc?", ≤{$lc}% colágeno/prot.":"") . ")" : "");
}

layout_start('Editar — ' . $prod['nombre']);
?>
<script>window.ALERGENOS = <?= $alergenosJS ?>;</script>

<nav class="mb-3"><ol class="breadcrumb small mb-0">
  <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/dashboard.php">Dashboard</a></li>
  <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/productos.php?imp=<?= $impId ?>">Importación #<?= $impId ?></a></li>
  <li class="breadcrumb-item active"><?= h($prod['nombre']) ?></li>
</ol></nav>

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
    <form method="post" id="wizard-form">
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
          <input type="text" name="denominacion" class="form-control"
                 value="<?= h($campos['denominacion'] ?? '') ?>"
                 placeholder="Ej: Carne fresca de vacuno. Lomo alto madurado.">
          <div class="form-text text-muted">Denominación tal como debe aparecer en la ficha del producto.</div>
        </div>

        <div class="col-md-4" data-tipo="carne_picada,preparado_carne">
          <label class="form-label campo-aesan critico">Límite máx. grasa (%) *</label>
          <input type="number" name="limite_grasa" class="form-control"
                 min="0" max="60" step="0.1" value="<?= h($campos['limite_grasa'] ?? '') ?>" placeholder="Ej: 20">
          <div class="form-text">Vacuno ≤20% | Porcino ≤30% | Ovino ≤25%</div>
        </div>

        <div class="col-md-4" data-tipo="carne_picada,preparado_carne">
          <label class="form-label campo-aesan critico">Límite colágeno/proteína (%) *</label>
          <input type="number" name="limite_colageno" class="form-control"
                 min="0" max="30" step="0.1" value="<?= h($campos['limite_colageno'] ?? '') ?>" placeholder="Ej: 15">
          <div class="form-text">Vacuno/Ovino ≤15% | Porcino/Mixto ≤18%</div>
        </div>

        <div class="col-md-4">
          <label class="form-label">Peso neto / presentación</label>
          <input type="text" name="peso_unidad" class="form-control"
                 value="<?= h($campos['peso_unidad'] ?? '') ?>" placeholder="Ej: 400 g, 1 kg">
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
      <p class="text-muted small mb-3">Las menciones de origen deben figurar de forma expresa en la ficha. No es suficiente que se deduzcan del nombre o la raza.</p>
      <input type="hidden" name="especie" value="<?= h($especie) ?>">
      <div class="row g-3">

        <?php if ($especie==='vacuno' || !$especie): ?>
        <div class="col-12 <?= $especie!=='vacuno'?'origen-vacuno':'' ?>">
          <div class="alert alert-info py-2 small">
            <strong>Carne de vacuno:</strong> se requieren Nacido en / Criado en / Sacrificado en (o «Origen: X» si los tres coinciden).
          </div>
        </div>
        <?php foreach (['origen_nacido'=>'Nacido en','origen_criado'=>'Criado en','origen_sacrificado'=>'Sacrificado en'] as $k=>$lbl): ?>
        <div class="col-md-4 campo-aesan critico origen-vacuno">
          <label class="form-label"><?= $lbl ?> *</label>
          <input type="text" name="<?= $k ?>" class="form-control"
                 value="<?= h($campos[$k] ?? '') ?>" placeholder="España">
        </div>
        <?php endforeach; ?>
        <?php endif; ?>

        <?php if (in_array($especie,['porcino','aves','ovino']) || !$especie): ?>
        <div class="col-12 origen-otros">
          <div class="alert alert-info py-2 small">
            <strong>Porcino / Aves / Ovino:</strong> se requiere el país de cría y el país de sacrificio.
          </div>
        </div>
        <div class="col-md-6 campo-aesan critico origen-otros">
          <label class="form-label">País de cría *</label>
          <input type="text" name="origen_cria" class="form-control"
                 value="<?= h($campos['origen_cria'] ?? '') ?>" placeholder="España">
        </div>
        <div class="col-md-6 campo-aesan critico origen-otros">
          <label class="form-label">País de sacrificio *</label>
          <input type="text" name="origen_sacrificado" class="form-control"
                 value="<?= h($campos['origen_sacrificado'] ?? '') ?>" placeholder="España">
        </div>
        <?php endif; ?>

        <div class="col-md-6 campo-aesan critico origen-generico"
             <?= in_array($especie,['vacuno','porcino','aves','ovino'])?'style="display:none"':'' ?>>
          <label class="form-label">País de origen *</label>
          <input type="text" name="origen_pais" class="form-control"
                 value="<?= h($campos['origen_pais'] ?? '') ?>" placeholder="España">
        </div>
      </div>

      <?php elseif ($paso===3): // ═══ PASO 3: Ingredientes ══════════════════ ?>
      <?php $algsGuardados = $campos['alergenos_lista'] ? explode(',', $campos['alergenos_lista']) : []; ?>
      <h5 class="mb-1"><i class="bi bi-list-ul"></i> Ingredientes y alérgenos</h5>
      <p class="text-muted small mb-3">Los alérgenos se detectan automáticamente al escribir y se resaltarán en el HTML exportado.</p>
      <div class="row g-3">
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
        </div>
      </div>

      <?php elseif ($paso===4): // ═══ PASO 4: Conservación ══════════════════ ?>
      <h5 class="mb-1"><i class="bi bi-thermometer-half"></i> Conservación e instrucciones</h5>
      <p class="text-muted small mb-3">Información obligatoria antes de la compra para todos los productos cárnicos.</p>
      <div class="row g-3">
        <div class="col-md-10 campo-aesan critico">
          <label class="form-label">Condiciones de conservación *</label>
          <input type="text" name="conservacion" class="form-control"
                 value="<?= h($campos['conservacion'] ?? '') ?>"
                 placeholder="Ej: Conservar refrigerado entre 0 y 4 ºC">
          <div class="form-text">
            Sugerencias:
            <a href="#" class="text-primary sugerencia-txt" data-txt="Conservar refrigerado entre 0 y 4 ºC" data-campo="conservacion">Fresco</a> |
            <a href="#" class="text-primary sugerencia-txt" data-txt="Conservar congelado a -18 ºC o inferior" data-campo="conservacion">Congelado</a> |
            <a href="#" class="text-primary sugerencia-txt" data-txt="Conservar refrigerado entre 2 y 4 ºC" data-campo="conservacion">Preparado</a>
          </div>
        </div>
        <div class="col-md-10 campo-aesan critico">
          <label class="form-label">Instrucciones de uso / cocinado *</label>
          <input type="text" name="instruccion_uso" class="form-control"
                 value="<?= h($campos['instruccion_uso'] ?? '') ?>"
                 placeholder="Ej: Cocinar completamente antes de su consumo. Temperatura mínima 70 ºC.">
          <div class="form-text">
            Sugerencias:
            <a href="#" class="text-primary sugerencia-txt" data-txt="Cocinar completamente antes de su consumo. Temperatura interna mínima 70 ºC." data-campo="instruccion_uso">Hamburguesa cruda</a> |
            <a href="#" class="text-primary sugerencia-txt" data-txt="Listo para consumir. No requiere cocinado." data-campo="instruccion_uso">Listo para consumir</a>
          </div>
        </div>
        <div class="col-12">
          <label class="form-label fw-semibold">Estado del producto</label>
          <div class="d-flex gap-3 flex-wrap">
            <?php foreach (['fresco'=>'Fresco / Refrigerado','congelado'=>'Congelado','descongelado'=>'Descongelado (indicar en ficha)'] as $k=>$v): ?>
            <div class="form-check">
              <input class="form-check-input" type="radio" name="estado_producto"
                     value="<?= $k ?>" id="sp-<?= $k ?>"
                     <?= ($campos['estado_producto']??'fresco')===$k?'checked':'' ?>>
              <label class="form-check-label" for="sp-<?= $k ?>"><?= $v ?></label>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <?php elseif ($paso===5): // ═══ PASO 5: Nutricional ═══════════════════ ?>
      <h5 class="mb-1"><i class="bi bi-bar-chart"></i> Información nutricional</h5>
      <p class="text-muted small mb-3">Obligatoria para preparados y productos cárnicos. Las kcal se calculan automáticamente.</p>
      <?php if ($tipo==='carne_fresca'): ?>
      <div class="alert alert-info small"><i class="bi bi-info-circle"></i>
        La carne fresca sin aditivos está <strong>exenta</strong> de tabla nutricional. Puedes rellenarla de forma voluntaria.
      </div>
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
          <button type="submit" name="solo_guardar" value="1" class="btn btn-outline-primary">
            <i class="bi bi-floppy"></i> Guardar
          </button>
          <button type="submit" class="btn btn-primary">
            <?= $paso<5 ? '<i class="bi bi-arrow-right"></i> Siguiente' : '<i class="bi bi-check2-circle"></i> Finalizar' ?>
          </button>
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
        <?php $cubierto = !empty($campos[$key]); ?>
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
// Sync tipo select → hidden
document.getElementById('tipo_validado')?.addEventListener('change', function() {
  document.getElementById('tipo_validado_hidden').value = this.value;
});

// Sugerencias rápidas de texto
document.querySelectorAll('.sugerencia-txt').forEach(a => {
  a.addEventListener('click', e => {
    e.preventDefault();
    const campo = document.querySelector(`[name="${a.dataset.campo}"]`);
    if (campo) campo.value = a.dataset.txt;
  });
});
</script>

<?php layout_end(); ?>
