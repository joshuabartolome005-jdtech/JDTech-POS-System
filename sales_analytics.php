<?php
require_once 'config/database.php';
require_once 'config/auth.php';
requireAdmin();

$pageTitle = 'Sales Analytics';
require 'includes/header.php';

$dateFrom = $_GET['from'] ?? date('Y-m-d', strtotime('-29 days'));
$dateTo = $_GET['to'] ?? date('Y-m-d');

// --- Daily sales trend ---
$stmt = $pdo->prepare("
    SELECT DATE(created_at) as sale_date, SUM(total_amount) as total, COUNT(*) as cnt
    FROM sales
    WHERE status = 'completed' AND DATE(created_at) BETWEEN ? AND ?
    GROUP BY DATE(created_at)
    ORDER BY sale_date ASC
");
$stmt->execute([$dateFrom, $dateTo]);
$dailyRows = $stmt->fetchAll();

// Fill in every date in range (so the line chart doesn't skip empty days)
$dailyLabels = [];
$dailyTotals = [];
$dailyByDate = [];
foreach ($dailyRows as $r) {
    $dailyByDate[$r['sale_date']] = (float)$r['total'];
}
$cursor = strtotime($dateFrom);
$end = strtotime($dateTo);
while ($cursor <= $end) {
    $d = date('Y-m-d', $cursor);
    $dailyLabels[] = date('M d', $cursor);
    $dailyTotals[] = $dailyByDate[$d] ?? 0;
    $cursor = strtotime('+1 day', $cursor);
}

// --- Sales by payment method ---
$stmt = $pdo->prepare("
    SELECT payment_method, SUM(total_amount) as total
    FROM sales
    WHERE status = 'completed' AND DATE(created_at) BETWEEN ? AND ?
    GROUP BY payment_method
");
$stmt->execute([$dateFrom, $dateTo]);
$paymentRows = $stmt->fetchAll();
$paymentLabels = array_column($paymentRows, 'payment_method');
$paymentTotals = array_map('floatval', array_column($paymentRows, 'total'));

// --- Sales by category ---
$stmt = $pdo->prepare("
    SELECT COALESCE(c.name, 'Uncategorized') as category_name, SUM(si.subtotal) as total
    FROM sale_items si
    JOIN sales s ON si.sale_id = s.id
    LEFT JOIN products p ON si.product_id = p.id
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE s.status = 'completed' AND DATE(s.created_at) BETWEEN ? AND ?
    GROUP BY category_name
    ORDER BY total DESC
");
$stmt->execute([$dateFrom, $dateTo]);
$categoryRows = $stmt->fetchAll();
$categoryLabels = array_column($categoryRows, 'category_name');
$categoryTotals = array_map('floatval', array_column($categoryRows, 'total'));

// --- Sales by hour of day (peak hours) ---
$stmt = $pdo->prepare("
    SELECT HOUR(created_at) as hr, SUM(total_amount) as total
    FROM sales
    WHERE status = 'completed' AND DATE(created_at) BETWEEN ? AND ?
    GROUP BY hr
");
$stmt->execute([$dateFrom, $dateTo]);
$hourRows = $stmt->fetchAll();
$hourByHr = [];
foreach ($hourRows as $r) {
    $hourByHr[(int)$r['hr']] = (float)$r['total'];
}
$hourLabels = [];
$hourTotals = [];
for ($h = 0; $h < 24; $h++) {
    $hourLabels[] = date('g A', mktime($h, 0, 0));
    $hourTotals[] = $hourByHr[$h] ?? 0;
}

// --- Summary stats for the period ---
$totalSales = array_sum($dailyTotals);
$totalTransactions = array_sum(array_column($dailyRows, 'cnt'));
$avgTicket = $totalTransactions > 0 ? $totalSales / $totalTransactions : 0;
$bestDayIndex = $dailyTotals ? array_search(max($dailyTotals), $dailyTotals) : null;
$bestDayLabel = $bestDayIndex !== null && $dailyTotals[$bestDayIndex] > 0 ? $dailyLabels[$bestDayIndex] : '-';
?>

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

<div class="row g-3 mb-3">
    <div class="col-md-3 col-sm-6">
        <div class="stat-card bg-grad-1">
            <small>Total Sales</small>
            <h3>&#8369;<?= number_format($totalSales, 2) ?></h3>
            <small>&nbsp;</small>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="stat-card bg-grad-2">
            <small>Transactions</small>
            <h3><?= $totalTransactions ?></h3>
            <small>&nbsp;</small>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="stat-card bg-grad-3">
            <small>Average Ticket</small>
            <h3>&#8369;<?= number_format($avgTicket, 2) ?></h3>
            <small>per transaction</small>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="stat-card bg-grad-4">
            <small>Best Day</small>
            <h3><?= htmlspecialchars($bestDayLabel) ?></h3>
            <small>&nbsp;</small>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Daily Sales Trend</div>
            <div class="card-body">
                <canvas id="dailyTrendChart" height="90"></canvas>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Sales by Payment Method</div>
            <div class="card-body">
                <canvas id="paymentChart" height="220"></canvas>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Sales by Category</div>
            <div class="card-body">
                <canvas id="categoryChart" height="200"></canvas>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Sales by Hour of Day</div>
            <div class="card-body">
                <canvas id="hourChart" height="200"></canvas>
            </div>
        </div>
    </div>
</div>

<script src="assets/vendor/chartjs/chart.umd.js"></script>
<script>
const dailyLabels = <?= json_encode($dailyLabels) ?>;
const dailyTotals = <?= json_encode($dailyTotals) ?>;
const paymentLabels = <?= json_encode($paymentLabels) ?>;
const paymentTotals = <?= json_encode($paymentTotals) ?>;
const categoryLabels = <?= json_encode($categoryLabels) ?>;
const categoryTotals = <?= json_encode($categoryTotals) ?>;
const hourLabels = <?= json_encode($hourLabels) ?>;
const hourTotals = <?= json_encode($hourTotals) ?>;

const pesoTooltip = {
    callbacks: {
        label: function (ctx) {
            const val = ctx.parsed.y !== undefined ? ctx.parsed.y : ctx.parsed;
            return (ctx.dataset.label ? ctx.dataset.label + ': ' : '') + '\u20B1' + val.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
    }
};

new Chart(document.getElementById('dailyTrendChart'), {
    type: 'line',
    data: {
        labels: dailyLabels,
        datasets: [{
            label: 'Sales',
            data: dailyTotals,
            borderColor: '#4b3fff',
            backgroundColor: 'rgba(75,63,255,0.1)',
            tension: 0.3,
            fill: true,
            pointRadius: 2
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false }, tooltip: pesoTooltip },
        scales: { y: { beginAtZero: true, ticks: { callback: v => '\u20B1' + v.toLocaleString() } } }
    }
});

new Chart(document.getElementById('paymentChart'), {
    type: 'doughnut',
    data: {
        labels: paymentLabels,
        datasets: [{
            data: paymentTotals,
            backgroundColor: ['#4b3fff', '#ff7a59', '#10b981', '#f59e0b']
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { position: 'bottom' }, tooltip: pesoTooltip }
    }
});

new Chart(document.getElementById('categoryChart'), {
    type: 'bar',
    data: {
        labels: categoryLabels,
        datasets: [{
            label: 'Sales',
            data: categoryTotals,
            backgroundColor: '#4b3fff'
        }]
    },
    options: {
        responsive: true,
        indexAxis: 'y',
        plugins: { legend: { display: false }, tooltip: pesoTooltip },
        scales: { x: { beginAtZero: true, ticks: { callback: v => '\u20B1' + v.toLocaleString() } } }
    }
});

new Chart(document.getElementById('hourChart'), {
    type: 'bar',
    data: {
        labels: hourLabels,
        datasets: [{
            label: 'Sales',
            data: hourTotals,
            backgroundColor: '#ff7a59'
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false }, tooltip: pesoTooltip },
        scales: { y: { beginAtZero: true, ticks: { callback: v => '\u20B1' + v.toLocaleString() } } }
    }
});
</script>

<?php require 'includes/footer.php'; ?>
