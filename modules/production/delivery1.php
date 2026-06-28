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
$filterStatus = $_GET['status'] ?? '';

$where  = ['dn.delivery_date BETWEEN ? AND ?'];
$params = [$filterFrom, $filterTo];
if ($filterCust) { $where[] = 'c.customer_name LIKE ?'; $params[] = "%$filterCust%"; }
if ($filterStatus) { $where[] = 'dn.status = ?'; $params[] = $filterStatus; }

$deliveries = $pdo->prepare("
    SELECT dn.*,
           c.customer_name,
           u.full_name        AS created_by_name,
           COUNT(dni.id)      AS item_count,
           SUM(dni.quantity)  AS total_qty
    FROM delivery_notes dn
    LEFT JOIN customers c        ON dn.customer_id = c.id
    LEFT JOIN users u            ON dn.created_by  = u.id
    LEFT JOIN delivery_note_items dni ON dni.delivery_note_id = dn.id
    WHERE " . implode(' AND ', $where) . "
    GROUP BY dn.id
    ORDER BY dn.delivery_date DESC, dn.id DESC
");
$deliveries->execute($params);
$deliveries = $deliveries->fetchAll(PDO::FETCH_ASSOC);

$grandTotal = array_sum(array_column($deliveries, 'total_amount'));

$customers = $pdo->query("
    SELECT id, customer_name, customer_code FROM customers WHERE is_active=1 ORDER BY customer_name
")->fetchAll(PDO::FETCH_ASSOC);

// ✅ Load danh sách output còn hàng để chọn khi tạo biên bản
$outputList = $pdo->query("
    SELECT po.id          AS output_id,
           po.output_no,
           po.output_date,
           po.quantity_completed,
           po.quantity_delivered,
           pc.id          AS product_code_id,
           pc.product_code,
           pc.description,
           pc.unit,
           COALESCE(pp.unit_price, 0) AS unit_price,
           (po.quantity_completed - COALESCE(SUM(dni.quantity),0)) AS available
    FROM production_outputs po
    JOIN product_codes pc ON po.product_code_id = pc.id
    LEFT JOIN product_prices pp ON pp.product_code_id = pc.id
    LEFT JOIN delivery_note_items dni ON dni.production_output_id = po.id
    GROUP BY po.id
    HAVING available > 0
    ORDER BY po.output_date DESC, pc.product_code
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
            <h4 class="mb-1"><i class="fas fa-shipping-fast me-2 text-primary"></i>Biên bản giao hàng</h4>
            <p class="text-muted mb-0">Quản lý phiếu giao hàng cho khách</p>
        </div>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalDelivery">
            <i class="fas fa-plus me-1"></i> Tạo biên bản
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
                        <option value="draft"     <?= $filterStatus==='draft'    ?'selected':'' ?>>Nháp</option>
                        <option value="confirmed" <?= $filterStatus==='confirmed'?'selected':'' ?>>Đã xác nhận</option>
                        <option value="invoiced"  <?= $filterStatus==='invoiced' ?'selected':'' ?>>Đã xuất HĐ</option>
                    </select>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-sm btn-primary">
                        <i class="fas fa-filter me-1"></i>Lọc
                    </button>
                    <a href="?" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
                </div>
                <?php if (!empty($deliveries)): ?>
                <div class="col-auto ms-auto">
                    <span class="badge bg-success fs-6">Tổng: <?= number_format($grandTotal) ?> đ</span>
                </div>
                <?php endif; ?>
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
                            <th>Số biên bản</th>
                            <th>Ngày GH</th>
                            <th>Khách hàng</th>
                            <th class="text-center">Số dòng SP</th>
                            <th class="text-end">Tổng SL</th>
                            <th class="text-end">Thành tiền</th>
                            <th>Trạng thái</th>
                            <th>Người tạo</th>
                            <th>Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($deliveries)): ?>
                        <tr><td colspan="9" class="text-center text-muted py-4">Chưa có biên bản nào</td></tr>
                    <?php else: ?>
                        <?php foreach ($deliveries as $dv): ?>
                        <tr>
                            <td class="fw-semibold text-primary"><?= htmlspecialchars($dv['delivery_no']) ?></td>
                            <td><?= date('d/m/Y', strtotime($dv['delivery_date'])) ?></td>
                            <td class="fw-semibold"><?= htmlspecialchars($dv['customer_name'] ?? '—') ?></td>
                            <td class="text-center"><?= $dv['item_count'] ?></td>
                            <td class="text-end"><?= number_format($dv['total_qty'] ?? 0) ?></td>
                            <td class="text-end fw-bold text-success">
                                <?= number_format($dv['total_amount']) ?> đ
                            </td>
                            <td>
                                <?php
                                $st = ['draft'=>['secondary','Nháp'],'confirmed'=>['primary','Xác nhận'],'invoiced'=>['success','Xuất HĐ']];
                                $s  = $st[$dv['status']] ?? ['secondary','?'];
                                echo "<span class='badge bg-{$s[0]}'>{$s[1]}</span>";
                                ?>
                            </td>
                            <td class="small text-muted"><?= htmlspecialchars($dv['created_by_name'] ?? '—') ?></td>
                            <td>
                                <a href="delivery_detail.php?id=<?= $dv['id'] ?>"
                                   class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <a href="/erp/api/production/print_delivery.php?id=<?= $dv['id'] ?>"
                                   target="_blank" class="btn btn-sm btn-outline-secondary">
                                    <i class="fas fa-print"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                    <?php if (!empty($deliveries)): ?>
                    <tfoot class="table-light">
                        <tr>
                            <td colspan="5" class="text-end fw-bold">Tổng cộng:</td>
                            <td class="text-end fw-bold text-success fs-6">
                                <?= number_format($grandTotal) ?> đ
                            </td>
                            <td colspan="3"></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
        <div class="card-footer text-muted small">
            Tổng: <strong><?= count($deliveries) ?></strong> biên bản
        </div>
    </div>
</div>
</div>

<!-- ============ MODAL TẠO BIÊN BẢN ============ -->
<div class="modal fade" id="modalDelivery" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="fas fa-shipping-fast me-2"></i>Tạo biên bản giao hàng
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="formDelivery">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">

                    <div class="row g-3 mb-3 pb-3 border-bottom">
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Ngày giao <span class="text-danger">*</span></label>
                            <input type="date" name="delivery_date" class="form-control"
                                   value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label fw-semibold">Khách hàng <span class="text-danger">*</span></label>
                            <select name="customer_id" class="form-select" required>
                                <option value="">-- Chọn khách hàng --</option>
                                <?php foreach ($customers as $c): ?>
                                <option value="<?= $c['id'] ?>">
                                    [<?= htmlspecialchars($c['customer_code']) ?>]
                                    <?= htmlspecialchars($c['customer_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Ghi chú</label>
                            <input type="text" name="note" class="form-control"
                                   placeholder="Ghi chú biên bản...">
                        </div>
                    </div>

                    <!-- Chi tiết SP -->
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="fw-bold">Chi tiết sản phẩm
                            <small class="text-muted fw-normal">(chọn từ output SX)</small>
                        </span>
                        <button type="button" class="btn btn-sm btn-success" id="btnAddRow">
                            <i class="fas fa-plus me-1"></i>Thêm dòng
                        </button>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-bordered align-middle mb-0" id="itemTable">
                            <thead class="table-light">
                                <tr>
                                    <th width="260">Output SX <span class="text-danger">*</span></th>
                                    <th>Mô tả</th>
                                    <th width="70">ĐVT</th>
                                    <th width="110">Số lượng</th>
                                    <th width="130">Đơn giá</th>
                                    <th width="140">Thành tiền</th>
                                    <th width="40"></th>
                                </tr>
                            </thead>
                            <tbody id="itemBody"></tbody>
                            <tfoot>
                                <tr class="table-light">
                                    <td colspan="5" class="text-end fw-bold">Tổng cộng:</td>
                                    <td class="fw-bold text-success" id="grandTotalDisplay">0 đ</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Huỷ</button>
                <button type="button" class="btn btn-warning" id="btnSaveDraft">
                    <i class="fas fa-save me-1"></i>Lưu nháp
                </button>
                <button type="button" class="btn btn-primary" id="btnSaveConfirm">
                    <i class="fas fa-check me-1"></i>Xác nhận & Lưu
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Output data cho JS -->
<script>
// ✅ Truyền danh sách output từ PHP sang JS
const OUTPUTS = <?= json_encode($outputList) ?>;

function makeRow(idx) {
    const opts = OUTPUTS.map(o =>
        `<option value="${o.output_id}"
            data-pcid="${o.product_code_id}"
            data-desc="${o.description}"
            data-unit="${o.unit}"
            data-price="${o.unit_price}"
            data-available="${o.available}">
            [${o.product_code}] ${o.description}
            — Còn: ${parseFloat(o.available).toLocaleString()} ${o.unit}
            (${o.output_no})
        </option>`
    ).join('');

    return `
    <tr data-idx="${idx}">
        <td>
            <select name="items[${idx}][production_output_id]"
                    class="form-select form-select-sm sel-output" required>
                <option value="">-- Chọn output SX --</option>
                ${opts}
            </select>
            <input type="hidden" name="items[${idx}][product_code_id]" class="inp-pcid">
        </td>
        <td>
            <input type="text" name="items[${idx}][description]"
                   class="form-control form-control-sm inp-desc" readonly>
        </td>
        <td>
            <input type="text" name="items[${idx}][unit]"
                   class="form-control form-control-sm inp-unit" readonly>
        </td>
        <td>
            <input type="number" name="items[${idx}][quantity]"
                   class="form-control form-control-sm inp-qty"
                   placeholder="0" min="1" value="0" required>
            <div class="form-text text-info inp-avail small"></div>
        </td>
        <td>
            <input type="number" name="items[${idx}][unit_price]"
                   class="form-control form-control-sm inp-price"
                   placeholder="0" min="0" value="0">
        </td>
        <td>
            <input type="number" name="items[${idx}][total_price]"
                   class="form-control form-control-sm inp-total fw-bold text-success"
                   readonly value="0">
        </td>
        <td>
            <button type="button"
                    class="btn btn-sm btn-outline-danger btn-remove-row">
                <i class="fas fa-times"></i>
            </button>
        </td>
    </tr>`;
}

let rowIdx = 0;

function addRow() {
    document.getElementById('itemBody').insertAdjacentHTML('beforeend', makeRow(rowIdx++));
}

// Reset modal mỗi lần mở
document.getElementById('modalDelivery').addEventListener('show.bs.modal', () => {
    document.getElementById('itemBody').innerHTML = '';
    rowIdx = 0;
    addRow();
    updateGrandTotal();
});

document.getElementById('btnAddRow').addEventListener('click', addRow);

// Event delegation
document.getElementById('itemBody').addEventListener('change', function(e) {
    const row = e.target.closest('tr'); if (!row) return;

    // ✅ Chọn output → autofill product_code_id, desc, unit, price, available
    if (e.target.classList.contains('sel-output')) {
        const opt = e.target.options[e.target.selectedIndex];
        row.querySelector('.inp-pcid').value  = opt.dataset.pcid  || '';
        row.querySelector('.inp-desc').value  = opt.dataset.desc  || '';
        row.querySelector('.inp-unit').value  = opt.dataset.unit  || '';
        row.querySelector('.inp-price').value = opt.dataset.price || 0;
        row.querySelector('.inp-qty').max     = opt.dataset.available || '';
        row.querySelector('.inp-avail').textContent =
            opt.dataset.available
                ? `Tối đa: ${parseFloat(opt.dataset.available).toLocaleString()}`
                : '';
        calcRowTotal(row);
    }

    if (e.target.classList.contains('inp-qty') ||
        e.target.classList.contains('inp-price')) {
        calcRowTotal(row);
    }
});

document.getElementById('itemBody').addEventListener('input', function(e) {
    const row = e.target.closest('tr'); if (!row) return;
    if (e.target.classList.contains('inp-qty') ||
        e.target.classList.contains('inp-price')) {
        calcRowTotal(row);
    }
});

document.getElementById('itemBody').addEventListener('click', function(e) {
    if (e.target.closest('.btn-remove-row')) {
        if (document.querySelectorAll('#itemBody tr').length <= 1) {
            alert('Cần ít nhất 1 dòng!'); return;
        }
        e.target.closest('tr').remove();
        updateGrandTotal();
    }
});

function calcRowTotal(row) {
    const qty   = parseFloat(row.querySelector('.inp-qty').value)   || 0;
    const price = parseFloat(row.querySelector('.inp-price').value) || 0;
    row.querySelector('.inp-total').value = Math.round(qty * price);
    updateGrandTotal();
}

function updateGrandTotal() {
    let sum = 0;
    document.querySelectorAll('.inp-total').forEach(el => sum += parseFloat(el.value) || 0);
    document.getElementById('grandTotalDisplay').textContent =
        sum.toLocaleString('vi-VN') + ' đ';
}

function saveDelivery(status) {
    const form = document.getElementById('formDelivery');
    if (!form.checkValidity()) { form.reportValidity(); return; }

    // Kiểm tra ít nhất 1 dòng hợp lệ
    let valid = false;
    document.querySelectorAll('#itemBody tr').forEach(r => {
        const out = r.querySelector('.sel-output').value;
        const qty = parseFloat(r.querySelector('.inp-qty').value) || 0;
        if (out && qty > 0) valid = true;
    });
    if (!valid) { alert('Vui lòng chọn output SX và nhập số lượng!'); return; }

    const btn = status === 'confirmed'
        ? document.getElementById('btnSaveConfirm')
        : document.getElementById('btnSaveDraft');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Đang lưu...';

    const fd = new FormData(form);
    fd.append('status', status);

    fetch('/erp/api/production/save_delivery.php', { method:'POST', body: fd })
    .then(r => r.json())
    .then(res => {
        if (res.ok) {
            bootstrap.Modal.getInstance(document.getElementById('modalDelivery')).hide();
            location.reload();
        } else { alert('Lỗi: ' + res.msg); }
    })
    .finally(() => {
        btn.disabled = false;
        btn.innerHTML = status === 'confirmed'
            ? '<i class="fas fa-check me-1"></i>Xác nhận & Lưu'
            : '<i class="fas fa-save me-1"></i>Lưu nháp';
    });
}

document.getElementById('btnSaveDraft').addEventListener('click',   () => saveDelivery('draft'));
document.getElementById('btnSaveConfirm').addEventListener('click', () => saveDelivery('confirmed'));
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/footer.php'; ?>