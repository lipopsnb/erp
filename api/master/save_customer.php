<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';
header('Content-Type: application/json');
requireLogin();
requireRole('director','accountant','warehouse','manager');

$pdo  = getDBConnection();
$user = currentUser();
$action = trim($_POST['action'] ?? 'save');

if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
    echo json_encode(['ok' => false, 'msg' => 'CSRF invalid']); exit;
}

$id            = (int)($_POST['id'] ?? 0);
$customerCode  = strtoupper(trim($_POST['customer_code'] ?? '')) ?: null;
$customerName  = trim($_POST['customer_name'] ?? '');
$address       = trim($_POST['address'] ?? '') ?: null;
$contactPerson = trim($_POST['contact_person'] ?? '') ?: null;
$phone         = trim($_POST['phone'] ?? '') ?: null;
$email         = trim($_POST['email'] ?? '') ?: null;
$isActive      = isset($_POST['is_active']) ? 1 : 0;

if ($action !== 'delete' && !$customerName) {
    echo json_encode(['ok' => false, 'msg' => 'Thiếu tên khách hàng']); exit;
}

try {
    if ($action === 'delete') {
        if (!hasRole('director')) {
            echo json_encode(['ok' => false, 'msg' => 'Bạn không có quyền xoá khách hàng']); exit;
        }
        if (!$id) {
            echo json_encode(['ok' => false, 'msg' => 'Thiếu ID khách hàng']); exit;
        }

        $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $hasTxn = false;
        foreach ([
            ['table' => 'warehouse_in', 'col' => 'customer_id'],
            ['table' => 'warehouse_out', 'col' => 'customer_id'],
            ['table' => 'deliveries', 'col' => 'customer_id'],
            ['table' => 'invoices', 'col' => 'customer_id']
        ] as $ref) {
            $checkStmt->execute([$ref['table']]);
            if (!(int)$checkStmt->fetchColumn()) {
                continue;
            }
            $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM `{$ref['table']}` WHERE `{$ref['col']}` = ?");
            $cntStmt->execute([$id]);
            if ((int)$cntStmt->fetchColumn() > 0) {
                $hasTxn = true;
                break;
            }
        }

        if ($hasTxn) {
            $pdo->prepare("UPDATE customers SET is_active = 0, updated_at = NOW() WHERE id = ?")->execute([$id]);
            echo json_encode(['ok' => true, 'msg' => 'Khách hàng đã có giao dịch, đã chuyển sang trạng thái ngừng']);
        } else {
            $pdo->prepare("DELETE FROM customers WHERE id = ?")->execute([$id]);
            echo json_encode(['ok' => true, 'msg' => 'Đã xoá khách hàng']);
        }
        exit;
    }

    if ($id) {
        $stmt = $pdo->prepare("
            UPDATE customers
            SET customer_code=?, customer_name=?, address=?,
                contact_person=?, phone=?, email=?, is_active=?, updated_at=NOW()
            WHERE id=?
        ");
        $stmt->execute([$customerCode, $customerName, $address,
                        $contactPerson, $phone, $email, $isActive, $id]);
        echo json_encode(['ok' => true, 'msg' => 'Đã cập nhật']);
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO customers
                (customer_code, customer_name, address, contact_person, phone, email, is_active, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$customerCode, $customerName, $address,
                        $contactPerson, $phone, $email, $isActive, $user['id']]);
        echo json_encode(['ok' => true, 'msg' => 'Đã thêm mới', 'id' => $pdo->lastInsertId()]);
    }
} catch (PDOException $e) {
    if ($e->getCode() == 23000) {
        echo json_encode(['ok' => false, 'msg' => 'Mã KH đã tồn tại']);
    } else {
        error_log($e->getMessage());
        echo json_encode(['ok' => false, 'msg' => 'Lỗi hệ thống']);
    }
}