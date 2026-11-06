<?php
require_once 'config/database.php';
require_once 'config/auth.php';

// Pinapayagan ang Admin at Cashier na ma-access ang page na ito
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'cashier'])) {
    header('Location: login.php');
    exit;
}

$isAdmin = ($_SESSION['role'] === 'admin');
$pageTitle = 'Reports';
require 'includes/header.php';

$dateFrom = $_GET['from'] ?? date('Y-m-01');
$dateTo = $_GET['to'] ?? date('Y-m-d');

// Best-selling items (by quantity sold) - completed sales only
$stmt = $pdo->prepare("
    SELECT si.product_name,
           SUM(si.qty) as total_qty,
           SUM(si.subtotal) as total_revenue
    FROM sale_items si
    JOIN sales s ON si.sale_id = s.id
    WHERE s.status = 'completed' AND DATE(s.created_at) BETWEEN ? AND ?
    GROUP BY si.product_name
    ORDER BY total_qty DESC
    LIMIT 10
");
$stmt->execute([$dateFrom, $dateTo]);
$bestSellers = $stmt->fetchAll();

// Profit margin per product
$stmt = $pdo->prepare("
    SELECT si.product_id, si.product_name,
           SUM(si.qty) as total_qty,
           SUM(si.subtotal) as total_revenue,
           COALESCE(p.cost, 0) as unit_cost
    FROM sale_items si
    JOIN sales s ON si.sale_id = s.id
    LEFT JOIN products p ON si.product_id = p.id
    WHERE s.status = 'completed' AND DATE(s.created_at) BETWEEN ? AND ?
    GROUP BY si.product_id, si.product_name, p.cost
    ORDER BY total_revenue DESC
");
$stmt->execute([$dateFrom, $dateTo]);
$profitRows = $stmt->fetchAll();

$totalRevenue = 0;
$totalCost = 0;
foreach ($profitRows as &$row) {
    $row['total_cost'] = $row['unit_cost'] * $row['total_qty'];
    $row['profit'] = $row['total_revenue'] - $row['total_cost'];
    $totalRevenue += $row['total_revenue'];
    $totalCost += $row['total_cost'];
}
unset($row);
$totalProfit = $totalRevenue - $totalCost;

// Sales by category
$stmt = $pdo->prepare("
    SELECT COALESCE(c.name, 'Uncategorized') as category_name,
           SUM(si.qty) as total_qty,
           SUM(si.subtotal) as total_revenue
    FROM sale_items si
    JOIN sales s ON si.sale_id = s.id
    LEFT JOIN products p ON si.product_id = p.id
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE s.status = 'completed' AND DATE(s.created_at) BETWEEN ? AND ?
    GROUP BY category_name
    ORDER BY total_revenue DESC
");
$stmt->execute([$dateFrom, $dateTo]);
$byCategory = $stmt->fetchAll();
?>

<?php if (!$isAdmin): ?>
<div class="alert alert-info d-flex align-items-center mb-3" role="alert">
    <i class="bi bi-info-circle-fill me-2 fs-5"></i>
    <div>
        <strong>View-Only Mode</strong>
    </div>
</div>
<?php endif; ?>

<form class="d-flex gap-2 mb-3 flex-wrap align-items-end" method="GET">
    <div>
        <label class="form-label small mb-1">From</label>
        <input type="date" name="from" class="form-control" value="<?= htmlspecialchars($dateFrom) ?>">
    </div>
    <div>
        <label class="form-label small mb-1">To</label>
        <input type="date" name="to" class="form-control" value="<?= htmlspecialchars($dateTo) ?>">
    </div>
    <button class="btn btn-primary">Filter</button>
</form>

<?php if ($isAdmin): ?>
<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="stat-card bg-grad-1">
            <small>Total Revenue</small>
            <h3>&#8369;<?= number_format($totalRevenue, 2) ?></h3>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card bg-grad-2">
            <small>Total Cost</small>
            <h3>&#8369;<?= number_format($totalCost, 2) ?></h3>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card bg-grad-3">
            <small>Estimated Profit</small>
            <h3>&#8369;<?= number_format($totalProfit, 2) ?></h3>
        </div>
    </div>
</div>

<div class="alert alert-warning py-2 small mb-3">
    <i class="bi bi-info-circle"></i> Profit is calculated using each product's <strong>current</strong> cost value from the Products page. If you changed a product's cost after selling it, past profit figures will reflect the new cost, not what it actually cost you at the time.
</div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold">Best-Selling Items</div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Item</th>
                            <th>Qty Sold</th>
                            <?php if ($isAdmin): ?><th class="text-end">Revenue</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$bestSellers): ?>
                            <tr><td colspan="<?= $isAdmin ? 4 : 3 ?>" class="text-center text-muted py-4">No sales in this range.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($bestSellers as $i => $b): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td><?= htmlspecialchars($b['product_name']) ?></td>
                            <td><?= $b['total_qty'] ?></td>
                            <?php if ($isAdmin): ?>
                                <td class="text-end">&#8369;<?= number_format($b['total_revenue'], 2) ?></td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Sales by Category</div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Category</th>
                            <th>Qty Sold</th>
                            <?php if ($isAdmin): ?><th class="text-end">Revenue</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$byCategory): ?>
                            <tr><td colspan="<?= $isAdmin ? 3 : 2 ?>" class="text-center text-muted py-4">No sales in this range.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($byCategory as $c): ?>
                        <tr>
                            <td><?= htmlspecialchars($c['category_name']) ?></td>
                            <td><?= $c['total_qty'] ?></td>
                            <?php if ($isAdmin): ?>
                                <td class="text-end">&#8369;<?= number_format($c['total_revenue'], 2) ?></td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">
                <?= $isAdmin ? 'Profit Margin by Item' : 'Sales Volume by Item' ?>
            </div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Item</th>
                            <th>Qty</th>
                            <?php if ($isAdmin): ?>
                                <th class="text-end">Revenue</th>
                                <th class="text-end">Cost</th>
                                <th class="text-end">Profit</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$profitRows): ?>
                            <tr><td colspan="<?= $isAdmin ? 5 : 2 ?>" class="text-center text-muted py-4">No sales in this range.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($profitRows as $p): ?>
                        <tr>
                            <td><?= htmlspecialchars($p['product_name']) ?></td>
                            <td><?= $p['total_qty'] ?></td>
                            <?php if ($isAdmin): ?>
                                <td class="text-end">&#8369;<?= number_format($p['total_revenue'], 2) ?></td>
                                <td class="text-end text-muted">&#8369;<?= number_format($p['total_cost'], 2) ?></td>
                                <td class="text-end <?= $p['profit'] >= 0 ? 'text-success' : 'text-danger' ?>">&#8369;<?= number_format($p['profit'], 2) ?></td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require 'includes/footer.php'; ?>