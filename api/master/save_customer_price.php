<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';
header('Content-Type: application/json');
requireLogin();
requireRole('director','accountant','manager');

$pdo  = getDBConnection();
$user = currentUser();

if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
    echo json_encode(['ok' => false, 'msg' => 'CSRF invalid']); exit;
}

$customerId    = (int)($_POST['customer_id']     ?? 0);
$productCodeId = (int)($_POST['product_code_id'] ?? 0);
$unitPrice     = (float)($_POST['unit_price']    ?? 0);
$note          = trim($_POST['note']             ?? '') ?: null;
$isActive      = isset($_POST['is_active']) ? 1 : 1;
$action        = trim($_POST['action']           ?? 'save');

if (!$customerId || !$productCodeId) {
    echo json_encode(['ok' => false, 'msg' => 'Thiếu khách hàng hoặc mã sản phẩm']); exit;
}
if ($unitPrice < 0) {
    echo json_encode(['ok' => false, 'msg' => 'Đơn giá không hợp lệ']); exit;
}

try {
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['ok' => false, 'msg' => 'Thiếu ID']); exit; }
        $pdo->prepare("DELETE FROM customer_prices WHERE id = ?")->execute([$id]);
        echo json_encode(['ok' => true, 'msg' => 'Đã xoá đơn giá']);
        exit;
    }

    $id = (int)($_POST['id'] ?? 0);

    if ($id) {
        // Update
        $pdo->prepare("
            UPDATE customer_prices
            SET customer_id = ?, product_code_id = ?, unit_price = ?, note = ?, is_active = ?
            WHERE id = ?
        ")->execute([$customerId, $productCodeId, $unitPrice, $note, $isActive, $id]);
        echo json_encode(['ok' => true, 'msg' => 'Đã cập nhật đơn giá']);
    } else {
        // Insert or update on duplicate
        $pdo->prepare("
            INSERT INTO customer_prices (customer_id, product_code_id, unit_price, note, is_active)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE unit_price = VALUES(unit_price),
                                    note       = VALUES(note),
                                    is_active  = VALUES(is_active)
        ")->execute([$customerId, $productCodeId, $unitPrice, $note, $isActive]);
        echo json_encode(['ok' => true, 'msg' => 'Đã lưu đơn giá']);
    }
} catch (Throwable $e) {
    error_log($e->getMessage());
    echo json_encode(['ok' => false, 'msg' => 'Lỗi hệ thống: ' . $e->getMessage()]);
}
