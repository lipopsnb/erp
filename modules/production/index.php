<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';
requireLogin();
requireRole('director','accountant','warehouse','production','manager');

$pdo  = getDBConnection();
$user = currentUser();

$today = date('Y-m-d');
$month = date('Y-m');

// ── 1. Tổng nhận từ kho hôm nay ──────────────────────────────
$rcvToday = $pdo->prepare("
    SELECT COALESCE(SUM(quantity_received), 0)
    FROM production_receipts
    WHERE receipt_date = ?
");
$rcvToday->execute([$today]);
$rcvToday = (float)$rcvToday->fetchColumn();

// ── 2. Tổng output OK hôm nay ────────────────────────────────
$outToday = $pdo->prepare("
    SELECT COALESCE(SUM(quantity_completed), 0)
    FROM production_outputs
    WHERE output_date = ?
");
$outToday->execute([$today]);
$outToday = (float)$outToday->fetchColumn();

// ── 3. Tổng giao hàng tháng này ──────────────────────────────
// ✅ Fix: dùng delivery_notes + delivery_note_items (bảng thật)
$delMonth = $pdo->prepare("
    SELECT COALESCE(SUM(dni.quantity), 0)
    FROM delivery_note_items dni
    JOIN delivery_notes dn ON dni.delivery_note_id = dn.id
    WHERE DATE_FORMAT(dn.delivery_date, '%Y-%m') = ?
      AND dn.status IN ('confirmed', 'invoiced')
");
$delMonth->execute([$month]);
$delMonth = (float)$delMonth->fetchColumn();

// ── 4. Tồn kho SX hôm nay ────────────────────────────────────
// ✅ Fix: đọc từ production_stock thay vì tính toán cũ
$sxStock = $pdo->prepare("
    SELECT pc.product_code,
           pc.description,
           pc.unit,
           COALESCE(ps.qty_pending,   0) AS qty_pending,
           COALESCE(ps.qty_completed, 0) AS qty_completed,
           COALESCE(ps.qty_defect,    0) AS qty_defect,
           COALESCE(ps.qty_pending, 0)
               + COALESCE(ps.qty_completed, 0)
               + COALESCE(ps.qty_defect, 0) AS total_remain
    FROM product_codes pc
    JOIN production_stock ps ON ps.product_code_id = pc.id
    WHERE pc.is_active = 1
      AND ps.stock_date = ?
      AND (ps.qty_pending + ps.qty_completed + ps.qty_defect) > 0
    ORDER BY pc.product_code
");
$sxStock->execute([$today]);
$sxStock = $sxStock->fetchAll(PDO::FETCH_ASSOC);

// ── 5. Output 7 ngày gần nhất ────────────────────────────────
$recentOutput = $pdo->query("
    SELECT po.output_date,
           pc.product_code,
           SUM(po.quantity_completed) AS qty_ok,
           SUM(po.quantity_defect)    AS qty_ng
    FROM production_outputs po
    JOIN product_codes pc ON po.product_code_id = pc.id
    WHERE po.output_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    GROUP BY po.output_date, po.product_code_id
    ORDER BY po.output_date DESC, pc.product_code
")->fetchAll(PDO::FETCH_ASSOC);

include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/sidebar.php';
?>
<div class="main-content">
<?php include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/warehouse_nav.php'; ?>
<div class="container-fluid py-4">

    <div class="mb-4">
        <h4 class="mb-1"><i class="fas fa-industry me-2 text-primary"></i>Tổng quan sản xuất</h4>
        <p class="text-muted mb-0">Hôm nay: <strong><?= date('d/m/Y') ?></strong></p>
    </div>

    <!-- Thống kê nhanh -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm text-center py-3">
                <div class="fs-2 fw-bold text-info"><?= number_format($rcvToday) ?></div>
                <div class="text-muted small">Nhận từ kho hôm nay</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm text-center py-3">
                <div class="fs-2 fw-bold text-success"><?= number_format($outToday) ?></div>
                <div class="text-muted small">Output OK hôm nay</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm text-center py-3">
                <div class="fs-2 fw-bold text-primary"><?= number_format($delMonth) ?></div>
                <div class="text-muted small">Giao hàng tháng <?= date('m/Y') ?></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm text-center py-3">
                <div class="fs-2 fw-bold text-warning"><?= count($sxStock) ?></div>
                <div class="text-muted small">Mã SP đang SX</div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <!-- Tồn SX hôm nay -->
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-bold d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-boxes me-2 text-warning"></i>Tồn kho SX hôm nay</span>
                    <span class="badge bg-warning text-dark"><?= date('d/m/Y') ?></span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 small">
                            <thead class="table-dark">
                                <tr>
                                    <th>Mã SP</th>
                                    <th class="text-end text-secondary">Đang làm</th>
                                    <th class="text-end text-success">Hoàn thành</th>
                                    <th class="text-end text-danger">Lỗi</th>
                                    <th class="text-end">Tổng</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($sxStock)): ?>
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-3">
                                        <i class="fas fa-check-circle text-success me-1"></i>
                                        Không có tồn trong SX hôm nay
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($sxStock as $s): ?>
                                <tr>
                                    <td>
                                        <span class="badge bg-primary"><?= htmlspecialchars($s['product_code']) ?></span>
                                        <div class="small text-muted"><?= htmlspecialchars($s['description']) ?></div>
                                    </td>
                                    <td class="text-end text-secondary fw-bold">
                                        <?= number_format($s['qty_pending']) ?>
                                    </td>
                                    <td class="text-end text-success fw-bold">
                                        <?= number_format($s['qty_completed']) ?>
                                    </td>
                                    <td class="text-end text-danger">
                                        <?= number_format($s['qty_defect']) ?>
                                    </td>
                                    <td class="text-end fw-bold text-warning">
                                        <?= number_format($s['total_remain']) ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer bg-white small text-muted">
                    <i class="fas fa-info-circle me-1"></i>
                    Tự động về kho lúc 23:59 hoặc khi <strong>Chốt ngày</strong>
                </div>
            </div>
        </div>

        <!-- Output 7 ngày -->
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-bold">
                    <i class="fas fa-chart-bar me-2 text-success"></i>Output 7 ngày gần nhất
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 small">
                            <thead class="table-dark">
                                <tr>
                                    <th>Ngày</th>
                                    <th>Mã SP</th>
                                    <th class="text-end text-success">Hoàn thành</th>
                                    <th class="text-end text-danger">Lỗi</th>
                                    <th class="text-end">Tỷ lệ OK</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($recentOutput)): ?>
                                <tr><td colspan="5" class="text-center text-muted py-3">Chưa có dữ liệu</td></tr>
                            <?php else: ?>
                                <?php foreach ($recentOutput as $o):
                                    $total = $o['qty_ok'] + $o['qty_ng'];
                                    $rate  = $total > 0 ? round($o['qty_ok'] / $total * 100, 1) : 0;
                                ?>
                                <tr>
                                    <td><?= date('d/m', strtotime($o['output_date'])) ?></td>
                                    <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($o['product_code']) ?></span></td>
                                    <td class="text-end text-success fw-bold"><?= number_format($o['qty_ok']) ?></td>
                                    <td class="text-end text-danger"><?= number_format($o['qty_ng']) ?></td>
                                    <td class="text-end">
                                        <span class="badge bg-<?= $rate>=95?'success':($rate>=80?'warning':'danger') ?>">
                                            <?= $rate ?>%
                                        </span>
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
</div>
</div>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/footer.php'; ?>