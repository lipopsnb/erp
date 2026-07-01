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
        SELECT sei.id AS export_item_id,
               sei.export_id,
               se.export_no,
               sei.product_code_id,
               sei.qty_export,
               fgs.type AS fgs_type,
               pc.product_code,
               pc.description,
               pc.unit
        FROM stock_export_items sei
        JOIN stock_exports se ON se.id = sei.export_id
        JOIN finished_goods_stock fgs ON fgs.id = sei.fgs_id
        JOIN product_codes pc ON pc.id = sei.product_code_id
        WHERE se.customer_id = ?
          AND se.status = 'confirmed'
          AND sei.delivery_id IS NULL
        ORDER BY se.export_date DESC, sei.id DESC
    ");
    $stmt->execute([$customerId]);

    echo json_encode(['ok' => true, 'items' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
}
?>
