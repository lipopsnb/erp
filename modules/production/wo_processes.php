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
<<<<<<< HEAD
        $formId = 'form-wo-' . $r['id'];
=======
>>>>>>> 2d78dfa9065c6d955e30172aa04de19c7e992c2f
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
<<<<<<< HEAD
            <form class="form-wo-process" id="<?= $formId ?>" data-id="<?= $r['id'] ?>">
=======
            <form class="form-wo-process" data-id="<?= $r['id'] ?>">
>>>>>>> 2d78dfa9065c6d955e30172aa04de19c7e992c2f
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="warehouse_in_id" value="<?= $r['id'] ?>">
                <table class="table table-bordered align-middle mb-0">
                    <thead class="table-light">
                        <tr>
<<<<<<< HEAD
                            <!-- Checkbox chọn tất cả -->
                            <th width="36" class="text-center">
                                <input type="checkbox" class="form-check-input chk-all"
                                       data-form="<?= $formId ?>" title="Chọn tất cả">
                            </th>
                            <th width="36">#</th>
=======
                            <th width="40">#</th>
>>>>>>> 2d78dfa9065c6d955e30172aa04de19c7e992c2f
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
<<<<<<< HEAD
                    <tr data-qty="<?= (float)$it['quantity'] ?>" data-row="<?= $j ?>">
                        <!-- Checkbox từng dòng -->
                        <td class="text-center">
                            <input type="checkbox" class="form-check-input chk-row"
                                   data-form="<?= $formId ?>" data-row="<?= $j ?>">
                        </td>
                        <td class="text-muted small"><?= $j + 1 ?></td>
                        <td><span class="badge bg-primary"><?= htmlspecialchars($it['product_code']) ?></span></td>
                        <td class="small"><?= htmlspecialchars($it['description']) ?></td>
                        <td class="text-end fw-semibold"><?= number_format((float)$it['quantity'], 3) ?></td>
=======
                    <tr>
                        <td class="text-muted small"><?= $j + 1 ?></td>
                        <td><span class="badge bg-primary"><?= htmlspecialchars($it['product_code']) ?></span></td>
                        <td class="small"><?= htmlspecialchars($it['description']) ?></td>
                        <td class="text-end"><?= number_format($it['quantity'], 0) ?></td>
>>>>>>> 2d78dfa9065c6d955e30172aa04de19c7e992c2f
                        <td>
                            <input type="hidden" name="items[<?= $j ?>][warehouse_in_item_id]" value="<?= $it['id'] ?>">
                            <input type="hidden" name="items[<?= $j ?>][product_code_id]"      value="<?= $it['product_code_id'] ?>">
                            <input type="number" name="items[<?= $j ?>][quantity_input]"
<<<<<<< HEAD
                                   id="<?= $formId ?>-qi-<?= $j ?>"
=======
>>>>>>> 2d78dfa9065c6d955e30172aa04de19c7e992c2f
                                   class="form-control form-control-sm text-end"
                                   value="<?= $it['quantity_input'] ?? $it['quantity'] ?>"
                                   min="0" step="0.001">
                        </td>
                        <td>
                            <input type="number" name="items[<?= $j ?>][quantity_done]"
<<<<<<< HEAD
                                   id="<?= $formId ?>-qd-<?= $j ?>"
=======
>>>>>>> 2d78dfa9065c6d955e30172aa04de19c7e992c2f
                                   class="form-control form-control-sm text-end"
                                   value="<?= $it['quantity_done'] ?? 0 ?>"
                                   min="0" step="0.001">
                        </td>
                        <td>
                            <input type="number" name="items[<?= $j ?>][quantity_rejected]"
<<<<<<< HEAD
                                   id="<?= $formId ?>-qr-<?= $j ?>"
=======
>>>>>>> 2d78dfa9065c6d955e30172aa04de19c7e992c2f
                                   class="form-control form-control-sm text-end"
                                   value="<?= $it['quantity_rejected'] ?? 0 ?>"
                                   min="0" step="0.001">
                        </td>
                        <td>
                            <input type="date" name="items[<?= $j ?>][process_date]"
<<<<<<< HEAD
                                   id="<?= $formId ?>-pd-<?= $j ?>"
=======
>>>>>>> 2d78dfa9065c6d955e30172aa04de19c7e992c2f
                                   class="form-control form-control-sm"
                                   value="<?= $it['process_date'] ?? date('Y-m-d') ?>">
                        </td>
                        <td>
<<<<<<< HEAD
                            <select name="items[<?= $j ?>][status]"
                                    id="<?= $formId ?>-st-<?= $j ?>"
                                    class="form-select form-select-sm">
=======
                            <select name="items[<?= $j ?>][status]" class="form-select form-select-sm">
>>>>>>> 2d78dfa9065c6d955e30172aa04de19c7e992c2f
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
<<<<<<< HEAD

                <!-- Toolbar dưới bảng -->
                <div class="p-2 d-flex justify-content-between align-items-center bg-light border-top">
                    <div class="d-flex align-items-center gap-2">
                        <!-- Nút hoàn thành loạt: fill qty_done = qty_nhận cho các dòng được tick -->
                        <button type="button"
                                class="btn btn-sm btn-success btn-done-batch"
                                data-form="<?= $formId ?>">
                            <i class="fas fa-check-double me-1"></i>Hoàn thành loạt
                        </button>
                        <span class="text-muted small ms-1 badge-selected-<?= $formId ?>"></span>
                    </div>
                    <button type="submit" class="btn btn-sm btn-primary">
                        <i class="fas fa-save me-1"></i>Lưu tiến độ
=======
                <div class="p-2 text-end bg-light">
                    <button type="submit" class="btn btn-sm btn-success">
                        <i class="fas fa-save me-1"></i> Lưu tiến độ
>>>>>>> 2d78dfa9065c6d955e30172aa04de19c7e992c2f
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
<<<<<<< HEAD
(function () {
    // ── Checkbox "Chọn tất cả" ──────────────────────────────────────────
    document.querySelectorAll('.chk-all').forEach(chkAll => {
        const formId = chkAll.dataset.form;
        chkAll.addEventListener('change', () => {
            document.querySelectorAll(`.chk-row[data-form="${formId}"]`)
                .forEach(c => { c.checked = chkAll.checked; });
            updateBadge(formId);
        });
    });

    // ── Checkbox từng dòng → cập nhật badge đếm ──────────────────────
    document.querySelectorAll('.chk-row').forEach(chk => {
        chk.addEventListener('change', () => {
            const formId = chk.dataset.form;
            updateBadge(formId);
            // Nếu bỏ tick 1 dòng thì bỏ chk-all
            const all = document.querySelectorAll(`.chk-row[data-form="${formId}"]`);
            const allChecked = [...all].every(c => c.checked);
            const chkAll = document.querySelector(`.chk-all[data-form="${formId}"]`);
            if (chkAll) chkAll.checked = allChecked;
        });
    });

    function updateBadge(formId) {
        const count = document.querySelectorAll(`.chk-row[data-form="${formId}"]:checked`).length;
        const badge = document.querySelector(`.badge-selected-${formId}`);
        if (badge) badge.textContent = count > 0 ? `Đã chọn ${count} dòng` : '';
    }

    // ── Nút "Hoàn thành loạt" ───────────────────────────────────────────
    document.querySelectorAll('.btn-done-batch').forEach(btn => {
        btn.addEventListener('click', () => {
            const formId = btn.dataset.form;
            const checked = document.querySelectorAll(`.chk-row[data-form="${formId}"]:checked`);

            if (checked.length === 0) {
                alert('⚠️ Vui lòng chọn ít nhất 1 dòng.');
                return;
            }

            checked.forEach(chk => {
                const row   = chk.dataset.row;
                const tr    = chk.closest('tr');
                const qty   = parseFloat(tr.dataset.qty) || 0;
                const today = new Date().toISOString().slice(0, 10);

                // SL đầu vào = SL nhận
                const qiEl = document.getElementById(`${formId}-qi-${row}`);
                if (qiEl) qiEl.value = qty;

                // SL hoàn thành = SL nhận
                const qdEl = document.getElementById(`${formId}-qd-${row}`);
                if (qdEl) qdEl.value = qty;

                // SL lỗi = 0
                const qrEl = document.getElementById(`${formId}-qr-${row}`);
                if (qrEl) qrEl.value = 0;

                // Ngày GC = hôm nay (nếu chưa có)
                const pdEl = document.getElementById(`${formId}-pd-${row}`);
                if (pdEl && !pdEl.value) pdEl.value = today;

                // Trạng thái = done
                const stEl = document.getElementById(`${formId}-st-${row}`);
                if (stEl) stEl.value = 'done';
            });

            // Highlight các dòng được fill
            checked.forEach(chk => {
                chk.closest('tr').classList.add('table-success');
                setTimeout(() => chk.closest('tr').classList.remove('table-success'), 1500);
            });
        });
    });

    // ── Submit form (Lưu tiến độ) ───────────────────────────────────────
    document.querySelectorAll('.form-wo-process').forEach(form => {
        form.addEventListener('submit', e => {
            e.preventDefault();
            const btn = form.querySelector('[type="submit"]');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Đang lưu...';

            const fd = new FormData(form);
            fetch('/erp/api/production/save_wo_process.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(d => {
                    if (d.ok) {
                        // Toast thay cho alert
                        showToast('success', d.msg);
                        setTimeout(() => location.reload(), 900);
                    } else {
                        showToast('danger', d.msg);
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fas fa-save me-1"></i>Lưu tiến độ';
                    }
                })
                .catch(() => {
                    showToast('danger', 'Lỗi kết nối máy chủ.');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-save me-1"></i>Lưu tiến độ';
                });
        });
    });

    // ── Toast nhỏ góc phải ─────────────────────────────────────────────
    function showToast(type, msg) {
        let container = document.getElementById('wo-toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'wo-toast-container';
            container.style.cssText = 'position:fixed;top:20px;right:20px;z-index:9999;min-width:280px;';
            document.body.appendChild(container);
        }
        const t = document.createElement('div');
        t.className = `alert alert-${type} alert-dismissible shadow mb-2 py-2`;
        t.innerHTML = `<i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'} me-2"></i>${msg}
            <button type="button" class="btn-close" onclick="this.parentElement.remove()"></button>`;
        container.appendChild(t);
        setTimeout(() => t.remove(), 3000);
    }
})();
=======
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
>>>>>>> 2d78dfa9065c6d955e30172aa04de19c7e992c2f
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/footer.php'; ?>
