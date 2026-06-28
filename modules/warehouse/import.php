<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';
requireLogin();
requireRole('director','accountant','warehouse','manager');

$pdo  = getDBConnection();
$user = currentUser();

$filterDate   = $_GET['date']   ?? '';
$filterStatus = $_GET['status'] ?? '';
$filterCode   = trim($_GET['code'] ?? '');

$where  = ['1=1'];
$params = [];

if ($filterDate)   { $where[] = 'wi.import_date = ?';        $params[] = $filterDate; }
if ($filterStatus) { $where[] = 'wi.status = ?';             $params[] = $filterStatus; }
if ($filterCode)   { $where[] = 'pc.product_code LIKE ?';    $params[] = "%$filterCode%"; }

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

$productList = $pdo->query("
    SELECT id, product_code, description, unit
    FROM product_codes WHERE is_active = 1 ORDER BY product_code
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
                        <option value="pending"   <?= $filterStatus==='pending'  ?'selected':'' ?>>Chờ chuyển SX</option>
                        <option value="partial"   <?= $filterStatus==='partial'  ?'selected':'' ?>>Chuyển 1 phần</option>
                        <option value="completed" <?= $filterStatus==='completed'?'selected':'' ?>>Hoàn thành</option>
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

    <!-- Bảng -->
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>Số phiếu</th>
                            <th>Ngày nhập</th>
                            <th>Mã SP</th>
                            <th>Mô tả</th>
                            <th class="text-end">SL nhập</th>
                            <th class="text-end">Đã → SX</th>
                            <th class="text-end">Còn lại</th>
                            <th>Trạng thái</th>
                            <th>Người tạo</th>
                            <th>Ghi chú</th>
                            <th width="100">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($imports)): ?>
                        <tr><td colspan="11" class="text-center text-muted py-4">Chưa có phiếu nhập nào</td></tr>
                    <?php else: ?>
                        <?php foreach ($imports as $im):
                            $isToday    = ($im['import_date'] === $today);
                            $isDirector = ($userRole === 'director');
                            $canEdit    = $isToday || $isDirector;
                        ?>
                        <tr>
                            <td class="fw-semibold text-primary"><?= htmlspecialchars($im['import_no']) ?></td>
                            <td><?= date('d/m/Y', strtotime($im['import_date'])) ?></td>
                            <td><span class="badge bg-primary"><?= htmlspecialchars($im['product_code']) ?></span></td>
                            <td class="small"><?= htmlspecialchars($im['product_desc']) ?></td>
                            <td class="text-end fw-bold"><?= number_format($im['quantity']) ?></td>
                            <td class="text-end text-warning"><?= number_format($im['quantity_sent']) ?></td>
                            <td class="text-end fw-bold <?= ($im['quantity']-$im['quantity_sent'])<=0?'text-danger':'text-success' ?>">
                                <?= number_format($im['quantity'] - $im['quantity_sent']) ?>
                            </td>
                            <td>
                                <?php
                                $badges = [
                                    'pending'   => ['warning text-dark','Chờ SX'],
                                    'partial'   => ['info','Một phần'],
                                    'completed' => ['success','Hoàn thành'],
                                ];
                                $b = $badges[$im['status']] ?? ['secondary','?'];
                                echo "<span class='badge bg-{$b[0]}'>{$b[1]}</span>";
                                ?>
                            </td>
                            <td class="small text-muted"><?= htmlspecialchars($im['created_by_name'] ?? '—') ?></td>
                            <td class="small text-muted"><?= htmlspecialchars($im['note'] ?? '—') ?></td>
                            <td>
                                <?php if ($canEdit): ?>
                                    <button class="btn btn-xs btn-outline-warning btn-edit-import"
                                            title="Sửa"
                                            data-id="<?= $im['id'] ?>"
                                            data-import-date="<?= $im['import_date'] ?>"
                                            data-product-code-id="<?= $im['product_code_id'] ?>"
                                            data-quantity="<?= $im['quantity'] ?>"
                                            data-note="<?= htmlspecialchars($im['note'] ?? '') ?>"
                                            data-is-today="<?= $isToday ? '1' : '0' ?>">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="btn btn-xs btn-outline-danger btn-delete-import ms-1"
                                            title="Xóa"
                                            data-id="<?= $im['id'] ?>"
                                            data-name="<?= htmlspecialchars($im['import_no']) ?>"
                                            data-is-today="<?= $isToday ? '1' : '0' ?>">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                <?php else: ?>
                                    <span class="text-muted small"><i class="fas fa-lock"></i></span>
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
            Tổng: <strong><?= count($imports) ?></strong> phiếu nhập
        </div>
    </div>
</div>
</div>

<!-- Modal tạo phiếu nhập (giữ nguyên) -->
<div class="modal fade" id="modalImport" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-file-import me-2"></i>Tạo phiếu nhập kho</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="formImport">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Ngày nhập <span class="text-danger">*</span></label>
                        <input type="date" name="import_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
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
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Mô tả hàng hoá</label>
                        <input type="text" id="importDesc" class="form-control bg-light" readonly placeholder="Tự động từ mã SP">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Số lượng <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="number" name="quantity" class="form-control" placeholder="0" min="1" required>
                            <span class="input-group-text" id="importUnit">—</span>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Ghi chú</label>
                        <textarea name="note" class="form-control" rows="2"></textarea>
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

<!-- Modal Sửa phiếu nhập -->
<div class="modal fade" id="modalEditImport" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title fw-bold">
                    <i class="fas fa-edit me-2"></i>Sửa phiếu nhập
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="editLockWarning" class="alert alert-warning d-none small">
                    <i class="fas fa-exclamation-triangle me-1"></i>
                    Bạn đang sửa bản ghi <strong>không phải hôm nay</strong> (quyền Giám đốc)
                </div>
                <form id="formEditImport">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="id"     id="editImportId">
                    <input type="hidden" name="action" value="update">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Ngày nhập <span class="text-danger">*</span></label>
                        <input type="date" name="import_date" id="editImportDate" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Mã sản phẩm <span class="text-danger">*</span></label>
                        <select name="product_code_id" id="editImportProduct" class="form-select" required>
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
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Số lượng <span class="text-danger">*</span></label>
                        <input type="number" name="quantity" id="editImportQty"
                               class="form-control" min="1" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Ghi chú</label>
                        <textarea name="note" id="editImportNote" class="form-control" rows="2"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Huỷ</button>
                <button type="button" class="btn btn-warning fw-bold" id="btnUpdateImport">
                    <i class="fas fa-save me-1"></i>Lưu thay đổi
                </button>
            </div>
        </div>
    </div>
</div>

<script src="/erp/assets/js/edit_delete_helper.js"></script>
<script>
const USER_ROLE = '<?= $userRole ?>';

// ── Auto-fill khi tạo mới ─────────────────────────────────────
document.getElementById('importProduct').addEventListener('change', function() {
    const opt = this.options[this.selectedIndex];
    document.getElementById('importDesc').value       = opt.dataset.desc || '';
    document.getElementById('importUnit').textContent = opt.dataset.unit || '—';
});

// ── Tạo mới ───────────────────────────────────────────────────
document.getElementById('btnSaveImport').addEventListener('click', () => {
    const form = document.getElementById('formImport');
    if (!form.checkValidity()) { form.reportValidity(); return; }
    const btn = document.getElementById('btnSaveImport');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Đang lưu...';
    fetch('/erp/api/warehouse/save_import.php', { method:'POST', body: new FormData(form) })
    .then(r => r.json())
    .then(res => {
        if (res.ok) {
            bootstrap.Modal.getInstance(document.getElementById('modalImport')).hide();
            location.reload();
        } else { alert('Lỗi: ' + res.msg); }
    })
    .finally(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save me-1"></i>Tạo phiếu';
    });
});

// ── Mở modal sửa ─────────────────────────────────────────────
document.querySelectorAll('.btn-edit-import').forEach(btn => {
    btn.addEventListener('click', function() {
        const d = this.dataset;
        document.getElementById('editImportId').value   = d.id;
        document.getElementById('editImportDate').value = d.importDate;
        document.getElementById('editImportQty').value  = d.quantity;
        document.getElementById('editImportNote').value = d.note;

        // Chọn đúng option product
        const sel = document.getElementById('editImportProduct');
        for (let opt of sel.options) {
            if (opt.value == d.productCodeId) { opt.selected = true; break; }
        }

        // Hiện warning nếu không phải hôm nay
        const warn = document.getElementById('editLockWarning');
        warn.classList.toggle('d-none', d.isToday === '1');

        new bootstrap.Modal(document.getElementById('modalEditImport')).show();
    });
});

// ── Lưu sửa ───────────────────────────────────────────────────
document.getElementById('btnUpdateImport').addEventListener('click', () => {
    const form = document.getElementById('formEditImport');
    if (!form.checkValidity()) { form.reportValidity(); return; }
    const btn = document.getElementById('btnUpdateImport');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Đang lưu...';
    callEditAPI('/erp/api/warehouse/update_import.php', new FormData(form), () => {
        bootstrap.Modal.getInstance(document.getElementById('modalEditImport')).hide();
        location.reload();
    }).finally(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save me-1"></i>Lưu thay đổi';
    });
});

// ── Xóa ───────────────────────────────────────────────────────
document.querySelectorAll('.btn-delete-import').forEach(btn => {
    btn.addEventListener('click', function() {
        const d = this.dataset;
        confirmDelete(d.name, () => {
            const fd = new FormData();
            fd.append('csrf_token', '<?= $csrf ?>');
            fd.append('id',     d.id);
            fd.append('action', 'delete');
            callEditAPI('/erp/api/warehouse/update_import.php', fd, () => location.reload());
        });
    });
});
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/footer.php'; ?>