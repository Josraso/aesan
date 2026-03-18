<?php
// includes/historial.php – gestión del historial de cambios

class Historial {

    /**
     * Registra un cambio en un producto
     */
    public static function registrar(int $productoId, int $usuarioId, string $accion, string $detalle = ''): void {
        try {
            DB::insert('historial_productos', [
                'producto_id' => $productoId,
                'usuario_id'  => $usuarioId,
                'accion'      => $accion,
                'detalle'     => substr($detalle, 0, 1000),
                'ip'          => $_SERVER['REMOTE_ADDR'] ?? '',
            ]);
        } catch (\Exception $e) {
            // Silencioso: no interrumpir el flujo principal
        }
    }

    /**
     * Obtiene el historial de un producto
     */
    public static function obtener(int $productoId, int $limit = 20): array {
        return DB::rows(
            "SELECT h.*, u.nombre AS usuario_nombre
             FROM historial_productos h
             JOIN usuarios u ON u.id = h.usuario_id
             WHERE h.producto_id = ?
             ORDER BY h.created_at DESC
             LIMIT ?",
            [$productoId, $limit]
        );
    }

    /**
     * Genera texto descriptivo del cambio comparando campos antes/después
     */
    public static function describir(array $camposAntes, array $camposDespues): string {
        $cambios = [];
        $labels  = [
            'denominacion'     => 'Denominación',
            'especie'          => 'Especie',
            'origen_nacido'    => 'Nacido en',
            'origen_criado'    => 'Criado en',
            'origen_sacrificado'=>'Sacrificado en',
            'origen_pais'      => 'País origen',
            'ingredientes'     => 'Ingredientes',
            'alergenos_lista'  => 'Alérgenos',
            'conservacion'     => 'Conservación',
            'instruccion_uso'  => 'Instrucción uso',
            'nutricional'      => 'Nutricional',
            'energia_kcal'     => 'Energía (kcal)',
            'grasas'           => 'Grasas',
            'proteinas'        => 'Proteínas',
        ];

        foreach ($labels as $key => $label) {
            $antes   = $camposAntes[$key]   ?? '';
            $despues = $camposDespues[$key] ?? '';
            if ($antes !== $despues) {
                if (!$antes && $despues) {
                    $cambios[] = "Añadido: {$label}";
                } elseif ($antes && !$despues) {
                    $cambios[] = "Eliminado: {$label}";
                } else {
                    $cambios[] = "Modificado: {$label}";
                }
            }
        }

        return $cambios ? implode(', ', $cambios) : 'Sin cambios detectados';
    }

    /**
     * Icono por tipo de acción
     */
    public static function icono(string $accion): string {
        return match($accion) {
            'importado'  => '<i class="bi bi-cloud-upload text-primary"></i>',
            'editado'    => '<i class="bi bi-pencil text-warning"></i>',
            'completado' => '<i class="bi bi-check-circle text-success"></i>',
            'exportado'  => '<i class="bi bi-download text-info"></i>',
            default      => '<i class="bi bi-circle text-secondary"></i>',
        };
    }
}
