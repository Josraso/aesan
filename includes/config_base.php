<?php
// includes/config_base.php
// Calcula BASE_PATH y BASE_URL dinámicamente

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

if (!defined('BASE_URL')) {
    // Cargar config generada por el instalador si existe
    $cfgFile = __DIR__ . '/config.php';
    if (file_exists($cfgFile) && !defined('DB_HOST')) {
        require_once $cfgFile;
    }

    if (defined('APP_URL') && APP_URL !== '') {
        define('BASE_URL', APP_URL);
    } else {
        // Calcular automáticamente desde SCRIPT_NAME
        // SCRIPT_NAME = /aesan/dashboard.php → BASE_URL = /aesan
        $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        define('BASE_URL', $scriptDir);
    }
}

/**
 * Genera una URL absoluta partiendo de la base de la app
 * url('dashboard.php') → /aesan/dashboard.php
 */
function url(string $path = ''): string {
    return BASE_URL . '/' . ltrim($path, '/');
}
