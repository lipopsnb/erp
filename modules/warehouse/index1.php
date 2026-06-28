<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';
requireLogin();
requireRole('director','accountant','warehouse','manager');

$pdo  = getDBConnection();
$user = currentUser();

// ── Tồn kho theo từng mã SP ───────────────────────────────────────────
$stock = $pdo->query("
    SELECT
        pc.product_code,
        pc.description,
        pc.unit,
        COALESCE(SUM(wi.quantity), 0)      AS total_imported,
        COALESCE(SUM(wi.quantity_sent), 0) AS total_sent,
        COALESCE(SUM(wi.quantity), 0)
            - COALESCE(SUM(wi.quantity_sent), 0) AS remaining
    FROM product_codes pc
    LEFT JOIN warehouse_imports wi ON wi.product_code_id = pc.id
    WHERE pc.is_active = 1
    GROUP BY pc.id, pc.product_code, pc.description, pc.unit
    ORDER BY pc.product_code
")->fetchAll(PDO::FETCH_ASSOC);

// ── Thống kê tổng quan ────────────────────────────────────────────────
$totalProducts  = count($stock);
$lowStock       = array_filter($stock, fn($s) => $s['remaining'] <= 0);
$hasStock       = array_filter($stock, fn($s) => $s['remaining'] > 0);

// ── Nhập kho gần đây ──────────────────────────────────────────────────
$recentImports = $pdo->query("
    SELECT wi.*, pc.product_code, pc.description, pc.unit,
           u.full_name AS created_by_name
    FROM warehouse_imports wi
    JOIN product_codes pc ON wi.product_code_id = pc.id
    LEFT JOIN users u ON wi.created_by = u.id
    ORDER BY wi.created_at DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/sidebar.php';

?>

<div class="main-content">
<?php include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/warehouse_nav.php'; ?>
<div class="container-fluid py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="fas fa-boxes me-2 text-primary"></i>Tổng quan kho</h4>
            <p class="text-muted mb-0">Tồn kho real-time theo mã sản phẩm</p>
        </div>
        <a href="/erp/modules/warehouse/import.php" class="btn btn-primary">
            <i class="fas fa-file-import me-1"></i> Nhập SP gia công
        </a>
    </div>

    <?php showFlash(); ?>

    <!-- Thống kê nhanh -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm text-center py-3">
                <div class="fs-2 fw-bold text-primary"><?= $totalProducts ?></div>
                <div class="text-muted small">Tổng mã SP</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm text-center py-3">
                <div class="fs-2 fw-bold text-success"><?= count($hasStock) ?></div>
                <div class="text-muted small">Còn tồn kho</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm text-center py-3">
                <div class="fs-2 fw-bold text-danger"><?= count($lowStock) ?></div>
                <div class="text-muted small">Hết / Âm tồn kho</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm text-center py-3">
                <div class="fs-2 fw-bold text-info">
                    <?= number_format(array_sum(array_column($stock, 'total_imported'))) ?>
                </div>
                <div class="text-muted small">Tổng đã nhập</div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <!-- Bảng tồn kho -->
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-bold">
                    <i class="fas fa-warehouse me-2 text-primary"></i>Tồn kho hiện tại
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-dark">
                                <tr>
                                    <th>Mã SP</th>
                                    <th>Mô tả</th>
                                    <th class="text-end">Nhập</th>
                                    <th class="text-end">Đã → SX</th>
                                    <th class="text-end">Tồn</th>
                                    <th width="80">Trạng thái</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($stock)): ?>
                                <tr><td colspan="6" class="text-center text-muted py-4">Chưa có dữ liệu</td></tr>
                            <?php else: ?>
                                <?php foreach ($stock as $s): ?>
                                <tr>
                                    <td><span class="badge bg-primary"><?= htmlspecialchars($s['product_code']) ?></span></td>
                                    <td class="small"><?= htmlspecialchars($s['description']) ?></td>
                                    <td class="text-end"><?= number_format($s['total_imported']) ?></td>
                                    <td class="text-end text-warning"><?= number_format($s['total_sent']) ?></td>
                                    <td class="text-end fw-bold <?= $s['remaining'] <= 0 ? 'text-danger' : 'text-success' ?>">
                                        <?= number_format($s['remaining']) ?>
                                        <small class="text-muted fw-normal"><?= htmlspecialchars($s['unit']) ?></small>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($s['remaining'] <= 0): ?>
                                            <span class="badge bg-danger">Hết</span>
                                        <?php elseif ($s['remaining'] < 50): ?>
                                            <span class="badge bg-warning text-dark">Thấp</span>
                                        <?php else: ?>
                                            <span class="badge bg-success">OK</span>
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

        <!-- Nhập kho gần đây -->
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <span class="fw-bold"><i class="fas fa-history me-2 text-success"></i>Nhập kho gần đây</span>
                    <a href="/erp/modules/warehouse/import.php" class="btn btn-sm btn-outline-primary">Xem tất cả</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 small">
                            <thead class="table-light">
                                <tr>
                                    <th>Số phiếu</th>
                                    <th>Mã SP</th>
                                    <th class="text-end">SL</th>
                                    <th>Ngày</th>
                                    <th>Trạng thái</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($recentImports)): ?>
                                <tr><td colspan="5" class="text-center text-muted py-3">Chưa có dữ liệu</td></tr>
                            <?php else: ?>
                                <?php foreach ($recentImports as $r): ?>
                                <tr>
                                    <td class="text-primary fw-semibold"><?= htmlspecialchars($r['import_no']) ?></td>
                                    <td><span class="badge bg-light text-dark"><?= htmlspecialchars($r['product_code']) ?></span></td>
                                    <td class="text-end"><?= number_format($r['quantity']) ?></td>
                                    <td class="text-muted"><?= date('d/m', strtotime($r['import_date'])) ?></td>
                                    <td>
                                        <?php
                                        $badges = [
                                            'pending'   => ['warning','Chờ'],
                                            'partial'   => ['info','1 phần'],
                                            'completed' => ['success','Xong'],
                                        ];
                                        $b = $badges[$r['status']] ?? ['secondary','?'];
                                        echo "<span class='badge bg-{$b[0]}'>{$b[1]}</span>";
                                        ?>
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