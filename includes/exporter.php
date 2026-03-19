<?php
// includes/exporter.php

require_once __DIR__ . '/validator.php';

class Exporter {

    /**
     * Mezcla el operador de la importación en $campos si el producto no tiene uno propio.
     * Llamar antes de generarBloqueAesan cuando se disponga de $importRow.
     */
    public static function prepararCampos(array $campos, array $importRow = []): array {
        if (empty($campos['operador_nombre']) && !empty($importRow['operador_nombre'])) {
            $campos['operador_nombre']    = $importRow['operador_nombre'];
            $campos['operador_direccion'] = $importRow['operador_direccion'] ?? '';
        }
        return $campos;
    }

    // ── Genera el bloque HTML AESAN para insertar en la descripción larga ────
    public static function generarBloqueAesan(array $campos, string $tipo): string {
        $c = $campos;

        // ── Pack: bloque especial ─────────────────────────────────────────────
        if ($tipo === 'pack') {
            return self::generarBloqueAesanPack($c);
        }

        // ── Ingredientes con alérgenos resaltados DENTRO de la lista ──────────
        // Reglamento (UE) 1169/2011, Art. 21: los alérgenos deben destacarse
        // tipográficamente EN la lista de ingredientes
        $ingredientesHtml = '';
        if (!empty($c['ingredientes'])) {
            $ingredientesHtml = Validator::resaltarAlergenos(htmlspecialchars($c['ingredientes']));
        }

        // ── Tabla nutricional ─────────────────────────────────────────────────
        $nutricionalHtml = '';
        $nutIncluir = ($c['nutricional_incluir'] ?? '1') !== '0'; // default ON para compatibilidad
        if ($nutIncluir && (!empty($c['energia_kcal']) || !empty($c['energia_kj']))) {
            $nutricionalHtml = self::tablaNutricional($c);
        }

        // ── Origen según especie ──────────────────────────────────────────────
        $origenHtml = self::bloqueOrigen($c);

        // ── Alérgenos: "Contiene:" o declaración explícita de ausencia ────────
        $alergenosHtml = '';
        $alergenosVal  = trim($c['alergenos_lista'] ?? '');
        if ($alergenosVal === 'ninguno') {
            // Declaración explícita de ausencia de alérgenos
            $alergenosHtml = '<p class="aesan-alergenos"><strong>Alérgenos:</strong> '
                . 'No contiene ningún alérgeno de los listados en el Anexo II del Reglamento (UE) 1169/2011.</p>';
        } elseif ($alergenosVal) {
            $lista = array_filter(array_map('trim', explode(',', $alergenosVal)));
            if ($lista) {
                $items = implode(', ', array_map(
                    fn($a) => '<strong>' . htmlspecialchars(Validator::labelAlergeno($a)) . '</strong>',
                    $lista
                ));
                $nota = $ingredientesHtml
                    ? ' <span style="font-size:.85em;color:#666">(destacados en la lista de ingredientes)</span>'
                    : '';
                $alergenosHtml = "<p class=\"aesan-alergenos\"><strong>Contiene:</strong> {$items}{$nota}</p>";
            }
        }

        // ── Estado congelación / descongelado ─────────────────────────────────
        // Reglamento (UE) 1169/2011, Anexo VI y Anexo X pto. 3
        $estadoCongHtml = '';
        $estadoCong = $c['estado_producto'] ?? '';
        if ($estadoCong === 'descongelado') {
            $estadoCongHtml = '<p class="aesan-descongelado" style="font-weight:700;color:#C0392B">⚠ DESCONGELADO. Una vez descongelado no volver a congelar.</p>';
        } elseif ($estadoCong === 'congelado') {
            $fechaCong = $c['fecha_congelacion'] ?? '';
            $estadoCongHtml = '<p class="aesan-congelado"><strong>Producto congelado.</strong>'
                . ($fechaCong ? ' Fecha de congelación: <strong>' . htmlspecialchars($fechaCong) . '</strong>' : '')
                . '</p>';
        }

        // ── Conservación ──────────────────────────────────────────────────────
        $conservHtml = '';
        if (!empty($c['conservacion'])) {
            $conservHtml = '<p class="aesan-conservacion"><strong>Conservación:</strong> '
                . htmlspecialchars($c['conservacion']) . '</p>';
        }

        // ── Instrucción de uso / cocinado ─────────────────────────────────────
        $usoHtml = '';
        if (!empty($c['instruccion_uso'])) {
            $usoHtml = '<p class="aesan-uso"><strong>Instrucciones de uso:</strong> '
                . htmlspecialchars($c['instruccion_uso']) . '</p>';
        }

        // ── Denominación legal ────────────────────────────────────────────────
        // Reglamento (UE) 1169/2011, Anexo VI, Parte B:
        // Para carne picada y hamburguesas la denominación DEBE incluir
        // el contenido máximo en materia grasa y la relación colágeno/proteínas
        $denomHtml = '';
        if (!empty($c['denominacion'])) {
            $denom = $c['denominacion'];
            if (in_array($tipo, ['carne_picada', 'preparado_carne'])) {
                $lg = trim($c['limite_grasa']    ?? '');
                $lc = trim($c['limite_colageno'] ?? '');
                if ($lg || $lc) {
                    $limites = [];
                    if ($lg) $limites[] = "contenido máximo en materia grasa: {$lg}%";
                    if ($lc) $limites[] = "relación colágeno/proteínas: {$lc}%";
                    $denom = rtrim($denom, '.') . ' (' . implode('; ', $limites) . ').';
                }
            }
            $denomHtml = '<p class="aesan-denominacion"><strong>Denominación:</strong> '
                . htmlspecialchars($denom) . '</p>';
        }

        // ── Peso neto ─────────────────────────────────────────────────────────
        $pesoHtml = '';
        if (!empty($c['peso_unidad'])) {
            $pesoHtml = '<p class="aesan-peso"><strong>Peso neto:</strong> '
                . htmlspecialchars($c['peso_unidad']) . '</p>';
        }

        // ── Operador responsable ──────────────────────────────────────────────
        // Reglamento (UE) 1169/2011, Art. 9.1.h y Art. 8.1: obligatorio
        $operadorHtml = '';
        if (!empty($c['operador_nombre'])) {
            $op = htmlspecialchars($c['operador_nombre']);
            if (!empty($c['operador_direccion'])) {
                $op .= ' · ' . htmlspecialchars($c['operador_direccion']);
            }
            $operadorHtml = "<p class=\"aesan-operador\"><strong>Responsable:</strong> {$op}</p>";
        }

        // ── Aviso si hay aditivos registrados que no aparecen en ingredientes ─
        $avisosHtml = '';
        $aditivos = trim($c['aditivos'] ?? '');
        if ($aditivos === 'ninguno') {
            $aditivos = ''; // declaración explícita de ausencia → no hay aviso, no hay sección
        } elseif ($aditivos && !empty($c['ingredientes'])) {
            $ingLower      = strtolower($c['ingredientes']);
            $noEncontrados = [];
            foreach (preg_split('/[,;]+/', $aditivos) as $ad) {
                $ad = trim($ad);
                if ($ad && !str_contains($ingLower, strtolower(mb_substr($ad, 0, 12)))) {
                    $noEncontrados[] = $ad;
                }
            }
            if ($noEncontrados) {
                $avisosHtml = "<!-- ⚠ REVISAR ANTES DE PUBLICAR: Los siguientes aditivos están registrados "
                    . "pero NO se detectan en la lista de ingredientes visible. "
                    . "Inclúyelos en la lista de ingredientes: "
                    . htmlspecialchars(implode('; ', $noEncontrados)) . " -->\n";
            }
        } // end elseif

        // ── Ensamblar bloque HTML ─────────────────────────────────────────────
        $html  = "\n<!-- AESAN_BLOCK_START -->\n";
        $html .= '<!-- AESAN_CAMPOS:' . base64_encode(json_encode(array_merge($campos, ['tipo_validado' => $tipo]))) . " -->\n";
        if ($avisosHtml) $html .= $avisosHtml;
        $html .= "<div class=\"info-alimentaria\">\n";
        $html .= "  <h3>Información alimentaria</h3>\n";
        if ($estadoCongHtml)   $html .= "  {$estadoCongHtml}\n";
        if ($denomHtml)        $html .= "  {$denomHtml}\n";
        if ($ingredientesHtml) {
            $html .= "  <p class=\"aesan-ingredientes\"><strong>Ingredientes:</strong> "
                  . $ingredientesHtml . "</p>\n";
        }
        if ($alergenosHtml)    $html .= "  {$alergenosHtml}\n";
        if ($origenHtml)       $html .= "  {$origenHtml}\n";
        if ($conservHtml)      $html .= "  {$conservHtml}\n";
        if ($usoHtml)          $html .= "  {$usoHtml}\n";
        if ($pesoHtml)         $html .= "  {$pesoHtml}\n";
        if ($nutricionalHtml)  $html .= "  {$nutricionalHtml}\n";
        if ($operadorHtml)     $html .= "  {$operadorHtml}\n";
        $html .= "</div>\n";
        $html .= "<!-- AESAN_BLOCK_END -->\n";

        return $html;
    }

    // ── Bloque AESAN específico para packs / lotes ───────────────────────────
    private static function generarBloqueAesanPack(array $c): string {
        $denomHtml = '';
        if (!empty($c['denominacion'])) {
            $denomHtml = '<p class="aesan-denominacion"><strong>Denominación:</strong> '
                . htmlspecialchars($c['denominacion']) . '</p>';
        }

        $contenidoHtml = '';
        if (!empty($c['contenido_pack'])) {
            $contenidoHtml = '<p class="aesan-contenido"><strong>Contenido:</strong> '
                . nl2br(htmlspecialchars($c['contenido_pack'])) . '</p>';
        }

        $pesoHtml = '';
        if (!empty($c['peso_unidad'])) {
            $pesoHtml = '<p class="aesan-peso"><strong>Peso / contenido:</strong> '
                . htmlspecialchars($c['peso_unidad']) . '</p>';
        }

        $origenHtml = self::bloqueOrigen($c);
        // Para packs con origen simple (no especie concreta) mostrar "Origen de la carne"
        if (!$origenHtml && !empty($c['origen_pais'])) {
            $origenHtml = '<p class="aesan-origen"><strong>Origen de la carne:</strong> '
                . htmlspecialchars($c['origen_pais']) . '</p>';
        }

        $alergenosHtml = '';
        $alergenosVal  = trim($c['alergenos_lista'] ?? '');
        if ($alergenosVal === 'ninguno') {
            $alergenosHtml = '<p class="aesan-alergenos"><strong>Alérgenos:</strong> '
                . 'No contiene ningún alérgeno de los listados en el Anexo II del Reglamento (UE) 1169/2011.</p>';
        } elseif ($alergenosVal) {
            $lista = array_filter(array_map('trim', explode(',', $alergenosVal)));
            if ($lista) {
                $items = implode(', ', array_map(
                    fn($a) => '<strong>' . htmlspecialchars(Validator::labelAlergeno($a)) . '</strong>',
                    $lista
                ));
                $alergenosHtml = "<p class=\"aesan-alergenos\"><strong>Contiene:</strong> {$items}</p>";
            }
        }

        $conservHtml = '';
        if (!empty($c['conservacion'])) {
            $conservHtml = '<p class="aesan-conservacion"><strong>Conservación:</strong> '
                . htmlspecialchars($c['conservacion']) . '</p>';
        }

        $operadorHtml = '';
        if (!empty($c['operador_nombre'])) {
            $op = htmlspecialchars($c['operador_nombre']);
            if (!empty($c['operador_direccion'])) {
                $op .= ' · ' . htmlspecialchars($c['operador_direccion']);
            }
            $operadorHtml = "<p class=\"aesan-operador\"><strong>Responsable:</strong> {$op}</p>";
        }

        $html  = "\n<!-- AESAN_BLOCK_START -->\n";
        $html .= '<!-- AESAN_CAMPOS:' . base64_encode(json_encode(array_merge($c, ['tipo_validado' => 'pack']))) . " -->\n";
        $html .= "<div class=\"info-alimentaria\">\n";
        $html .= "  <h3>Información alimentaria</h3>\n";
        if ($denomHtml)    $html .= "  {$denomHtml}\n";
        if ($contenidoHtml)$html .= "  {$contenidoHtml}\n";
        if ($pesoHtml)     $html .= "  {$pesoHtml}\n";
        if ($origenHtml)   $html .= "  {$origenHtml}\n";
        if ($alergenosHtml)$html .= "  {$alergenosHtml}\n";
        if ($conservHtml)  $html .= "  {$conservHtml}\n";
        if ($operadorHtml) $html .= "  {$operadorHtml}\n";
        $html .= "</div>\n";
        $html .= "<!-- AESAN_BLOCK_END -->\n";

        return $html;
    }

    // ── Bloque de origen según especie (Reglamentos CE 1760/2000, UE 1337/2013) ─
    private static function bloqueOrigen(array $c): string {
        $especie = $c['especie'] ?? '';
        $html    = '';

        if ($especie === 'vacuno') {
            // Reglamento (CE) 1760/2000 y 1825/2000: Nacido/Criado/Sacrificado en
            $nacido      = trim($c['origen_nacido']      ?? '');
            $criado      = trim($c['origen_criado']      ?? '');
            $sacrificado = trim($c['origen_sacrificado'] ?? '');
            if ($nacido && $nacido === $criado && $criado === $sacrificado) {
                // Todos coinciden → mención única con desglose explícito
                $html = '<p class="aesan-origen"><strong>Origen:</strong> '
                    . htmlspecialchars($nacido)
                    . ' <span style="font-size:.9em;color:#555">(nacido, criado y sacrificado en '
                    . htmlspecialchars($nacido) . ')</span></p>';
            } else {
                $filas = '';
                if ($nacido)      $filas .= '<li>Nacido en: <strong>'      . htmlspecialchars($nacido)      . '</strong></li>';
                if ($criado)      $filas .= '<li>Criado en: <strong>'      . htmlspecialchars($criado)      . '</strong></li>';
                if ($sacrificado) $filas .= '<li>Sacrificado en: <strong>' . htmlspecialchars($sacrificado) . '</strong></li>';
                if ($filas) $html = '<div class="aesan-origen"><strong>Origen:</strong><ul>' . $filas . '</ul></div>';
            }
        } elseif (in_array($especie, ['porcino','aves','ovino'])) {
            // Reglamento (UE) 1337/2013: país de cría y país de sacrificio
            $cria  = trim($c['origen_cria']        ?? '');
            $sacri = trim($c['origen_sacrificado'] ?? '');
            if ($cria && $cria === $sacri) {
                $html = '<p class="aesan-origen"><strong>Origen:</strong> '
                    . htmlspecialchars($cria)
                    . ' <span style="font-size:.9em;color:#555">(criado y sacrificado en '
                    . htmlspecialchars($cria) . ')</span></p>';
            } else {
                $filas = '';
                if ($cria)  $filas .= '<li>País de cría: <strong>'        . htmlspecialchars($cria)  . '</strong></li>';
                if ($sacri) $filas .= '<li>País de sacrificio: <strong>'  . htmlspecialchars($sacri) . '</strong></li>';
                if ($filas) $html = '<div class="aesan-origen"><strong>Origen:</strong><ul>' . $filas . '</ul></div>';
            }
        } elseif (!empty($c['origen_pais'])) {
            $html = '<p class="aesan-origen"><strong>Origen:</strong> '
                . htmlspecialchars($c['origen_pais']) . '</p>';
        }
        return $html;
    }

    // ── Tabla nutricional HTML ───────────────────────────────────────────────
    private static function tablaNutricional(array $c): string {
        $rows = [
            ['Valor energético', ($c['energia_kj'] ?? '') . ' kJ / ' . ($c['energia_kcal'] ?? '') . ' kcal'],
            ['Grasas', ($c['grasas'] ?? '') . ' g'],
            ['&nbsp;&nbsp;de las cuales saturadas', ($c['grasas_saturadas'] ?? '') . ' g'],
            ['Hidratos de carbono', ($c['hidratos'] ?? '') . ' g'],
            ['&nbsp;&nbsp;de los cuales azúcares', ($c['azucares'] ?? '') . ' g'],
            ['Proteínas', ($c['proteinas'] ?? '') . ' g'],
            ['Sal', ($c['sal'] ?? '') . ' g'],
        ];
        $tbody = '';
        foreach ($rows as $r) {
            $tbody .= "<tr><td>{$r[0]}</td><td>{$r[1]}</td></tr>\n";
        }
        return "<table class=\"tabla-nutricional\">\n"
            . "<caption>Información nutricional (por 100 g)</caption>\n"
            . "<thead><tr><th>Nutriente</th><th>Por 100 g</th></tr></thead>\n"
            . "<tbody>{$tbody}</tbody>\n"
            . "</table>\n";
    }

    // ── Fusionar descripción original + bloque AESAN ─────────────────────────
    public static function fusionarDescripcion(string $original, string $bloqueAesan): string {
        // Si ya hay un bloque AESAN previo → reemplazar
        if (str_contains($original, '<!-- AESAN_BLOCK_START -->')) {
            $original = preg_replace(
                '/\s*<!-- AESAN_BLOCK_START -->.*?<!-- AESAN_BLOCK_END -->\s*/s',
                '',
                $original
            );
        }
        return rtrim($original) . "\n" . $bloqueAesan;
    }

    // ── Exportar array de productos a CSV para PrestaShop ────────────────────
    public static function exportarCSV(array $productos, array $importRow = []): string {
        $delim = ';';
        $enc   = '"';
        $nl    = "\n";

        $cols = ['ID','Reference','Name','Short description','Description'];
        $out  = self::csvRow($cols, $delim, $enc) . $nl;

        foreach ($productos as $p) {
            $campos      = json_decode($p['campos_json'] ?? '{}', true) ?: [];
            $campos      = self::prepararCampos($campos, $importRow);
            $tipo        = $p['tipo_validado'] ?? $p['tipo_detectado'] ?? 'otro';
            $bloqueAesan = self::generarBloqueAesan($campos, $tipo);
            $descLarga  = self::fusionarDescripcion($p['desc_larga_original'] ?? '', $bloqueAesan);

            $descCorta  = ($p['desc_corta_usar'] && !empty($p['desc_corta_sugerida']))
                ? $p['desc_corta_sugerida']
                : ($p['desc_corta_original'] ?? '');

            $out .= self::csvRow([
                $p['ps_id']      ?? '',
                $p['referencia'] ?? '',
                $p['nombre']     ?? '',
                $descCorta,
                $descLarga,
            ], $delim, $enc) . $nl;
        }
        return $out;
    }

    private static function csvRow(array $fields, string $delim, string $enc): string {
        return implode($delim, array_map(
            fn($f) => $enc . str_replace($enc, $enc.$enc, $f ?? '') . $enc,
            $fields
        ));
    }
}
