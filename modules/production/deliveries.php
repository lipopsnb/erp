<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';
requireLogin();
requireRole('director','accountant','warehouse','production','manager');

$pdo  = getDBConnection();
$user = currentUser();

$filterFrom      = $_GET['from']             ?? date('Y-m-01');
$filterTo        = $_GET['to']               ?? date('Y-m-d');
$filterCust      = trim($_GET['cust']        ?? '');
$filterStatus    = $_GET['status']           ?? '';
$where  = ['d.delivery_date BETWEEN ? AND ?'];
$params = [$filterFrom, $filterTo];
if ($filterCust)   { $where[] = 'c.customer_name LIKE ?'; $params[] = "%$filterCust%"; }
if ($filterStatus) { $where[] = 'd.status = ?';           $params[] = $filterStatus; }

$stmt = $pdo->prepare("
    SELECT d.*,
           c.customer_name, c.customer_code,
           u.full_name    AS created_by_name
    FROM deliveries d
    LEFT JOIN customers c ON d.customer_id = c.id
    LEFT JOIN users u     ON d.created_by  = u.id
    WHERE " . implode(' AND ', $where) . "
    GROUP BY d.id
    ORDER BY d.delivery_date DESC, d.id DESC
");
$stmt->execute($params);
$deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
            <h4 class="mb-1"><i class="fas fa-truck me-2 text-primary"></i>Phiếu giao hàng</h4>
            <p class="text-muted mb-0">Quản lý biên bản giao hàng — cơ sở xuất hóa đơn</p>
        </div>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalDL">
            <i class="fas fa-plus me-1"></i> Tạo phiếu giao
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
                        <option value="invoiced"  <?= $filterStatus === 'invoiced'  ? 'selected' : '' ?>>Đã xuất HĐ</option>
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
                            <th width="160">Số phiếu</th>
                            <th width="110">Ngày giao</th>
                            <th>Khách hàng</th>
                            <th width="130">Trạng thái</th>
                            <th width="200">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($deliveries)): ?>
                        <tr><td colspan="6" class="text-center text-muted py-4">Chưa có phiếu giao hàng nào</td></tr>
                    <?php else: ?>
                        <?php foreach ($deliveries as $i => $dv):
                            $statusCfg = [
                                'draft'     => ['bg-warning text-dark', 'Nháp'],
                                'confirmed' => ['bg-success',           'Đã xác nhận'],
                                'invoiced'  => ['bg-primary',           'Đã xuất HĐ'],
                            ];
                            [$sCls, $sLbl] = $statusCfg[$dv['status']] ?? ['bg-secondary', $dv['status']];
                        ?>
                        <tr>
                            <td class="text-muted small"><?= $i + 1 ?></td>
                            <td class="fw-semibold text-primary"><?= htmlspecialchars($dv['delivery_no']) ?></td>
                            <td><?= date('d/m/Y', strtotime($dv['delivery_date'])) ?></td>
                            <td>
                                <?php if ($dv['customer_code']): ?>
                                <span class="badge bg-secondary me-1"><?= htmlspecialchars($dv['customer_code']) ?></span>
                                <?php endif; ?>
                                <?= htmlspecialchars($dv['customer_name'] ?? '—') ?>
                            </td>
                            <td><span class="badge <?= $sCls ?>"><?= $sLbl ?></span></td>
                            <td>
                                <button class="btn btn-xs btn-outline-primary btn-view-dl"
                                        data-id="<?= $dv['id'] ?>"
                                        title="Xem chi tiết">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <?php if ($dv['status'] === 'draft'): ?>
                                <button class="btn btn-xs btn-outline-success ms-1 btn-confirm-dl"
                                        data-id="<?= $dv['id'] ?>"
                                        data-no="<?= htmlspecialchars($dv['delivery_no']) ?>">
                                    <i class="fas fa-check"></i> Xác nhận
                                </button>
                                <button class="btn btn-xs btn-outline-danger ms-1 btn-delete-dl"
                                        data-id="<?= $dv['id'] ?>"
                                        data-no="<?= htmlspecialchars($dv['delivery_no']) ?>">
                                    <i class="fas fa-trash"></i>
                                </button>
                                <?php endif; ?>
                                <?php if ($dv['status'] === 'confirmed'): ?>
                                <a href="/erp/api/production/print_delivery.php?id=<?= $dv['id'] ?>"
                                   target="_blank"
                                   class="btn btn-xs btn-outline-secondary ms-1"
                                   title="In biên bản giao hàng">
                                    <i class="fas fa-print me-1"></i>In biên bản
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
            Tổng: <strong><?= count($deliveries) ?></strong> phiếu
        </div>
    </div>
</div>
</div>

<!-- Modal Tạo phiếu giao hàng -->
<div class="modal fade" id="modalDL" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-truck me-2"></i>Tạo phiếu giao hàng</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="formDL">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="action"     value="save">
                    <input type="hidden" name="id"         value="">
                    <div class="row g-3 mb-3">
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Ngày giao <span class="text-danger">*</span></label>
                            <input type="date" name="delivery_date" class="form-control"
                                   value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Khách hàng <span class="text-danger">*</span></label>
                            <select name="customer_id" id="dlCustomer" class="form-select" required>
                                <option value="">-- Chọn khách hàng --</option>
                                <?php foreach ($customers as $c): ?>
                                <option value="<?= $c['id'] ?>">
                                    [<?= htmlspecialchars($c['customer_code'] ?? '') ?>] <?= htmlspecialchars($c['customer_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label fw-semibold">Ghi chú</label>
                            <input type="text" name="note" class="form-control" placeholder="Ghi chú phiếu giao...">
                        </div>
                    </div>
                    <div class="mb-2 d-flex justify-content-between align-items-center">
                        <div id="dlStockMsg" class="text-muted small">
                            <i class="fas fa-info-circle me-1"></i>Chọn khách hàng để xem danh sách sản phẩm tồn kho.
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-success" id="btnAddDLRow">
                            <i class="fas fa-plus me-1"></i> Thêm dòng
                        </button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-bordered align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th width="40">#</th>
                                    <th width="130">Mã SP</th>
                                    <th>Tên sản phẩm</th>
                                    <th width="80">ĐVT</th>
                                    <th class="text-end" width="130">Số lượng</th>
                                    <th>Ghi chú</th>
                                    <th width="40"></th>
                                </tr>
                            </thead>
                            <tbody id="dlItems"></tbody>
                        </table>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Huỷ</button>
                <button type="button" class="btn btn-primary" id="btnSaveDL">
                    <i class="fas fa-save me-1"></i> Lưu phiếu giao
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal Xem chi tiết -->
<div class="modal fade" id="modalDLDetail" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title"><i class="fas fa-eye me-2"></i>Chi tiết phiếu giao hàng</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="dlDetailBody">
                <div class="text-center py-3"><i class="fas fa-spinner fa-spin"></i></div>
            </div>
        </div>
    </div>
</div>

<script>
const csrf = '<?= $csrf ?>';
let dlRowIdx = 0;

function escHtml(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function addDLRow(item = {}) {
    dlRowIdx++;
    const n   = dlRowIdx;
    const qty = parseFloat(item.qty_available) || '';
    const tr  = document.createElement('tr');
    tr.innerHTML = `
        <td class="text-muted small">${document.querySelectorAll('#dlItems tr').length + 1}</td>
        <td>
            <input type="hidden" name="items[${n}][product_code_id]" value="${escHtml(String(item.product_code_id || ''))}">
            <span class="badge bg-primary">${escHtml(item.product_code || '—')}</span>
        </td>
        <td class="fw-semibold">${escHtml(item.description || '—')}</td>
        <td class="text-muted small">${escHtml(item.unit || '')}</td>
        <td>
            <input type="number" name="items[${n}][quantity]" class="form-control form-control-sm text-end"
                   value="${qty}" min="0.001" step="0.001" ${qty ? `max="${qty}"` : ''} required>
            ${qty ? `<div class="form-text text-info" style="font-size:10px;">Tối đa: ${parseFloat(qty).toLocaleString('vi-VN')}</div>` : ''}
        </td>
        <td>
            <input type="text" name="items[${n}][note]" class="form-control form-control-sm"
                   value="${escHtml(item.note || '')}" placeholder="...">
        </td>
        <td><button type="button" class="btn btn-xs btn-outline-danger btn-del-dl-row"><i class="fas fa-times"></i></button></td>`;
    tr.querySelector('.btn-del-dl-row').addEventListener('click', () => tr.remove());
    document.getElementById('dlItems').appendChild(tr);
}

// Khi mở modal: chỉ xoá tbody + reset fields, KHÔNG reset toàn form (giữ csrf_token)
document.querySelector('[data-bs-target="#modalDL"]').addEventListener('click', () => {
    document.getElementById('dlItems').innerHTML = '';
    dlRowIdx = 0;
    document.querySelector('#formDL [name="delivery_date"]').value = new Date().toISOString().slice(0,10);
    document.querySelector('#formDL [name="customer_id"]').value = '';
    document.querySelector('#formDL [name="note"]').value = '';
    document.getElementById('dlStockMsg').innerHTML =
        '<i class="fas fa-info-circle me-1"></i>Chọn khách hàng để xem danh sách sản phẩm tồn kho.';
    document.getElementById('dlStockMsg').className = 'text-muted small';
});

// Thêm dòng thủ công
document.getElementById('btnAddDLRow').addEventListener('click', () => addDLRow());

// Khi chọn khách hàng → tự động load SP tồn kho
document.getElementById('dlCustomer').addEventListener('change', function () {
    const custId = this.value;
    const body   = document.getElementById('dlItems');
    const msg    = document.getElementById('dlStockMsg');
    body.innerHTML = '';
    dlRowIdx = 0;
    if (!custId) {
        msg.innerHTML = '<i class="fas fa-info-circle me-1"></i>Chọn khách hàng để xem danh sách sản phẩm tồn kho.';
        msg.className = 'text-muted small';
        return;
    }
    msg.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Đang tải...';
    msg.className = 'text-muted small';
    fetch(`/erp/api/production/get_customer_stock.php?customer_id=${custId}`)
        .then(r => r.json())
        .then(res => {
            if (!res.ok) {
                msg.innerHTML = '<i class="fas fa-times-circle me-1"></i>Lỗi từ máy chủ: ' + escHtml(res.msg || 'Không xác định');
                msg.className = 'text-danger small';
                return;
            }
            if (!res.items || !res.items.length) {
                msg.innerHTML = '<i class="fas fa-exclamation-circle me-1"></i>Không còn sản phẩm nào cần giao cho khách hàng này.';
                msg.className = 'text-warning small';
                return;
            }
            msg.innerHTML = `<i class="fas fa-check-circle me-1"></i>Tìm thấy ${res.items.length} sản phẩm tồn kho.`;
            msg.className = 'text-success small';
            res.items.forEach(item => addDLRow(item));
        })
        .catch(() => {
            msg.innerHTML = '<i class="fas fa-times-circle me-1"></i>Lỗi khi tải dữ liệu.';
            msg.className = 'text-danger small';
        });
});

document.getElementById('btnSaveDL').addEventListener('click', () => {
    const form = document.getElementById('formDL');
    if (!form.checkValidity()) { form.reportValidity(); return; }
    const rows = document.querySelectorAll('#dlItems tr');
    if (!rows.length) { alert('Chưa có sản phẩm nào để giao!'); return; }
    let hasQty = false;
    rows.forEach(r => {
        const qtyEl = r.querySelector('input[type="number"]');
        if (qtyEl && parseFloat(qtyEl.value) > 0) hasQty = true;
    });
    if (!hasQty) { alert('Vui lòng nhập số lượng giao cho ít nhất một sản phẩm!'); return; }
    const fd = new FormData(form);
    fetch('/erp/api/production/save_deliveries.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.ok) location.reload();
            else alert('Lỗi: ' + d.msg);
        });
});

// Xem chi tiết
document.querySelectorAll('.btn-view-dl').forEach(btn => {
    btn.addEventListener('click', () => {
        const id = btn.dataset.id;
        document.getElementById('dlDetailBody').innerHTML = '<div class="text-center py-3"><i class="fas fa-spinner fa-spin"></i></div>';
        new bootstrap.Modal(document.getElementById('modalDLDetail')).show();
        fetch(`/erp/api/invoice/get_delivery_items.php?id=${id}`)
            .then(r => r.json())
            .then(d => {
                if (!d.ok) { document.getElementById('dlDetailBody').innerHTML = '<div class="alert alert-danger">'+d.msg+'</div>'; return; }
                let rows = d.items.map((it, i) => `<tr>
                    <td>${i + 1}</td>
                    <td><span class="badge bg-primary">${escHtml(it.product_code)}</span></td>
                    <td>${escHtml(it.description || '')}</td>
                    <td class="text-center">${escHtml(it.unit || '')}</td>
                    <td class="text-end">${parseFloat(it.quantity).toLocaleString('vi-VN')}</td>
                </tr>`).join('');
                document.getElementById('dlDetailBody').innerHTML = `
                    <table class="table table-bordered table-sm align-middle">
                        <thead class="table-dark"><tr><th>#</th><th>Mã SP</th><th>Mô tả</th><th>ĐVT</th><th class="text-end">SL</th></tr></thead>
                        <tbody>${rows}</tbody>
                    </table>`;
            });
    });
});

// Xác nhận
document.querySelectorAll('.btn-confirm-dl').forEach(btn => {
    btn.addEventListener('click', () => {
        if (!confirm(`Xác nhận giao hàng cho phiếu ${btn.dataset.no}?`)) return;
        const fd = new FormData();
        fd.append('csrf_token', csrf);
        fd.append('action', 'confirm');
        fd.append('id', btn.dataset.id);
        fetch('/erp/api/production/save_deliveries.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (d.ok) location.reload();
                else alert('Lỗi: ' + d.msg);
            });
    });
});

// Xoá — dùng csrf từ biến JS, KHÔNG lấy từ form (tránh bị reset)
document.querySelectorAll('.btn-delete-dl').forEach(btn => {
    btn.addEventListener('click', () => {
        if (!confirm(`Xoá phiếu ${btn.dataset.no}?`)) return;
        const fd = new FormData();
        fd.append('csrf_token', csrf);
        fd.append('action', 'delete');
        fd.append('id', btn.dataset.id);
        fetch('/erp/api/production/save_deliveries.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (d.ok) location.reload();
                else alert('Lỗi: ' + d.msg);
            });
    });
});
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/footer.php'; ?>
