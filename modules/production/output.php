<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';
requireLogin();
<<<<<<< HEAD
requireRole('director', 'accountant', 'warehouse', 'production', 'manager');

$pdo = getDBConnection();
$user = currentUser();

$filterCustomer = (int) ($_GET['customer_id'] ?? 0);
$filterStatus = trim((string) ($_GET['status'] ?? ''));
$filterFrom = trim((string) ($_GET['from'] ?? ''));
$filterTo = trim((string) ($_GET['to'] ?? ''));

$where = ['1=1'];
$params = [];
if ($filterCustomer > 0) {
    $where[] = 'pp.customer_id = ?';
    $params[] = $filterCustomer;
}
if (in_array($filterStatus, ['in_progress', 'completed'], true)) {
    $where[] = 'pp.status = ?';
    $params[] = $filterStatus;
}
if ($filterFrom !== '') {
    $where[] = 'DATE(pp.created_at) >= ?';
    $params[] = $filterFrom;
}
if ($filterTo !== '') {
    $where[] = 'DATE(pp.created_at) <= ?';
    $params[] = $filterTo;
}

$progressRows = fetchAllSafe($pdo, "
    SELECT pp.*, wi.receipt_no, wi.receipt_date,
           c.customer_name, c.customer_code,
           pc.product_code, pc.description, pc.unit,
           COUNT(ppl.id) AS log_count
    FROM production_progress pp
    JOIN warehouse_in wi ON wi.id = pp.warehouse_in_id
    JOIN customers c ON c.id = pp.customer_id
    JOIN product_codes pc ON pc.id = pp.product_code_id
    LEFT JOIN production_progress_logs ppl ON ppl.progress_id = pp.id
    WHERE " . implode(' AND ', $where) . "
    GROUP BY pp.id
    ORDER BY pp.created_at DESC, pp.id DESC
", $params);

$customers = fetchAllSafe($pdo, "SELECT id, customer_code, customer_name FROM customers WHERE is_active = 1 ORDER BY customer_name");
$eligibleReceipts = fetchAllSafe($pdo, "
    SELECT wi.id, wi.receipt_no, wi.receipt_date, c.customer_name,
           COUNT(wii.id) AS item_count,
           SUM(CASE WHEN pp.id IS NULL THEN 1 ELSE 0 END) AS missing_count
    FROM warehouse_in wi
    JOIN customers c ON c.id = wi.customer_id
    JOIN warehouse_in_items wii ON wii.warehouse_in_id = wi.id
    LEFT JOIN production_progress pp
        ON pp.warehouse_in_id = wi.id
       AND pp.product_code_id = wii.product_code_id
    GROUP BY wi.id
    HAVING missing_count > 0
    ORDER BY wi.receipt_date DESC, wi.id DESC
");

$csrf = generateCSRF();
$canDeleteLogs = hasRole('director');
=======
requireRole('director','accountant','warehouse','production','manager');

$pdo  = getDBConnection();
$user = currentUser();

$filterDate = $_GET['date'] ?? date('Y-m-d');

$outputs = $pdo->prepare("
    SELECT po.*,
           pc.product_code, pc.description AS product_desc, pc.unit,
           pr.receipt_no,
           u.full_name AS created_by_name,
           COALESCE(SUM(dni.quantity), 0) AS qty_delivered_actual
    FROM production_outputs po
    JOIN product_codes pc       ON po.product_code_id       = pc.id
    JOIN production_receipts pr ON po.production_receipt_id = pr.id
    LEFT JOIN users u           ON po.created_by            = u.id
    LEFT JOIN delivery_note_items dni ON dni.production_output_id = po.id
    WHERE po.output_date = ?
    GROUP BY po.id
    ORDER BY po.created_at DESC
");
$outputs->execute([$filterDate]);
$outputs = $outputs->fetchAll(PDO::FETCH_ASSOC);

$totalOK        = array_sum(array_column($outputs, 'quantity_completed'));
$totalNG        = array_sum(array_column($outputs, 'quantity_defect'));
$totalDelivered = array_sum(array_column($outputs, 'qty_delivered_actual'));

$receiptList = $pdo->query("
    SELECT pr.id, pr.receipt_no, pr.receipt_date,
           pc.product_code, pc.description, pc.unit,
           pr.product_code_id,
           pr.quantity_received,
           COALESCE(SUM(po.quantity_completed + po.quantity_defect), 0) AS reported
    FROM production_receipts pr
    JOIN product_codes pc ON pr.product_code_id = pc.id
    LEFT JOIN production_outputs po ON po.production_receipt_id = pr.id
    GROUP BY pr.id
    HAVING pr.quantity_received > reported
    ORDER BY pr.receipt_date DESC, pc.product_code
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
            <h4 class="mb-1"><i class="fas fa-cogs me-2 text-primary"></i>Tiến độ gia công</h4>
            <p class="text-muted mb-0">Cộng dồn tiến độ theo từng lệnh sản xuất từ phiếu nhập NVL</p>
        </div>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalCreateProgress">
            <i class="fas fa-plus me-1"></i>Tạo lệnh SX
        </button>
    </div>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <form class="row g-2 align-items-end" method="GET">
                <div class="col-md-3">
                    <label class="form-label small text-muted">Khách hàng</label>
                    <select name="customer_id" class="form-select form-select-sm">
                        <option value="0">-- Tất cả --</option>
                        <?php foreach ($customers as $customer): ?>
                            <option value="<?= (int) $customer['id'] ?>" <?= $filterCustomer === (int) $customer['id'] ? 'selected' : '' ?>>
                                [<?= e($customer['customer_code']) ?>] <?= e($customer['customer_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted">Trạng thái</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">-- Tất cả --</option>
                        <option value="in_progress" <?= $filterStatus === 'in_progress' ? 'selected' : '' ?>>Đang SX</option>
                        <option value="completed" <?= $filterStatus === 'completed' ? 'selected' : '' ?>>Hoàn thành</option>
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
                    <a href="output.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
                </div>
=======

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="fas fa-clipboard-list me-2 text-primary"></i>Output cuối ngày</h4>
            <p class="text-muted mb-0">Ghi nhận sản lượng hoàn thành / lỗi theo ngày</p>
        </div>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalOutput">
            <i class="fas fa-plus me-1"></i> Nhập output
        </button>
    </div>

    <?php showFlash(); ?>

    <!-- Filter -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2">
            <form class="row g-2 align-items-center" method="GET">
                <div class="col-auto">
                    <input type="date" name="date" class="form-control form-control-sm"
                           value="<?= htmlspecialchars($filterDate) ?>">
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-sm btn-primary">
                        <i class="fas fa-filter me-1"></i>Xem
                    </button>
                </div>
                <?php if ($totalOK + $totalNG > 0): ?>
                <div class="col-auto ms-auto">
                    <span class="badge bg-success fs-6 me-1">✓ Hoàn thành: <?= number_format($totalOK) ?></span>
                    <span class="badge bg-danger fs-6 me-1">✗ Lỗi: <?= number_format($totalNG) ?></span>
                    <span class="badge bg-primary fs-6">↑ Đã giao: <?= number_format($totalDelivered) ?></span>
                </div>
                <?php endif; ?>
>>>>>>> 2d78dfa9065c6d955e30172aa04de19c7e992c2f
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
<<<<<<< HEAD
                            <th>Số lệnh</th>
                            <th>Phiếu nhập NVL</th>
                            <th>Khách hàng</th>
                            <th>Mã SP</th>
                            <th class="text-end">Tổng NVL</th>
                            <th class="text-end">Đã HT</th>
                            <th class="text-end">Lỗi</th>
                            <th class="text-end">Còn lại</th>
                            <th width="150">% hoàn thành</th>
                            <th>Trạng thái</th>
                            <th width="130">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$progressRows): ?>
                        <tr><td colspan="11" class="text-center text-muted py-4">Chưa có lệnh sản xuất</td></tr>
                    <?php else: foreach ($progressRows as $row):
                        $progressPercent = (float) $row['qty_total'] > 0
                            ? min(100, round((((float) $row['qty_done'] + (float) $row['qty_defect']) / (float) $row['qty_total']) * 100, 1))
                            : 0;
                        $statusBadge = $row['status'] === 'completed' ? 'bg-success' : 'bg-warning text-dark';
                    ?>
                        <tr>
                            <td>
                                <div class="fw-semibold text-primary"><?= e($row['progress_no']) ?></div>
                                <div class="small text-muted"><?= (int) $row['log_count'] ?> logs</div>
                            </td>
                            <td>
                                <div class="fw-semibold"><?= e($row['receipt_no']) ?></div>
                                <div class="small text-muted"><?= formatDate($row['receipt_date']) ?></div>
                            </td>
                            <td><?= e($row['customer_name']) ?></td>
                            <td>
                                <span class="badge bg-primary"><?= e($row['product_code']) ?></span>
                                <div class="small text-muted"><?= e($row['description']) ?></div>
                            </td>
                            <td class="text-end"><?= number_format((float) $row['qty_total'], 0) ?></td>
                            <td class="text-end text-success fw-semibold"><?= number_format((float) $row['qty_done'], 0) ?></td>
                            <td class="text-end text-danger fw-semibold"><?= number_format((float) $row['qty_defect'], 0) ?></td>
                            <td class="text-end text-warning fw-semibold"><?= number_format((float) $row['qty_remaining'], 0) ?></td>
                            <td>
                                <div class="progress" style="height: 20px;">
                                    <div class="progress-bar bg-success" role="progressbar" style="width: <?= $progressPercent ?>%;">
                                        <?= $progressPercent ?>%
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="badge <?= $statusBadge ?>">
                                    <?= $row['status'] === 'completed' ? 'Hoàn thành' : 'Đang SX' ?>
                                </span>
                            </td>
                            <td>
                                <button class="btn btn-sm btn-outline-primary btn-detail"
                                        data-id="<?= (int) $row['id'] ?>"
                                        title="Xem chi tiết">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <?php if ($row['status'] !== 'completed'): ?>
                                <button class="btn btn-sm btn-outline-success btn-log ms-1"
                                        data-id="<?= (int) $row['id'] ?>"
                                        data-no="<?= e($row['progress_no']) ?>"
                                        data-remaining="<?= e($row['qty_remaining']) ?>"
                                        data-receipt="<?= e($row['receipt_no']) ?>"
                                        data-product="<?= e($row['product_code']) ?>"
                                        title="Cập nhật tiến độ">
                                    <i class="fas fa-plus"></i>
                                </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
=======
                            <th>Số output</th>
                            <th>Mã SP</th>
                            <th>Phiếu nhận SX</th>
                            <th class="text-end text-success">Hoàn thành</th>
                            <th class="text-end text-danger">Lỗi</th>
                            <th class="text-end text-primary">Đã giao</th>
                            <th class="text-end text-warning">Còn lại</th>
                            <th class="text-center">Tỷ lệ OK</th>
                            <th>Người nhập</th>
                            <th>Ghi chú</th>
                            <th width="90">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($outputs)): ?>
                        <tr><td colspan="11" class="text-center text-muted py-4">Chưa có output nào</td></tr>
                    <?php else: ?>
                        <?php foreach ($outputs as $o):
                            $total     = $o['quantity_completed'] + $o['quantity_defect'];
                            $rate      = $total > 0 ? round($o['quantity_completed'] / $total * 100, 1) : 0;
                            $remaining = max(0, $o['quantity_completed'] - $o['qty_delivered_actual']);
                            $isToday   = ($o['output_date'] === $today);
                            $canEdit   = $isToday || ($userRole === 'director');
                        ?>
                        <tr>
                            <td class="text-primary fw-semibold"><?= htmlspecialchars($o['output_no']) ?></td>
                            <td><span class="badge bg-primary"><?= htmlspecialchars($o['product_code']) ?></span></td>
                            <td class="small text-muted"><?= htmlspecialchars($o['receipt_no']) ?></td>
                            <td class="text-end fw-bold text-success"><?= number_format($o['quantity_completed']) ?></td>
                            <td class="text-end text-danger"><?= number_format($o['quantity_defect']) ?></td>
                            <td class="text-end text-primary"><?= number_format($o['qty_delivered_actual']) ?></td>
                            <td class="text-end fw-bold <?= $remaining > 0 ? 'text-warning':'text-muted' ?>">
                                <?= number_format($remaining) ?>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-<?= $rate>=95?'success':($rate>=80?'warning':'danger') ?>">
                                    <?= $rate ?>%
                                </span>
                            </td>
                            <td class="small"><?= htmlspecialchars($o['created_by_name'] ?? '—') ?></td>
                            <td class="small text-muted"><?= htmlspecialchars($o['note'] ?? '—') ?></td>
                            <td>
                                <?php if ($canEdit): ?>
                                <button class="btn btn-xs btn-outline-warning btn-edit-output"
                                        data-id="<?= $o['id'] ?>"
                                        data-completed="<?= $o['quantity_completed'] ?>"
                                        data-defect="<?= $o['quantity_defect'] ?>"
                                        data-note="<?= htmlspecialchars($o['note'] ?? '') ?>"
                                        data-output-no="<?= htmlspecialchars($o['output_no']) ?>"
                                        data-is-today="<?= $isToday ? '1':'0' ?>"
                                        title="Sửa">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="btn btn-xs btn-outline-danger btn-delete-output ms-1"
                                        data-id="<?= $o['id'] ?>"
                                        data-name="<?= htmlspecialchars($o['output_no']) ?>"
                                        title="Xóa">
                                    <i class="fas fa-trash"></i>
                                </button>
                                <?php else: ?>
                                <span class="text-muted"><i class="fas fa-lock"></i></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                    <?php if (!empty($outputs)): ?>
                    <tfoot class="table-light fw-bold">
                        <tr>
                            <td colspan="3" class="text-end">Tổng:</td>
                            <td class="text-end text-success"><?= number_format($totalOK) ?></td>
                            <td class="text-end text-danger"><?= number_format($totalNG) ?></td>
                            <td class="text-end text-primary"><?= number_format($totalDelivered) ?></td>
                            <td class="text-end text-warning"><?= number_format(max(0, $totalOK - $totalDelivered)) ?></td>
                            <td class="text-center">
                                <?php $r = ($totalOK+$totalNG)>0 ? round($totalOK/($totalOK+$totalNG)*100,1):0; ?>
                                <span class="badge bg-<?= $r>=95?'success':($r>=80?'warning':'danger') ?>"><?= $r ?>%</span>
                            </td>
                            <td colspan="3"></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
>>>>>>> 2d78dfa9065c6d955e30172aa04de19c7e992c2f
                </table>
            </div>
        </div>
    </div>
</div>
</div>

<<<<<<< HEAD
<!-- Modal Tạo lệnh SX -->
<div class="modal fade" id="modalCreateProgress" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-plus me-2"></i>Tạo lệnh SX</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="formCreateProgress">
                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                    <input type="hidden" name="action" value="create">
                    <div class="mb-3">
                        <label class="form-label">Phiếu nhập NVL</label>
                        <select name="warehouse_in_id" class="form-select" required>
                            <option value="">-- Chọn phiếu còn thiếu lệnh SX --</option>
                            <?php foreach ($eligibleReceipts as $receipt): ?>
                                <option value="<?= (int) $receipt['id'] ?>">
                                    <?= e($receipt['receipt_no']) ?> - <?= e($receipt['customer_name']) ?> (thiếu <?= (int) $receipt['missing_count'] ?> mã)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Huỷ</button>
                <button type="button" class="btn btn-primary" id="btnCreateProgress">Tạo lệnh</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal Cập nhật tiến độ -->
<div class="modal fade" id="modalProgressLog" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-pen me-2"></i>Cập nhật tiến độ</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="small text-muted mb-3" id="logMeta"></div>
                <form id="formProgressLog">
                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                    <input type="hidden" name="action" value="log">
                    <input type="hidden" name="progress_id" id="logProgressId">
                    <div class="mb-3">
                        <label class="form-label">Ngày</label>
                        <input type="date" name="log_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label text-success">SL hoàn thành thêm</label>
                            <input type="number" step="0.001" min="0" name="qty_done" class="form-control" value="0">
                        </div>
                        <div class="col-6">
                            <label class="form-label text-danger">SL lỗi thêm</label>
                            <input type="number" step="0.001" min="0" name="qty_defect" class="form-control" value="0">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Ghi chú</label>
=======
<!-- Modal tạo output -->
<div class="modal fade" id="modalOutput" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-clipboard-list me-2"></i>Nhập output cuối ngày</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="formOutput">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="product_code_id" id="outProductId">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Ngày <span class="text-danger">*</span></label>
                        <input type="date" name="output_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Phiếu nhận SX <span class="text-danger">*</span></label>
                        <select name="production_receipt_id" id="selReceipt" class="form-select" required>
                            <option value="">-- Chọn phiếu nhận còn hàng --</option>
                            <?php foreach ($receiptList as $rl):
                                $rem = $rl['quantity_received'] - $rl['reported']; ?>
                            <option value="<?= $rl['id'] ?>"
                                    data-pcid="<?= $rl['product_code_id'] ?>"
                                    data-remaining="<?= $rem ?>">
                                [<?= htmlspecialchars($rl['product_code']) ?>]
                                <?= htmlspecialchars($rl['description']) ?>
                                — Còn: <?= number_format($rem) ?> <?= $rl['unit'] ?>
                                (<?= $rl['receipt_no'] ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text text-info" id="outRemaining"></div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold text-success">SL Hoàn thành <span class="text-danger">*</span></label>
                            <input type="number" name="quantity_completed" class="form-control border-success"
                                   placeholder="0" min="0" value="0" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold text-danger">SL Lỗi</label>
                            <input type="number" name="quantity_defect" class="form-control border-danger"
                                   placeholder="0" min="0" value="0">
                        </div>
                    </div>
                    <div class="alert alert-info small py-2 mb-3">
                        <i class="fas fa-info-circle me-1"></i>
                        <strong>Số lượng đã giao</strong> tính tự động từ biên bản giao hàng.
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Ghi chú</label>
>>>>>>> 2d78dfa9065c6d955e30172aa04de19c7e992c2f
                        <textarea name="note" class="form-control" rows="2"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Huỷ</button>
<<<<<<< HEAD
                <button type="button" class="btn btn-success" id="btnSaveLog">Lưu tiến độ</button>
=======
                <button type="button" class="btn btn-primary" id="btnSaveOutput">
                    <i class="fas fa-save me-1"></i>Lưu
                </button>
>>>>>>> 2d78dfa9065c6d955e30172aa04de19c7e992c2f
            </div>
        </div>
    </div>
</div>

<<<<<<< HEAD
<!-- Modal Chi tiết tiến độ -->
<div class="modal fade" id="modalProgressDetail" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-eye me-2"></i>Chi tiết tiến độ</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="progressDetailHeader" class="mb-3"></div>
                <div class="row g-3">
                    <div class="col-lg-6">
                        <h6 class="fw-semibold">Lịch sử logs</h6>
                        <div id="progressLogs"></div>
                    </div>
                    <div class="col-lg-6">
                        <h6 class="fw-semibold">Kho thành phẩm phát sinh</h6>
                        <div id="progressFgs"></div>
                    </div>
                </div>
=======
<!-- Modal sửa output -->
<div class="modal fade" id="modalEditOutput" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title fw-bold"><i class="fas fa-edit me-2"></i>Sửa output</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="editOutputWarning" class="alert alert-warning d-none small">
                    <i class="fas fa-exclamation-triangle me-1"></i>
                    Bạn đang sửa bản ghi <strong>không phải hôm nay</strong> (quyền Giám đốc)
                </div>
                <form id="formEditOutput">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="id"     id="editOutputId">
                    <input type="hidden" name="action" value="update">
                    <div class="mb-2 text-muted small">
                        Số output: <strong id="editOutputNo" class="text-primary"></strong>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold text-success">SL Hoàn thành <span class="text-danger">*</span></label>
                            <input type="number" name="quantity_completed" id="editOutputCompleted"
                                   class="form-control border-success" min="0" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold text-danger">SL Lỗi</label>
                            <input type="number" name="quantity_defect" id="editOutputDefect"
                                   class="form-control border-danger" min="0" value="0">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Ghi chú</label>
                        <textarea name="note" id="editOutputNote" class="form-control" rows="2"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Huỷ</button>
                <button type="button" class="btn btn-warning fw-bold" id="btnUpdateOutput">
                    <i class="fas fa-save me-1"></i>Lưu thay đổi
                </button>
>>>>>>> 2d78dfa9065c6d955e30172aa04de19c7e992c2f
            </div>
        </div>
    </div>
</div>

<script>
<<<<<<< HEAD
const csrfToken = <?= json_encode($csrf) ?>;
const canDeleteLogs = <?= $canDeleteLogs ? 'true' : 'false' ?>;

function getModalLog() {
    return bootstrap.Modal.getOrCreateInstance(document.getElementById('modalProgressLog'));
}
function getModalDetail() {
    return bootstrap.Modal.getOrCreateInstance(document.getElementById('modalProgressDetail'));
}

function postForm(url, formData) {
    return fetch(url, { method: 'POST', body: formData }).then(r => r.json());
}

function fmtQty(value) {
    return Number(value || 0).toLocaleString('vi-VN', { minimumFractionDigits: 0, maximumFractionDigits: 3 });
}

function esc(value) {
    return String(value ?? '').replace(/[&<>"']/g, char => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
    }[char]));
}

// Tạo lệnh SX thủ công
document.getElementById('btnCreateProgress').addEventListener('click', () => {
    const form = document.getElementById('formCreateProgress');
    if (!form.checkValidity()) { form.reportValidity(); return; }
    postForm('/erp/api/production/save_progress.php', new FormData(form)).then(data => {
        if (!data.ok) return alert(data.msg);
        location.reload();
    });
});

// Mở modal cập nhật tiến độ
document.querySelectorAll('.btn-log').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('logProgressId').value = btn.dataset.id;
        document.getElementById('logMeta').innerHTML =
            `<strong>${esc(btn.dataset.no)}</strong> · Phiếu ${esc(btn.dataset.receipt)} · Mã ${esc(btn.dataset.product)}<br>` +
            `Còn lại hiện tại: <strong class="text-warning">${esc(btn.dataset.remaining)}</strong>`;
        // Reset form
        document.querySelector('#formProgressLog [name="qty_done"]').value = '0';
        document.querySelector('#formProgressLog [name="qty_defect"]').value = '0';
        document.querySelector('#formProgressLog [name="note"]').value = '';
        getModalLog().show();
    });
});

// Lưu tiến độ
document.getElementById('btnSaveLog').addEventListener('click', () => {
    const form = document.getElementById('formProgressLog');
    if (!form.checkValidity()) { form.reportValidity(); return; }
    const btn = document.getElementById('btnSaveLog');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Đang lưu...';
    postForm('/erp/api/production/save_progress.php', new FormData(form)).then(data => {
        if (!data.ok) { alert(data.msg); }
        else { location.reload(); }
    }).finally(() => {
        btn.disabled = false;
        btn.innerHTML = 'Lưu tiến độ';
    });
});

// Xem chi tiết
document.querySelectorAll('.btn-detail').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('progressDetailHeader').innerHTML = '<div class="text-center py-3"><i class="fas fa-spinner fa-spin"></i> Đang tải...</div>';
        document.getElementById('progressLogs').innerHTML = '';
        document.getElementById('progressFgs').innerHTML = '';
        getModalDetail().show();

        fetch(`/erp/api/production/get_progress_detail.php?id=${btn.dataset.id}`)
            .then(r => r.json())
            .then(data => {
                if (!data.ok) {
                    document.getElementById('progressDetailHeader').innerHTML = `<div class="alert alert-danger">${esc(data.msg)}</div>`;
                    return;
                }
                const h = data.header;
                const percent = Number(h.qty_total) > 0
                    ? Math.min(100, (((Number(h.qty_done) + Number(h.qty_defect)) / Number(h.qty_total)) * 100).toFixed(1))
                    : 0;

                document.getElementById('progressDetailHeader').innerHTML = `
                    <div class="row g-2 small">
                        <div class="col-md-6">
                            <strong>${esc(h.progress_no)}</strong> · Phiếu ${esc(h.receipt_no)}<br>
                            ${esc(h.customer_name)} · <span class="badge bg-primary">${esc(h.product_code)}</span> ${esc(h.description)}
                        </div>
                        <div class="col-md-6 text-md-end">
                            Tổng NVL: <strong>${fmtQty(h.qty_total)}</strong><br>
                            Tiến độ: <strong>${percent}%</strong> · Còn lại: <strong class="text-warning">${fmtQty(h.qty_remaining)}</strong>
                        </div>
                    </div>`;

                document.getElementById('progressLogs').innerHTML = data.logs.length
                    ? `<div class="list-group">${data.logs.map((log, index) => `
                        <div class="list-group-item">
                            <div class="d-flex justify-content-between align-items-start gap-2">
                                <div>
                                    <div class="fw-semibold">${esc(log.log_date)}</div>
                                    <div class="small text-success">HT: ${fmtQty(log.qty_done)} · Lỗi: ${fmtQty(log.qty_defect)}</div>
                                    <div class="small text-muted">${esc(log.note || '—')}</div>
                                </div>
                                ${canDeleteLogs && index === 0
                                    ? `<button class="btn btn-sm btn-outline-danger btn-delete-log" data-id="${log.id}"><i class="fas fa-trash"></i></button>`
                                    : ''}
                            </div>
                        </div>`).join('')}</div>`
                    : '<div class="text-muted small">Chưa có log nào.</div>';

                document.getElementById('progressFgs').innerHTML = data.fgs.length
                    ? `<div class="table-responsive"><table class="table table-sm table-bordered align-middle">
                        <thead class="table-light"><tr><th>FGS</th><th>Loại</th><th class="text-end">SL</th><th>TT</th></tr></thead>
                        <tbody>${data.fgs.map(item => `
                        <tr class="${item.type === 'defect' ? 'table-danger' : ''}">
                            <td>${esc(item.fgs_no)}<div class="small text-muted">${esc(item.source_date)}</div></td>
                            <td>${item.type === 'defect' ? '<span class="badge bg-danger">Lỗi</span>' : '<span class="badge bg-success">HT</span>'}</td>
                            <td class="text-end">${fmtQty(item.qty_in)}</td>
                            <td>${esc(item.status)}</td>
                        </tr>`).join('')}</tbody></table></div>`
                    : '<div class="text-muted small">Chưa phát sinh kho TP.</div>';

                // Xoá log
                document.querySelectorAll('.btn-delete-log').forEach(deleteBtn => {
                    deleteBtn.addEventListener('click', () => {
                        if (!confirm('Chỉ có thể xoá log mới nhất chưa tạo kho TP. Tiếp tục?')) return;
                        const fd = new FormData();
                        fd.append('csrf_token', csrfToken);
                        fd.append('action', 'delete_log');
                        fd.append('log_id', deleteBtn.dataset.id);
                        postForm('/erp/api/production/save_progress.php', fd).then(resp => {
                            if (!resp.ok) return alert(resp.msg);
                            location.reload();
                        });
                    });
                });
            });
    });
});
</script>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/footer.php'; ?>
=======
const CSRF_OUTPUT = '<?= $csrf ?>';

// ── Tạo mới ──────────────────────────────────────────────────
document.getElementById('selReceipt').addEventListener('change', function() {
    const opt = this.options[this.selectedIndex];
    document.getElementById('outProductId').value = opt.dataset.pcid || '';
    document.getElementById('outRemaining').textContent =
        opt.dataset.remaining ? `Còn lại tối đa: ${parseInt(opt.dataset.remaining).toLocaleString()}` : '';
});

document.getElementById('btnSaveOutput').addEventListener('click', () => {
    const form = document.getElementById('formOutput');
    if (!form.checkValidity()) { form.reportValidity(); return; }
    const btn = document.getElementById('btnSaveOutput');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Đang lưu...';
    fetch('/erp/api/production/save_output.php', { method:'POST', body: new FormData(form) })
    .then(r => r.json())
    .then(res => {
        if (res.ok) {
            bootstrap.Modal.getInstance(document.getElementById('modalOutput')).hide();
            location.reload();
        } else { alert('Lỗi: ' + res.msg); }
    })
    .finally(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save me-1"></i>Lưu';
    });
});

// ── Mở modal sửa ─────────────────────────────────────────────
document.querySelectorAll('.btn-edit-output').forEach(btn => {
    btn.addEventListener('click', function() {
        const d = this.dataset;
        document.getElementById('editOutputId').value        = d.id;
        document.getElementById('editOutputNo').textContent  = d.outputNo;
        document.getElementById('editOutputCompleted').value = d.completed;
        document.getElementById('editOutputDefect').value    = d.defect;
        document.getElementById('editOutputNote').value      = d.note;
        document.getElementById('editOutputWarning').classList.toggle('d-none', d.isToday === '1');
        new bootstrap.Modal(document.getElementById('modalEditOutput')).show();
    });
});

// ── Lưu sửa ───────────────────────────────────────────────────
document.getElementById('btnUpdateOutput').addEventListener('click', () => {
    const form = document.getElementById('formEditOutput');
    if (!form.checkValidity()) { form.reportValidity(); return; }
    const btn = document.getElementById('btnUpdateOutput');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Đang lưu...';
    fetch('/erp/api/production/update_output.php', { method:'POST', body: new FormData(form) })
    .then(r => r.json())
    .then(res => {
        if (res.ok) {
            bootstrap.Modal.getInstance(document.getElementById('modalEditOutput')).hide();
            location.reload();
        } else { alert('Lỗi: ' + res.msg); }
    })
    .finally(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save me-1"></i>Lưu thay đổi';
    });
});

// ── Xóa ───────────────────────────────────────────────────────
document.querySelectorAll('.btn-delete-output').forEach(btn => {
    btn.addEventListener('click', function() {
        const d = this.dataset;
        if (!confirm(`Xóa output "${d.name}"?\nHành động không thể hoàn tác!`)) return;
        const fd = new FormData();
        fd.append('csrf_token', CSRF_OUTPUT);
        fd.append('id',     d.id);
        fd.append('action', 'delete');
        fetch('/erp/api/production/update_output.php', { method:'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.ok) { location.reload(); }
            else { alert('Lỗi: ' + res.msg); }
        });
    });
});
</script>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/footer.php'; ?>
>>>>>>> 2d78dfa9065c6d955e30172aa04de19c7e992c2f
