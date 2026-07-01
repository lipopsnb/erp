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
        SELECT pp.*, wi.receipt_no, wi.receipt_date,
               c.customer_name, c.customer_code,
               pc.product_code, pc.description, pc.unit,
               u.full_name AS created_by_name
        FROM production_progress pp
        JOIN warehouse_in wi ON wi.id = pp.warehouse_in_id
        JOIN customers c ON c.id = pp.customer_id
        JOIN product_codes pc ON pc.id = pp.product_code_id
        LEFT JOIN users u ON u.id = pp.created_by
        WHERE pp.id = ?
    ");
    $headerStmt->execute([$id]);
    $header = $headerStmt->fetch(PDO::FETCH_ASSOC);
    if (!$header) {
        throw new RuntimeException('Không tìm thấy lệnh sản xuất');
    }

    $logsStmt = $pdo->prepare("
        SELECT ppl.*, u.full_name AS created_by_name
        FROM production_progress_logs ppl
        LEFT JOIN users u ON u.id = ppl.created_by
        WHERE ppl.progress_id = ?
        ORDER BY ppl.id DESC
    ");
    $logsStmt->execute([$id]);

    $fgsStmt = $pdo->prepare("
        SELECT fgs.*, pc.product_code, pc.description, pc.unit
        FROM finished_goods_stock fgs
        JOIN product_codes pc ON pc.id = fgs.product_code_id
        WHERE fgs.progress_id = ?
        ORDER BY fgs.id DESC
    ");
    $fgsStmt->execute([$id]);

    echo json_encode([
        'ok' => true,
        'header' => $header,
        'logs' => $logsStmt->fetchAll(PDO::FETCH_ASSOC),
        'fgs' => $fgsStmt->fetchAll(PDO::FETCH_ASSOC),
    ]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
}
?>
