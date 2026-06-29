<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';
requireLogin();
requireRole('director','accountant','manager');

$pdo = getDBConnection();
$selectedYear = (int)($_GET['year'] ?? date('Y'));
$selectedMonth = (int)($_GET['month'] ?? date('n'));
if ($selectedYear < 2000 || $selectedYear > 2100) {
    $selectedYear = (int)date('Y');
}
if ($selectedMonth < 1 || $selectedMonth > 12) {
    $selectedMonth = (int)date('n');
}

$categories = getExpenseCategories($pdo);
$hasCategories = !empty($categories);
$budgetStmt = $pdo->prepare('SELECT * FROM admin_budgets WHERE budget_year = ? AND budget_month = ?');
$budgetStmt->execute([$selectedYear, $selectedMonth]);
$budgetRows = $budgetStmt->fetchAll(PDO::FETCH_ASSOC);
$budgetByCategory = [];
foreach ($budgetRows as $row) {
    $budgetByCategory[$row['category_id']] = $row;
}

$actualStmt = $pdo->prepare("SELECT category_id, COALESCE(SUM(amount), 0) AS actual_amount
    FROM expense_requests
    WHERE status = 'approved' AND expense_date BETWEEN ? AND ?
    GROUP BY category_id");
$startDate = sprintf('%04d-%02d-01', $selectedYear, $selectedMonth);
$endDate = date('Y-m-t', strtotime($startDate));
$actualStmt->execute([$startDate, $endDate]);
$actualByCategory = [];
foreach ($actualStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $actualByCategory[$row['category_id']] = (float)$row['actual_amount'];
}

$totalBudget = 0;
$totalActual = 0;
$rows = [];
foreach ($categories as $category) {
    $budgetAmount = (float)($budgetByCategory[$category['id']]['budget_amount'] ?? 0);
    $actualAmount = (float)($actualByCategory[$category['id']] ?? 0);
    $remaining = $budgetAmount - $actualAmount;
    $usagePercent = $budgetAmount > 0 ? min(100, round(($actualAmount / $budgetAmount) * 100, 2)) : ($actualAmount > 0 ? 100 : 0);
    $rows[] = [
        'category' => $category,
        'budget' => $budgetAmount,
        'actual' => $actualAmount,
        'remaining' => $remaining,
        'usage' => $usagePercent,
        'note' => $budgetByCategory[$category['id']]['note'] ?? '',
    ];
    $totalBudget += $budgetAmount;
    $totalActual += $actualAmount;
}
$totalRemaining = $totalBudget - $totalActual;
$totalUsage = $totalBudget > 0 ? min(100, round(($totalActual / $totalBudget) * 100, 2)) : ($totalActual > 0 ? 100 : 0);

$csrf = generateCSRF();
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/sidebar.php';
?>
<div class="main-content">
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="fas fa-chart-pie me-2 text-primary"></i>Ngân sách hành chính</h4>
            <p class="text-muted mb-0">Đối chiếu ngân sách và thực chi theo từng loại chi phí</p>
        </div>
        <button class="btn btn-primary" id="btnSetBudget" <?= $hasCategories ? '' : 'disabled' ?>><i class="fas fa-sliders-h me-1"></i> Thiết lập ngân sách</button>
    </div>

    <?php showFlash(); ?>
    <?php if (!$hasCategories): ?>
    <div class="alert alert-warning">
        Chưa có loại chi phí nào. Vui lòng thêm vào bảng expense_categories.
    </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2">
            <form class="row g-2 align-items-center" method="GET">
                <div class="col-md-2"><input type="number" name="year" class="form-control form-control-sm" min="2000" max="2100" value="<?= $selectedYear ?>"></div>
                <div class="col-md-2"><select name="month" class="form-select form-select-sm"><?php for ($m = 1; $m <= 12; $m++): ?><option value="<?= $m ?>" <?= $selectedMonth === $m ? 'selected' : '' ?>>Tháng <?= $m ?></option><?php endfor; ?></select></div>
                <div class="col-auto"><button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-filter me-1"></i>Xem</button></div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>Loại chi phí</th>
                            <th width="160" class="text-end">Ngân sách</th>
                            <th width="160" class="text-end">Thực chi</th>
                            <th width="160" class="text-end">Còn lại</th>
                            <th width="220">% Sử dụng</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <?php $progressClass = $row['usage'] >= 100 ? 'bg-danger' : ($row['usage'] >= 80 ? 'bg-warning' : 'bg-success'); ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?= htmlspecialchars($row['category']['category_name']) ?></div>
                                <div class="small text-muted"><?= htmlspecialchars($row['note'] ?: '—') ?></div>
                            </td>
                            <td class="text-end fw-semibold"><?= number_format($row['budget'], 0, ',', '.') ?></td>
                            <td class="text-end"><?= number_format($row['actual'], 0, ',', '.') ?></td>
                            <td class="text-end <?= $row['remaining'] < 0 ? 'text-danger fw-semibold' : '' ?>"><?= number_format($row['remaining'], 0, ',', '.') ?></td>
                            <td>
                                <div class="d-flex justify-content-between small mb-1"><span><?= number_format($row['usage'], 0, ',', '.') ?>%</span><span><?= number_format($row['actual'], 0, ',', '.') ?>/<?= number_format($row['budget'], 0, ',', '.') ?></span></div>
                                <div class="progress" style="height: 10px;"><div class="progress-bar <?= $progressClass ?>" style="width: <?= min(100, $row['usage']) ?>%"></div></div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light">
                        <tr>
                            <th>Tổng cộng</th>
                            <th class="text-end"><?= number_format($totalBudget, 0, ',', '.') ?></th>
                            <th class="text-end"><?= number_format($totalActual, 0, ',', '.') ?></th>
                            <th class="text-end <?= $totalRemaining < 0 ? 'text-danger' : '' ?>"><?= number_format($totalRemaining, 0, ',', '.') ?></th>
                            <th>
                                <div class="d-flex justify-content-between small mb-1"><span><?= number_format($totalUsage, 0, ',', '.') ?>%</span><span><?= number_format($totalActual, 0, ',', '.') ?>/<?= number_format($totalBudget, 0, ',', '.') ?></span></div>
                                <div class="progress" style="height: 10px;"><div class="progress-bar <?= $totalUsage >= 100 ? 'bg-danger' : ($totalUsage >= 80 ? 'bg-warning' : 'bg-success') ?>" style="width: <?= min(100, $totalUsage) ?>%"></div></div>
                            </th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>
</div>

<div class="modal fade" id="modalBudget" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-sliders-h me-2"></i>Thiết lập ngân sách tháng <?= $selectedMonth ?>/<?= $selectedYear ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="formBudget">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="budget_year" value="<?= $selectedYear ?>">
                    <input type="hidden" name="budget_month" value="<?= $selectedMonth ?>">
                    <div class="table-responsive">
                        <table class="table table-bordered align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Loại chi phí</th>
                                    <th width="180" class="text-end">Ngân sách</th>
                                    <th>Ghi chú</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($rows as $row): ?>
                                <tr>
                                    <td class="fw-semibold"><?= htmlspecialchars($row['category']['category_name']) ?></td>
                                    <td><input type="number" step="0.01" min="0" name="budgets[<?= $row['category']['id'] ?>][amount]" class="form-control text-end" value="<?= htmlspecialchars((string)$row['budget']) ?>"></td>
                                    <td><input type="text" name="budgets[<?= $row['category']['id'] ?>][note]" class="form-control" value="<?= htmlspecialchars($row['note']) ?>"></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </form>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Huỷ</button><button type="button" class="btn btn-primary" id="btnSaveBudget"><i class="fas fa-save me-1"></i>Lưu ngân sách</button></div>
        </div>
    </div>
</div>

<script>
const csrfBudget = '<?= $csrf ?>';
const hasBudgetCategories = <?= $hasCategories ? 'true' : 'false' ?>;
const budgetModal = new bootstrap.Modal(document.getElementById('modalBudget'));
document.getElementById('btnSetBudget').addEventListener('click', () => {
    if (!hasBudgetCategories) {
        alert('Chưa có loại chi phí nào. Vui lòng thêm vào bảng expense_categories.');
        return;
    }
    budgetModal.show();
});
document.getElementById('btnSaveBudget').addEventListener('click', async () => {
    if (!hasBudgetCategories) {
        alert('Chưa có loại chi phí nào. Vui lòng thêm vào bảng expense_categories.');
        return;
    }
    const form = document.getElementById('formBudget');
    const fd = new FormData(form);
    if (!fd.has('csrf_token')) {
        fd.append('csrf_token', csrfBudget);
    }
    fd.append('action', 'save');
    const response = await fetch('/erp/api/admin/save_budget.php', { method: 'POST', body: fd });
    const data = await response.json();
    if (data.ok) {
        location.reload();
        return;
    }
    alert(data.msg || 'Không thể lưu ngân sách');
});
</script>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/footer.php'; ?>
