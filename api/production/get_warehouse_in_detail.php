<?php
/**
 * API: Lấy chi tiết phiếu nhập kho NVL
 * GET: id = warehouse_in.id
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
header('Content-Type: application/json');
requireLogin();

$pdo = getDBConnection();
$id  = (int)($_GET['id'] ?? 0);
if (!$id) { echo json_encode(['ok' => false, 'msg' => 'Missing id']); exit; }

$header = $pdo->prepare("
    SELECT wi.*, c.customer_name, c.customer_code, u.full_name AS created_by_name
    FROM warehouse_in wi
    LEFT JOIN customers c ON wi.customer_id = c.id
    LEFT JOIN users u     ON wi.created_by  = u.id
    WHERE wi.id = ?
");
$header->execute([$id]);
$header = $header->fetch(PDO::FETCH_ASSOC);
if (!$header) { echo json_encode(['ok' => false, 'msg' => 'Không tìm thấy phiếu']); exit; }

$items = $pdo->prepare("
    SELECT wii.*, pc.product_code, pc.description, pc.unit
    FROM warehouse_in_items wii
    JOIN product_codes pc ON wii.product_code_id = pc.id
    WHERE wii.warehouse_in_id = ?
    ORDER BY wii.id
");
$items->execute([$id]);
$items = $items->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['ok' => true, 'header' => $header, 'items' => $items]);
