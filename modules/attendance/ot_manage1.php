<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';

// Từ cấp Quản lý sản xuất trở lên
requireRole('production', 'manager', 'director', 'accountant');

$pdo  = getDBConnection();
$user = currentUser();

// ── XỬ LÝ DUYỆT / TỪ CHỐI ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCSRF($_POST['csrf_token'] ?? '')) {
    $action        = $_POST['action'] ?? '';
    $ot_id         = (int)$_POST['ot_id'];
    $reject_reason = trim($_POST['reject_reason'] ?? '');

    // Duyệt 1 đơn
    if ($action === 'approve') {
        $stmt = $pdo->prepare("
            UPDATE overtime_requests
            SET status = 'approved', approved_by = ?, approved_at = NOW()
            WHERE id = ? AND status = 'pending'
        ");
        $stmt->execute([$user['id'], $ot_id]);

        if ($stmt->rowCount()) {
            $ot = $pdo->prepare("SELECT user_id, ot_date, start_time, end_time, hours FROM overtime_requests WHERE id = ?");
            $ot->execute([$ot_id]);
            $otData = $ot->fetch();

            $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, reference_id) VALUES (?,?,?,'ot_approved',?)")
                ->execute([
                    $otData['user_id'],
                    '✅ Đơn OT được duyệt',
                    'Đơn OT ngày ' . formatDate($otData['ot_date']) .
                    ' (' . $otData['start_time'] . '–' . $otData['end_time'] . ', ' . $otData['hours'] . ' giờ) đã được duyệt bởi ' . $user['full_name'],
                    $ot_id
                ]);
            setFlash('success', '✅ Đã duyệt đơn OT.');
        }
        header('Location: /erp/modules/attendance/ot_manage.php?' . http_build_query($_GET));
        exit();
    }

    // Từ chối 1 đơn
    if ($action === 'reject') {
        if (empty($reject_reason)) {
            setFlash('danger', '❌ Vui lòng nhập lý do từ chối.');
        } else {
            $stmt = $pdo->prepare("
                UPDATE overtime_requests
                SET status = 'rejected', approved_by = ?, approved_at = NOW(), reject_reason = ?
                WHERE id = ? AND status = 'pending'
            ");
            $stmt->execute([$user['id'], $reject_reason, $ot_id]);

            if ($stmt->rowCount()) {
                $ot = $pdo->prepare("SELECT user_id, ot_date FROM overtime_requests WHERE id = ?");
                $ot->execute([$ot_id]);
                $otData = $ot->fetch();
                $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, reference_id) VALUES (?,?,?,'ot_rejected',?)")
                    ->execute([
                        $otData['user_id'],
                        '❌ Đơn OT bị từ chối',
                        'Đơn OT ngày ' . formatDate($otData['ot_date']) . ' bị từ chối. Lý do: ' . $reject_reason,
                        $ot_id
                    ]);
                setFlash('warning', '⚠️ Đã từ chối đơn OT.');
            }
        }
        header('Location: /erp/modules/attendance/ot_manage.php?' . http_build_query($_GET));
        exit();
    }

    // Duyệt hàng loạt
    if ($action === 'bulk_approve') {
        $ids = $_POST['selected_ids'] ?? [];
        if (empty($ids)) {
            setFlash('danger', 'Vui lòng chọn ít nhất 1 đơn.');
        } else {
            $count = 0;
            foreach ($ids as $id) {
                $id   = (int)$id;
                $stmt = $pdo->prepare("UPDATE overtime_requests SET status='approved', approved_by=?, approved_at=NOW() WHERE id=? AND status='pending'");
                $stmt->execute([$user['id'], $id]);
                if ($stmt->rowCount()) {
                    $count++;
                    $ot = $pdo->prepare("SELECT user_id, ot_date, hours FROM overtime_requests WHERE id=?");
                    $ot->execute([$id]);
                    $otData = $ot->fetch();
                    $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, reference_id) VALUES (?,?,?,'ot_approved',?)")
                        ->execute([
                            $otData['user_id'],
                            '✅ Đơn OT được duyệt',
                            'Đơn OT ngày ' . formatDate($otData['ot_date']) . ' (' . $otData['hours'] . ' giờ) đã được duyệt.',
                            $id
                        ]);
                }
            }
            setFlash('success', "✅ Đã duyệt <strong>$count</strong> đơn OT.");
        }
        header('Location: /erp/modules/attendance/ot_manage.php?' . http_build_query($_GET));
        exit();
    }
}

// ── BỘ LỌC ──────────────────────────────────────────────────────────────────
$filterStatus = $_GET['status']    ?? 'pending';
$filterDept   = (int)($_GET['dept'] ?? 0);
$filterMonth  = (int)($_GET['month'] ?? date('m'));
$filterYear   = (int)($_GET['year']  ?? date('Y'));
$filterUser   = (int)($_GET['user_id'] ?? 0);

// ── Query danh sách đơn OT ──
$sql    = "
    SELECT ot.*,
           u.full_name, u.employee_code,
           d.name AS dept_name,
           ws.shift_name, ws.color AS shift_color,
           ws.ot_multiplier, ws.weekend_multiplier, ws.holiday_multiplier,
           a.full_name AS approver_name
    FROM overtime_requests ot
    JOIN users u ON ot.user_id = u.id
    LEFT JOIN departments d ON u.department_id = d.id
    LEFT JOIN work_shifts ws ON ot.shift_id = ws.id
    LEFT JOIN users a ON ot.approved_by = a.id
    WHERE MONTH(ot.ot_date) = ? AND YEAR(ot.ot_date) = ?
";
$params = [$filterMonth, $filterYear];

if ($filterStatus !== 'all') { $sql .= " AND ot.status = ?"; $params[] = $filterStatus; }
if ($filterDept)             { $sql .= " AND u.department_id = ?"; $params[] = $filterDept; }
if ($filterUser)             { $sql .= " AND ot.user_id = ?"; $params[] = $filterUser; }

$sql .= " ORDER BY FIELD(ot.status,'pending','approved','rejected'), ot.ot_date DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll();

// ── Thống kê tháng ──
$statsStmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(status = 'pending')  AS pending,
        SUM(status = 'approved') AS approved,
        SUM(status = 'rejected') AS rejected,
        SUM(CASE WHEN status = 'approved' THEN hours ELSE 0 END) AS total_hours,
        SUM(CASE WHEN status = 'approved' AND ot_type = 'weekday' THEN hours ELSE 0 END) AS weekday_hours,
        SUM(CASE WHEN status = 'approved' AND ot_type = 'weekend' THEN hours ELSE 0 END) AS weekend_hours,
        SUM(CASE WHEN status = 'approved' AND ot_type = 'holiday' THEN hours ELSE 0 END) AS holiday_hours
    FROM overtime_requests
    WHERE MONTH(ot_date) = ? AND YEAR(ot_date) = ?
");
$statsStmt->execute([$filterMonth, $filterYear]);
$stats = $statsStmt->fetch();

// Ép kiểu an toàn để tránh Deprecated null → float (PHP 8.1+)
$statTotalHours   = (float)($stats['total_hours']   ?? 0);
$statWeekdayHours = (float)($stats['weekday_hours'] ?? 0);
$statWeekendHours = (float)($stats['weekend_hours'] ?? 0);
$statHolidayHours = (float)($stats['holiday_hours'] ?? 0);
$statPending      = (int)  ($stats['pending']       ?? 0);
$statApproved     = (int)  ($stats['approved']      ?? 0);

$depts   = $pdo->query("SELECT * FROM departments ORDER BY name")->fetchAll();
$empList = $pdo->query("SELECT id, full_name, employee_code FROM users WHERE is_active=1 ORDER BY full_name")->fetchAll();

$otTypeLabel = [
    'weekday' => ['Ngày thường', 'secondary'],
    'weekend' => ['Cuối tuần',   'warning'],
    'holiday' => ['Ngày lễ',     'danger']
];
$statusLabel = [
    'pending'  => ['⌛ Chờ duyệt', 'warning'],
    'approved' => ['✅ Đã duyệt',  'success'],
    'rejected' => ['❌ Từ chối',   'danger']
];

$csrf = generateCSRF();
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/sidebar.php';
?>

<div class="main-content">
<div class="container-fluid py-4">

        <!-- Tiêu đề -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1">✅ Duyệt Tăng ca (OT)</h4>
            <p class="text-muted small mb-0">Tháng <?= $filterMonth ?>/<?= $filterYear ?></p>
        </div>
        <div class="d-flex gap-2">
            <a href="/erp/modules/attendance/import_ot.php" class="btn btn-outline-primary btn-sm">
                <i class="fas fa-upload me-1"></i>Import OT
            </a>
        </div>
    </div>

    <?php showFlash(); ?>

    <!-- ── Thống kê tháng ── -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center py-3">
                <div class="fs-2 fw-bold text-warning"><?= $statPending ?></div>
                <div class="small text-muted">⌛ Chờ duyệt</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center py-3">
                <div class="fs-2 fw-bold text-success"><?= $statApproved ?></div>
                <div class="small text-muted">✅ Đã duyệt</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center py-3">
                <div class="fs-2 fw-bold text-primary"><?= number_format($statTotalHours, 1) ?></div>
                <div class="small text-muted">⏱️ Tổng giờ OT</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center py-3">
                <div class="d-flex justify-content-center gap-2">
                    <div>
                        <div class="fw-bold text-secondary"><?= number_format($statWeekdayHours, 1) ?>h</div>
                        <div style="font-size:10px;" class="text-muted">Thường</div>
                    </div>
                    <div>
                        <div class="fw-bold text-warning"><?= number_format($statWeekendHours, 1) ?>h</div>
                        <div style="font-size:10px;" class="text-muted">T7/CN</div>
                    </div>
                    <div>
                        <div class="fw-bold text-danger"><?= number_format($statHolidayHours, 1) ?>h</div>
                        <div style="font-size:10px;" class="text-muted">Lễ</div>
                    </div>
                </div>
                <div class="small text-muted mt-1">📊 Phân loại giờ</div>
            </div>
        </div>
    </div>

    <!-- ── Bộ lọc ── -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-6 col-md-2">
                    <label class="form-label small fw-semibold mb-1">Tháng</label>
                    <select name="month" class="form-select form-select-sm">
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?= $m ?>" <?= $m == $filterMonth ? 'selected' : '' ?>>Tháng <?= $m ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-6 col-md-1">
                    <label class="form-label small fw-semibold mb-1">Năm</label>
                    <select name="year" class="form-select form-select-sm">
                        <?php for ($y = date('Y')-1; $y <= date('Y')+1; $y++): ?>
                        <option value="<?= $y ?>" <?= $y == $filterYear ? 'selected' : '' ?>><?= $y ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small fw-semibold mb-1">Trạng thái</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="pending"  <?= $filterStatus==='pending'  ?'selected':'' ?>>⌛ Chờ duyệt</option>
                        <option value="approved" <?= $filterStatus==='approved' ?'selected':'' ?>>✅ Đã duyệt</option>
                        <option value="rejected" <?= $filterStatus==='rejected' ?'selected':'' ?>>❌ Từ chối</option>
                        <option value="all"      <?= $filterStatus==='all'      ?'selected':'' ?>>Tất cả</option>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small fw-semibold mb-1">Phòng ban</label>
                    <select name="dept" class="form-select form-select-sm">
                        <option value="">Tất cả</option>
                        <?php foreach ($depts as $d): ?>
                        <option value="<?= $d['id'] ?>" <?= $filterDept==$d['id']?'selected':'' ?>>
                            <?= htmlspecialchars($d['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold mb-1">Nhân viên</label>
                    <select name="user_id" class="form-select form-select-sm">
                        <option value="">Tất cả nhân viên</option>
                        <?php foreach ($empList as $e): ?>
                        <option value="<?= $e['id'] ?>" <?= $filterUser==$e['id']?'selected':'' ?>>
                            <?= htmlspecialchars($e['employee_code'] . ' - ' . $e['full_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 d-flex gap-1">
                    <button type="submit" class="btn btn-primary btn-sm flex-grow-1">Lọc</button>
                    <a href="/erp/modules/attendance/ot_manage.php" class="btn btn-outline-secondary btn-sm">↺</a>
                </div>
            </form>
        </div>
    </div>

    <!-- ── Danh sách đơn OT ── -->
    <form method="POST" id="bulkForm">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="bulk_approve">

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-bold">
                    📋 Danh sách đơn OT
                    <span class="badge bg-secondary ms-1"><?= count($requests) ?></span>
                </span>
                <?php if ($filterStatus === 'pending' && !empty($requests)): ?>
                <div class="d-flex gap-2 align-items-center">
                    <div class="form-check mb-0">
                        <input class="form-check-input" type="checkbox" id="selectAll"
                               onchange="document.querySelectorAll('.ot-check').forEach(cb=>cb.checked=this.checked); updateBulkBtn()">
                        <label class="form-check-label small" for="selectAll">Chọn tất cả</label>
                    </div>
                    <button type="submit" class="btn btn-success btn-sm" id="bulkApproveBtn" disabled
                            onclick="return confirm('Duyệt tất cả đơn đã chọn?')">
                        <i class="fas fa-check-double me-1"></i>Duyệt hàng loạt
                        <span id="bulkCount" class="badge bg-white text-success ms-1">0</span>
                    </button>
                </div>
                <?php endif; ?>
            </div>

            <div class="card-body p-0">
                <?php if (empty($requests)): ?>
                <div class="text-center text-muted py-5">
                    <i class="fas fa-clipboard-check fa-3x mb-3 d-block opacity-25"></i>
                    Không có đơn OT nào
                </div>
                <?php else: ?>

                <!-- View desktop: table -->
                <div class="table-responsive d-none d-md-block">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <?php if ($filterStatus === 'pending'): ?><th width="40"></th><?php endif; ?>
                                <th>Nhân viên</th>
                                <th>Ngày OT</th>
                                <th>Giờ OT</th>
                                <th>Loại</th>
                                <th>Hệ số</th>
                                <th>Lý do</th>
                                <th>Ngày gửi</th>
                                <th>Trạng thái</th>
                                <th class="text-center">Thao tác</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($requests as $ot):
                            $otp = $otTypeLabel[$ot['ot_type']] ?? ['?','secondary'];
                            $st  = $statusLabel[$ot['status']];
                            $mult = match($ot['ot_type']) {
                                'weekend' => $ot['weekend_multiplier'] ?? 2.0,
                                'holiday' => $ot['holiday_multiplier'] ?? 3.0,
                                default   => $ot['ot_multiplier'] ?? 1.5
                            };
                        ?>
                        <tr class="<?= $ot['status']==='rejected'?'opacity-50':'' ?>">
                            <?php if ($filterStatus === 'pending'): ?>
                            <td>
                                <?php if ($ot['status'] === 'pending'): ?>
                                <input type="checkbox" name="selected_ids[]" value="<?= $ot['id'] ?>"
                                       class="form-check-input ot-check" onchange="updateBulkBtn()">
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                            <td>
                                <div class="fw-semibold small"><?= htmlspecialchars($ot['full_name']) ?></div>
                                <div class="text-muted" style="font-size:11px;">
                                    <?= $ot['employee_code'] ?> · <?= htmlspecialchars($ot['dept_name'] ?? '') ?>
                                </div>
                            </td>
                            <td>
                                <div class="fw-semibold small"><?= formatDate($ot['ot_date']) ?></div>
                                <div style="font-size:11px;" class="text-muted">
                                    <?= date('l', strtotime($ot['ot_date'])) ?>
                                </div>
                            </td>
                            <td>
                                <div class="fw-bold text-primary"><?= $ot['hours'] ?>h</div>
                                <div style="font-size:11px;" class="text-muted">
                                    <?= substr($ot['start_time'],0,5) ?>–<?= substr($ot['end_time'],0,5) ?>
                                </div>
                            </td>
                            <td>
                                <span class="badge bg-<?= $otp[1] ?> text-<?= $otp[1]==='warning'?'dark':'white' ?>">
                                    <?= $otp[0] ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($ot['shift_name']): ?>
                                <span class="badge" style="background:<?= $ot['shift_color'] ?>; font-size:11px;">
                                    <?= $mult ?>x
                                </span>
                                <?php else: ?>
                                <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <small class="text-muted" title="<?= htmlspecialchars($ot['reason']) ?>">
                                    <?= mb_strimwidth(htmlspecialchars($ot['reason']), 0, 30, '...') ?>
                                </small>
                            </td>
                            <td><small class="text-muted"><?= formatDate($ot['created_at'], 'd/m H:i') ?></small></td>
                            <td>
                                <span class="badge bg-<?= $st[1] ?> text-<?= $st[1]==='warning'?'dark':'white' ?>">
                                    <?= $st[0] ?>
                                </span>
                                <?php if ($ot['status'] !== 'pending' && $ot['approver_name']): ?>
                                <div style="font-size:10px;" class="text-muted"><?= htmlspecialchars($ot['approver_name']) ?></div>
                                <?php endif; ?>
                                <?php if ($ot['status'] === 'rejected' && $ot['reject_reason']): ?>
                                <div style="font-size:10px;" class="text-danger" title="<?= htmlspecialchars($ot['reject_reason']) ?>">
                                    <?= mb_strimwidth(htmlspecialchars($ot['reject_reason']), 0, 20, '...') ?>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($ot['status'] === 'pending'): ?>
                                <div class="d-flex gap-1 justify-content-center">
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                        <input type="hidden" name="action" value="approve">
                                        <input type="hidden" name="ot_id" value="<?= $ot['id'] ?>">
                                        <button class="btn btn-xs btn-success"
                                                onclick="return confirm('Duyệt đơn OT của <?= htmlspecialchars($ot['full_name']) ?>?')"
                                                title="Duyệt">
                                            <i class="fas fa-check"></i>
                                        </button>
                                    </form>
                                    <button class="btn btn-xs btn-danger"
                                            onclick="showRejectModal(<?= $ot['id'] ?>, '<?= htmlspecialchars($ot['full_name']) ?>')"
                                            title="Từ chối">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                                <?php else: ?>
                                <button class="btn btn-xs btn-outline-secondary"
                                        onclick="showDetail(<?= htmlspecialchars(json_encode($ot)) ?>)"
                                        title="Chi tiết">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- View mobile: cards -->
                <div class="d-md-none">
                    <?php foreach ($requests as $ot):
                        $otp = $otTypeLabel[$ot['ot_type']] ?? ['?','secondary'];
                        $st  = $statusLabel[$ot['status']];
                    ?>
                    <div class="p-3 border-bottom">
                        <div class="d-flex justify-content-between mb-1">
                            <div>
                                <strong class="small"><?= htmlspecialchars($ot['full_name']) ?></strong>
                                <span class="text-muted small ms-1">(<?= $ot['employee_code'] ?>)</span>
                            </div>
                            <span class="badge bg-<?= $st[1] ?> text-<?= $st[1]==='warning'?'dark':'white' ?>"><?= $st[0] ?></span>
                        </div>
                        <div class="d-flex flex-wrap gap-2 small mb-2">
                            <span><i class="fas fa-calendar me-1 text-primary"></i><?= formatDate($ot['ot_date']) ?></span>
                            <span><i class="fas fa-clock me-1 text-success"></i><?= $ot['hours'] ?>h (<?= substr($ot['start_time'],0,5) ?>–<?= substr($ot['end_time'],0,5) ?>)</span>
                            <span class="badge bg-<?= $otp[1] ?>"><?= $otp[0] ?></span>
                        </div>
                        <div class="small text-muted mb-2"><?= htmlspecialchars($ot['reason']) ?></div>
                        <?php if ($ot['status'] === 'pending'): ?>
                        <div class="d-flex gap-2">
                            <form method="POST" class="d-inline flex-grow-1">
                                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                <input type="hidden" name="action" value="approve">
                                <input type="hidden" name="ot_id" value="<?= $ot['id'] ?>">
                                <button class="btn btn-success btn-sm w-100">✅ Duyệt</button>
                            </form>
                            <button class="btn btn-danger btn-sm flex-grow-1"
                                    onclick="showRejectModal(<?= $ot['id'] ?>, '<?= htmlspecialchars($ot['full_name']) ?>')">
                                ❌ Từ chối
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>

                <?php endif; ?>
            </div>
        </div>
    </form>
</div>
</div>

<!-- ── Modal Từ chối ── -->
<div class="modal fade" id="rejectModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="rejectForm">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="ot_id" id="rejectOtId">
                <div class="modal-header border-0">
                    <h6 class="modal-title">❌ Từ chối đơn OT của <strong id="rejectEmpName"></strong></h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label fw-semibold">Lý do từ chối <span class="text-danger">*</span></label>
                    <textarea name="reject_reason" class="form-control" rows="3" required
                              placeholder="Nhập lý do từ chối..."></textarea>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Huỷ</button>
                    <button type="submit" class="btn btn-danger">Xác nhận từ chối</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ── Modal Chi tiết ── -->
<div class="modal fade" id="detailModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header border-0">
                <h6 class="modal-title">📋 Chi tiết đơn OT</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="detailBody"></div>
        </div>
    </div>
</div>

<style>
.btn-xs { padding: 3px 10px; font-size: 12px; }
</style>

<script>
function showRejectModal(id, name) {
    document.getElementById('rejectOtId').value  = id;
    document.getElementById('rejectEmpName').textContent = name;
    document.querySelector('#rejectForm textarea').value = '';
    new bootstrap.Modal(document.getElementById('rejectModal')).show();
}

function showDetail(ot) {
    const otTypeLabel = { weekday:'Ngày thường', weekend:'Cuối tuần', holiday:'Ngày lễ' };
    const statusLabel = { pending:'Chờ duyệt', approved:'Đã duyệt', rejected:'Từ chối' };
    document.getElementById('detailBody').innerHTML = `
        <table class="table table-sm">
            <tr><th>Nhân viên</th><td>${ot.full_name} (${ot.employee_code})</td></tr>
            <tr><th>Phòng ban</th><td>${ot.dept_name || '—'}</td></tr>
            <tr><th>Ngày OT</th><td>${ot.ot_date}</td></tr>
            <tr><th>Giờ OT</th><td>${ot.start_time} – ${ot.end_time} (${ot.hours} giờ)</td></tr>
            <tr><th>Loại</th><td>${otTypeLabel[ot.ot_type] || ot.ot_type}</td></tr>
            <tr><th>Lý do</th><td>${ot.reason}</td></tr>
            <tr><th>Trạng thái</th><td>${statusLabel[ot.status]}</td></tr>
            ${ot.approver_name ? `<tr><th>Người duyệt</th><td>${ot.approver_name}</td></tr>` : ''}
            ${ot.reject_reason ? `<tr><th>Lý do từ chối</th><td class="text-danger">${ot.reject_reason}</td></tr>` : ''}
        </table>`;
    new bootstrap.Modal(document.getElementById('detailModal')).show();
}

function updateBulkBtn() {
    const count = document.querySelectorAll('.ot-check:checked').length;
    const btn   = document.getElementById('bulkApproveBtn');
    const cnt   = document.getElementById('bulkCount');
    if (btn) {
        btn.disabled = count === 0;
        cnt.textContent = count;
    }
    const all  = document.querySelectorAll('.ot-check').length;
    const saEl = document.getElementById('selectAll');
    if (saEl) saEl.indeterminate = count > 0 && count < all;
    if (saEl) saEl.checked = count === all && all > 0;
}
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/footer.php'; ?>
