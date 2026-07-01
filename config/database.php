<?php
// ── Đặt múi giờ Việt Nam cho PHP ──────────────────────────────────────────
<<<<<<< HEAD
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
=======
>>>>>>> 2d78dfa9065c6d955e30172aa04de19c7e992c2f
date_default_timezone_set('Asia/Ho_Chi_Minh');

function getDBConnection() {
    static $pdo = null;
    if ($pdo === null) {
        $host   = getenv('DB_HOST') ?: 'localhost';
        $dbname = getenv('DB_NAME') ?: 'erp';
        $user   = getenv('DB_USER') ?: 'root';
        $pass   = getenv('DB_PASS') ?: '';
        $pdo = new PDO(
            "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
            $user, $pass,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
        // ── Đặt múi giờ cho MySQL session ─────────────────────────────────
        $pdo->exec("SET time_zone = '+07:00'");
    }
    return $pdo;
}
