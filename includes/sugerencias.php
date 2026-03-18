<?php
// includes/sugerencias.php — Asistencia contextual por tipo y especie (cumplimiento AESAN)

class Sugerencias {

    // ── Denominaciones legales por tipo y especie ──────────────────────────────
    private static array $denominaciones = [
        'carne_fresca' => [
            'vacuno'  => [
                'Carne fresca de vacuno.',
                'Lomo de vaca.',
                'Entrecot de vacuno.',
                'Solomillo de vaca.',
                'Chuleta de vacuno.',
                'Filete de ternera.',
                'Costilla de vacuno.',
                'Falda de vacuno.',
                'Aguja de vacuno.',
                'Pecho de vacuno. Apto para guisar.',
            ],
            'porcino' => [
                'Carne fresca de cerdo.',
                'Lomo de cerdo fresco.',
                'Solomillo de cerdo fresco.',
                'Costillas de cerdo frescas.',
                'Panceta fresca de cerdo.',
                'Chuleta de cerdo fresca.',
                'Secreto ibérico fresco.',
                'Presa ibérica fresca.',
                'Carrillera de cerdo fresca.',
            ],
            'aves'    => [
                'Carne fresca de pollo.',
                'Pechuga de pollo fresca.',
                'Muslos de pollo frescos.',
                'Contramuslos de pollo frescos.',
                'Alitas de pollo frescas.',
                'Carne fresca de pavo.',
                'Pechuga de pavo fresca.',
            ],
            'ovino'   => [
                'Carne fresca de cordero.',
                'Pierna de cordero fresca.',
                'Chuletas de cordero frescas.',
                'Costillas de cordero frescas.',
                'Paletilla de cordero fresca.',
                'Carne fresca de cabrito.',
            ],
            '_' => ['Carne fresca.'],
        ],
        'carne_picada' => [
            'vacuno'  => [
                'Carne picada de vacuno.',
                'Hamburguesa de vacuno.',
                'Carne picada de ternera.',
            ],
            'porcino' => [
                'Carne picada de cerdo.',
                'Hamburguesa de cerdo.',
                'Carne picada de cerdo ibérico.',
            ],
            'aves'    => [
                'Carne picada de pollo.',
                'Hamburguesa de pollo.',
                'Carne picada de pavo.',
            ],
            'ovino'   => [
                'Carne picada de cordero.',
                'Hamburguesa de cordero.',
            ],
            '_' => ['Carne picada.'],
        ],
        'preparado_carne' => [
            'vacuno'  => [
                'Preparado de carne de vacuno.',
                'Hamburguesa de vacuno con especias.',
                'Brocheta de vacuno.',
                'Albóndiga de vacuno.',
                'Filete ruso de vacuno.',
                'Carne de vacuno adobada.',
            ],
            'porcino' => [
                'Preparado de carne de cerdo.',
                'Pincho moruno de cerdo.',
                'Albóndiga de cerdo.',
                'Salchicha fresca de cerdo.',
                'Carne de cerdo adobada.',
                'Costillas de cerdo adobadas.',
                'Lomo de cerdo adobado.',
            ],
            'aves'    => [
                'Preparado de carne de pollo.',
                'Hamburguesa de pollo con especias.',
                'Nuggets de pollo.',
                'Alita de pollo marinada.',
                'Contramuslo de pollo adobado.',
                'Pechuga de pollo adobada.',
            ],
            'ovino'   => [
                'Preparado de carne de cordero.',
                'Hamburguesa de cordero con especias.',
                'Brocheta de cordero.',
                'Carne de cordero adobada.',
            ],
            '_' => ['Preparado de carne.'],
        ],
        'producto_carnico' => [
            '_' => [
                'Chorizo.',
                'Salchichón.',
                'Morcilla cocida.',
                'Fuet.',
                'Lomo embuchado.',
                'Butifarra.',
                'Longaniza.',
                'Salchicha cocida.',
                'Jamón cocido.',
                'Mortadela.',
            ],
        ],
        'otro' => [
            '_' => ['Preparación alimenticia de origen animal.'],
        ],
    ];

    // ── Plantillas de ingredientes por tipo y especie ──────────────────────────
    private static array $ingredientes = [
        'carne_fresca' => [
            'vacuno'  => 'Carne de vacuno (100%).',
            'porcino' => 'Carne de cerdo (100%).',
            'aves'    => 'Carne de pollo (100%).',
            'ovino'   => 'Carne de cordero (100%).',
            '_'       => 'Carne (100%).',
        ],
        'carne_picada' => [
            'vacuno'  => 'Carne picada de vacuno (100%).',
            'porcino' => 'Carne picada de cerdo (100%).',
            'aves'    => 'Carne picada de pollo (100%).',
            'ovino'   => 'Carne picada de cordero (100%).',
            '_'       => 'Carne picada (100%).',
        ],
        'preparado_carne' => [
            'vacuno'  => 'Carne de vacuno (87%), agua (8%), sal (1,5%), especias [pimienta negra, ajo en polvo] (0,5%), antioxidante: ascorbato sódico (E301).',
            'porcino' => 'Carne de cerdo (83%), agua (10%), sal (1,5%), especias [pimentón, comino, orégano] (1%), ajo (0,5%), conservante: nitrito sódico (E250), antioxidante: ascorbato sódico (E301).',
            'aves'    => 'Carne de pollo (86%), agua (9%), sal (1,5%), especias (1%), aromas naturales (0,5%), conservante: nitrito sódico (E250).',
            'ovino'   => 'Carne de cordero (87%), agua (8%), sal (1,5%), especias [pimentón, comino, menta] (1%), antioxidante: ascorbato sódico (E301).',
            '_'       => 'Carne (85%), agua, sal, especias.',
        ],
        'producto_carnico' => [
            '_' => 'Carne de cerdo (75%), tocino (15%), sal (2%), especias [pimentón dulce, orégano, ajo] (2%), azúcar (1%), conservante: nitrito sódico (E250), antioxidante: ascorbato sódico (E301).',
        ],
        'otro' => ['_' => ''],
    ];

    // ── Conservación por tipo (primer ítem = sugerencia principal) ─────────────
    private static array $conservacion = [
        'carne_fresca'    => [
            'Conservar refrigerado entre 0 y 4 ºC.',
            'Conservar refrigerado entre 0 y 2 ºC.',
            'Conservar refrigerado entre 0 y 4 ºC. Consumir antes de la fecha indicada.',
        ],
        'carne_picada'    => [
            'Conservar refrigerado entre 0 y 2 ºC. Consumir el mismo día de apertura del envase.',
            'Conservar refrigerado entre 0 y 4 ºC.',
        ],
        'preparado_carne' => [
            'Conservar refrigerado entre 2 y 4 ºC.',
            'Conservar refrigerado entre 0 y 4 ºC.',
            'Conservar refrigerado entre 0 y 4 ºC. Una vez abierto, consumir en 24 h.',
        ],
        'producto_carnico'=> [
            'Conservar en lugar fresco y seco. Una vez abierto, conservar refrigerado entre 2 y 6 ºC y consumir en 3-5 días.',
            'Conservar refrigerado entre 2 y 6 ºC.',
            'Conservar a temperatura ambiente. Una vez abierto, refrigerar y consumir en 5 días.',
        ],
        '_' => ['Conservar refrigerado entre 0 y 4 ºC.'],
    ];

    // ── Conservación para congelados ───────────────────────────────────────────
    public static array $conservacionCongelado = [
        'Conservar congelado a -18 ºC o inferior. No volver a congelar una vez descongelado.',
        'Conservar congelado a -18 ºC o inferior. Descongelar en refrigerador (0-4 ºC) antes de cocinar.',
    ];

    // ── Instrucciones de uso por tipo ──────────────────────────────────────────
    private static array $instrucciones = [
        'carne_fresca' => [
            'Cocinar completamente antes de su consumo. Temperatura interna mínima 70 ºC.',
            'Cocinar a la plancha o parrilla hasta completa cocción. Temperatura interna mínima 70 ºC.',
            'Cocinar en horno a 200 ºC hasta completa cocción.',
            'Apto para consumo en crudo (carpaccio, steak tartar) bajo responsabilidad del consumidor.',
        ],
        'carne_picada' => [
            'Cocinar completamente antes de su consumo. Temperatura interna mínima 70 ºC en todos los puntos. No consumir en crudo.',
            'Cocinar hasta que desaparezca completamente el color rosado. Temperatura interna mínima 70 ºC.',
            'Cocinar a la plancha a fuego medio-alto hasta total cocción. No consumir en crudo.',
        ],
        'preparado_carne' => [
            'Cocinar completamente antes de su consumo. Temperatura interna mínima 70 ºC.',
            'Freír en abundante aceite caliente hasta dorado uniforme. Temperatura interna mínima 70 ºC.',
            'Hornear a 180 ºC durante 20-25 minutos hasta completa cocción.',
            'Cocinar a la plancha a fuego medio-alto hasta completa cocción.',
            'Cocinar a la brasa hasta completa cocción. Temperatura interna mínima 70 ºC.',
            'Cocinar al vapor o hervido hasta temperatura interna mínima de 70 ºC.',
        ],
        'producto_carnico' => [
            'Listo para consumir. No requiere cocinado.',
            'Calentar antes de consumir si se desea.',
            'Se recomienda consumir a temperatura ambiente para apreciar sus cualidades organolépticas.',
            'Servir en lonchas finas. Listo para consumir.',
        ],
        '_' => [
            'Cocinar completamente antes de su consumo.',
            'Listo para consumir. No requiere cocinado.',
        ],
    ];

    // ── Límites legales grasa/colágeno para carne picada (% max) ──────────────
    // Fuente: Reglamento (CE) 853/2004, Anexo III, Sección V
    public static array $limites = [
        'vacuno'  => ['grasa' => ['12','15','20'],    'colageno' => ['10','12','15']],
        'porcino' => ['grasa' => ['20','25','30'],    'colageno' => ['12','15','18']],
        'aves'    => ['grasa' => ['10','12','15'],    'colageno' => ['8','10']],
        'ovino'   => ['grasa' => ['15','20','25'],    'colageno' => ['10','12','15']],
        '_'       => ['grasa' => ['15','20','25','30'], 'colageno' => ['10','12','15','18']],
    ];

    // ── Valores nutricionales de referencia por 100 g (orientativos) ───────────
    // Fuente: Tablas BEDCA / USDA. Solo para asistir al usuario, debe verificarlos.
    private static array $nutricional = [
        'carne_fresca' => [
            'vacuno'  => ['energia_kj'=>795, 'energia_kcal'=>190,'grasas'=>11.0,'grasas_saturadas'=>4.5,'hidratos'=>0,  'azucares'=>0,  'proteinas'=>20.0,'sal'=>0.10],
            'porcino' => ['energia_kj'=>900, 'energia_kcal'=>215,'grasas'=>14.0,'grasas_saturadas'=>5.0,'hidratos'=>0,  'azucares'=>0,  'proteinas'=>21.0,'sal'=>0.10],
            'aves'    => ['energia_kj'=>540, 'energia_kcal'=>129,'grasas'=>3.0, 'grasas_saturadas'=>1.0,'hidratos'=>0,  'azucares'=>0,  'proteinas'=>24.0,'sal'=>0.10],
            'ovino'   => ['energia_kj'=>720, 'energia_kcal'=>172,'grasas'=>9.0, 'grasas_saturadas'=>4.0,'hidratos'=>0,  'azucares'=>0,  'proteinas'=>22.0,'sal'=>0.10],
        ],
        'carne_picada' => [
            'vacuno'  => ['energia_kj'=>840, 'energia_kcal'=>200,'grasas'=>13.0,'grasas_saturadas'=>5.0,'hidratos'=>0,  'azucares'=>0,  'proteinas'=>19.0,'sal'=>0.15],
            'porcino' => ['energia_kj'=>1050,'energia_kcal'=>250,'grasas'=>20.0,'grasas_saturadas'=>7.0,'hidratos'=>0,  'azucares'=>0,  'proteinas'=>19.0,'sal'=>0.15],
            'aves'    => ['energia_kj'=>630, 'energia_kcal'=>150,'grasas'=>7.0, 'grasas_saturadas'=>2.0,'hidratos'=>0,  'azucares'=>0,  'proteinas'=>21.0,'sal'=>0.15],
            'ovino'   => ['energia_kj'=>756, 'energia_kcal'=>180,'grasas'=>11.0,'grasas_saturadas'=>5.0,'hidratos'=>0,  'azucares'=>0,  'proteinas'=>20.0,'sal'=>0.15],
        ],
        'preparado_carne' => [
            '_' => ['energia_kj'=>750,'energia_kcal'=>180,'grasas'=>10.0,'grasas_saturadas'=>3.5,'hidratos'=>3.0,'azucares'=>0.5,'proteinas'=>19.0,'sal'=>1.2],
        ],
        'producto_carnico' => [
            '_' => ['energia_kj'=>1400,'energia_kcal'=>335,'grasas'=>28.0,'grasas_saturadas'=>10.0,'hidratos'=>1.0,'azucares'=>0.5,'proteinas'=>20.0,'sal'=>2.5],
        ],
    ];

    // ── Opciones rápidas ───────────────────────────────────────────────────────
    public static array $pesos   = ['100 g','200 g','250 g','300 g','400 g','500 g','750 g','1 kg','1,5 kg','2 kg','5 kg','Granel'];
    public static array $paises  = ['España','Alemania','Francia','Italia','Países Bajos','Portugal','Polonia','Irlanda','Dinamarca','Reino Unido','Brasil','Argentina'];

    // ── Getters ────────────────────────────────────────────────────────────────
    public static function getDenominaciones(string $tipo, string $especie): array {
        $m = self::$denominaciones[$tipo] ?? self::$denominaciones['otro'];
        return $m[$especie] ?? $m['_'] ?? [];
    }

    public static function getIngredientes(string $tipo, string $especie): string {
        $m = self::$ingredientes[$tipo] ?? [];
        return $m[$especie] ?? $m['_'] ?? '';
    }

    public static function getConservacion(string $tipo): array {
        return self::$conservacion[$tipo] ?? self::$conservacion['_'];
    }

    public static function getInstrucciones(string $tipo): array {
        return self::$instrucciones[$tipo] ?? self::$instrucciones['_'];
    }

    public static function getNutricionalRef(string $tipo, string $especie): array {
        $m = self::$nutricional[$tipo] ?? [];
        return $m[$especie] ?? $m['_'] ?? [];
    }

    /** Exporta todo como JSON para uso en JS */
    public static function toJson(): string {
        return json_encode([
            'denominaciones' => self::$denominaciones,
            'ingredientes'   => self::$ingredientes,
            'conservacion'   => self::$conservacion,
            'congelado'      => self::$conservacionCongelado,
            'instrucciones'  => self::$instrucciones,
            'nutricional'    => self::$nutricional,
            'limites'        => self::$limites,
            'pesos'          => self::$pesos,
            'paises'         => self::$paises,
        ]);
    }
}
