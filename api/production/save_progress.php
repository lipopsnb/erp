<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';

header('Content-Type: application/json');
requireLogin();
requireRole('director', 'accountant', 'warehouse', 'production', 'manager');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'msg' => 'Method not allowed']);
    exit;
}

if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
    echo json_encode(['ok' => false, 'msg' => 'CSRF token không hợp lệ']);
    exit;
}

$pdo = getDBConnection();
$user = currentUser();
$action = trim((string) ($_POST['action'] ?? ''));

try {
    if ($action === 'create') {
        $warehouseInId = (int) ($_POST['warehouse_in_id'] ?? 0);
        if ($warehouseInId <= 0) {
            throw new RuntimeException('Thiếu phiếu nhập NVL');
        }

        $stmt = $pdo->prepare("
            SELECT wi.id, wi.customer_id, wi.note, wi.status,
                   wii.id AS warehouse_in_item_id, wii.product_code_id, wii.quantity, wii.note AS item_note
            FROM warehouse_in wi
            JOIN warehouse_in_items wii ON wii.warehouse_in_id = wi.id
            WHERE wi.id = ?
            ORDER BY wii.id
        ");
        $stmt->execute([$warehouseInId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            throw new RuntimeException('Không tìm thấy phiếu nhập NVL');
        }

        $existingStmt = $pdo->prepare("
            SELECT product_code_id
            FROM production_progress
            WHERE warehouse_in_id = ?
        ");
        $existingStmt->execute([$warehouseInId]);
        $existingProducts = array_map('intval', $existingStmt->fetchAll(PDO::FETCH_COLUMN));

        $toCreate = [];
        foreach ($rows as $row) {
            if (in_array((int) $row['product_code_id'], $existingProducts, true)) {
                continue;
            }

            $row['progress_no'] = generateDocNo($pdo, 'PP');
            $toCreate[] = $row;
        }

        if (!$toCreate) {
            throw new RuntimeException('Phiếu này đã được tạo lệnh SX đầy đủ');
        }

        $pdo->beginTransaction();

        $insert = $pdo->prepare("
            INSERT INTO production_progress
                (progress_no, warehouse_in_id, product_code_id, customer_id, qty_total, qty_done, qty_defect, qty_remaining, status, note, created_by)
            VALUES
                (?, ?, ?, ?, ?, 0, 0, ?, 'in_progress', ?, ?)
        ");

        foreach ($toCreate as $row) {
            $insert->execute([
                $row['progress_no'],
                $warehouseInId,
                $row['product_code_id'],
                $row['customer_id'],
                $row['quantity'],
                $row['quantity'],
                $row['item_note'] ?: $row['note'],
                $user['id'],
            ]);
        }

        $pdo->prepare("UPDATE warehouse_in SET status = 'processing' WHERE id = ?")->execute([$warehouseInId]);
        $pdo->commit();

        echo json_encode(['ok' => true, 'msg' => 'Đã tạo ' . count($toCreate) . ' lệnh sản xuất']);
        exit;
    }

    if ($action === 'log') {
        $progressId = (int) ($_POST['progress_id'] ?? 0);
        $logDate = trim((string) ($_POST['log_date'] ?? date('Y-m-d')));
        $qtyDone = (float) ($_POST['qty_done'] ?? 0);
        $qtyDefect = (float) ($_POST['qty_defect'] ?? 0);
        $note = trim((string) ($_POST['note'] ?? ''));

        if ($progressId <= 0) {
            throw new RuntimeException('Thiếu lệnh sản xuất');
        }
        if ($qtyDone < 0 || $qtyDefect < 0) {
            throw new RuntimeException('Số lượng không được âm');
        }
        if (($qtyDone + $qtyDefect) <= 0 && $note === '') {
            throw new RuntimeException('Cần nhập số lượng hoặc ghi chú');
        }

        $progressStmt = $pdo->prepare("
            SELECT *
            FROM production_progress
            WHERE id = ?
        ");
        $progressStmt->execute([$progressId]);
        $progress = $progressStmt->fetch(PDO::FETCH_ASSOC);
        if (!$progress) {
            throw new RuntimeException('Không tìm thấy lệnh sản xuất');
        }

        if (($qtyDone + $qtyDefect) > (float) $progress['qty_remaining']) {
            throw new RuntimeException('Số lượng hoàn thành + lỗi vượt quá số lượng còn lại');
        }

        $fgsNumbers = [];
        if ($qtyDone > 0) {
            $fgsNumbers['normal'] = generateDocNo($pdo, 'FGS');
        }
        if ($qtyDefect > 0) {
            $fgsNumbers['defect'] = generateDocNo($pdo, 'FGS');
        }

        $pdo->beginTransaction();

        $logStmt = $pdo->prepare("
            INSERT INTO production_progress_logs
                (progress_id, log_date, qty_done, qty_defect, note, created_by)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $logStmt->execute([$progressId, $logDate, $qtyDone, $qtyDefect, $note ?: null, $user['id']]);
        $logId = (int) $pdo->lastInsertId();

        $newQtyDone = (float) $progress['qty_done'] + $qtyDone;
        $newQtyDefect = (float) $progress['qty_defect'] + $qtyDefect;
        $newQtyRemaining = max(0, (float) $progress['qty_total'] - $newQtyDone - $newQtyDefect);
        $newStatus = $newQtyRemaining <= 0 ? 'completed' : 'in_progress';

        $pdo->prepare("
            UPDATE production_progress
            SET qty_done = ?, qty_defect = ?, qty_remaining = ?, status = ?, note = ?
            WHERE id = ?
        ")->execute([
            $newQtyDone,
            $newQtyDefect,
            $newQtyRemaining,
            $newStatus,
            $note !== '' ? $note : $progress['note'],
            $progressId,
        ]);

        $fgsStmt = $pdo->prepare("
            INSERT INTO finished_goods_stock
                (fgs_no, progress_id, progress_log_id, product_code_id, customer_id, type, qty_in, qty_exported, qty_remaining, status, source_date, note)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, 0, ?, 'pending_export', ?, ?)
        ");

        if ($qtyDone > 0) {
            $fgsStmt->execute([
                $fgsNumbers['normal'],
                $progressId,
                $logId,
                $progress['product_code_id'],
                $progress['customer_id'],
                'normal',
                $qtyDone,
                $qtyDone,
                $logDate,
                $note ?: null,
            ]);
        }

        if ($qtyDefect > 0) {
            $defectNote = '⚠️ Hàng lỗi - chờ trả KH';
            if ($note !== '') {
                $defectNote .= ' | ' . $note;
            }

            $fgsStmt->execute([
                $fgsNumbers['defect'],
                $progressId,
                $logId,
                $progress['product_code_id'],
                $progress['customer_id'],
                'defect',
                $qtyDefect,
                $qtyDefect,
                $logDate,
                $defectNote,
            ]);
        }

        $incompleteStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM production_progress
            WHERE warehouse_in_id = ? AND status = 'in_progress'
        ");
        $incompleteStmt->execute([$progress['warehouse_in_id']]);
        $hasIncomplete = (int) $incompleteStmt->fetchColumn() > 0;
        $pdo->prepare("UPDATE warehouse_in SET status = ? WHERE id = ?")->execute([
            $hasIncomplete ? 'processing' : 'done',
            $progress['warehouse_in_id'],
        ]);

        $pdo->commit();
        echo json_encode(['ok' => true, 'msg' => 'Đã cập nhật tiến độ']);
        exit;
    }

    if ($action === 'delete_log') {
        $logId = (int) ($_POST['log_id'] ?? 0);
        if ($logId <= 0) {
            throw new RuntimeException('Thiếu log cần xoá');
        }

        $logStmt = $pdo->prepare("
            SELECT ppl.*, pp.qty_total, pp.qty_done AS progress_qty_done, pp.qty_defect AS progress_qty_defect,
                   pp.warehouse_in_id
            FROM production_progress_logs ppl
            JOIN production_progress pp ON pp.id = ppl.progress_id
            WHERE ppl.id = ?
        ");
        $logStmt->execute([$logId]);
        $log = $logStmt->fetch(PDO::FETCH_ASSOC);
        if (!$log) {
            throw new RuntimeException('Không tìm thấy log');
        }

        $latestStmt = $pdo->prepare("
            SELECT id
            FROM production_progress_logs
            WHERE progress_id = ?
            ORDER BY id DESC
            LIMIT 1
        ");
        $latestStmt->execute([$log['progress_id']]);
        if ((int) $latestStmt->fetchColumn() !== $logId) {
            throw new RuntimeException('Chỉ được xoá log mới nhất');
        }

        $fgsCheck = $pdo->prepare("
            SELECT COUNT(*)
            FROM finished_goods_stock
            WHERE progress_log_id = ?
        ");
        $fgsCheck->execute([$logId]);
        if ((int) $fgsCheck->fetchColumn() > 0) {
            throw new RuntimeException('Log này đã sinh kho thành phẩm nên không thể xoá');
        }

        $pdo->beginTransaction();

        $newQtyDone = max(0, (float) $log['progress_qty_done'] - (float) $log['qty_done']);
        $newQtyDefect = max(0, (float) $log['progress_qty_defect'] - (float) $log['qty_defect']);
        $newQtyRemaining = max(0, (float) $log['qty_total'] - $newQtyDone - $newQtyDefect);
        $newStatus = $newQtyRemaining <= 0 ? 'completed' : 'in_progress';

        $pdo->prepare("DELETE FROM production_progress_logs WHERE id = ?")->execute([$logId]);
        $pdo->prepare("
            UPDATE production_progress
            SET qty_done = ?, qty_defect = ?, qty_remaining = ?, status = ?
            WHERE id = ?
        ")->execute([$newQtyDone, $newQtyDefect, $newQtyRemaining, $newStatus, $log['progress_id']]);

        $incompleteStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM production_progress
            WHERE warehouse_in_id = ? AND status = 'in_progress'
        ");
        $incompleteStmt->execute([$log['warehouse_in_id']]);
        $hasIncomplete = (int) $incompleteStmt->fetchColumn() > 0;
        $pdo->prepare("UPDATE warehouse_in SET status = ? WHERE id = ?")->execute([
            $hasIncomplete ? 'processing' : 'done',
            $log['warehouse_in_id'],
        ]);

        $pdo->commit();
        echo json_encode(['ok' => true, 'msg' => 'Đã xoá log mới nhất']);
        exit;
    }

    throw new RuntimeException('Action không hợp lệ');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
}
?>
