<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';
requireLogin();
<<<<<<< HEAD
requireRole('director', 'accountant', 'warehouse', 'production', 'manager');

$pdo = getDBConnection();
$filterCustomer = (int) ($_GET['customer_id'] ?? 0);
$filterStatus = trim((string) ($_GET['status'] ?? ''));
$filterFrom = trim((string) ($_GET['from'] ?? ''));
$filterTo = trim((string) ($_GET['to'] ?? ''));

$where = ['1=1'];
$params = [];
if ($filterCustomer > 0) { $where[] = 'd.customer_id = ?'; $params[] = $filterCustomer; }
if (in_array($filterStatus, ['draft', 'confirmed', 'invoiced'], true)) { $where[] = 'd.status = ?'; $params[] = $filterStatus; }
if ($filterFrom !== '') { $where[] = 'd.delivery_date >= ?'; $params[] = $filterFrom; }
if ($filterTo !== '') { $where[] = 'd.delivery_date <= ?'; $params[] = $filterTo; }

$rows = fetchAllSafe($pdo, "
    SELECT d.*, c.customer_name, c.customer_code,
           COUNT(di.id) AS item_count,
           COALESCE(SUM(di.quantity), 0) AS total_qty
    FROM deliveries d
    JOIN customers c ON c.id = d.customer_id
    LEFT JOIN delivery_items di ON di.delivery_id = d.id
    WHERE " . implode(' AND ', $where) . "
    GROUP BY d.id
    ORDER BY d.delivery_date DESC, d.id DESC
", $params);

$customers = fetchAllSafe($pdo, "SELECT id, customer_code, customer_name FROM customers WHERE is_active = 1 ORDER BY customer_name");
$csrf = generateCSRF();
=======
requireRole('director','accountant','warehouse','production','manager');

$pdo  = getDBConnection();
$user = currentUser();

$filterFrom   = $_GET['from']   ?? date('Y-m-01');
$filterTo     = $_GET['to']     ?? date('Y-m-d');
$filterCust   = trim($_GET['cust'] ?? '');
$filterStatus = $_GET['status'] ?? '';

$where  = ['dn.delivery_date BETWEEN ? AND ?'];
$params = [$filterFrom, $filterTo];
if ($filterCust)   { $where[] = 'c.customer_name LIKE ?'; $params[] = "%$filterCust%"; }
if ($filterStatus) { $where[] = 'dn.status = ?';          $params[] = $filterStatus; }

$deliveries = $pdo->prepare("
    SELECT dn.*,
           c.customer_name,
           u.full_name       AS created_by_name,
           COUNT(dni.id)     AS item_count,
           SUM(dni.quantity) AS total_qty
    FROM delivery_notes dn
    LEFT JOIN customers c             ON dn.customer_id        = c.id
    LEFT JOIN users u                 ON dn.created_by         = u.id
    LEFT JOIN delivery_note_items dni ON dni.delivery_note_id  = dn.id
    WHERE " . implode(' AND ', $where) . "
    GROUP BY dn.id
    ORDER BY dn.delivery_date DESC, dn.id DESC
");
$deliveries->execute($params);
$deliveries = $deliveries->fetchAll(PDO::FETCH_ASSOC);

// Lấy danh sách khách hàng kèm phone, address để auto-fill
$customers = $pdo->query("
    SELECT id, customer_name, customer_code, phone, address
    FROM customers WHERE is_active=1 ORDER BY customer_name
")->fetchAll(PDO::FETCH_ASSOC);

$outputList = $pdo->query("
    SELECT po.id          AS output_id,
           po.output_no,
           po.output_date,
           po.quantity_completed,
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

$today    = date('Y-m-d');
$userRole = $user['role'] ?? '';
$csrf     = generateCSRF();

>>>>>>> 2d78dfa9065c6d955e30172aa04de19c7e992c2f
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/sidebar.php';
?>
<div class="main-content">
<div class="container-fluid py-4">
<<<<<<< HEAD
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="fas fa-truck me-2 text-primary"></i>Giao hàng</h4>
            <p class="text-muted mb-0">Tạo biên bản giao hàng trực tiếp từ các dòng xuất kho đã xác nhận và chưa giao</p>
        </div>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalDelivery">
            <i class="fas fa-plus me-1"></i>Tạo biên bản
        </button>
    </div>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <form class="row g-2 align-items-end" method="GET">
                <div class="col-md-3">
                    <label class="form-label small text-muted">Khách hàng</label>
                    <select name="customer_id" class="form-select form-select-sm">
                        <option value="0">-- Tất cả --</option>
                        <?php foreach ($customers as $c): ?>
                            <option value="<?= (int)$c['id'] ?>" <?= $filterCustomer === (int)$c['id'] ? 'selected' : '' ?>>
                                [<?= e($c['customer_code']) ?>] <?= e($c['customer_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted">Trạng thái</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">-- Tất cả --</option>
                        <option value="draft"     <?= $filterStatus === 'draft'     ? 'selected' : '' ?>>Nháp</option>
                        <option value="confirmed" <?= $filterStatus === 'confirmed' ? 'selected' : '' ?>>Đã xác nhận</option>
                        <option value="invoiced"  <?= $filterStatus === 'invoiced'  ? 'selected' : '' ?>>Đã xuất HĐ</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted">Từ ngày</label>
                    <input type="date" name="from" class="form-control form-control-sm" value="<?= e($filterFrom) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted">Đến ngày</label>
                    <input type="date" name="to" class="form-control form-control-sm" value="<?= e($filterTo) ?>">
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-filter me-1"></i>Lọc</button>
                    <a href="delivery.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
=======

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
                    <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-filter me-1"></i>Lọc</button>
                    <a href="?" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
>>>>>>> 2d78dfa9065c6d955e30172aa04de19c7e992c2f
                </div>
            </form>
        </div>
    </div>

<<<<<<< HEAD
=======
    <!-- Bảng -->
>>>>>>> 2d78dfa9065c6d955e30172aa04de19c7e992c2f
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>Số biên bản</th>
<<<<<<< HEAD
                            <th>Ngày giao</th>
=======
                            <th>Ngày GH</th>
>>>>>>> 2d78dfa9065c6d955e30172aa04de19c7e992c2f
                            <th>Khách hàng</th>
                            <th class="text-center">Số dòng SP</th>
                            <th class="text-end">Tổng SL</th>
                            <th>Trạng thái</th>
<<<<<<< HEAD
                            <th width="260">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr><td colspan="7" class="text-center text-muted py-4">Chưa có biên bản giao hàng</td></tr>
                    <?php else: foreach ($rows as $row):
                        $statusMap = [
                            'draft'     => ['bg-warning text-dark', 'Nháp'],
                            'confirmed' => ['bg-success',           'Đã xác nhận'],
                            'invoiced'  => ['bg-primary',           'Đã xuất HĐ'],
                        ];
                        [$sBadge, $sLabel] = $statusMap[$row['status']] ?? ['bg-secondary', $row['status']];
                    ?>
                        <tr>
                            <td>
                                <div class="fw-semibold text-primary"><?= e($row['delivery_no']) ?></div>
                                <div class="small text-muted"><?= e($row['note'] ?? '—') ?></div>
                            </td>
                            <td><?= formatDate($row['delivery_date']) ?></td>
                            <td>
                                <?php if ($row['customer_code']): ?>
                                    <span class="badge bg-secondary me-1"><?= e($row['customer_code']) ?></span>
                                <?php endif; ?>
                                <?= e($row['customer_name']) ?>
                            </td>
                            <td class="text-center"><?= (int)$row['item_count'] ?></td>
                            <td class="text-end fw-semibold"><?= number_format((float)$row['total_qty'], 0) ?></td>
                            <td><span class="badge <?= $sBadge ?>"><?= $sLabel ?></span></td>
                            <td>
                                <?php if ($row['status'] === 'draft'): ?>
                                    <!-- Nháp: cho xác nhận hoặc xoá -->
                                    <button class="btn btn-sm btn-success btn-confirm"
                                            data-id="<?= (int)$row['id'] ?>"
                                            title="Xác nhận để có thể in biên bản">
                                        <i class="fas fa-check me-1"></i>Xác nhận
                                    </button>
                                    <button class="btn btn-sm btn-outline-danger btn-delete ms-1"
                                            data-id="<?= (int)$row['id'] ?>"
                                            title="Xoá biên bản nháp">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                <?php else: ?>
                                    <!-- Đã xác nhận hoặc đã xuất HĐ: cho in biên bản -->
                                    <a href="/erp/api/production/print_delivery.php?id=<?= (int)$row['id'] ?>"
                                       target="_blank"
                                       class="btn btn-sm btn-outline-secondary"
                                       title="In biên bản giao hàng">
                                        <i class="fas fa-print me-1"></i>In biên bản
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
=======
                            <th>Người tạo</th>
                            <th width="110">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($deliveries)): ?>
                        <tr><td colspan="8" class="text-center text-muted py-4">Chưa có biên bản nào</td></tr>
                    <?php else: ?>
                        <?php foreach ($deliveries as $dv):
                            $isToday    = ($dv['delivery_date'] === $today);
                            $isDirector = ($userRole === 'director');
                            $canEdit    = ($isToday || $isDirector) && ($dv['status'] !== 'invoiced' || $isDirector);
                            $st = ['draft'=>['secondary','Nháp'],'confirmed'=>['primary','Xác nhận'],'invoiced'=>['success','Xuất HĐ']];
                            $s  = $st[$dv['status']] ?? ['secondary','?'];
                        ?>
                        <tr>
                            <td class="fw-semibold text-primary"><?= htmlspecialchars($dv['delivery_no']) ?></td>
                            <td><?= date('d/m/Y', strtotime($dv['delivery_date'])) ?></td>
                            <td class="fw-semibold"><?= htmlspecialchars($dv['customer_name'] ?? '—') ?></td>
                            <td class="text-center"><?= $dv['item_count'] ?></td>
                            <td class="text-end"><?= number_format($dv['total_qty'] ?? 0) ?></td>
                            <td><span class="badge bg-<?= $s[0] ?>"><?= $s[1] ?></span></td>
                            <td class="small text-muted"><?= htmlspecialchars($dv['created_by_name'] ?? '—') ?></td>
                            <td>
                                <a href="delivery_detail.php?id=<?= $dv['id'] ?>"
                                   class="btn btn-sm btn-outline-primary" title="Xem">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <a href="/erp/api/production/print_delivery.php?id=<?= $dv['id'] ?>"
                                   target="_blank" class="btn btn-sm btn-outline-secondary" title="In">
                                    <i class="fas fa-print"></i>
                                </a>
                                <?php if ($canEdit): ?>
                                <button class="btn btn-sm btn-outline-warning btn-edit-delivery"
                                        data-id="<?= $dv['id'] ?>"
                                        data-delivery-date="<?= $dv['delivery_date'] ?>"
                                        data-customer-id="<?= $dv['customer_id'] ?>"
                                        data-note="<?= htmlspecialchars($dv['note'] ?? '') ?>"
                                        data-status="<?= $dv['status'] ?>"
                                        data-sender-name="<?= htmlspecialchars($dv['sender_name'] ?? '') ?>"
                                        data-receiver-name="<?= htmlspecialchars($dv['receiver_name'] ?? '') ?>"
                                        data-receiver-phone="<?= htmlspecialchars($dv['receiver_phone'] ?? '') ?>"
                                        data-driver-name="<?= htmlspecialchars($dv['driver_name'] ?? '') ?>"
                                        data-driver-phone="<?= htmlspecialchars($dv['driver_phone'] ?? '') ?>"
                                        data-vehicle-plate="<?= htmlspecialchars($dv['vehicle_plate'] ?? '') ?>"
                                        data-is-today="<?= $isToday?'1':'0' ?>"
                                        title="Sửa">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-danger btn-delete-delivery"
                                        data-id="<?= $dv['id'] ?>"
                                        data-name="<?= htmlspecialchars($dv['delivery_no']) ?>"
                                        title="Xóa">
                                    <i class="fas fa-trash"></i>
                                </button>
                                <?php else: ?>
                                <span class="text-muted ms-1"><i class="fas fa-lock"></i></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
>>>>>>> 2d78dfa9065c6d955e30172aa04de19c7e992c2f
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer text-muted small">
<<<<<<< HEAD
            Tổng: <strong><?= count($rows) ?></strong> biên bản
            &nbsp;·&nbsp;
            <span class="text-warning fw-semibold">Nháp</span>: bấm <strong>Xác nhận</strong> để chốt và in biên bản.
=======
            Tổng: <strong><?= count($deliveries) ?></strong> biên bản
>>>>>>> 2d78dfa9065c6d955e30172aa04de19c7e992c2f
        </div>
    </div>
</div>
</div>

<<<<<<< HEAD
<!-- Modal Tạo biên bản giao hàng -->
=======
<!-- ══ Modal TẠO biên bản ══════════════════════════════════════════════ -->
>>>>>>> 2d78dfa9065c6d955e30172aa04de19c7e992c2f
<div class="modal fade" id="modalDelivery" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
<<<<<<< HEAD
                <h5 class="modal-title"><i class="fas fa-truck me-2"></i>Tạo biên bản giao hàng</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info py-2 small mb-3">
                    <i class="fas fa-info-circle me-1"></i>
                    Chọn dòng xuất kho cần giao → Bấm <strong>"Xác nhận giao"</strong> để tạo biên bản và in ngay, hoặc <strong>"Lưu nháp"</strong> rồi xác nhận sau.
                </div>
                <form id="formDelivery">
                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                    <input type="hidden" name="action" value="create">
                    <div class="row g-3 mb-3">
                        <div class="col-md-3">
                            <label class="form-label">Ngày giao</label>
                            <input type="date" name="delivery_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Khách hàng</label>
                            <select name="customer_id" id="deliveryCustomer" class="form-select" required>
                                <option value="">-- Chọn khách hàng --</option>
                                <?php foreach ($customers as $c): ?>
                                    <option value="<?= (int)$c['id'] ?>">[<?= e($c['customer_code']) ?>] <?= e($c['customer_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">Ghi chú</label>
                            <input type="text" name="note" class="form-control" placeholder="Ghi chú giao hàng">
                        </div>
                    </div>
                    <div id="deliveryHelp" class="small text-muted mb-2">
                        <i class="fas fa-info-circle me-1"></i>Chọn khách hàng để xem các dòng xuất kho đã xác nhận và chưa giao.
=======
                <h5 class="modal-title"><i class="fas fa-shipping-fast me-2"></i>Tạo biên bản giao hàng</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="formDelivery">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">

                    <!-- ── Hàng 1: Ngày, Khách hàng, Ghi chú ── -->
                    <div class="row g-3 mb-3">
                        <div class="col-md-2">
                            <label class="form-label fw-semibold">Ngày giao <span class="text-danger">*</span></label>
                            <input type="date" name="delivery_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Khách hàng <span class="text-danger">*</span></label>
                            <select name="customer_id" id="selCustomer" class="form-select" required>
                                <option value="">-- Chọn khách hàng --</option>
                                <?php foreach ($customers as $c): ?>
                                <option value="<?= $c['id'] ?>"
                                        data-phone="<?= htmlspecialchars($c['phone'] ?? '') ?>"
                                        data-address="<?= htmlspecialchars($c['address'] ?? '') ?>"
                                        data-name="<?= htmlspecialchars($c['customer_name']) ?>">
                                    [<?= htmlspecialchars($c['customer_code']) ?>] <?= htmlspecialchars($c['customer_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Ghi chú</label>
                            <input type="text" name="note" class="form-control" placeholder="Ghi chú biên bản...">
                        </div>
                    </div>

                    <!-- ── Hàng 2: Thông tin giao nhận ── -->
                    <div class="card border-0 bg-light rounded-3 p-3 mb-3">
                        <div class="fw-bold text-secondary small text-uppercase mb-2">
                            <i class="fas fa-id-card me-1"></i>Thông tin giao nhận
                        </div>
                        <div class="row g-2">
                            <div class="col-md-3">
                                <label class="form-label form-label-sm fw-semibold">Người nhận hàng</label>
                                <input type="text" name="receiver_name" id="inpReceiverName"
                                       class="form-control form-control-sm" placeholder="Tên người nhận...">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label form-label-sm fw-semibold">SĐT người nhận</label>
                                <input type="text" name="receiver_phone" id="inpReceiverPhone"
                                       class="form-control form-control-sm" placeholder="Số điện thoại...">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label form-label-sm fw-semibold">Người lập phiếu / Giao hàng</label>
                                <input type="text" name="sender_name" id="inpSenderName"
                                       class="form-control form-control-sm"
                                       value="<?= htmlspecialchars($user['full_name'] ?? '') ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label form-label-sm fw-semibold">&nbsp;</label>
                                <div class="form-text text-info mt-1">
                                    <i class="fas fa-info-circle me-1"></i>
                                    Lấy từ tài khoản đang đăng nhập
                                </div>
                            </div>
                        </div>
                        <div class="row g-2 mt-1">
                            <div class="col-md-3">
                                <label class="form-label form-label-sm fw-semibold">Tài xế</label>
                                <input type="text" name="driver_name"
                                       class="form-control form-control-sm" placeholder="Tên tài xế...">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label form-label-sm fw-semibold">SĐT tài xế</label>
                                <input type="text" name="driver_phone"
                                       class="form-control form-control-sm" placeholder="SĐT tài xế...">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label form-label-sm fw-semibold">Biển số xe</label>
                                <input type="text" name="vehicle_plate"
                                       class="form-control form-control-sm" placeholder="VD: 30A-12345">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label form-label-sm fw-semibold text-muted">Địa chỉ giao</label>
                                <input type="text" name="delivery_address" id="inpDeliveryAddress"
                                       class="form-control form-control-sm bg-light" placeholder="Tự động theo KH..." readonly>
                            </div>
                        </div>
                    </div>

                    <!-- ── Chi tiết SP ── -->
                    <div id="stockLoadMsg" class="text-muted small mb-2">
                        <i class="fas fa-info-circle me-1"></i>Chọn khách hàng để xem danh sách sản phẩm tồn kho.
>>>>>>> 2d78dfa9065c6d955e30172aa04de19c7e992c2f
                    </div>
                    <div class="table-responsive">
                        <table class="table table-bordered align-middle mb-0">
                            <thead class="table-light">
                                <tr>
<<<<<<< HEAD
                                    <th width="50"><input type="checkbox" id="checkAll" class="form-check-input" title="Chọn tất cả"></th>
                                    <th>Phiếu xuất</th>
                                    <th>Mã SP</th>
                                    <th>Loại</th>
                                    <th class="text-end">SL giao</th>
                                </tr>
                            </thead>
                            <tbody id="deliveryItemsBody">
                                <tr><td colspan="5" class="text-center text-muted py-3">Chọn khách hàng để tải dữ liệu</td></tr>
                            </tbody>
=======
                                    <th width="130">Mã SP</th>
                                    <th>Mô tả</th>
                                    <th width="70">ĐVT</th>
                                    <th width="120">Số lượng</th>
                                    <th width="40"></th>
                                </tr>
                            </thead>
                            <tbody id="itemBody"></tbody>
>>>>>>> 2d78dfa9065c6d955e30172aa04de19c7e992c2f
                        </table>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Huỷ</button>
<<<<<<< HEAD
                <button type="button" class="btn btn-warning" id="btnDeliveryDraft">
                    <i class="fas fa-save me-1"></i>Lưu nháp
                </button>
                <button type="button" class="btn btn-success" id="btnDeliveryConfirm">
                    <i class="fas fa-check me-1"></i>Xác nhận giao &amp; In biên bản
=======
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

<!-- ══ Modal SỬA biên bản ══════════════════════════════════════════════ -->
<div class="modal fade" id="modalEditDelivery" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title fw-bold"><i class="fas fa-edit me-2"></i>Sửa biên bản giao hàng</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="editDeliveryWarning" class="alert alert-warning d-none small">
                    <i class="fas fa-exclamation-triangle me-1"></i>
                    Bạn đang sửa bản ghi <strong>không phải hôm nay</strong> (quyền Giám đốc)
                </div>
                <form id="formEditDelivery">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="id"     id="editDeliveryId">
                    <input type="hidden" name="action" value="update">

                    <!-- ── Hàng 1 ── -->
                    <div class="row g-3 mb-3">
                        <div class="col-md-2">
                            <label class="form-label fw-semibold">Ngày giao <span class="text-danger">*</span></label>
                            <input type="date" name="delivery_date" id="editDeliveryDate" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Khách hàng <span class="text-danger">*</span></label>
                            <select name="customer_id" id="editDeliveryCustomer" class="form-select" required>
                                <option value="">-- Chọn khách hàng --</option>
                                <?php foreach ($customers as $c): ?>
                                <option value="<?= $c['id'] ?>"
                                        data-phone="<?= htmlspecialchars($c['phone'] ?? '') ?>"
                                        data-address="<?= htmlspecialchars($c['address'] ?? '') ?>">
                                    [<?= htmlspecialchars($c['customer_code']) ?>] <?= htmlspecialchars($c['customer_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Ghi chú</label>
                            <input type="text" name="note" id="editDeliveryNote" class="form-control">
                        </div>
                    </div>

                    <!-- ── Thông tin giao nhận ── -->
                    <div class="card border-0 bg-light rounded-3 p-3 mb-3">
                        <div class="fw-bold text-secondary small text-uppercase mb-2">
                            <i class="fas fa-id-card me-1"></i>Thông tin giao nhận
                        </div>
                        <div class="row g-2">
                            <div class="col-md-3">
                                <label class="form-label form-label-sm fw-semibold">Người nhận hàng</label>
                                <input type="text" name="receiver_name" id="editReceiverName"
                                       class="form-control form-control-sm">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label form-label-sm fw-semibold">SĐT người nhận</label>
                                <input type="text" name="receiver_phone" id="editReceiverPhone"
                                       class="form-control form-control-sm">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label form-label-sm fw-semibold">Người lập phiếu / Giao hàng</label>
                                <input type="text" name="sender_name" id="editSenderName"
                                       class="form-control form-control-sm">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label form-label-sm fw-semibold text-muted">Địa chỉ giao</label>
                                <input type="text" name="delivery_address" id="editDeliveryAddress"
                                       class="form-control form-control-sm bg-light" readonly>
                            </div>
                        </div>
                        <div class="row g-2 mt-1">
                            <div class="col-md-3">
                                <label class="form-label form-label-sm fw-semibold">Tài xế</label>
                                <input type="text" name="driver_name" id="editDriverName"
                                       class="form-control form-control-sm">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label form-label-sm fw-semibold">SĐT tài xế</label>
                                <input type="text" name="driver_phone" id="editDriverPhone"
                                       class="form-control form-control-sm">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label form-label-sm fw-semibold">Biển số xe</label>
                                <input type="text" name="vehicle_plate" id="editVehiclePlate"
                                       class="form-control form-control-sm">
                            </div>
                        </div>
                    </div>

                    <!-- ── Chi tiết SP ── -->
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="fw-bold">Chi tiết sản phẩm</span>
                        <button type="button" class="btn btn-sm btn-success" id="btnAddEditRow">
                            <i class="fas fa-plus me-1"></i>Thêm dòng
                        </button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-bordered align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th width="270">Output SX <span class="text-danger">*</span></th>
                                    <th>Mô tả</th>
                                    <th width="70">ĐVT</th>
                                    <th width="110">Số lượng</th>
                                    <th width="130">Đơn giá</th>
                                    <th width="140">Thành tiền</th>
                                    <th width="40"></th>
                                </tr>
                            </thead>
                            <tbody id="editItemBody"></tbody>
                            <tfoot>
                                <tr class="table-light">
                                    <td colspan="5" class="text-end fw-bold">Tổng cộng:</td>
                                    <td class="fw-bold text-success" id="editGrandTotalDisplay">0 đ</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Huỷ</button>
                <button type="button" class="btn btn-warning fw-bold" id="btnUpdateDelivery">
                    <i class="fas fa-save me-1"></i>Lưu thay đổi
>>>>>>> 2d78dfa9065c6d955e30172aa04de19c7e992c2f
                </button>
            </div>
        </div>
    </div>
</div>

<script>
<<<<<<< HEAD
const csrfToken = <?= json_encode($csrf) ?>;

function postForm(url, formData) {
    return fetch(url, { method: 'POST', body: formData }).then(r => r.json());
}
function fmtQty(value) {
    return Number(value || 0).toLocaleString('vi-VN', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
}
function esc(value) {
    return String(value ?? '').replace(/[&<>"']/g, char => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
    }[char]));
}

// Chọn tất cả
document.getElementById('checkAll').addEventListener('change', function () {
    document.querySelectorAll('.export-item-check').forEach(cb => cb.checked = this.checked);
});

function renderExportableItems(items) {
    const body = document.getElementById('deliveryItemsBody');
    document.getElementById('checkAll').checked = false;
    if (!items || !items.length) {
        body.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-4">Không còn dòng xuất kho nào chờ giao</td></tr>';
        return;
    }
    body.innerHTML = items.map(item => `
        <tr class="${item.fgs_type === 'defect' ? 'table-danger' : ''}">
            <td class="text-center">
                <input type="checkbox" class="form-check-input export-item-check" value="${item.export_item_id}" checked>
            </td>
            <td>${esc(item.export_no)}</td>
            <td><span class="badge bg-primary">${esc(item.product_code)}</span><div class="small text-muted">${esc(item.description)}</div></td>
            <td>${item.fgs_type === 'defect' ? '<span class="badge bg-danger">Lỗi</span>' : '<span class="badge bg-success">HT</span>'}</td>
            <td class="text-end fw-semibold">${fmtQty(item.qty_export)}</td>
        </tr>`).join('');
    // Đồng bộ trạng thái checkbox "chọn tất cả"
    document.getElementById('checkAll').checked = true;
}

// Khi chọn khách hàng
document.getElementById('deliveryCustomer').addEventListener('change', function () {
    const customerId = this.value;
    const body = document.getElementById('deliveryItemsBody');
    const help = document.getElementById('deliveryHelp');
    document.getElementById('checkAll').checked = false;
    if (!customerId) {
        body.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-3">Chọn khách hàng để tải dữ liệu</td></tr>';
        help.innerHTML = '<i class="fas fa-info-circle me-1"></i>Chọn khách hàng để xem các dòng xuất kho đã xác nhận và chưa giao.';
        help.className = 'small text-muted mb-2';
        return;
    }
    body.innerHTML = '<tr><td colspan="5" class="text-center py-3"><i class="fas fa-spinner fa-spin"></i> Đang tải...</td></tr>';
    fetch(`/erp/api/production/get_exportable_items.php?customer_id=${customerId}`)
        .then(r => r.json())
        .then(data => {
            if (!data.ok) {
                body.innerHTML = `<tr><td colspan="5" class="text-center text-danger py-3">${esc(data.msg)}</td></tr>`;
                return;
            }
            renderExportableItems(data.items || []);
            const count = (data.items || []).length;
            help.innerHTML = count
                ? `<i class="fas fa-check-circle me-1 text-success"></i>Tìm thấy <strong>${count}</strong> dòng xuất kho. Bỏ tích những dòng không muốn giao.`
                : '<i class="fas fa-exclamation-circle me-1 text-warning"></i>Không có dòng xuất kho nào chờ giao.';
            help.className = count ? 'small text-success mb-2' : 'small text-warning mb-2';
        })
        .catch(() => {
            body.innerHTML = '<tr><td colspan="5" class="text-center text-danger py-3">Lỗi khi tải dữ liệu</td></tr>';
        });
});

// Reset modal khi mở
document.getElementById('modalDelivery').addEventListener('show.bs.modal', () => {
    document.getElementById('formDelivery').reset();
    document.getElementById('deliveryItemsBody').innerHTML = '<tr><td colspan="5" class="text-center text-muted py-3">Chọn khách hàng để tải dữ liệu</td></tr>';
    document.getElementById('checkAll').checked = false;
    document.getElementById('deliveryHelp').innerHTML = '<i class="fas fa-info-circle me-1"></i>Chọn khách hàng để xem các dòng xuất kho đã xác nhận và chưa giao.';
    document.getElementById('deliveryHelp').className = 'small text-muted mb-2';
});

function collectDeliveryForm(confirmNow) {
    const form = document.getElementById('formDelivery');
    if (!form.checkValidity()) { form.reportValidity(); return null; }
    const fd = new FormData(form);
    fd.append('confirm_now', confirmNow ? '1' : '0');
    let selected = 0;
    document.querySelectorAll('.export-item-check:checked').forEach((input, index) => {
        fd.append(`export_item_ids[${index}]`, input.value);
        selected++;
    });
    if (!selected) throw new Error('Phải chọn ít nhất 1 dòng xuất kho để giao');
    return fd;
}

// Lưu nháp
document.getElementById('btnDeliveryDraft').addEventListener('click', () => {
    try {
        const fd = collectDeliveryForm(false);
        if (!fd) return;
        const btn = document.getElementById('btnDeliveryDraft');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Đang lưu...';
        postForm('/erp/api/production/save_delivery_v2.php', fd).then(data => {
            if (!data.ok) return alert(data.msg);
            alert('✅ Đã lưu nháp!\nBấm nút "Xác nhận" ở danh sách để chốt và in biên bản.');
            location.reload();
        }).finally(() => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-save me-1"></i>Lưu nháp';
        });
    } catch (error) { alert(error.message); }
});

// Xác nhận giao & in ngay
document.getElementById('btnDeliveryConfirm').addEventListener('click', () => {
    try {
        const fd = collectDeliveryForm(true);
        if (!fd) return;
        const btn = document.getElementById('btnDeliveryConfirm');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Đang xử lý...';
        postForm('/erp/api/production/save_delivery_v2.php', fd).then(data => {
            if (!data.ok) { alert(data.msg); return; }
            // Mở trang in trong tab mới, reload danh sách
            if (data.delivery_id) {
                window.open(`/erp/api/production/print_delivery.php?id=${data.delivery_id}`, '_blank');
            }
            location.reload();
        }).finally(() => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check me-1"></i>Xác nhận giao &amp; In biên bản';
        });
    } catch (error) { alert(error.message); }
});

// Xác nhận từ danh sách
document.querySelectorAll('.btn-confirm').forEach(btn => {
    btn.addEventListener('click', () => {
        if (!confirm('Xác nhận biên bản giao hàng này?\nSau khi xác nhận sẽ mở trang in.')) return;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>...';
        const fd = new FormData();
        fd.append('csrf_token', csrfToken);
        fd.append('action', 'confirm');
        fd.append('id', btn.dataset.id);
        postForm('/erp/api/production/save_delivery_v2.php', fd).then(data => {
            if (!data.ok) { alert(data.msg); btn.disabled = false; btn.innerHTML = '<i class="fas fa-check me-1"></i>Xác nhận'; return; }
            // Mở trang in rồi reload
            if (data.delivery_id) {
                window.open(`/erp/api/production/print_delivery.php?id=${data.delivery_id}`, '_blank');
            }
            location.reload();
        });
    });
});

// Xoá
document.querySelectorAll('.btn-delete').forEach(btn => {
    btn.addEventListener('click', () => {
        if (!confirm('Xoá biên bản giao hàng nháp này?')) return;
        const fd = new FormData();
        fd.append('csrf_token', csrfToken);
        fd.append('action', 'delete');
        fd.append('id', btn.dataset.id);
        postForm('/erp/api/production/save_delivery_v2.php', fd).then(data => {
            if (!data.ok) return alert(data.msg);
            location.reload();
=======
const OUTPUTS       = <?= json_encode($outputList) ?>;
const CUSTOMERS_MAP = <?= json_encode(array_column($customers, null, 'id')) ?>;
const CSRF_DELIVERY = '<?= $csrf ?>';

// ── Auto-fill khi chọn khách hàng (modal TẠO) ─────────────────────────
document.getElementById('selCustomer').addEventListener('change', function() {
    const opt = this.options[this.selectedIndex];
    document.getElementById('inpReceiverName').value    = opt.dataset.name  || '';
    document.getElementById('inpReceiverPhone').value   = opt.dataset.phone || '';
    document.getElementById('inpDeliveryAddress').value = opt.dataset.address || '';
    const custId = this.value;
    const body = document.getElementById('itemBody');
    const msg  = document.getElementById('stockLoadMsg');
    body.innerHTML = '';
    rowIdx = 0;
    if (!custId) {
        msg.innerHTML = '<i class="fas fa-info-circle me-1"></i>Chọn khách hàng để xem danh sách sản phẩm tồn kho.';
        return;
    }
    msg.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Đang tải...';
    fetch(`/erp/api/production/get_customer_stock.php?customer_id=${custId}`)
    .then(r => r.json())
    .then(res => {
        if (!res.ok || !res.items || !res.items.length) {
            msg.innerHTML = '<span class="text-warning"><i class="fas fa-exclamation-circle me-1"></i>Không còn sản phẩm nào cần giao cho khách hàng này.</span>';
            return;
        }
        msg.innerHTML = `<span class="text-success"><i class="fas fa-check-circle me-1"></i>Tìm thấy ${res.items.length} sản phẩm tồn kho.</span>`;
        res.items.forEach(item => {
            body.insertAdjacentHTML('beforeend', makeStockRow(item, rowIdx++));
        });
    })
    .catch(() => {
        msg.innerHTML = '<span class="text-danger"><i class="fas fa-times-circle me-1"></i>Lỗi khi tải dữ liệu.</span>';
    });
});

// ── Auto-fill khi chọn khách hàng (modal SỬA) ────────────────────────
document.getElementById('editDeliveryCustomer').addEventListener('change', function() {
    const opt = this.options[this.selectedIndex];
    document.getElementById('editDeliveryAddress').value = opt.dataset.address || '';
    // Chỉ fill phone nếu trống
    if (!document.getElementById('editReceiverPhone').value) {
        document.getElementById('editReceiverPhone').value = opt.dataset.phone || '';
    }
});

// ── Tạo dòng SP ──────────────────────────────────────────────────────
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
            <select name="items[${idx}][production_output_id]" class="form-select form-select-sm sel-output" required>
                <option value="">-- Chọn output SX --</option>${opts}
            </select>
            <input type="hidden" name="items[${idx}][product_code_id]" class="inp-pcid">
        </td>
        <td><input type="text" name="items[${idx}][description]" class="form-control form-control-sm inp-desc" readonly></td>
        <td><input type="text" name="items[${idx}][unit]" class="form-control form-control-sm inp-unit" readonly></td>
        <td>
            <input type="number" name="items[${idx}][quantity]" class="form-control form-control-sm inp-qty" placeholder="0" min="1" value="0" required>
            <div class="form-text text-info inp-avail small"></div>
        </td>
        <td><input type="number" name="items[${idx}][unit_price]" class="form-control form-control-sm inp-price" placeholder="0" min="0" value="0"></td>
        <td><input type="number" name="items[${idx}][total_price]" class="form-control form-control-sm inp-total fw-bold text-success" readonly value="0"></td>
        <td><button type="button" class="btn btn-sm btn-outline-danger btn-remove-row"><i class="fas fa-times"></i></button></td>
    </tr>`;
}

// ── Escape HTML để tránh XSS khi điền data từ API vào template ────────
function htmlEsc(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Tạo dòng SP từ kho thành phẩm (modal TẠO) ────────────────────────
function makeStockRow(item, idx) {
    const qty = parseFloat(item.qty_available) || 0;
    return `
    <tr data-idx="${idx}">
        <td>
            <input type="hidden" name="items[${idx}][product_code_id]" value="${item.product_code_id}">
            <span class="fw-semibold">${htmlEsc(item.product_code)}</span>
        </td>
        <td><input type="text" name="items[${idx}][description]" class="form-control form-control-sm" value="${htmlEsc(item.description)}" readonly></td>
        <td><input type="text" name="items[${idx}][unit]" class="form-control form-control-sm" value="${htmlEsc(item.unit)}" readonly></td>
        <td>
            <input type="number" name="items[${idx}][quantity]" class="form-control form-control-sm inp-qty"
                   min="0.01" step="any" max="${qty}" value="${qty}" required>
            <div class="form-text text-info small">Tối đa: ${qty.toLocaleString('vi-VN')}</div>
        </td>
        <td><button type="button" class="btn btn-sm btn-outline-danger btn-remove-row"><i class="fas fa-times"></i></button></td>
    </tr>`;
}

function calcRowTotal(row) {
    const qty   = parseFloat(row.querySelector('.inp-qty').value)   || 0;
    const price = parseFloat(row.querySelector('.inp-price').value) || 0;
    row.querySelector('.inp-total').value = Math.round(qty * price);
}
function updateTotal(displayId, bodyId) {
    let sum = 0;
    document.querySelectorAll(`#${bodyId} .inp-total`).forEach(el => sum += parseFloat(el.value)||0);
    document.getElementById(displayId).textContent = sum.toLocaleString('vi-VN') + ' đ';
}
function bindBodyEvents(bodyId, displayId) {
    const body = document.getElementById(bodyId);
    body.addEventListener('change', e => {
        const row = e.target.closest('tr'); if (!row) return;
        if (e.target.classList.contains('sel-output')) {
            const opt = e.target.options[e.target.selectedIndex];
            row.querySelector('.inp-pcid').value  = opt.dataset.pcid  || '';
            row.querySelector('.inp-desc').value  = opt.dataset.desc  || '';
            row.querySelector('.inp-unit').value  = opt.dataset.unit  || '';
            row.querySelector('.inp-price').value = opt.dataset.price || 0;
            row.querySelector('.inp-qty').max     = opt.dataset.available || '';
            row.querySelector('.inp-avail').textContent = opt.dataset.available
                ? `Tối đa: ${parseFloat(opt.dataset.available).toLocaleString()}` : '';
            calcRowTotal(row); updateTotal(displayId, bodyId);
        }
        if (e.target.classList.contains('inp-qty') || e.target.classList.contains('inp-price')) {
            calcRowTotal(row); updateTotal(displayId, bodyId);
        }
    });
    body.addEventListener('input', e => {
        const row = e.target.closest('tr'); if (!row) return;
        if (e.target.classList.contains('inp-qty') || e.target.classList.contains('inp-price')) {
            calcRowTotal(row); updateTotal(displayId, bodyId);
        }
    });
    body.addEventListener('click', e => {
        if (e.target.closest('.btn-remove-row')) {
            if (document.querySelectorAll(`#${bodyId} tr`).length <= 1) {
                alert('Cần ít nhất 1 dòng!'); return;
            }
            e.target.closest('tr').remove();
            updateTotal(displayId, bodyId);
        }
    });
}

let rowIdx = 0, editRowIdx = 0;
bindBodyEvents('editItemBody', 'editGrandTotalDisplay');

// Xử lý xoá dòng trong modal TẠO
document.getElementById('itemBody').addEventListener('click', e => {
    if (e.target.closest('.btn-remove-row')) {
        e.target.closest('tr').remove();
    }
});

document.getElementById('modalDelivery').addEventListener('show.bs.modal', () => {
    document.getElementById('itemBody').innerHTML = '';
    rowIdx = 0;
    document.getElementById('stockLoadMsg').innerHTML = '<i class="fas fa-info-circle me-1"></i>Chọn khách hàng để xem danh sách sản phẩm tồn kho.';
    // Reset sender name về user hiện tại
    document.getElementById('inpSenderName').value = '<?= addslashes($user['full_name'] ?? '') ?>';
    document.getElementById('selCustomer').value = '';
    document.getElementById('inpReceiverName').value  = '';
    document.getElementById('inpReceiverPhone').value = '';
    document.getElementById('inpDeliveryAddress').value = '';
});
document.getElementById('btnAddEditRow').addEventListener('click', () => {
    document.getElementById('editItemBody').insertAdjacentHTML('beforeend', makeRow(editRowIdx++));
});

// ── Lưu tạo mới ──────────────────────────────────────────────────────
function saveDelivery(status) {
    const form = document.getElementById('formDelivery');
    if (!form.checkValidity()) { form.reportValidity(); return; }
    const rows = document.querySelectorAll('#itemBody tr');
    if (!rows.length) { alert('Chưa có sản phẩm nào để giao!'); return; }
    let valid = false;
    rows.forEach(r => {
        const qtyEl = r.querySelector('.inp-qty');
        if (qtyEl && parseFloat(qtyEl.value) > 0) valid = true;
    });
    if (!valid) { alert('Vui lòng nhập số lượng giao!'); return; }
    const btn = status==='confirmed' ? document.getElementById('btnSaveConfirm') : document.getElementById('btnSaveDraft');
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
        btn.innerHTML = status==='confirmed'
            ? '<i class="fas fa-check me-1"></i>Xác nhận & Lưu'
            : '<i class="fas fa-save me-1"></i>Lưu nháp';
    });
}
document.getElementById('btnSaveDraft').addEventListener('click',   () => saveDelivery('draft'));
document.getElementById('btnSaveConfirm').addEventListener('click', () => saveDelivery('confirmed'));

// ── Mở modal sửa ─────────────────────────────────────────────────────
document.querySelectorAll('.btn-edit-delivery').forEach(btn => {
    btn.addEventListener('click', function() {
        const d = this.dataset;
        document.getElementById('editDeliveryId').value          = d.id;
        document.getElementById('editDeliveryDate').value        = d.deliveryDate;
        document.getElementById('editDeliveryNote').value        = d.note;
        document.getElementById('editSenderName').value          = d.senderName   || '';
        document.getElementById('editReceiverName').value        = d.receiverName || '';
        document.getElementById('editReceiverPhone').value       = d.receiverPhone|| '';
        document.getElementById('editDriverName').value          = d.driverName   || '';
        document.getElementById('editDriverPhone').value         = d.driverPhone  || '';
        document.getElementById('editVehiclePlate').value        = d.vehiclePlate || '';

        // Set khách hàng + địa chỉ
        const sel = document.getElementById('editDeliveryCustomer');
        for (let opt of sel.options) {
            if (opt.value == d.customerId) {
                opt.selected = true;
                document.getElementById('editDeliveryAddress').value = opt.dataset.address || '';
                break;
            }
        }
        document.getElementById('editDeliveryWarning').classList.toggle('d-none', d.isToday === '1');

        // Load items
        fetch(`/erp/api/production/get_delivery_items.php?id=${d.id}`)
        .then(r => r.json())
        .then(res => {
            const body = document.getElementById('editItemBody');
            body.innerHTML = '';
            editRowIdx = 0;
            if (res.ok && res.items.length > 0) {
                res.items.forEach(item => {
                    body.insertAdjacentHTML('beforeend', makeRow(editRowIdx++));
                    const row = body.lastElementChild;
                    const selOut = row.querySelector('.sel-output');
                    for (let opt of selOut.options) {
                        if (opt.value == item.production_output_id) { opt.selected = true; break; }
                    }
                    selOut.dispatchEvent(new Event('change'));
                    row.querySelector('.inp-qty').value   = item.quantity;
                    row.querySelector('.inp-price').value = item.unit_price;
                    calcRowTotal(row);
                });
            } else {
                body.insertAdjacentHTML('beforeend', makeRow(editRowIdx++));
            }
            updateTotal('editGrandTotalDisplay', 'editItemBody');
        });

        new bootstrap.Modal(document.getElementById('modalEditDelivery')).show();
    });
});

// ── Lưu sửa ──────────────────────────────────────────────────────────
document.getElementById('btnUpdateDelivery').addEventListener('click', () => {
    const form = document.getElementById('formEditDelivery');
    if (!form.checkValidity()) { form.reportValidity(); return; }
    let valid = false;
    document.querySelectorAll('#editItemBody tr').forEach(r => {
        if (r.querySelector('.sel-output').value && parseFloat(r.querySelector('.inp-qty').value)>0) valid = true;
    });
    if (!valid) { alert('Vui lòng chọn output SX và nhập số lượng!'); return; }
    const btn = document.getElementById('btnUpdateDelivery');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Đang lưu...';
    fetch('/erp/api/production/update_delivery.php', { method:'POST', body: new FormData(form) })
    .then(r => r.json())
    .then(res => {
        if (res.ok) {
            bootstrap.Modal.getInstance(document.getElementById('modalEditDelivery')).hide();
            location.reload();
        } else { alert('Lỗi: ' + res.msg); }
    })
    .finally(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save me-1"></i>Lưu thay đổi';
    });
});

// ── Xóa ──────────────────────────────────────────────────────────────
document.querySelectorAll('.btn-delete-delivery').forEach(btn => {
    btn.addEventListener('click', function() {
        const d = this.dataset;
        if (!confirm(`Xóa biên bản "${d.name}"?\nHành động không thể hoàn tác!`)) return;
        const fd = new FormData();
        fd.append('csrf_token', CSRF_DELIVERY);
        fd.append('id',     d.id);
        fd.append('action', 'delete');
        fetch('/erp/api/production/update_delivery.php', { method:'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.ok) { location.reload(); }
            else { alert('Lỗi: ' + res.msg); }
>>>>>>> 2d78dfa9065c6d955e30172aa04de19c7e992c2f
        });
    });
});
</script>
<<<<<<< HEAD
<?php include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/footer.php'; ?>
=======
<?php include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/footer.php'; ?>
>>>>>>> 2d78dfa9065c6d955e30172aa04de19c7e992c2f
