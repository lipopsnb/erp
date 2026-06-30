<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';

requireLogin();
requireRole('director', 'accountant', 'manager', 'warehouse');

$pdo = getDBConnection();
$errors = [];
$oldInputWasFlashed = false;
$stockTolerance = 0.0001;
$filterMonth = preg_match('/^\d{4}-\d{2}$/', $_GET['month'] ?? '') ? (string)$_GET['month'] : date('Y-m');
$filterItemId = (int)($_GET['item_id'] ?? 0);

$exportPageUrl = static function (array $overrides = []) use ($filterMonth, $filterItemId): string {
    $params = [
        'month' => $overrides['month'] ?? $filterMonth,
    ];
    $itemId = array_key_exists('item_id', $overrides) ? (int)$overrides['item_id'] : $filterItemId;
    if ($itemId > 0) {
        $params['item_id'] = $itemId;
    }
    return '/erp/modules/admin/inv_export.php?' . http_build_query($params);
};

$isValidDate = static function (?string $value): bool {
    if ($value === null || $value === '') {
        return false;
    }
    $date = DateTime::createFromFormat('Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
};

$generateExportNo = static function (string $exportDate) use ($pdo): string {
    $dateKey = date('Ymd', strtotime($exportDate));
    $prefix = 'INVEXP-' . $dateKey . '-';
    $last = (string)fetchScalarSafe(
        $pdo,
        'SELECT export_no FROM inv_exports WHERE export_no LIKE ? ORDER BY id DESC LIMIT 1',
        [$prefix . '%'],
        ''
    );
    $seq = $last !== '' ? ((int)substr($last, -3) + 1) : 1;
    return $prefix . str_pad((string)$seq, 3, '0', STR_PAD_LEFT);
};

$items = fetchAllSafe($pdo, 'SELECT id, item_code, item_name, unit FROM inv_items WHERE is_active = 1 ORDER BY item_name, id');
$itemIds = array_map(static fn(array $row): int => (int)$row['id'], $items);
$itemUnits = [];
foreach ($items as $itemRow) {
    $itemUnits[(int)$itemRow['id']] = $itemRow['unit'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ensurePostCsrf();
    $action = trim($_POST['action'] ?? 'create');

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $export = fetchOneSafe($pdo, 'SELECT * FROM inv_exports WHERE id = ? LIMIT 1', [$id]);
        if (!$export) {
            setFlash('danger', 'Không tìm thấy phiếu xuất.');
        } else {
            // Deleting an export always restores stock (never causes negative stock), so no stock check needed.
            $pdo->prepare('DELETE FROM inv_exports WHERE id = ?')->execute([$id]);
            setFlash('success', 'Đã xoá phiếu xuất.');
        }
        redirect($exportPageUrl(['month' => $filterMonth]));
    }

    $itemId = (int)($_POST['item_id'] ?? 0);
    $exportDate = trim($_POST['export_date'] ?? date('Y-m-d'));
    $quantity = (float)($_POST['quantity'] ?? 0);
    $purpose = trim($_POST['purpose'] ?? '');
    $department = trim($_POST['department'] ?? '') ?: null;
    $requestedByName = trim($_POST['requested_by_name'] ?? '') ?: null;
    $note = trim($_POST['note'] ?? '') ?: null;

    if (!in_array($itemId, $itemIds, true)) {
        $errors[] = 'Vui lòng chọn hàng hoá hợp lệ.';
    }
    if (!$isValidDate($exportDate)) {
        $errors[] = 'Ngày xuất không hợp lệ.';
    }
    if ($quantity <= 0) {
        $errors[] = 'Số lượng xuất phải lớn hơn 0.';
    }
    if ($purpose === '') {
        $errors[] = 'Vui lòng nhập mục đích xuất kho.';
    }

    $currentStock = 0.0;
    if (!$errors) {
        $currentStock = (float)fetchScalarSafe(
            $pdo,
            'SELECT COALESCE((SELECT SUM(quantity) FROM inv_imports WHERE item_id = ?), 0) - COALESCE((SELECT SUM(quantity) FROM inv_exports WHERE item_id = ?), 0)',
            [$itemId, $itemId],
            0
        );
        if ($quantity > $currentStock + $stockTolerance) {
            $errors[] = 'Số lượng xuất vượt quá tồn kho hiện tại.';
        }
    }

    if (!$errors) {
        try {
            $stmt = $pdo->prepare("INSERT INTO inv_exports
                (export_no, item_id, export_date, quantity, purpose, department, requested_by_name, note, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $generateExportNo($exportDate),
                $itemId,
                $exportDate,
                $quantity,
                $purpose,
                $department,
                $requestedByName,
                $note,
                currentUserId(),
            ]);
            clearOldInput();
            setFlash('success', 'Đã lưu phiếu xuất kho.');
            redirect($exportPageUrl(['month' => date('Y-m', strtotime($exportDate))]));
        } catch (Throwable $e) {
            $errors[] = 'Không thể lưu phiếu xuất kho.';
        }
    }

    if ($errors) {
        flashOldInput($_POST);
        $oldInputWasFlashed = true;
    }
}

$formValues = [
    'item_id' => '',
    'export_date' => date('Y-m-d'),
    'quantity' => '',
    'purpose' => '',
    'department' => '',
    'requested_by_name' => '',
    'note' => '',
];
if (isset($_SESSION['_old_input']) && is_array($_SESSION['_old_input'])) {
    foreach ($formValues as $key => $value) {
        if (array_key_exists($key, $_SESSION['_old_input'])) {
            $formValues[$key] = (string)$_SESSION['_old_input'][$key];
        }
    }
}

$historyWhere = ['1=1'];
$historyParams = [];
if ($filterMonth !== '') {
    $historyWhere[] = "DATE_FORMAT(ie.export_date, '%Y-%m') = ?";
    $historyParams[] = $filterMonth;
}
if ($filterItemId > 0) {
    $historyWhere[] = 'ie.item_id = ?';
    $historyParams[] = $filterItemId;
}

$exports = fetchAllSafe(
    $pdo,
    "SELECT ie.*, it.item_code, it.item_name, it.unit, u.full_name AS created_by_name
     FROM inv_exports ie
     JOIN inv_items it ON it.id = ie.item_id
     LEFT JOIN users u ON u.id = ie.created_by
     WHERE " . implode(' AND ', $historyWhere) . "
     ORDER BY ie.export_date DESC, ie.id DESC",
    $historyParams
);

include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/sidebar.php';
?>
<div class="main-content">
    <div class="container-fluid py-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
            <div>
                <h4 class="mb-1"><i class="fas fa-arrow-up me-2 text-primary"></i>Xuất kho vật tư</h4>
                <p class="text-muted mb-0">Xuất vật tư cho các phòng ban và kiểm soát không vượt tồn kho hiện tại.</p>
            </div>
        </div>

        <?php showFlash(); ?>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white">
                <h5 class="mb-0">Tạo phiếu xuất kho</h5>
            </div>
            <div class="card-body">
                <?php if ($errors): ?>
                    <div class="alert alert-danger">
                        <ul class="mb-0 ps-3">
                            <?php foreach ($errors as $error): ?>
                                <li><?= e($error) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                <?php if (!$items): ?>
                    <div class="alert alert-warning mb-0">Chưa có hàng hoá đang sử dụng. Vui lòng tạo danh mục trước.</div>
                <?php else: ?>
                    <form method="post">
                        <?= csrfInput() ?>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Hàng hoá <span class="text-danger">*</span></label>
                                <select name="item_id" id="item_id" class="form-select" required>
                                    <option value="">-- Chọn hàng hoá --</option>
                                    <?php foreach ($items as $item): ?>
                                        <option value="<?= (int)$item['id'] ?>" data-unit="<?= e($item['unit']) ?>" <?= (int)$formValues['item_id'] === (int)$item['id'] ? 'selected' : '' ?>>
                                            <?= e($item['item_code'] . ' - ' . $item['item_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label fw-semibold">Ngày xuất <span class="text-danger">*</span></label>
                                <input type="date" name="export_date" class="form-control" value="<?= e($formValues['export_date']) ?>" required>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label fw-semibold">Số lượng <span class="text-danger">*</span></label>
                                <input type="number" step="0.01" min="0.01" name="quantity" class="form-control" value="<?= e($formValues['quantity']) ?>" required>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label fw-semibold">Đơn vị tính</label>
                                <input type="text" id="item_unit_display" class="form-control" value="<?= e($itemUnits[(int)$formValues['item_id']] ?? '') ?>" readonly>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label fw-semibold">Tồn hiện tại</label>
                                <input type="text" id="current_stock_display" class="form-control" value="0" readonly>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Mục đích <span class="text-danger">*</span></label>
                                <input type="text" name="purpose" class="form-control" value="<?= e($formValues['purpose']) ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Phòng ban nhận</label>
                                <input type="text" name="department" class="form-control" value="<?= e($formValues['department']) ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Người đề nghị</label>
                                <input type="text" name="requested_by_name" class="form-control" value="<?= e($formValues['requested_by_name']) ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold">Ghi chú</label>
                                <textarea name="note" rows="3" class="form-control"><?= e($formValues['note']) ?></textarea>
                            </div>
                        </div>
                        <div class="mt-3">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Lưu phiếu xuất</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body py-2">
                <form method="get" class="row g-2 align-items-center">
                    <div class="col-md-2">
                        <input type="month" name="month" class="form-control form-control-sm" value="<?= e($filterMonth) ?>">
                    </div>
                    <div class="col-md-4">
                        <select name="item_id" class="form-select form-select-sm">
                            <option value="0">-- Tất cả hàng hoá --</option>
                            <?php foreach ($items as $item): ?>
                                <option value="<?= (int)$item['id'] ?>" <?= $filterItemId === (int)$item['id'] ? 'selected' : '' ?>><?= e($item['item_code'] . ' - ' . $item['item_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-filter me-1"></i>Lọc</button>
                    </div>
                    <div class="col-auto">
                        <a href="/erp/modules/admin/inv_export.php" class="btn btn-sm btn-outline-secondary">Đặt lại</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>Số phiếu</th>
                            <th>Ngày xuất</th>
                            <th>Hàng hoá</th>
                            <th class="text-end">Số lượng</th>
                            <th>Mục đích</th>
                            <th>Phòng ban</th>
                            <th>Người đề nghị</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$exports): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">Chưa có phiếu xuất nào.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($exports as $export): ?>
                            <tr>
                                <td class="fw-semibold"><?= e($export['export_no']) ?></td>
                                <td><?= e(formatDate($export['export_date'])) ?></td>
                                <td>
                                    <div class="fw-semibold"><?= e($export['item_name']) ?></div>
                                    <div class="small text-muted"><?= e($export['item_code']) ?></div>
                                </td>
                                <td class="text-end"><?= e(number_format((float)$export['quantity'], 2, ',', '.')) ?> <?= e($export['unit']) ?></td>
                                <td><?= e($export['purpose']) ?></td>
                                <td><?= e($export['department'] ?: '—') ?></td>
                                <td><?= e($export['requested_by_name'] ?: '—') ?></td>
                                <td>
                                    <form method="post" onsubmit="return confirm('Bạn có chắc muốn xoá phiếu xuất này?');">
                                        <?= csrfInput() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int)$export['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
async function updateStockInfo() {
    const select = document.getElementById('item_id');
    if (!select) {
        return;
    }
    const option = select.options[select.selectedIndex];
    document.getElementById('item_unit_display').value = option ? (option.getAttribute('data-unit') || '') : '';
    document.getElementById('current_stock_display').value = '0';

    const itemId = select.value;
    if (!itemId) {
        return;
    }

    try {
        const response = await fetch('/erp/api/inv_stock.php?item_id=' + encodeURIComponent(itemId));
        if (!response.ok) {
            throw new Error('HTTP ' + response.status);
        }
        const data = await response.json();
        if (data && data.ok) {
            document.getElementById('current_stock_display').value = Number(data.stock || 0).toLocaleString('vi-VN') + ' ' + (data.unit || '');
        } else {
            document.getElementById('current_stock_display').value = 'Không tải được tồn kho';
        }
    } catch (_error) {
        document.getElementById('current_stock_display').value = 'Không tải được tồn kho';
    }
}

const itemSelect = document.getElementById('item_id');
if (itemSelect) {
    itemSelect.addEventListener('change', updateStockInfo);
}
updateStockInfo();
</script>
<?php
if ($oldInputWasFlashed || isset($_SESSION['_old_input'])) {
    clearOldInput();
}
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/footer.php';
?>
