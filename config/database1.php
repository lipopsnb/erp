<?php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');       // Mặc định XAMPP
define('DB_PASS', '');           // Mặc định XAMPP không có password
define('DB_NAME', 'erp_system');
define('DB_CHARSET', 'utf8mb4');

function getDBConnection() {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            die('<div style="font-family:Arial;padding:20px;color:red;">
                <h3>❌ Lỗi kết nối Database</h3>
                <p>' . htmlspecialchars($e->getMessage()) . '</p>
                <p>Kiểm tra XAMPP đã bật MySQL chưa?</p>
            </div>');
        }
    }
    return $pdo;
}
?>