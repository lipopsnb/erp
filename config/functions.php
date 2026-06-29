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

// ---- Safe DB helpers ----
function fetchAllSafe(PDO $pdo, string $sql, array $params = []): array {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function fetchOneSafe(PDO $pdo, string $sql, array $params = []): ?array {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function fetchScalarSafe(PDO $pdo, string $sql, array $params = [], $default = null) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $val = $stmt->fetchColumn();
        return $val !== false ? $val : $default;
    } catch (Throwable $e) {
        return $default;
    }
}

// ---- CSRF helpers (form-based) ----
function csrfInput(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(generateCSRF()) . '">';
}

function ensurePostCsrf(): void {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        die('CSRF token không hợp lệ. Vui lòng tải lại trang.');
    }
}

// ---- Output escape ----
function e(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ---- Redirect helper ----
function redirect(string $path): never {
    if (str_starts_with($path, 'http') || str_starts_with($path, '/')) {
        header('Location: ' . $path);
    } else {
        header('Location: /erp/' . $path);
    }
    exit();
}

// ---- Old input (sau redirect với errors) ----
function old(string $key, string $default = ''): string {
    return (string) ($_SESSION['_old_input'][$key] ?? $default);
}

function flashOldInput(array $data): void {
    $_SESSION['_old_input'] = $data;
}

function clearOldInput(): void {
    unset($_SESSION['_old_input']);
}

// ---- Format currency ----
function formatCurrency($amount): string {
    return number_format((float)$amount, 0, ',', '.') . ' ₫';
}

// ---- Current user ID helper ----
function currentUserId(): int {
    return (int) ($_SESSION['user_id'] ?? 0);
}
?>
