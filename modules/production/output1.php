<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';
requireLogin();
requireRole('director','accountant','warehouse','production','manager');

$pdo  = getDBConnection();
$user = currentUser();

$filterDate = $_GET['date'] ?? date('Y-m-d');

$outputs = $pdo->prepare("
    SELECT po.*, pc.product_code, pc.description AS product_desc, pc.unit,
           pr.receipt_no,
           u.full_name AS created_by_name
    FROM production_outputs po
    JOIN product_codes pc       ON po.product_code_id       = pc.id
    JOIN production_receipts pr ON po.production_receipt_id = pr.id
    LEFT JOIN users u           ON po.created_by            = u.id
    WHERE po.output_date = ?
    ORDER BY po.created_at DESC
");
$outputs->execute([$filterDate]);
$outputs = $outputs->fetchAll(PDO::FETCH_ASSOC);

$totalOK        = array_sum(array_column($outputs, 'quantity_completed'));
$totalNG        = array_sum(array_column($outputs, 'quantity_defect'));
$totalDelivered = array_sum(array_column($outputs, 'quantity_delivered'));

// ✅ Danh sách phiếu nhận SX còn hàng
$receiptList = $pdo->query("
    SELECT pr.id, pr.receipt_no, pr.receipt_date,
           pc.product_code, pc.description, pc.unit,
           pr.product_code_id,
           pr.quantity_received,
           COALESCE(SUM(po.quantity_completed), 0) AS used
    FROM production_receipts pr
    JOIN product_codes pc ON pr.product_code_id = pc.id
    LEFT JOIN production_outputs po ON po.production_receipt_id = pr.id
    GROUP BY pr.id
    HAVING pr.quantity_received > used
    ORDER BY pr.receipt_date DESC, pc.product_code
")->fetchAll(PDO::FETCH_ASSOC);

$csrf = generateCSRF();
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/sidebar.php';
?>
<div class="main-content">
<?php include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/warehouse_nav.php'; ?>
<div class="container-fluid py-4">

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
                    <span class="badge bg-success fs-6 me-1">Hoàn thành: <?= number_format($totalOK) ?></span>
                    <span class="badge bg-danger fs-6 me-1">Lỗi: <?= number_format($totalNG) ?></span>
                    <span class="badge bg-warning text-dark fs-6">Giao: <?= number_format($totalDelivered) ?></span>
                </div>
                <?php endif; ?>
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
                            <th>Số output</th>
                            <th>Mã SP</th>
                            <th>Phiếu nhận SX</th>
                            <th class="text-end text-success">Hoàn thành</th>
                            <th class="text-end text-danger">Lỗi</th>
                            <th class="text-end text-warning">Giao</th>
                            <th class="text-center">Tỷ lệ OK</th>
                            <th>Người nhập</th>
                            <th>Ghi chú</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($outputs)): ?>
                        <tr><td colspan="9" class="text-center text-muted py-4">Chưa có output nào</td></tr>
                    <?php else: ?>
                        <?php foreach ($outputs as $o):
                            $total = $o['quantity_completed'] + $o['quantity_defect'];
                            $rate  = $total > 0 ? round($o['quantity_completed'] / $total * 100, 1) : 0;
                        ?>
                        <tr>
                            <td class="text-primary fw-semibold"><?= htmlspecialchars($o['output_no']) ?></td>
                            <td><span class="badge bg-primary"><?= htmlspecialchars($o['product_code']) ?></span></td>
                            <td class="small text-muted"><?= htmlspecialchars($o['receipt_no']) ?></td>
                            <td class="text-end fw-bold text-success"><?= number_format($o['quantity_completed']) ?></td>
                            <td class="text-end text-danger"><?= number_format($o['quantity_defect']) ?></td>
                            <td class="text-end text-warning"><?= number_format($o['quantity_delivered']) ?></td>
                            <td class="text-center">
                                <span class="badge bg-<?= $rate>=95?'success':($rate>=80?'warning':'danger') ?>">
                                    <?= $rate ?>%
                                </span>
                            </td>
                            <td class="small"><?= htmlspecialchars($o['created_by_name'] ?? '—') ?></td>
                            <td class="small text-muted"><?= htmlspecialchars($o['note'] ?? '—') ?></td>
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
                            <td class="text-end text-warning"><?= number_format($totalDelivered) ?></td>
                            <td class="text-center">
                                <?php $r = ($totalOK+$totalNG)>0 ? round($totalOK/($totalOK+$totalNG)*100,1) : 0; ?>
                                <span class="badge bg-<?= $r>=95?'success':($r>=80?'warning':'danger') ?>">
                                    <?= $r ?>%
                                </span>
                            </td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>
</div>
</div>

<!-- Modal nhập output -->
<div class="modal fade" id="modalOutput" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="fas fa-clipboard-list me-2"></i>Nhập output cuối ngày
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="formOutput">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="product_code_id" id="outProductId">

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Ngày <span class="text-danger">*</span></label>
                        <input type="date" name="output_date" class="form-control"
                               value="<?= date('Y-m-d') ?>" required>
                    </div>

                    <!-- ✅ Chọn phiếu nhận SX -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold">
                            Phiếu nhận SX <span class="text-danger">*</span>
                        </label>
                        <select name="production_receipt_id" id="selReceipt" class="form-select" required>
                            <option value="">-- Chọn phiếu nhận còn hàng --</option>
                            <?php foreach ($receiptList as $rl):
                                $remaining = $rl['quantity_received'] - $rl['used'];
                            ?>
                            <option value="<?= $rl['id'] ?>"
                                    data-pcid="<?= $rl['product_code_id'] ?>"
                                    data-remaining="<?= $remaining ?>">
                                [<?= htmlspecialchars($rl['product_code']) ?>]
                                <?= htmlspecialchars($rl['description']) ?>
                                — Còn: <?= number_format($remaining) ?> <?= $rl['unit'] ?>
                                (<?= $rl['receipt_no'] ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text text-info" id="outRemaining"></div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-4">
                            <label class="form-label fw-semibold text-success">
                                SL Hoàn thành <span class="text-danger">*</span>
                            </label>
                            <input type="number" name="quantity_completed"
                                   class="form-control border-success"
                                   placeholder="0" min="0" value="0" required>
                        </div>
                        <div class="col-4">
                            <label class="form-label fw-semibold text-danger">SL Lỗi</label>
                            <input type="number" name="quantity_defect"
                                   class="form-control border-danger"
                                   placeholder="0" min="0" value="0">
                        </div>
                        <div class="col-4">
                            <label class="form-label fw-semibold text-warning">SL Giao</label>
                            <input type="number" name="quantity_delivered"
                                   class="form-control border-warning"
                                   placeholder="0" min="0" value="0">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Ghi chú</label>
                        <textarea name="note" class="form-control" rows="2"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Huỷ</button>
                <button type="button" class="btn btn-primary" id="btnSaveOutput">
                    <i class="fas fa-save me-1"></i>Lưu
                </button>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('selReceipt').addEventListener('change', function() {
    const opt = this.options[this.selectedIndex];
    document.getElementById('outProductId').value = opt.dataset.pcid || '';
    document.getElementById('outRemaining').textContent =
        opt.dataset.remaining
            ? `Còn lại tối đa: ${parseInt(opt.dataset.remaining).toLocaleString()}`
            : '';
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
</script>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/footer.php'; ?>