<?php
$pageTitle = 'Dashboard';
require 'includes/header.php';

$todaySales = $pdo->query("SELECT COALESCE(SUM(total_amount),0) as total, COUNT(*) as cnt FROM sales WHERE DATE(created_at) = CURDATE() AND status = 'completed'")->fetch();
$totalProducts = $pdo->query("SELECT COUNT(*) as cnt FROM products")->fetch()['cnt'];

// Kuhanin ang bilang at listahan ng Low Stock mula sa INGREDIENTS table
$lowStock = $pdo->query("SELECT COUNT(*) as cnt FROM ingredients WHERE stock_qty <= low_stock_threshold")->fetch()['cnt'];

$monthSales = $pdo->query("SELECT COALESCE(SUM(total_amount),0) as total FROM sales WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE()) AND status = 'completed'")->fetch()['total'];

$recentSales = $pdo->query("SELECT s.*, u.full_name FROM sales s LEFT JOIN users u ON s.user_id = u.id ORDER BY s.created_at DESC LIMIT 8")->fetchAll();

// Listahan ng mga raw ingredients na mababa na sa kanilang threshold
$lowStockItems = $pdo->query("SELECT * FROM ingredients WHERE stock_qty <= low_stock_threshold ORDER BY stock_qty ASC LIMIT 5")->fetchAll();
?>

<div class="row g-3 mb-4">
    <div class="col-md-3 col-sm-6">
        <div class="stat-card bg-grad-1">
            <small>Today's Sales</small>
            <h3>&#8369;<?= number_format($todaySales['total'], 2) ?></h3>
            <small><?= $todaySales['cnt'] ?> transaction(s)</small>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="stat-card bg-grad-2">
            <small>This Month's Sales</small>
            <h3>&#8369;<?= number_format($monthSales, 2) ?></h3>
            <small>&nbsp;</small>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="stat-card bg-grad-3">
            <small>Total Products</small>
            <h3><?= $totalProducts ?></h3>
            <small>&nbsp;</small>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="stat-card bg-grad-4">
            <small>Low Stock Ingredients</small>
            <h3><?= $lowStock ?></h3>
            <small>At or below threshold</small>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Recent Transactions</div>
            <div class="table-responsive">
                <table class="table mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Invoice #</th>
                            <th>Cashier</th>
                            <th>Total</th>
                            <th>Payment</th>
                            <th>Status</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$recentSales): ?>
                            <tr><td colspan="6" class="text-center text-muted py-4">No transactions yet.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($recentSales as $s): ?>
                        <tr>
                            <td><a href="sale_view?id=<?= $s['id'] ?>"><?= htmlspecialchars($s['invoice_no']) ?></a></td>
                            <td><?= htmlspecialchars($s['full_name'] ?? '-') ?></td>
                            <td>&#8369;<?= number_format($s['total_amount'], 2) ?></td>
                            <td><span class="badge bg-secondary"><?= htmlspecialchars($s['payment_method']) ?></span></td>
                            <td>
                                <?php if ($s['status'] === 'voided'): ?>
                                    <span class="badge bg-danger">Voided</span>
                                <?php else: ?>
                                    <span class="badge bg-success">Completed</span>
                                <?php endif; ?>
                            </td>
                            <td><?= date('M d, Y h:i A', strtotime($s['created_at'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-exclamation-triangle-fill text-warning"></i> Low Stock Alert</div>
            <ul class="list-group list-group-flush">
                <?php if (!$lowStockItems): ?>
                    <li class="list-group-item text-muted">All ingredients have healthy stock levels.</li>
                <?php endif; ?>
                <?php foreach ($lowStockItems as $p): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <div>
                        <?= htmlspecialchars($p['name']) ?>
                        <div class="text-muted" style="font-size: 0.75rem;">Threshold: <?= $p['low_stock_threshold'] ?> <?= htmlspecialchars($p['unit']) ?></div>
                    </div>
                    <span class="badge bg-danger rounded-pill"><?= $p['stock_qty'] ?> <?= htmlspecialchars($p['unit']) ?> left</span>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>

<?php require 'includes/footer.php'; ?>