<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';
requireLogin();
requireRole('director','accountant','warehouse','production','manager');

$pdo  = getDBConnection();
$user = currentUser();

$filterFrom   = $_GET['from']     ?? date('Y-m-01');
$filterTo     = $_GET['to']       ?? date('Y-m-d');
$filterCust   = trim($_GET['cust']   ?? '');
$filterStatus = $_GET['status']   ?? '';

$where  = ['wi.receipt_date BETWEEN ? AND ?'];
$params = [$filterFrom, $filterTo];
if ($filterCust)   { $where[] = 'c.customer_name LIKE ?';  $params[] = "%$filterCust%"; }
if ($filterStatus) { $where[] = 'wi.status = ?';           $params[] = $filterStatus; }

$stmt = $pdo->prepare("
    SELECT wi.*,
           c.customer_name, c.customer_code,
           u.full_name AS created_by_name,
           COUNT(wii.id)     AS item_count,
           SUM(wii.quantity) AS total_qty
    FROM warehouse_in wi
    LEFT JOIN customers c         ON wi.customer_id = c.id
    LEFT JOIN users u             ON wi.created_by  = u.id
    LEFT JOIN warehouse_in_items wii ON wii.warehouse_in_id = wi.id
    WHERE " . implode(' AND ', $where) . "
    GROUP BY wi.id
    ORDER BY wi.receipt_date DESC, wi.id DESC
");
$stmt->execute($params);
$receipts = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
            <h4 class="mb-1"><i class="fas fa-file-import me-2 text-primary"></i>Phiếu nhập kho NVL</h4>
            <p class="text-muted mb-0">Khách hàng gửi hàng đến để gia công</p>
        </div>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalWI">
            <i class="fas fa-plus me-1"></i> Tạo phiếu nhập
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
                        <option value="open"       <?= $filterStatus === 'open'       ? 'selected' : '' ?>>Mở</option>
                        <option value="processing" <?= $filterStatus === 'processing' ? 'selected' : '' ?>>Đang gia công</option>
                        <option value="done"       <?= $filterStatus === 'done'       ? 'selected' : '' ?>>Hoàn thành</option>
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

    <!-- Bảng danh sách -->
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th width="50">#</th>
                            <th width="150">Số phiếu</th>
                            <th width="110">Ngày nhập</th>
                            <th>Khách hàng</th>
                            <th class="text-center" width="80">Số mã SP</th>
                            <th class="text-end" width="120">Tổng SL</th>
                            <th width="130">Trạng thái</th>
                            <th>Ghi chú</th>
                            <th width="150">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($receipts)): ?>
                        <tr><td colspan="9" class="text-center text-muted py-4">Chưa có phiếu nhập nào</td></tr>
                    <?php else: ?>
                        <?php foreach ($receipts as $i => $r): ?>
                        <tr>
                            <td class="text-muted small"><?= $i + 1 ?></td>
                            <td class="fw-semibold text-primary"><?= htmlspecialchars($r['receipt_no']) ?></td>
                            <td><?= date('d/m/Y', strtotime($r['receipt_date'])) ?></td>
                            <td>
                                <?php if ($r['customer_code']): ?>
                                <span class="badge bg-secondary me-1"><?= htmlspecialchars($r['customer_code']) ?></span>
                                <?php endif; ?>
                                <?= htmlspecialchars($r['customer_name'] ?? '—') ?>
                            </td>
                            <td class="text-center"><?= $r['item_count'] ?></td>
                            <td class="text-end fw-bold"><?= number_format($r['total_qty'] ?? 0, 0) ?></td>
                            <td>
                                <?php
                                $statusMap = [
                                    'open'       => ['bg-warning text-dark', 'Mở'],
                                    'processing' => ['bg-info text-white',   'Đang gia công'],
                                    'done'       => ['bg-success',           'Hoàn thành'],
                                ];
                                [$cls, $lbl] = $statusMap[$r['status']] ?? ['bg-secondary', $r['status']];
                                ?>
                                <span class="badge <?= $cls ?>"><?= $lbl ?></span>
                            </td>
                            <td class="text-muted small"><?= htmlspecialchars($r['note'] ?? '—') ?></td>
                            <td>
                                <button class="btn btn-xs btn-outline-primary btn-view-wi"
                                        data-id="<?= $r['id'] ?>"
                                        title="Xem chi tiết">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <?php if ($r['status'] === 'open'): ?>
                                <button class="btn btn-xs btn-outline-warning btn-edit-wi ms-1"
                                        data-id="<?= $r['id'] ?>"
                                        title="Sửa">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="btn btn-xs btn-outline-success ms-1 btn-start-wi"
                                        data-id="<?= $r['id'] ?>"
                                        data-no="<?= htmlspecialchars($r['receipt_no']) ?>"
                                        title="Bắt đầu gia công">
                                    <i class="fas fa-play"></i>
                                </button>
                                <button class="btn btn-xs btn-outline-danger ms-1 btn-delete-wi"
                                        data-id="<?= $r['id'] ?>"
                                        data-no="<?= htmlspecialchars($r['receipt_no']) ?>"
                                        title="Xoá">
                                    <i class="fas fa-trash"></i>
                                </button>
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
            Tổng: <strong><?= count($receipts) ?></strong> phiếu
        </div>
    </div>
</div>
</div>

<!-- Modal Tạo/Sửa phiếu nhập kho -->
<div class="modal fade" id="modalWI" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="modalWITitle">
                    <i class="fas fa-file-import me-2"></i>Tạo phiếu nhập kho NVL
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="formWI">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="action"     value="save">
                    <input type="hidden" name="id"         id="wiId" value="">
                    <div class="row g-3 mb-3">
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Ngày nhập <span class="text-danger">*</span></label>
                            <input type="date" name="receipt_date" id="wiDate"
                                   class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label fw-semibold">Khách hàng <span class="text-danger">*</span></label>
                            <select name="customer_id" id="wiCustomer" class="form-select" required>
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
                            <input type="text" name="note" id="wiNote" class="form-control"
                                   placeholder="Ghi chú phiếu nhập...">
                        </div>
                    </div>

                    <!-- Dòng sản phẩm -->
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <strong><i class="fas fa-list me-1"></i> Danh sách mã hàng</strong>
                        <button type="button" class="btn btn-sm btn-outline-success" id="btnAddRow">
                            <i class="fas fa-plus me-1"></i> Thêm dòng
                        </button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-bordered align-middle mb-0" id="tblItems">
                            <thead class="table-light">
                                <tr>
                                    <th width="40">#</th>
                                    <th>Mã sản phẩm <span class="text-danger">*</span></th>
                                    <th width="180" class="text-end">Số lượng <span class="text-danger">*</span></th>
                                    <th>Ghi chú</th>
                                    <th width="50"></th>
                                </tr>
                            </thead>
                            <tbody id="wiItems">
                                <!-- Rows added by JS -->
                            </tbody>
                        </table>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Huỷ</button>
                <button type="button" class="btn btn-primary" id="btnSaveWI">
                    <i class="fas fa-save me-1"></i> Lưu phiếu
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal Xem chi tiết -->
<div class="modal fade" id="modalWIDetail" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title"><i class="fas fa-eye me-2"></i>Chi tiết phiếu nhập kho</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="wiDetailBody">
                <div class="text-center py-3"><i class="fas fa-spinner fa-spin"></i> Đang tải...</div>
            </div>
        </div>
    </div>
</div>

<script>
const csrf = '<?= $csrf ?>';
let rowIndex = 0;
window._customerProducts = [];

function escHtml(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function buildProductOptionsFromCache(selected = 0) {
    const products = window._customerProducts || [];
    if (products.length === 0) {
        return '<option value="">-- Chọn khách hàng trước --</option>';
    }
    let opts = '<option value="">-- Chọn mã SP --</option>';
    products.forEach(p => {
        opts += `<option value="${p.id}" data-unit="${escHtml(p.unit)}" ${p.id == selected ? 'selected' : ''}>
                    [${escHtml(p.product_code)}] ${escHtml(p.description)} (${escHtml(p.unit)})
                 </option>`;
    });
    return opts;
}

// Khi chọn khách hàng: fetch SP của KH đó và rebuild tất cả dropdown
document.getElementById('wiCustomer').addEventListener('change', function() {
    const custId = this.value;
    if (!custId) {
        window._customerProducts = [];
        document.querySelectorAll('#wiItems select[name*="[product_code_id]"]').forEach(sel => {
            sel.innerHTML = '<option value="">-- Chọn khách hàng trước --</option>';
            sel.disabled = true;
        });
        return;
    }
    fetch(`/erp/api/production/get_customer_products.php?customer_id=${custId}`)
        .then(r => r.json())
        .then(res => {
            if (res.ok) {
                window._customerProducts = res.products;
                document.querySelectorAll('#wiItems select[name*="[product_code_id]"]').forEach(sel => {
                    const currentVal = sel.value;
                    sel.innerHTML = buildProductOptionsFromCache(0);
                    sel.value = currentVal;
                    sel.disabled = false;
                });
            } else {
                window._customerProducts = [];
                document.querySelectorAll('#wiItems select[name*="[product_code_id]"]').forEach(sel => {
                    sel.innerHTML = '<option value="">Không có SP nào</option>';
                    sel.disabled = true;
                });
            }
        })
        .catch(() => {
            alert('Không thể tải danh sách sản phẩm. Vui lòng thử lại.');
        });
});

function addRow(item = {}) {
    rowIndex++;
    const n = rowIndex;
    const hasCust = window._customerProducts.length > 0;
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td class="text-muted small row-num">${document.querySelectorAll('#wiItems tr').length + 1}</td>
        <td>
            <input type="hidden" name="items[${n}][id]" value="${item.id || 0}">
            <select name="items[${n}][product_code_id]" class="form-select form-select-sm" required ${hasCust ? '' : 'disabled'}>
                ${buildProductOptionsFromCache(item.product_code_id || 0)}
            </select>
        </td>
        <td>
            <input type="number" name="items[${n}][quantity]" class="form-control form-control-sm text-end"
                   value="${item.quantity || ''}" min="0.001" step="0.001" placeholder="0" required>
        </td>
        <td>
            <input type="text" name="items[${n}][note]" class="form-control form-control-sm"
                   value="${item.note || ''}" placeholder="Ghi chú...">
        </td>
        <td class="text-center">
            <button type="button" class="btn btn-xs btn-outline-danger btn-del-row"><i class="fas fa-times"></i></button>
        </td>`;
    tr.querySelector('.btn-del-row').addEventListener('click', () => {
        tr.remove();
        renumberRows();
    });
    document.getElementById('wiItems').appendChild(tr);
}

function renumberRows() {
    document.querySelectorAll('#wiItems tr').forEach((tr, i) => {
        const num = tr.querySelector('.row-num');
        if (num) num.textContent = i + 1;
    });
}

// Mở modal tạo mới
document.querySelector('[data-bs-target="#modalWI"]').addEventListener('click', () => {
    document.getElementById('modalWITitle').innerHTML = '<i class="fas fa-file-import me-2"></i>Tạo phiếu nhập kho NVL';
    document.getElementById('formWI').reset();
    document.getElementById('wiId').value = '';
    document.getElementById('wiDate').value = '<?= date('Y-m-d') ?>';
    document.getElementById('wiItems').innerHTML = '';
    rowIndex = 0;
    window._customerProducts = [];
    addRow();
});

document.getElementById('btnAddRow').addEventListener('click', () => addRow());

// Lưu phiếu
document.getElementById('btnSaveWI').addEventListener('click', () => {
    const form = document.getElementById('formWI');
    if (!form.checkValidity()) { form.reportValidity(); return; }
    const fd = new FormData(form);
    fetch('/erp/api/production/save_warehouse_in.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.ok) location.reload();
            else alert('Lỗi: ' + d.msg);
        });
});

// Sửa phiếu
document.querySelectorAll('.btn-edit-wi').forEach(btn => {
    btn.addEventListener('click', () => {
        const id = btn.dataset.id;
        fetch(`/erp/api/production/get_warehouse_in_detail.php?id=${id}`)
            .then(r => r.json())
            .then(d => {
                if (!d.ok) { alert(d.msg); return; }
                const h = d.header;
                document.getElementById('modalWITitle').innerHTML = '<i class="fas fa-edit me-2"></i>Sửa phiếu nhập kho';
                document.getElementById('wiId').value       = h.id;
                document.getElementById('wiDate').value     = h.receipt_date;
                document.getElementById('wiCustomer').value = h.customer_id;
                document.getElementById('wiNote').value     = h.note || '';
                document.getElementById('wiItems').innerHTML = '';
                rowIndex = 0;
                window._customerProducts = [];
                // Fetch SP của KH này trước, rồi mới render các dòng
                fetch(`/erp/api/production/get_customer_products.php?customer_id=${h.customer_id}`)
                    .then(r => r.json())
                    .then(res => {
                        window._customerProducts = res.ok ? res.products : [];
                        d.items.forEach(it => addRow(it));
                        new bootstrap.Modal(document.getElementById('modalWI')).show();
                    })
                    .catch(() => {
                        alert('Không thể tải danh sách sản phẩm. Vui lòng thử lại.');
                    });
            });
    });
});

// Xem chi tiết
document.querySelectorAll('.btn-view-wi').forEach(btn => {
    btn.addEventListener('click', () => {
        const id = btn.dataset.id;
        document.getElementById('wiDetailBody').innerHTML = '<div class="text-center py-3"><i class="fas fa-spinner fa-spin"></i> Đang tải...</div>';
        new bootstrap.Modal(document.getElementById('modalWIDetail')).show();
        fetch(`/erp/api/production/get_warehouse_in_detail.php?id=${id}`)
            .then(r => r.json())
            .then(d => {
                if (!d.ok) { document.getElementById('wiDetailBody').innerHTML = '<div class="alert alert-danger">'+d.msg+'</div>'; return; }
                const h = d.header;
                const statusMap = { open:'<span class="badge bg-warning text-dark">Mở</span>', processing:'<span class="badge bg-info">Đang gia công</span>', done:'<span class="badge bg-success">Hoàn thành</span>' };
                let rows = d.items.map((it,i) => `<tr>
                    <td>${i+1}</td>
                    <td><span class="badge bg-primary">${it.product_code}</span></td>
                    <td>${it.description || ''}</td>
                    <td class="text-center">${it.unit || ''}</td>
                    <td class="text-end fw-bold">${parseFloat(it.quantity).toLocaleString()}</td>
                    <td class="text-muted small">${it.note || '—'}</td>
                </tr>`).join('');
                document.getElementById('wiDetailBody').innerHTML = `
                    <div class="row mb-3">
                        <div class="col-md-6"><strong>Số phiếu:</strong> ${h.receipt_no}</div>
                        <div class="col-md-6"><strong>Ngày:</strong> ${new Date(h.receipt_date).toLocaleDateString('vi-VN')}</div>
                        <div class="col-md-6 mt-2"><strong>Khách hàng:</strong> ${h.customer_name}</div>
                        <div class="col-md-6 mt-2"><strong>Trạng thái:</strong> ${statusMap[h.status] || h.status}</div>
                        <div class="col-12 mt-2"><strong>Ghi chú:</strong> ${h.note || '—'}</div>
                    </div>
                    <table class="table table-bordered table-sm align-middle">
                        <thead class="table-dark"><tr><th>#</th><th>Mã SP</th><th>Mô tả</th><th>ĐV</th><th class="text-end">Số lượng</th><th>Ghi chú</th></tr></thead>
                        <tbody>${rows}</tbody>
                    </table>`;
            });
    });
});

// Bắt đầu gia công
document.querySelectorAll('.btn-start-wi').forEach(btn => {
    btn.addEventListener('click', () => {
        if (!confirm(`Bắt đầu gia công phiếu ${btn.dataset.no}?`)) return;
        const fd = new FormData();
        fd.append('csrf_token', csrf);
        fd.append('action', 'start');
        fd.append('id', btn.dataset.id);
        fetch('/erp/api/production/save_warehouse_in.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (d.ok) location.reload();
                else alert('Lỗi: ' + d.msg);
            });
    });
});

// Xoá
document.querySelectorAll('.btn-delete-wi').forEach(btn => {
    btn.addEventListener('click', () => {
        if (!confirm(`Xoá phiếu ${btn.dataset.no}?`)) return;
        const fd = new FormData();
        fd.append('csrf_token', csrf);
        fd.append('action', 'delete');
        fd.append('id', btn.dataset.id);
        fetch('/erp/api/production/save_warehouse_in.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (d.ok) location.reload();
                else alert('Lỗi: ' + d.msg);
            });
    });
});
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/footer.php'; ?>
