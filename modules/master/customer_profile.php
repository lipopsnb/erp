<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';
requireLogin();
requireRole('director','accountant','warehouse','production','manager');

$pdo = getDBConnection();
$id  = (int)($_GET['id'] ?? 0);
if (!$id) {
    header('Location: /erp/modules/master/customers.php');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
$stmt->execute([$id]);
$customer = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$customer) {
    setFlash('warning', 'Không tìm thấy khách hàng');
    header('Location: /erp/modules/master/customers.php');
    exit;
}

$currentStmt = $pdo->prepare("
    SELECT cp.*, pc.product_code, pc.description, pc.unit
    FROM customer_prices cp
    JOIN product_codes pc ON cp.product_code_id = pc.id
    LEFT JOIN customer_prices cp_newer
           ON cp_newer.customer_id = cp.customer_id
          AND cp_newer.product_code_id = cp.product_code_id
          AND cp_newer.effective_date <= CURDATE()
          AND (cp_newer.expired_date IS NULL OR cp_newer.expired_date >= CURDATE())
          AND (
               cp_newer.effective_date > cp.effective_date
               OR (cp_newer.effective_date = cp.effective_date AND cp_newer.id > cp.id)
          )
    WHERE cp.customer_id = ?
      AND cp.effective_date <= CURDATE()
      AND (cp.expired_date IS NULL OR cp.expired_date >= CURDATE())
      AND cp_newer.id IS NULL
    ORDER BY pc.product_code
");
$currentStmt->execute([$id]);
$currentPrices = $currentStmt->fetchAll(PDO::FETCH_ASSOC);

$historyStmt = $pdo->prepare("
    SELECT cp.*, pc.product_code, pc.description
    FROM customer_prices cp
    JOIN product_codes pc ON cp.product_code_id = pc.id
    WHERE cp.customer_id = ?
    ORDER BY pc.product_code, cp.effective_date DESC, cp.id DESC
");
$historyStmt->execute([$id]);
$priceHistory = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

$csrf = generateCSRF();
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/sidebar.php';
?>
<div class="main-content">
<div class="container-fluid py-4">
    <div class="mb-3">
        <a href="/erp/modules/master/customers.php" class="btn btn-link p-0 text-decoration-none">
            <i class="fas fa-arrow-left me-1"></i> Danh sách khách hàng
        </a>
    </div>

    <?php showFlash(); ?>

    <div class="row g-3">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="text-center mb-3">
                        <div class="rounded-circle bg-light d-inline-flex align-items-center justify-content-center mb-2" style="width:72px;height:72px;">
                            <i class="fas fa-building text-primary fs-3"></i>
                        </div>
                        <h5 class="mb-1"><?= htmlspecialchars($customer['customer_name']) ?></h5>
                        <span class="badge bg-secondary"><?= htmlspecialchars($customer['customer_code'] ?? '—') ?></span>
                    </div>
                    <hr>
                    <div class="small">
                        <div class="mb-2"><i class="fas fa-phone me-2 text-muted"></i><?= htmlspecialchars($customer['phone'] ?? '—') ?></div>
                        <div class="mb-2"><i class="fas fa-envelope me-2 text-muted"></i><?= htmlspecialchars($customer['email'] ?? '—') ?></div>
                        <div class="mb-2"><i class="fas fa-map-marker-alt me-2 text-muted"></i><?= htmlspecialchars($customer['address'] ?? '—') ?></div>
                        <div class="mb-2"><i class="fas fa-user me-2 text-muted"></i><?= htmlspecialchars($customer['contact_person'] ?? '—') ?></div>
                    </div>
                    <hr>
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span class="text-muted">Trạng thái</span>
                        <?= !empty($customer['is_active'])
                            ? '<span class="badge bg-success">Đang dùng</span>'
                            : '<span class="badge bg-secondary">Ngừng</span>' ?>
                    </div>
                    <?php if (hasRole('director','accountant','warehouse','manager')): ?>
                    <button class="btn btn-outline-warning btn-sm w-100" data-bs-toggle="modal" data-bs-target="#modalCustomer">
                        <i class="fas fa-edit me-1"></i>Sửa KH
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-md-9">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-0 pb-0">
                    <ul class="nav nav-tabs card-header-tabs" id="customerTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-info" type="button">Thông tin KH</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-products" type="button">Mã sản phẩm</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-prices" type="button">Bảng giá</button>
                        </li>
                    </ul>
                </div>
                <div class="card-body">
                    <div class="tab-content">
                        <div class="tab-pane fade show active" id="tab-info">
                            <div class="row g-3">
                                <div class="col-md-4"><strong>Mã KH:</strong> <?= htmlspecialchars($customer['customer_code'] ?? '—') ?></div>
                                <div class="col-md-8"><strong>Tên KH:</strong> <?= htmlspecialchars($customer['customer_name']) ?></div>
                                <div class="col-md-6"><strong>Địa chỉ:</strong> <?= htmlspecialchars($customer['address'] ?? '—') ?></div>
                                <div class="col-md-3"><strong>SĐT:</strong> <?= htmlspecialchars($customer['phone'] ?? '—') ?></div>
                                <div class="col-md-3"><strong>Email:</strong> <?= htmlspecialchars($customer['email'] ?? '—') ?></div>
                                <div class="col-md-4"><strong>Người liên hệ:</strong> <?= htmlspecialchars($customer['contact_person'] ?? '—') ?></div>
                                <div class="col-md-4"><strong>Trạng thái:</strong>
                                    <?= !empty($customer['is_active']) ? 'Đang dùng' : 'Ngừng' ?>
                                </div>
                                <div class="col-md-4"><strong>Ngày tạo:</strong>
                                    <?= !empty($customer['created_at']) ? date('d/m/Y H:i', strtotime($customer['created_at'])) : '—' ?>
                                </div>
                            </div>
                            <div class="mt-4 d-flex gap-2">
                                <?php if (hasRole('director','accountant','warehouse','manager')): ?>
                                <button class="btn btn-outline-warning btn-sm" data-bs-toggle="modal" data-bs-target="#modalCustomer">
                                    <i class="fas fa-edit me-1"></i>Sửa
                                </button>
                                <?php endif; ?>
                                <?php if (hasRole('director')): ?>
                                <button class="btn btn-outline-danger btn-sm" id="btnDeleteCustomer">
                                    <i class="fas fa-trash me-1"></i>Xoá KH
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="tab-pane fade" id="tab-products">
                            <?php if (hasRole('director','accountant','manager')): ?>
                            <div class="mb-3">
                                <button class="btn btn-primary" id="btnAddPrice" data-bs-toggle="modal" data-bs-target="#modalPrice">
                                    <i class="fas fa-plus me-1"></i>+ Thêm mã SP
                                </button>
                            </div>
                            <?php endif; ?>

                            <div class="table-responsive">
                                <table class="table table-hover align-middle shadow-sm">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Mã SP</th>
                                            <th>Mô tả</th>
                                            <th>Đơn vị</th>
                                            <th class="text-end">Đơn giá</th>
                                            <th>Ngày áp dụng</th>
                                            <th>Đến ngày</th>
                                            <?php if (hasRole('director','accountant','manager')): ?>
                                            <th width="120">Thao tác</th>
                                            <?php endif; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php if (empty($currentPrices)): ?>
                                        <tr><td colspan="<?= hasRole('director','accountant','manager') ? 7 : 6 ?>" class="text-center text-muted py-4">Chưa có giá hiện tại</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($currentPrices as $row): ?>
                                        <tr>
                                            <td><span class="badge bg-primary"><?= htmlspecialchars($row['product_code']) ?></span></td>
                                            <td><?= htmlspecialchars($row['description']) ?></td>
                                            <td><?= htmlspecialchars($row['unit'] ?? '—') ?></td>
                                            <td class="text-end fw-semibold"><?= number_format((float)$row['unit_price'], 0, ',', '.') ?> đ</td>
                                            <td><?= !empty($row['effective_date']) ? date('d/m/Y', strtotime($row['effective_date'])) : '—' ?></td>
                                            <td><?= !empty($row['expired_date']) ? date('d/m/Y', strtotime($row['expired_date'])) : '—' ?></td>
                                            <?php if (hasRole('director','accountant','manager')): ?>
                                            <td>
                                                <button class="btn btn-outline-warning btn-sm btn-edit-price"
                                                        data-id="<?= (int)$row['id'] ?>"
                                                        data-product-id="<?= (int)$row['product_code_id'] ?>"
                                                        data-product-code="<?= htmlspecialchars($row['product_code']) ?>"
                                                        data-description="<?= htmlspecialchars($row['description']) ?>"
                                                        data-unit="<?= htmlspecialchars($row['unit'] ?? '') ?>"
                                                        data-price="<?= htmlspecialchars($row['unit_price']) ?>"
                                                        data-effective-date="<?= htmlspecialchars($row['effective_date'] ?? '') ?>"
                                                        data-expired-date="<?= htmlspecialchars($row['expired_date'] ?? '') ?>">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="btn btn-outline-danger btn-sm btn-delete-price"
                                                        data-product-id="<?= (int)$row['product_code_id'] ?>"
                                                        data-product-name="<?= htmlspecialchars($row['product_code']) ?>">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </td>
                                            <?php endif; ?>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="tab-pane fade" id="tab-prices">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle shadow-sm">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Mã SP</th>
                                            <th>Mô tả</th>
                                            <th class="text-end">Đơn giá</th>
                                            <th>Ngày áp dụng</th>
                                            <th>Ngày hết hạn</th>
                                            <th>Trạng thái</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php if (empty($priceHistory)): ?>
                                        <tr><td colspan="6" class="text-center text-muted py-4">Chưa có lịch sử giá</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($priceHistory as $row): ?>
                                            <?php
                                            $today = date('Y-m-d');
                                            $isCurrent = !empty($row['effective_date']) && $row['effective_date'] <= $today
                                                && (empty($row['expired_date']) || $row['expired_date'] >= $today);
                                            ?>
                                        <tr>
                                            <td><span class="badge bg-primary"><?= htmlspecialchars($row['product_code']) ?></span></td>
                                            <td><?= htmlspecialchars($row['description']) ?></td>
                                            <td class="text-end"><?= number_format((float)$row['unit_price'], 0, ',', '.') ?> đ</td>
                                            <td><?= !empty($row['effective_date']) ? date('d/m/Y', strtotime($row['effective_date'])) : '—' ?></td>
                                            <td><?= !empty($row['expired_date']) ? date('d/m/Y', strtotime($row['expired_date'])) : '—' ?></td>
                                            <td><?= $isCurrent ? '<span class="badge bg-success">Hiện tại</span>' : '<span class="badge bg-secondary">Đã hết hạn</span>' ?></td>
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
        </div>
    </div>
</div>
</div>

<?php if (hasRole('director','accountant','warehouse','manager')): ?>
<div class="modal fade" id="modalCustomer" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Sửa khách hàng</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="formCustomer">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="id" value="<?= (int)$customer['id'] ?>">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Mã khách hàng</label>
                            <input type="text" name="customer_code" class="form-control text-uppercase" value="<?= htmlspecialchars($customer['customer_code'] ?? '') ?>">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label fw-semibold">Tên khách hàng <span class="text-danger">*</span></label>
                            <input type="text" name="customer_name" class="form-control" required value="<?= htmlspecialchars($customer['customer_name']) ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Địa chỉ</label>
                            <input type="text" name="address" class="form-control" value="<?= htmlspecialchars($customer['address'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Người liên hệ</label>
                            <input type="text" name="contact_person" class="form-control" value="<?= htmlspecialchars($customer['contact_person'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Điện thoại</label>
                            <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($customer['phone'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Email</label>
                            <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($customer['email'] ?? '') ?>">
                        </div>
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="is_active" value="1" <?= !empty($customer['is_active']) ? 'checked' : '' ?>>
                                <label class="form-check-label">Đang giao dịch</label>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                <button class="btn btn-primary" id="btnSaveCustomer"><i class="fas fa-save me-1"></i>Lưu</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (hasRole('director','accountant','manager')): ?>
<div class="modal fade" id="modalPrice" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="modalPriceTitle"><i class="fas fa-plus me-2"></i>Thêm mã SP</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="formPrice">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="action" id="priceAction" value="add">
                    <input type="hidden" name="id" id="priceId" value="">
                    <input type="hidden" name="customer_id" value="<?= (int)$customer['id'] ?>">
                    <input type="hidden" name="product_code_id" id="priceProductId" value="">

                    <div id="addOnlyFields">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Mã sản phẩm <span class="text-danger">*</span></label>
                            <input type="text" class="form-control text-uppercase" name="product_code" id="priceProductCode" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Tên sản phẩm <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="description" id="priceDescription" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Đơn vị tính <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="unit" id="priceUnit" required>
                        </div>
                    </div>

                    <div id="editReadonlyFields" style="display:none;">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Mã sản phẩm</label>
                            <input type="text" class="form-control bg-light" id="displayProductCode" readonly>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Tên sản phẩm</label>
                            <input type="text" class="form-control bg-light" id="displayDescription" readonly>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Đơn vị tính</label>
                            <input type="text" class="form-control bg-light" id="displayUnit" readonly>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Đơn giá <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" name="unit_price" id="priceValue" min="0" step="1" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Ngày áp dụng <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" name="effective_date" id="priceDate" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Đến ngày</label>
                        <input type="date" class="form-control" name="expired_date" id="priceExpiredDate">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Ghi chú</label>
                        <input type="text" class="form-control" name="note" id="priceNote">
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                <button class="btn btn-primary" id="btnSavePrice"><i class="fas fa-save me-1"></i>Lưu</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
const csrf = '<?= $csrf ?>';

document.getElementById('btnSaveCustomer')?.addEventListener('click', () => {
    const form = document.getElementById('formCustomer');
    if (!form.checkValidity()) { form.reportValidity(); return; }
    fetch('/erp/api/master/save_customer.php', { method: 'POST', body: new FormData(form) })
        .then(r => r.json())
        .then(d => d.ok ? location.reload() : alert('Lỗi: ' + d.msg));
});

document.getElementById('btnDeleteCustomer')?.addEventListener('click', () => {
    if (!confirm('Xác nhận xoá khách hàng?')) return;
    const fd = new FormData();
    fd.append('csrf_token', csrf);
    fd.append('action', 'delete');
    fd.append('id', '<?= (int)$customer['id'] ?>');
    fetch('/erp/api/master/save_customer.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (!d.ok) return alert('Lỗi: ' + d.msg);
            location.href = '/erp/modules/master/customers.php';
        });
});

document.getElementById('btnAddPrice')?.addEventListener('click', () => {
    document.getElementById('modalPriceTitle').innerHTML = '<i class="fas fa-plus me-2"></i>Thêm mã SP';
    document.getElementById('priceAction').value = 'add';
    document.getElementById('priceId').value = '';
    document.getElementById('priceProductId').value = '';
    document.getElementById('addOnlyFields').style.display = '';
    document.getElementById('editReadonlyFields').style.display = 'none';
    document.querySelectorAll('#addOnlyFields input').forEach(el => { el.disabled = false; });
    document.getElementById('formPrice').reset();
    document.getElementById('priceDate').value = '<?= date('Y-m-d') ?>';
});

document.querySelectorAll('.btn-edit-price').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('modalPriceTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Sửa giá';
        document.getElementById('priceAction').value = 'edit';
        document.getElementById('priceId').value = btn.dataset.id;
        document.getElementById('priceProductId').value = btn.dataset.productId;
        document.getElementById('addOnlyFields').style.display = 'none';
        document.querySelectorAll('#addOnlyFields input').forEach(el => { el.disabled = true; });
        document.getElementById('editReadonlyFields').style.display = '';
        document.getElementById('displayProductCode').value = btn.dataset.productCode || '';
        document.getElementById('displayDescription').value = btn.dataset.description || '';
        document.getElementById('displayUnit').value = btn.dataset.unit || '';
        document.getElementById('priceValue').value = btn.dataset.price;
        document.getElementById('priceDate').value = btn.dataset.effectiveDate || '<?= date('Y-m-d') ?>';
        document.getElementById('priceExpiredDate').value = btn.dataset.expiredDate || '';
        document.getElementById('priceNote').value = '';
        new bootstrap.Modal(document.getElementById('modalPrice')).show();
    });
});

document.getElementById('btnSavePrice')?.addEventListener('click', () => {
    const form = document.getElementById('formPrice');
    if (!form.checkValidity()) { form.reportValidity(); return; }
    const fd = new FormData(form);
    fetch('/erp/api/master/save_customer_price.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => d.ok ? location.reload() : alert('Lỗi: ' + d.msg));
});

document.querySelectorAll('.btn-delete-price').forEach(btn => {
    btn.addEventListener('click', () => {
        if (!confirm(`Xóa toàn bộ lịch sử giá của mã "${btn.dataset.productName}"?`)) return;
        const fd = new FormData();
        fd.append('csrf_token', csrf);
        fd.append('action', 'delete');
        fd.append('customer_id', '<?= (int)$customer['id'] ?>');
        fd.append('product_code_id', btn.dataset.productId);
        fetch('/erp/api/master/save_customer_price.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => d.ok ? location.reload() : alert('Lỗi: ' + d.msg));
    });
});
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/footer.php'; ?>
