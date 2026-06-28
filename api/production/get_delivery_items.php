<?php
/**
 * API: Lấy items của warehouse_out để tạo phiếu giao hàng
 * GET: warehouse_out_id, customer_id
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
header('Content-Type: application/json');
requireLogin();

$pdo           = getDBConnection();
$warehouseOutId = (int)($_GET['warehouse_out_id'] ?? 0);
$customerId    = (int)($_GET['customer_id']       ?? 0);

if (!$warehouseOutId) { echo json_encode(['ok' => false, 'msg' => 'Missing warehouse_out_id']); exit; }

$items = $pdo->prepare("
    SELECT woi.product_code_id, woi.quantity,
           pc.product_code, pc.description, pc.unit,
           COALESCE((
               SELECT cp.unit_price
               FROM customer_prices cp
               WHERE cp.product_code_id = woi.product_code_id
                 AND cp.customer_id = ?
                 AND cp.effective_date <= CURDATE()
                 AND (cp.expired_date IS NULL OR cp.expired_date >= CURDATE())
               ORDER BY cp.effective_date DESC, cp.id DESC
               LIMIT 1
           ), 0) AS unit_price
    FROM warehouse_out_items woi
    JOIN product_codes pc ON woi.product_code_id = pc.id
    WHERE woi.warehouse_out_id = ?
    ORDER BY woi.id
");
$items->execute([$customerId, $warehouseOutId]);
echo json_encode(['ok' => true, 'items' => $items->fetchAll(PDO::FETCH_ASSOC)]);
