<?php
/**
 * API: Cập nhật tiến độ gia công (wo_processes)
 * POST:
 *   action = 'save' | 'done_all'
 *   warehouse_in_id
 *   items[n][warehouse_in_item_id]
 *   items[n][product_code_id]
 *   items[n][quantity_input]
 *   items[n][quantity_done]
 *   items[n][quantity_rejected]
 *   items[n][status]       — processing | done
 *   items[n][process_date]
 *   items[n][note]
 * Khi status = 'done': tự tạo warehouse_items
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';
header('Content-Type: application/json');
requireLogin();
requireRole('director','accountant','warehouse','production','manager');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'msg' => 'Method not allowed']); exit;
}
if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
    echo json_encode(['ok' => false, 'msg' => 'CSRF invalid']); exit;
}

$pdo           = getDBConnection();
$user          = currentUser();
$warehouseInId = (int)($_POST['warehouse_in_id'] ?? 0);
$items         = $_POST['items'] ?? [];

if (!$warehouseInId || empty($items)) {
    echo json_encode(['ok' => false, 'msg' => 'Thiếu thông tin']); exit;
}

// Lấy customer_id từ phiếu nhập
$wi = $pdo->prepare("SELECT customer_id, status FROM warehouse_in WHERE id = ?");
$wi->execute([$warehouseInId]);
$wi = $wi->fetch();
if (!$wi) {
    echo json_encode(['ok' => false, 'msg' => 'Không tìm thấy phiếu nhập kho']); exit;
}
$customerId = (int)$wi['customer_id'];

try {
    $pdo->beginTransaction();

    $allDone = true;

    foreach ($items as $it) {
        $wItemId    = (int)($it['warehouse_in_item_id'] ?? 0);
        $pcId       = (int)($it['product_code_id']      ?? 0);
        $qtyInput   = (float)($it['quantity_input']      ?? 0);
        $qtyDone    = (float)($it['quantity_done']       ?? 0);
        $qtyReject  = (float)($it['quantity_rejected']   ?? 0);
        $status     = in_array($it['status'] ?? '', ['processing','done']) ? $it['status'] : 'processing';
        $procDate   = trim($it['process_date'] ?? date('Y-m-d')) ?: date('Y-m-d');
        $note       = trim($it['note'] ?? '') ?: null;

        if (!$pcId) continue;
        if ($status !== 'done') $allDone = false;

        // Upsert wo_processes
        $existing = $pdo->prepare("
            SELECT id FROM wo_processes
            WHERE warehouse_in_id = ? AND warehouse_in_item_id = ?
        ");
        $existing->execute([$warehouseInId, $wItemId]);
        $existId = $existing->fetchColumn();

        if ($existId) {
            $pdo->prepare("
                UPDATE wo_processes
                SET quantity_input = ?, quantity_done = ?, quantity_rejected = ?,
                    status = ?, process_date = ?, note = ?, updated_by = ?
                WHERE id = ?
            ")->execute([$qtyInput, $qtyDone, $qtyReject, $status, $procDate, $note, $user['id'], $existId]);
            $woId = $existId;
        } else {
            $pdo->prepare("
                INSERT INTO wo_processes
                    (warehouse_in_id, warehouse_in_item_id, product_code_id,
                     quantity_input, quantity_done, quantity_rejected,
                     status, process_date, note, updated_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([$warehouseInId, $wItemId, $pcId,
                         $qtyInput, $qtyDone, $qtyReject,
                         $status, $procDate, $note, $user['id']]);
            $woId = (int)$pdo->lastInsertId();
        }

        // Khi status = done → tạo / cập nhật warehouse_items
        if ($status === 'done') {
            // Chỉ xoá warehouse_items chưa được xuất kho/giao hàng
            $linked = $pdo->prepare("
                SELECT COUNT(*) FROM warehouse_items wi
                WHERE wi.wo_process_id = ?
                  AND wi.status IN ('delivered')
            ");
            $linked->execute([$woId]);
            if ((int)$linked->fetchColumn() > 0) {
                // Không xoá — hàng đã được giao, chỉ cập nhật wo_processes
                continue;
            }
            $pdo->prepare("DELETE FROM warehouse_items WHERE wo_process_id = ? AND status NOT IN ('delivered')")->execute([$woId]);

            // Thành phẩm đạt
            if ($qtyDone > 0) {
                $pdo->prepare("
                    INSERT INTO warehouse_items
                        (warehouse_in_id, wo_process_id, product_code_id, customer_id,
                         quantity, quantity_delivered, status)
                    VALUES (?, ?, ?, ?, ?, 0, 'waiting')
                ")->execute([$warehouseInId, $woId, $pcId, $customerId, $qtyDone]);
            }

            // Hàng lỗi → rejected
            if ($qtyReject > 0) {
                $pdo->prepare("
                    INSERT INTO warehouse_items
                        (warehouse_in_id, wo_process_id, product_code_id, customer_id,
                         quantity, quantity_delivered, status)
                    VALUES (?, ?, ?, ?, ?, 0, 'rejected')
                ")->execute([$warehouseInId, $woId, $pcId, $customerId, $qtyReject]);
            }
        }
    }

    // Nếu tất cả items đều done → cập nhật warehouse_in status = 'done'
    if ($allDone) {
        $pdo->prepare("UPDATE warehouse_in SET status = 'done' WHERE id = ?")->execute([$warehouseInId]);
    }

    $pdo->commit();
    echo json_encode(['ok' => true, 'msg' => 'Đã cập nhật tiến độ gia công']);

} catch (Throwable $e) {
    $pdo->rollBack();
    error_log($e->getMessage());
    echo json_encode(['ok' => false, 'msg' => 'Lỗi hệ thống: ' . $e->getMessage()]);
}
