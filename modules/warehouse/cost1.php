<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';
requireLogin();
requireRole('director','accountant','warehouse','manager');

$pdo  = getDBConnection();
$user = currentUser();

// ── Filter ────────────────────────────────────────────────────────────
$filterFrom = $_GET['from']      ?? date('Y-m-01');
$filterTo   = $_GET['to']        ?? date('Y-m-d');
$filterType = $_GET['cost_type'] ?? '';

$where  = ['entry_date BETWEEN ? AND ?'];
$params = [$filterFrom, $filterTo];

if ($filterType) {
    $where[]  = 'cost_type = ?';
    $params[] = $filterType;
}

$entries = $pdo->prepare("
    SELECT ce.*, u.full_name AS created_by_name
    FROM cost_entries ce
    LEFT JOIN users u ON ce.created_by = u.id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY ce.entry_date DESC, ce.id DESC
");
$entries->execute($params);
$entries = $entries->fetchAll(PDO::FETCH_ASSOC);

$totalAmount = array_sum(array_column($entries, 'total_amount'));

$costTypes = [
    'material'  => ['label' => 'Nguyên liệu',      'color' => 'primary'],
    'supplies'  => ['label' => 'Vật tư',            'color' => 'info'],
    'machinery' => ['label' => 'Máy móc',           'color' => 'warning'],
    'transport' => ['label' => 'Vận chuyển',        'color' => 'success'],
    'other'     => ['label' => 'Khác',              'color' => 'secondary'],
];

$csrf = generateCSRF();
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/sidebar.php';

?>

<div class="main-content">
<?php include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/warehouse_nav.php'; ?>
<div class="container-fluid py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="fas fa-receipt me-2 text-primary"></i>Chi phí mua vào</h4>
            <p class="text-muted mb-0">Nguyên liệu, vật tư, máy móc, vận chuyển...</p>
        </div>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalCost">
            <i class="fas fa-plus me-1"></i> Thêm chi phí
        </button>
    </div>

    <?php showFlash(); ?>

    <!-- Filter -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2">
            <form class="row g-2 align-items-center" method="GET">
                <div class="col-auto">
                    <input type="date" name="from" class="form-control form-control-sm"
                           value="<?= $filterFrom ?>">
                </div>
                <div class="col-auto"><span class="text-muted">→</span></div>
                <div class="col-auto">
                    <input type="date" name="to" class="form-control form-control-sm"
                           value="<?= $filterTo ?>">
                </div>
                <div class="col-md-2">
                    <select name="cost_type" class="form-select form-select-sm">
                        <option value="">-- Loại chi phí --</option>
                        <?php foreach ($costTypes as $k => $v): ?>
                        <option value="<?= $k ?>" <?= $filterType===$k ? 'selected':'' ?>>
                            <?= $v['label'] ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-sm btn-primary">
                        <i class="fas fa-filter me-1"></i>Lọc
                    </button>
                    <a href="?" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
                </div>
                <div class="col-auto ms-auto">
                    <span class="badge bg-danger fs-6">
                        Tổng: <?= number_format($totalAmount) ?> đ
                    </span>
                </div>
            </form>
        </div>
    </div>

    <!-- Bảng chi phí -->
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th width="100">Ngày</th>
                            <th width="120">Loại</th>
                            <th>Mô tả</th>
                            <th width="150">Nhà cung cấp</th>
                            <th width="80" class="text-end">SL</th>
                            <th width="80">ĐVT</th>
                            <th width="120" class="text-end">Đơn giá</th>
                            <th width="130" class="text-end">Thành tiền</th>
                            <th width="120">Số HĐ</th>
                            <th width="120">Người nhập</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($entries)): ?>
                        <tr><td colspan="10" class="text-center text-muted py-4">Chưa có chi phí nào</td></tr>
                    <?php else: ?>
                        <?php foreach ($entries as $e): ?>
                        <tr>
                            <td><?= date('d/m/Y', strtotime($e['entry_date'])) ?></td>
                            <td>
                                <?php $ct = $costTypes[$e['cost_type']] ?? ['label'=>$e['cost_type'],'color'=>'secondary']; ?>
                                <span class="badge bg-<?= $ct['color'] ?>"><?= $ct['label'] ?></span>
                            </td>
                            <td class="small"><?= htmlspecialchars($e['description'] ?? '—') ?></td>
                            <td class="small"><?= htmlspecialchars($e['supplier_name'] ?? '—') ?></td>
                            <td class="text-end"><?= $e['quantity'] > 0 ? number_format($e['quantity'],2) : '—' ?></td>
                            <td><?= htmlspecialchars($e['unit'] ?? '—') ?></td>
                            <td class="text-end"><?= $e['unit_price'] > 0 ? number_format($e['unit_price']).' đ' : '—' ?></td>
                            <td class="text-end fw-bold text-danger"><?= number_format($e['total_amount']) ?> đ</td>
                            <td class="small text-muted"><?= htmlspecialchars($e['invoice_no'] ?? '—') ?></td>
                            <td class="small text-muted"><?= htmlspecialchars($e['created_by_name'] ?? '—') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                    <?php if (!empty($entries)): ?>
                    <tfoot class="table-light">
                        <tr>
                            <td colspan="7" class="text-end fw-bold">Tổng cộng:</td>
                            <td class="text-end fw-bold text-danger fs-6"><?= number_format($totalAmount) ?> đ</td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>
</div>
</div>

<!-- Modal thêm chi phí -->
<div class="modal fade" id="modalCost" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="fas fa-receipt me-2"></i>Thêm chi phí mua vào
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="formCost">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Ngày <span class="text-danger">*</span></label>
                            <input type="date" name="entry_date" class="form-control"
                                   value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Loại chi phí <span class="text-danger">*</span></label>
                            <select name="cost_type" class="form-select" required>
                                <?php foreach ($costTypes as $k => $v): ?>
                                <option value="<?= $k ?>"><?= $v['label'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Nhà cung cấp</label>
                            <input type="text" name="supplier_name" class="form-control"
                                   placeholder="Tên NCC...">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Mô tả <span class="text-danger">*</span></label>
                            <input type="text" name="description" class="form-control"
                                   placeholder="Mô tả chi phí..." required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Số lượng</label>
                            <input type="number" name="quantity" id="costQty"
                                   class="form-control" placeholder="0" min="0" step="0.01" value="0">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Đơn vị</label>
                            <input type="text" name="unit" class="form-control" placeholder="kg, cái, m...">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Đơn giá</label>
                            <input type="number" name="unit_price" id="costPrice"
                                   class="form-control" placeholder="0" min="0" value="0">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Thành tiền <span class="text-danger">*</span></label>
                            <input type="number" name="total_amount" id="costTotal"
                                   class="form-control fw-bold" placeholder="0" min="0" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Số hoá đơn</label>
                            <input type="text" name="invoice_no" class="form-control" placeholder="Số HĐ/chứng từ">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Ghi chú</label>
                            <input type="text" name="note" class="form-control" placeholder="Ghi chú...">
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Huỷ</button>
                <button type="button" class="btn btn-primary" id="btnSaveCost">
                    <i class="fas fa-save me-1"></i>Lưu
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Tự tính thành tiền
function calcTotal() {
    const qty   = parseFloat(document.getElementById('costQty').value)   || 0;
    const price = parseFloat(document.getElementById('costPrice').value) || 0;
    if (qty > 0 && price > 0) {
        document.getElementById('costTotal').value = Math.round(qty * price);
    }
}
document.getElementById('costQty').addEventListener('input', calcTotal);
document.getElementById('costPrice').addEventListener('input', calcTotal);

document.getElementById('btnSaveCost').addEventListener('click', () => {
    const form = document.getElementById('formCost');
    if (!form.checkValidity()) { form.reportValidity(); return; }

    const btn = document.getElementById('btnSaveCost');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Đang lưu...';

    fetch('/erp/api/warehouse/save_cost.php', {
        method: 'POST',
        body: new FormData(form)
    })
    .then(r => r.json())
    .then(res => {
        if (res.ok) {
            bootstrap.Modal.getInstance(document.getElementById('modalCost')).hide();
            location.reload();
        } else {
            alert('Lỗi: ' + res.msg);
        }
    })
    .finally(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save me-1"></i>Lưu';
    });
});
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/footer.php'; ?>