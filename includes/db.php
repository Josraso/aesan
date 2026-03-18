<?php
// includes/db.php  – PDO wrapper singleton

class DB {
    private static ?PDO $pdo = null;

    public static function get(): PDO {
        if (self::$pdo === null) {
            if (!defined('DB_HOST')) {
                $cfg = dirname(__DIR__) . '/includes/config.php';
                if (file_exists($cfg)) require_once $cfg;
                else die('config.php no encontrado. Ejecuta el instalador primero.');
            }
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
            self::$pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }
        return self::$pdo;
    }

    public static function q(string $sql, array $params = []): PDOStatement {
        $st = self::get()->prepare($sql);
        $st->execute($params);
        return $st;
    }

    public static function row(string $sql, array $params = []): ?array {
        $r = self::q($sql, $params)->fetch();
        return $r ?: null;
    }

    public static function rows(string $sql, array $params = []): array {
        return self::q($sql, $params)->fetchAll();
    }

    public static function insert(string $table, array $data): int {
        $cols = implode(',', array_map(fn($c) => "`$c`", array_keys($data)));
        $ph   = implode(',', array_fill(0, count($data), '?'));
        self::q("INSERT INTO `$table` ($cols) VALUES ($ph)", array_values($data));
        return (int)self::get()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $wp = []): void {
        $set = implode(',', array_map(fn($c) => "`$c`=?", array_keys($data)));
        self::q("UPDATE `$table` SET $set WHERE $where", array_merge(array_values($data), $wp));
    }
}
