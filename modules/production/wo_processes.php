<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';
requireLogin();
requireRole('director','accountant','warehouse','production','manager');

$pdo  = getDBConnection();
$user = currentUser();

$filterCust = trim($_GET['cust'] ?? '');

$where  = ["wi.status IN ('processing','done')"];
$params = [];
if ($filterCust) {
    $where[]  = 'c.customer_name LIKE ?';
    $params[] = "%$filterCust%";
}

// Lấy các phiếu đang/đã gia công
$stmt = $pdo->prepare("
    SELECT wi.*,
           c.customer_name, c.customer_code,
           COUNT(wii.id)     AS item_count,
           SUM(wii.quantity) AS total_qty
    FROM warehouse_in wi
    LEFT JOIN customers c            ON wi.customer_id = c.id
    LEFT JOIN warehouse_in_items wii ON wii.warehouse_in_id = wi.id
    WHERE " . implode(' AND ', $where) . "
    GROUP BY wi.id
    ORDER BY wi.receipt_date DESC, wi.id DESC
");
$stmt->execute($params);
$receiptList = $stmt->fetchAll(PDO::FETCH_ASSOC);

$csrf = generateCSRF();
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/sidebar.php';
?>
<div class="main-content">
<div class="container-fluid py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="fas fa-cogs me-2 text-primary"></i>Tiến độ gia công</h4>
            <p class="text-muted mb-0">Cập nhật số lượng hoàn thành / lỗi cho từng phiếu nhập kho</p>
        </div>
    </div>

    <?php showFlash(); ?>

    <!-- Filter -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2">
            <form class="row g-2 align-items-center" method="GET">
                <div class="col-md-3">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text"><i class="fas fa-search"></i></span>
                        <input type="text" name="cust" class="form-control"
                               placeholder="Khách hàng..." value="<?= htmlspecialchars($filterCust) ?>">
                    </div>
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

    <?php if (empty($receiptList)): ?>
    <div class="alert alert-info border-0 shadow-sm">
        <i class="fas fa-info-circle me-2"></i>
        Không có phiếu nào đang gia công. Hãy tạo phiếu nhập kho và bấm "Bắt đầu gia công".
    </div>
    <?php else: ?>

    <?php foreach ($receiptList as $r):
        // Lấy items của phiếu
        $wItemsStmt = $pdo->prepare("
            SELECT wii.*,
                   pc.product_code, pc.description, pc.unit,
                   wp.id AS wp_id, wp.quantity_input, wp.quantity_done,
                   wp.quantity_rejected, wp.status AS wp_status,
                   wp.process_date, wp.note AS wp_note
            FROM warehouse_in_items wii
            JOIN product_codes pc ON wii.product_code_id = pc.id
            LEFT JOIN wo_processes wp ON wp.warehouse_in_item_id = wii.id
                                      AND wp.warehouse_in_id = wii.warehouse_in_id
            WHERE wii.warehouse_in_id = ?
            ORDER BY wii.id
        ");
        $wItemsStmt->execute([$r['id']]);
        $wItems = $wItemsStmt->fetchAll(PDO::FETCH_ASSOC);
        $statusMap = [
            'open'       => ['bg-warning text-dark', 'Mở'],
            'processing' => ['bg-info text-white',   'Đang gia công'],
            'done'       => ['bg-success',           'Hoàn thành'],
        ];
        [$cls, $lbl] = $statusMap[$r['status']] ?? ['bg-secondary', $r['status']];
    ?>
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <div>
                <span class="fw-bold text-primary me-2"><?= htmlspecialchars($r['receipt_no']) ?></span>
                <span class="text-muted me-3"><?= date('d/m/Y', strtotime($r['receipt_date'])) ?></span>
                <span class="badge bg-secondary me-1"><?= htmlspecialchars($r['customer_code'] ?? '') ?></span>
                <span><?= htmlspecialchars($r['customer_name'] ?? '') ?></span>
            </div>
            <span class="badge <?= $cls ?>"><?= $lbl ?></span>
        </div>
        <div class="card-body p-0">
            <form class="form-wo-process" data-id="<?= $r['id'] ?>">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="warehouse_in_id" value="<?= $r['id'] ?>">
                <table class="table table-bordered align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th width="40">#</th>
                            <th>Mã SP</th>
                            <th>Mô tả</th>
                            <th class="text-end">SL nhận</th>
                            <th class="text-end" width="120">SL đầu vào</th>
                            <th class="text-end" width="130">SL hoàn thành</th>
                            <th class="text-end" width="110">SL lỗi</th>
                            <th width="130">Ngày GC</th>
                            <th width="110">Trạng thái</th>
                            <th>Ghi chú</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($wItems as $j => $it): ?>
                    <tr>
                        <td class="text-muted small"><?= $j + 1 ?></td>
                        <td><span class="badge bg-primary"><?= htmlspecialchars($it['product_code']) ?></span></td>
                        <td class="small"><?= htmlspecialchars($it['description']) ?></td>
                        <td class="text-end"><?= number_format($it['quantity'], 0) ?></td>
                        <td>
                            <input type="hidden" name="items[<?= $j ?>][warehouse_in_item_id]" value="<?= $it['id'] ?>">
                            <input type="hidden" name="items[<?= $j ?>][product_code_id]"      value="<?= $it['product_code_id'] ?>">
                            <input type="number" name="items[<?= $j ?>][quantity_input]"
                                   class="form-control form-control-sm text-end"
                                   value="<?= $it['quantity_input'] ?? $it['quantity'] ?>"
                                   min="0" step="0.001">
                        </td>
                        <td>
                            <input type="number" name="items[<?= $j ?>][quantity_done]"
                                   class="form-control form-control-sm text-end"
                                   value="<?= $it['quantity_done'] ?? 0 ?>"
                                   min="0" step="0.001">
                        </td>
                        <td>
                            <input type="number" name="items[<?= $j ?>][quantity_rejected]"
                                   class="form-control form-control-sm text-end"
                                   value="<?= $it['quantity_rejected'] ?? 0 ?>"
                                   min="0" step="0.001">
                        </td>
                        <td>
                            <input type="date" name="items[<?= $j ?>][process_date]"
                                   class="form-control form-control-sm"
                                   value="<?= $it['process_date'] ?? date('Y-m-d') ?>">
                        </td>
                        <td>
                            <select name="items[<?= $j ?>][status]" class="form-select form-select-sm">
                                <option value="processing" <?= ($it['wp_status'] ?? '') !== 'done' ? 'selected' : '' ?>>Chưa xong</option>
                                <option value="done"       <?= ($it['wp_status'] ?? '') === 'done' ? 'selected' : '' ?>>Hoàn thành</option>
                            </select>
                        </td>
                        <td>
                            <input type="text" name="items[<?= $j ?>][note]"
                                   class="form-control form-control-sm"
                                   value="<?= htmlspecialchars($it['wp_note'] ?? '') ?>"
                                   placeholder="Ghi chú...">
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="p-2 text-end bg-light">
                    <button type="submit" class="btn btn-sm btn-success">
                        <i class="fas fa-save me-1"></i> Lưu tiến độ
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

</div>
</div>

<script>
document.querySelectorAll('.form-wo-process').forEach(form => {
    form.addEventListener('submit', e => {
        e.preventDefault();
        const fd = new FormData(form);
        fetch('/erp/api/production/save_wo_process.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (d.ok) {
                    alert('✅ ' + d.msg);
                    location.reload();
                } else {
                    alert('❌ Lỗi: ' + d.msg);
                }
            });
    });
});
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/footer.php'; ?>
