<?php
$currentPage = $_SERVER['REQUEST_URI'];
function isActive($path) {
    global $currentPage;
    return strpos($currentPage, $path) !== false ? 'active' : '';
}
$sidebarUser = currentUser();
?>
<div class="sidebar" id="sidebar">
    <ul class="nav flex-column pt-2">

        <!-- TỔNG QUAN -->
        <li class="nav-item">
            <a class="nav-link <?= isActive('/dashboard') ?>" href="/erp/dashboard.php">
                <i class="fas fa-home"></i> <span>Tổng quan</span>
            </a>
        </li>

        <!-- ==================== CÁ NHÂN (tất cả đều thấy) ==================== -->
        <li class="nav-section">CÁ NHÂN</li>

        <li class="nav-item">
            <a class="nav-link <?= isActive('/modules/users/profile') ?>"
               href="/erp/modules/users/profile.php?id=<?= $sidebarUser['id'] ?>">
                <i class="fas fa-id-card"></i> <span>Hồ sơ của tôi</span>
            </a>
        </li>

        <li class="nav-item">
            <a class="nav-link <?= isActive('/modules/users/change_password') ?>"
               href="/erp/modules/users/change_password.php?id=<?= $sidebarUser['id'] ?>">
                <i class="fas fa-key"></i> <span>Đổi mật khẩu</span>
            </a>
        </li>

        <!-- ==================== CHẤM CÔNG ==================== -->
        <li class="nav-section">CHẤM CÔNG</li>

        <li class="nav-item">
            <a class="nav-link <?= isActive('/attendance/index') ?>"
               href="/erp/modules/attendance/index.php">
                <i class="fas fa-calendar-check"></i> <span>Lịch chấm công</span>
            </a>
        </li>

        <?php if (hasRole('employee', 'production', 'warehouse')): ?>
        <li class="nav-item">
            <a class="nav-link <?= isActive('/leave_request') ?>"
               href="/erp/modules/attendance/leave_request.php">
                <i class="fas fa-calendar-minus"></i> <span>Xin nghỉ phép</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= isActive('/ot_request') ?>"
               href="/erp/modules/attendance/ot_request.php">
                <i class="fas fa-clock"></i> <span>Đăng ký OT</span>
            </a>
        </li>
        <?php endif; ?>

        <?php if (hasRole('production', 'manager', 'director', 'accountant')): ?>
        <li class="nav-item">
            <a class="nav-link <?= isActive('/all_attendance') ?>"
               href="/erp/modules/attendance/all_attendance.php">
                <i class="fas fa-table"></i> <span>Bảng chấm công</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= isActive('/leave_manage') ?>"
               href="/erp/modules/attendance/leave_manage.php">
                <i class="fas fa-clipboard-check"></i>
                <span>Duyệt nghỉ phép</span>
                <span class="badge bg-warning text-dark ms-1" id="sidebarLeaveCount"></span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= isActive('/ot_manage') ?>"
               href="/erp/modules/attendance/ot_manage.php">
                <i class="fas fa-user-clock"></i>
                <span>Duyệt OT</span>
                <span class="badge bg-info ms-1" id="sidebarOTCount"></span>
            </a>
        </li>
        <?php endif; ?>

        <?php if (hasRole('director', 'accountant', 'manager', 'production')): ?>
        <li class="nav-item">
            <a class="nav-link <?= isActive('/shift_schedule') ?>"
               href="/erp/modules/attendance/shift_schedule.php">
                <i class="fas fa-calendar-alt"></i> <span>Lịch ca tháng</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= isActive('/shift_assign') ?>"
               href="/erp/modules/attendance/shift_assign.php">
                <i class="fas fa-users-cog"></i> <span>Phân công ca</span>
            </a>
        </li>
        <?php endif; ?>

        <?php if (hasRole('director', 'accountant', 'manager')): ?>
        <li class="nav-item">
            <a class="nav-link <?= isActive('/shift_setup') ?>"
               href="/erp/modules/attendance/shift_setup.php">
                <i class="fas fa-sliders-h"></i> <span>Setup ca làm việc</span>
            </a>
        </li>
        <?php endif; ?>

        <!-- ==================== BẢNG LƯƠNG ==================== -->
        <?php if (hasRole('director', 'accountant', 'manager')): ?>
        <li class="nav-section">BẢNG LƯƠNG</li>
        <li class="nav-item">
            <a class="nav-link <?= isActive('/payroll/index') ?>"
               href="/erp/modules/payroll/index.php">
                <i class="fas fa-money-check-alt"></i> <span>Quản lý kỳ lương</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= isActive('/payroll/holidays') ?>"
               href="/erp/modules/payroll/holidays.php">
                <i class="fas fa-calendar-times"></i> <span>Ngày lễ</span>
            </a>
        </li>
        <?php endif; ?>

        <?php if (!hasRole('director', 'accountant', 'manager')): ?>
        <li class="nav-section">BẢNG LƯƠNG</li>
        <li class="nav-item">
            <a class="nav-link <?= isActive('/payroll/my_payroll') ?>"
               href="/erp/modules/payroll/my_payroll.php">
                <i class="fas fa-file-invoice-dollar"></i> <span>Phiếu lương của tôi</span>
            </a>
        </li>
        <?php endif; ?>

        <!-- ==================== DANH MỤC ==================== -->
        <?php if (hasRole('director','accountant','warehouse','production','manager')): ?>
        <li class="nav-section">DANH MỤC</li>
        <li class="nav-item">
            <a class="nav-link <?= isActive('/master/customers') ?>"
               href="/erp/modules/master/customers.php">
                <i class="fas fa-users"></i> <span>Khách hàng</span>
            </a>
        </li>
        <?php endif; ?>

        <!-- ==================== KHO & SẢN XUẤT ==================== -->
        <?php if (hasRole('director','accountant','warehouse','production','manager')): ?>
        <li class="nav-section">KHO & SẢN XUẤT</li>
        <li class="nav-item">
            <a class="nav-link <?= isActive('/warehouse/') ?>"
               href="/erp/modules/warehouse/index.php">
                <i class="fas fa-boxes"></i> <span>Quản lý kho</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= isActive('/production/') ?>"
               href="/erp/modules/production/index.php">
                <i class="fas fa-industry"></i> <span>Sản xuất</span>
            </a>
        </li>
        <?php endif; ?>

        <!-- ==================== GIA CÔNG (MODULE 2) ==================== -->
        <?php if (hasRole('director','accountant','warehouse','production','manager')): ?>
        <li class="nav-section">GIA CÔNG</li>
        <li class="nav-item">
            <a class="nav-link <?= isActive('/production/warehouse_in') ?>"
               href="/erp/modules/production/warehouse_in.php">
                <i class="fas fa-file-import"></i> <span>Nhập kho NVL</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= isActive('/production/wo_processes') ?>"
               href="/erp/modules/production/wo_processes.php">
                <i class="fas fa-cogs"></i> <span>Tiến độ gia công</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= isActive('/warehouse/warehouse_items') ?>"
               href="/erp/modules/warehouse/warehouse_items.php">
                <i class="fas fa-boxes"></i> <span>Kho thành phẩm</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= isActive('/production/warehouse_out') ?>"
               href="/erp/modules/production/warehouse_out.php">
                <i class="fas fa-file-export"></i> <span>Xuất kho</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= isActive('/production/deliveries') ?>"
               href="/erp/modules/production/deliveries.php">
                <i class="fas fa-truck"></i> <span>Giao hàng</span>
            </a>
        </li>
        <?php endif; ?>

        <!-- ==================== HOÁ ĐƠN & CÔNG NỢ ==================== -->
        <?php if (hasRole('director','accountant','manager')): ?>
        <li class="nav-section">HOÁ ĐƠN</li>
        <li class="nav-item">
            <a class="nav-link <?= isActive('/invoice/index') ?>"
               href="/erp/modules/invoice/index.php">
                <i class="fas fa-file-invoice"></i> <span>Hoá đơn</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= isActive('/invoice/debt') ?>"
               href="/erp/modules/invoice/debt.php">
                <i class="fas fa-hand-holding-usd"></i> <span>Công nợ</span>
            </a>
        </li>
        <?php endif; ?>

        <?php if (hasRole('director','accountant','manager','warehouse','production','employee')): ?>
        <li class="nav-section">HÀNH CHÍNH</li>
        <li class="nav-item">
            <a class="nav-link <?= isActive('/admin/expenses') ?>"
               href="/erp/modules/admin/expenses.php">
                <i class="fas fa-file-invoice-dollar"></i> <span>Chi phí</span>
            </a>
        </li>
        <?php endif; ?>
        <?php if (hasRole('director','accountant','manager')): ?>
        <li class="nav-item">
            <a class="nav-link <?= isActive('/admin/assets') ?>"
               href="/erp/modules/admin/assets.php">
                <i class="fas fa-laptop"></i> <span>Tài sản</span>
            </a>
        </li>
        <?php endif; ?>
        <?php if (hasRole('director','accountant','manager','warehouse')): ?>
        <li class="nav-section">KHO VẬT TƯ</li>
        <li class="nav-item">
            <a class="nav-link <?= isActive('/admin/inv_items') ?>" href="/erp/modules/admin/inv_items.php">
                <i class="fas fa-list-alt"></i> <span>Danh mục hàng hoá</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= isActive('/admin/inv_import') ?>" href="/erp/modules/admin/inv_import.php">
                <i class="fas fa-arrow-down"></i> <span>Nhập kho</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= isActive('/admin/inv_export') ?>" href="/erp/modules/admin/inv_export.php">
                <i class="fas fa-arrow-up"></i> <span>Xuất kho</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= isActive('/admin/inv_stock') ?>" href="/erp/modules/admin/inv_stock.php">
                <i class="fas fa-warehouse"></i> <span>Tồn kho</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= isActive('/admin/inv_report') ?>" href="/erp/modules/admin/inv_report.php">
                <i class="fas fa-chart-bar"></i> <span>Báo cáo kho</span>
            </a>
        </li>
        <?php endif; ?>
        <?php if (hasRole('director','accountant','manager','warehouse')): ?>
        <li class="nav-item">
            <a class="nav-link <?= isActive('/admin/vehicles') ?>"
               href="/erp/modules/admin/vehicles.php">
                <i class="fas fa-car"></i> <span>Phương tiện</span>
            </a>
        </li>
        <?php endif; ?>
        <?php if (hasRole('director','accountant','manager')): ?>
        <li class="nav-item">
            <a class="nav-link <?= isActive('/admin/budget') ?>"
               href="/erp/modules/admin/budget.php">
                <i class="fas fa-chart-pie"></i> <span>Ngân sách HC</span>
            </a>
        </li>
        <?php endif; ?>

        <!-- ==================== KPI SẢN XUẤT ==================== -->
        <?php if (hasRole('director', 'accountant', 'manager', 'warehouse', 'production')): ?>
        <li class="nav-section">KPI SẢN XUẤT</li>
        <li class="nav-item">
            <a class="nav-link <?= isActive('/modules/kpi/assign') ?>"
               href="/erp/modules/kpi/assign.php">
                <i class="fas fa-tasks"></i> <span>Phân bổ KPI</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= isActive('/modules/kpi/result') ?>"
               href="/erp/modules/kpi/result.php">
                <i class="fas fa-clipboard-check"></i> <span>Kết quả KPI</span>
                <span class="badge bg-warning text-dark ms-1" id="sidebarKpiCount"></span>
            </a>
        </li>
        <?php endif; ?>

        <!-- ==================== QUẢN LÝ HỆ THỐNG ==================== -->
        <?php if (hasRole('director', 'accountant')): ?>
        <li class="nav-section">QUẢN LÝ HỆ THỐNG</li>
        <li class="nav-item">
            <a class="nav-link <?= isActive('/modules/users/index') ?>"
               href="/erp/modules/users/index.php">
                <i class="fas fa-users-cog"></i> <span>Quản lý tài khoản</span>
            </a>
        </li>
        <?php endif; ?>

    </ul>
</div>
