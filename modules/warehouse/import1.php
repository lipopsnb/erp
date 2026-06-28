<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';
requireLogin();
requireRole('director','accountant','warehouse','manager');

$pdo  = getDBConnection();
$user = currentUser();

// ── Filter ────────────────────────────────────────────────────────────
$filterDate   = $_GET['date']   ?? '';
$filterStatus = $_GET['status'] ?? '';
$filterCode   = trim($_GET['code'] ?? '');

$where  = ['1=1'];
$params = [];

if ($filterDate) {
    $where[]  = 'wi.import_date = ?';
    $params[] = $filterDate;
}
if ($filterStatus) {
    $where[]  = 'wi.status = ?';
    $params[] = $filterStatus;
}
if ($filterCode) {
    $where[]  = 'pc.product_code LIKE ?';
    $params[] = "%$filterCode%";
}

$imports = $pdo->prepare("
    SELECT wi.*, pc.product_code, pc.description AS product_desc, pc.unit,
           u.full_name AS created_by_name
    FROM warehouse_imports wi
    JOIN product_codes pc ON wi.product_code_id = pc.id
    LEFT JOIN users u ON wi.created_by = u.id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY wi.created_at DESC
");
$imports->execute($params);
$imports = $imports->fetchAll(PDO::FETCH_ASSOC);

// ── Danh sách SP cho dropdown ─────────────────────────────────────────
$productList = $pdo->query("
    SELECT id, product_code, description, unit
    FROM product_codes WHERE is_active = 1 ORDER BY product_code
")->fetchAll(PDO::FETCH_ASSOC);

$csrf = generateCSRF();
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/sidebar.php';

?>

<div class="main-content">
<?php include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/warehouse_nav.php'; ?>
<div class="container-fluid py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="fas fa-file-import me-2 text-primary"></i>Nhập SP gia công</h4>
            <p class="text-muted mb-0">Quản lý phiếu nhập kho sản phẩm gia công</p>
        </div>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalImport">
            <i class="fas fa-plus me-1"></i> Tạo phiếu nhập
        </button>
    </div>

    <?php showFlash(); ?>

    <!-- Filter -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2">
            <form class="row g-2 align-items-center" method="GET">
                <div class="col-md-3">
                    <input type="date" name="date" class="form-control form-control-sm"
                           value="<?= htmlspecialchars($filterDate) ?>">
                </div>
                <div class="col-md-2">
                    <input type="text" name="code" class="form-control form-control-sm"
                           placeholder="Mã SP..." value="<?= htmlspecialchars($filterCode) ?>">
                </div>
                <div class="col-md-2">
                    <select name="status" class="form-select form-select-sm">
                        <option value="">-- Trạng thái --</option>
                        <option value="pending"   <?= $filterStatus==='pending'   ? 'selected':'' ?>>Chờ chuyển SX</option>
                        <option value="partial"   <?= $filterStatus==='partial'   ? 'selected':'' ?>>Chuyển 1 phần</option>
                        <option value="completed" <?= $filterStatus==='completed' ? 'selected':'' ?>>Hoàn thành</option>
                    </select>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-sm btn-primary">
                        <i class="fas fa-filter me-1"></i>Lọc
                    </button>
                    <a href="?" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Bảng nhập kho -->
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th width="150">Số phiếu</th>
                            <th width="100">Ngày nhập</th>
                            <th width="120">Mã SP</th>
                            <th>Mô tả</th>
                            <th width="80" class="text-end">SL nhập</th>
                            <th width="80" class="text-end">Đã → SX</th>
                            <th width="80" class="text-end">Còn lại</th>
                            <th width="100">Trạng thái</th>
                            <th width="120">Người tạo</th>
                            <th>Ghi chú</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($imports)): ?>
                        <tr><td colspan="10" class="text-center text-muted py-4">Chưa có phiếu nhập nào</td></tr>
                    <?php else: ?>
                        <?php foreach ($imports as $im): ?>
                        <tr>
                            <td class="fw-semibold text-primary"><?= htmlspecialchars($im['import_no']) ?></td>
                            <td><?= date('d/m/Y', strtotime($im['import_date'])) ?></td>
                            <td><span class="badge bg-primary"><?= htmlspecialchars($im['product_code']) ?></span></td>
                            <td class="small"><?= htmlspecialchars($im['product_desc']) ?></td>
                            <td class="text-end fw-bold"><?= number_format($im['quantity']) ?></td>
                            <td class="text-end text-warning"><?= number_format($im['quantity_sent']) ?></td>
                            <td class="text-end fw-bold <?= ($im['quantity'] - $im['quantity_sent']) <= 0 ? 'text-danger' : 'text-success' ?>">
                                <?= number_format($im['quantity'] - $im['quantity_sent']) ?>
                            </td>
                            <td>
                                <?php
                                $badges = [
                                    'pending'   => ['warning text-dark', 'Chờ SX'],
                                    'partial'   => ['info',              'Một phần'],
                                    'completed' => ['success',           'Hoàn thành'],
                                ];
                                $b = $badges[$im['status']] ?? ['secondary','?'];
                                echo "<span class='badge bg-{$b[0]}'>{$b[1]}</span>";
                                ?>
                            </td>
                            <td class="small text-muted"><?= htmlspecialchars($im['created_by_name'] ?? '—') ?></td>
                            <td class="small text-muted"><?= htmlspecialchars($im['note'] ?? '—') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer text-muted small">
            Tổng: <strong><?= count($imports) ?></strong> phiếu nhập
        </div>
    </div>
</div>
</div>

<!-- Modal tạo phiếu nhập -->
<div class="modal fade" id="modalImport" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="fas fa-file-import me-2"></i>Tạo phiếu nhập kho
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="formImport">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Ngày nhập <span class="text-danger">*</span></label>
                        <input type="date" name="import_date" class="form-control"
                               value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Mã sản phẩm <span class="text-danger">*</span></label>
                        <select name="product_code_id" id="importProduct" class="form-select" required>
                            <option value="">-- Chọn mã SP --</option>
                            <?php foreach ($productList as $pl): ?>
                            <option value="<?= $pl['id'] ?>"
                                    data-desc="<?= htmlspecialchars($pl['description']) ?>"
                                    data-unit="<?= htmlspecialchars($pl['unit']) ?>">
                                [<?= htmlspecialchars($pl['product_code']) ?>] <?= htmlspecialchars($pl['description']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <!-- Auto-fill mô tả -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Mô tả hàng hoá</label>
                        <input type="text" id="importDesc" class="form-control bg-light"
                               readonly placeholder="Tự động từ mã SP">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Số lượng <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="number" name="quantity" class="form-control"
                                   placeholder="0" min="1" required>
                            <span class="input-group-text" id="importUnit">—</span>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Ghi chú</label>
                        <textarea name="note" class="form-control" rows="2"
                                  placeholder="Ghi chú thêm..."></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Huỷ</button>
                <button type="button" class="btn btn-primary" id="btnSaveImport">
                    <i class="fas fa-save me-1"></i>Tạo phiếu
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Auto-fill mô tả + đơn vị khi chọn mã SP
document.getElementById('importProduct').addEventListener('change', function() {
    const opt = this.options[this.selectedIndex];
    document.getElementById('importDesc').value    = opt.dataset.desc || '';
    document.getElementById('importUnit').textContent = opt.dataset.unit || '—';
});

// Lưu phiếu nhập
document.getElementById('btnSaveImport').addEventListener('click', () => {
    const form = document.getElementById('formImport');
    if (!form.checkValidity()) { form.reportValidity(); return; }

    const btn = document.getElementById('btnSaveImport');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Đang lưu...';

    fetch('/erp/api/warehouse/save_import.php', {
        method: 'POST',
        body: new FormData(form)
    })
    .then(r => r.json())
    .then(res => {
        if (res.ok) {
            bootstrap.Modal.getInstance(document.getElementById('modalImport')).hide();
            location.reload();
        } else {
            alert('Lỗi: ' + res.msg);
        }
    })
    .finally(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save me-1"></i>Tạo phiếu';
    });
});
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/footer.php'; ?>