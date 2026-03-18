/* ============================================================
   AESAN Checker — app.js v2
   ============================================================ */

document.addEventListener('DOMContentLoaded', () => {

  // ── Bootstrap tooltips ──────────────────────────────────────
  document.querySelectorAll('[data-bs-toggle="tooltip"]')
    .forEach(el => new bootstrap.Tooltip(el));

  // ── Flash auto-dismiss ──────────────────────────────────────
  const flash = document.querySelector('.flash-msg .alert');
  if (flash) setTimeout(() => {
    flash.style.transition = 'opacity .5s';
    flash.style.opacity = '0';
    setTimeout(() => flash.closest('.flash-msg')?.remove(), 500);
  }, 4000);

  // ── Dropzone ────────────────────────────────────────────────
  const dz    = document.getElementById('dropzone');
  const input = document.getElementById('excel-input');
  if (dz && input) {
    dz.addEventListener('click', () => input.click());
    dz.addEventListener('dragover', e => { e.preventDefault(); dz.classList.add('drag-over'); });
    dz.addEventListener('dragleave', () => dz.classList.remove('drag-over'));
    dz.addEventListener('drop', e => {
      e.preventDefault();
      dz.classList.remove('drag-over');
      if (e.dataTransfer.files.length) {
        // Asignar fichero al input
        const dt = new DataTransfer();
        dt.items.add(e.dataTransfer.files[0]);
        input.files = dt.files;
        updateDropzoneLabel(e.dataTransfer.files[0].name);
      }
    });
    input.addEventListener('change', () => {
      if (input.files.length) updateDropzoneLabel(input.files[0].name);
    });
    function updateDropzoneLabel(name) {
      const lbl = dz.querySelector('.dz-label');
      if (lbl) lbl.textContent = '📄 ' + name;
    }
  }

  // ── Select-all + contador exportar ──────────────────────────
  const selAll   = document.getElementById('sel-all');
  const btnExport = document.getElementById('btn-exportar');

  function updateExportBtn() {
    if (!btnExport) return;
    const n = document.querySelectorAll('.sel-producto:checked').length;
    btnExport.disabled = n === 0;
    btnExport.innerHTML = n > 0
      ? `<i class="bi bi-download"></i> Exportar seleccionados (${n})`
      : `<i class="bi bi-download"></i> Exportar seleccionados`;
  }

  if (selAll) {
    selAll.addEventListener('change', () => {
      document.querySelectorAll('.sel-producto:not(:disabled)')
        .forEach(cb => cb.checked = selAll.checked);
      updateExportBtn();
    });
  }

  document.querySelectorAll('.sel-producto')
    .forEach(cb => cb.addEventListener('change', updateExportBtn));
  updateExportBtn();

  // ── Confirmar exportación ────────────────────────────────────
  const exportForm = document.getElementById('form-exportar');
  if (exportForm) {
    exportForm.addEventListener('submit', e => {
      const n = document.querySelectorAll('.sel-producto:checked').length;
      if (n === 0) { e.preventDefault(); return; }
      if (!confirm(`¿Exportar ${n} producto(s) al CSV para PrestaShop?\nSe marcarán como exportados.`))
        e.preventDefault();
    });
  }

  // ── Wizard: tipo de producto → mostrar/ocultar campos ────────
  const tipoSelect = document.getElementById('tipo_validado');
  const tipoHidden = document.getElementById('tipo_validado_hidden');

  if (tipoSelect) {
    tipoSelect.addEventListener('change', () => {
      if (tipoHidden) tipoHidden.value = tipoSelect.value;
      filtrarCamposTipo();
    });
    filtrarCamposTipo();
  }

  function filtrarCamposTipo() {
    const t = tipoSelect?.value || '';
    document.querySelectorAll('[data-tipo]').forEach(el => {
      const tipos = el.dataset.tipo.split(',');
      el.style.display = (tipos.includes(t) || tipos.includes('all')) ? '' : 'none';
    });
  }

  // ── Wizard: especie → campos de origen ──────────────────────
  const especieSelect = document.getElementById('especie');
  if (especieSelect) {
    especieSelect.addEventListener('change', adaptarOrigen);
    adaptarOrigen();
  }

  function adaptarOrigen() {
    const esp = especieSelect?.value || '';
    const esVacuno  = esp === 'vacuno';
    const esOtros   = ['porcino','aves','ovino'].includes(esp);
    const esGenerico = !esVacuno && !esOtros;

    document.querySelectorAll('.origen-vacuno')
      .forEach(el => el.style.display = (esVacuno || !esp) ? '' : 'none');
    document.querySelectorAll('.origen-otros')
      .forEach(el => el.style.display = (esOtros || !esp) ? '' : 'none');
    document.querySelectorAll('.origen-generico')
      .forEach(el => el.style.display = (esGenerico && esp) ? '' : 'none');
  }

  // ── Wizard: alérgenos interactivos ──────────────────────────
  const algTags   = document.querySelectorAll('.alg-tag');
  const algInput  = document.getElementById('alergenos_lista');
  const ingInput  = document.getElementById('ingredientes');
  const algHint   = document.getElementById('alg-hint');

  function syncAlergenos() {
    if (!algInput) return;
    const activos = [...algTags]
      .filter(t => t.classList.contains('active'))
      .map(t => t.dataset.alg);
    algInput.value = activos.join(',');
    if (algHint) {
      const labels = [...algTags]
        .filter(t => t.classList.contains('active'))
        .map(t => t.textContent.trim());
      algHint.textContent = labels.length
        ? '⚠ Detectados: ' + labels.join(', ') + '. Se resaltarán en el HTML exportado.'
        : '';
    }
  }

  // Inicializar desde valor guardado
  if (algInput && algTags.length) {
    const saved = algInput.value ? algInput.value.split(',').map(s => s.trim()) : [];
    algTags.forEach(tag => {
      if (saved.includes(tag.dataset.alg)) tag.classList.add('active');
      tag.addEventListener('click', () => {
        tag.classList.toggle('active');
        syncAlergenos();
      });
    });
  }

  // Auto-detección al escribir ingredientes
  if (ingInput) {
    ingInput.addEventListener('input', detectarAlergenos);
    detectarAlergenos();
  }

  function detectarAlergenos() {
    if (!ingInput || !algTags.length) return;
    const texto = ingInput.value.toLowerCase();
    const algs  = window.ALERGENOS || {};
    algTags.forEach(tag => {
      const terminos = algs[tag.dataset.alg] || [];
      if (terminos.some(t => texto.includes(t.toLowerCase()))) {
        tag.classList.add('active');
      }
    });
    syncAlergenos();
  }

  // ── Wizard: cálculo automático kcal ─────────────────────────
  const grasasI   = document.getElementById('grasas');
  const hcI       = document.getElementById('hidratos');
  const protI     = document.getElementById('proteinas');
  const kcalInput = document.getElementById('energia_kcal');
  const kjInput   = document.getElementById('energia_kj');

  if (grasasI && hcI && protI && kcalInput) {
    [grasasI, hcI, protI].forEach(el => el.addEventListener('input', calcKcal));
    calcKcal();
  }

  function calcKcal() {
    const g  = parseFloat(grasasI.value)  || 0;
    const hc = parseFloat(hcI.value)      || 0;
    const pr = parseFloat(protI.value)    || 0;
    const kcal = Math.round(g * 9 + hc * 4 + pr * 4);
    const kj   = Math.round(kcal * 4.184);
    if (!kcalInput.dataset.manual) kcalInput.value = kcal || '';
    if (kjInput && !kjInput.dataset.manual) kjInput.value = kj || '';
  }

  // Permitir edición manual de kcal
  if (kcalInput) kcalInput.addEventListener('focus', () => kcalInput.dataset.manual = '1');

  // ── Wizard: preview bloque AESAN ────────────────────────────
  const btnPreview  = document.getElementById('btn-preview');
  const previewDiv  = document.getElementById('aesan-preview');
  const wizardForm  = document.getElementById('wizard-form');

  if (btnPreview && previewDiv && wizardForm) {
    btnPreview.addEventListener('click', () => {
      const data = new FormData(wizardForm);
      data.set('action', 'preview');
      btnPreview.innerHTML = '<i class="bi bi-hourglass-split"></i> Generando...';
      btnPreview.disabled = true;

      fetch(window.location.pathname + window.location.search, {
        method: 'POST', body: data
      })
      .then(r => r.json())
      .then(d => {
        previewDiv.innerHTML = d.html || '<em class="text-muted">Sin datos suficientes.</em>';
        previewDiv.classList.remove('d-none');
      })
      .catch(() => {
        previewDiv.innerHTML = '<em class="text-danger">Error al generar la vista previa.</em>';
        previewDiv.classList.remove('d-none');
      })
      .finally(() => {
        btnPreview.innerHTML = '<i class="bi bi-eye"></i> Vista previa del bloque AESAN';
        btnPreview.disabled = false;
      });
    });
  }

  // ── Sugerencias rápidas de texto ────────────────────────────
  document.querySelectorAll('.sugerencia-txt').forEach(a => {
    a.addEventListener('click', e => {
      e.preventDefault();
      const campo = document.querySelector(`[name="${a.dataset.campo}"]`);
      if (campo) { campo.value = a.dataset.txt; campo.focus(); }
    });
  });

  // ── Preview descripción final (modal en productos.php) ───────
  // (el evento se declara inline en productos.php porque necesita BASE_URL)

});
