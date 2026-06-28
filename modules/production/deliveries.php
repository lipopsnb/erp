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
$warehouseOutId  = (int)($_GET['warehouse_out_id'] ?? 0);

$where  = ['d.delivery_date BETWEEN ? AND ?'];
$params = [$filterFrom, $filterTo];
if ($filterCust)   { $where[] = 'c.customer_name LIKE ?'; $params[] = "%$filterCust%"; }
if ($filterStatus) { $where[] = 'd.status = ?';           $params[] = $filterStatus; }

$stmt = $pdo->prepare("
    SELECT d.*,
           c.customer_name, c.customer_code,
           u.full_name    AS created_by_name,
           wo.export_no
    FROM deliveries d
    LEFT JOIN customers c     ON d.customer_id      = c.id
    LEFT JOIN users u         ON d.created_by       = u.id
    LEFT JOIN warehouse_out wo ON d.warehouse_out_id = wo.id
    WHERE " . implode(' AND ', $where) . "
    GROUP BY d.id
    ORDER BY d.delivery_date DESC, d.id DESC
");
$stmt->execute($params);
$deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);

$customers = $pdo->query("
    SELECT id, customer_code, customer_name FROM customers WHERE is_active = 1 ORDER BY customer_name
")->fetchAll(PDO::FETCH_ASSOC);

// Lấy danh sách phiếu xuất kho confirmed (để tạo giao hàng từ đó)
$confirmedExports = $pdo->query("
    SELECT wo.id, wo.export_no, wo.export_date, wo.customer_id, c.customer_name
    FROM warehouse_out wo
    JOIN customers c ON wo.customer_id = c.id
    WHERE wo.status = 'confirmed'
    ORDER BY wo.export_date DESC
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
                            <th>Phiếu xuất kho</th>
                            <th class="text-end" width="140">Tổng tiền</th>
                            <th width="130">Trạng thái</th>
                            <th width="200">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($deliveries)): ?>
                        <tr><td colspan="8" class="text-center text-muted py-4">Chưa có phiếu giao hàng nào</td></tr>
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
                            <td class="small text-muted"><?= htmlspecialchars($dv['export_no'] ?? '—') ?></td>
                            <td class="text-end fw-bold text-success">
                                <?= number_format($dv['total_amount'], 0, ',', '.') ?> đ
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
                    <input type="hidden" name="csrf_token"      value="<?= $csrf ?>">
                    <input type="hidden" name="action"          value="save">
                    <input type="hidden" name="id"              value="">
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
                            <label class="form-label fw-semibold">Từ phiếu xuất kho (tuỳ chọn)</label>
                            <select name="warehouse_out_id" id="dlWarehouseOut" class="form-select">
                                <option value="">-- Chọn phiếu xuất kho --</option>
                                <?php foreach ($confirmedExports as $ce): ?>
                                <option value="<?= $ce['id'] ?>" data-cust="<?= $ce['customer_id'] ?>">
                                    <?= htmlspecialchars($ce['export_no']) ?>
                                    — <?= htmlspecialchars($ce['customer_name']) ?>
                                    (<?= date('d/m/Y', strtotime($ce['export_date'])) ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <input type="text" name="note" class="form-control" placeholder="Ghi chú phiếu giao...">
                    </div>
                    <div class="mb-2">
                        <button type="button" class="btn btn-sm btn-outline-info" id="btnLoadDLItems">
                            <i class="fas fa-search me-1"></i> Load hàng từ phiếu xuất kho
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-success ms-2" id="btnAddDLRow">
                            <i class="fas fa-plus me-1"></i> Thêm dòng thủ công
                        </button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-bordered align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th width="40">#</th>
                                    <th>Mã SP</th>
                                    <th class="text-end" width="130">Số lượng</th>
                                    <th class="text-end" width="150">Đơn giá</th>
                                    <th class="text-end" width="150">Thành tiền</th>
                                    <th>Ghi chú</th>
                                    <th width="40"></th>
                                </tr>
                            </thead>
                            <tbody id="dlItems"></tbody>
                            <tfoot>
                                <tr class="table-warning">
                                    <td colspan="4" class="text-end fw-bold">Tổng cộng:</td>
                                    <td class="text-end fw-bold" id="dlGrandTotal">0 đ</td>
                                    <td colspan="2"></td>
                                </tr>
                            </tfoot>
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
const productList = <?= json_encode(
    $pdo->query("SELECT id, product_code, description, unit FROM product_codes WHERE is_active=1 ORDER BY product_code")
        ->fetchAll(PDO::FETCH_ASSOC),
    JSON_UNESCAPED_UNICODE
) ?>;
let dlRowIdx = 0;

function escHtml(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function buildPcOptions(selected = 0) {
    let o = '<option value="">-- Chọn mã SP --</option>';
    productList.forEach(p => {
        o += `<option value="${p.id}" data-unit="${escHtml(p.unit)}" ${p.id==selected?'selected':''}>[${escHtml(p.product_code)}] ${escHtml(p.description)}</option>`;
    });
    return o;
}

function calcTotal() {
    let total = 0;
    document.querySelectorAll('#dlItems tr').forEach(tr => {
        const qty   = parseFloat(tr.querySelector('.dl-qty')?.value   || 0);
        const price = parseFloat(tr.querySelector('.dl-price')?.value || 0);
        const t     = qty * price;
        const tEl   = tr.querySelector('.dl-total');
        if (tEl) tEl.value = Math.round(t);
        total += t;
    });
    document.getElementById('dlGrandTotal').textContent = Math.round(total).toLocaleString() + ' đ';
}

function addDLRow(item = {}) {
    dlRowIdx++;
    const n = dlRowIdx;
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td class="text-muted small dl-rownum">${document.querySelectorAll('#dlItems tr').length+1}</td>
        <td>
            <select name="items[${n}][product_code_id]" class="form-select form-select-sm" required>
                ${buildPcOptions(item.product_code_id||0)}
            </select>
        </td>
        <td>
            <input type="number" name="items[${n}][quantity]" class="form-control form-control-sm text-end dl-qty"
                   value="${item.quantity||''}" min="0.001" step="0.001" required>
        </td>
        <td>
            <input type="number" name="items[${n}][unit_price]" class="form-control form-control-sm text-end dl-price"
                   value="${item.unit_price||0}" min="0" step="1">
        </td>
        <td>
            <input type="number" name="items[${n}][total_price]" class="form-control form-control-sm text-end dl-total"
                   value="${item.total_price||0}" min="0" step="1" readonly>
        </td>
        <td>
            <input type="text" name="items[${n}][note]" class="form-control form-control-sm"
                   value="${item.note||''}" placeholder="...">
        </td>
        <td><button type="button" class="btn btn-xs btn-outline-danger btn-del-dl-row"><i class="fas fa-times"></i></button></td>`;
    tr.querySelector('.btn-del-dl-row').addEventListener('click', () => { tr.remove(); calcTotal(); });
    tr.querySelectorAll('.dl-qty,.dl-price').forEach(inp => inp.addEventListener('input', calcTotal));
    document.getElementById('dlItems').appendChild(tr);
    calcTotal();
}

document.querySelector('[data-bs-target="#modalDL"]').addEventListener('click', () => {
    document.getElementById('formDL').reset();
    document.getElementById('dlItems').innerHTML = '';
    dlRowIdx = 0;
    addDLRow();
});

document.getElementById('btnAddDLRow').addEventListener('click', () => addDLRow());

document.getElementById('btnLoadDLItems').addEventListener('click', () => {
    const woId   = document.getElementById('dlWarehouseOut').value;
    const custId = document.getElementById('dlCustomer').value;
    if (!woId) { alert('Vui lòng chọn phiếu xuất kho trước'); return; }
    fetch(`/erp/api/production/get_delivery_items.php?warehouse_out_id=${woId}&customer_id=${custId}`)
        .then(r => r.json())
        .then(d => {
            if (!d.ok) { alert(d.msg); return; }
            document.getElementById('dlItems').innerHTML = '';
            dlRowIdx = 0;
            d.items.forEach(it => addDLRow(it));
        });
});

document.getElementById('btnSaveDL').addEventListener('click', () => {
    const form = document.getElementById('formDL');
    if (!form.checkValidity()) { form.reportValidity(); return; }
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
        // Load từ delivery_items
        fetch(`/erp/api/invoice/get_delivery_items.php?id=${id}`)
            .then(r => r.json())
            .then(d => {
                if (!d.ok) { document.getElementById('dlDetailBody').innerHTML = '<div class="alert alert-danger">'+d.msg+'</div>'; return; }
                let rows = d.items.map((it,i) => `<tr>
                    <td>${i+1}</td>
                    <td><span class="badge bg-primary">${it.product_code}</span></td>
                    <td>${it.description||''}</td>
                    <td class="text-center">${it.unit||''}</td>
                    <td class="text-end">${parseFloat(it.quantity).toLocaleString()}</td>
                    <td class="text-end">${parseFloat(it.unit_price).toLocaleString()} đ</td>
                    <td class="text-end fw-bold">${parseFloat(it.total_price).toLocaleString()} đ</td>
                </tr>`).join('');
                const total = d.items.reduce((s,it) => s + parseFloat(it.total_price||0), 0);
                document.getElementById('dlDetailBody').innerHTML = `
                    <table class="table table-bordered table-sm align-middle">
                        <thead class="table-dark"><tr><th>#</th><th>Mã SP</th><th>Mô tả</th><th>ĐV</th><th class="text-end">SL</th><th class="text-end">Đơn giá</th><th class="text-end">Thành tiền</th></tr></thead>
                        <tbody>${rows}</tbody>
                        <tfoot class="table-warning"><tr><td colspan="6" class="text-end fw-bold">Tổng:</td><td class="text-end fw-bold">${total.toLocaleString()} đ</td></tr></tfoot>
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

// Xoá
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
