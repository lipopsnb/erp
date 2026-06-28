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
                    <span class="badge bg-success fs-6 me-1">✓ Hoàn thành: <?= number_format($totalOK) ?></span>
                    <span class="badge bg-danger fs-6 me-1">✗ Lỗi: <?= number_format($totalNG) ?></span>
                    <span class="badge bg-primary fs-6">↑ Đã giao: <?= number_format($totalDelivered) ?></span>
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
                </table>
            </div>
        </div>
    </div>
</div>
</div>

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
            </div>
        </div>
    </div>
</div>

<script>
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