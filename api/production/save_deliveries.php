<?php
/**
 * API: Tạo / Xác nhận phiếu giao hàng (deliveries)
 * POST:
 *   action = 'save' | 'confirm' | 'delete'
 *   id, delivery_date, customer_id, warehouse_out_id, note
 *   items[n][product_code_id], items[n][quantity],
 *   items[n][unit_price], items[n][total_price], items[n][note]
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

$pdo    = getDBConnection();
$user   = currentUser();
$action = trim($_POST['action'] ?? 'save');
$id     = (int)($_POST['id'] ?? 0);

// ── Xoá ─────────────────────────────────────────────────────────────────
if ($action === 'delete') {
    if (!$id) { echo json_encode(['ok' => false, 'msg' => 'Thiếu ID']); exit; }
    $row = $pdo->prepare("SELECT status FROM deliveries WHERE id = ?");
    $row->execute([$id]);
    $row = $row->fetch();
    if (!$row || $row['status'] !== 'draft') {
        echo json_encode(['ok' => false, 'msg' => 'Chỉ xoá được phiếu ở trạng thái Nháp']); exit;
    }
    try {
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM delivery_items WHERE delivery_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM deliveries WHERE id = ?")->execute([$id]);
        $pdo->commit();
        echo json_encode(['ok' => true, 'msg' => 'Đã xoá phiếu giao hàng']);
    } catch (Throwable $e) {
        $pdo->rollBack();
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

// ── Xác nhận ─────────────────────────────────────────────────────────────
if ($action === 'confirm') {
    if (!$id) { echo json_encode(['ok' => false, 'msg' => 'Thiếu ID']); exit; }
    $pdo->prepare("UPDATE deliveries SET status = 'confirmed' WHERE id = ? AND status = 'draft'")
        ->execute([$id]);
    echo json_encode(['ok' => true, 'msg' => 'Đã xác nhận giao hàng — sẵn sàng xuất hoá đơn']);
    exit;
}

// ── Tạo / Sửa ────────────────────────────────────────────────────────────
$deliveryDate  = trim($_POST['delivery_date']   ?? date('Y-m-d'));
$customerId    = (int)($_POST['customer_id']    ?? 0);
$warehouseOutId = (int)($_POST['warehouse_out_id'] ?? 0) ?: null;
$note          = trim($_POST['note']            ?? '') ?: null;
$items         = $_POST['items'] ?? [];

if (!$deliveryDate || !$customerId) {
    echo json_encode(['ok' => false, 'msg' => 'Thiếu ngày hoặc khách hàng']); exit;
}

$validItems = [];
$grandTotal = 0;
foreach ($items as $it) {
    $pcId  = (int)($it['product_code_id'] ?? 0);
    $qty   = (float)($it['quantity']      ?? 0);
    $price = (float)($it['unit_price']    ?? 0);
    $total = (float)($it['total_price']   ?? ($qty * $price));
    if ($pcId && $qty > 0) {
        $validItems[] = [
            'product_code_id' => $pcId,
            'quantity'        => $qty,
            'unit_price'      => $price,
            'total_price'     => round($total),
            'note'            => trim($it['note'] ?? '') ?: null,
        ];
        $grandTotal += round($total);
    }
}
if (empty($validItems)) {
    echo json_encode(['ok' => false, 'msg' => 'Phải có ít nhất 1 dòng hàng hợp lệ']); exit;
}

try {
    $pdo->beginTransaction();

    if ($id) {
        $chk = $pdo->prepare("SELECT status FROM deliveries WHERE id = ?");
        $chk->execute([$id]);
        $ex = $chk->fetch();
        if (!$ex || $ex['status'] !== 'draft') {
            $pdo->rollBack();
            echo json_encode(['ok' => false, 'msg' => 'Chỉ sửa phiếu ở trạng thái Nháp']); exit;
        }
        $pdo->prepare("
            UPDATE deliveries
            SET delivery_date = ?, customer_id = ?, warehouse_out_id = ?, total_amount = ?, note = ?
            WHERE id = ?
        ")->execute([$deliveryDate, $customerId, $warehouseOutId, $grandTotal, $note, $id]);
        $pdo->prepare("DELETE FROM delivery_items WHERE delivery_id = ?")->execute([$id]);
    } else {
        // Sinh số phiếu DL-YYYYMMDD-XXX
        $pdo->prepare("
            INSERT INTO document_sequences (doc_type, doc_date, last_seq) VALUES ('DL',?,1)
            ON DUPLICATE KEY UPDATE last_seq = last_seq + 1
        ")->execute([$deliveryDate]);
        $seq = (int)$pdo->query("
            SELECT last_seq FROM document_sequences WHERE doc_type='DL' AND doc_date='$deliveryDate'
        ")->fetchColumn();
        $deliveryNo = 'DL-' . date('Ymd', strtotime($deliveryDate)) . '-' . str_pad($seq, 3, '0', STR_PAD_LEFT);

        $pdo->prepare("
            INSERT INTO deliveries
                (delivery_no, delivery_date, customer_id, warehouse_out_id,
                 total_amount, note, status, created_by)
            VALUES (?, ?, ?, ?, ?, ?, 'draft', ?)
        ")->execute([$deliveryNo, $deliveryDate, $customerId, $warehouseOutId, $grandTotal, $note, $user['id']]);
        $id = (int)$pdo->lastInsertId();
    }

    $stmt = $pdo->prepare("
        INSERT INTO delivery_items (delivery_id, product_code_id, quantity, unit_price, total_price, note)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    foreach ($validItems as $it) {
        $stmt->execute([$id, $it['product_code_id'], $it['quantity'],
                        $it['unit_price'], $it['total_price'], $it['note']]);
    }

    $pdo->commit();
    echo json_encode(['ok' => true, 'msg' => 'Đã lưu phiếu giao hàng', 'id' => $id]);

} catch (Throwable $e) {
    $pdo->rollBack();
    error_log($e->getMessage());
    echo json_encode(['ok' => false, 'msg' => 'Lỗi hệ thống: ' . $e->getMessage()]);
}
