<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';
requireLogin();
requireRole('director','accountant','warehouse','production','manager');

$pdo  = getDBConnection();
$user = currentUser();

$filterDate = $_GET['date'] ?? date('Y-m-d');
$filterCode = trim($_GET['code'] ?? '');

$where  = ['pr.receipt_date = ?'];
$params = [$filterDate];
if ($filterCode) {
    $where[]  = 'pc.product_code LIKE ?';
    $params[] = "%$filterCode%";
}

$receipts = $pdo->prepare("
    SELECT pr.*, pc.product_code, pc.description AS product_desc, pc.unit,
           wi.import_no, u.full_name AS created_by_name
    FROM production_receipts pr
    JOIN product_codes pc ON pr.product_code_id = pc.id
    LEFT JOIN warehouse_imports wi ON pr.warehouse_import_id = wi.id
    LEFT JOIN users u ON pr.created_by = u.id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY pr.created_at DESC
");
$receipts->execute($params);
$receipts = $receipts->fetchAll(PDO::FETCH_ASSOC);

$availableImports = $pdo->query("
    SELECT wi.id, wi.import_no, wi.import_date,
           pc.product_code, pc.description, pc.unit,
           wi.product_code_id,
           (wi.quantity - wi.quantity_sent) AS available
    FROM warehouse_imports wi
    JOIN product_codes pc ON wi.product_code_id = pc.id
    WHERE (wi.quantity - wi.quantity_sent) > 0
      AND wi.status != 'completed'
    ORDER BY wi.import_date, pc.product_code
")->fetchAll(PDO::FETCH_ASSOC);

$today    = date('Y-m-d');
$userRole = $user['role'] ?? '';
$csrf     = generateCSRF();

include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/sidebar.php';
?>
<div class="main-content">
<?php include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/warehouse_nav.php'; ?>
<div class="container-fluid py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="fas fa-truck-loading me-2 text-primary"></i>Nhận hàng từ kho</h4>
            <p class="text-muted mb-0">SX nhận nguyên liệu / SP từ kho để sản xuất</p>
        </div>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalReceipt">
            <i class="fas fa-plus me-1"></i> Tạo phiếu nhận
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
                <div class="col-md-3">
                    <input type="text" name="code" class="form-control form-control-sm"
                           placeholder="Mã SP..." value="<?= htmlspecialchars($filterCode) ?>">
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

    <!-- Bảng -->
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>Số phiếu nhận</th>
                            <th>Ngày</th>
                            <th>Mã SP</th>
                            <th>Mô tả</th>
                            <th class="text-end">SL nhận</th>
                            <th>Phiếu kho</th>
                            <th>Người nhận</th>
                            <th>Ghi chú</th>
                            <th width="90">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($receipts)): ?>
                        <tr><td colspan="9" class="text-center text-muted py-4">Chưa có phiếu nhận nào</td></tr>
                    <?php else: ?>
                        <?php foreach ($receipts as $r):
                            $isToday    = ($r['receipt_date'] === $today);
                            $isDirector = ($userRole === 'director');
                            $canEdit    = $isToday || $isDirector;
                        ?>
                        <tr>
                            <td class="fw-semibold text-primary"><?= htmlspecialchars($r['receipt_no']) ?></td>
                            <td><?= date('d/m/Y', strtotime($r['receipt_date'])) ?></td>
                            <td><span class="badge bg-primary"><?= htmlspecialchars($r['product_code']) ?></span></td>
                            <td class="small"><?= htmlspecialchars($r['product_desc']) ?></td>
                            <td class="text-end fw-bold"><?= number_format($r['quantity_received']) ?></td>
                            <td class="small text-muted"><?= htmlspecialchars($r['import_no'] ?? '—') ?></td>
                            <td class="small"><?= htmlspecialchars($r['created_by_name'] ?? '—') ?></td>
                            <td class="small text-muted"><?= htmlspecialchars($r['note'] ?? '—') ?></td>
                            <td>
                                <?php if ($canEdit): ?>
                                <button class="btn btn-xs btn-outline-warning btn-edit-receipt"
                                        data-id="<?= $r['id'] ?>"
                                        data-import-id="<?= $r['warehouse_import_id'] ?>"
                                        data-qty="<?= $r['quantity_received'] ?>"
                                        data-note="<?= htmlspecialchars($r['note'] ?? '') ?>"
                                        data-is-today="<?= $isToday ? '1':'0' ?>"
                                        title="Sửa">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="btn btn-xs btn-outline-danger btn-delete-receipt ms-1"
                                        data-id="<?= $r['id'] ?>"
                                        data-name="<?= htmlspecialchars($r['receipt_no']) ?>"
                                        title="Xóa">
                                    <i class="fas fa-trash"></i>
                                </button>
                                <?php else: ?>
                                <span class="text-muted"><i class="fas fa-lock"></i></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer text-muted small">
            Tổng: <strong><?= count($receipts) ?></strong> phiếu nhận
        </div>
    </div>
</div>
</div>

<!-- Modal tạo phiếu nhận -->
<div class="modal fade" id="modalReceipt" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-truck-loading me-2"></i>Tạo phiếu nhận từ kho</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="formReceipt">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Ngày nhận <span class="text-danger">*</span></label>
                        <input type="date" name="receipt_date" class="form-control"
                               value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Chọn phiếu nhập kho <span class="text-danger">*</span></label>
                        <select name="warehouse_import_id" id="selectImport" class="form-select" required>
                            <option value="">-- Chọn phiếu kho còn hàng --</option>
                            <?php foreach ($availableImports as $ai): ?>
                            <option value="<?= $ai['id'] ?>"
                                    data-unit="<?= htmlspecialchars($ai['unit']) ?>"
                                    data-available="<?= $ai['available'] ?>"
                                    data-pcid="<?= $ai['product_code_id'] ?>">
                                [<?= htmlspecialchars($ai['product_code']) ?>]
                                <?= htmlspecialchars($ai['description']) ?>
                                — Còn: <?= number_format($ai['available']) ?> <?= $ai['unit'] ?>
                                (<?= $ai['import_no'] ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <input type="hidden" name="product_code_id" id="rcvProductId">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Số lượng nhận <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="number" name="quantity" id="rcvQty"
                                   class="form-control" placeholder="0" min="1" required>
                            <span class="input-group-text" id="rcvUnit">—</span>
                        </div>
                        <div class="form-text text-info" id="rcvAvailable"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Ghi chú</label>
                        <textarea name="note" class="form-control" rows="2"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Huỷ</button>
                <button type="button" class="btn btn-primary" id="btnSaveReceipt">
                    <i class="fas fa-save me-1"></i>Tạo phiếu
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal sửa phiếu nhận -->
<div class="modal fade" id="modalEditReceipt" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title fw-bold"><i class="fas fa-edit me-2"></i>Sửa phiếu nhận</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="editReceiptWarning" class="alert alert-warning d-none small">
                    <i class="fas fa-exclamation-triangle me-1"></i>
                    Bạn đang sửa bản ghi <strong>không phải hôm nay</strong> (quyền Giám đốc)
                </div>
                <form id="formEditReceipt">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="id"     id="editReceiptId">
                    <input type="hidden" name="action" value="update">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Chọn phiếu nhập kho <span class="text-danger">*</span></label>
                        <select name="warehouse_import_id" id="editSelectImport" class="form-select" required>
                            <option value="">-- Chọn phiếu kho --</option>
                            <?php foreach ($availableImports as $ai): ?>
                            <option value="<?= $ai['id'] ?>"
                                    data-unit="<?= htmlspecialchars($ai['unit']) ?>"
                                    data-available="<?= $ai['available'] ?>"
                                    data-pcid="<?= $ai['product_code_id'] ?>">
                                [<?= htmlspecialchars($ai['product_code']) ?>]
                                <?= htmlspecialchars($ai['description']) ?>
                                — Còn: <?= number_format($ai['available']) ?> <?= $ai['unit'] ?>
                                (<?= $ai['import_no'] ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <input type="hidden" name="product_code_id" id="editRcvProductId">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Số lượng nhận <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="number" name="quantity_received" id="editRcvQty"
                                   class="form-control" min="1" required>
                            <span class="input-group-text" id="editRcvUnit">—</span>
                        </div>
                        <div class="form-text text-info" id="editRcvAvailable"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Ghi chú</label>
                        <textarea name="note" id="editReceiptNote" class="form-control" rows="2"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Huỷ</button>
                <button type="button" class="btn btn-warning fw-bold" id="btnUpdateReceipt">
                    <i class="fas fa-save me-1"></i>Lưu thay đổi
                </button>
            </div>
        </div>
    </div>
</div>

<script>
const USER_ROLE_RECEIPT = '<?= $userRole ?>';
const CSRF_RECEIPT = '<?= $csrf ?>';

// ── Tạo mới ──────────────────────────────────────────────────
document.getElementById('selectImport').addEventListener('change', function() {
    const opt = this.options[this.selectedIndex];
    document.getElementById('rcvProductId').value  = opt.dataset.pcid || '';
    document.getElementById('rcvUnit').textContent = opt.dataset.unit || '—';
    document.getElementById('rcvAvailable').textContent =
        opt.dataset.available ? `Tối đa: ${parseInt(opt.dataset.available).toLocaleString()} ${opt.dataset.unit}` : '';
    document.getElementById('rcvQty').max = opt.dataset.available || '';
});

document.getElementById('btnSaveReceipt').addEventListener('click', () => {
    const form = document.getElementById('formReceipt');
    if (!form.checkValidity()) { form.reportValidity(); return; }
    const btn = document.getElementById('btnSaveReceipt');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Đang lưu...';
    fetch('/erp/api/production/save_receipt.php', { method:'POST', body: new FormData(form) })
    .then(r => r.json())
    .then(res => {
        if (res.ok) {
            bootstrap.Modal.getInstance(document.getElementById('modalReceipt')).hide();
            location.reload();
        } else { alert('Lỗi: ' + res.msg); }
    })
    .finally(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save me-1"></i>Tạo phiếu';
    });
});

// ── Edit select import autofill ───────────────────────────────
document.getElementById('editSelectImport').addEventListener('change', function() {
    const opt = this.options[this.selectedIndex];
    document.getElementById('editRcvProductId').value  = opt.dataset.pcid || '';
    document.getElementById('editRcvUnit').textContent = opt.dataset.unit || '—';
    document.getElementById('editRcvAvailable').textContent =
        opt.dataset.available ? `Tối đa: ${parseInt(opt.dataset.available).toLocaleString()} ${opt.dataset.unit}` : '';
    document.getElementById('editRcvQty').max = opt.dataset.available || '';
});

// ── Mở modal sửa ─────────────────────────────────────────────
document.querySelectorAll('.btn-edit-receipt').forEach(btn => {
    btn.addEventListener('click', function() {
        const d = this.dataset;
        document.getElementById('editReceiptId').value   = d.id;
        document.getElementById('editRcvQty').value      = d.qty;
        document.getElementById('editReceiptNote').value = d.note;

        // Chọn đúng import
        const sel = document.getElementById('editSelectImport');
        for (let opt of sel.options) {
            if (opt.value == d.importId) { opt.selected = true; break; }
        }
        sel.dispatchEvent(new Event('change'));

        document.getElementById('editReceiptWarning').classList.toggle('d-none', d.isToday === '1');
        new bootstrap.Modal(document.getElementById('modalEditReceipt')).show();
    });
});

// ── Lưu sửa ───────────────────────────────────────────────────
document.getElementById('btnUpdateReceipt').addEventListener('click', () => {
    const form = document.getElementById('formEditReceipt');
    if (!form.checkValidity()) { form.reportValidity(); return; }
    const btn = document.getElementById('btnUpdateReceipt');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Đang lưu...';
    fetch('/erp/api/production/update_receipt.php', { method:'POST', body: new FormData(form) })
    .then(r => r.json())
    .then(res => {
        if (res.ok) {
            bootstrap.Modal.getInstance(document.getElementById('modalEditReceipt')).hide();
            location.reload();
        } else { alert('Lỗi: ' + res.msg); }
    })
    .finally(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save me-1"></i>Lưu thay đổi';
    });
});

// ── Xóa ────────────��──────────────────────────────────────────
document.querySelectorAll('.btn-delete-receipt').forEach(btn => {
    btn.addEventListener('click', function() {
        const d = this.dataset;
        if (!confirm(`Xóa phiếu nhận "${d.name}"?\nHành động không thể hoàn tác!`)) return;
        const fd = new FormData();
        fd.append('csrf_token', CSRF_RECEIPT);
        fd.append('id',     d.id);
        fd.append('action', 'delete');
        fetch('/erp/api/production/update_receipt.php', { method:'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.ok) { location.reload(); }
            else { alert('Lỗi: ' + res.msg); }
        });
    });
});
</script>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/footer.php'; ?>