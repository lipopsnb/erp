<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';
requireLogin();
requireRole('director','accountant','warehouse','production','manager');

$pdo = getDBConnection();
$id  = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: delivery.php'); exit; }

$delivery = $pdo->prepare("
    SELECT d.*, c.customer_name, c.customer_code, c.address, c.phone,
           u.full_name AS created_by_name
    FROM deliveries d
    LEFT JOIN customers c ON d.customer_id = c.id
    LEFT JOIN users u     ON d.created_by  = u.id
    WHERE d.id = ?
");
$delivery->execute([$id]);
$delivery = $delivery->fetch(PDO::FETCH_ASSOC);
if (!$delivery) { header('Location: delivery.php'); exit; }

$items = $pdo->prepare("
    SELECT di.*, pc.product_code, pc.description AS product_desc, pc.unit
    FROM delivery_items di
    JOIN product_codes pc ON di.product_code_id = pc.id
    WHERE di.delivery_id = ?
    ORDER BY di.id
");
$items->execute([$id]);
$items = $items->fetchAll(PDO::FETCH_ASSOC);

$totalQty    = array_sum(array_column($items, 'quantity'));
$totalAmount = array_sum(array_column($items, 'total_price'));

include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/sidebar.php';
?>
<div class="main-content">
<?php include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/warehouse_nav.php'; ?>
<div class="container-fluid py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <a href="delivery.php" class="btn btn-sm btn-outline-secondary me-2">
                <i class="fas fa-arrow-left"></i>
            </a>
            <span class="fs-5 fw-bold">
                <i class="fas fa-file-alt me-2 text-primary"></i>
                <?= htmlspecialchars($delivery['delivery_no']) ?>
            </span>
        </div>
        <div class="d-flex gap-2">
            <a href="/erp/api/production/print_delivery.php?id=<?= $id ?>"
               target="_blank" class="btn btn-outline-secondary">
                <i class="fas fa-print me-1"></i>In biên bản
            </a>
            <?php if ($delivery['status'] === 'confirmed' && hasRole('director','accountant','manager')): ?>
            <a href="/erp/modules/invoice/create.php?delivery_id=<?= $id ?>"
               class="btn btn-success">
                <i class="fas fa-file-invoice me-1"></i>Xuất hoá đơn
            </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-3">
        <!-- Thông tin biên bản -->
        <div class="col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-bold">
                    <i class="fas fa-info-circle me-2 text-primary"></i>Thông tin biên bản
                </div>
                <div class="card-body">
                    <table class="table table-sm mb-0">
                        <tr><td class="text-muted w-40">Số biên bản</td>
                            <td class="fw-bold text-primary"><?= htmlspecialchars($delivery['delivery_no']) ?></td></tr>
                        <tr><td class="text-muted">Ngày giao</td>
                            <td><?= date('d/m/Y', strtotime($delivery['delivery_date'])) ?></td></tr>
                        <tr><td class="text-muted">Trạng thái</td>
                            <td><?php
                                $st = ['draft'=>['secondary','Nháp'],'confirmed'=>['primary','Xác nhận'],'invoiced'=>['success','Xuất HĐ']];
                                $s  = $st[$delivery['status']] ?? ['secondary','?'];
                                echo "<span class='badge bg-{$s[0]}'>{$s[1]}</span>";
                            ?></td></tr>
                        <tr><td class="text-muted">Người tạo</td>
                            <td><?= htmlspecialchars($delivery['created_by_name'] ?? '—') ?></td></tr>
                        <tr><td class="text-muted">Ghi chú</td>
                            <td><?= htmlspecialchars($delivery['note'] ?? '—') ?></td></tr>
                    </table>
                </div>
            </div>
        </div>

        <!-- Thông tin khách hàng -->
        <div class="col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-bold">
                    <i class="fas fa-user me-2 text-success"></i>Khách hàng
                </div>
                <div class="card-body">
                    <table class="table table-sm mb-0">
                        <tr><td class="text-muted w-40">Tên KH</td>
                            <td class="fw-bold"><?= htmlspecialchars($delivery['customer_name'] ?? '—') ?></td></tr>
                        <tr><td class="text-muted">Mã KH</td>
                            <td><?= htmlspecialchars($delivery['customer_code'] ?? '—') ?></td></tr>
                        <tr><td class="text-muted">Địa chỉ</td>
                            <td><?= htmlspecialchars($delivery['address'] ?? '—') ?></td></tr>
                        <tr><td class="text-muted">SĐT</td>
                            <td><?= htmlspecialchars($delivery['phone'] ?? '—') ?></td></tr>
                    </table>
                </div>
            </div>
        </div>

        <!-- Chi tiết SP -->
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-bold">
                    <i class="fas fa-list me-2 text-warning"></i>Chi tiết sản phẩm
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-dark">
                                <tr>
                                    <th>#</th>
                                    <th>Mã SP</th>
                                    <th>Mô tả</th>
                                    <th width="80">ĐVT</th>
                                    <th width="100" class="text-end">Số lượng</th>
                                    <th width="130" class="text-end">Đơn giá</th>
                                    <th width="150" class="text-end">Thành tiền</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php $i = 1; foreach ($items as $it): ?>
                            <tr>
                                <td class="text-muted"><?= $i++ ?></td>
                                <td><span class="badge bg-primary"><?= htmlspecialchars($it['product_code']) ?></span></td>
                                <td><?= htmlspecialchars($it['product_desc']) ?></td>
                                <td><?= htmlspecialchars($it['unit']) ?></td>
                                <td class="text-end fw-bold"><?= number_format($it['quantity']) ?></td>
                                <td class="text-end"><?= number_format($it['unit_price']) ?> đ</td>
                                <td class="text-end fw-bold text-success"><?= number_format($it['total_price']) ?> đ</td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr>
                                    <td colspan="4" class="text-end fw-bold">Tổng cộng:</td>
                                    <td class="text-end fw-bold"><?= number_format($totalQty) ?></td>
                                    <td></td>
                                    <td class="text-end fw-bold text-success fs-6">
                                        <?= number_format($totalAmount) ?> đ
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</div>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/footer.php'; ?>