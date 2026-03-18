<?php
// includes/validator.php
// Toda la lógica de detección de tipo y validación AESAN

class Validator {

    // ── Tipos de producto ────────────────────────────────────────────────────
    const TIPOS = [
        'carne_fresca'    => 'Carne fresca',
        'carne_picada'    => 'Carne picada',
        'preparado_carne' => 'Preparado de carne',
        'producto_carnico'=> 'Producto cárnico',
        'otro'            => 'Otro',
    ];

    // Palabras clave para detección automática (orden importa: más específico primero)
    private static array $keywords = [
        'carne_picada'    => ['picada','hamburguesa','burger','burger meat','carne molida'],
        'preparado_carne' => ['preparado','adobado','marinado','albóndiga','salchicha fresca',
                              'butifarra fresca','chorizo fresco','morcilla fresca','kebab',
                              'pincho','brocheta','churro de','filete empanado'],
        'producto_carnico'=> ['chorizo','jamón','lomo embuchado','salchichón','fuet','mortadela',
                              'jamón cocido','fiambre','cecina','morcilla curada','sobrasada',
                              'longaniza','paté','foie'],
        'carne_fresca'    => ['chuletón','chuleta','solomillo','lomo','costilla','entrecot',
                              'secreto','presa','pluma','aguja','falda','carrillera','rabo',
                              'morcillo','redondo','tapa','babilla','contra','vacío','brisket',
                              'rack','t-bone','tomahawk','vaca','buey','ternera','cordero',
                              'cerdo','pollo','pavo','conejo','pato','wagyu','angus'],
    ];

    // Especies para origen
    const ESPECIES = [
        'vacuno'  => ['vaca','buey','ternera','vacuno','angus','wagyu','frisona','rubia','charolesa'],
        'porcino' => ['cerdo','ibérico','porcino','cochinillo'],
        'aves'    => ['pollo','pavo','pato','oca','pintada','ave','aviar'],
        'ovino'   => ['oveja','cordero','ovino','cabrito','caprino','cabra'],
    ];

    // Alérgenos declarables (Reglamento UE 1169/2011 Anexo II)
    const ALERGENOS = [
        'gluten'      => ['trigo','centeno','cebada','avena','espelta','kamut','harina','almidón de trigo',
                          'proteína de trigo','pan rallado','sémola'],
        'crustaceos'  => ['gamba','langosta','cangrejo','cigala','langostino','bogavante'],
        'huevos'      => ['huevo','yema','clara','albúmina','lisozima'],
        'pescado'     => ['pescado','atún','bacalao','merluza','sardina','anchoa','salmón'],
        'cacahuetes'  => ['cacahuete','cacahuetes','maní'],
        'soja'        => ['soja','soya','proteína de soja','lecitina de soja'],
        'lacteos'     => ['leche','lactosa','nata','mantequilla','queso','suero','caseína','lactosuero'],
        'frutos_secos'=> ['almendra','avellana','nuez','anacardo','pistacho','nuez de macadamia',
                          'nuez de brasil','piñones'],
        'apio'        => ['apio'],
        'mostaza'     => ['mostaza'],
        'sesamo'      => ['sésamo','tahini','ajonjolí'],
        'sulfitos'    => ['sulfito','sulfuroso','dióxido de azufre','E220','E221','E222','E223',
                          'E224','E225','E226','E227','E228'],
        'altramuces'  => ['altramuz','lupino'],
        'moluscos'    => ['almeja','mejillón','ostra','calamar','pulpo','caracol'],
    ];

    // Campos obligatorios por tipo
    private static array $camposRequeridos = [
        'carne_fresca' => [
            'denominacion'   => ['label'=>'Denominación del alimento',      'paso'=>1, 'critico'=>true],
            'especie'        => ['label'=>'Especie animal',                  'paso'=>1, 'critico'=>true],
            'origen_pais'    => ['label'=>'País de origen',                  'paso'=>2, 'critico'=>true],
            'conservacion'   => ['label'=>'Condiciones de conservación',     'paso'=>4, 'critico'=>true],
            'peso_unidad'    => ['label'=>'Peso / unidad y precio por kg',   'paso'=>1, 'critico'=>false],
        ],
        'carne_picada' => [
            'denominacion'   => ['label'=>'Denominación del alimento',      'paso'=>1, 'critico'=>true],
            'especie'        => ['label'=>'Especie animal',                  'paso'=>1, 'critico'=>true],
            'limite_grasa'   => ['label'=>'Límite contenido en grasa (%)',   'paso'=>1, 'critico'=>true],
            'limite_colageno'=> ['label'=>'Límite colágeno/proteína (%)',    'paso'=>1, 'critico'=>true],
            'origen_pais'    => ['label'=>'País de origen',                  'paso'=>2, 'critico'=>true],
            'ingredientes'   => ['label'=>'Lista de ingredientes',           'paso'=>3, 'critico'=>true],
            'alergenos_lista'=> ['label'=>'Alérgenos declarados y resaltados','paso'=>3,'critico'=>true],
            'conservacion'   => ['label'=>'Condiciones de conservación',     'paso'=>4, 'critico'=>true],
            'instruccion_uso'=> ['label'=>'Instrucción de cocinado',         'paso'=>4, 'critico'=>true],
            'peso_unidad'    => ['label'=>'Peso / unidad y precio por kg',   'paso'=>1, 'critico'=>false],
        ],
        'preparado_carne' => [
            'denominacion'   => ['label'=>'Denominación del alimento',       'paso'=>1, 'critico'=>true],
            'especie'        => ['label'=>'Especie animal',                   'paso'=>1, 'critico'=>true],
            'origen_pais'    => ['label'=>'País de origen',                   'paso'=>2, 'critico'=>true],
            'ingredientes'   => ['label'=>'Lista de ingredientes completa',   'paso'=>3, 'critico'=>true],
            'alergenos_lista'=> ['label'=>'Alérgenos declarados y resaltados','paso'=>3,'critico'=>true],
            'conservacion'   => ['label'=>'Condiciones de conservación',      'paso'=>4, 'critico'=>true],
            'instruccion_uso'=> ['label'=>'Instrucción de cocinado (si crudo)','paso'=>4,'critico'=>true],
            'nutricional'    => ['label'=>'Información nutricional (tabla)',   'paso'=>5, 'critico'=>true],
            'peso_unidad'    => ['label'=>'Peso / unidad y precio por kg',    'paso'=>1, 'critico'=>false],
            'aditivos'       => ['label'=>'Aditivos utilizados',              'paso'=>3, 'critico'=>false],
        ],
        'producto_carnico' => [
            'denominacion'   => ['label'=>'Denominación del alimento',        'paso'=>1, 'critico'=>true],
            'especie'        => ['label'=>'Especie animal',                    'paso'=>1, 'critico'=>true],
            'origen_pais'    => ['label'=>'País de origen',                    'paso'=>2, 'critico'=>true],
            'ingredientes'   => ['label'=>'Lista de ingredientes completa',    'paso'=>3, 'critico'=>true],
            'alergenos_lista'=> ['label'=>'Alérgenos declarados y resaltados', 'paso'=>3,'critico'=>true],
            'conservacion'   => ['label'=>'Condiciones de conservación',       'paso'=>4, 'critico'=>true],
            'nutricional'    => ['label'=>'Información nutricional (tabla)',    'paso'=>5, 'critico'=>true],
            'peso_unidad'    => ['label'=>'Peso / unidad y precio por kg',     'paso'=>1, 'critico'=>false],
            'aditivos'       => ['label'=>'Aditivos utilizados',               'paso'=>3, 'critico'=>false],
            'instruccion_uso'=> ['label'=>'Instrucción de uso (si procede)',   'paso'=>4, 'critico'=>false],
        ],
        'otro' => [
            'denominacion'   => ['label'=>'Denominación del alimento',  'paso'=>1, 'critico'=>true],
            'conservacion'   => ['label'=>'Condiciones de conservación','paso'=>4, 'critico'=>false],
        ],
    ];

    // ── Detección automática de tipo ─────────────────────────────────────────
    public static function detectarTipo(string $nombre, string $descCorta = '', string $descLarga = ''): string {
        $texto = strtolower($nombre . ' ' . $descCorta . ' ' . substr($descLarga, 0, 500));
        // strip html
        $texto = strip_tags($texto);

        foreach (self::$keywords as $tipo => $palabras) {
            foreach ($palabras as $p) {
                if (str_contains($texto, $p)) return $tipo;
            }
        }
        return 'otro';
    }

    // ── Detección de especie ─────────────────────────────────────────────────
    public static function detectarEspecie(string $nombre, string $desc = ''): string {
        $texto = strtolower($nombre . ' ' . $desc);
        foreach (self::ESPECIES as $especie => $palabras) {
            foreach ($palabras as $p) {
                if (str_contains($texto, $p)) return $especie;
            }
        }
        return '';
    }

    // ── Validar un producto con su campos_json ya relleno ────────────────────
    public static function validar(array $producto): array {
        $tipo   = $producto['tipo_validado'] ?? $producto['tipo_detectado'] ?? 'otro';
        $campos = json_decode($producto['campos_json'] ?? '{}', true) ?: [];
        $reqs   = self::$camposRequeridos[$tipo] ?? self::$camposRequeridos['otro'];

        // Normalizar origen_pais a partir de campos específicos de especie
        if (empty($campos['origen_pais'])) {
            $campos['origen_pais'] = $campos['origen_nacido']
                ?? $campos['origen_cria']
                ?? $campos['origen_sacrificado']
                ?? '';
        }

        // nutricional se considera cubierto si hay al menos kcal o kj
        if (empty($campos['nutricional']) && (!empty($campos['energia_kcal']) || !empty($campos['energia_kj']))) {
            $campos['nutricional'] = 'ok';
        }

        $faltanCriticos    = [];
        $faltanSecundarios = [];

        foreach ($reqs as $key => $info) {
            $valor = $campos[$key] ?? '';
            if (empty($valor) || $valor === null) {
                if ($info['critico']) {
                    $faltanCriticos[] = $info['label'];
                } else {
                    $faltanSecundarios[] = $info['label'];
                }
            }
        }

        if (!empty($faltanCriticos)) {
            $estado = 'incompleto';
        } elseif (!empty($faltanSecundarios)) {
            $estado = 'ok'; // secundarios no bloquean
        } else {
            $estado = 'ok';
        }

        return [
            'estado'             => $estado,
            'faltan_criticos'    => $faltanCriticos,
            'faltan_secundarios' => $faltanSecundarios,
        ];
    }

    // ── Campos requeridos por tipo ───────────────────────────────────────────
    public static function getCamposRequeridos(string $tipo): array {
        return self::$camposRequeridos[$tipo] ?? self::$camposRequeridos['otro'];
    }

    // ── Detectar alérgenos en texto de ingredientes ──────────────────────────
    public static function detectarAlergenos(string $ingredientes): array {
        $texto     = strtolower($ingredientes);
        $detectados = [];
        foreach (self::ALERGENOS as $nombre => $terminos) {
            foreach ($terminos as $t) {
                if (str_contains($texto, strtolower($t))) {
                    $detectados[] = $nombre;
                    break;
                }
            }
        }
        return array_unique($detectados);
    }

    // ── Resaltar alérgenos en HTML (para la descripción exportada) ───────────
    public static function resaltarAlergenos(string $ingredientes): string {
        foreach (self::ALERGENOS as $terminos) {
            foreach ($terminos as $t) {
                $pattern = '/\b(' . preg_quote($t, '/') . ')\b/i';
                $ingredientes = preg_replace($pattern, '<strong class="alergeno">$1</strong>', $ingredientes);
            }
        }
        return $ingredientes;
    }

    // ── Labels de tipos ──────────────────────────────────────────────────────
    public static function labelTipo(string $tipo): string {
        return self::TIPOS[$tipo] ?? $tipo;
    }

    public static function labelAlergeno(string $key): string {
        $map = [
            'gluten'=>'Gluten','crustaceos'=>'Crustáceos','huevos'=>'Huevos',
            'pescado'=>'Pescado','cacahuetes'=>'Cacahuetes','soja'=>'Soja',
            'lacteos'=>'Lácteos','frutos_secos'=>'Frutos de cáscara','apio'=>'Apio',
            'mostaza'=>'Mostaza','sesamo'=>'Sésamo','sulfitos'=>'Sulfitos',
            'altramuces'=>'Altramuces','moluscos'=>'Moluscos',
        ];
        return $map[$key] ?? ucfirst($key);
    }
}
