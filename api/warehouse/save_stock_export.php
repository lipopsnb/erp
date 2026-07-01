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
    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            throw new RuntimeException('Thiếu phiếu xuất');
        }

        $stmt = $pdo->prepare("SELECT status FROM stock_exports WHERE id = ?");
        $stmt->execute([$id]);
        $status = $stmt->fetchColumn();
        if ($status !== 'draft') {
            throw new RuntimeException('Chỉ xoá được phiếu nháp');
        }

        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM stock_export_items WHERE export_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM stock_exports WHERE id = ?")->execute([$id]);
        $pdo->commit();

        echo json_encode(['ok' => true, 'msg' => 'Đã xoá phiếu xuất']);
        exit;
    }

    if ($action === 'confirm') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            throw new RuntimeException('Thiếu phiếu xuất');
        }

        $pdo->beginTransaction();

        $headerStmt = $pdo->prepare("
            SELECT *
            FROM stock_exports
            WHERE id = ?
            FOR UPDATE
        ");
        $headerStmt->execute([$id]);
        $header = $headerStmt->fetch(PDO::FETCH_ASSOC);
        if (!$header) {
            throw new RuntimeException('Không tìm thấy phiếu xuất');
        }
        if ($header['status'] !== 'draft') {
            throw new RuntimeException('Phiếu xuất đã được xác nhận');
        }

        $itemStmt = $pdo->prepare("
            SELECT *
            FROM stock_export_items
            WHERE export_id = ?
            ORDER BY id
        ");
        $itemStmt->execute([$id]);
        $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$items) {
            throw new RuntimeException('Phiếu xuất chưa có dòng hàng');
        }

        $grouped = [];
        foreach ($items as $item) {
            $fgsId = (int) $item['fgs_id'];
            $grouped[$fgsId] = ($grouped[$fgsId] ?? 0) + (float) $item['qty_export'];
        }

        $fgsStmt = $pdo->prepare("
            SELECT *
            FROM finished_goods_stock
            WHERE id = ?
            FOR UPDATE
        ");
        $updateStmt = $pdo->prepare("
            UPDATE finished_goods_stock
            SET qty_exported = ?, qty_remaining = ?, status = ?
            WHERE id = ?
        ");

        foreach ($grouped as $fgsId => $qtyExport) {
            $fgsStmt->execute([$fgsId]);
            $fgs = $fgsStmt->fetch(PDO::FETCH_ASSOC);
            if (!$fgs) {
                throw new RuntimeException('Không tìm thấy dòng kho thành phẩm #' . $fgsId);
            }
            if (!in_array($fgs['status'], ['pending_export', 'partial_export'], true)) {
                throw new RuntimeException('Dòng FGS ' . $fgs['fgs_no'] . ' không còn khả dụng để xuất');
            }
            if ($qtyExport > (float) $fgs['qty_remaining']) {
                throw new RuntimeException('Số lượng xuất vượt tồn còn lại của ' . $fgs['fgs_no']);
            }

            $newExported = (float) $fgs['qty_exported'] + $qtyExport;
            if ($newExported > (float) $fgs['qty_in']) {
                throw new RuntimeException('SL xuất cộng dồn vượt quá SL nhập của ' . $fgs['fgs_no']);
            }
            $newRemaining = max(0, (float) $fgs['qty_remaining'] - $qtyExport);
            $newStatus = $newRemaining <= 0 ? 'exported' : 'partial_export';

            $updateStmt->execute([$newExported, $newRemaining, $newStatus, $fgsId]);
        }

        $pdo->prepare("UPDATE stock_exports SET status = 'confirmed' WHERE id = ?")->execute([$id]);
        $pdo->commit();

        echo json_encode(['ok' => true, 'msg' => 'Đã xác nhận xuất kho']);
        exit;
    }

    if ($action !== 'save') {
        throw new RuntimeException('Action không hợp lệ');
    }

    $id = (int) ($_POST['id'] ?? 0);
    $exportDate = trim((string) ($_POST['export_date'] ?? date('Y-m-d')));
    $customerId = (int) ($_POST['customer_id'] ?? 0);
    $note = trim((string) ($_POST['note'] ?? ''));
    $confirmNow = (int) ($_POST['confirm_now'] ?? 0) === 1;
    $items = $_POST['items'] ?? [];

    if ($customerId <= 0 || $exportDate === '') {
        throw new RuntimeException('Thiếu ngày xuất hoặc khách hàng');
    }

    $validItems = [];
    foreach ($items as $item) {
        $fgsId = (int) ($item['fgs_id'] ?? 0);
        $productCodeId = (int) ($item['product_code_id'] ?? 0);
        $qtyExport = (float) ($item['qty_export'] ?? 0);
        if ($fgsId > 0 && $productCodeId > 0 && $qtyExport > 0) {
            $validItems[] = [
                'fgs_id' => $fgsId,
                'product_code_id' => $productCodeId,
                'qty_export' => $qtyExport,
                'note' => trim((string) ($item['note'] ?? '')) ?: null,
            ];
        }
    }

    if (!$validItems) {
        throw new RuntimeException('Phải chọn ít nhất 1 dòng hàng');
    }

    $grouped = [];
    foreach ($validItems as $item) {
        $grouped[$item['fgs_id']] = ($grouped[$item['fgs_id']] ?? 0) + $item['qty_export'];
    }

    $checkStmt = $pdo->prepare("
        SELECT id, fgs_no, customer_id, product_code_id, qty_remaining, status
        FROM finished_goods_stock
        WHERE id = ?
    ");

    foreach ($grouped as $fgsId => $qtyExport) {
        $checkStmt->execute([$fgsId]);
        $fgs = $checkStmt->fetch(PDO::FETCH_ASSOC);
        if (!$fgs) {
            throw new RuntimeException('Không tìm thấy dòng FGS #' . $fgsId);
        }
        if ((int) $fgs['customer_id'] !== $customerId) {
            throw new RuntimeException('Dòng ' . $fgs['fgs_no'] . ' không thuộc khách hàng đã chọn');
        }
        if (!in_array($fgs['status'], ['pending_export', 'partial_export'], true)) {
            throw new RuntimeException('Dòng ' . $fgs['fgs_no'] . ' không còn khả dụng để xuất');
        }
        if ($qtyExport > (float) $fgs['qty_remaining']) {
            throw new RuntimeException('Số lượng xuất vượt tồn còn lại của ' . $fgs['fgs_no']);
        }
    }

    $exportNo = null;
    if ($id <= 0) {
        $exportNo = generateDocNo($pdo, 'WO');
    }

    $pdo->beginTransaction();

    if ($id > 0) {
        $headerStmt = $pdo->prepare("SELECT status FROM stock_exports WHERE id = ? FOR UPDATE");
        $headerStmt->execute([$id]);
        $header = $headerStmt->fetch(PDO::FETCH_ASSOC);
        if (!$header || $header['status'] !== 'draft') {
            throw new RuntimeException('Chỉ sửa được phiếu nháp');
        }

        $pdo->prepare("
            UPDATE stock_exports
            SET export_date = ?, customer_id = ?, note = ?
            WHERE id = ?
        ")->execute([$exportDate, $customerId, $note ?: null, $id]);
        $pdo->prepare("DELETE FROM stock_export_items WHERE export_id = ?")->execute([$id]);
    } else {
        $pdo->prepare("
            INSERT INTO stock_exports
                (export_no, export_date, customer_id, status, note, created_by)
            VALUES (?, ?, ?, 'draft', ?, ?)
        ")->execute([$exportNo, $exportDate, $customerId, $note ?: null, $user['id']]);
        $id = (int) $pdo->lastInsertId();
    }

    $insertStmt = $pdo->prepare("
        INSERT INTO stock_export_items
            (export_id, fgs_id, product_code_id, qty_export, note)
        VALUES (?, ?, ?, ?, ?)
    ");
    foreach ($validItems as $item) {
        $insertStmt->execute([$id, $item['fgs_id'], $item['product_code_id'], $item['qty_export'], $item['note']]);
    }

    if ($confirmNow) {
        $fgsStmt = $pdo->prepare("
            SELECT *
            FROM finished_goods_stock
            WHERE id = ?
            FOR UPDATE
        ");
        $updateStmt = $pdo->prepare("
            UPDATE finished_goods_stock
            SET qty_exported = ?, qty_remaining = ?, status = ?
            WHERE id = ?
        ");

        foreach ($grouped as $fgsId => $qtyExport) {
            $fgsStmt->execute([$fgsId]);
            $fgs = $fgsStmt->fetch(PDO::FETCH_ASSOC);
            if (!$fgs) {
                throw new RuntimeException('Không tìm thấy dòng FGS #' . $fgsId);
            }
            if (!in_array($fgs['status'], ['pending_export', 'partial_export'], true)) {
                throw new RuntimeException('Dòng ' . $fgs['fgs_no'] . ' không còn khả dụng để xuất');
            }
            if ($qtyExport > (float) $fgs['qty_remaining']) {
                throw new RuntimeException('SL xuất vượt tồn còn lại của ' . $fgs['fgs_no']);
            }

            $newExported = (float) $fgs['qty_exported'] + $qtyExport;
            if ($newExported > (float) $fgs['qty_in']) {
                throw new RuntimeException('SL xuất cộng dồn vượt quá SL nhập của ' . $fgs['fgs_no']);
            }
            $newRemaining = max(0, (float) $fgs['qty_remaining'] - $qtyExport);
            $newStatus = $newRemaining <= 0 ? 'exported' : 'partial_export';
            $updateStmt->execute([$newExported, $newRemaining, $newStatus, $fgsId]);
        }

        $pdo->prepare("UPDATE stock_exports SET status = 'confirmed' WHERE id = ?")->execute([$id]);
    }

    $pdo->commit();
    echo json_encode([
        'ok' => true,
        'msg' => $confirmNow ? 'Đã xác nhận phiếu xuất kho' : 'Đã lưu phiếu xuất kho',
        'id' => $id,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
}
?>
