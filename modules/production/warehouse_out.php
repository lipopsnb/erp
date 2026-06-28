<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';
requireLogin();
requireRole('director','accountant','warehouse','production','manager');

$pdo  = getDBConnection();
$user = currentUser();

$filterFrom   = $_GET['from']   ?? date('Y-m-01');
$filterTo     = $_GET['to']     ?? date('Y-m-d');
$filterCust   = trim($_GET['cust'] ?? '');
$filterStatus = $_GET['status']    ?? '';

$where  = ['wo.export_date BETWEEN ? AND ?'];
$params = [$filterFrom, $filterTo];
if ($filterCust)   { $where[] = 'c.customer_name LIKE ?'; $params[] = "%$filterCust%"; }
if ($filterStatus) { $where[] = 'wo.status = ?';          $params[] = $filterStatus; }

$stmt = $pdo->prepare("
    SELECT wo.*,
           c.customer_name, c.customer_code,
           u.full_name AS created_by_name,
           COUNT(woi.id)     AS item_count,
           SUM(woi.quantity) AS total_qty
    FROM warehouse_out wo
    LEFT JOIN customers c           ON wo.customer_id = c.id
    LEFT JOIN users u               ON wo.created_by  = u.id
    LEFT JOIN warehouse_out_items woi ON woi.warehouse_out_id = wo.id
    WHERE " . implode(' AND ', $where) . "
    GROUP BY wo.id
    ORDER BY wo.export_date DESC, wo.id DESC
");
$stmt->execute($params);
$exports = $stmt->fetchAll(PDO::FETCH_ASSOC);

$customers = $pdo->query("
    SELECT id, customer_code, customer_name FROM customers WHERE is_active = 1 ORDER BY customer_name
")->fetchAll(PDO::FETCH_ASSOC);

$csrf = generateCSRF();
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/sidebar.php';
?>
<div class="main-content">
<div class="container-fluid py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="fas fa-file-export me-2 text-primary"></i>Phiếu xuất kho thành phẩm</h4>
            <p class="text-muted mb-0">Xuất hàng từ kho thành phẩm chuẩn bị giao cho khách</p>
        </div>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalWO">
            <i class="fas fa-plus me-1"></i> Tạo phiếu xuất
        </button>
    </div>

    <?php showFlash(); ?>

    <!-- Filter -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2">
            <form class="row g-2 align-items-center" method="GET">
                <div class="col-auto">
                    <input type="date" name="from" class="form-control form-control-sm" value="<?= $filterFrom ?>">
                </div>
                <div class="col-auto"><span class="text-muted">→</span></div>
                <div class="col-auto">
                    <input type="date" name="to" class="form-control form-control-sm" value="<?= $filterTo ?>">
                </div>
                <div class="col-md-2">
                    <input type="text" name="cust" class="form-control form-control-sm"
                           placeholder="Khách hàng..." value="<?= htmlspecialchars($filterCust) ?>">
                </div>
                <div class="col-md-2">
                    <select name="status" class="form-select form-select-sm">
                        <option value="">-- Trạng thái --</option>
                        <option value="draft"     <?= $filterStatus === 'draft'     ? 'selected' : '' ?>>Nháp</option>
                        <option value="confirmed" <?= $filterStatus === 'confirmed' ? 'selected' : '' ?>>Đã xác nhận</option>
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
                            <th width="50">#</th>
                            <th width="150">Số phiếu</th>
                            <th width="110">Ngày xuất</th>
                            <th>Khách hàng</th>
                            <th class="text-center" width="80">Số mã SP</th>
                            <th class="text-end" width="120">Tổng SL</th>
                            <th width="130">Trạng thái</th>
                            <th>Ghi chú</th>
                            <th width="160">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($exports)): ?>
                        <tr><td colspan="9" class="text-center text-muted py-4">Chưa có phiếu xuất nào</td></tr>
                    <?php else: ?>
                        <?php foreach ($exports as $i => $ex): ?>
                        <tr>
                            <td class="text-muted small"><?= $i + 1 ?></td>
                            <td class="fw-semibold text-primary"><?= htmlspecialchars($ex['export_no']) ?></td>
                            <td><?= date('d/m/Y', strtotime($ex['export_date'])) ?></td>
                            <td>
                                <?php if ($ex['customer_code']): ?>
                                <span class="badge bg-secondary me-1"><?= htmlspecialchars($ex['customer_code']) ?></span>
                                <?php endif; ?>
                                <?= htmlspecialchars($ex['customer_name'] ?? '—') ?>
                            </td>
                            <td class="text-center"><?= $ex['item_count'] ?></td>
                            <td class="text-end fw-bold"><?= number_format($ex['total_qty'] ?? 0, 0) ?></td>
                            <td>
                                <?= $ex['status'] === 'confirmed'
                                    ? '<span class="badge bg-success">Đã xác nhận</span>'
                                    : '<span class="badge bg-warning text-dark">Nháp</span>' ?>
                            </td>
                            <td class="text-muted small"><?= htmlspecialchars($ex['note'] ?? '—') ?></td>
                            <td>
                                <?php if ($ex['status'] === 'draft'): ?>
                                <button class="btn btn-xs btn-outline-success btn-confirm-wo ms-1"
                                        data-id="<?= $ex['id'] ?>"
                                        data-no="<?= htmlspecialchars($ex['export_no']) ?>"
                                        title="Xác nhận xuất kho">
                                    <i class="fas fa-check"></i>
                                </button>
                                <button class="btn btn-xs btn-outline-danger ms-1 btn-delete-wo"
                                        data-id="<?= $ex['id'] ?>"
                                        data-no="<?= htmlspecialchars($ex['export_no']) ?>"
                                        title="Xoá">
                                    <i class="fas fa-trash"></i>
                                </button>
                                <?php endif; ?>
                                <?php if ($ex['status'] === 'confirmed'): ?>
                                <a href="/erp/modules/production/deliveries.php?warehouse_out_id=<?= $ex['id'] ?>"
                                   class="btn btn-xs btn-outline-primary" title="Tạo phiếu giao hàng">
                                    <i class="fas fa-truck"></i> Giao hàng
                                </a>
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
            Tổng: <strong><?= count($exports) ?></strong> phiếu
        </div>
    </div>
</div>
</div>

<!-- Modal tạo phiếu xuất kho -->
<div class="modal fade" id="modalWO" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="fas fa-file-export me-2"></i>Tạo phiếu xuất kho
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="formWO">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="action"     value="save">
                    <input type="hidden" name="id"         value="">
                    <div class="row g-3 mb-3">
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Ngày xuất <span class="text-danger">*</span></label>
                            <input type="date" name="export_date" class="form-control"
                                   value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label fw-semibold">Khách hàng <span class="text-danger">*</span></label>
                            <select name="customer_id" id="woCustomer" class="form-select" required>
                                <option value="">-- Chọn khách hàng --</option>
                                <?php foreach ($customers as $c): ?>
                                <option value="<?= $c['id'] ?>">
                                    [<?= htmlspecialchars($c['customer_code'] ?? '') ?>] <?= htmlspecialchars($c['customer_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Ghi chú</label>
                            <input type="text" name="note" class="form-control" placeholder="Ghi chú...">
                        </div>
                    </div>
                    <div class="mb-2">
                        <button type="button" class="btn btn-sm btn-outline-info" id="btnLoadItems">
                            <i class="fas fa-search me-1"></i> Load hàng chờ giao của khách
                        </button>
                    </div>
                    <div id="woItemsArea" class="d-none">
                        <div class="table-responsive">
                            <table class="table table-bordered align-middle" id="tblWOItems">
                                <thead class="table-light">
                                    <tr>
                                        <th width="40"><input type="checkbox" id="checkAll"></th>
                                        <th>Mã SP</th>
                                        <th>Mô tả</th>
                                        <th class="text-end">SL trong kho</th>
                                        <th class="text-end" width="130">SL xuất</th>
                                        <th>Ghi chú</th>
                                    </tr>
                                </thead>
                                <tbody id="woItemsBody"></tbody>
                            </table>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Huỷ</button>
                <button type="button" class="btn btn-primary" id="btnSaveWO">
                    <i class="fas fa-save me-1"></i> Lưu phiếu
                </button>
            </div>
        </div>
    </div>
</div>

<script>
const csrf = '<?= $csrf ?>';
let loadedItems = [];

document.getElementById('btnLoadItems').addEventListener('click', () => {
    const custId = document.getElementById('woCustomer').value;
    if (!custId) { alert('Vui lòng chọn khách hàng trước'); return; }
    fetch(`/erp/api/warehouse/get_waiting_items.php?customer_id=${custId}`)
        .then(r => r.json())
        .then(d => {
            if (!d.ok) { alert(d.msg); return; }
            loadedItems = d.items;
            const tbody = document.getElementById('woItemsBody');
            tbody.innerHTML = '';
            if (!d.items.length) {
                tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-3">Không có hàng chờ giao</td></tr>';
            } else {
                d.items.forEach((it, i) => {
                    tbody.innerHTML += `<tr>
                        <td><input type="checkbox" class="item-check" data-idx="${i}" checked></td>
                        <td><span class="badge bg-primary">${it.product_code}</span>
                            <input type="hidden" name="items[${i}][warehouse_item_id]" value="${it.id}">
                            <input type="hidden" name="items[${i}][product_code_id]"   value="${it.product_code_id}">
                        </td>
                        <td class="small">${it.description || ''}</td>
                        <td class="text-end">${parseFloat(it.quantity || 0).toLocaleString()}</td>
                        <td><input type="number" name="items[${i}][quantity]" class="form-control form-control-sm text-end"
                                   value="${parseFloat(it.quantity || 0)}" min="0.001" max="${parseFloat(it.quantity || 0)}" step="0.001"></td>
                        <td><input type="text" name="items[${i}][note]" class="form-control form-control-sm" placeholder="..."></td>
                    </tr>`;
                });
            }
            document.getElementById('woItemsArea').classList.remove('d-none');
        });
});

// Check all
document.getElementById('checkAll').addEventListener('change', function() {
    document.querySelectorAll('.item-check').forEach(cb => cb.checked = this.checked);
});

document.getElementById('btnSaveWO').addEventListener('click', () => {
    const form = document.getElementById('formWO');
    if (!form.checkValidity()) { form.reportValidity(); return; }
    const fd = new FormData(form);
    // Remove unchecked items
    const checked = new Set([...document.querySelectorAll('.item-check:checked')].map(cb => cb.dataset.idx));
    // Re-build items from checked only
    const finalFd = new FormData();
    for (const [k, v] of fd.entries()) {
        if (!k.startsWith('items[')) { finalFd.append(k, v); continue; }
        const m = k.match(/^items\[(\d+)\]/);
        if (m && checked.has(m[1])) finalFd.append(k, v);
    }
    fetch('/erp/api/production/save_warehouse_out.php', { method: 'POST', body: finalFd })
        .then(r => r.json())
        .then(d => {
            if (d.ok) location.reload();
            else alert('Lỗi: ' + d.msg);
        });
});

// Xác nhận
document.querySelectorAll('.btn-confirm-wo').forEach(btn => {
    btn.addEventListener('click', () => {
        if (!confirm(`Xác nhận xuất kho phiếu ${btn.dataset.no}?\nSẽ cập nhật trạng thái hàng → "Đã giao".`)) return;
        const fd = new FormData();
        fd.append('csrf_token', csrf);
        fd.append('action', 'confirm');
        fd.append('id', btn.dataset.id);
        fetch('/erp/api/production/save_warehouse_out.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (d.ok) location.reload();
                else alert('Lỗi: ' + d.msg);
            });
    });
});

// Xoá
document.querySelectorAll('.btn-delete-wo').forEach(btn => {
    btn.addEventListener('click', () => {
        if (!confirm(`Xoá phiếu ${btn.dataset.no}?`)) return;
        const fd = new FormData();
        fd.append('csrf_token', csrf);
        fd.append('action', 'delete');
        fd.append('id', btn.dataset.id);
        fetch('/erp/api/production/save_warehouse_out.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (d.ok) location.reload();
                else alert('Lỗi: ' + d.msg);
            });
    });
});
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/footer.php'; ?>
