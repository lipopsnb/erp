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
$defaultVatPercent = 10;
$paymentStatusMap = [
    'paid' => 'Đã thanh toán',
    'unpaid' => 'Chưa thanh toán',
];
$activeTab = in_array($_GET['tab'] ?? 'import', ['import', 'history'], true) ? (string)$_GET['tab'] : 'import';
$filterMonth = preg_match('/^\d{4}-\d{2}$/', $_GET['month'] ?? '') ? (string)$_GET['month'] : date('Y-m');
$filterItemId = (int)($_GET['item_id'] ?? 0);
$filterPaymentStatus = trim($_GET['payment_status'] ?? '');

$importPageUrl = static function (array $overrides = []) use ($activeTab, $filterMonth, $filterItemId, $filterPaymentStatus): string {
    $params = [
        'tab' => $overrides['tab'] ?? $activeTab,
        'month' => $overrides['month'] ?? $filterMonth,
    ];
    $itemId = array_key_exists('item_id', $overrides) ? (int)$overrides['item_id'] : $filterItemId;
    $paymentStatus = $overrides['payment_status'] ?? $filterPaymentStatus;
    if ($itemId > 0) {
        $params['item_id'] = $itemId;
    }
    if ($paymentStatus !== '') {
        $params['payment_status'] = $paymentStatus;
    }
    if (!empty($overrides['action'])) {
        $params['action'] = $overrides['action'];
    }
    if (!empty($overrides['id'])) {
        $params['id'] = (int)$overrides['id'];
    }
    return '/erp/modules/admin/inv_import.php?' . http_build_query($params);
};

$isValidDate = static function (?string $value): bool {
    if ($value === null || $value === '') {
        return false;
    }
    $date = DateTime::createFromFormat('Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
};

$generateImportNo = static function (string $importDate) use ($pdo): string {
    $dateKey = date('Ymd', strtotime($importDate));
    $prefix = 'IMP-' . $dateKey . '-';
    $last = (string)fetchScalarSafe(
        $pdo,
        'SELECT import_no FROM inv_imports WHERE import_no LIKE ? ORDER BY id DESC LIMIT 1',
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
    $action = trim($_POST['action'] ?? '');

    if ($action === 'create') {
        $itemId = (int)($_POST['item_id'] ?? 0);
        $importDate = trim($_POST['import_date'] ?? date('Y-m-d'));
        $quantity = (float)($_POST['quantity'] ?? 0);
        $unitPrice = trim($_POST['unit_price'] ?? '') !== '' ? (float)$_POST['unit_price'] : 0;
        $vatPercent = trim($_POST['vat_percent'] ?? '') !== '' ? (float)$_POST['vat_percent'] : $defaultVatPercent;
        $invoiceNo = trim($_POST['invoice_no'] ?? '') ?: null;
        $supplier = trim($_POST['supplier'] ?? '') ?: null;
        $paymentStatus = trim($_POST['payment_status'] ?? 'unpaid');
        $note = trim($_POST['note'] ?? '') ?: null;

        if (!in_array($itemId, $itemIds, true)) {
            $errors[] = 'Vui lòng chọn hàng hoá hợp lệ.';
        }
        if (!$isValidDate($importDate)) {
            $errors[] = 'Ngày nhập không hợp lệ.';
        }
        if ($quantity <= 0) {
            $errors[] = 'Số lượng nhập phải lớn hơn 0.';
        }
        if ($unitPrice < 0) {
            $errors[] = 'Đơn giá không được âm.';
        }
        if ($vatPercent < 0) {
            $errors[] = 'VAT không được âm.';
        }
        if (!isset($paymentStatusMap[$paymentStatus])) {
            $errors[] = 'Tình trạng thanh toán không hợp lệ.';
        }

        if (!$errors) {
            try {
                $stmt = $pdo->prepare("INSERT INTO inv_imports
                    (import_no, item_id, import_date, quantity, unit_price, vat_percent, invoice_no, supplier, payment_status, note, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $generateImportNo($importDate),
                    $itemId,
                    $importDate,
                    $quantity,
                    $unitPrice,
                    $vatPercent,
                    $invoiceNo,
                    $supplier,
                    $paymentStatus,
                    $note,
                    currentUserId(),
                ]);
                clearOldInput();
                setFlash('success', 'Đã lưu phiếu nhập kho.');
                redirect($importPageUrl(['tab' => 'history', 'month' => date('Y-m', strtotime($importDate))]));
            } catch (Throwable $e) {
                $errors[] = 'Không thể lưu phiếu nhập kho.';
            }
        }
    }

    if ($action === 'edit_meta') {
        $id = (int)($_POST['id'] ?? 0);
        $invoiceNo = trim($_POST['invoice_no'] ?? '') ?: null;
        $supplier = trim($_POST['supplier'] ?? '') ?: null;
        $paymentStatus = trim($_POST['payment_status'] ?? 'unpaid');
        $note = trim($_POST['note'] ?? '') ?: null;
        $existingImport = fetchOneSafe($pdo, 'SELECT * FROM inv_imports WHERE id = ? LIMIT 1', [$id]);

        if (!$existingImport) {
            $errors[] = 'Không tìm thấy phiếu nhập.';
        }
        if (!isset($paymentStatusMap[$paymentStatus])) {
            $errors[] = 'Tình trạng thanh toán không hợp lệ.';
        }

        if (!$errors && $existingImport) {
            try {
                $pdo->prepare("UPDATE inv_imports
                    SET invoice_no = ?, supplier = ?, payment_status = ?, note = ?
                    WHERE id = ?")
                    ->execute([$invoiceNo, $supplier, $paymentStatus, $note, $id]);
                clearOldInput();
                setFlash('success', 'Đã cập nhật phiếu nhập.');
                redirect($importPageUrl(['tab' => 'history', 'month' => date('Y-m', strtotime((string)$existingImport['import_date']))]));
            } catch (Throwable $e) {
                $errors[] = 'Không thể cập nhật phiếu nhập.';
            }
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $import = fetchOneSafe($pdo, 'SELECT * FROM inv_imports WHERE id = ? LIMIT 1', [$id]);
        if (!$import) {
            setFlash('danger', 'Không tìm thấy phiếu nhập.');
        } else {
            $currentStock = (float)fetchScalarSafe(
                $pdo,
                'SELECT COALESCE((SELECT SUM(quantity) FROM inv_imports WHERE item_id = ?), 0) - COALESCE((SELECT SUM(quantity) FROM inv_exports WHERE item_id = ?), 0)',
                [(int)$import['item_id'], (int)$import['item_id']],
                0
            );
            if (($currentStock - (float)$import['quantity']) < -$stockTolerance) {
                setFlash('danger', 'Không thể xoá phiếu nhập vì sẽ làm tồn kho âm.');
            } else {
                $pdo->prepare('DELETE FROM inv_imports WHERE id = ?')->execute([$id]);
                setFlash('success', 'Đã xoá phiếu nhập.');
            }
        }
        redirect($importPageUrl(['tab' => 'history']));
    }

    if ($errors) {
        flashOldInput($_POST);
        $oldInputWasFlashed = true;
        $activeTab = 'import';
    }
}

$editImport = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_meta' && (int)($_POST['id'] ?? 0) > 0) {
    $editImport = fetchOneSafe(
        $pdo,
        'SELECT ii.*, it.item_code, it.item_name, it.unit FROM inv_imports ii JOIN inv_items it ON it.id = ii.item_id WHERE ii.id = ? LIMIT 1',
        [(int)$_POST['id']]
    );
}
if (($_GET['action'] ?? '') === 'edit' && (int)($_GET['id'] ?? 0) > 0) {
    $editImport = fetchOneSafe(
        $pdo,
        'SELECT ii.*, it.item_code, it.item_name, it.unit FROM inv_imports ii JOIN inv_items it ON it.id = ii.item_id WHERE ii.id = ? LIMIT 1',
        [(int)$_GET['id']]
    );
    if ($editImport) {
        $activeTab = 'import';
    }
}
if ($editImport && !isset($itemUnits[(int)$editImport['item_id']])) {
    $items[] = [
        'id' => (int)$editImport['item_id'],
        'item_code' => $editImport['item_code'],
        'item_name' => $editImport['item_name'],
        'unit' => $editImport['unit'],
    ];
    $itemUnits[(int)$editImport['item_id']] = $editImport['unit'];
}

$formValues = [
    'id' => $editImport['id'] ?? '',
    'item_id' => isset($editImport['item_id']) ? (string)(int)$editImport['item_id'] : '',
    'import_date' => $editImport['import_date'] ?? date('Y-m-d'),
    'quantity' => isset($editImport['quantity']) ? (string)(float)$editImport['quantity'] : '',
    'unit_price' => isset($editImport['unit_price']) ? (string)(float)$editImport['unit_price'] : '',
    'vat_percent' => isset($editImport['vat_percent']) ? (string)(float)$editImport['vat_percent'] : (string)$defaultVatPercent,
    'invoice_no' => $editImport['invoice_no'] ?? '',
    'supplier' => $editImport['supplier'] ?? '',
    'payment_status' => $editImport['payment_status'] ?? 'unpaid',
    'note' => $editImport['note'] ?? '',
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
    $historyWhere[] = "DATE_FORMAT(ii.import_date, '%Y-%m') = ?";
    $historyParams[] = $filterMonth;
}
if ($filterItemId > 0) {
    $historyWhere[] = 'ii.item_id = ?';
    $historyParams[] = $filterItemId;
}
if ($filterPaymentStatus !== '' && isset($paymentStatusMap[$filterPaymentStatus])) {
    $historyWhere[] = 'ii.payment_status = ?';
    $historyParams[] = $filterPaymentStatus;
}

$imports = fetchAllSafe(
    $pdo,
    "SELECT ii.*, it.item_code, it.item_name, it.unit, u.full_name AS created_by_name
     FROM inv_imports ii
     JOIN inv_items it ON it.id = ii.item_id
     LEFT JOIN users u ON u.id = ii.created_by
     WHERE " . implode(' AND ', $historyWhere) . "
     ORDER BY ii.import_date DESC, ii.id DESC",
    $historyParams
);

include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/sidebar.php';
?>
<div class="main-content">
    <div class="container-fluid py-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
            <div>
                <h4 class="mb-1"><i class="fas fa-arrow-down me-2 text-primary"></i>Nhập kho vật tư</h4>
                <p class="text-muted mb-0">Ghi nhận hàng nhập và quản lý lịch sử chứng từ nhập kho vật tư nội bộ.</p>
            </div>
        </div>

        <?php showFlash(); ?>

        <ul class="nav nav-tabs mb-3">
            <li class="nav-item">
                <a class="nav-link <?= $activeTab === 'import' ? 'active' : '' ?>" href="/erp/modules/admin/inv_import.php?<?= e(http_build_query(['tab' => 'import', 'month' => $filterMonth, 'item_id' => $filterItemId, 'payment_status' => $filterPaymentStatus])) ?>">Nhập kho</a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $activeTab === 'history' ? 'active' : '' ?>" href="/erp/modules/admin/inv_import.php?<?= e(http_build_query(['tab' => 'history', 'month' => $filterMonth, 'item_id' => $filterItemId, 'payment_status' => $filterPaymentStatus])) ?>">Lịch sử nhập</a>
            </li>
        </ul>

        <div class="tab-content">
            <div class="tab-pane fade <?= $activeTab === 'import' ? 'show active' : '' ?>">
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><?= $editImport ? 'Sửa thông tin phiếu nhập' : 'Tạo phiếu nhập kho' ?></h5>
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
                        <?php if (!$items && !$editImport): ?>
                            <div class="alert alert-warning mb-0">Chưa có hàng hoá đang sử dụng. Vui lòng tạo danh mục trước.</div>
                        <?php else: ?>
                            <form method="post">
                                <?= csrfInput() ?>
                                <input type="hidden" name="action" value="<?= $editImport ? 'edit_meta' : 'create' ?>">
                                <input type="hidden" name="id" value="<?= e($formValues['id']) ?>">
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label fw-semibold">Hàng hoá <span class="text-danger">*</span></label>
                                        <select name="item_id" id="item_id" class="form-select" <?= $editImport ? 'disabled' : 'required' ?>>
                                            <option value="">-- Chọn hàng hoá --</option>
                                            <?php foreach ($items as $item): ?>
                                                <option value="<?= (int)$item['id'] ?>" data-unit="<?= e($item['unit']) ?>" <?= (int)$formValues['item_id'] === (int)$item['id'] ? 'selected' : '' ?>>
                                                    <?= e($item['item_code'] . ' - ' . $item['item_name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <?php if ($editImport): ?>
                                            <input type="hidden" name="item_id" value="<?= e($formValues['item_id']) ?>">
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label fw-semibold">Ngày nhập <span class="text-danger">*</span></label>
                                        <input type="date" name="import_date" class="form-control" value="<?= e($formValues['import_date']) ?>" <?= $editImport ? 'readonly' : 'required' ?>>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label fw-semibold">Số lượng <span class="text-danger">*</span></label>
                                        <input type="number" step="0.01" min="0.01" id="qty" name="quantity" class="form-control" value="<?= e($formValues['quantity']) ?>" <?= $editImport ? 'readonly' : 'required' ?>>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label fw-semibold">Đơn vị tính</label>
                                        <input type="text" id="item_unit_display" class="form-control" value="<?= e($itemUnits[(int)$formValues['item_id']] ?? ($editImport['unit'] ?? '')) ?>" readonly>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label fw-semibold">VAT (%)</label>
                                        <input type="number" step="0.01" min="0" id="vat_percent" name="vat_percent" class="form-control" value="<?= e($formValues['vat_percent']) ?>" <?= $editImport ? 'readonly' : '' ?>>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label fw-semibold">Đơn giá</label>
                                        <input type="number" step="0.01" min="0" id="unit_price" name="unit_price" class="form-control" value="<?= e($formValues['unit_price']) ?>" <?= $editImport ? 'readonly' : '' ?>>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label fw-semibold">Thành tiền</label>
                                        <input type="text" id="total_amount_display" class="form-control" readonly>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label fw-semibold">Số hoá đơn</label>
                                        <input type="text" name="invoice_no" class="form-control" value="<?= e($formValues['invoice_no']) ?>">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label fw-semibold">Nhà cung cấp</label>
                                        <input type="text" name="supplier" class="form-control" value="<?= e($formValues['supplier']) ?>">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label fw-semibold">Tình trạng thanh toán</label>
                                        <select name="payment_status" class="form-select">
                                            <?php foreach ($paymentStatusMap as $key => $label): ?>
                                                <option value="<?= e($key) ?>" <?= $formValues['payment_status'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label fw-semibold">Ghi chú</label>
                                        <textarea name="note" rows="3" class="form-control"><?= e($formValues['note']) ?></textarea>
                                    </div>
                                </div>
                                <?php if ($editImport): ?>
                                    <div class="alert alert-info mt-3 mb-0">
                                        Chỉ cho phép sửa số hoá đơn, nhà cung cấp, tình trạng thanh toán và ghi chú để tránh ảnh hưởng tồn kho.
                                    </div>
                                <?php endif; ?>
                                <div class="mt-3 d-flex gap-2">
                                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i><?= $editImport ? 'Cập nhật phiếu nhập' : 'Lưu phiếu nhập' ?></button>
                                    <?php if ($editImport): ?>
                                        <a class="btn btn-outline-secondary" href="<?= e($importPageUrl(['tab' => 'history'])) ?>">Hủy</a>
                                    <?php endif; ?>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="tab-pane fade <?= $activeTab === 'history' ? 'show active' : '' ?>">
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-body py-2">
                        <form method="get" class="row g-2 align-items-center">
                            <input type="hidden" name="tab" value="history">
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
                            <div class="col-md-3">
                                <select name="payment_status" class="form-select form-select-sm">
                                    <option value="">-- Tất cả thanh toán --</option>
                                    <?php foreach ($paymentStatusMap as $key => $label): ?>
                                        <option value="<?= e($key) ?>" <?= $filterPaymentStatus === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-auto">
                                <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-filter me-1"></i>Lọc</button>
                            </div>
                            <div class="col-auto">
                                <a href="/erp/modules/admin/inv_import.php?tab=history" class="btn btn-sm btn-outline-secondary">Đặt lại</a>
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
                                    <th>Ngày nhập</th>
                                    <th>Hàng hoá</th>
                                    <th class="text-end">Số lượng</th>
                                    <th class="text-end">Đơn giá</th>
                                    <th class="text-end">Thành tiền</th>
                                    <th>Thanh toán</th>
                                    <th>Nhà cung cấp</th>
                                    <th width="150">Thao tác</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (!$imports): ?>
                                <tr>
                                    <td colspan="9" class="text-center text-muted py-4">Chưa có phiếu nhập nào.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($imports as $import): ?>
                                    <tr>
                                        <td class="fw-semibold"><?= e($import['import_no']) ?></td>
                                        <td><?= e(formatDate($import['import_date'])) ?></td>
                                        <td>
                                            <div class="fw-semibold"><?= e($import['item_name']) ?></div>
                                            <div class="small text-muted"><?= e($import['item_code']) ?></div>
                                        </td>
                                        <td class="text-end"><?= e(number_format((float)$import['quantity'], 2, ',', '.')) ?> <?= e($import['unit']) ?></td>
                                        <td class="text-end"><?= e(number_format((float)$import['unit_price'], 0, ',', '.')) ?> ₫</td>
                                        <td class="text-end fw-semibold"><?= e(number_format((float)$import['total_amount'], 0, ',', '.')) ?> ₫</td>
                                        <td>
                                            <span class="badge <?= $import['payment_status'] === 'paid' ? 'bg-success' : 'bg-warning text-dark' ?>">
                                                <?= e($paymentStatusMap[$import['payment_status']] ?? $import['payment_status']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div><?= e($import['supplier'] ?: '—') ?></div>
                                            <div class="small text-muted"><?= e($import['invoice_no'] ?: 'Không có HĐ') ?></div>
                                        </td>
                                        <td>
                                            <div class="d-flex gap-2">
                                                <a class="btn btn-sm btn-outline-primary" href="/erp/modules/admin/inv_import.php?<?= e(http_build_query(['tab' => 'import', 'action' => 'edit', 'id' => (int)$import['id'], 'month' => $filterMonth, 'item_id' => $filterItemId, 'payment_status' => $filterPaymentStatus])) ?>">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <form method="post" onsubmit="return confirm('Bạn có chắc muốn xoá phiếu nhập này?');">
                                                    <?= csrfInput() ?>
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="id" value="<?= (int)$import['id'] ?>">
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
    </div>
</div>

<script>
function recalcTotal() {
    const qty = parseFloat(document.getElementById('qty').value) || 0;
    const price = parseFloat(document.getElementById('unit_price').value) || 0;
    const vat = parseFloat(document.getElementById('vat_percent').value) || 0;
    const total = qty * price * (1 + vat / 100);
    document.getElementById('total_amount_display').value = total.toLocaleString('vi-VN') + ' ₫';
}

function updateItemUnit() {
    const select = document.getElementById('item_id');
    if (!select) {
        return;
    }
    const option = select.options[select.selectedIndex];
    document.getElementById('item_unit_display').value = option ? (option.getAttribute('data-unit') || '') : '';
}

['qty', 'unit_price', 'vat_percent'].forEach((id) => {
    const element = document.getElementById(id);
    if (element) {
        element.addEventListener('input', recalcTotal);
    }
});

const itemSelect = document.getElementById('item_id');
if (itemSelect) {
    itemSelect.addEventListener('change', updateItemUnit);
}

updateItemUnit();
recalcTotal();
</script>
<?php
if ($oldInputWasFlashed || isset($_SESSION['_old_input'])) {
    clearOldInput();
}
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/footer.php';
?>
