<?php
// Guard: đảm bảo auth đã được load
if (!function_exists('hasRole')) {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
}
if (!function_exists('isActiveNav')) {
    function isActiveNav($path) {
        return strpos($_SERVER['REQUEST_URI'], $path) !== false ? 'active' : '';
    }
}
?>
<div class="warehouse-nav">
    <div class="container-fluid">
        <ul class="warehouse-nav-tabs">

            <?php if (hasRole('director','accountant','warehouse','production','manager')): ?>
            <!-- DANH MỤC -->
            <li class="nav-group-label">Danh mục</li>
            <li>
                <a href="/erp/modules/master/product_codes.php"
                   class="<?= isActiveNav('/master/product_codes') ?>">
                    <i class="fas fa-barcode"></i> Mã sản phẩm
                </a>
            </li>
            <li>
                <a href="/erp/modules/master/customers.php"
                   class="<?= isActiveNav('/master/customers') ?>">
                    <i class="fas fa-users"></i> Khách hàng
                </a>
            </li>
            <?php endif; ?>

            <?php if (hasRole('director','accountant','manager')): ?>
            <li>
                <a href="/erp/modules/master/prices.php"
                   class="<?= isActiveNav('/master/prices') ?>">
                    <i class="fas fa-tags"></i> Bảng giá
                </a>
            </li>
            <?php endif; ?>

            <?php if (hasRole('director','accountant','warehouse','manager')): ?>
            <!-- KHO -->
            <li class="nav-group-label">Kho</li>
            <li>
                <a href="/erp/modules/warehouse/index.php"
                   class="<?= isActiveNav('/warehouse/index') ?>">
                    <i class="fas fa-boxes"></i> Tồn kho
                </a>
            </li>
            <li>
                <a href="/erp/modules/warehouse/import.php"
                   class="<?= isActiveNav('/warehouse/import') ?>">
                    <i class="fas fa-file-import"></i> Nhập SP gia công
                </a>
            </li>
            <li>
                <a href="/erp/modules/warehouse/cost.php"
                   class="<?= isActiveNav('/warehouse/cost') ?>">
                    <i class="fas fa-receipt"></i> Chi phí mua vào
                </a>
            </li>
            <?php endif; ?>

            <?php if (hasRole('director','accountant','warehouse','production','manager')): ?>
            <!-- SẢN XUẤT -->
            <li class="nav-group-label">Sản xuất</li>
            <li>
                <a href="/erp/modules/production/index.php"
                   class="<?= isActiveNav('/production/index') ?>">
                    <i class="fas fa-industry"></i> Tổng quan
                </a>
            </li>
            <li>
                <a href="/erp/modules/production/receipt.php"
                   class="<?= isActiveNav('/production/receipt') ?>">
                    <i class="fas fa-truck-loading"></i> Nhận từ kho
                </a>
            </li>
            <li>
                <a href="/erp/modules/production/output.php"
                   class="<?= isActiveNav('/production/output') ?>">
                    <i class="fas fa-clipboard-list"></i> Output cuối ngày
                </a>
            </li>
            <li>
                <a href="/erp/modules/production/delivery.php"
                   class="<?= isActiveNav('/production/delivery') ?>">
                    <i class="fas fa-shipping-fast"></i> Biên bản giao hàng
                </a>
            </li>
            <?php endif; ?>

            <?php if (hasRole('director','accountant','manager')): ?>
            <!-- HOÁ ĐƠN -->
            <li class="nav-group-label">Hoá đơn</li>
            <li>
                <a href="/erp/modules/invoice/index.php"
                   class="<?= isActiveNav('/invoice/index') ?>">
                    <i class="fas fa-file-invoice"></i> Hoá đơn
                </a>
            </li>
            <li>
                <a href="/erp/modules/invoice/debt.php"
                   class="<?= isActiveNav('/invoice/debt') ?>">
                    <i class="fas fa-hand-holding-usd"></i> Công nợ
                </a>
            </li>
            <?php endif; ?>

        </ul>
    </div>
</div>