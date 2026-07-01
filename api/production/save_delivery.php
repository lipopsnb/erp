<?php
/**
 * API: Tạo biên bản giao hàng
 * POST: delivery_date, customer_id, note, status,
 *       items[n][product_code_id], items[n][quantity],
 *       items[n][description], items[n][unit]
 * → Tạo delivery_notes + delivery_note_items (unit_price=0, total_price=0, production_output_id=NULL)
 * → Kiểm tra tồn kho từ warehouse_items
 * → Ghi warehouse_stock_log
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
    echo json_encode(['ok' => false, 'msg' => 'Invalid CSRF token']); exit;
}

$pdo  = getDBConnection();
$user = currentUser();

$deliveryDate = trim($_POST['delivery_date'] ?? '');
$customerId   = (int)($_POST['customer_id']  ?? 0);
$note         = trim($_POST['note']          ?? '');
$status       = in_array($_POST['status'] ?? '', ['draft','confirmed']) ? $_POST['status'] : 'draft';
$items        = $_POST['items'] ?? [];

if (!$deliveryDate || !$customerId || empty($items)) {
    echo json_encode(['ok' => false, 'msg' => 'Thiếu thông tin bắt buộc']); exit;
}

// Lọc dòng hợp lệ
$validItems = [];
foreach ($items as $it) {
    $pcId = (int)($it['product_code_id'] ?? 0);
    $qty  = (float)($it['quantity']      ?? 0);
    if ($pcId && $qty > 0) {
        $validItems[] = [
            'pc_id'       => $pcId,
            'qty'         => $qty,
            'description' => trim($it['description'] ?? ''),
            'unit'        => trim($it['unit']        ?? ''),
        ];
    }
}
if (empty($validItems)) {
    echo json_encode(['ok' => false, 'msg' => 'Không có dòng sản phẩm hợp lệ']); exit;
}

// Kiểm tra bảng warehouse_items có tồn tại không
$tblCheck = $pdo->prepare("
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'warehouse_items'
");
$tblCheck->execute();
$hasWarehouseItems = (bool)$tblCheck->fetchColumn();

try {
    $pdo->beginTransaction();

    // Sinh số biên bản: DN-YYYYMMDD-XXXX
    $prefix = 'DN-' . date('Ymd', strtotime($deliveryDate)) . '-';
    $lastNo = $pdo->prepare("
        SELECT delivery_no FROM delivery_notes
        WHERE delivery_no LIKE ? ORDER BY id DESC LIMIT 1
    ");
    $lastNo->execute([$prefix . '%']);
    $lastRow    = $lastNo->fetchColumn();
    $seq        = $lastRow ? ((int)substr($lastRow, -4) + 1) : 1;
    $deliveryNo = $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);

    // 1. Tạo delivery_notes (total_amount = 0, không cần tính tiền)
    $pdo->prepare("
        INSERT INTO delivery_notes
            (delivery_no, delivery_date, customer_id, total_amount, status, note, created_by)
        VALUES (?, ?, ?, 0, ?, ?, ?)
    ")->execute([
        $deliveryNo, $deliveryDate, $customerId,
        $status, $note ?: null, $user['id']
    ]);
    $noteId = $pdo->lastInsertId();

    foreach ($validItems as $it) {
        // Kiểm tra số lượng available trong kho thành phẩm (giống logic get_customer_stock.php)
        if ($hasWarehouseItems) {
            $qtyInStmt = $pdo->prepare("
                SELECT COALESCE(SUM(quantity), 0) FROM warehouse_items
                WHERE customer_id = ? AND product_code_id = ?
            ");
            $qtyInStmt->execute([$customerId, $it['pc_id']]);
            $qtyIn = (float)$qtyInStmt->fetchColumn();

            $qtyOutStmt = $pdo->prepare("
                SELECT COALESCE(SUM(di.quantity), 0)
                FROM delivery_items di
                JOIN deliveries d ON d.id = di.delivery_id
                WHERE d.customer_id = ?
                  AND di.product_code_id = ?
                  AND d.status IN ('draft','confirmed','invoiced')
            ");
            $qtyOutStmt->execute([$customerId, $it['pc_id']]);
            $qtyOut = (float)$qtyOutStmt->fetchColumn();
        } else {
            // Fallback: dùng warehouse_out_items (phiếu xuất kho confirmed)
            $qtyInStmt = $pdo->prepare("
                SELECT COALESCE(SUM(woi.quantity), 0)
                FROM warehouse_out_items woi
                JOIN warehouse_out wo ON wo.id = woi.warehouse_out_id
                WHERE wo.customer_id = ? AND woi.product_code_id = ?
                  AND wo.status = 'confirmed'
            ");
            $qtyInStmt->execute([$customerId, $it['pc_id']]);
            $qtyIn = (float)$qtyInStmt->fetchColumn();

            $qtyOutStmt = $pdo->prepare("
                SELECT COALESCE(SUM(di.quantity), 0)
                FROM delivery_items di
                JOIN deliveries d ON d.id = di.delivery_id
                WHERE d.customer_id = ?
                  AND di.product_code_id = ?
                  AND d.status IN ('draft','confirmed','invoiced')
            ");
            $qtyOutStmt->execute([$customerId, $it['pc_id']]);
            $qtyOut = (float)$qtyOutStmt->fetchColumn();
        }

        $avail = $qtyIn - $qtyOut;
        if ($it['qty'] > $avail) {
            throw new Exception(
                "Sản phẩm #" . $it['pc_id'] .
                ": SL giao ({$it['qty']}) vượt quá tồn kho còn lại ($avail)"
            );
        }

        // 2. Tạo delivery_note_items (unit_price=0, total_price=0, production_output_id=NULL)
        $pdo->prepare("
            INSERT INTO delivery_note_items
                (delivery_note_id, production_output_id, product_code_id,
                 description, unit, quantity, unit_price, total_price)
            VALUES (?, NULL, ?, ?, ?, ?, 0, 0)
        ")->execute([
            $noteId, $it['pc_id'],
            $it['description'], $it['unit'], $it['qty']
        ]);

        // 3. Ghi log delivery (âm)
        $pdo->prepare("
            INSERT INTO warehouse_stock_log
                (product_code_id, log_date, txn_type, stock_type,
                 qty_change, ref_table, ref_id, note, created_by)
            VALUES (?, ?, 'delivery', 'completed', ?, 'delivery_notes', ?, ?, ?)
        ")->execute([
            $it['pc_id'], $deliveryDate, -$it['qty'],
            $noteId, "Giao hàng: $deliveryNo", $user['id']
        ]);
    }

    $pdo->commit();
    echo json_encode(['ok' => true, 'delivery_no' => $deliveryNo]);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
}