<?php
// includes/auth.php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config_base.php';

class Auth {

    public static function start(): void {
        if (session_status() === PHP_SESSION_NONE) session_start();
    }

    public static function login(string $email, string $password): bool {
        self::start();
        $u = DB::row('SELECT * FROM usuarios WHERE email=? AND activo=1', [$email]);
        if ($u && password_verify($password, $u['password'])) {
            $_SESSION['uid']    = $u['id'];
            $_SESSION['nombre'] = $u['nombre'];
            $_SESSION['rol']    = $u['rol'];
            return true;
        }
        return false;
    }

    public static function logout(): void {
        self::start();
        session_destroy();
        header('Location: ' . BASE_URL . '/index.php');
        exit;
    }

    public static function check(string $rol = ''): void {
        self::start();
        if (empty($_SESSION['uid'])) {
            header('Location: ' . BASE_URL . '/index.php?msg=session');
            exit;
        }
        if ($rol && $_SESSION['rol'] !== $rol) {
            header('Location: ' . BASE_URL . '/dashboard.php?msg=forbidden');
            exit;
        }
    }

    public static function isAdmin(): bool {
        self::start();
        return ($_SESSION['rol'] ?? '') === 'admin';
    }

    public static function uid(): int    { return (int)($_SESSION['uid']    ?? 0); }
    public static function nombre(): string { return $_SESSION['nombre'] ?? ''; }
    public static function rol(): string    { return $_SESSION['rol']    ?? ''; }
}
