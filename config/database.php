<?php
function getDBConnection() {
    static $pdo = null;
    if ($pdo === null) {
        $host   = 'localhost';
        $dbname = 'liprolog_erp';
        $user   = 'liprolog_erp';
        $pass   = 'dung@123A';  // ← điền password bạn vừa đặt ở Step 2
        $pdo = new PDO(
            "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
            $user, $pass,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    }
    return $pdo;
}