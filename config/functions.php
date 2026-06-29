<?php
// ---- Format ngày tháng ----
function formatDate($date, $format = 'd/m/Y') {
    if (empty($date)) return '-';
    return date($format, strtotime($date));
}

function formatDateTime($datetime) {
    if (empty($datetime)) return '-';
    return date('d/m/Y H:i', strtotime($datetime));
}

// ---- Flash message ----
function setFlash($type, $message) {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash() {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

function showFlash() {
    $flash = getFlash();
    if ($flash) {
        $icon = $flash['type'] === 'success' ? '✅' : ($flash['type'] === 'danger' ? '❌' : 'ℹ️');
        echo "<div class='alert alert-{$flash['type']} alert-dismissible fade show' role='alert'>
                {$icon} {$flash['message']}
                <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
              </div>";
    }
}

// ---- Lấy ngày làm việc trong tháng (trừ Chủ Nhật) ----
function getWorkingDaysInMonth($year, $month) {
    $days = [];
    $totalDays = cal_days_in_month(CAL_GREGORIAN, $month, $year);
    for ($d = 1; $d <= $totalDays; $d++) {
        $date = "$year-$month-$d";
        $dow = date('N', strtotime($date)); // 7 = Sunday
        if ($dow != 7) {
            $days[] = $date;
        }
    }
    return $days;
}

// ---- Tính giờ làm việc ----
function calcWorkHours($check_in, $check_out) {
    if (empty($check_in) || empty($check_out)) return 0;
    $diff = strtotime($check_out) - strtotime($check_in);
    return round($diff / 3600, 2);
}

// ---- CSRF Token ----
function generateCSRF() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCSRF($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function getExpenseCategories($pdo) {
    try {
        $sql = "SELECT id, category_name FROM expense_categories WHERE is_active = 1 ORDER BY category_name";
        $categories = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($categories)) {
            return $categories;
        }

        $pdo->exec("INSERT IGNORE INTO expense_categories (id, category_name) VALUES
            (1,'Tiền điện'),(2,'Tiền nước'),(3,'Internet'),(4,'Điện thoại'),
            (5,'Thuê văn phòng'),(6,'Chuyển phát nhanh'),(7,'Văn phòng phẩm'),
            (8,'Vệ sinh'),(9,'Mua sắm máy móc / Thiết bị'),(10,'Mua sắm vật tư tiêu hao'),(11,'Khác')");

        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}
?>
