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

// Tổng nhận từ kho hôm nay
$rcvToday = $pdo->prepare("
    SELECT COALESCE(SUM(quantity_received),0) FROM production_receipts WHERE receipt_date = ?
");
$rcvToday->execute([$today]);
$rcvToday = $rcvToday->fetchColumn();

// Tổng output hôm nay
$outToday = $pdo->prepare("
    SELECT COALESCE(SUM(quantity_completed),0) FROM production_outputs WHERE output_date = ?
");
$outToday->execute([$today]);
$outToday = $outToday->fetchColumn();

// Tổng giao hàng tháng này
$delMonth = $pdo->prepare("
    SELECT COALESCE(SUM(di.quantity),0) FROM delivery_items di
    JOIN deliveries d ON di.delivery_id = d.id
    WHERE DATE_FORMAT(d.delivery_date,'%Y-%m') = ?
");
$delMonth->execute([$month]);
$delMonth = $delMonth->fetchColumn();

// Output 7 ngày gần nhất
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

// Tồn kho SX
$sxStock = $pdo->query("
    SELECT pc.product_code, pc.description, pc.unit,
           COALESCE(SUM(pr.quantity_received),0) AS received,
           COALESCE(SUM(po.qty_ok),0)            AS produced
    FROM product_codes pc
    LEFT JOIN production_receipts pr ON pr.product_code_id = pc.id
    LEFT JOIN (
        SELECT product_code_id, SUM(quantity_completed) AS qty_ok
        FROM production_outputs GROUP BY product_code_id
    ) po ON po.product_code_id = pc.id
    WHERE pc.is_active = 1
    GROUP BY pc.id
    HAVING received > 0
    ORDER BY pc.product_code
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
        <!-- Tồn SX -->
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-bold">
                    <i class="fas fa-boxes me-2 text-warning"></i>Tồn kho SX
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 small">
                            <thead class="table-dark">
                                <tr>
                                    <th>Mã SP</th>
                                    <th class="text-end">Đã nhận</th>
                                    <th class="text-end">Đã SX</th>
                                    <th class="text-end">Còn lại</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($sxStock)): ?>
                                <tr><td colspan="4" class="text-center text-muted py-3">Chưa có dữ liệu</td></tr>
                            <?php else: ?>
                                <?php foreach ($sxStock as $s):
                                    $remain = $s['received'] - $s['produced'];
                                ?>
                                <tr>
                                    <td><span class="badge bg-primary"><?= htmlspecialchars($s['product_code']) ?></span>
                                        <div class="small text-muted"><?= htmlspecialchars($s['description']) ?></div>
                                    </td>
                                    <td class="text-end"><?= number_format($s['received']) ?></td>
                                    <td class="text-end text-success"><?= number_format($s['produced']) ?></td>
                                    <td class="text-end fw-bold <?= $remain <= 0 ? 'text-danger' : 'text-warning' ?>">
                                        <?= number_format($remain) ?>
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
                                    <td><span class="badge bg-light text-dark"><?= htmlspecialchars($o['product_code']) ?></span></td>
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