<?php
/**
 * API: Tạo / Cập nhật phiếu nhập kho NVL (warehouse_in)
 * POST fields:
 *   action = 'save' | 'start' | 'delete'
 *   id            — ID phiếu (khi sửa/xoá)
 *   receipt_date, customer_id, note
 *   items[n][id]              — ID dòng (khi sửa)
 *   items[n][product_code_id]
 *   items[n][quantity]
 *   items[n][note]
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

// ── Xoá phiếu (chỉ khi status = 'open') ──────────────────────────────────
if ($action === 'delete') {
    if (!$id) { echo json_encode(['ok' => false, 'msg' => 'Thiếu ID']); exit; }
    $row = $pdo->prepare("SELECT status FROM warehouse_in WHERE id = ?");
    $row->execute([$id]);
    $row = $row->fetch();
    if (!$row) { echo json_encode(['ok' => false, 'msg' => 'Không tìm thấy phiếu']); exit; }
    if ($row['status'] !== 'open') {
        echo json_encode(['ok' => false, 'msg' => 'Chỉ xoá được phiếu chưa xử lý']); exit;
    }
    try {
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM warehouse_in_items WHERE warehouse_in_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM warehouse_in WHERE id = ?")->execute([$id]);
        $pdo->commit();
        echo json_encode(['ok' => true, 'msg' => 'Đã xoá phiếu']);
    } catch (Throwable $e) {
        $pdo->rollBack();
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

// ── Bắt đầu gia công (open → processing) ─────────────────────────────────
if ($action === 'start') {
    if (!$id) { echo json_encode(['ok' => false, 'msg' => 'Thiếu ID']); exit; }
    $pdo->prepare("UPDATE warehouse_in SET status='processing' WHERE id = ? AND status = 'open'")
        ->execute([$id]);
    echo json_encode(['ok' => true, 'msg' => 'Đã bắt đầu gia công']);
    exit;
}

// ── Tạo / Sửa phiếu ──────────────────────────────────────────────────────
$receiptDate = trim($_POST['receipt_date'] ?? date('Y-m-d'));
$customerId  = (int)($_POST['customer_id'] ?? 0);
$note        = trim($_POST['note']         ?? '') ?: null;
$items       = $_POST['items'] ?? [];

if (!$receiptDate || !$customerId) {
    echo json_encode(['ok' => false, 'msg' => 'Thiếu ngày hoặc khách hàng']); exit;
}

$validItems = [];
foreach ($items as $it) {
    $pcId = (int)($it['product_code_id'] ?? 0);
    $qty  = (float)($it['quantity']      ?? 0);
    if ($pcId && $qty > 0) {
        $validItems[] = [
            'id'              => (int)($it['id'] ?? 0),
            'product_code_id' => $pcId,
            'quantity'        => $qty,
            'note'            => trim($it['note'] ?? '') ?: null,
        ];
    }
}
if (empty($validItems)) {
    echo json_encode(['ok' => false, 'msg' => 'Phải có ít nhất 1 dòng sản phẩm hợp lệ']); exit;
}

try {
    $pdo->beginTransaction();

    if ($id) {
        // Kiểm tra trạng thái
        $chk = $pdo->prepare("SELECT status FROM warehouse_in WHERE id = ?");
        $chk->execute([$id]);
        $existing = $chk->fetch();
        if (!$existing || $existing['status'] !== 'open') {
            $pdo->rollBack();
            echo json_encode(['ok' => false, 'msg' => 'Chỉ sửa được phiếu chưa xử lý']); exit;
        }
        $pdo->prepare("
            UPDATE warehouse_in SET receipt_date = ?, customer_id = ?, note = ?
            WHERE id = ?
        ")->execute([$receiptDate, $customerId, $note, $id]);
        // Xoá items cũ → thêm lại
        $pdo->prepare("DELETE FROM warehouse_in_items WHERE warehouse_in_id = ?")->execute([$id]);
    } else {
        // Sinh số phiếu WI-YYYYMMDD-XXX
        $pdo->prepare("
            INSERT INTO document_sequences (doc_type, doc_date, last_seq) VALUES ('WI',?,1)
            ON DUPLICATE KEY UPDATE last_seq = last_seq + 1
        ")->execute([$receiptDate]);
        $seqStmt = $pdo->prepare("SELECT last_seq FROM document_sequences WHERE doc_type='WI' AND doc_date=?");
        $seqStmt->execute([$receiptDate]);
        $seq = (int)$seqStmt->fetchColumn();
        $receiptNo = 'WI-' . date('Ymd', strtotime($receiptDate)) . '-' . str_pad($seq, 3, '0', STR_PAD_LEFT);

        $pdo->prepare("
            INSERT INTO warehouse_in (receipt_no, receipt_date, customer_id, note, status, created_by)
            VALUES (?, ?, ?, ?, 'open', ?)
        ")->execute([$receiptNo, $receiptDate, $customerId, $note, $user['id']]);
        $id = (int)$pdo->lastInsertId();
    }

    // Insert items
    $stmtItem = $pdo->prepare("
        INSERT INTO warehouse_in_items (warehouse_in_id, product_code_id, quantity, note)
        VALUES (?, ?, ?, ?)
    ");
    foreach ($validItems as $it) {
        $stmtItem->execute([$id, $it['product_code_id'], $it['quantity'], $it['note']]);
    }

    $pdo->commit();
    echo json_encode(['ok' => true, 'msg' => 'Đã lưu phiếu nhập kho', 'id' => $id]);

} catch (Throwable $e) {
    $pdo->rollBack();
    error_log($e->getMessage());
    echo json_encode(['ok' => false, 'msg' => 'Lỗi hệ thống: ' . $e->getMessage()]);
}
