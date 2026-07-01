<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';

requireLogin();
requireRole('director', 'accountant', 'manager');

$pdo = getDBConnection();
$errors = [];
$oldInputWasFlashed = false;
$categoryMap = [
    'consumable' => 'Vật tư tiêu hao',
    'stationery' => 'Văn phòng phẩm',
    'equipment' => 'Thiết bị',
    'machinery' => 'Máy móc',
    'other' => 'Khác',
];
$activeMap = [
    '1' => 'Đang sử dụng',
    '0' => 'Ngưng sử dụng',
];
$filterCategory = trim($_GET['category'] ?? '');
$filterActive = trim($_GET['is_active'] ?? '');

$inventoryItemsUrl = static function (array $overrides = []) use ($filterCategory, $filterActive): string {
    $params = [];
    $category = $overrides['category'] ?? $filterCategory;
    $isActive = array_key_exists('is_active', $overrides) ? (string)$overrides['is_active'] : $filterActive;
    if ($category !== '') {
        $params['category'] = $category;
    }
    if ($isActive !== '') {
        $params['is_active'] = $isActive;
    }
    if (!empty($overrides['action'])) {
        $params['action'] = $overrides['action'];
    }
    if (!empty($overrides['id'])) {
        $params['id'] = (int)$overrides['id'];
    }
    if (!empty($overrides['show_form'])) {
        $params['show_form'] = 1;
    }
    return '/erp/modules/admin/inv_items.php' . ($params ? '?' . http_build_query($params) : '');
};

$makeItemCode = static function () use ($pdo): string {
    $lastCode = (string)fetchScalarSafe(
        $pdo,
        "SELECT item_code FROM inv_items WHERE item_code LIKE 'ITM-%' ORDER BY id DESC LIMIT 1",
        [],
        ''
    );
    $next = $lastCode !== '' ? ((int)substr($lastCode, -3) + 1) : 1;
    return 'ITM-' . str_pad((string)$next, 3, '0', STR_PAD_LEFT);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ensurePostCsrf();
    $action = trim($_POST['action'] ?? '');

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $itemCode = trim($_POST['item_code'] ?? '');
        $itemName = trim($_POST['item_name'] ?? '');
        $category = trim($_POST['category'] ?? 'other');
        $unit = trim($_POST['unit'] ?? 'Cái');
        $minStock = trim($_POST['min_stock'] ?? '') !== '' ? (float)$_POST['min_stock'] : 0;
        $description = trim($_POST['description'] ?? '') ?: null;
        $isActive = !empty($_POST['is_active']) ? 1 : 0;

        if ($itemName === '') {
            $errors[] = 'Vui lòng nhập tên hàng hoá.';
        }
        if (!isset($categoryMap[$category])) {
            $errors[] = 'Loại hàng hoá không hợp lệ.';
        }
        if ($unit === '') {
            $errors[] = 'Vui lòng nhập đơn vị tính.';
        }
        if ($minStock < 0) {
            $errors[] = 'Tồn tối thiểu không được âm.';
        }

        $existingItem = null;
        if ($id > 0) {
            $existingItem = fetchOneSafe($pdo, 'SELECT id, item_code FROM inv_items WHERE id = ? LIMIT 1', [$id]);
            if (!$existingItem) {
                $errors[] = 'Không tìm thấy hàng hoá cần cập nhật.';
            }
        }
        if ($itemCode === '') {
            $itemCode = $id > 0 && !empty($existingItem['item_code']) ? (string)$existingItem['item_code'] : $makeItemCode();
            $_POST['item_code'] = $itemCode;
        }

        $duplicate = fetchOneSafe($pdo, 'SELECT id FROM inv_items WHERE item_code = ? AND id != ? LIMIT 1', [$itemCode, $id]);
        if ($duplicate) {
            $errors[] = 'Mã hàng hoá đã tồn tại.';
        }

        if (!$errors) {
            try {
                if ($id > 0) {
                    $stmt = $pdo->prepare("UPDATE inv_items
                        SET item_code = ?, item_name = ?, category = ?, unit = ?, min_stock = ?, description = ?, is_active = ?,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE id = ?");
                    $stmt->execute([$itemCode, $itemName, $category, $unit, $minStock, $description, $isActive, $id]);
                    setFlash('success', 'Đã cập nhật hàng hoá.');
                } else {
                    $stmt = $pdo->prepare("INSERT INTO inv_items
                        (item_code, item_name, category, unit, min_stock, description, is_active)
                        VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$itemCode, $itemName, $category, $unit, $minStock, $description, $isActive]);
                    setFlash('success', 'Đã thêm hàng hoá mới.');
                }
                clearOldInput();
                redirect($inventoryItemsUrl());
            } catch (Throwable $e) {
                if (str_contains($e->getMessage(), 'Duplicate entry')) {
                    $errors[] = 'Mã hàng hoá đã tồn tại. Vui lòng thử lại.';
                } else {
                    $errors[] = 'Không thể lưu hàng hoá.';
                }
            }
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $item = fetchOneSafe($pdo, 'SELECT id, item_name FROM inv_items WHERE id = ? LIMIT 1', [$id]);
        if (!$item) {
            setFlash('danger', 'Không tìm thấy hàng hoá.');
        } else {
            $relatedCount = (int)fetchScalarSafe(
                $pdo,
                'SELECT (SELECT COUNT(*) FROM inv_imports WHERE item_id = ?) + (SELECT COUNT(*) FROM inv_exports WHERE item_id = ?) AS total',
                [$id, $id],
                0
            );
            if ($relatedCount > 0) {
                setFlash('danger', 'Không thể xoá hàng hoá đã phát sinh nhập/xuất.');
            } else {
                $pdo->prepare('DELETE FROM inv_items WHERE id = ?')->execute([$id]);
                setFlash('success', 'Đã xoá hàng hoá.');
            }
        }
        redirect($inventoryItemsUrl());
    }

    if ($errors) {
        flashOldInput($_POST);
        $oldInputWasFlashed = true;
    }
}

$editItem = null;
if (($_GET['action'] ?? '') === 'edit' && (int)($_GET['id'] ?? 0) > 0) {
    $editItem = fetchOneSafe($pdo, 'SELECT * FROM inv_items WHERE id = ? LIMIT 1', [(int)$_GET['id']]);
}

$defaultItemCode = $makeItemCode();
$formValues = [
    'id' => $editItem['id'] ?? '',
    'item_code' => $editItem['item_code'] ?? $defaultItemCode,
    'item_name' => $editItem['item_name'] ?? '',
    'category' => $editItem['category'] ?? 'other',
    'unit' => $editItem['unit'] ?? 'Cái',
    'min_stock' => isset($editItem['min_stock']) ? (string)(float)$editItem['min_stock'] : '0',
    'description' => $editItem['description'] ?? '',
    'is_active' => isset($editItem['is_active']) ? (string)(int)$editItem['is_active'] : '1',
];
if (isset($_SESSION['_old_input']) && is_array($_SESSION['_old_input'])) {
    foreach ($formValues as $key => $value) {
        if (array_key_exists($key, $_SESSION['_old_input'])) {
            if ($key === 'is_active') {
                $formValues[$key] = !empty($_SESSION['_old_input'][$key]) ? '1' : '0';
            } else {
                $formValues[$key] = (string)$_SESSION['_old_input'][$key];
            }
        }
    }
}
if ($formValues['item_code'] === '') {
    $formValues['item_code'] = $defaultItemCode;
}
$showForm = !empty($errors) || $editItem !== null || isset($_GET['show_form']);

$where = ['1=1'];
$params = [];
if ($filterCategory !== '') {
    $where[] = 'i.category = ?';
    $params[] = $filterCategory;
}
if ($filterActive !== '' && isset($activeMap[$filterActive])) {
    $where[] = 'i.is_active = ?';
    $params[] = (int)$filterActive;
}

$items = fetchAllSafe(
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
     ORDER BY i.created_at DESC, i.id DESC",
    $params
);

include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/sidebar.php';
?>
<div class="main-content">
    <div class="container-fluid py-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
            <div>
                <h4 class="mb-1"><i class="fas fa-list-alt me-2 text-primary"></i>Danh mục hàng hoá</h4>
                <p class="text-muted mb-0">Quản lý vật tư tiêu hao, văn phòng phẩm, thiết bị và máy móc nội bộ.</p>
            </div>
            <button class="btn btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#inventory-item-form" aria-expanded="<?= $showForm ? 'true' : 'false' ?>">
                <i class="fas fa-plus me-1"></i><?= $editItem ? 'Sửa hàng hoá' : 'Thêm hàng hoá' ?>
            </button>
        </div>

        <?php showFlash(); ?>

        <div class="collapse <?= $showForm ? 'show' : '' ?> mb-4" id="inventory-item-form">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><?= $editItem ? 'Cập nhật hàng hoá' : 'Thêm hàng hoá mới' ?></h5>
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
                    <form method="post">
                        <?= csrfInput() ?>
                        <input type="hidden" name="action" value="save">
                        <input type="hidden" name="id" value="<?= e($formValues['id']) ?>">
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">Mã hàng</label>
                                <input type="text" name="item_code" class="form-control" value="<?= e($formValues['item_code']) ?>" placeholder="ITM-001">
                                <div class="form-text">Để trống hệ thống sẽ tự tạo mã dạng ITM-001.</div>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label fw-semibold">Tên hàng hoá <span class="text-danger">*</span></label>
                                <input type="text" name="item_name" class="form-control" value="<?= e($formValues['item_name']) ?>" required>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label fw-semibold">Loại hàng</label>
                                <select name="category" class="form-select">
                                    <?php foreach ($categoryMap as $key => $label): ?>
                                        <option value="<?= e($key) ?>" <?= $formValues['category'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label fw-semibold">Đơn vị tính <span class="text-danger">*</span></label>
                                <input type="text" name="unit" class="form-control" value="<?= e($formValues['unit']) ?>" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">Tồn tối thiểu</label>
                                <input type="number" step="0.01" min="0" name="min_stock" class="form-control" value="<?= e($formValues['min_stock']) ?>">
                            </div>
                            <div class="col-md-3 d-flex align-items-end">
                                <div class="form-check form-switch mb-2">
                                    <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1" <?= $formValues['is_active'] === '1' ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="is_active">Đang sử dụng</label>
                                </div>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold">Mô tả</label>
                                <textarea name="description" rows="3" class="form-control"><?= e($formValues['description']) ?></textarea>
                            </div>
                        </div>
                        <div class="mt-3 d-flex gap-2">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Lưu hàng hoá</button>
                            <?php if ($editItem): ?>
                                <a class="btn btn-outline-secondary" href="<?= e($inventoryItemsUrl()) ?>">Hủy</a>
                            <?php endif; ?>
                        </div>
                    </form>
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
                    <div class="col-md-3">
                        <select name="is_active" class="form-select form-select-sm">
                            <option value="">-- Tất cả trạng thái --</option>
                            <?php foreach ($activeMap as $key => $label): ?>
                                <option value="<?= e($key) ?>" <?= $filterActive === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-filter me-1"></i>Lọc</button>
                    </div>
                    <div class="col-auto">
                        <a href="/erp/modules/admin/inv_items.php" class="btn btn-sm btn-outline-secondary">Đặt lại</a>
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
                            <th class="text-end">Tồn hiện tại</th>
                            <th class="text-end">Tồn tối thiểu</th>
                            <th>Trạng thái</th>
                            <th>Mô tả</th>
                            <th width="150">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$items): ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted py-4">Chưa có hàng hoá nào.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($items as $item): ?>
                            <?php
                            $stock = (float)$item['current_stock'];
                            $minStock = (float)$item['min_stock'];
                            $isLowStock = $stock < $minStock;
                            ?>
                            <tr>
                                <td class="fw-semibold"><?= e($item['item_code']) ?></td>
                                <td>
                                    <div class="fw-semibold"><?= e($item['item_name']) ?></div>
                                    <?php if ($isLowStock): ?>
                                        <span class="badge bg-danger mt-1">Dưới mức tối thiểu</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= e($categoryMap[$item['category']] ?? $item['category']) ?></td>
                                <td><?= e($item['unit']) ?></td>
                                <td class="text-end fw-semibold <?= $isLowStock ? 'text-danger' : '' ?>"><?= e(number_format($stock, 2, ',', '.')) ?></td>
                                <td class="text-end"><?= e(number_format($minStock, 2, ',', '.')) ?></td>
                                <td>
                                    <span class="badge <?= (int)$item['is_active'] === 1 ? 'bg-success' : 'bg-secondary' ?>">
                                        <?= (int)$item['is_active'] === 1 ? 'Đang sử dụng' : 'Ngưng sử dụng' ?>
                                    </span>
                                </td>
                                <td class="text-muted small"><?= e($item['description'] ?: '—') ?></td>
                                <td>
                                    <div class="d-flex gap-2">
                                        <a class="btn btn-sm btn-outline-primary" href="/erp/modules/admin/inv_items.php?<?= e(http_build_query(['action' => 'edit', 'id' => (int)$item['id'], 'category' => $filterCategory, 'is_active' => $filterActive])) ?>">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <form method="post" onsubmit="return confirm('Bạn có chắc muốn xoá hàng hoá này?');">
                                            <?= csrfInput() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
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
<?php
if ($oldInputWasFlashed || isset($_SESSION['_old_input'])) {
    clearOldInput();
}
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/footer.php';
