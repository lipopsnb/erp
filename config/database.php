<?php
// ── Đặt múi giờ Việt Nam cho PHP ──────────────────────────────────────────
date_default_timezone_set('Asia/Ho_Chi_Minh');

function getDBConnection() {
    static $pdo = null;
    if ($pdo === null) {
        $host   = 'localhost';
        $dbname = 'liprolog_erp';
        $user   = 'liprolog_erp';
        $pass   = 'dung@123A';
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
