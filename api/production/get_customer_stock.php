<?php
/**
 * API: Lấy danh sách SP trong kho thành phẩm của KH còn chưa giao hết
 * GET: customer_id
 *
 * Logic:
 *  - Tổng nhập kho (warehouse_items): qty_in  = SUM quantity của KH + SP
 *  - Tổng đã giao (delivery_items từ deliveries confirmed): qty_out
 *  - Tồn = qty_in - qty_out  > 0 thì hiện
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
header('Content-Type: application/json');
requireLogin();

$pdo        = getDBConnection();
$customerId = (int)($_GET['customer_id'] ?? 0);

if (!$customerId) {
    echo json_encode(['ok' => false, 'msg' => 'Thiếu customer_id']); exit;
}

/*
 * Kho thành phẩm = warehouse_items (bảng lưu SP hoàn thành / lỗi trả lại)
 * Đã giao        = delivery_items JOIN deliveries (status IN draft,confirmed,invoiced)
 *
 * Nếu bảng warehouse_items không tồn tại, fallback về warehouse_out_items
 */

// Kiểm tra bảng warehouse_items có tồn tại không
$tblCheck = $pdo->prepare("
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'warehouse_items'
");
$tblCheck->execute();
$hasWarehouseItems = (bool)$tblCheck->fetchColumn();

if ($hasWarehouseItems) {
    $stmt = $pdo->prepare("
        SELECT
            pc.id             AS product_code_id,
            pc.product_code,
            pc.description,
            pc.unit,
            COALESCE(stock.qty_in,  0)                         AS qty_in_stock,
            COALESCE(delivered.qty_out, 0)                     AS qty_delivered,
            COALESCE(stock.qty_in, 0) - COALESCE(delivered.qty_out, 0) AS qty_available
        FROM product_codes pc
        JOIN (
            SELECT product_code_id, SUM(quantity) AS qty_in
            FROM warehouse_items
            WHERE customer_id = ?
            GROUP BY product_code_id
        ) stock ON stock.product_code_id = pc.id
        LEFT JOIN (
            SELECT di.product_code_id, SUM(di.quantity) AS qty_out
            FROM delivery_items di
            JOIN deliveries d ON d.id = di.delivery_id
            WHERE d.customer_id = ?
              AND d.status IN ('draft','confirmed','invoiced')
            GROUP BY di.product_code_id
        ) delivered ON delivered.product_code_id = pc.id
        WHERE pc.is_active = 1
          AND (COALESCE(stock.qty_in, 0) - COALESCE(delivered.qty_out, 0)) > 0
        ORDER BY pc.product_code
    ");
    $stmt->execute([$customerId, $customerId]);
} else {
    // Fallback: dùng warehouse_out_items (phiếu xuất kho confirmed)
    $stmt = $pdo->prepare("
        SELECT
            pc.id             AS product_code_id,
            pc.product_code,
            pc.description,
            pc.unit,
            COALESCE(stock.qty_in,  0)                         AS qty_in_stock,
            COALESCE(delivered.qty_out, 0)                     AS qty_delivered,
            COALESCE(stock.qty_in, 0) - COALESCE(delivered.qty_out, 0) AS qty_available
        FROM product_codes pc
        JOIN (
            SELECT woi.product_code_id, SUM(woi.quantity) AS qty_in
            FROM warehouse_out_items woi
            JOIN warehouse_out wo ON wo.id = woi.warehouse_out_id
            WHERE wo.customer_id = ?
              AND wo.status = 'confirmed'
            GROUP BY woi.product_code_id
        ) stock ON stock.product_code_id = pc.id
        LEFT JOIN (
            SELECT di.product_code_id, SUM(di.quantity) AS qty_out
            FROM delivery_items di
            JOIN deliveries d ON d.id = di.delivery_id
            WHERE d.customer_id = ?
              AND d.status IN ('draft','confirmed','invoiced')
            GROUP BY di.product_code_id
        ) delivered ON delivered.product_code_id = pc.id
        WHERE pc.is_active = 1
          AND (COALESCE(stock.qty_in, 0) - COALESCE(delivered.qty_out, 0)) > 0
        ORDER BY pc.product_code
    ");
    $stmt->execute([$customerId, $customerId]);
}

$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['ok' => true, 'items' => $items]);
