<?php
// includes/functions.php

require_once __DIR__ . '/config_base.php';

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function redirect(string $url): void {
    // Si la URL no empieza por http ni /, añadir BASE_URL
    if (!str_starts_with($url, 'http') && !str_starts_with($url, '/')) {
        $url = BASE_URL . '/' . $url;
    }
    header('Location: ' . $url);
    exit;
}

function flash(string $msg, string $tipo = 'success'): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['flash'] = ['msg' => $msg, 'tipo' => $tipo];
}

function getFlash(): ?array {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}

function estadoBadge(string $estado): string {
    return match($estado) {
        'ok'         => '<span class="badge bg-success"><i class="bi bi-check-circle"></i> Completo</span>',
        'incompleto' => '<span class="badge bg-danger"><i class="bi bi-x-circle"></i> Incompleto</span>',
        default      => '<span class="badge bg-secondary"><i class="bi bi-hourglass"></i> Pendiente</span>',
    };
}

function tipoBadge(string $tipo): string {
    $map = [
        'carne_fresca'    => ['bg-info text-dark',    ''],
        'carne_picada'    => ['bg-warning text-dark',  ''],
        'preparado_carne' => ['bg-primary',            ''],
        'producto_carnico'=> ['bg-dark',               ''],
        'pack'            => ['bg-secondary',          'background:#6f42c1!important;color:#fff!important'],
        'otro'            => ['bg-secondary',          ''],
    ];
    [$cls, $style] = $map[$tipo] ?? ['bg-secondary', ''];
    $label = \Validator::labelTipo($tipo);
    $styleAttr = $style ? " style=\"{$style}\"" : '';
    return "<span class=\"badge {$cls}\"{$styleAttr}>{$label}</span>";
}

function porcentajeCompletado(array $producto): int {
    $campos = json_decode($producto['campos_json'] ?? '{}', true) ?: [];
    $tipo   = $producto['tipo_validado'] ?? $producto['tipo_detectado'] ?? 'otro';
    $reqs   = \Validator::getCamposRequeridos($tipo);
    if (!$reqs) return 100;
    $total    = count($reqs);
    $cubiertos = 0;
    foreach ($reqs as $key => $info) {
        if (!empty($campos[$key])) $cubiertos++;
    }
    return (int)round($cubiertos / $total * 100);
}
