<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';
requireLogin();
requireRole('director','accountant','warehouse','manager');

$pdo  = getDBConnection();
$user = currentUser();

$today     = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$monthStart = date('Y-m-01');

// ── 1. Tồn kho hiện tại (warehouse_stock) ────────────────────
$warehouseStock = $pdo->query("
    SELECT
        pc.product_code,
        pc.description,
        pc.unit,
        COALESCE(ws.qty_pending,   0) AS qty_pending,
        COALESCE(ws.qty_completed, 0) AS qty_completed,
        COALESCE(ws.qty_defect,    0) AS qty_defect,
        COALESCE(ws.qty_pending,0) + COALESCE(ws.qty_completed,0)
            + COALESCE(ws.qty_defect,0) AS qty_total
    FROM product_codes pc
    LEFT JOIN warehouse_stock ws ON ws.product_code_id = pc.id
    WHERE pc.is_active = 1
    ORDER BY pc.product_code
")->fetchAll(PDO::FETCH_ASSOC);

// ── 2. Tồn trong SX hôm nay (production_stock) ───────────────
$productionStock = $pdo->prepare("
    SELECT
        pc.product_code,
        pc.description,
        pc.unit,
        COALESCE(ps.qty_pending,   0) AS qty_pending,
        COALESCE(ps.qty_completed, 0) AS qty_completed,
        COALESCE(ps.qty_defect,    0) AS qty_defect
    FROM product_codes pc
    LEFT JOIN production_stock ps
        ON ps.product_code_id = pc.id AND ps.stock_date = ?
    WHERE pc.is_active = 1
      AND (ps.qty_pending > 0 OR ps.qty_completed > 0 OR ps.qty_defect > 0)
    ORDER BY pc.product_code
");
$productionStock->execute([$today]);
$productionStock = $productionStock->fetchAll(PDO::FETCH_ASSOC);

// ── 3. Tồn kho ngày hôm qua (từ day_close_log) ───────────────
$yesterdayClose = $pdo->prepare("
    SELECT qty_completed_returned, qty_defect_returned,
           qty_pending_returned, close_type, closed_by,
           u.full_name AS closed_by_name
    FROM day_close_log dcl
    LEFT JOIN users u ON dcl.closed_by = u.id
    WHERE close_date = ?
");
$yesterdayClose->execute([$yesterday]);
$yesterdayClose = $yesterdayClose->fetch(PDO::FETCH_ASSOC);

// ── 4. Nhập kho hôm nay ───────────────────────────────────────
$todayImport = $pdo->prepare("
    SELECT
        COALESCE(SUM(wi.quantity), 0) AS total_qty,
        COUNT(wi.id)                  AS total_records
    FROM warehouse_imports wi
    WHERE wi.import_date = ?
");
$todayImport->execute([$today]);
$todayImport = $todayImport->fetch(PDO::FETCH_ASSOC);

// ── 5. Đã giao hôm nay ───────────────────────────────────────
$todayDelivery = $pdo->prepare("
    SELECT
        COALESCE(SUM(dni.quantity), 0) AS total_qty,
        COUNT(DISTINCT dn.id)          AS total_notes
    FROM delivery_notes dn
    JOIN delivery_note_items dni ON dni.delivery_note_id = dn.id
    WHERE dn.delivery_date = ?
      AND dn.status IN ('confirmed','invoiced')
");
$todayDelivery->execute([$today]);
$todayDelivery = $todayDelivery->fetch(PDO::FETCH_ASSOC);

// ── 6. Đã giao trong tháng ────────────────────────────────────
$monthDelivery = $pdo->prepare("
    SELECT
        COALESCE(SUM(dni.quantity), 0)    AS total_qty,
        COALESCE(SUM(dn.total_amount), 0) AS total_amount,
        COUNT(DISTINCT dn.id)             AS total_notes
    FROM delivery_notes dn
    JOIN delivery_note_items dni ON dni.delivery_note_id = dn.id
    WHERE dn.delivery_date BETWEEN ? AND ?
      AND dn.status IN ('confirmed','invoiced')
");
$monthDelivery->execute([$monthStart, $today]);
$monthDelivery = $monthDelivery->fetch(PDO::FETCH_ASSOC);

// ── 7. Đã giao ngày hôm qua ──────────────────────────────────
$yesterdayDelivery = $pdo->prepare("
    SELECT
        COALESCE(SUM(dni.quantity), 0)    AS total_qty,
        COALESCE(SUM(dn.total_amount), 0) AS total_amount
    FROM delivery_notes dn
    JOIN delivery_note_items dni ON dni.delivery_note_id = dn.id
    WHERE dn.delivery_date = ?
      AND dn.status IN ('confirmed','invoiced')
");
$yesterdayDelivery->execute([$yesterday]);
$yesterdayDelivery = $yesterdayDelivery->fetch(PDO::FETCH_ASSOC);

// ── 8. Tổng hợp nhanh ─────────────────────────────────────────
$totalWHPending   = array_sum(array_column($warehouseStock, 'qty_pending'));
$totalWHCompleted = array_sum(array_column($warehouseStock, 'qty_completed'));
$totalWHDefect    = array_sum(array_column($warehouseStock, 'qty_defect'));
$totalWH          = $totalWHPending + $totalWHCompleted + $totalWHDefect;

$totalProdPending   = array_sum(array_column($productionStock, 'qty_pending'));
$totalProdCompleted = array_sum(array_column($productionStock, 'qty_completed'));
$totalProdDefect    = array_sum(array_column($productionStock, 'qty_defect'));

// ── 9. Kiểm tra đã chốt ngày hôm qua chưa ────────────────────
$todayClose = $pdo->prepare("SELECT id FROM day_close_log WHERE close_date = ?");
$todayClose->execute([date('Y-m-d', strtotime('-1 day'))]);
$isYesterdayClosed = (bool)$todayClose->fetchColumn();

$csrf = generateCSRF();
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/sidebar.php';
?>

<div class="main-content">
<?php include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/warehouse_nav.php'; ?>
<div class="container-fluid py-4">

    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1">
                <i class="fas fa-boxes me-2 text-primary"></i>Tổng quan kho
            </h4>
            <p class="text-muted mb-0">
                Cập nhật: <?= date('H:i d/m/Y') ?>
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="/erp/modules/warehouse/import.php" class="btn btn-primary">
                <i class="fas fa-file-import me-1"></i> Nhập SP gia công
            </a>
            <?php if (hasRole('director','manager','warehouse')): ?>
            <button class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#modalCloseDay">
                <i class="fas fa-flag-checkered me-1"></i> Chốt ngày
            </button>
            <?php endif; ?>
        </div>
    </div>

    <?php showFlash(); ?>

    <?php if (!$isYesterdayClosed): ?>
    <div class="alert alert-warning d-flex align-items-center mb-3">
        <i class="fas fa-exclamation-triangle me-2"></i>
        <div>
            <strong>Chưa chốt ngày <?= date('d/m/Y', strtotime($yesterday)) ?>!</strong>
            Tồn trong SX ngày hôm qua chưa được chuyển về kho.
            <button class="btn btn-sm btn-warning ms-2"
                    data-bs-toggle="modal" data-bs-target="#modalCloseDay">
                Chốt ngay
            </button>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── ROW 1: Số liệu nhanh ── -->
    <div class="row g-3 mb-4">
        <!-- Tổng tồn kho -->
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center py-3">
                    <div class="fs-2 fw-bold text-primary"><?= number_format($totalWH) ?></div>
                    <div class="text-muted small">Tổng tồn kho</div>
                    <div class="mt-1">
                        <span class="badge bg-success"><?= number_format($totalWHCompleted) ?> HT</span>
                        <span class="badge bg-danger ms-1"><?= number_format($totalWHDefect) ?> Lỗi</span>
                        <span class="badge bg-secondary ms-1"><?= number_format($totalWHPending) ?> Chờ</span>
                    </div>
                </div>
            </div>
        </div>
        <!-- Tồn trong SX -->
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center py-3">
                    <div class="fs-2 fw-bold text-warning">
                        <?= number_format($totalProdPending + $totalProdCompleted + $totalProdDefect) ?>
                    </div>
                    <div class="text-muted small">Tồn trong SX hôm nay</div>
                    <div class="mt-1">
                        <span class="badge bg-success"><?= number_format($totalProdCompleted) ?> HT</span>
                        <span class="badge bg-danger ms-1"><?= number_format($totalProdDefect) ?> Lỗi</span>
                        <span class="badge bg-secondary ms-1"><?= number_format($totalProdPending) ?> Đang làm</span>
                    </div>
                </div>
            </div>
        </div>
        <!-- Nhập hôm nay -->
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center py-3">
                    <div class="fs-2 fw-bold text-info"><?= number_format($todayImport['total_qty']) ?></div>
                    <div class="text-muted small">Nhập vào hôm nay</div>
                    <div class="mt-1 text-muted small"><?= $todayImport['total_records'] ?> phiếu</div>
                </div>
            </div>
        </div>
        <!-- Giao hôm nay -->
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center py-3">
                    <div class="fs-2 fw-bold text-success"><?= number_format($todayDelivery['total_qty']) ?></div>
                    <div class="text-muted small">Đã giao hôm nay</div>
                    <div class="mt-1 text-muted small"><?= $todayDelivery['total_notes'] ?> biên bản</div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── ROW 2: Hôm qua + Tháng này ── -->
    <div class="row g-3 mb-4">
        <!-- Tồn ngày hôm qua -->
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-bold border-bottom">
                    <i class="fas fa-history me-2 text-secondary"></i>
                    Tồn cuối ngày <?= date('d/m/Y', strtotime($yesterday)) ?>
                    <?php if ($isYesterdayClosed): ?>
                        <span class="badge bg-success ms-1 small">Đã chốt</span>
                    <?php else: ?>
                        <span class="badge bg-warning text-dark ms-1 small">Chưa chốt</span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if ($yesterdayClose): ?>
                    <div class="row text-center g-2">
                        <div class="col-4">
                            <div class="fw-bold text-success fs-5">
                                <?= number_format($yesterdayClose['qty_completed_returned']) ?>
                            </div>
                            <div class="small text-muted">Hoàn thành</div>
                        </div>
                        <div class="col-4">
                            <div class="fw-bold text-danger fs-5">
                                <?= number_format($yesterdayClose['qty_defect_returned']) ?>
                            </div>
                            <div class="small text-muted">Lỗi</div>
                        </div>
                        <div class="col-4">
                            <div class="fw-bold text-secondary fs-5">
                                <?= number_format($yesterdayClose['qty_pending_returned']) ?>
                            </div>
                            <div class="small text-muted">Chưa làm</div>
                        </div>
                    </div>
                    <hr class="my-2">
                    <div class="small text-muted text-center">
                        Chốt bởi:
                        <?= $yesterdayClose['close_type'] === 'auto'
                            ? '<span class="badge bg-secondary">Tự động</span>'
                            : htmlspecialchars($yesterdayClose['closed_by_name'] ?? '—') ?>
                    </div>
                    <?php else: ?>
                    <div class="text-center text-muted py-3">
                        <i class="fas fa-info-circle me-1"></i>Chưa có dữ liệu chốt ngày
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Đã giao hôm qua -->
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-bold border-bottom">
                    <i class="fas fa-truck me-2 text-warning"></i>
                    Giao hàng ngày <?= date('d/m/Y', strtotime($yesterday)) ?>
                </div>
                <div class="card-body text-center">
                    <div class="fw-bold text-warning fs-3">
                        <?= number_format($yesterdayDelivery['total_qty']) ?>
                    </div>
                    <div class="text-muted small mb-2">Sản phẩm đã giao</div>
                    <div class="fw-bold text-success">
                        <?= number_format($yesterdayDelivery['total_amount']) ?> đ
                    </div>
                    <div class="text-muted small">Doanh thu</div>
                </div>
            </div>
        </div>

        <!-- Đã giao trong tháng -->
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-bold border-bottom">
                    <i class="fas fa-calendar-alt me-2 text-success"></i>
                    Tháng <?= date('m/Y') ?>
                </div>
                <div class="card-body text-center">
                    <div class="fw-bold text-success fs-3">
                        <?= number_format($monthDelivery['total_qty']) ?>
                    </div>
                    <div class="text-muted small mb-2">Sản phẩm đã giao</div>
                    <div class="fw-bold text-success">
                        <?= number_format($monthDelivery['total_amount']) ?> đ
                    </div>
                    <div class="text-muted small"><?= $monthDelivery['total_notes'] ?> biên bản</div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── ROW 3: Bảng tồn kho chi tiết + SX ── -->
    <div class="row g-3">
        <!-- Tồn kho chi tiết -->
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-bold">
                    <i class="fas fa-warehouse me-2 text-primary"></i>
                    Tồn kho hiện tại theo mã SP
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 small">
                            <thead class="table-dark">
                                <tr>
                                    <th>Mã SP</th>
                                    <th class="text-end text-success">Hoàn thành</th>
                                    <th class="text-end text-danger">Lỗi</th>
                                    <th class="text-end text-secondary">Chờ SX</th>
                                    <th class="text-end">Tổng</th>
                                    <th class="text-center">Trạng thái</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($warehouseStock)): ?>
                                <tr><td colspan="6" class="text-center text-muted py-4">Chưa có dữ liệu</td></tr>
                            <?php else: ?>
                                <?php foreach ($warehouseStock as $s):
                                    if ($s['qty_total'] <= 0) continue; ?>
                                <tr>
                                    <td>
                                        <span class="badge bg-primary"><?= htmlspecialchars($s['product_code']) ?></span>
                                        <div class="text-muted" style="font-size:0.75rem"><?= htmlspecialchars($s['description']) ?></div>
                                    </td>
                                    <td class="text-end fw-bold text-success">
                                        <?= number_format($s['qty_completed']) ?>
                                        <span class="text-muted fw-normal"><?= $s['unit'] ?></span>
                                    </td>
                                    <td class="text-end text-danger">
                                        <?= number_format($s['qty_defect']) ?>
                                    </td>
                                    <td class="text-end text-secondary">
                                        <?= number_format($s['qty_pending']) ?>
                                    </td>
                                    <td class="text-end fw-bold">
                                        <?= number_format($s['qty_total']) ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($s['qty_completed'] > 0): ?>
                                            <span class="badge bg-success">Sẵn giao</span>
                                        <?php elseif ($s['qty_defect'] > 0): ?>
                                            <span class="badge bg-danger">Có lỗi</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Chờ SX</span>
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

        <!-- Tồn trong SX hôm nay -->
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-bold">
                    <i class="fas fa-industry me-2 text-warning"></i>
                    Tồn trong SX — <?= date('d/m/Y') ?>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($productionStock)): ?>
                    <div class="text-center text-muted py-4">
                        <i class="fas fa-check-circle text-success me-1"></i>
                        SX không có tồn hôm nay
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 small">
                            <thead class="table-light">
                                <tr>
                                    <th>Mã SP</th>
                                    <th class="text-end text-secondary">Đang làm</th>
                                    <th class="text-end text-success">HT</th>
                                    <th class="text-end text-danger">Lỗi</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($productionStock as $ps): ?>
                            <tr>
                                <td>
                                    <span class="badge bg-warning text-dark"><?= htmlspecialchars($ps['product_code']) ?></span>
                                    <div class="text-muted" style="font-size:0.75rem"><?= htmlspecialchars($ps['description']) ?></div>
                                </td>
                                <td class="text-end text-secondary fw-bold"><?= number_format($ps['qty_pending']) ?></td>
                                <td class="text-end text-success fw-bold"><?= number_format($ps['qty_completed']) ?></td>
                                <td class="text-end text-danger"><?= number_format($ps['qty_defect']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="card-footer bg-white small text-muted">
                    <i class="fas fa-info-circle me-1"></i>
                    Tự động về kho lúc 23:59 hoặc khi bấm <strong>Chốt ngày</strong>
                </div>
            </div>
        </div>
    </div>

</div>
</div>

<!-- ── Modal Chốt ngày ── -->
<div class="modal fade" id="modalCloseDay" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title fw-bold">
                    <i class="fas fa-flag-checkered me-2"></i>Chốt ngày
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="formCloseDay">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Ngày cần chốt</label>
                        <input type="date" name="close_date" class="form-control"
                               value="<?= $yesterday ?>" max="<?= $today ?>">
                    </div>
                    <div class="alert alert-warning small">
                        <i class="fas fa-exclamation-triangle me-1"></i>
                        Toàn bộ tồn trong SX của ngày này sẽ được chuyển về kho:
                        <ul class="mb-0 mt-1">
                            <li><strong>Hoàn thành</strong> → Tồn kho (sẵn giao)</li>
                            <li><strong>Lỗi</strong> → Tồn kho (chờ xử lý)</li>
                            <li><strong>Chưa làm</strong> → Tồn kho (chờ chuyển SX lại)</li>
                        </ul>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Huỷ</button>
                <button type="button" class="btn btn-warning fw-bold" id="btnCloseDay">
                    <i class="fas fa-flag-checkered me-1"></i>Xác nhận chốt ngày
                </button>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('btnCloseDay').addEventListener('click', () => {
    const form = document.getElementById('formCloseDay');
    if (!confirm('Bạn có chắc muốn chốt ngày này? Hành động không thể hoàn tác!')) return;

    const btn = document.getElementById('btnCloseDay');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Đang xử lý...';

    fetch('/erp/api/warehouse/close_day.php', {
        method: 'POST',
        body: new FormData(form)
    })
    .then(r => r.json())
    .then(res => {
        if (res.ok) {
            alert('✅ ' + res.msg);
            bootstrap.Modal.getInstance(document.getElementById('modalCloseDay')).hide();
            location.reload();
        } else {
            alert('❌ Lỗi: ' + res.msg);
        }
    })
    .catch(() => alert('❌ Lỗi kết nối server'))
    .finally(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-flag-checkered me-1"></i>Xác nhận chốt ngày';
    });
});
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/footer.php'; ?>