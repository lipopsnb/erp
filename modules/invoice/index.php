<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';
requireLogin();
requireRole('director','accountant','manager');

$pdo  = getDBConnection();
$user = currentUser();

$filterFrom   = $_GET['from']   ?? date('Y-m-01');
$filterTo     = $_GET['to']     ?? date('Y-m-d');
$filterCust   = trim($_GET['cust']   ?? '');
$filterStatus = $_GET['status'] ?? '';

$where  = ['i.invoice_date BETWEEN ? AND ?'];
$params = [$filterFrom, $filterTo];
if ($filterCust) { $where[] = 'c.customer_name LIKE ?'; $params[] = "%$filterCust%"; }
if ($filterStatus) { $where[] = 'i.status = ?'; $params[] = $filterStatus; }

$invoices = $pdo->prepare("
    SELECT i.*,
           c.customer_name, c.customer_code,
           u.full_name AS created_by_name,
           COALESCE(SUM(p.amount),0) AS paid_amount
    FROM invoices i
    LEFT JOIN customers c  ON i.customer_id  = c.id
    LEFT JOIN users u      ON i.created_by   = u.id
    LEFT JOIN payments p   ON p.invoice_id   = i.id
    WHERE " . implode(' AND ', $where) . "
    GROUP BY i.id
    ORDER BY i.invoice_date DESC, i.id DESC
");
$invoices->execute($params);
$invoices = $invoices->fetchAll(PDO::FETCH_ASSOC);

$totalAmount = array_sum(array_column($invoices, 'total_amount'));
$totalPaid   = array_sum(array_column($invoices, 'paid_amount'));
$totalDebt   = $totalAmount - $totalPaid;

$customers = $pdo->query("
    SELECT id, customer_name, customer_code FROM customers WHERE is_active=1 ORDER BY customer_name
")->fetchAll(PDO::FETCH_ASSOC);

$productList = $pdo->query("
    SELECT pc.id, pc.product_code, pc.description, pc.unit,
           COALESCE(p.unit_price,0) AS unit_price
    FROM product_codes pc
    LEFT JOIN prices p ON p.product_code_id = pc.id AND p.is_active = 1
    WHERE pc.is_active = 1 ORDER BY pc.product_code
")->fetchAll(PDO::FETCH_ASSOC);

// Biên bản chưa xuất HĐ
$pendingDeliveries = $pdo->query("
    SELECT d.id, d.delivery_no, d.delivery_date, d.total_amount,
           c.customer_name, d.customer_id
    FROM deliveries d
    LEFT JOIN customers c ON d.customer_id = c.id
    WHERE d.status = 'confirmed'
    ORDER BY d.delivery_date DESC
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
            <h4 class="mb-1"><i class="fas fa-file-invoice me-2 text-primary"></i>Ho�� đơn</h4>
            <p class="text-muted mb-0">Quản lý hoá đơn bán hàng</p>
        </div>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalInvoice">
            <i class="fas fa-plus me-1"></i> Tạo hoá đơn
        </button>
    </div>

    <?php showFlash(); ?>

    <!-- Thống kê nhanh -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm text-center py-3">
                <div class="fs-5 fw-bold text-primary"><?= number_format($totalAmount) ?> đ</div>
                <div class="text-muted small">Tổng hoá đơn</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm text-center py-3">
                <div class="fs-5 fw-bold text-success"><?= number_format($totalPaid) ?> đ</div>
                <div class="text-muted small">Đã thu</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm text-center py-3">
                <div class="fs-5 fw-bold text-danger"><?= number_format($totalDebt) ?> đ</div>
                <div class="text-muted small">Còn nợ</div>
            </div>
        </div>
    </div>

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
                        <option value="unpaid"   <?= $filterStatus==='unpaid'   ?'selected':'' ?>>Chưa thanh toán</option>
                        <option value="partial"  <?= $filterStatus==='partial'  ?'selected':'' ?>>Thanh toán 1 phần</option>
                        <option value="paid"     <?= $filterStatus==='paid'     ?'selected':'' ?>>Đã thanh toán</option>
                        <option value="cancelled"<?= $filterStatus==='cancelled'?'selected':'' ?>>Đã huỷ</option>
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

    <!-- Bảng hoá đơn -->
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>Số HĐ</th>
                            <th>Ngày HĐ</th>
                            <th>Khách hàng</th>
                            <th class="text-end">Tổng tiền</th>
                            <th class="text-end">Đã thu</th>
                            <th class="text-end">Còn nợ</th>
                            <th>Trạng thái</th>
                            <th>Hạn TT</th>
                            <th>Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($invoices)): ?>
                        <tr><td colspan="9" class="text-center text-muted py-4">Chưa có hoá đơn nào</td></tr>
                    <?php else: ?>
                        <?php foreach ($invoices as $inv):
                            $debt    = $inv['total_amount'] - $inv['paid_amount'];
                            $overdue = $inv['due_date'] && $inv['status'] !== 'paid' && $inv['due_date'] < date('Y-m-d');
                        ?>
                        <tr class="<?= $overdue ? 'table-danger' : '' ?>">
                            <td class="fw-semibold text-primary">
                                <?= htmlspecialchars($inv['invoice_no']) ?>
                                <?php if ($overdue): ?>
                                    <span class="badge bg-danger ms-1">Quá hạn</span>
                                <?php endif; ?>
                            </td>
                            <td><?= date('d/m/Y', strtotime($inv['invoice_date'])) ?></td>
                            <td class="fw-semibold"><?= htmlspecialchars($inv['customer_name'] ?? '—') ?></td>
                            <td class="text-end"><?= number_format($inv['total_amount']) ?> đ</td>
                            <td class="text-end text-success"><?= number_format($inv['paid_amount']) ?> đ</td>
                            <td class="text-end fw-bold <?= $debt > 0 ? 'text-danger' : 'text-success' ?>">
                                <?= number_format($debt) ?> đ
                            </td>
                            <td>
                                <?php
                                $st = [
                                    'unpaid'    => ['danger',  'Chưa TT'],
                                    'partial'   => ['warning', '1 phần'],
                                    'paid'      => ['success', 'Đã TT'],
                                    'cancelled' => ['secondary','Huỷ'],
                                ];
                                $s = $st[$inv['status']] ?? ['secondary','?'];
                                echo "<span class='badge bg-{$s[0]}'>{$s[1]}</span>";
                                ?>
                            </td>
                            <td class="<?= $overdue ? 'text-danger fw-bold' : 'text-muted' ?> small">
                                <?= $inv['due_date'] ? date('d/m/Y', strtotime($inv['due_date'])) : '—' ?>
                            </td>
                            <td>
                                <a href="invoice_detail.php?id=<?= $inv['id'] ?>"
                                   class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <?php if ($inv['status'] !== 'paid' && $inv['status'] !== 'cancelled'): ?>
                                <button class="btn btn-sm btn-outline-success btn-pay"
                                        data-id="<?= $inv['id'] ?>"
                                        data-no="<?= htmlspecialchars($inv['invoice_no']) ?>"
                                        data-debt="<?= $debt ?>">
                                    <i class="fas fa-money-bill-wave"></i>
                                </button>
                                <?php endif; ?>
                                <a href="/erp/api/invoice/print_invoice.php?id=<?= $inv['id'] ?>"
                                   target="_blank" class="btn btn-sm btn-outline-secondary">
                                    <i class="fas fa-print"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer text-muted small">
            Tổng: <strong><?= count($invoices) ?></strong> hoá đơn
        </div>
    </div>
</div>
</div>

<!-- ============ MODAL TẠO HOÁ ĐƠN ============ -->
<div class="modal fade" id="modalInvoice" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="fas fa-file-invoice me-2"></i>Tạo hoá đơn
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="formInvoice">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">

                    <div class="row g-3 mb-3 pb-3 border-bottom">
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Ngày HĐ <span class="text-danger">*</span></label>
                            <input type="date" name="invoice_date" class="form-control"
                                   value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Hạn thanh toán</label>
                            <input type="date" name="due_date" class="form-control"
                                   value="<?= date('Y-m-d', strtotime('+30 days')) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Khách hàng <span class="text-danger">*</span></label>
                            <select name="customer_id" id="invCustomer" class="form-select" required>
                                <option value="">-- Chọn KH --</option>
                                <?php foreach ($customers as $c): ?>
                                <option value="<?= $c['id'] ?>">
                                    [<?= htmlspecialchars($c['customer_code']) ?>]
                                    <?= htmlspecialchars($c['customer_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-semibold">Thuế VAT (%)</label>
                            <input type="number" name="vat_rate" id="invVat"
                                   class="form-control" value="0" min="0" max="100">
                        </div>
                        <div class="col-12">
                            <!-- Import từ biên bản giao hàng -->
                            <?php if (!empty($pendingDeliveries)): ?>
                            <div class="alert alert-info py-2 mb-0">
                                <i class="fas fa-info-circle me-1"></i>
                                <strong>Import từ biên bản:</strong>
                                <select id="selDelivery" class="form-select form-select-sm d-inline-block w-auto ms-2">
                                    <option value="">-- Chọn biên bản --</option>
                                    <?php foreach ($pendingDeliveries as $dv): ?>
                                    <option value="<?= $dv['id'] ?>"
                                            data-custid="<?= $dv['customer_id'] ?>">
                                        <?= htmlspecialchars($dv['delivery_no']) ?>
                                        — <?= htmlspecialchars($dv['customer_name']) ?>
                                        (<?= date('d/m/Y', strtotime($dv['delivery_date'])) ?>)
                                        — <?= number_format($dv['total_amount']) ?> đ
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="btn btn-sm btn-info ms-1" id="btnImportDelivery">
                                    <i class="fas fa-download me-1"></i>Import
                                </button>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Ghi chú</label>
                            <input type="text" name="note" class="form-control" placeholder="Ghi chú hoá đơn...">
                        </div>
                    </div>

                    <!-- Chi tiết SP -->
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="fw-bold">Chi tiết sản phẩm</span>
                        <button type="button" class="btn btn-sm btn-success" id="btnAddInvRow">
                            <i class="fas fa-plus me-1"></i>Thêm dòng
                        </button>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-bordered align-middle mb-2" id="invItemTable">
                            <thead class="table-light">
                                <tr>
                                    <th width="220">Mã sản phẩm</th>
                                    <th>Mô tả</th>
                                    <th width="70">ĐVT</th>
                                    <th width="100">Số lượng</th>
                                    <th width="130">Đơn giá</th>
                                    <th width="140">Thành tiền</th>
                                    <th width="40"></th>
                                </tr>
                            </thead>
                            <tbody id="invItemBody"></tbody>
                            <tfoot>
                                <tr class="table-light">
                                    <td colspan="5" class="text-end fw-bold">Tạm tính:</td>
                                    <td class="fw-bold" id="invSubtotal">0 đ</td>
                                    <td></td>
                                </tr>
                                <tr class="table-light">
                                    <td colspan="5" class="text-end fw-bold">VAT (<span id="vatPct">0</span>%):</td>
                                    <td class="fw-bold text-warning" id="invVatAmount">0 đ</td>
                                    <td></td>
                                </tr>
                                <tr class="table-light">
                                    <td colspan="5" class="text-end fw-bold fs-6">Tổng cộng:</td>
                                    <td class="fw-bold text-success fs-6" id="invGrandTotal">0 đ</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Huỷ</button>
                <button type="button" class="btn btn-primary" id="btnSaveInvoice">
                    <i class="fas fa-save me-1"></i>Tạo hoá đơn
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ============ MODAL THU TIỀN ============ -->
<div class="modal fade" id="modalPay" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">
                    <i class="fas fa-money-bill-wave me-2"></i>Ghi nhận thanh toán
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="formPay">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="invoice_id" id="payInvoiceId">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Số hoá đơn</label>
                        <input type="text" id="payInvoiceNo" class="form-control bg-light" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Ngày thu <span class="text-danger">*</span></label>
                        <input type="date" name="payment_date" class="form-control"
                               value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Số tiền thu <span class="text-danger">*</span></label>
                        <input type="number" name="amount" id="payAmount"
                               class="form-control" placeholder="0" min="1" required>
                        <div class="form-text text-danger" id="payDebtInfo"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Hình thức</label>
                        <select name="payment_method" class="form-select">
                            <option value="cash">Tiền mặt</option>
                            <option value="transfer">Chuyển khoản</option>
                            <option value="check">Séc</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Ghi chú</label>
                        <input type="text" name="note" class="form-control" placeholder="Số chứng từ, ghi chú...">
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Huỷ</button>
                <button type="button" class="btn btn-success" id="btnSavePay">
                    <i class="fas fa-save me-1"></i>Lưu
                </button>
            </div>
        </div>
    </div>
</div>

<script>
const INV_PRODUCTS = <?= json_encode($productList) ?>;
let invRowIdx = 0;

function makeInvRow(idx, pc={}) {
    const opts = INV_PRODUCTS.map(p =>
        `<option value="${p.id}"
            data-desc="${p.description}" data-unit="${p.unit}" data-price="${p.unit_price}"
            ${pc.product_code_id == p.id ? 'selected' : ''}>
            [${p.product_code}] ${p.description}
        </option>`
    ).join('');
    return `
    <tr data-idx="${idx}">
        <td><select name="items[${idx}][product_code_id]" class="form-select form-select-sm sel-inv-product" required>
            <option value="">-- Chọn SP --</option>${opts}</select></td>
        <td><input type="text" name="items[${idx}][description]"
                   class="form-control form-control-sm inp-inv-desc" value="${pc.description||''}" readonly></td>
        <td><input type="text" name="items[${idx}][unit]"
                   class="form-control form-control-sm inp-inv-unit" value="${pc.unit||''}" readonly></td>
        <td><input type="number" name="items[${idx}][quantity]"
                   class="form-control form-control-sm inp-inv-qty" min="1" value="${pc.quantity||0}" required></td>
        <td><input type="number" name="items[${idx}][unit_price]"
                   class="form-control form-control-sm inp-inv-price" min="0" value="${pc.unit_price||0}"></td>
        <td><input type="number" name="items[${idx}][total_price]"
                   class="form-control form-control-sm inp-inv-total fw-bold text-success" readonly value="${pc.total_price||0}"></td>
        <td><button type="button" class="btn btn-sm btn-outline-danger btn-rm-inv"><i class="fas fa-times"></i></button></td>
    </tr>`;
}

function addInvRow(pc={}) {
    document.getElementById('invItemBody').insertAdjacentHTML('beforeend', makeInvRow(invRowIdx++, pc));
    calcInvRow(document.querySelector(`#invItemBody tr:last-child`));
}

document.getElementById('modalInvoice').addEventListener('show.bs.modal', () => {
    document.getElementById('invItemBody').innerHTML = '';
    invRowIdx = 0;
    addInvRow();
    updateInvTotals();
});

document.getElementById('btnAddInvRow').addEventListener('click', () => addInvRow());

// Event delegation
document.getElementById('invItemBody').addEventListener('change', function(e) {
    const row = e.target.closest('tr'); if (!row) return;
    if (e.target.classList.contains('sel-inv-product')) {
        const opt = e.target.options[e.target.selectedIndex];
        row.querySelector('.inp-inv-desc').value  = opt.dataset.desc  || '';
        row.querySelector('.inp-inv-unit').value  = opt.dataset.unit  || '';
        row.querySelector('.inp-inv-price').value = opt.dataset.price || 0;
        calcInvRow(row);
    }
    if (e.target.classList.contains('inp-inv-qty') ||
        e.target.classList.contains('inp-inv-price')) calcInvRow(row);
});
document.getElementById('invItemBody').addEventListener('input', function(e) {
    const row = e.target.closest('tr'); if (!row) return;
    if (e.target.classList.contains('inp-inv-qty') ||
        e.target.classList.contains('inp-inv-price')) calcInvRow(row);
});
document.getElementById('invItemBody').addEventListener('click', function(e) {
    if (e.target.closest('.btn-rm-inv')) {
        if (document.querySelectorAll('#invItemBody tr').length <= 1) { alert('Cần ít nhất 1 dòng!'); return; }
        e.target.closest('tr').remove(); updateInvTotals();
    }
});

function calcInvRow(row) {
    const qty   = parseFloat(row.querySelector('.inp-inv-qty').value)   || 0;
    const price = parseFloat(row.querySelector('.inp-inv-price').value) || 0;
    row.querySelector('.inp-inv-total').value = Math.round(qty * price);
    updateInvTotals();
}

function updateInvTotals() {
    let sub = 0;
    document.querySelectorAll('.inp-inv-total').forEach(el => sub += parseFloat(el.value)||0);
    const vat  = (parseFloat(document.getElementById('invVat').value) || 0) / 100;
    const vatAmt = Math.round(sub * vat);
    document.getElementById('invSubtotal').textContent    = sub.toLocaleString('vi-VN') + ' đ';
    document.getElementById('vatPct').textContent         = document.getElementById('invVat').value || 0;
    document.getElementById('invVatAmount').textContent   = vatAmt.toLocaleString('vi-VN') + ' đ';
    document.getElementById('invGrandTotal').textContent  = (sub + vatAmt).toLocaleString('vi-VN') + ' đ';
}
document.getElementById('invVat').addEventListener('input', updateInvTotals);

// Import từ biên bản
const btnImport = document.getElementById('btnImportDelivery');
if (btnImport) {
    btnImport.addEventListener('click', () => {
        const sel = document.getElementById('selDelivery');
        const id  = sel.value; if (!id) return;
        const custId = sel.options[sel.selectedIndex].dataset.custid;
        // Set customer
        const custSel = document.getElementById('invCustomer');
        if (custId) custSel.value = custId;

        fetch(`/erp/api/invoice/get_delivery_items.php?id=${id}`)
        .then(r => r.json())
        .then(res => {
            if (res.ok) {
                document.getElementById('invItemBody').innerHTML = '';
                invRowIdx = 0;
                res.items.forEach(it => addInvRow(it));
                // Ghi nhớ delivery_id để link
                document.getElementById('formInvoice').dataset.deliveryId = id;
            } else alert('Lỗi: ' + res.msg);
        });
    });
}

// Lưu hoá đơn
document.getElementById('btnSaveInvoice').addEventListener('click', () => {
    const form = document.getElementById('formInvoice');
    if (!form.checkValidity()) { form.reportValidity(); return; }

    let valid = false;
    document.querySelectorAll('#invItemBody tr').forEach(r => {
        if (r.querySelector('.sel-inv-product').value &&
            parseFloat(r.querySelector('.inp-inv-qty').value) > 0) valid = true;
    });
    if (!valid) { alert('Cần ít nhất 1 dòng sản phẩm hợp lệ!'); return; }

    const btn = document.getElementById('btnSaveInvoice');
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Đang lưu...';

    const fd = new FormData(form);
    if (form.dataset.deliveryId) fd.append('delivery_id', form.dataset.deliveryId);

    fetch('/erp/api/invoice/save_invoice.php', { method:'POST', body: fd })
    .then(r => r.json())
    .then(res => {
        if (res.ok) { bootstrap.Modal.getInstance(document.getElementById('modalInvoice')).hide(); location.reload(); }
        else alert('Lỗi: ' + res.msg);
    })
    .finally(() => { btn.disabled=false; btn.innerHTML='<i class="fas fa-save me-1"></i>Tạo hoá đơn'; });
});

// Thu tiền
document.querySelectorAll('.btn-pay').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('payInvoiceId').value  = btn.dataset.id;
        document.getElementById('payInvoiceNo').value  = btn.dataset.no;
        document.getElementById('payAmount').value     = btn.dataset.debt;
        document.getElementById('payDebtInfo').textContent = 'Còn nợ: ' + parseInt(btn.dataset.debt).toLocaleString('vi-VN') + ' đ';
        new bootstrap.Modal(document.getElementById('modalPay')).show();
    });
});

document.getElementById('btnSavePay').addEventListener('click', () => {
    const form = document.getElementById('formPay');
    if (!form.checkValidity()) { form.reportValidity(); return; }
    const btn = document.getElementById('btnSavePay');
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Đang lưu...';
    fetch('/erp/api/invoice/save_payment.php', { method:'POST', body: new FormData(form) })
    .then(r => r.json())
    .then(res => {
        if (res.ok) { bootstrap.Modal.getInstance(document.getElementById('modalPay')).hide(); location.reload(); }
        else alert('Lỗi: ' + res.msg);
    })
    .finally(() => { btn.disabled=false; btn.innerHTML='<i class="fas fa-save me-1"></i>Lưu'; });
});
</script>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/footer.php'; ?>