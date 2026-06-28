<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';
requireLogin();
requireRole('director','accountant','manager','warehouse','production','employee');

$pdo = getDBConnection();
$user = currentUser();
$canViewAll = hasRole('director', 'accountant', 'manager');
$canApprove = hasRole('director', 'accountant');
$canReject = hasRole('director');
$filterMonth = preg_match('/^\d{4}-\d{2}$/', $_GET['month'] ?? '') ? $_GET['month'] : date('Y-m');
$filterCategory = (int)($_GET['category_id'] ?? 0);
$filterStatus = trim($_GET['status'] ?? '');
$monthStart = $filterMonth . '-01';
$monthEnd = date('Y-m-t', strtotime($monthStart));

$baseWhere = ['er.expense_date BETWEEN ? AND ?'];
$baseParams = [$monthStart, $monthEnd];
if (!$canViewAll) {
    $baseWhere[] = 'er.requested_by = ?';
    $baseParams[] = $user['id'];
}
if ($filterCategory > 0) {
    $baseWhere[] = 'er.category_id = ?';
    $baseParams[] = $filterCategory;
}

$listWhere = $baseWhere;
$listParams = $baseParams;
if ($filterStatus !== '') {
    $listWhere[] = 'er.status = ?';
    $listParams[] = $filterStatus;
}

$expensesStmt = $pdo->prepare("SELECT er.*, ec.category_name,
        ru.full_name AS requested_name,
        au.full_name AS approved_name,
        COALESCE(SUM(ep.amount), 0) AS paid_amount
    FROM expense_requests er
    JOIN expense_categories ec ON ec.id = er.category_id
    JOIN users ru ON ru.id = er.requested_by
    LEFT JOIN users au ON au.id = er.approved_by
    LEFT JOIN expense_payments ep ON ep.expense_id = er.id
    WHERE " . implode(' AND ', $listWhere) . "
    GROUP BY er.id
    ORDER BY er.expense_date DESC, er.id DESC");
$expensesStmt->execute($listParams);
$expenses = $expensesStmt->fetchAll(PDO::FETCH_ASSOC);

$statsStmt = $pdo->prepare("SELECT
        COALESCE(SUM(amount), 0) AS total_amount,
        COALESCE(SUM(CASE WHEN status = 'approved' THEN amount ELSE 0 END), 0) AS approved_amount,
        COALESCE(SUM(CASE WHEN status = 'submitted' THEN amount ELSE 0 END), 0) AS submitted_amount,
        COALESCE(SUM(CASE WHEN status = 'rejected' THEN amount ELSE 0 END), 0) AS rejected_amount
    FROM expense_requests er
    WHERE " . implode(' AND ', $baseWhere));
$statsStmt->execute($baseParams);
$stats = $statsStmt->fetch() ?: ['total_amount' => 0, 'approved_amount' => 0, 'submitted_amount' => 0, 'rejected_amount' => 0];

$categories = $pdo->query("SELECT id, category_name FROM expense_categories WHERE is_active = 1 ORDER BY category_name")
    ->fetchAll(PDO::FETCH_ASSOC);

$paymentsByExpense = [];
if ($expenses) {
    $expenseIds = array_column($expenses, 'id');
    $placeholders = implode(',', array_fill(0, count($expenseIds), '?'));
    $paymentStmt = $pdo->prepare("SELECT ep.*, u.full_name AS paid_by_name
        FROM expense_payments ep
        LEFT JOIN users u ON u.id = ep.paid_by
        WHERE ep.expense_id IN ($placeholders)
        ORDER BY ep.payment_date DESC, ep.id DESC");
    $paymentStmt->execute($expenseIds);
    foreach ($paymentStmt->fetchAll(PDO::FETCH_ASSOC) as $payment) {
        $paymentsByExpense[$payment['expense_id']][] = $payment;
    }
}

$expensesJson = [];
foreach ($expenses as $expense) {
    $expensesJson[$expense['id']] = $expense;
}

$csrf = generateCSRF();
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/sidebar.php';
?>
<div class="main-content">
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="fas fa-file-invoice-dollar me-2 text-primary"></i>Quản lý chi phí hành chính</h4>
            <p class="text-muted mb-0">Theo dõi đề xuất chi phí, hoá đơn và tình trạng phê duyệt</p>
        </div>
        <button class="btn btn-primary" id="btnCreateExpense">
            <i class="fas fa-plus me-1"></i> Tạo đề xuất
        </button>
    </div>

    <?php showFlash(); ?>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2">
            <form class="row g-2 align-items-center" method="GET">
                <div class="col-md-2">
                    <input type="month" name="month" class="form-control form-control-sm" value="<?= htmlspecialchars($filterMonth) ?>">
                </div>
                <div class="col-md-3">
                    <select name="category_id" class="form-select form-select-sm">
                        <option value="">-- Loại chi phí --</option>
                        <?php foreach ($categories as $category): ?>
                        <option value="<?= $category['id'] ?>" <?= $filterCategory === (int)$category['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($category['category_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="status" class="form-select form-select-sm">
                        <option value="">-- Trạng thái --</option>
                        <option value="draft" <?= $filterStatus === 'draft' ? 'selected' : '' ?>>Nháp</option>
                        <option value="submitted" <?= $filterStatus === 'submitted' ? 'selected' : '' ?>>Chờ duyệt</option>
                        <option value="approved" <?= $filterStatus === 'approved' ? 'selected' : '' ?>>Đã duyệt</option>
                        <option value="rejected" <?= $filterStatus === 'rejected' ? 'selected' : '' ?>>Từ chối</option>
                    </select>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-filter me-1"></i>Lọc</button>
                    <a href="?" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small mb-1">Tổng tháng</div>
                    <h4 class="mb-0 text-primary"><?= number_format((float)$stats['total_amount'], 0, ',', '.') ?> ₫</h4>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small mb-1">Đã duyệt</div>
                    <h4 class="mb-0 text-success"><?= number_format((float)$stats['approved_amount'], 0, ',', '.') ?> ₫</h4>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small mb-1">Chờ duyệt</div>
                    <h4 class="mb-0 text-warning"><?= number_format((float)$stats['submitted_amount'], 0, ',', '.') ?> ₫</h4>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small mb-1">Từ chối</div>
                    <h4 class="mb-0 text-danger"><?= number_format((float)$stats['rejected_amount'], 0, ',', '.') ?> ₫</h4>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th width="150">Số phiếu</th>
                            <th width="110">Ngày</th>
                            <th width="180">Loại chi phí</th>
                            <th>Mục đích</th>
                            <th width="140" class="text-end">Số tiền</th>
                            <th width="90" class="text-center">Có HĐ</th>
                            <th width="120">Trạng thái</th>
                            <th width="210">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$expenses): ?>
                        <tr><td colspan="8" class="text-center text-muted py-4">Chưa có đề xuất chi phí nào</td></tr>
                    <?php else: ?>
                        <?php foreach ($expenses as $expense): ?>
                        <?php
                        $statusMap = [
                            'draft' => ['secondary', 'Nháp'],
                            'submitted' => ['warning text-dark', 'Chờ duyệt'],
                            'approved' => ['success', 'Đã duyệt'],
                            'rejected' => ['danger', 'Từ chối'],
                        ];
                        [$statusClass, $statusLabel] = $statusMap[$expense['status']] ?? ['secondary', $expense['status']];
                        $canModify = $canViewAll || (int)$expense['requested_by'] === (int)$user['id'];
                        ?>
                        <tr>
                            <td class="fw-semibold text-primary"><?= htmlspecialchars($expense['request_no']) ?></td>
                            <td><?= formatDate($expense['expense_date']) ?></td>
                            <td><?= htmlspecialchars($expense['category_name']) ?></td>
                            <td>
                                <div class="fw-semibold"><?= htmlspecialchars($expense['purpose']) ?></div>
                                <div class="small text-muted">Người đề xuất: <?= htmlspecialchars($expense['requested_name']) ?></div>
                            </td>
                            <td class="text-end fw-bold"><?= number_format((float)$expense['amount'], 0, ',', '.') ?></td>
                            <td class="text-center"><?= (int)$expense['has_invoice'] === 1 ? '<span class="badge bg-success">Có</span>' : '<span class="badge bg-light text-dark">Không</span>' ?></td>
                            <td><span class="badge bg-<?= $statusClass ?>"><?= $statusLabel ?></span></td>
                            <td>
                                <button class="btn btn-xs btn-outline-primary btn-view-expense" data-id="<?= $expense['id'] ?>" title="Xem chi tiết">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <?php if ($expense['status'] === 'draft' && $canModify): ?>
                                <button class="btn btn-xs btn-outline-warning ms-1 btn-edit-expense" data-id="<?= $expense['id'] ?>" title="Sửa">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="btn btn-xs btn-outline-success ms-1 btn-submit-expense" data-id="<?= $expense['id'] ?>" title="Gửi duyệt">
                                    <i class="fas fa-paper-plane"></i>
                                </button>
                                <button class="btn btn-xs btn-outline-danger ms-1 btn-delete-expense" data-id="<?= $expense['id'] ?>" title="Xoá">
                                    <i class="fas fa-trash"></i>
                                </button>
                                <?php endif; ?>
                                <?php if ($expense['status'] === 'submitted' && $canApprove): ?>
                                <button class="btn btn-xs btn-outline-success ms-1 btn-approve-expense" data-id="<?= $expense['id'] ?>" title="Duyệt">
                                    <i class="fas fa-check"></i>
                                </button>
                                <?php endif; ?>
                                <?php if ($expense['status'] === 'submitted' && $canReject): ?>
                                <button class="btn btn-xs btn-outline-danger ms-1 btn-reject-expense" data-id="<?= $expense['id'] ?>" title="Từ chối">
                                    <i class="fas fa-times"></i>
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
    </div>
</div>
</div>

<div class="modal fade" id="modalExpense" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="expenseModalTitle"><i class="fas fa-file-invoice-dollar me-2"></i>Tạo đề xuất chi phí</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="formExpense">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="id" id="expenseId" value="">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Loại chi phí <span class="text-danger">*</span></label>
                            <select name="category_id" id="expenseCategory" class="form-select" required>
                                <option value="">-- Chọn loại chi phí --</option>
                                <?php foreach ($categories as $category): ?>
                                <option value="<?= $category['id'] ?>"><?= htmlspecialchars($category['category_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Ngày chi phí <span class="text-danger">*</span></label>
                            <input type="date" name="expense_date" id="expenseDate" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Số tiền <span class="text-danger">*</span></label>
                            <input type="number" name="amount" id="expenseAmount" class="form-control text-end" min="0" step="0.01" required>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label fw-semibold">Mục đích <span class="text-danger">*</span></label>
                            <textarea name="purpose" id="expensePurpose" class="form-control" rows="2" required></textarea>
                        </div>
                        <div class="col-md-4">
                            <div class="form-check mt-4">
                                <input class="form-check-input" type="checkbox" name="has_invoice" id="expenseHasInvoice" value="1">
                                <label class="form-check-label fw-semibold" for="expenseHasInvoice">Có hoá đơn</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Hình thức thanh toán</label>
                            <select name="payment_method" id="expensePaymentMethod" class="form-select">
                                <option value="cash">Tiền mặt</option>
                                <option value="bank_transfer">Chuyển khoản</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Ghi chú</label>
                            <input type="text" name="note" id="expenseNote" class="form-control">
                        </div>
                    </div>
                    <div class="row g-3 mt-1" id="invoiceFields">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Số hoá đơn</label>
                            <input type="text" name="invoice_no" id="expenseInvoiceNo" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Ngày hoá đơn</label>
                            <input type="date" name="invoice_date" id="expenseInvoiceDate" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Tên công ty</label>
                            <input type="text" name="invoice_company" id="expenseInvoiceCompany" class="form-control">
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Huỷ</button>
                <button type="button" class="btn btn-primary" id="btnSaveExpense"><i class="fas fa-save me-1"></i>Lưu đề xuất</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalExpenseDetail" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title"><i class="fas fa-eye me-2"></i>Chi tiết đề xuất chi phí</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="expenseDetailBody"></div>
        </div>
    </div>
</div>

<script>
const csrfExpense = '<?= $csrf ?>';
const expensesData = <?= json_encode($expensesJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const paymentsByExpense = <?= json_encode($paymentsByExpense, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const expenseModal = new bootstrap.Modal(document.getElementById('modalExpense'));
const expenseDetailModal = new bootstrap.Modal(document.getElementById('modalExpenseDetail'));

function escHtml(value) {
    return String(value || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function formatMoney(value) {
    return Number(value || 0).toLocaleString('vi-VN') + ' ₫';
}

function statusBadge(status) {
    const map = {
        draft: '<span class="badge bg-secondary">Nháp</span>',
        submitted: '<span class="badge bg-warning text-dark">Chờ duyệt</span>',
        approved: '<span class="badge bg-success">Đã duyệt</span>',
        rejected: '<span class="badge bg-danger">Từ chối</span>'
    };
    return map[status] || escHtml(status);
}

function toggleInvoiceFields() {
    const visible = document.getElementById('expenseHasInvoice').checked;
    document.getElementById('invoiceFields').classList.toggle('d-none', !visible);
}

function resetExpenseForm() {
    document.getElementById('formExpense').reset();
    document.getElementById('expenseId').value = '';
    document.getElementById('expenseDate').value = '<?= date('Y-m-d') ?>';
    document.getElementById('expensePaymentMethod').value = 'cash';
    document.getElementById('expenseModalTitle').innerHTML = '<i class="fas fa-file-invoice-dollar me-2"></i>Tạo đề xuất chi phí';
    toggleInvoiceFields();
}

async function submitExpenseAction(action, payload = {}) {
    const fd = new FormData();
    fd.append('csrf_token', csrfExpense);
    fd.append('action', action);
    Object.entries(payload).forEach(([key, value]) => fd.append(key, value));
    const response = await fetch('/erp/api/admin/save_expense.php', { method: 'POST', body: fd });
    const data = await response.json();
    if (data.ok) {
        location.reload();
        return;
    }
    alert(data.msg || 'Có lỗi xảy ra');
}

document.getElementById('btnCreateExpense').addEventListener('click', () => {
    resetExpenseForm();
    expenseModal.show();
});

document.getElementById('expenseHasInvoice').addEventListener('change', toggleInvoiceFields);
toggleInvoiceFields();

document.getElementById('btnSaveExpense').addEventListener('click', async () => {
    const form = document.getElementById('formExpense');
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }
    const fd = new FormData(form);
    fd.append('action', document.getElementById('expenseId').value ? 'edit' : 'add');
    const response = await fetch('/erp/api/admin/save_expense.php', { method: 'POST', body: fd });
    const data = await response.json();
    if (data.ok) {
        location.reload();
        return;
    }
    alert(data.msg || 'Không thể lưu đề xuất');
});

document.querySelectorAll('.btn-edit-expense').forEach(btn => {
    btn.addEventListener('click', () => {
        const row = expensesData[btn.dataset.id];
        if (!row) return;
        resetExpenseForm();
        document.getElementById('expenseModalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Sửa đề xuất chi phí';
        document.getElementById('expenseId').value = row.id;
        document.getElementById('expenseCategory').value = row.category_id;
        document.getElementById('expenseDate').value = row.expense_date;
        document.getElementById('expenseAmount').value = row.amount;
        document.getElementById('expensePurpose').value = row.purpose || '';
        document.getElementById('expenseHasInvoice').checked = Number(row.has_invoice) === 1;
        document.getElementById('expenseInvoiceNo').value = row.invoice_no || '';
        document.getElementById('expenseInvoiceDate').value = row.invoice_date || '';
        document.getElementById('expenseInvoiceCompany').value = row.invoice_company || '';
        document.getElementById('expensePaymentMethod').value = row.payment_method || 'cash';
        document.getElementById('expenseNote').value = row.note || '';
        toggleInvoiceFields();
        expenseModal.show();
    });
});

document.querySelectorAll('.btn-view-expense').forEach(btn => {
    btn.addEventListener('click', () => {
        const row = expensesData[btn.dataset.id];
        if (!row) return;
        const payments = paymentsByExpense[row.id] || [];
        const paymentRows = payments.length
            ? payments.map((payment, index) => `<tr>
                <td>${index + 1}</td>
                <td>${new Date(payment.payment_date).toLocaleDateString('vi-VN')}</td>
                <td class="text-end fw-semibold">${formatMoney(payment.amount)}</td>
                <td>${payment.payment_method === 'bank_transfer' ? 'Chuyển khoản' : 'Tiền mặt'}</td>
                <td>${escHtml(payment.paid_by_name || '—')}</td>
                <td>${escHtml(payment.note || '—')}</td>
            </tr>`).join('')
            : '<tr><td colspan="6" class="text-center text-muted py-3">Chưa có lịch sử thanh toán</td></tr>';

        document.getElementById('expenseDetailBody').innerHTML = `
            <div class="row g-3 mb-3">
                <div class="col-md-6"><strong>Số phiếu:</strong> ${escHtml(row.request_no)}</div>
                <div class="col-md-6"><strong>Trạng thái:</strong> ${statusBadge(row.status)}</div>
                <div class="col-md-6"><strong>Ngày chi phí:</strong> ${new Date(row.expense_date).toLocaleDateString('vi-VN')}</div>
                <div class="col-md-6"><strong>Loại chi phí:</strong> ${escHtml(row.category_name || '')}</div>
                <div class="col-md-6"><strong>Người đề xuất:</strong> ${escHtml(row.requested_name || '')}</div>
                <div class="col-md-6"><strong>Số tiền:</strong> <span class="fw-bold text-primary">${formatMoney(row.amount)}</span></div>
                <div class="col-md-12"><strong>Mục đích:</strong><div class="mt-1">${escHtml(row.purpose || '')}</div></div>
                <div class="col-md-6"><strong>Hoá đơn:</strong> ${Number(row.has_invoice) === 1 ? 'Có' : 'Không'}</div>
                <div class="col-md-6"><strong>Hình thức thanh toán:</strong> ${row.payment_method === 'bank_transfer' ? 'Chuyển khoản' : 'Tiền mặt'}</div>
                ${Number(row.has_invoice) === 1 ? `
                <div class="col-md-4"><strong>Số HĐ:</strong> ${escHtml(row.invoice_no || '—')}</div>
                <div class="col-md-4"><strong>Ngày HĐ:</strong> ${row.invoice_date ? new Date(row.invoice_date).toLocaleDateString('vi-VN') : '—'}</div>
                <div class="col-md-4"><strong>Công ty:</strong> ${escHtml(row.invoice_company || '—')}</div>` : ''}
                <div class="col-md-6"><strong>Người duyệt:</strong> ${escHtml(row.approved_name || '—')}</div>
                <div class="col-md-6"><strong>Thời điểm duyệt:</strong> ${row.approved_at ? new Date(row.approved_at.replace(' ', 'T')).toLocaleString('vi-VN') : '—'}</div>
                <div class="col-md-12"><strong>Lý do từ chối:</strong> ${escHtml(row.reject_reason || '—')}</div>
                <div class="col-md-12"><strong>Ghi chú:</strong> ${escHtml(row.note || '—')}</div>
            </div>
            <h6 class="fw-bold mb-2">Lịch sử thanh toán</h6>
            <div class="table-responsive">
                <table class="table table-bordered table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th width="50">#</th>
                            <th width="110">Ngày</th>
                            <th width="140" class="text-end">Số tiền</th>
                            <th width="130">Hình thức</th>
                            <th width="160">Người chi</th>
                            <th>Ghi chú</th>
                        </tr>
                    </thead>
                    <tbody>${paymentRows}</tbody>
                </table>
            </div>`;
        expenseDetailModal.show();
    });
});

document.querySelectorAll('.btn-submit-expense').forEach(btn => {
    btn.addEventListener('click', () => {
        if (confirm('Gửi đề xuất này để chờ duyệt?')) {
            submitExpenseAction('submit', { id: btn.dataset.id });
        }
    });
});

document.querySelectorAll('.btn-delete-expense').forEach(btn => {
    btn.addEventListener('click', () => {
        if (confirm('Xoá đề xuất chi phí này?')) {
            submitExpenseAction('delete', { id: btn.dataset.id });
        }
    });
});

document.querySelectorAll('.btn-approve-expense').forEach(btn => {
    btn.addEventListener('click', () => {
        if (confirm('Duyệt đề xuất chi phí này?')) {
            submitExpenseAction('approve', { id: btn.dataset.id });
        }
    });
});

document.querySelectorAll('.btn-reject-expense').forEach(btn => {
    btn.addEventListener('click', () => {
        const reason = prompt('Nhập lý do từ chối:', '');
        if (reason === null) return;
        submitExpenseAction('reject', { id: btn.dataset.id, reject_reason: reason });
    });
});
</script>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/footer.php'; ?>
