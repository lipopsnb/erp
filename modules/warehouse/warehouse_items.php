<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';
requireLogin();
requireRole('director','accountant','warehouse','production','manager');

$pdo  = getDBConnection();
$user = currentUser();

$filterStatus = $_GET['status']      ?? '';
$filterCust   = trim($_GET['cust']   ?? '');
$filterCode   = trim($_GET['code']   ?? '');

$where  = ['1=1'];
$params = [];
if ($filterStatus) { $where[] = 'wi.status = ?';           $params[] = $filterStatus; }
if ($filterCust)   { $where[] = 'c.customer_name LIKE ?';  $params[] = "%$filterCust%"; }
if ($filterCode)   { $where[] = 'pc.product_code LIKE ?';  $params[] = "%$filterCode%"; }

$stmt = $pdo->prepare("
    SELECT wi.*,
           pc.product_code, pc.description, pc.unit,
           c.customer_name, c.customer_code,
           wh_in.receipt_no
    FROM warehouse_items wi
    JOIN product_codes pc ON wi.product_code_id = pc.id
    JOIN customers c      ON wi.customer_id      = c.id
    LEFT JOIN warehouse_in wh_in ON wi.warehouse_in_id = wh_in.id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY wi.created_at DESC
");
$stmt->execute($params);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Thống kê nhanh
$stats = $pdo->query("
    SELECT status, COUNT(*) AS cnt, SUM(quantity) AS total_qty
    FROM warehouse_items
    GROUP BY status
")->fetchAll(PDO::FETCH_ASSOC);
$statMap = [];
foreach ($stats as $s) { $statMap[$s['status']] = $s; }

$customers = $pdo->query("
    SELECT id, customer_code, customer_name FROM customers WHERE is_active = 1 ORDER BY customer_name
")->fetchAll(PDO::FETCH_ASSOC);

$csrf = generateCSRF();
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/sidebar.php';
?>
<div class="main-content">
<div class="container-fluid py-4">

    <div class="mb-4">
        <h4 class="mb-1"><i class="fas fa-boxes me-2 text-primary"></i>Kho thành phẩm</h4>
        <p class="text-muted mb-0">Danh sách hàng hóa sau gia công</p>
    </div>

    <?php showFlash(); ?>

    <!-- Thống kê nhanh -->
    <div class="row g-3 mb-4">
        <?php
        $statConfig = [
            'done'      => ['bg-success',   'fa-check-circle',  'Hoàn thành'],
            'waiting'   => ['bg-warning',   'fa-clock',         'Chờ giao'],
            'delivered' => ['bg-primary',   'fa-truck',         'Đã giao'],
            'rejected'  => ['bg-danger',    'fa-times-circle',  'Lỗi-trả lại'],
        ];
        foreach ($statConfig as $key => [$bg, $icon, $label]):
            $cnt = $statMap[$key]['cnt'] ?? 0;
            $qty = $statMap[$key]['total_qty'] ?? 0;
            $bgColor = explode('-', $bg)[1] ?? '';
        ?>
        <div class="col-md-3">
            <a href="?status=<?= $key ?>" class="text-decoration-none">
                <div class="card border-0 shadow-sm <?= $filterStatus === $key ? 'border border-2 border-dark' : '' ?>">
                    <div class="card-body py-3 d-flex align-items-center gap-3">
                        <div class="rounded-circle p-2 bg-<?= $bgColor ?>-subtle text-<?= $bgColor ?> fs-4">
                            <i class="fas <?= $icon ?>"></i>
                        </div>
                        <div>
                            <div class="fw-bold fs-5"><?= number_format($qty, 0) ?></div>
                            <div class="text-muted small"><?= $label ?> (<?= $cnt ?> mục)</div>
                        </div>
                    </div>
                </div>
            </a>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Filter -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2">
            <form class="row g-2 align-items-center" method="GET">
                <div class="col-md-2">
                    <select name="status" class="form-select form-select-sm">
                        <option value="">-- Tất cả TT --</option>
                        <option value="done"      <?= $filterStatus === 'done'      ? 'selected' : '' ?>>Hoàn thành</option>
                        <option value="waiting"   <?= $filterStatus === 'waiting'   ? 'selected' : '' ?>>Chờ giao</option>
                        <option value="delivered" <?= $filterStatus === 'delivered' ? 'selected' : '' ?>>Đã giao</option>
                        <option value="rejected"  <?= $filterStatus === 'rejected'  ? 'selected' : '' ?>>Lỗi-trả lại</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <input type="text" name="cust" class="form-control form-control-sm"
                           placeholder="Khách hàng..." value="<?= htmlspecialchars($filterCust) ?>">
                </div>
                <div class="col-md-2">
                    <input type="text" name="code" class="form-control form-control-sm"
                           placeholder="Mã SP..." value="<?= htmlspecialchars($filterCode) ?>">
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

    <!-- Bảng -->
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th width="50">#</th>
                            <th width="130">Mã SP</th>
                            <th>Mô tả</th>
                            <th>Khách hàng</th>
                            <th>Phiếu NVL</th>
                            <th class="text-end" width="120">Số lượng</th>
                            <th class="text-end" width="120">Đã giao</th>
                            <th width="130">Trạng thái</th>
                            <th width="110">Ngày tạo</th>
                            <?php if (hasRole('director')): ?>
                            <th width="80">Sửa TT</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($items)): ?>
                        <tr><td colspan="<?= hasRole('director') ? 10 : 9 ?>" class="text-center text-muted py-4">Chưa có dữ liệu</td></tr>
                    <?php else: ?>
                        <?php foreach ($items as $i => $it):
                            $statusCfg = [
                                'done'      => ['bg-success',         'Hoàn thành'],
                                'waiting'   => ['bg-warning text-dark','Chờ giao'],
                                'delivered' => ['bg-primary',         'Đã giao'],
                                'rejected'  => ['bg-danger',          'Lỗi-trả lại'],
                            ];
                            [$sCls, $sLbl] = $statusCfg[$it['status']] ?? ['bg-secondary', $it['status']];
                        ?>
                        <tr>
                            <td class="text-muted small"><?= $i + 1 ?></td>
                            <td><span class="badge bg-primary"><?= htmlspecialchars($it['product_code']) ?></span></td>
                            <td class="small"><?= htmlspecialchars($it['description']) ?></td>
                            <td class="small">
                                <?php if ($it['customer_code']): ?>
                                <span class="badge bg-secondary me-1"><?= htmlspecialchars($it['customer_code']) ?></span>
                                <?php endif; ?>
                                <?= htmlspecialchars($it['customer_name']) ?>
                            </td>
                            <td class="small text-muted"><?= htmlspecialchars($it['receipt_no'] ?? '—') ?></td>
                            <td class="text-end fw-bold"><?= number_format($it['quantity'], 0) ?> <small class="text-muted"><?= htmlspecialchars($it['unit'] ?? '') ?></small></td>
                            <td class="text-end"><?= number_format($it['quantity_delivered'], 0) ?></td>
                            <td><span class="badge <?= $sCls ?>"><?= $sLbl ?></span></td>
                            <td class="small text-muted"><?= date('d/m/Y', strtotime($it['created_at'])) ?></td>
                            <?php if (hasRole('director')): ?>
                            <td>
                                <select class="form-select form-select-sm item-status-select"
                                        data-id="<?= $it['id'] ?>">
                                    <option value="done"      <?= $it['status'] === 'done'      ? 'selected' : '' ?>>Hoàn thành</option>
                                    <option value="waiting"   <?= $it['status'] === 'waiting'   ? 'selected' : '' ?>>Chờ giao</option>
                                    <option value="delivered" <?= $it['status'] === 'delivered' ? 'selected' : '' ?>>Đã giao</option>
                                    <option value="rejected"  <?= $it['status'] === 'rejected'  ? 'selected' : '' ?>>Lỗi-trả lại</option>
                                </select>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer text-muted small">
            Tổng: <strong><?= count($items) ?></strong> mục
        </div>
    </div>
</div>
</div>

<?php if (hasRole('director')): ?>
<script>
const csrf = '<?= $csrf ?>';
document.querySelectorAll('.item-status-select').forEach(sel => {
    sel.addEventListener('change', () => {
        const fd = new FormData();
        fd.append('csrf_token', csrf);
        fd.append('id', sel.dataset.id);
        fd.append('status', sel.value);
        fetch('/erp/api/warehouse/update_item_status.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (!d.ok) alert('Lỗi: ' + d.msg);
            });
    });
});
</script>
<?php endif; ?>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/footer.php'; ?>
