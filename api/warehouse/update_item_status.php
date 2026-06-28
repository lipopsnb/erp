<?php
/**
 * API: Cập nhật trạng thái warehouse_items (kho thành phẩm)
 * POST:
 *   action = 'update_status'
 *   id           — warehouse_item ID
 *   status       — done | waiting | delivered | rejected
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';
header('Content-Type: application/json');
requireLogin();
requireRole('director');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'msg' => 'Method not allowed']); exit;
}
if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
    echo json_encode(['ok' => false, 'msg' => 'CSRF invalid']); exit;
}

$pdo    = getDBConnection();
$id     = (int)($_POST['id']     ?? 0);
$status = trim($_POST['status']  ?? '');

$allowed = ['done', 'waiting', 'delivered', 'rejected'];
if (!$id || !in_array($status, $allowed)) {
    echo json_encode(['ok' => false, 'msg' => 'Thông tin không hợp lệ']); exit;
}

try {
    $pdo->prepare("UPDATE warehouse_items SET status = ? WHERE id = ?")
        ->execute([$status, $id]);
    echo json_encode(['ok' => true, 'msg' => 'Đã cập nhật trạng thái']);
} catch (Throwable $e) {
    error_log($e->getMessage());
    echo json_encode(['ok' => false, 'msg' => 'Lỗi hệ thống']);
}
