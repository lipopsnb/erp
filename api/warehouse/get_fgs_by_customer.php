<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';

header('Content-Type: application/json');
requireLogin();
requireRole('director', 'accountant', 'warehouse', 'production', 'manager');

$pdo = getDBConnection();
$customerId = (int) ($_GET['customer_id'] ?? 0);

try {
    if ($customerId <= 0) {
        throw new RuntimeException('Thiếu khách hàng');
    }

    $stmt = $pdo->prepare("
        SELECT fgs.id, fgs.fgs_no, fgs.product_code_id, fgs.type, fgs.qty_remaining, fgs.source_date, fgs.note,
               pc.product_code, pc.description, pc.unit
        FROM finished_goods_stock fgs
        JOIN product_codes pc ON pc.id = fgs.product_code_id
        WHERE fgs.customer_id = ?
          AND fgs.status IN ('pending_export', 'partial_export')
          AND fgs.qty_remaining > 0
        ORDER BY fgs.source_date DESC, fgs.id DESC
    ");
    $stmt->execute([$customerId]);

    echo json_encode(['ok' => true, 'items' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
}
?>
