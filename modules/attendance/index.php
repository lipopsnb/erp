<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';
requireLogin();

$user = currentUser();
$pdo = getDBConnection();

// Xử lý form chấm công thủ công (khi chưa có máy)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCSRF($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';
    $today = date('Y-m-d');
    $now = date('Y-m-d H:i:s');
    $lat = isset($_POST['lat']) && $_POST['lat'] !== '' && is_numeric($_POST['lat']) ? (float)$_POST['lat'] : null;
    $lng = isset($_POST['lng']) && $_POST['lng'] !== '' && is_numeric($_POST['lng']) ? (float)$_POST['lng'] : null;
    $locationMeta = resolveAttendanceLocation($pdo, $lat, $lng);
    $ip = $locationMeta['ip'];
    $locationFlag = $locationMeta['flag'];
    $savedLocation = true;
    $flagMsg = match ($locationFlag) {
        'verified' => ' ✅ Vị trí đã xác minh',
        'outside'  => ' ⚠️ Ngoài phạm vi công ty',
        'no_gps'   => ' 📍 Không có GPS',
        default    => '',
    };

    if ($action === 'check_in') {
        try {
            $stmt = $pdo->prepare("INSERT IGNORE INTO attendance_logs
                (user_id, check_in, work_date, source, check_in_ip, check_in_lat, check_in_lng, check_in_location_flag)
                VALUES (?, ?, ?, 'manual', ?, ?, ?, ?)");
            $stmt->execute([$user['id'], $now, $today, $ip, $lat, $lng, $locationFlag]);
        } catch (Throwable $e) {
            $savedLocation = false;
            $stmt = $pdo->prepare("INSERT IGNORE INTO attendance_logs (user_id, check_in, work_date, source) VALUES (?, ?, ?, 'manual')");
            $stmt->execute([$user['id'], $now, $today]);
        }
        setFlash('success', 'Chấm công vào ca thành công lúc ' . date('H:i') . ($savedLocation ? $flagMsg : ''));
    } elseif ($action === 'check_out') {
        try {
            $stmt = $pdo->prepare("UPDATE attendance_logs
                SET check_out = ?,
                    work_hours = ROUND(TIMESTAMPDIFF(MINUTE, check_in, ?) / 60, 2),
                    check_out_ip = ?,
                    check_out_lat = ?,
                    check_out_lng = ?,
                    check_out_location_flag = ?
                WHERE user_id = ? AND work_date = ? AND check_out IS NULL");
            $stmt->execute([$now, $now, $ip, $lat, $lng, $locationFlag, $user['id'], $today]);
        } catch (Throwable $e) {
            $savedLocation = false;
            $stmt = $pdo->prepare("UPDATE attendance_logs SET check_out = ?, work_hours = ROUND(TIMESTAMPDIFF(MINUTE, check_in, ?) / 60, 2) WHERE user_id = ? AND work_date = ? AND check_out IS NULL");
            $stmt->execute([$now, $now, $user['id'], $today]);
        }
        setFlash('success', 'Chấm công ra ca thành công lúc ' . date('H:i') . ($savedLocation ? $flagMsg : ''));
    }
    header('Location: /erp/modules/attendance/index.php');
    exit();
}

// Lấy tháng/năm từ query string (mặc định tháng hiện tại)
$viewMonth = (int)($_GET['month'] ?? date('m'));
$viewYear  = (int)($_GET['year']  ?? date('Y'));

// Điều hướng tháng trước/sau
if ($viewMonth < 1)  { $viewMonth = 12; $viewYear--; }
if ($viewMonth > 12) { $viewMonth = 1;  $viewYear++; }

// Chấm công hôm nay của user
$today = date('Y-m-d');
$stmt = $pdo->prepare("SELECT * FROM attendance_logs WHERE user_id = ? AND work_date = ?");
$stmt->execute([$user['id'], $today]);
$todayLog = $stmt->fetch();

// Lấy tất cả chấm công trong tháng xem
$stmt = $pdo->prepare("SELECT * FROM attendance_logs WHERE user_id = ? AND MONTH(work_date) = ? AND YEAR(work_date) = ? ORDER BY work_date");
$stmt->execute([$user['id'], $viewMonth, $viewYear]);
$monthLogs = [];
foreach ($stmt->fetchAll() as $log) {
    $monthLogs[$log['work_date']] = $log;
}

// Lấy đơn nghỉ phép đã duyệt trong tháng
$stmt = $pdo->prepare("SELECT * FROM leave_requests WHERE user_id = ? AND status = 'approved' AND (MONTH(start_date) = ? OR MONTH(end_date) = ?) AND (YEAR(start_date) = ? OR YEAR(end_date) = ?)");
$stmt->execute([$user['id'], $viewMonth, $viewMonth, $viewYear, $viewYear]);
$approvedLeaves = $stmt->fetchAll();

// Tạo map ngày nghỉ phép
$leaveDays = [];
foreach ($approvedLeaves as $leave) {
    $start = strtotime($leave['start_date']);
    $end   = strtotime($leave['end_date']);
    for ($d = $start; $d <= $end; $d += 86400) {
        $leaveDays[date('Y-m-d', $d)] = $leave['leave_type'];
    }
}

// Thống kê tháng
$totalWorkDays = 0;
$totalWorkHours = 0;
$lateDays = 0;
foreach ($monthLogs as $log) {
    if ($log['check_in']) {
        $totalWorkDays++;
        $totalWorkHours += $log['work_hours'];
        if (date('H:i', strtotime($log['check_in'])) > '08:15') $lateDays++;
    }
}

$csrf = generateCSRF();
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/sidebar.php';
?>

<div class="main-content">
<div class="container-fluid py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1">⏰ Chấm công</h4>
            <p class="text-muted mb-0"><?= htmlspecialchars($user['full_name']) ?> &bull; <?= date('l, d/m/Y') ?></p>
        </div>
        <?php if (hasRole('director','manager','accountant','production')): ?>
        <a href="/erp/modules/attendance/all_attendance.php" class="btn btn-outline-primary btn-sm">
            <i class="fas fa-table me-1"></i> Xem tất cả nhân viên
        </a>
        <?php endif; ?>
    </div>

    <?php showFlash(); ?>

    <div class="row g-3">
        <!-- Cột trái: Chấm công hôm nay + Thống kê -->
        <div class="col-lg-4">
            <!-- Chấm công hôm nay -->
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-primary text-white">
                    <h6 class="mb-0">📅 Hôm nay - <?= date('d/m/Y') ?></h6>
                </div>
                <div class="card-body text-center py-4">
                    <?php
                    $canCheckIn  = !$todayLog || !$todayLog['check_in'];
                    $canCheckOut = $todayLog && $todayLog['check_in'] && !$todayLog['check_out'];
                    ?>
                    <div class="mb-3">
                        <div class="row">
                            <div class="col-6 border-end">
                                <div class="text-muted small mb-1">Giờ vào</div>
                                <div class="fs-4 fw-bold <?= $todayLog && $todayLog['check_in'] ? 'text-success' : 'text-muted' ?>">
                                    <?= $todayLog && $todayLog['check_in'] ? date('H:i', strtotime($todayLog['check_in'])) : '--:--' ?>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="text-muted small mb-1">Giờ ra</div>
                                <div class="fs-4 fw-bold <?= $todayLog && $todayLog['check_out'] ? 'text-danger' : 'text-muted' ?>">
                                    <?= $todayLog && $todayLog['check_out'] ? date('H:i', strtotime($todayLog['check_out'])) : '--:--' ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if ($todayLog && $todayLog['check_out']): ?>
                        <div class="alert alert-success py-2">
                            ✅ Đã hoàn thành ca hôm nay<br>
                            <strong><?= $todayLog['work_hours'] ?> giờ</strong>
                        </div>
                    <?php else: ?>
                        <!-- Máy chấm công chưa có - dùng nút thủ công tạm -->
                        <div class="alert alert-warning py-2 small mb-3">
                            <i class="fas fa-info-circle me-1"></i>
                            <strong>Chú ý:</strong> Đang dùng chấm công thủ công.<br>
                            Khi lắp máy chấm công sẽ tự động.
                        </div>
                        <form method="POST" id="attendanceForm">
                            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                            <input type="hidden" name="lat" id="inputLat" value="">
                            <input type="hidden" name="lng" id="inputLng" value="">
                            <div id="gpsStatus" class="alert alert-secondary py-2 small mb-3">
                                <i class="fas fa-map-marker-alt me-1"></i>
                                <span id="gpsStatusText">Đang lấy vị trí GPS...</span>
                            </div>
                            <div class="text-muted small mb-3">
                                <i class="fas fa-network-wired me-1"></i>
                                IP của bạn: <code id="displayIp">Đang tải...</code>
                            </div>
                            <?php if ($canCheckIn): ?>
                                <input type="hidden" name="action" value="check_in">
                                <button type="submit" class="btn btn-success btn-lg w-100 mb-2" id="btnCheckIn" onclick="return confirmAttendance('VÀO')">
                                    <i class="fas fa-sign-in-alt me-2"></i>Chấm công VÀO
                                </button>
                            <?php elseif ($canCheckOut): ?>
                                <input type="hidden" name="action" value="check_out">
                                <div class="alert alert-info py-2 small mb-2">
                                    Đã vào: <?= date('H:i', strtotime($todayLog['check_in'])) ?>
                                </div>
                                <button type="submit" class="btn btn-danger btn-lg w-100" id="btnCheckOut" onclick="return confirmAttendance('RA')">
                                    <i class="fas fa-sign-out-alt me-2"></i>Chấm công RA
                                </button>
                            <?php endif; ?>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Thống kê tháng -->
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h6 class="mb-0 fw-bold">📊 Tháng <?= $viewMonth . '/' . $viewYear ?></h6>
                </div>
                <div class="card-body p-0">
                    <ul class="list-group list-group-flush">
                        <li class="list-group-item d-flex justify-content-between">
                            <span><i class="fas fa-check-circle text-success me-2"></i>Ngày công</span>
                            <strong><?= $totalWorkDays ?> ngày</strong>
                        </li>
                        <li class="list-group-item d-flex justify-content-between">
                            <span><i class="fas fa-clock text-primary me-2"></i>Tổng giờ làm</span>
                            <strong><?= number_format($totalWorkHours, 1) ?> giờ</strong>
                        </li>
                        <li class="list-group-item d-flex justify-content-between">
                            <span><i class="fas fa-exclamation-circle text-warning me-2"></i>Đi trễ</span>
                            <strong class="text-warning"><?= $lateDays ?> lần</strong>
                        </li>
                        <li class="list-group-item d-flex justify-content-between">
                            <span><i class="fas fa-umbrella-beach text-info me-2"></i>Ngày nghỉ phép</span>
                            <strong class="text-info"><?= count($leaveDays) ?> ngày</strong>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Cột phải: Lịch tháng -->
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <a href="?month=<?= $viewMonth-1 ?>&year=<?= $viewYear ?>" class="btn btn-sm btn-outline-secondary">
                        <i class="fas fa-chevron-left"></i>
                    </a>
                    <h6 class="mb-0 fw-bold">📅 Tháng <?= $viewMonth . '/' . $viewYear ?></h6>
                    <a href="?month=<?= $viewMonth+1 ?>&year=<?= $viewYear ?>" class="btn btn-sm btn-outline-secondary">
                        <i class="fas fa-chevron-right"></i>
                    </a>
                </div>
                <div class="card-body p-2">
                    <!-- Chú thích -->
                    <div class="d-flex flex-wrap gap-2 mb-3 px-2">
                        <span class="badge-legend bg-success text-white">✅ Đúng giờ</span>
                        <span class="badge-legend bg-warning text-dark">⚠️ Đi trễ</span>
                        <span class="badge-legend bg-info text-white">🏖️ Nghỉ phép</span>
                        <span class="badge-legend bg-danger text-white">❌ Vắng</span>
                        <span class="badge-legend bg-light text-muted">– Nghỉ CN</span>
                    </div>

                    <table class="table table-bordered calendar-table mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>Thứ 2</th><th>Thứ 3</th><th>Thứ 4</th>
                                <th>Thứ 5</th><th>Thứ 6</th><th>Thứ 7</th><th class="text-danger">CN</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php
                        $firstDay = mktime(0,0,0,$viewMonth,1,$viewYear);
                        $daysInMonth = cal_days_in_month(CAL_GREGORIAN,$viewMonth,$viewYear);
                        $startDow = date('N', $firstDay); // 1=Mon, 7=Sun

                        echo '<tr>';
                        // Ô trống trước ngày 1
                        for ($i = 1; $i < $startDow; $i++) echo '<td class="bg-light"></td>';

                        $col = $startDow;
                        for ($day = 1; $day <= $daysInMonth; $day++) {
                            $dateStr = sprintf('%04d-%02d-%02d', $viewYear, $viewMonth, $day);
                            $dow = date('N', mktime(0,0,0,$viewMonth,$day,$viewYear));
                            $isToday = ($dateStr === date('Y-m-d'));
                            $isSunday = ($dow == 7);
                            $log = $monthLogs[$dateStr] ?? null;
                            $isLeave = isset($leaveDays[$dateStr]);
                            $isFuture = $dateStr > date('Y-m-d');

                            // Xác định class và nội dung ô
                            $cellClass = '';
                            $content = '';

                            if ($isToday) $cellClass .= ' today-cell';

                            if ($isSunday) {
                                $cellClass .= ' bg-light text-muted';
                                $content = '<small class="text-muted">CN</small>';
                            } elseif ($isFuture) {
                                $content = '';
                            } elseif ($isLeave && !$log) {
                                $cellClass .= ' leave-cell';
                                $content = '<div class="small text-info fw-bold">🏖️ Phép</div>';
                            } elseif ($log && $log['check_in']) {
                                $isLate = date('H:i', strtotime($log['check_in'])) > '08:15';
                                $locationBadge = match ($log['check_in_location_flag'] ?? 'unknown') {
                                    'verified' => '<span class="badge bg-success badge-sm mt-1">📍✅</span>',
                                    'outside'  => '<span class="badge bg-warning text-dark badge-sm mt-1">📍⚠️</span>',
                                    'no_gps'   => '<span class="badge bg-secondary badge-sm mt-1">📍?</span>',
                                    default    => '',
                                };
                                $cellClass .= $isLate ? ' late-cell' : ' present-cell';
                                $content = '<div class="att-time">
                                    <span class="badge bg-success badge-sm">▶ ' . date('H:i', strtotime($log['check_in'])) . '</span><br>
                                    <span class="badge bg-danger badge-sm mt-1">◼ ' . ($log['check_out'] ? date('H:i', strtotime($log['check_out'])) : '?') . '</span>' .
                                    $locationBadge . '
                                </div>';
                            } else {
                                $cellClass .= ' absent-cell';
                                $content = '<div class="small text-danger">❌</div>';
                            }

                            echo "<td class='calendar-day $cellClass " . ($isToday ? 'border border-primary border-2' : '') . "'>
                                    <div class='day-number " . ($isToday ? 'fw-bold text-primary' : '') . "'>$day</div>
                                    $content
                                  </td>";

                            if ($col % 7 == 0 && $day < $daysInMonth) echo '</tr><tr>';
                            $col++;
                        }
                        // Ô trống cuối
                        while ($col % 7 != 1) { echo '<td class="bg-light"></td>'; $col++; }
                        echo '</tr>';
                        ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
</div>

<style>
.calendar-table td { height: 65px; vertical-align: top; padding: 4px; }
.day-number { font-size: 12px; font-weight: 600; margin-bottom: 2px; }
.present-cell { background: #f0fff4; }
.late-cell { background: #fffbf0; }
.leave-cell { background: #e8f4fd; }
.absent-cell { background: #fff5f5; }
.today-cell { outline: 2px solid #0d6efd !important; }
.att-time .badge-sm { font-size: 10px; padding: 2px 5px; }
.badge-legend { font-size: 11px; padding: 3px 8px; border-radius: 20px; }
</style>

<script>
const gpsStatusEl = document.getElementById('gpsStatus');
const gpsTextEl = document.getElementById('gpsStatusText');
const inputLat = document.getElementById('inputLat');
const inputLng = document.getElementById('inputLng');
const displayIpEl = document.getElementById('displayIp');

if (displayIpEl) {
    fetch('/erp/api/attendance/get_ip.php')
        .then(r => r.json())
        .then(d => {
            displayIpEl.textContent = d.ip || 'N/A';
        })
        .catch(() => {
            displayIpEl.textContent = 'N/A';
        });
}

if (gpsStatusEl && gpsTextEl && inputLat && inputLng) {
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(
            (pos) => {
                inputLat.value = pos.coords.latitude.toFixed(7);
                inputLng.value = pos.coords.longitude.toFixed(7);
                gpsStatusEl.className = 'alert alert-success py-2 small mb-3';
                gpsTextEl.innerHTML = `<i class="fas fa-check-circle me-1"></i>GPS: ${pos.coords.latitude.toFixed(5)}, ${pos.coords.longitude.toFixed(5)} (±${Math.round(pos.coords.accuracy)}m)`;
            },
            (err) => {
                gpsStatusEl.className = 'alert alert-warning py-2 small mb-3';
                const msgs = {1:'Đã từ chối quyền vị trí',2:'Không lấy được vị trí',3:'Hết thời gian chờ'};
                gpsTextEl.innerHTML = `<i class="fas fa-exclamation-triangle me-1"></i>Không có GPS: ${msgs[err.code] || 'Lỗi không xác định'}. Chấm công vẫn được nhưng sẽ không có xác minh vị trí.`;
            },
            { timeout: 10000, enableHighAccuracy: true }
        );
    } else {
        gpsStatusEl.className = 'alert alert-warning py-2 small mb-3';
        gpsTextEl.innerHTML = '<i class="fas fa-exclamation-triangle me-1"></i>Browser không hỗ trợ GPS.';
    }
}

function confirmAttendance(type) {
    const time = new Date().toLocaleTimeString('vi-VN');
    const hasGps = inputLat && inputLat.value !== '';
    const gpsNote = hasGps ? '' : '\n⚠️ Chưa có GPS - vị trí sẽ không được xác minh.';
    return confirm(`Xác nhận chấm công ${type} lúc ${time}?${gpsNote}`);
}
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/footer.php'; ?>