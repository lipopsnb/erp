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
        $deliveryDate = trim((string) ($_POST['delivery_date'] ?? date('Y-m-d')));
        $customerId = (int) ($_POST['customer_id'] ?? 0);
        $note = trim((string) ($_POST['note'] ?? ''));
        $confirmNow = (int) ($_POST['confirm_now'] ?? 0) === 1;
        $exportItemIds = array_values(array_filter(array_map('intval', (array) ($_POST['export_item_ids'] ?? []))));

        if ($customerId <= 0 || !$exportItemIds) {
            throw new RuntimeException('Thiếu khách hàng hoặc dòng xuất kho');
        }

        $deliveryNo = generateDocNo($pdo, 'DL');

        $placeholders = implode(',', array_fill(0, count($exportItemIds), '?'));
        $params = $exportItemIds;
        array_unshift($params, $customerId);
        $sql = "
            SELECT sei.id, sei.export_id, sei.fgs_id, sei.product_code_id, sei.qty_export, sei.delivery_id,
                   se.export_no, se.status AS export_status, se.customer_id,
                   fgs.qty_remaining, fgs.status AS fgs_status
            FROM stock_export_items sei
            JOIN stock_exports se ON se.id = sei.export_id
            JOIN finished_goods_stock fgs ON fgs.id = sei.fgs_id
            WHERE se.customer_id = ?
              AND sei.id IN ($placeholders)
            FOR UPDATE
        ";

        $pdo->beginTransaction();

        $itemStmt = $pdo->prepare($sql);
        $itemStmt->execute($params);
        $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($items) !== count($exportItemIds)) {
            throw new RuntimeException('Một số dòng xuất kho không hợp lệ');
        }

        foreach ($items as $item) {
            if ($item['export_status'] !== 'confirmed') {
                throw new RuntimeException('Chỉ được tạo giao hàng từ phiếu xuất đã xác nhận');
            }
            if (!empty($item['delivery_id'])) {
                throw new RuntimeException('Dòng xuất kho ' . $item['export_no'] . ' đã được gắn phiếu giao');
            }
        }

        $uniqueExportIds = array_values(array_unique(array_map('intval', array_column($items, 'export_id'))));
        $exportId = count($uniqueExportIds) === 1 ? $uniqueExportIds[0] : null;

        $pdo->prepare("
            INSERT INTO deliveries
                (delivery_no, delivery_date, customer_id, warehouse_out_id, export_id, total_amount, note, status, created_by)
            VALUES (?, ?, ?, NULL, ?, 0, ?, 'draft', ?)
        ")->execute([$deliveryNo, $deliveryDate, $customerId, $exportId, $note ?: null, $user['id']]);
        $deliveryId = (int) $pdo->lastInsertId();

        $insertItemStmt = $pdo->prepare("
            INSERT INTO delivery_items
                (delivery_id, product_code_id, export_item_id, quantity, unit_price, total_price, note)
            VALUES (?, ?, ?, ?, 0, 0, ?)
        ");
        $reserveStmt = $pdo->prepare("UPDATE stock_export_items SET delivery_id = ? WHERE id = ?");

        foreach ($items as $item) {
            $insertItemStmt->execute([
                $deliveryId,
                $item['product_code_id'],
                $item['id'],
                $item['qty_export'],
                $note ?: null,
            ]);
            $reserveStmt->execute([$deliveryId, $item['id']]);
        }

        if ($confirmNow) {
            $fgsIds = array_values(array_unique(array_map('intval', array_column($items, 'fgs_id'))));
            $fgsLockStmt = $pdo->prepare("
                SELECT id, qty_remaining
                FROM finished_goods_stock
                WHERE id = ?
                FOR UPDATE
            ");
            $fgsUpdateStmt = $pdo->prepare("UPDATE finished_goods_stock SET status = ? WHERE id = ?");
            $pendingDeliveryStmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM stock_export_items sei
                LEFT JOIN deliveries d ON d.id = sei.delivery_id
                WHERE sei.fgs_id = ?
                  AND (sei.delivery_id IS NULL OR (d.status <> 'confirmed' AND d.id <> ?))
            ");

            foreach ($fgsIds as $fgsId) {
                $fgsLockStmt->execute([$fgsId]);
                $fgs = $fgsLockStmt->fetch(PDO::FETCH_ASSOC);
                if (!$fgs) {
                    throw new RuntimeException('Không tìm thấy dòng kho thành phẩm #' . $fgsId);
                }

                $pendingDeliveryStmt->execute([$fgsId, $deliveryId]);
                $pendingCount = (int) $pendingDeliveryStmt->fetchColumn();
                $newStatus = (float) $fgs['qty_remaining'] <= 0
                    ? ($pendingCount === 0 ? 'delivered' : 'exported')
                    : 'partial_export';
                $fgsUpdateStmt->execute([$newStatus, $fgsId]);
            }

            $pdo->prepare("UPDATE deliveries SET status = 'confirmed' WHERE id = ?")->execute([$deliveryId]);
        }

        $pdo->commit();
        echo json_encode([
            'ok' => true,
            'msg' => $confirmNow ? 'Đã xác nhận giao hàng' : 'Đã tạo phiếu giao hàng',
            'id' => $deliveryId,
        ]);
        exit;
    }

    if ($action === 'confirm') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            throw new RuntimeException('Thiếu phiếu giao');
        }

        $pdo->beginTransaction();

        $headerStmt = $pdo->prepare("
            SELECT *
            FROM deliveries
            WHERE id = ?
            FOR UPDATE
        ");
        $headerStmt->execute([$id]);
        $header = $headerStmt->fetch(PDO::FETCH_ASSOC);
        if (!$header) {
            throw new RuntimeException('Không tìm thấy phiếu giao');
        }
        if ($header['status'] !== 'draft') {
            throw new RuntimeException('Phiếu giao đã được xác nhận');
        }

        $itemStmt = $pdo->prepare("
            SELECT di.id, di.export_item_id, sei.fgs_id
            FROM delivery_items di
            JOIN stock_export_items sei ON sei.id = di.export_item_id
            WHERE di.delivery_id = ?
            ORDER BY di.id
        ");
        $itemStmt->execute([$id]);
        $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$items) {
            throw new RuntimeException('Phiếu giao chưa có sản phẩm');
        }

        $fgsIds = array_values(array_unique(array_map('intval', array_column($items, 'fgs_id'))));
        $fgsLockStmt = $pdo->prepare("
            SELECT id, qty_remaining
            FROM finished_goods_stock
            WHERE id = ?
            FOR UPDATE
        ");
        $fgsUpdateStmt = $pdo->prepare("UPDATE finished_goods_stock SET status = ? WHERE id = ?");
        $pendingDeliveryStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM stock_export_items sei
            LEFT JOIN deliveries d ON d.id = sei.delivery_id
            WHERE sei.fgs_id = ?
              AND (sei.delivery_id IS NULL OR (d.status <> 'confirmed' AND d.id <> ?))
        ");

        foreach ($fgsIds as $fgsId) {
            $fgsLockStmt->execute([$fgsId]);
            $fgs = $fgsLockStmt->fetch(PDO::FETCH_ASSOC);
            if (!$fgs) {
                throw new RuntimeException('Không tìm thấy dòng kho thành phẩm #' . $fgsId);
            }

            $pendingDeliveryStmt->execute([$fgsId, $id]);
            $pendingCount = (int) $pendingDeliveryStmt->fetchColumn();
            $newStatus = (float) $fgs['qty_remaining'] <= 0
                ? ($pendingCount === 0 ? 'delivered' : 'exported')
                : 'partial_export';
            $fgsUpdateStmt->execute([$newStatus, $fgsId]);
        }

        $pdo->prepare("UPDATE deliveries SET status = 'confirmed' WHERE id = ?")->execute([$id]);
        $pdo->commit();

        echo json_encode(['ok' => true, 'msg' => 'Đã xác nhận giao hàng']);
        exit;
    }

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            throw new RuntimeException('Thiếu phiếu giao');
        }

        $pdo->beginTransaction();

        $headerStmt = $pdo->prepare("
            SELECT status
            FROM deliveries
            WHERE id = ?
            FOR UPDATE
        ");
        $headerStmt->execute([$id]);
        $header = $headerStmt->fetch(PDO::FETCH_ASSOC);
        if (!$header || $header['status'] !== 'draft') {
            throw new RuntimeException('Chỉ xoá được phiếu giao nháp');
        }

        $itemStmt = $pdo->prepare("
            SELECT export_item_id
            FROM delivery_items
            WHERE delivery_id = ?
        ");
        $itemStmt->execute([$id]);
        $exportItemIds = array_map('intval', $itemStmt->fetchAll(PDO::FETCH_COLUMN));

        if ($exportItemIds) {
            $placeholders = implode(',', array_fill(0, count($exportItemIds), '?'));
            $resetStmt = $pdo->prepare("UPDATE stock_export_items SET delivery_id = NULL WHERE id IN ($placeholders)");
            $resetStmt->execute($exportItemIds);
        }

        $pdo->prepare("DELETE FROM delivery_items WHERE delivery_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM deliveries WHERE id = ?")->execute([$id]);
        $pdo->commit();

        echo json_encode(['ok' => true, 'msg' => 'Đã xoá phiếu giao nháp']);
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
