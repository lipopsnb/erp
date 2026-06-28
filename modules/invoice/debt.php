<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';
requireLogin();
requireRole('director','accountant','manager');

$pdo  = getDBConnection();

// Công nợ theo từng khách hàng
$debtList = $pdo->query("
    SELECT c.id, c.customer_code, c.customer_name, c.phone,
           COUNT(i.id)                          AS invoice_count,
           COALESCE(SUM(i.total_amount),0)      AS total_amount,
           COALESCE(SUM(p.paid),0)              AS total_paid,
           COALESCE(SUM(i.total_amount),0)
               - COALESCE(SUM(p.paid),0)        AS total_debt,
           MAX(i.due_date)                       AS latest_due
    FROM customers c
    LEFT JOIN invoices i ON i.customer_id = c.id AND i.status != 'cancelled'
    LEFT JOIN (
        SELECT invoice_id, SUM(amount) AS paid FROM payments GROUP BY invoice_id
    ) p ON p.invoice_id = i.id
    WHERE c.is_active = 1
    GROUP BY c.id
    HAVING total_amount > 0
    ORDER BY total_debt DESC
")->fetchAll(PDO::FETCH_ASSOC);

$grandDebt  = array_sum(array_column($debtList, 'total_debt'));
$grandTotal = array_sum(array_column($debtList, 'total_amount'));
$grandPaid  = array_sum(array_column($debtList, 'total_paid'));

// Lịch sử thanh toán gần đây
$recentPayments = $pdo->query("
    SELECT p.*, i.invoice_no, c.customer_name, u.full_name AS created_by_name
    FROM payments p
    JOIN invoices i  ON p.invoice_id  = i.id
    JOIN customers c ON i.customer_id = c.id
    LEFT JOIN users u ON p.created_by = u.id
    ORDER BY p.payment_date DESC, p.id DESC
    LIMIT 15
")->fetchAll(PDO::FETCH_ASSOC);

include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/sidebar.php';
?>
<div class="main-content">
<?php include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/warehouse_nav.php'; ?>
<div class="container-fluid py-4">

    <div class="mb-4">
        <h4 class="mb-1"><i class="fas fa-hand-holding-usd me-2 text-primary"></i>Báo cáo công nợ</h4>
        <p class="text-muted mb-0">Tổng hợp công nợ theo khách hàng</p>
    </div>

    <!-- Tổng quan -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm text-center py-3">
                <div class="fs-5 fw-bold text-primary"><?= number_format($grandTotal) ?> đ</div>
                <div class="text-muted small">Tổng doanh thu</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm text-center py-3">
                <div class="fs-5 fw-bold text-success"><?= number_format($grandPaid) ?> đ</div>
                <div class="text-muted small">Đã thu</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm text-center py-3">
                <div class="fs-5 fw-bold text-danger"><?= number_format($grandDebt) ?> đ</div>
                <div class="text-muted small">Còn phải thu</div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <!-- Công nợ theo KH -->
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-bold">
                    <i class="fas fa-users me-2 text-danger"></i>Công nợ theo khách hàng
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-dark">
                                <tr>
                                    <th>Khách hàng</th>
                                    <th class="text-end">Tổng HĐ</th>
                                    <th class="text-end">Đã thu</th>
                                    <th class="text-end">Còn nợ</th>
                                    <th>Hạn cuối</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($debtList)): ?>
                                <tr><td colspan="5" class="text-center text-muted py-4">Chưa có dữ liệu</td></tr>
                            <?php else: ?>
                                <?php foreach ($debtList as $d):
                                    $overdue = $d['latest_due'] && $d['total_debt'] > 0 && $d['latest_due'] < date('Y-m-d');
                                ?>
                                <tr class="<?= $overdue ? 'table-danger' : '' ?>">
                                    <td>
                                        <div class="fw-semibold"><?= htmlspecialchars($d['customer_name']) ?></div>
                                        <div class="text-muted small"><?= htmlspecialchars($d['customer_code']) ?></div>
                                    </td>
                                    <td class="text-end"><?= number_format($d['total_amount']) ?> đ</td>
                                    <td class="text-end text-success"><?= number_format($d['total_paid']) ?> đ</td>
                                    <td class="text-end fw-bold <?= $d['total_debt'] > 0 ? 'text-danger' : 'text-success' ?>">
                                        <?= number_format($d['total_debt']) ?> đ
                                    </td>
                                    <td class="small <?= $overdue ? 'text-danger fw-bold' : 'text-muted' ?>">
                                        <?= $d['latest_due'] ? date('d/m/Y', strtotime($d['latest_due'])) : '—' ?>
                                        <?php if ($overdue): ?><span class="badge bg-danger ms-1">Quá hạn</span><?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                            <tfoot class="table-light fw-bold">
                                <tr>
                                    <td>Tổng cộng</td>
                                    <td class="text-end"><?= number_format($grandTotal) ?> đ</td>
                                    <td class="text-end text-success"><?= number_format($grandPaid) ?> đ</td>
                                    <td class="text-end text-danger"><?= number_format($grandDebt) ?> đ</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Lịch sử thanh toán -->
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-bold">
                    <i class="fas fa-history me-2 text-success"></i>Lịch sử thanh toán gần đây
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 small">
                            <thead class="table-dark">
                                <tr>
                                    <th>Ngày</th>
                                    <th>Số HĐ</th>
                                    <th>Khách hàng</th>
                                    <th class="text-end">Số tiền</th>
                                    <th>HT</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($recentPayments)): ?>
                                <tr><td colspan="5" class="text-center text-muted py-3">Chưa có thanh toán</td></tr>
                            <?php else: ?>
                                <?php foreach ($recentPayments as $pay): ?>
                                <tr>
                                    <td><?= date('d/m', strtotime($pay['payment_date'])) ?></td>
                                    <td class="text-primary"><?= htmlspecialchars($pay['invoice_no']) ?></td>
                                    <td><?= htmlspecialchars($pay['customer_name']) ?></td>
                                    <td class="text-end fw-bold text-success">
                                        <?= number_format($pay['amount']) ?> đ
                                    </td>
                                    <td>
                                        <?php
                                        $pm = ['cash'=>['secondary','TM'],'transfer'=>['info','CK'],'check'=>['warning','Séc']];
                                        $m  = $pm[$pay['payment_method']] ?? ['secondary','?'];
                                        echo "<span class='badge bg-{$m[0]}'>{$m[1]}</span>";
                                        ?>
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
</div>
</div>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/footer.php'; ?>