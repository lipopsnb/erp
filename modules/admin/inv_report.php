<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';

requireLogin();
requireRole('director', 'accountant', 'manager');

$pdo = getDBConnection();
// Categories for inv_items (internal supply items). NOT the same as assets.php $categoryMap (company_assets table).
$categoryMap = [
    'consumable' => 'Vật tư tiêu hao',
    'stationery' => 'Văn phòng phẩm',
    'equipment' => 'Thiết bị',
    'machinery' => 'Máy móc',
    'other' => 'Khác',
];
$selectedMonth = preg_match('/^\d{4}-\d{2}$/', $_GET['month'] ?? '') ? (string)$_GET['month'] : date('Y-m');
$filterCategory = trim($_GET['category'] ?? '');
$startDate = $selectedMonth . '-01';
$endDate = date('Y-m-t', strtotime($startDate));

$where = ['1=1'];
$params = [$startDate, $startDate, $startDate, $endDate, $startDate, $endDate, $startDate, $endDate];
if ($filterCategory !== '' && isset($categoryMap[$filterCategory])) {
    $where[] = 'i.category = ?';
    $params[] = $filterCategory;
}

$rows = fetchAllSafe(
    $pdo,
    "SELECT i.id, i.item_code, i.item_name, i.category, i.unit,
            COALESCE((SELECT SUM(quantity) FROM inv_imports WHERE item_id = i.id AND import_date < ?), 0) -
            COALESCE((SELECT SUM(quantity) FROM inv_exports WHERE item_id = i.id AND export_date < ?), 0) AS opening_stock,
            COALESCE((SELECT SUM(quantity) FROM inv_imports WHERE item_id = i.id AND import_date BETWEEN ? AND ?), 0) AS period_import,
            COALESCE((SELECT SUM(quantity) FROM inv_exports WHERE item_id = i.id AND export_date BETWEEN ? AND ?), 0) AS period_export,
            COALESCE((SELECT SUM(total_amount) FROM inv_imports WHERE item_id = i.id AND import_date BETWEEN ? AND ?), 0) AS period_import_amount
     FROM inv_items i
     WHERE " . implode(' AND ', $where) . "
     ORDER BY i.item_name ASC, i.id ASC",
    $params
);

$summaryWhere = '';
if ($filterCategory !== '' && isset($categoryMap[$filterCategory])) {
    $summaryWhere = ' AND it.category = ?';
}

$totalImportAmount = (float)fetchScalarSafe(
    $pdo,
    "SELECT COALESCE(SUM(ii.total_amount), 0)
     FROM inv_imports ii
     JOIN inv_items it ON it.id = ii.item_id
     WHERE ii.import_date BETWEEN ? AND ?" . $summaryWhere,
    array_merge([$startDate, $endDate], $filterCategory !== '' && isset($categoryMap[$filterCategory]) ? [$filterCategory] : []),
    0
);
$totalImportDocs = (int)fetchScalarSafe(
    $pdo,
    "SELECT COUNT(*)
     FROM inv_imports ii
     JOIN inv_items it ON it.id = ii.item_id
     WHERE ii.import_date BETWEEN ? AND ?" . $summaryWhere,
    array_merge([$startDate, $endDate], $filterCategory !== '' && isset($categoryMap[$filterCategory]) ? [$filterCategory] : []),
    0
);
$totalExportDocs = (int)fetchScalarSafe(
    $pdo,
    "SELECT COUNT(*)
     FROM inv_exports ie
     JOIN inv_items it ON it.id = ie.item_id
     WHERE ie.export_date BETWEEN ? AND ?" . $summaryWhere,
    array_merge([$startDate, $endDate], $filterCategory !== '' && isset($categoryMap[$filterCategory]) ? [$filterCategory] : []),
    0
);

include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/sidebar.php';
?>
<div class="main-content">
    <div class="container-fluid py-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
            <div>
                <h4 class="mb-1"><i class="fas fa-chart-bar me-2 text-primary"></i>Báo cáo kho vật tư</h4>
                <p class="text-muted mb-0">Tổng hợp nhập - xuất - tồn theo kỳ để theo dõi sử dụng vật tư hành chính.</p>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-md-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="text-muted small mb-1">Tổng tiền nhập kỳ</div>
                        <div class="fs-4 fw-bold"><?= e(number_format($totalImportAmount, 0, ',', '.')) ?> ₫</div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="text-muted small mb-1">Tổng phiếu nhập</div>
                        <div class="fs-4 fw-bold"><?= $totalImportDocs ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="text-muted small mb-1">Tổng phiếu xuất</div>
                        <div class="fs-4 fw-bold"><?= $totalExportDocs ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body py-2">
                <form method="get" class="row g-2 align-items-center">
                    <div class="col-md-3">
                        <input type="month" name="month" class="form-control form-control-sm" value="<?= e($selectedMonth) ?>">
                    </div>
                    <div class="col-md-3">
                        <select name="category" class="form-select form-select-sm">
                            <option value="">-- Tất cả loại hàng --</option>
                            <?php foreach ($categoryMap as $key => $label): ?>
                                <option value="<?= e($key) ?>" <?= $filterCategory === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-filter me-1"></i>Xem báo cáo</button>
                    </div>
                    <div class="col-auto">
                        <a href="/erp/modules/admin/inv_report.php" class="btn btn-sm btn-outline-secondary">Đặt lại</a>
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
                            <th class="text-end">Tồn đầu kỳ</th>
                            <th class="text-end">Nhập trong kỳ</th>
                            <th class="text-end">Xuất trong kỳ</th>
                            <th class="text-end">Tồn cuối kỳ</th>
                            <th class="text-end">Tổng tiền nhập</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted py-4">Chưa có dữ liệu báo cáo.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $row): ?>
                            <?php $closingStock = (float)$row['opening_stock'] + (float)$row['period_import'] - (float)$row['period_export']; ?>
                            <tr>
                                <td class="fw-semibold"><?= e($row['item_code']) ?></td>
                                <td><?= e($row['item_name']) ?></td>
                                <td><?= e($categoryMap[$row['category']] ?? $row['category']) ?></td>
                                <td><?= e($row['unit']) ?></td>
                                <td class="text-end"><?= e(number_format((float)$row['opening_stock'], 2, ',', '.')) ?></td>
                                <td class="text-end"><?= e(number_format((float)$row['period_import'], 2, ',', '.')) ?></td>
                                <td class="text-end"><?= e(number_format((float)$row['period_export'], 2, ',', '.')) ?></td>
                                <td class="text-end fw-semibold"><?= e(number_format($closingStock, 2, ',', '.')) ?></td>
                                <td class="text-end"><?= e(number_format((float)$row['period_import_amount'], 0, ',', '.')) ?> ₫</td>
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
