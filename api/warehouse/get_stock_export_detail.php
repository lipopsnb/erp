<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';

header('Content-Type: application/json');
requireLogin();
requireRole('director', 'accountant', 'warehouse', 'production', 'manager');

$pdo = getDBConnection();
$id = (int) ($_GET['id'] ?? 0);

try {
    if ($id <= 0) {
        throw new RuntimeException('Thiếu ID');
    }

    $headerStmt = $pdo->prepare("
        SELECT se.*, c.customer_name, c.customer_code, u.full_name AS created_by_name
        FROM stock_exports se
        JOIN customers c ON c.id = se.customer_id
        LEFT JOIN users u ON u.id = se.created_by
        WHERE se.id = ?
    ");
    $headerStmt->execute([$id]);
    $header = $headerStmt->fetch(PDO::FETCH_ASSOC);
    if (!$header) {
        throw new RuntimeException('Không tìm thấy phiếu xuất');
    }

    $itemsStmt = $pdo->prepare("
        SELECT sei.*, fgs.fgs_no, fgs.type, fgs.source_date, fgs.status AS fgs_status,
               pc.product_code, pc.description, pc.unit
        FROM stock_export_items sei
        JOIN finished_goods_stock fgs ON fgs.id = sei.fgs_id
        JOIN product_codes pc ON pc.id = sei.product_code_id
        WHERE sei.export_id = ?
        ORDER BY sei.id
    ");
    $itemsStmt->execute([$id]);

    echo json_encode([
        'ok' => true,
        'header' => $header,
        'items' => $itemsStmt->fetchAll(PDO::FETCH_ASSOC),
    ]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
}
?>
