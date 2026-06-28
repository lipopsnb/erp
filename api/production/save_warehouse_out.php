<?php
/**
 * API: Tạo / Xác nhận phiếu xuất kho thành phẩm (warehouse_out)
 * POST:
 *   action = 'save' | 'confirm' | 'delete'
 *   id, export_date, customer_id, note
 *   items[n][warehouse_item_id], items[n][product_code_id],
 *   items[n][quantity], items[n][note]
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
    $row = $pdo->prepare("SELECT status FROM warehouse_out WHERE id = ?");
    $row->execute([$id]);
    $row = $row->fetch();
    if (!$row || $row['status'] !== 'draft') {
        echo json_encode(['ok' => false, 'msg' => 'Chỉ xoá được phiếu ở trạng thái Nháp']); exit;
    }
    try {
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM warehouse_out_items WHERE warehouse_out_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM warehouse_out WHERE id = ?")->execute([$id]);
        $pdo->commit();
        echo json_encode(['ok' => true, 'msg' => 'Đã xoá phiếu xuất kho']);
    } catch (Throwable $e) {
        $pdo->rollBack();
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

// ── Xác nhận xuất kho ────────────────────────────────────────────────────
if ($action === 'confirm') {
    if (!$id) { echo json_encode(['ok' => false, 'msg' => 'Thiếu ID']); exit; }
    try {
        $pdo->beginTransaction();
        // Cập nhật status phiếu
        $pdo->prepare("UPDATE warehouse_out SET status = 'confirmed' WHERE id = ? AND status = 'draft'")
            ->execute([$id]);

        // Cập nhật warehouse_items → delivered (với kiểm tra không vượt quantity)
        $outItems = $pdo->prepare("SELECT warehouse_item_id, quantity FROM warehouse_out_items WHERE warehouse_out_id = ?");
        $outItems->execute([$id]);
        foreach ($outItems->fetchAll() as $oi) {
            $wiRow = $pdo->prepare("SELECT quantity, quantity_delivered FROM warehouse_items WHERE id = ? FOR UPDATE");
            $wiRow->execute([$oi['warehouse_item_id']]);
            $wi = $wiRow->fetch();
            if (!$wi) continue;
            $newDelivered = min($wi['quantity'], $wi['quantity_delivered'] + $oi['quantity']);
            $pdo->prepare("
                UPDATE warehouse_items
                SET status = 'delivered',
                    quantity_delivered = ?
                WHERE id = ?
            ")->execute([$newDelivered, $oi['warehouse_item_id']]);
        }

        $pdo->commit();
        echo json_encode(['ok' => true, 'msg' => 'Đã xác nhận xuất kho']);
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log($e->getMessage());
        echo json_encode(['ok' => false, 'msg' => 'Lỗi hệ thống: ' . $e->getMessage()]);
    }
    exit;
}

// ── Tạo / Sửa ────────────────────────────────────────────────────────────
$exportDate = trim($_POST['export_date'] ?? date('Y-m-d'));
$customerId = (int)($_POST['customer_id'] ?? 0);
$note       = trim($_POST['note']        ?? '') ?: null;
$items      = $_POST['items'] ?? [];

if (!$exportDate || !$customerId) {
    echo json_encode(['ok' => false, 'msg' => 'Thiếu ngày hoặc khách hàng']); exit;
}

$validItems = [];
foreach ($items as $it) {
    $wiId  = (int)($it['warehouse_item_id'] ?? 0);
    $pcId  = (int)($it['product_code_id']   ?? 0);
    $qty   = (float)($it['quantity']        ?? 0);
    if ($wiId && $pcId && $qty > 0) {
        $validItems[] = [
            'warehouse_item_id' => $wiId,
            'product_code_id'   => $pcId,
            'quantity'          => $qty,
            'note'              => trim($it['note'] ?? '') ?: null,
        ];
    }
}
if (empty($validItems)) {
    echo json_encode(['ok' => false, 'msg' => 'Phải chọn ít nhất 1 mặt hàng']); exit;
}

try {
    $pdo->beginTransaction();

    if ($id) {
        $chk = $pdo->prepare("SELECT status FROM warehouse_out WHERE id = ?");
        $chk->execute([$id]);
        $ex = $chk->fetch();
        if (!$ex || $ex['status'] !== 'draft') {
            $pdo->rollBack();
            echo json_encode(['ok' => false, 'msg' => 'Chỉ sửa phiếu ở trạng thái Nháp']); exit;
        }
        $pdo->prepare("UPDATE warehouse_out SET export_date = ?, customer_id = ?, note = ? WHERE id = ?")
            ->execute([$exportDate, $customerId, $note, $id]);
        $pdo->prepare("DELETE FROM warehouse_out_items WHERE warehouse_out_id = ?")->execute([$id]);
    } else {
        // Sinh số phiếu WO-YYYYMMDD-XXX
        $pdo->prepare("
            INSERT INTO document_sequences (doc_type, doc_date, last_seq) VALUES ('WO',?,1)
            ON DUPLICATE KEY UPDATE last_seq = last_seq + 1
        ")->execute([$exportDate]);
        $seqStmt = $pdo->prepare("SELECT last_seq FROM document_sequences WHERE doc_type='WO' AND doc_date=?");
        $seqStmt->execute([$exportDate]);
        $seq = (int)$seqStmt->fetchColumn();
        $exportNo = 'WO-' . date('Ymd', strtotime($exportDate)) . '-' . str_pad($seq, 3, '0', STR_PAD_LEFT);

        $pdo->prepare("
            INSERT INTO warehouse_out (export_no, export_date, customer_id, note, status, created_by)
            VALUES (?, ?, ?, ?, 'draft', ?)
        ")->execute([$exportNo, $exportDate, $customerId, $note, $user['id']]);
        $id = (int)$pdo->lastInsertId();
    }

    $stmt = $pdo->prepare("
        INSERT INTO warehouse_out_items (warehouse_out_id, warehouse_item_id, product_code_id, quantity, note)
        VALUES (?, ?, ?, ?, ?)
    ");
    foreach ($validItems as $it) {
        $stmt->execute([$id, $it['warehouse_item_id'], $it['product_code_id'], $it['quantity'], $it['note']]);
    }

    $pdo->commit();
    echo json_encode(['ok' => true, 'msg' => 'Đã lưu phiếu xuất kho', 'id' => $id]);

} catch (Throwable $e) {
    $pdo->rollBack();
    error_log($e->getMessage());
    echo json_encode(['ok' => false, 'msg' => 'Lỗi hệ thống: ' . $e->getMessage()]);
}
