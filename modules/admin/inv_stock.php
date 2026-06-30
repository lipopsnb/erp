<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';

requireLogin();
requireRole('director', 'accountant', 'manager', 'warehouse');

$pdo = getDBConnection();
$stockTolerance = 0.0001;
// Categories for inv_items (internal supply items). NOT the same as assets.php $categoryMap (company_assets table).
$categoryMap = [
    'consumable' => 'Vật tư tiêu hao',
    'stationery' => 'Văn phòng phẩm',
    'equipment' => 'Thiết bị',
    'machinery' => 'Máy móc',
    'other' => 'Khác',
];
$filterCategory = trim($_GET['category'] ?? '');

$where = ['1=1'];
$params = [];
if ($filterCategory !== '' && isset($categoryMap[$filterCategory])) {
    $where[] = 'i.category = ?';
    $params[] = $filterCategory;
}

$rows = fetchAllSafe(
    $pdo,
    "SELECT i.*,
            COALESCE(imp.total_in, 0) AS total_in,
            COALESCE(exp.total_out, 0) AS total_out,
            COALESCE(imp.total_in, 0) - COALESCE(exp.total_out, 0) AS current_stock
     FROM inv_items i
     LEFT JOIN (
        SELECT item_id, COALESCE(SUM(quantity), 0) AS total_in
        FROM inv_imports
        GROUP BY item_id
     ) imp ON imp.item_id = i.id
     LEFT JOIN (
        SELECT item_id, COALESCE(SUM(quantity), 0) AS total_out
        FROM inv_exports
        GROUP BY item_id
     ) exp ON exp.item_id = i.id
     WHERE " . implode(' AND ', $where) . "
     ORDER BY i.item_name ASC, i.id ASC",
    $params
);

$totalItems = count($rows);
$lowStockCount = 0;
$outOfStockCount = 0;
foreach ($rows as $row) {
    $stock = (float)$row['current_stock'];
    $minStock = (float)$row['min_stock'];
    if ($stock <= $stockTolerance) {
        $outOfStockCount++;
    } elseif ($stock < $minStock) {
        $lowStockCount++;
    }
}

include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/sidebar.php';
?>
<div class="main-content">
    <div class="container-fluid py-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
            <div>
                <h4 class="mb-1"><i class="fas fa-warehouse me-2 text-primary"></i>Tồn kho vật tư</h4>
                <p class="text-muted mb-0">Theo dõi tổng nhập, tổng xuất và mức tồn hiện tại của toàn bộ vật tư hành chính.</p>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-md-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="text-muted small mb-1">Tổng loại hàng</div>
                        <div class="fs-3 fw-bold"><?= $totalItems ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="text-muted small mb-1">Đang sắp hết</div>
                        <div class="fs-3 fw-bold text-danger"><?= $lowStockCount ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="text-muted small mb-1">Hết hàng</div>
                        <div class="fs-3 fw-bold text-secondary"><?= $outOfStockCount ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body py-2">
                <form method="get" class="row g-2 align-items-center">
                    <div class="col-md-3">
                        <select name="category" class="form-select form-select-sm">
                            <option value="">-- Tất cả loại hàng --</option>
                            <?php foreach ($categoryMap as $key => $label): ?>
                                <option value="<?= e($key) ?>" <?= $filterCategory === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-filter me-1"></i>Lọc</button>
                    </div>
                    <div class="col-auto">
                        <a href="/erp/modules/admin/inv_stock.php" class="btn btn-sm btn-outline-secondary">Đặt lại</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>Mã hàng</th>
                            <th>Tên hàng</th>
                            <th>Loại</th>
                            <th>Đơn vị</th>
                            <th class="text-end">Tổng nhập</th>
                            <th class="text-end">Tổng xuất</th>
                            <th class="text-end">Tồn kho</th>
                            <th class="text-end">Tồn tối thiểu</th>
                            <th>Trạng thái</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted py-4">Chưa có dữ liệu tồn kho.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $row): ?>
                            <?php
                            $stock = (float)$row['current_stock'];
                            $minStock = (float)$row['min_stock'];
                            if ($stock <= $stockTolerance) {
                                $badgeClass = 'bg-secondary';
                                $badgeLabel = 'Hết hàng';
                            } elseif ($stock < $minStock) {
                                $badgeClass = 'bg-danger';
                                $badgeLabel = 'Sắp hết';
                            } else {
                                $badgeClass = 'bg-success';
                                $badgeLabel = 'Đủ hàng';
                            }
                            ?>
                            <tr>
                                <td class="fw-semibold"><?= e($row['item_code']) ?></td>
                                <td><?= e($row['item_name']) ?></td>
                                <td><?= e($categoryMap[$row['category']] ?? $row['category']) ?></td>
                                <td><?= e($row['unit']) ?></td>
                                <td class="text-end"><?= e(number_format((float)$row['total_in'], 2, ',', '.')) ?></td>
                                <td class="text-end"><?= e(number_format((float)$row['total_out'], 2, ',', '.')) ?></td>
                                <td class="text-end fw-semibold <?= $stock <= $stockTolerance || $stock < $minStock ? 'text-danger' : '' ?>"><?= e(number_format($stock, 2, ',', '.')) ?></td>
                                <td class="text-end"><?= e(number_format($minStock, 2, ',', '.')) ?></td>
                                <td><span class="badge <?= $badgeClass ?>"><?= $badgeLabel ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/footer.php'; ?>
