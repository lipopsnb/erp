<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';
requireLogin();
requireRole('director','accountant','manager','warehouse');

$pdo = getDBConnection();
$filterStatus = trim($_GET['status'] ?? '');
$where = [];
$params = [];
if ($filterStatus !== '') {
    $where[] = 'status = ?';
    $params[] = $filterStatus;
}

$sql = 'SELECT * FROM vehicles';
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY created_at DESC, id DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);

$drivers = $pdo->query("SELECT id, full_name, username FROM users WHERE is_active = 1 ORDER BY full_name")
    ->fetchAll(PDO::FETCH_ASSOC);

$vehicleIds = array_column($vehicles, 'id');
$documents = [];
$fuels = [];
$trips = [];
if ($vehicleIds) {
    $placeholders = implode(',', array_fill(0, count($vehicleIds), '?'));

    $docStmt = $pdo->prepare("SELECT * FROM vehicle_documents WHERE vehicle_id IN ($placeholders) ORDER BY end_date ASC, id DESC");
    $docStmt->execute($vehicleIds);
    $documents = $docStmt->fetchAll(PDO::FETCH_ASSOC);

    $fuelStmt = $pdo->prepare("SELECT * FROM vehicle_fuel WHERE vehicle_id IN ($placeholders) ORDER BY fuel_date DESC, id DESC");
    $fuelStmt->execute($vehicleIds);
    $fuels = $fuelStmt->fetchAll(PDO::FETCH_ASSOC);

    $tripStmt = $pdo->prepare("SELECT vt.*, u.full_name AS driver_name
        FROM vehicle_trips vt
        LEFT JOIN users u ON u.id = vt.driver_id
        WHERE vt.vehicle_id IN ($placeholders)
        ORDER BY vt.trip_date DESC, vt.id DESC");
    $tripStmt->execute($vehicleIds);
    $trips = $tripStmt->fetchAll(PDO::FETCH_ASSOC);
}

$vehiclesById = [];
foreach ($vehicles as $vehicle) {
    $vehiclesById[$vehicle['id']] = $vehicle;
}
$documentsByVehicle = [];
foreach ($documents as $document) {
    $documentsByVehicle[$document['vehicle_id']][] = $document;
}
$fuelsByVehicle = [];
foreach ($fuels as $fuel) {
    $fuelsByVehicle[$fuel['vehicle_id']][] = $fuel;
}
$tripsByVehicle = [];
foreach ($trips as $trip) {
    $tripsByVehicle[$trip['vehicle_id']][] = $trip;
}

$statusMap = [
    'active' => ['success', 'Đang dùng'],
    'maintenance' => ['warning text-dark', 'Bảo dưỡng'],
    'disposed' => ['secondary', 'Thanh lý'],
];
$docTypeMap = [
    'registration' => 'Đăng kiểm',
    'insurance' => 'Bảo hiểm',
    'maintenance' => 'Bảo dưỡng',
];

$selectedVehicleId = (int)($_GET['vehicle'] ?? ($vehicleIds[0] ?? 0));
$csrf = generateCSRF();
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/sidebar.php';
?>
<div class="main-content">
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="fas fa-car me-2 text-primary"></i>Quản lý phương tiện</h4>
            <p class="text-muted mb-0">Theo dõi hồ sơ xe, đổ dầu và lịch sử sử dụng</p>
        </div>
        <button class="btn btn-primary" id="btnCreateVehicle"><i class="fas fa-plus me-1"></i> Thêm xe</button>
    </div>

    <?php showFlash(); ?>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2">
            <form class="row g-2 align-items-center" method="GET">
                <div class="col-md-3">
                    <select name="status" class="form-select form-select-sm">
                        <option value="">-- Trạng thái xe --</option>
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

    <div class="row g-3">
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0 pt-3">
                    <h6 class="fw-bold mb-0">Danh sách xe</h6>
                </div>
                <div class="card-body p-0">
                    <div class="list-group list-group-flush" id="vehicleList">
                        <?php if (!$vehicles): ?>
                        <div class="text-center text-muted py-4">Chưa có phương tiện nào</div>
                        <?php else: ?>
                            <?php foreach ($vehicles as $vehicle): ?>
                            <?php [$statusClass, $statusLabel] = $statusMap[$vehicle['status']] ?? ['secondary', $vehicle['status']]; ?>
                            <button type="button"
                                    class="list-group-item list-group-item-action vehicle-list-item <?= $selectedVehicleId === (int)$vehicle['id'] ? 'active' : '' ?>"
                                    data-id="<?= $vehicle['id'] ?>">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="fw-semibold"><?= htmlspecialchars($vehicle['plate_number']) ?></div>
                                        <div class="small <?= $selectedVehicleId === (int)$vehicle['id'] ? 'text-white-50' : 'text-muted' ?>"><?= htmlspecialchars($vehicle['vehicle_name']) ?></div>
                                    </div>
                                    <span class="badge bg-<?= $selectedVehicleId === (int)$vehicle['id'] ? 'light text-dark' : $statusClass ?>"><?= $statusLabel ?></span>
                                </div>
                            </button>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body" id="vehicleDetailBody">
                    <?php if (!$vehicles): ?>
                    <div class="text-center text-muted py-5">Chưa có dữ liệu phương tiện để hiển thị</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
</div>

<div class="modal fade" id="modalVehicle" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="vehicleModalTitle"><i class="fas fa-car me-2"></i>Thêm xe</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="formVehicle">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="type" value="vehicle">
                    <input type="hidden" name="id" id="vehicleId" value="">
                    <div class="row g-3">
                        <div class="col-md-4"><label class="form-label fw-semibold">Biển số <span class="text-danger">*</span></label><input type="text" name="plate_number" id="vehiclePlate" class="form-control" required></div>
                        <div class="col-md-8"><label class="form-label fw-semibold">Tên xe <span class="text-danger">*</span></label><input type="text" name="vehicle_name" id="vehicleName" class="form-control" required></div>
                        <div class="col-md-3"><label class="form-label fw-semibold">Hãng</label><input type="text" name="brand" id="vehicleBrand" class="form-control"></div>
                        <div class="col-md-3"><label class="form-label fw-semibold">Model</label><input type="text" name="model" id="vehicleModel" class="form-control"></div>
                        <div class="col-md-3"><label class="form-label fw-semibold">Năm</label><input type="number" name="year" id="vehicleYear" class="form-control" min="1900" max="2100"></div>
                        <div class="col-md-3"><label class="form-label fw-semibold">Màu</label><input type="text" name="color" id="vehicleColor" class="form-control"></div>
                        <div class="col-md-4"><label class="form-label fw-semibold">Trạng thái</label><select name="status" id="vehicleStatus" class="form-select"><?php foreach ($statusMap as $value => $meta): ?><option value="<?= $value ?>"><?= $meta[1] ?></option><?php endforeach; ?></select></div>
                        <div class="col-md-8"><label class="form-label fw-semibold">Ghi chú</label><input type="text" name="note" id="vehicleNote" class="form-control"></div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Huỷ</button>
                <button type="button" class="btn btn-primary" id="btnSaveVehicle"><i class="fas fa-save me-1"></i>Lưu xe</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalDocument" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title"><i class="fas fa-id-card me-2"></i>Hồ sơ xe</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="formDocument">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="type" value="document">
                    <input type="hidden" name="id" id="documentId" value="">
                    <input type="hidden" name="vehicle_id" id="documentVehicleId" value="">
                    <div class="mb-3"><label class="form-label fw-semibold">Loại hồ sơ <span class="text-danger">*</span></label><select name="doc_type" id="documentType" class="form-select" required><?php foreach ($docTypeMap as $value => $label): ?><option value="<?= $value ?>"><?= $label ?></option><?php endforeach; ?></select></div>
                    <div class="row g-3 mb-3"><div class="col-md-6"><label class="form-label fw-semibold">Từ ngày <span class="text-danger">*</span></label><input type="date" name="start_date" id="documentStart" class="form-control" required></div><div class="col-md-6"><label class="form-label fw-semibold">Đến ngày <span class="text-danger">*</span></label><input type="date" name="end_date" id="documentEnd" class="form-control" required></div></div>
                    <div class="mb-3"><label class="form-label fw-semibold">Chi phí</label><input type="number" name="cost" id="documentCost" class="form-control text-end" min="0" step="0.01"></div>
                    <div class="mb-3"><label class="form-label fw-semibold">Nhà cung cấp</label><input type="text" name="provider" id="documentProvider" class="form-control"></div>
                    <div><label class="form-label fw-semibold">Ghi chú</label><textarea name="note" id="documentNote" class="form-control" rows="2"></textarea></div>
                </form>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Huỷ</button><button type="button" class="btn btn-warning" id="btnSaveDocument"><i class="fas fa-save me-1"></i>Lưu</button></div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalFuel" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="fas fa-gas-pump me-2"></i>Lịch sử đổ dầu</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="formFuel">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="type" value="fuel">
                    <input type="hidden" name="id" id="fuelId" value="">
                    <input type="hidden" name="vehicle_id" id="fuelVehicleId" value="">
                    <div class="row g-3 mb-3"><div class="col-md-6"><label class="form-label fw-semibold">Ngày đổ <span class="text-danger">*</span></label><input type="date" name="fuel_date" id="fuelDate" class="form-control" required></div><div class="col-md-6"><label class="form-label fw-semibold">Số HĐ</label><input type="text" name="invoice_no" id="fuelInvoiceNo" class="form-control"></div></div>
                    <div class="row g-3 mb-3"><div class="col-md-4"><label class="form-label fw-semibold">Số tiền</label><input type="number" name="amount" id="fuelAmount" class="form-control text-end" min="0" step="0.01"></div><div class="col-md-4"><label class="form-label fw-semibold">Số lít</label><input type="number" name="liters" id="fuelLiters" class="form-control text-end" min="0" step="0.01"></div><div class="col-md-4"><label class="form-label fw-semibold">Km</label><input type="number" name="odometer" id="fuelOdometer" class="form-control text-end" min="0" step="1"></div></div>
                    <div><label class="form-label fw-semibold">Ghi chú</label><textarea name="note" id="fuelNote" class="form-control" rows="2"></textarea></div>
                </form>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Huỷ</button><button type="button" class="btn btn-info text-white" id="btnSaveFuel"><i class="fas fa-save me-1"></i>Lưu</button></div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalTrip" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-route me-2"></i>Lịch sử sử dụng xe</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="formTrip">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="type" value="trip">
                    <input type="hidden" name="id" id="tripId" value="">
                    <input type="hidden" name="vehicle_id" id="tripVehicleId" value="">
                    <div class="row g-3 mb-3"><div class="col-md-4"><label class="form-label fw-semibold">Ngày sử dụng <span class="text-danger">*</span></label><input type="date" name="trip_date" id="tripDate" class="form-control" required></div><div class="col-md-8"><label class="form-label fw-semibold">Tài xế</label><select name="driver_id" id="tripDriverId" class="form-select"><option value="">-- Chọn tài xế --</option><?php foreach ($drivers as $driver): ?><option value="<?= $driver['id'] ?>"><?= htmlspecialchars($driver['full_name']) ?> (<?= htmlspecialchars($driver['username']) ?>)</option><?php endforeach; ?></select></div></div>
                    <div class="row g-3 mb-3"><div class="col-md-6"><label class="form-label fw-semibold">Điểm đi</label><input type="text" name="origin" id="tripOrigin" class="form-control"></div><div class="col-md-6"><label class="form-label fw-semibold">Điểm đến</label><input type="text" name="destination" id="tripDestination" class="form-control"></div></div>
                    <div class="row g-3 mb-3"><div class="col-md-4"><label class="form-label fw-semibold">Km xuất phát</label><input type="number" name="km_start" id="tripKmStart" class="form-control text-end" min="0" step="1"></div><div class="col-md-4"><label class="form-label fw-semibold">Km kết thúc</label><input type="number" name="km_end" id="tripKmEnd" class="form-control text-end" min="0" step="1"></div><div class="col-md-4"><label class="form-label fw-semibold">Phí cầu đường</label><input type="number" name="toll_fee" id="tripTollFee" class="form-control text-end" min="0" step="0.01"></div></div>
                    <div><label class="form-label fw-semibold">Ghi chú</label><textarea name="note" id="tripNote" class="form-control" rows="2"></textarea></div>
                </form>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Huỷ</button><button type="button" class="btn btn-success" id="btnSaveTrip"><i class="fas fa-save me-1"></i>Lưu</button></div>
        </div>
    </div>
</div>

<script>
const csrfVehicle = '<?= $csrf ?>';
const vehiclesData = <?= json_encode($vehiclesById, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const documentsByVehicle = <?= json_encode($documentsByVehicle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const fuelsByVehicle = <?= json_encode($fuelsByVehicle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const tripsByVehicle = <?= json_encode($tripsByVehicle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const statusLabelMap = <?= json_encode(array_map(static fn($meta) => $meta[1], $statusMap), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const statusBadgeMap = <?= json_encode(array_map(static fn($meta) => $meta[0], $statusMap), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const docTypeMap = <?= json_encode($docTypeMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const selectedVehicleId = <?= (int)$selectedVehicleId ?>;
const detailBody = document.getElementById('vehicleDetailBody');
const vehicleModal = new bootstrap.Modal(document.getElementById('modalVehicle'));
const documentModal = new bootstrap.Modal(document.getElementById('modalDocument'));
const fuelModal = new bootstrap.Modal(document.getElementById('modalFuel'));
const tripModal = new bootstrap.Modal(document.getElementById('modalTrip'));

function escHtml(value) {
    return String(value || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function formatMoney(value) {
    return Number(value || 0).toLocaleString('vi-VN') + ' ₫';
}

function getDaysLeft(endDate) {
    const end = new Date(endDate + 'T00:00:00');
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    return Math.ceil((end - today) / 86400000);
}

function documentBadge(endDate) {
    const daysLeft = getDaysLeft(endDate);
    if (daysLeft < 30) return '<span class="badge bg-danger">&lt; 30 ngày</span>';
    if (daysLeft < 60) return '<span class="badge bg-warning text-dark">&lt; 60 ngày</span>';
    return '<span class="badge bg-success">Còn hạn</span>';
}

function renderVehicleDetail(vehicleId) {
    const vehicle = vehiclesData[vehicleId];
    if (!vehicle) {
        detailBody.innerHTML = '<div class="text-center text-muted py-5">Chọn xe để xem chi tiết</div>';
        return;
    }
    document.querySelectorAll('.vehicle-list-item').forEach(item => item.classList.toggle('active', Number(item.dataset.id) === Number(vehicleId)));
    const documents = documentsByVehicle[vehicleId] || [];
    const fuels = fuelsByVehicle[vehicleId] || [];
    const trips = tripsByVehicle[vehicleId] || [];
    const docRows = documents.length ? documents.map((row, index) => `
        <tr>
            <td>${index + 1}</td>
            <td>${escHtml(docTypeMap[row.doc_type] || row.doc_type)}</td>
            <td>${new Date(row.start_date).toLocaleDateString('vi-VN')}</td>
            <td>${new Date(row.end_date).toLocaleDateString('vi-VN')}</td>
            <td class="text-end">${formatMoney(row.cost)}</td>
            <td>${escHtml(row.provider || '—')}</td>
            <td>${documentBadge(row.end_date)}</td>
            <td>
                <button class="btn btn-xs btn-outline-warning me-1 btn-edit-document" data-id="${row.id}" data-vehicle-id="${vehicleId}"><i class="fas fa-edit"></i></button>
                <button class="btn btn-xs btn-outline-danger btn-delete-child" data-type="document" data-id="${row.id}"><i class="fas fa-trash"></i></button>
            </td>
        </tr>`).join('') : '<tr><td colspan="8" class="text-center text-muted py-3">Chưa có hồ sơ xe</td></tr>';
    const fuelRows = fuels.length ? fuels.map((row, index) => `
        <tr>
            <td>${index + 1}</td>
            <td>${escHtml(row.invoice_no || '—')}</td>
            <td>${new Date(row.fuel_date).toLocaleDateString('vi-VN')}</td>
            <td class="text-end">${Number(row.liters || 0).toLocaleString('vi-VN')}</td>
            <td class="text-end">${Number(row.odometer || 0).toLocaleString('vi-VN')}</td>
            <td class="text-end fw-semibold">${formatMoney(row.amount)}</td>
            <td>
                <button class="btn btn-xs btn-outline-warning me-1 btn-edit-fuel" data-id="${row.id}" data-vehicle-id="${vehicleId}"><i class="fas fa-edit"></i></button>
                <button class="btn btn-xs btn-outline-danger btn-delete-child" data-type="fuel" data-id="${row.id}"><i class="fas fa-trash"></i></button>
            </td>
        </tr>`).join('') : '<tr><td colspan="7" class="text-center text-muted py-3">Chưa có lịch sử đổ dầu</td></tr>';
    const tripRows = trips.length ? trips.map((row, index) => {
        const distance = (row.km_end !== null && row.km_start !== null) ? Number(row.km_end) - Number(row.km_start) : null;
        return `<tr>
            <td>${index + 1}</td>
            <td>${new Date(row.trip_date).toLocaleDateString('vi-VN')}</td>
            <td>${escHtml(row.driver_name || '—')}</td>
            <td>${escHtml(row.origin || '—')}</td>
            <td>${escHtml(row.destination || '—')}</td>
            <td class="text-end">${distance !== null ? Number(distance).toLocaleString('vi-VN') : '—'}</td>
            <td class="text-end">${formatMoney(row.toll_fee)}</td>
            <td>
                <button class="btn btn-xs btn-outline-warning me-1 btn-edit-trip" data-id="${row.id}" data-vehicle-id="${vehicleId}"><i class="fas fa-edit"></i></button>
                <button class="btn btn-xs btn-outline-danger btn-delete-child" data-type="trip" data-id="${row.id}"><i class="fas fa-trash"></i></button>
            </td>
        </tr>`;
    }).join('') : '<tr><td colspan="8" class="text-center text-muted py-3">Chưa có lịch sử sử dụng xe</td></tr>';

    detailBody.innerHTML = `
        <div class="d-flex justify-content-between align-items-start mb-3">
            <div>
                <h5 class="mb-1">${escHtml(vehicle.vehicle_name)}</h5>
                <div class="text-muted">Biển số: <strong>${escHtml(vehicle.plate_number)}</strong></div>
            </div>
            <div>
                <span class="badge bg-${statusBadgeMap[vehicle.status] || 'secondary'}">${escHtml(statusLabelMap[vehicle.status] || vehicle.status)}</span>
            </div>
        </div>
        <ul class="nav nav-tabs" id="vehicleTabs" role="tablist">
            <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabVehicleInfo" type="button">Thông tin xe</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabVehicleDocs" type="button">Đăng kiểm / Bảo hiểm / Bảo dưỡng</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabVehicleFuel" type="button">Lịch sử đổ dầu</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabVehicleTrips" type="button">Lịch sử sử dụng</button></li>
        </ul>
        <div class="tab-content border border-top-0 rounded-bottom p-3">
            <div class="tab-pane fade show active" id="tabVehicleInfo">
                <div class="row g-3">
                    <div class="col-md-6"><strong>Biển số:</strong> ${escHtml(vehicle.plate_number || '—')}</div>
                    <div class="col-md-6"><strong>Tên xe:</strong> ${escHtml(vehicle.vehicle_name || '—')}</div>
                    <div class="col-md-4"><strong>Hãng:</strong> ${escHtml(vehicle.brand || '—')}</div>
                    <div class="col-md-4"><strong>Model:</strong> ${escHtml(vehicle.model || '—')}</div>
                    <div class="col-md-4"><strong>Năm:</strong> ${escHtml(vehicle.year || '—')}</div>
                    <div class="col-md-4"><strong>Màu:</strong> ${escHtml(vehicle.color || '—')}</div>
                    <div class="col-md-4"><strong>Trạng thái:</strong> ${escHtml(statusLabelMap[vehicle.status] || vehicle.status || '—')}</div>
                    <div class="col-md-12"><strong>Ghi chú:</strong> ${escHtml(vehicle.note || '—')}</div>
                </div>
                <div class="mt-3">
                    <button class="btn btn-sm btn-outline-warning me-1" id="btnEditCurrentVehicle" data-id="${vehicle.id}"><i class="fas fa-edit me-1"></i>Sửa xe</button>
                    <button class="btn btn-sm btn-outline-danger" id="btnDeleteCurrentVehicle" data-id="${vehicle.id}"><i class="fas fa-trash me-1"></i>Xoá xe</button>
                </div>
            </div>
            <div class="tab-pane fade" id="tabVehicleDocs">
                <div class="d-flex justify-content-end mb-2"><button class="btn btn-sm btn-warning" id="btnAddDocument" data-vehicle-id="${vehicle.id}"><i class="fas fa-plus me-1"></i>Thêm hồ sơ</button></div>
                <div class="table-responsive"><table class="table table-bordered table-sm align-middle mb-0"><thead class="table-light"><tr><th width="50">#</th><th>Loại</th><th width="110">Từ ngày</th><th width="110">Đến ngày</th><th width="140" class="text-end">Chi phí</th><th>Nhà cung cấp</th><th width="120">Cảnh báo</th><th width="90">Thao tác</th></tr></thead><tbody>${docRows}</tbody></table></div>
            </div>
            <div class="tab-pane fade" id="tabVehicleFuel">
                <div class="d-flex justify-content-end mb-2"><button class="btn btn-sm btn-info text-white" id="btnAddFuel" data-vehicle-id="${vehicle.id}"><i class="fas fa-plus me-1"></i>Thêm đổ dầu</button></div>
                <div class="table-responsive"><table class="table table-bordered table-sm align-middle mb-0"><thead class="table-light"><tr><th width="50">#</th><th width="140">Số HĐ</th><th width="110">Ngày</th><th width="100" class="text-end">Số lít</th><th width="100" class="text-end">Km</th><th width="140" class="text-end">Số tiền</th><th width="90">Thao tác</th></tr></thead><tbody>${fuelRows}</tbody></table></div>
            </div>
            <div class="tab-pane fade" id="tabVehicleTrips">
                <div class="d-flex justify-content-end mb-2"><button class="btn btn-sm btn-success" id="btnAddTrip" data-vehicle-id="${vehicle.id}"><i class="fas fa-plus me-1"></i>Thêm lịch sử</button></div>
                <div class="table-responsive"><table class="table table-bordered table-sm align-middle mb-0"><thead class="table-light"><tr><th width="50">#</th><th width="110">Ngày</th><th width="160">Tài xế</th><th>Điểm đi</th><th>Điểm đến</th><th width="100" class="text-end">Km</th><th width="140" class="text-end">Phí cầu đường</th><th width="90">Thao tác</th></tr></thead><tbody>${tripRows}</tbody></table></div>
            </div>
        </div>`;

    const url = new URL(window.location.href);
    url.searchParams.set('vehicle', vehicleId);
    window.history.replaceState({}, '', url);
}

function resetVehicleForm() {
    document.getElementById('formVehicle').reset();
    document.getElementById('vehicleId').value = '';
    document.getElementById('vehicleStatus').value = 'active';
    document.getElementById('vehicleModalTitle').innerHTML = '<i class="fas fa-car me-2"></i>Thêm xe';
}
function resetDocumentForm(vehicleId) {
    document.getElementById('formDocument').reset();
    document.getElementById('documentId').value = '';
    document.getElementById('documentVehicleId').value = vehicleId || '';
}
function resetFuelForm(vehicleId) {
    document.getElementById('formFuel').reset();
    document.getElementById('fuelId').value = '';
    document.getElementById('fuelVehicleId').value = vehicleId || '';
}
function resetTripForm(vehicleId) {
    document.getElementById('formTrip').reset();
    document.getElementById('tripId').value = '';
    document.getElementById('tripVehicleId').value = vehicleId || '';
}

async function saveForm(formId, action) {
    const form = document.getElementById(formId);
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }
    const fd = new FormData(form);
    fd.append('action', action);
    const response = await fetch('/erp/api/admin/save_vehicle.php', { method: 'POST', body: fd });
    const data = await response.json();
    if (data.ok) {
        const vehicleId = fd.get('vehicle_id') || data.id || fd.get('id') || selectedVehicleId;
        const url = new URL(window.location.href);
        if (vehicleId) url.searchParams.set('vehicle', vehicleId);
        window.location.href = url.toString();
        return;
    }
    alert(data.msg || 'Có lỗi xảy ra');
}

async function deleteChild(type, id) {
    const fd = new FormData();
    fd.append('csrf_token', csrfVehicle);
    fd.append('type', type);
    fd.append('action', 'delete');
    fd.append('id', id);
    const response = await fetch('/erp/api/admin/save_vehicle.php', { method: 'POST', body: fd });
    const data = await response.json();
    if (data.ok) {
        location.reload();
        return;
    }
    alert(data.msg || 'Không thể xoá dữ liệu');
}

document.getElementById('btnCreateVehicle').addEventListener('click', () => {
    resetVehicleForm();
    vehicleModal.show();
});
document.getElementById('btnSaveVehicle').addEventListener('click', () => saveForm('formVehicle', document.getElementById('vehicleId').value ? 'edit' : 'add'));
document.getElementById('btnSaveDocument').addEventListener('click', () => saveForm('formDocument', document.getElementById('documentId').value ? 'edit' : 'add'));
document.getElementById('btnSaveFuel').addEventListener('click', () => saveForm('formFuel', document.getElementById('fuelId').value ? 'edit' : 'add'));
document.getElementById('btnSaveTrip').addEventListener('click', () => saveForm('formTrip', document.getElementById('tripId').value ? 'edit' : 'add'));

document.querySelectorAll('.vehicle-list-item').forEach(btn => btn.addEventListener('click', () => renderVehicleDetail(btn.dataset.id)));

detailBody.addEventListener('click', event => {
    const target = event.target.closest('button');
    if (!target) return;

    if (target.id === 'btnEditCurrentVehicle') {
        const row = vehiclesData[target.dataset.id];
        if (!row) return;
        resetVehicleForm();
        document.getElementById('vehicleModalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Sửa xe';
        document.getElementById('vehicleId').value = row.id;
        document.getElementById('vehiclePlate').value = row.plate_number || '';
        document.getElementById('vehicleName').value = row.vehicle_name || '';
        document.getElementById('vehicleBrand').value = row.brand || '';
        document.getElementById('vehicleModel').value = row.model || '';
        document.getElementById('vehicleYear').value = row.year || '';
        document.getElementById('vehicleColor').value = row.color || '';
        document.getElementById('vehicleStatus').value = row.status || 'active';
        document.getElementById('vehicleNote').value = row.note || '';
        vehicleModal.show();
    }

    if (target.id === 'btnDeleteCurrentVehicle') {
        if (confirm('Xoá xe này?')) {
            deleteChild('vehicle', target.dataset.id);
        }
    }

    if (target.id === 'btnAddDocument') {
        resetDocumentForm(target.dataset.vehicleId);
        documentModal.show();
    }
    if (target.id === 'btnAddFuel') {
        resetFuelForm(target.dataset.vehicleId);
        fuelModal.show();
    }
    if (target.id === 'btnAddTrip') {
        resetTripForm(target.dataset.vehicleId);
        tripModal.show();
    }

    if (target.classList.contains('btn-edit-document')) {
        const row = (documentsByVehicle[target.dataset.vehicleId] || []).find(item => Number(item.id) === Number(target.dataset.id));
        if (!row) return;
        resetDocumentForm(target.dataset.vehicleId);
        document.getElementById('documentId').value = row.id;
        document.getElementById('documentVehicleId').value = row.vehicle_id;
        document.getElementById('documentType').value = row.doc_type;
        document.getElementById('documentStart').value = row.start_date;
        document.getElementById('documentEnd').value = row.end_date;
        document.getElementById('documentCost').value = row.cost || '';
        document.getElementById('documentProvider').value = row.provider || '';
        document.getElementById('documentNote').value = row.note || '';
        documentModal.show();
    }

    if (target.classList.contains('btn-edit-fuel')) {
        const row = (fuelsByVehicle[target.dataset.vehicleId] || []).find(item => Number(item.id) === Number(target.dataset.id));
        if (!row) return;
        resetFuelForm(target.dataset.vehicleId);
        document.getElementById('fuelId').value = row.id;
        document.getElementById('fuelVehicleId').value = row.vehicle_id;
        document.getElementById('fuelDate').value = row.fuel_date;
        document.getElementById('fuelInvoiceNo').value = row.invoice_no || '';
        document.getElementById('fuelAmount').value = row.amount || '';
        document.getElementById('fuelLiters').value = row.liters || '';
        document.getElementById('fuelOdometer').value = row.odometer || '';
        document.getElementById('fuelNote').value = row.note || '';
        fuelModal.show();
    }

    if (target.classList.contains('btn-edit-trip')) {
        const row = (tripsByVehicle[target.dataset.vehicleId] || []).find(item => Number(item.id) === Number(target.dataset.id));
        if (!row) return;
        resetTripForm(target.dataset.vehicleId);
        document.getElementById('tripId').value = row.id;
        document.getElementById('tripVehicleId').value = row.vehicle_id;
        document.getElementById('tripDate').value = row.trip_date;
        document.getElementById('tripDriverId').value = row.driver_id || '';
        document.getElementById('tripOrigin').value = row.origin || '';
        document.getElementById('tripDestination').value = row.destination || '';
        document.getElementById('tripKmStart').value = row.km_start || '';
        document.getElementById('tripKmEnd').value = row.km_end || '';
        document.getElementById('tripTollFee').value = row.toll_fee || '';
        document.getElementById('tripNote').value = row.note || '';
        tripModal.show();
    }

    if (target.classList.contains('btn-delete-child')) {
        if (confirm('Xoá dữ liệu này?')) {
            deleteChild(target.dataset.type, target.dataset.id);
        }
    }
});

if (Object.keys(vehiclesData).length > 0) {
    renderVehicleDetail(vehiclesData[selectedVehicleId] ? selectedVehicleId : Object.keys(vehiclesData)[0]);
}
</script>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/footer.php'; ?>
