<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';
requireLogin();
requireRole('director', 'accountant', 'warehouse', 'production', 'manager');

$pdo = getDBConnection();
$filterCustomer = (int) ($_GET['customer_id'] ?? 0);
$filterType = trim((string) ($_GET['type'] ?? ''));
$filterStatus = trim((string) ($_GET['status'] ?? ''));
$filterCode = trim((string) ($_GET['code'] ?? ''));

$where = ['1=1'];
$params = [];
if ($filterCustomer > 0) { $where[] = 'fgs.customer_id = ?'; $params[] = $filterCustomer; }
if (in_array($filterType, ['normal', 'defect'], true)) { $where[] = 'fgs.type = ?'; $params[] = $filterType; }
if (in_array($filterStatus, ['pending_export', 'partial_export', 'exported', 'delivered'], true)) { $where[] = 'fgs.status = ?'; $params[] = $filterStatus; }
if ($filterCode !== '') { $where[] = 'pc.product_code LIKE ?'; $params[] = '%' . $filterCode . '%'; }

$rows = fetchAllSafe($pdo, "
    SELECT fgs.*, c.customer_name, c.customer_code,
           pc.product_code, pc.description, pc.unit,
           pp.progress_no
    FROM finished_goods_stock fgs
    JOIN customers c ON c.id = fgs.customer_id
    JOIN product_codes pc ON pc.id = fgs.product_code_id
    JOIN production_progress pp ON pp.id = fgs.progress_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY fgs.source_date DESC, fgs.id DESC
", $params);

$pendingTotal = (float) fetchScalarSafe($pdo, "
    SELECT COALESCE(SUM(qty_remaining), 0)
    FROM finished_goods_stock
    WHERE type = 'normal' AND status IN ('pending_export', 'partial_export')
", [], 0);

$defectTotal = (float) fetchScalarSafe($pdo, "
    SELECT COALESCE(SUM(qty_remaining), 0)
    FROM finished_goods_stock
    WHERE type = 'defect' AND status IN ('pending_export', 'partial_export')
", [], 0);

$customers = fetchAllSafe($pdo, "SELECT id, customer_code, customer_name FROM customers WHERE is_active = 1 ORDER BY customer_name");

include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/sidebar.php';
$statusLabels = [
    'pending_export' => ['bg-warning text-dark', 'Chờ xuất'],
    'partial_export' => ['bg-info', 'Xuất 1 phần'],
    'exported' => ['bg-primary', 'Đã xuất'],
    'delivered' => ['bg-success', 'Đã giao'],
];
?>
<div class="main-content">
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="fas fa-boxes me-2 text-primary"></i>Kho thành phẩm</h4>
            <p class="text-muted mb-0">Theo dõi toàn bộ hàng hoàn thành và hàng lỗi phát sinh từ sản xuất</p>
        </div>
        <div class="text-end small">
            <div><span class="badge bg-warning text-dark">Pending Export</span> <?= number_format($pendingTotal, 0) ?></div>
            <div><span class="badge bg-danger">Defect</span> <?= number_format($defectTotal, 0) ?></div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-3"><div class="card-body">
        <form class="row g-2 align-items-end" method="GET">
            <div class="col-md-3"><label class="form-label small text-muted">Khách hàng</label><select name="customer_id" class="form-select form-select-sm"><option value="0">-- Tất cả --</option><?php foreach ($customers as $customer): ?><option value="<?= (int) $customer['id'] ?>" <?= $filterCustomer === (int) $customer['id'] ? 'selected' : '' ?>>[<?= e($customer['customer_code']) ?>] <?= e($customer['customer_name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><label class="form-label small text-muted">Loại</label><select name="type" class="form-select form-select-sm"><option value="">-- Tất cả --</option><option value="normal" <?= $filterType === 'normal' ? 'selected' : '' ?>>HT</option><option value="defect" <?= $filterType === 'defect' ? 'selected' : '' ?>>Lỗi</option></select></div>
            <div class="col-md-2"><label class="form-label small text-muted">Trạng thái</label><select name="status" class="form-select form-select-sm"><option value="">-- Tất cả --</option><?php foreach ($statusLabels as $key => $cfg): ?><option value="<?= e($key) ?>" <?= $filterStatus === $key ? 'selected' : '' ?>><?= e($cfg[1]) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><label class="form-label small text-muted">Mã SP</label><input type="text" name="code" class="form-control form-control-sm" value="<?= e($filterCode) ?>" placeholder="Nhập mã SP"></div>
            <div class="col-auto"><button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-filter me-1"></i>Lọc</button><a href="finished_goods.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a></div>
        </form>
    </div></div>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-dark"><tr><th>Số FGS</th><th>Ngày</th><th>Khách hàng</th><th>Mã SP</th><th>Loại</th><th class="text-end">SL nhập</th><th class="text-end">Đã xuất</th><th class="text-end">Còn lại</th><th>Trạng thái</th><th>Ghi chú</th></tr></thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr><td colspan="10" class="text-center text-muted py-4">Chưa có dữ liệu kho thành phẩm</td></tr>
                    <?php else: foreach ($rows as $row): [$statusClass, $statusText] = $statusLabels[$row['status']] ?? ['bg-secondary', $row['status']]; ?>
                        <tr class="<?= $row['type'] === 'defect' ? 'table-danger' : '' ?>">
                            <td><div class="fw-semibold text-primary"><?= e($row['fgs_no']) ?></div><div class="small text-muted"><?= e($row['progress_no']) ?></div></td>
                            <td><?= formatDate($row['source_date']) ?></td>
                            <td><?php if ($row['customer_code']): ?><span class="badge bg-secondary me-1"><?= e($row['customer_code']) ?></span><?php endif; ?><?= e($row['customer_name']) ?></td>
                            <td><span class="badge bg-primary"><?= e($row['product_code']) ?></span><div class="small text-muted"><?= e($row['description']) ?></div></td>
                            <td><?= $row['type'] === 'defect' ? '<span class="badge bg-danger">Lỗi</span>' : '<span class="badge bg-success">HT</span>' ?></td>
                            <td class="text-end"><?= number_format((float) $row['qty_in'], 0) ?></td>
                            <td class="text-end"><?= number_format((float) $row['qty_exported'], 0) ?></td>
                            <td class="text-end fw-semibold <?= (float) $row['qty_remaining'] > 0 ? 'text-warning' : 'text-muted' ?>"><?= number_format((float) $row['qty_remaining'], 0) ?></td>
                            <td><span class="badge <?= $statusClass ?>"><?= e($statusText) ?></span></td>
                            <td class="small <?= $row['type'] === 'defect' ? 'text-danger fw-semibold' : 'text-muted' ?>"><?= e($row['note'] ?: ($row['type'] === 'defect' ? '⚠️ Hàng lỗi - chờ trả KH' : '—')) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
</div>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/footer.php'; ?>
