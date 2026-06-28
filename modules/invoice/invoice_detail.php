<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';
requireLogin();
requireRole('director','accountant','manager');

$pdo = getDBConnection();
$id  = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: index.php'); exit; }

$inv = $pdo->prepare("
    SELECT i.*, c.customer_name, c.customer_code, c.address, c.phone,
           u.full_name AS created_by_name
    FROM invoices i
    LEFT JOIN customers c ON i.customer_id = c.id
    LEFT JOIN users u     ON i.created_by  = u.id
    WHERE i.id = ?
");
$inv->execute([$id]);
$inv = $inv->fetch(PDO::FETCH_ASSOC);
if (!$inv) { header('Location: index.php'); exit; }

$items = $pdo->prepare("
    SELECT ii.*, pc.product_code
    FROM invoice_items ii
    JOIN product_codes pc ON ii.product_code_id = pc.id
    WHERE ii.invoice_id = ?
    ORDER BY ii.id
");
$items->execute([$id]);
$items = $items->fetchAll(PDO::FETCH_ASSOC);

// Lịch sử thanh toán từ debt_payments
$payments = $pdo->prepare("
    SELECT dp.*, u.full_name AS created_by_name
    FROM debt_payments dp
    LEFT JOIN users u ON dp.created_by = u.id
    WHERE dp.invoice_id = ?
    ORDER BY dp.payment_date DESC
");
$payments->execute([$id]);
$payments = $payments->fetchAll(PDO::FETCH_ASSOC);

$totalPaid = array_sum(array_column($payments, 'amount'));
$debt      = $inv['total_amount'] - $totalPaid;

// debt_tracking
$dt = $pdo->prepare("SELECT * FROM debt_tracking WHERE invoice_id = ?");
$dt->execute([$id]);
$dt = $dt->fetch(PDO::FETCH_ASSOC);

$csrf = generateCSRF();
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/sidebar.php';
?>
<div class="main-content">
<?php include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/warehouse_nav.php'; ?>
<div class="container-fluid py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <a href="index.php" class="btn btn-sm btn-outline-secondary me-2">
                <i class="fas fa-arrow-left"></i>
            </a>
            <span class="fs-5 fw-bold">
                <i class="fas fa-file-invoice me-2 text-primary"></i>
                <?= htmlspecialchars($inv['invoice_no']) ?>
            </span>
        </div>
        <div class="d-flex gap-2">
            <a href="/erp/api/invoice/print_invoice.php?id=<?= $id ?>"
               target="_blank" class="btn btn-outline-secondary">
                <i class="fas fa-print me-1"></i>In hoá đơn
            </a>
            <?php if ($debt > 0): ?>
            <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalPay">
                <i class="fas fa-money-bill-wave me-1"></i>Thu tiền
            </button>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-3">
        <!-- Thông tin HĐ -->
        <div class="col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-bold">
                    <i class="fas fa-info-circle me-2 text-primary"></i>Thông tin hoá đơn
                </div>
                <div class="card-body">
                    <table class="table table-sm mb-0">
                        <tr><td class="text-muted w-40">Số HĐ</td>
                            <td class="fw-bold text-primary"><?= htmlspecialchars($inv['invoice_no']) ?></td></tr>
                        <tr><td class="text-muted">Ngày HĐ</td>
                            <td><?= date('d/m/Y', strtotime($inv['invoice_date'])) ?></td></tr>
                        <tr><td class="text-muted">Hạn TT</td>
                            <td><?= $inv['due_date'] ? date('d/m/Y', strtotime($inv['due_date'])) : '—' ?></td></tr>
                        <tr><td class="text-muted">Trạng thái</td>
                            <td><?php
                                $st = ['draft'=>['secondary','Nháp'],'confirmed'=>['success','Đã xác nhận'],'cancelled'=>['danger','Huỷ']];
                                $s  = $st[$inv['status']] ?? ['secondary','?'];
                                echo "<span class='badge bg-{$s[0]}'>{$s[1]}</span>";
                            ?></td></tr>
                        <tr><td class="text-muted">Người tạo</td>
                            <td><?= htmlspecialchars($inv['created_by_name'] ?? '—') ?></td></tr>
                        <tr><td class="text-muted">Ghi chú</td>
                            <td><?= htmlspecialchars($inv['note'] ?? '—') ?></td></tr>
                    </table>
                </div>
            </div>
        </div>

        <!-- Thông tin KH + Công nợ -->
        <div class="col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-bold">
                    <i class="fas fa-user me-2 text-success"></i>Khách hàng & Công nợ
                </div>
                <div class="card-body">
                    <table class="table table-sm mb-0">
                        <tr><td class="text-muted w-40">Tên KH</td>
                            <td class="fw-bold"><?= htmlspecialchars($inv['customer_name'] ?? '—') ?></td></tr>
                        <tr><td class="text-muted">Mã KH</td>
                            <td><?= htmlspecialchars($inv['customer_code'] ?? '—') ?></td></tr>
                        <tr><td class="text-muted">Địa chỉ</td>
                            <td><?= htmlspecialchars($inv['address'] ?? '—') ?></td></tr>
                        <tr><td class="text-muted">SĐT</td>
                            <td><?= htmlspecialchars($inv['phone'] ?? '—') ?></td></tr>
                        <tr><td class="text-muted">Tổng tiền</td>
                            <td class="fw-bold"><?= number_format($inv['total_amount']) ?> đ</td></tr>
                        <tr><td class="text-muted">Đã thu</td>
                            <td class="text-success fw-bold"><?= number_format($totalPaid) ?> đ</td></tr>
                        <tr><td class="text-muted">Còn nợ</td>
                            <td class="fw-bold <?= $debt > 0 ? 'text-danger' : 'text-success' ?>">
                                <?= number_format($debt) ?> đ
                            </td></tr>
                    </table>
                </div>
            </div>
        </div>

        <!-- Chi tiết SP -->
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-bold">
                    <i class="fas fa-list me-2 text-warning"></i>Chi tiết sản phẩm
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-dark">
                                <tr>
                                    <th>#</th>
                                    <th>Mã SP</th>
                                    <th>Mô tả</th>
                                    <th>ĐVT</th>
                                    <th class="text-end">SL</th>
                                    <th class="text-end">Đơn giá</th>
                                    <th class="text-end">Thành tiền</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php $i=1; foreach ($items as $it): ?>
                            <tr>
                                <td class="text-muted"><?= $i++ ?></td>
                                <td><span class="badge bg-primary"><?= htmlspecialchars($it['product_code']) ?></span></td>
                                <td><?= htmlspecialchars($it['description']) ?></td>
                                <td><?= htmlspecialchars($it['unit']) ?></td>
                                <td class="text-end fw-bold"><?= number_format($it['quantity']) ?></td>
                                <td class="text-end"><?= number_format($it['unit_price']) ?> đ</td>
                                <td class="text-end fw-bold text-success"><?= number_format($it['total_price']) ?> đ</td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr>
                                    <td colspan="4" class="text-end fw-bold">Tổng cộng:</td>
                                    <td class="text-end fw-bold">
                                        <?= number_format(array_sum(array_column($items,'quantity'))) ?>
                                    </td>
                                    <td></td>
                                    <td class="text-end fw-bold text-success fs-6">
                                        <?= number_format($inv['total_amount']) ?> đ
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Lịch sử thanh toán -->
        <?php if (!empty($payments)): ?>
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-bold">
                    <i class="fas fa-history me-2 text-success"></i>Lịch sử thanh toán
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 small">
                            <thead class="table-dark">
                                <tr>
                                    <th>Ngày thu</th>
                                    <th class="text-end">Số tiền</th>
                                    <th>Hình thức</th>
                                    <th>Số CT</th>
                                    <th>Người thu</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($payments as $pay): ?>
                            <tr>
                                <td><?= date('d/m/Y', strtotime($pay['payment_date'])) ?></td>
                                <td class="text-end fw-bold text-success">
                                    <?= number_format($pay['amount']) ?> đ
                                </td>
                                <td>
                                    <?php
                                    $pm = ['cash'=>['secondary','Tiền mặt'],'transfer'=>['info','Chuyển khoản'],'other'=>['warning','Khác']];
                                    $m  = $pm[$pay['payment_method']] ?? ['secondary','?'];
                                    echo "<span class='badge bg-{$m[0]}'>{$m[1]}</span>";
                                    ?>
                                </td>
                                <td class="text-muted"><?= htmlspecialchars($pay['reference_no'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($pay['created_by_name'] ?? '—') ?></td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
</div>

<!-- Modal thu tiền -->
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
                    <input type="hidden" name="invoice_id" value="<?= $id ?>">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Ngày thu <span class="text-danger">*</span></label>
                        <input type="date" name="payment_date" class="form-control"
                               value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Số tiền thu <span class="text-danger">*</span></label>
                        <input type="number" name="amount" class="form-control"
                               value="<?= $debt ?>" min="1" max="<?= $debt ?>" required>
                        <div class="form-text text-danger">
                            Còn nợ: <?= number_format($debt) ?> đ
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Hình thức</label>
                        <select name="payment_method" class="form-select">
                            <option value="cash">Tiền mặt</option>
                            <option value="transfer">Chuyển khoản</option>
                            <option value="other">Khác</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Số chứng từ / Ghi chú</label>
                        <input type="text" name="note" class="form-control"
                               placeholder="Số chứng từ, diễn giải...">
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
document.getElementById('btnSavePay').addEventListener('click', () => {
    const form = document.getElementById('formPay');
    if (!form.checkValidity()) { form.reportValidity(); return; }
    const btn = document.getElementById('btnSavePay');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Đang lưu...';
    fetch('/erp/api/invoice/save_payment.php', { method:'POST', body: new FormData(form) })
    .then(r => r.json())
    .then(res => {
        if (res.ok) {
            bootstrap.Modal.getInstance(document.getElementById('modalPay')).hide();
            location.reload();
        } else { alert('Lỗi: ' + res.msg); }
    })
    .finally(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save me-1"></i>Lưu';
    });
});
</script>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/footer.php'; ?>