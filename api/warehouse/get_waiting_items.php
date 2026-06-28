<?php
/**
 * API: Lấy danh sách hàng đang chờ giao (warehouse_items status='waiting')
 * theo khách hàng
 * GET: customer_id
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
header('Content-Type: application/json');
requireLogin();

$pdo        = getDBConnection();
$customerId = (int)($_GET['customer_id'] ?? 0);
if (!$customerId) { echo json_encode(['ok' => false, 'msg' => 'Missing customer_id']); exit; }

$items = $pdo->prepare("
    SELECT wi.id, wi.product_code_id, wi.quantity,
           pc.product_code, pc.description, pc.unit
    FROM warehouse_items wi
    JOIN product_codes pc ON wi.product_code_id = pc.id
    WHERE wi.customer_id = ? AND wi.status = 'waiting'
      AND (wi.quantity - wi.quantity_delivered) > 0
    ORDER BY pc.product_code
");
$items->execute([$customerId]);
echo json_encode(['ok' => true, 'items' => $items->fetchAll(PDO::FETCH_ASSOC)]);
