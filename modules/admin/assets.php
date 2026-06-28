<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';
requireLogin();
requireRole('director','accountant','manager');

$pdo = getDBConnection();
$filterCategory = trim($_GET['category'] ?? '');
$filterStatus = trim($_GET['status'] ?? '');
$where = ['1=1'];
$params = [];
if ($filterCategory !== '') {
    $where[] = 'ca.category = ?';
    $params[] = $filterCategory;
}
if ($filterStatus !== '') {
    $where[] = 'ca.status = ?';
    $params[] = $filterStatus;
}

$assetsStmt = $pdo->prepare("SELECT ca.*, aa.id AS current_assignment_id, aa.assigned_date AS current_assigned_date,
        u.full_name AS current_user_name
    FROM company_assets ca
    LEFT JOIN asset_assignments aa ON aa.asset_id = ca.id AND aa.returned_date IS NULL
    LEFT JOIN users u ON u.id = aa.user_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY ca.created_at DESC, ca.id DESC");
$assetsStmt->execute($params);
$assets = $assetsStmt->fetchAll(PDO::FETCH_ASSOC);

$statsWhere = ['1=1'];
$statsParams = [];
if ($filterCategory !== '') {
    $statsWhere[] = 'category = ?';
    $statsParams[] = $filterCategory;
}
$statsStmt = $pdo->prepare("SELECT
        COUNT(*) AS total_assets,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_assets,
        SUM(CASE WHEN status = 'assigned' THEN 1 ELSE 0 END) AS assigned_assets,
        SUM(CASE WHEN status = 'maintenance' THEN 1 ELSE 0 END) AS maintenance_assets
    FROM company_assets
    WHERE " . implode(' AND ', $statsWhere));
$statsStmt->execute($statsParams);
$stats = $statsStmt->fetch() ?: ['total_assets' => 0, 'active_assets' => 0, 'assigned_assets' => 0, 'maintenance_assets' => 0];

$employees = $pdo->query("SELECT id, full_name, username FROM users WHERE is_active = 1 ORDER BY full_name")
    ->fetchAll(PDO::FETCH_ASSOC);

$assignmentsByAsset = [];
if ($assets) {
    $assetIds = array_column($assets, 'id');
    $placeholders = implode(',', array_fill(0, count($assetIds), '?'));
    $assignStmt = $pdo->prepare("SELECT aa.*, u.full_name, cb.full_name AS created_by_name
        FROM asset_assignments aa
        JOIN users u ON u.id = aa.user_id
        LEFT JOIN users cb ON cb.id = aa.created_by
        WHERE aa.asset_id IN ($placeholders)
        ORDER BY aa.assigned_date DESC, aa.id DESC");
    $assignStmt->execute($assetIds);
    foreach ($assignStmt->fetchAll(PDO::FETCH_ASSOC) as $assignment) {
        $assignmentsByAsset[$assignment['asset_id']][] = $assignment;
    }
}

$assetsJson = [];
foreach ($assets as $asset) {
    $assetsJson[$asset['id']] = $asset;
}

$categoryMap = [
    'computer' => 'Máy tính',
    'printer' => 'Máy in',
    'furniture' => 'Bàn ghế',
    'machinery' => 'Máy móc',
    'vehicle' => 'Phương tiện',
    'other' => 'Khác',
];
$statusMap = [
    'active' => ['success', 'Đang dùng'],
    'assigned' => ['primary', 'Đã cấp phát'],
    'maintenance' => ['warning text-dark', 'Bảo dưỡng'],
    'disposed' => ['secondary', 'Thanh lý'],
];

$csrf = generateCSRF();
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/sidebar.php';
?>
<div class="main-content">
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="fas fa-laptop me-2 text-primary"></i>Tài sản công ty</h4>
            <p class="text-muted mb-0">Quản lý tài sản, tình trạng sử dụng và lịch sử cấp phát</p>
        </div>
        <button class="btn btn-primary" id="btnCreateAsset"><i class="fas fa-plus me-1"></i> Thêm tài sản</button>
    </div>

    <?php showFlash(); ?>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2">
            <form class="row g-2 align-items-center" method="GET">
                <div class="col-md-3">
                    <select name="category" class="form-select form-select-sm">
                        <option value="">-- Loại tài sản --</option>
                        <?php foreach ($categoryMap as $value => $label): ?>
                        <option value="<?= $value ?>" <?= $filterCategory === $value ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <select name="status" class="form-select form-select-sm">
                        <option value="">-- Trạng thái --</option>
                        <?php foreach ($statusMap as $value => $meta): ?>
                        <option value="<?= $value ?>" <?= $filterStatus === $value ? 'selected' : '' ?>><?= $meta[1] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-filter me-1"></i>Lọc</button>
                    <a href="?" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-3"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small mb-1">Tổng tài sản</div><h4 class="mb-0 text-primary"><?= (int)$stats['total_assets'] ?></h4></div></div></div>
        <div class="col-md-3"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small mb-1">Đang dùng</div><h4 class="mb-0 text-success"><?= (int)$stats['active_assets'] ?></h4></div></div></div>
        <div class="col-md-3"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small mb-1">Đã cấp phát</div><h4 class="mb-0 text-primary"><?= (int)$stats['assigned_assets'] ?></h4></div></div></div>
        <div class="col-md-3"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small mb-1">Bảo dưỡng</div><h4 class="mb-0 text-warning"><?= (int)$stats['maintenance_assets'] ?></h4></div></div></div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th width="120">Mã TS</th>
                            <th>Tên</th>
                            <th width="140">Loại</th>
                            <th width="110">Ngày mua</th>
                            <th width="130" class="text-end">Giá mua</th>
                            <th width="160">Vị trí</th>
                            <th width="130">Trạng thái</th>
                            <th width="240">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$assets): ?>
                        <tr><td colspan="8" class="text-center text-muted py-4">Chưa có tài sản nào</td></tr>
                    <?php else: ?>
                        <?php foreach ($assets as $asset): ?>
                        <?php [$statusClass, $statusLabel] = $statusMap[$asset['status']] ?? ['secondary', $asset['status']]; ?>
                        <tr>
                            <td class="fw-semibold text-primary"><?= htmlspecialchars($asset['asset_code']) ?></td>
                            <td>
                                <div class="fw-semibold"><?= htmlspecialchars($asset['asset_name']) ?></div>
                                <div class="small text-muted"><?= htmlspecialchars($asset['supplier'] ?: '—') ?></div>
                            </td>
                            <td><?= htmlspecialchars($categoryMap[$asset['category']] ?? $asset['category']) ?></td>
                            <td><?= formatDate($asset['purchase_date']) ?></td>
                            <td class="text-end"><?= number_format((float)$asset['purchase_price'], 0, ',', '.') ?></td>
                            <td><?= htmlspecialchars($asset['location'] ?: '—') ?></td>
                            <td>
                                <span class="badge bg-<?= $statusClass ?>"><?= $statusLabel ?></span>
                                <?php if ($asset['current_user_name']): ?>
                                <div class="small text-muted mt-1"><?= htmlspecialchars($asset['current_user_name']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button class="btn btn-xs btn-outline-primary btn-history-asset" data-id="<?= $asset['id'] ?>" title="Lịch sử cấp phát"><i class="fas fa-clock-rotate-left"></i></button>
                                <button class="btn btn-xs btn-outline-warning ms-1 btn-edit-asset" data-id="<?= $asset['id'] ?>" title="Sửa"><i class="fas fa-edit"></i></button>
                                <?php if ($asset['status'] !== 'assigned' && $asset['status'] !== 'disposed'): ?>
                                <button class="btn btn-xs btn-outline-success ms-1 btn-assign-asset" data-id="<?= $asset['id'] ?>" title="Cấp phát"><i class="fas fa-user-check"></i></button>
                                <?php endif; ?>
                                <?php if (!empty($asset['current_assignment_id'])): ?>
                                <button class="btn btn-xs btn-outline-info ms-1 btn-return-asset" data-assignment-id="<?= $asset['current_assignment_id'] ?>" title="Thu hồi"><i class="fas fa-undo"></i></button>
                                <?php endif; ?>
                                <button class="btn btn-xs btn-outline-danger ms-1 btn-delete-asset" data-id="<?= $asset['id'] ?>" title="Xoá"><i class="fas fa-trash"></i></button>
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

<div class="modal fade" id="modalAsset" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="assetModalTitle"><i class="fas fa-laptop me-2"></i>Thêm tài sản</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="formAsset">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="id" id="assetId" value="">
                    <div class="row g-3">
                        <div class="col-md-4"><label class="form-label fw-semibold">Mã tài sản <span class="text-danger">*</span></label><input type="text" name="asset_code" id="assetCode" class="form-control" required></div>
                        <div class="col-md-8"><label class="form-label fw-semibold">Tên tài sản <span class="text-danger">*</span></label><input type="text" name="asset_name" id="assetName" class="form-control" required></div>
                        <div class="col-md-4"><label class="form-label fw-semibold">Loại</label><select name="category" id="assetCategory" class="form-select"><?php foreach ($categoryMap as $value => $label): ?><option value="<?= $value ?>"><?= $label ?></option><?php endforeach; ?></select></div>
                        <div class="col-md-4"><label class="form-label fw-semibold">Ngày mua</label><input type="date" name="purchase_date" id="assetPurchaseDate" class="form-control"></div>
                        <div class="col-md-4"><label class="form-label fw-semibold">Giá mua</label><input type="number" name="purchase_price" id="assetPurchasePrice" class="form-control text-end" step="0.01" min="0"></div>
                        <div class="col-md-4"><label class="form-label fw-semibold">Nhà cung cấp</label><input type="text" name="supplier" id="assetSupplier" class="form-control"></div>
                        <div class="col-md-4"><label class="form-label fw-semibold">Vị trí</label><input type="text" name="location" id="assetLocation" class="form-control"></div>
                        <div class="col-md-4"><label class="form-label fw-semibold">Trạng thái</label><select name="status" id="assetStatus" class="form-select"><?php foreach ($statusMap as $value => $meta): ?><option value="<?= $value ?>"><?= $meta[1] ?></option><?php endforeach; ?></select></div>
                        <div class="col-md-12"><label class="form-label fw-semibold">Ghi chú</label><textarea name="note" id="assetNote" class="form-control" rows="2"></textarea></div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Huỷ</button>
                <button type="button" class="btn btn-primary" id="btnSaveAsset"><i class="fas fa-save me-1"></i>Lưu tài sản</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalAssignAsset" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-user-check me-2"></i>Cấp phát tài sản</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="formAssignAsset">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="asset_id" id="assignAssetId" value="">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Nhân viên <span class="text-danger">*</span></label>
                        <select name="user_id" id="assignUserId" class="form-select" required>
                            <option value="">-- Chọn nhân viên --</option>
                            <?php foreach ($employees as $employee): ?>
                            <option value="<?= $employee['id'] ?>"><?= htmlspecialchars($employee['full_name']) ?> (<?= htmlspecialchars($employee['username']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Ngày cấp <span class="text-danger">*</span></label>
                        <input type="date" name="assigned_date" id="assignDate" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div>
                        <label class="form-label fw-semibold">Ghi chú</label>
                        <textarea name="note" id="assignNote" class="form-control" rows="2"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Huỷ</button>
                <button type="button" class="btn btn-success" id="btnSaveAssignAsset"><i class="fas fa-save me-1"></i>Lưu cấp phát</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalAssetHistory" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title"><i class="fas fa-clock-rotate-left me-2"></i>Lịch sử cấp phát</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="assetHistoryBody"></div>
        </div>
    </div>
</div>

<script>
const csrfAsset = '<?= $csrf ?>';
const assetsData = <?= json_encode($assetsJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const assignmentsByAsset = <?= json_encode($assignmentsByAsset, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const assetModal = new bootstrap.Modal(document.getElementById('modalAsset'));
const assignModal = new bootstrap.Modal(document.getElementById('modalAssignAsset'));
const historyModal = new bootstrap.Modal(document.getElementById('modalAssetHistory'));
const categoryMap = <?= json_encode($categoryMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const statusTextMap = <?= json_encode(array_map(static fn($meta) => $meta[1], $statusMap), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

function escHtml(value) {
    return String(value || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

async function postAssetAction(action, payload = {}) {
    const fd = new FormData();
    fd.append('csrf_token', csrfAsset);
    fd.append('action', action);
    Object.entries(payload).forEach(([key, value]) => fd.append(key, value));
    const response = await fetch('/erp/api/admin/save_asset.php', { method: 'POST', body: fd });
    const data = await response.json();
    if (data.ok) {
        location.reload();
        return;
    }
    alert(data.msg || 'Có lỗi xảy ra');
}

function resetAssetForm() {
    document.getElementById('formAsset').reset();
    document.getElementById('assetId').value = '';
    document.getElementById('assetStatus').value = 'active';
    document.getElementById('assetCategory').value = 'other';
    document.getElementById('assetModalTitle').innerHTML = '<i class="fas fa-laptop me-2"></i>Thêm tài sản';
}

document.getElementById('btnCreateAsset').addEventListener('click', () => {
    resetAssetForm();
    assetModal.show();
});

document.getElementById('btnSaveAsset').addEventListener('click', async () => {
    const form = document.getElementById('formAsset');
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }
    const fd = new FormData(form);
    fd.append('action', document.getElementById('assetId').value ? 'edit' : 'add');
    const response = await fetch('/erp/api/admin/save_asset.php', { method: 'POST', body: fd });
    const data = await response.json();
    if (data.ok) {
        location.reload();
        return;
    }
    alert(data.msg || 'Không thể lưu tài sản');
});

document.querySelectorAll('.btn-edit-asset').forEach(btn => {
    btn.addEventListener('click', () => {
        const row = assetsData[btn.dataset.id];
        if (!row) return;
        resetAssetForm();
        document.getElementById('assetModalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Sửa tài sản';
        document.getElementById('assetId').value = row.id;
        document.getElementById('assetCode').value = row.asset_code || '';
        document.getElementById('assetName').value = row.asset_name || '';
        document.getElementById('assetCategory').value = row.category || 'other';
        document.getElementById('assetPurchaseDate').value = row.purchase_date || '';
        document.getElementById('assetPurchasePrice').value = row.purchase_price || '';
        document.getElementById('assetSupplier').value = row.supplier || '';
        document.getElementById('assetLocation').value = row.location || '';
        document.getElementById('assetStatus').value = row.status || 'active';
        document.getElementById('assetNote').value = row.note || '';
        assetModal.show();
    });
});

document.querySelectorAll('.btn-assign-asset').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('formAssignAsset').reset();
        document.getElementById('assignAssetId').value = btn.dataset.id;
        document.getElementById('assignDate').value = '<?= date('Y-m-d') ?>';
        assignModal.show();
    });
});

document.getElementById('btnSaveAssignAsset').addEventListener('click', async () => {
    const form = document.getElementById('formAssignAsset');
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }
    const fd = new FormData(form);
    fd.append('action', 'assign');
    const response = await fetch('/erp/api/admin/save_asset.php', { method: 'POST', body: fd });
    const data = await response.json();
    if (data.ok) {
        location.reload();
        return;
    }
    alert(data.msg || 'Không thể cấp phát tài sản');
});

document.querySelectorAll('.btn-history-asset').forEach(btn => {
    btn.addEventListener('click', () => {
        const row = assetsData[btn.dataset.id];
        const history = assignmentsByAsset[btn.dataset.id] || [];
        const rows = history.length
            ? history.map((item, index) => `<tr>
                <td>${index + 1}</td>
                <td>${escHtml(item.full_name || '')}</td>
                <td>${new Date(item.assigned_date).toLocaleDateString('vi-VN')}</td>
                <td>${item.returned_date ? new Date(item.returned_date).toLocaleDateString('vi-VN') : '<span class="badge bg-primary">Đang giữ</span>'}</td>
                <td>${escHtml(item.note || '—')}</td>
            </tr>`).join('')
            : '<tr><td colspan="5" class="text-center text-muted py-3">Chưa có lịch sử cấp phát</td></tr>';
        document.getElementById('assetHistoryBody').innerHTML = `
            <div class="mb-3">
                <div><strong>Mã tài sản:</strong> ${escHtml(row.asset_code || '')}</div>
                <div><strong>Tên tài sản:</strong> ${escHtml(row.asset_name || '')}</div>
                <div><strong>Loại:</strong> ${escHtml(categoryMap[row.category] || row.category || '')}</div>
                <div><strong>Trạng thái:</strong> ${escHtml(statusTextMap[row.status] || row.status || '')}</div>
            </div>
            <div class="table-responsive">
                <table class="table table-bordered table-sm align-middle mb-0">
                    <thead class="table-light"><tr><th width="50">#</th><th>Nhân viên</th><th width="120">Ngày cấp</th><th width="140">Ngày thu hồi</th><th>Ghi chú</th></tr></thead>
                    <tbody>${rows}</tbody>
                </table>
            </div>`;
        historyModal.show();
    });
});

document.querySelectorAll('.btn-return-asset').forEach(btn => {
    btn.addEventListener('click', () => {
        if (confirm('Thu hồi tài sản này?')) {
            postAssetAction('return', { assignment_id: btn.dataset.assignmentId });
        }
    });
});

document.querySelectorAll('.btn-delete-asset').forEach(btn => {
    btn.addEventListener('click', () => {
        if (confirm('Xoá tài sản này?')) {
            postAssetAction('delete', { id: btn.dataset.id });
        }
    });
});
</script>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/footer.php'; ?>
