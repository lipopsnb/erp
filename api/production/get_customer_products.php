<?php
/**
 * API: Lấy danh sách mã SP của một khách hàng (từ customer_prices đang hiệu lực)
 * GET: customer_id
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

// Lấy mã SP của KH này có giá đang hiệu lực
$stmt = $pdo->prepare("
    SELECT pc.id, pc.product_code, pc.description, pc.unit
    FROM customer_prices cp
    JOIN product_codes pc ON cp.product_code_id = pc.id
    WHERE cp.customer_id = ?
      AND cp.effective_date <= CURDATE()
      AND (cp.expired_date IS NULL OR cp.expired_date >= CURDATE())
      AND pc.is_active = 1
    GROUP BY pc.id
    ORDER BY pc.product_code
");
$stmt->execute([$customerId]);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['ok' => true, 'products' => $products]);
