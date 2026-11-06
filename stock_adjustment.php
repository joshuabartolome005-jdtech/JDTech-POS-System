<?php
$pageTitle = 'Stock Adjustment';
require 'config/database.php';
require 'config/auth.php';
require 'includes/audit.php';
requireLogin();

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['adjust_stock'])) {
    $productId = (int)($_POST['product_id'] ?? 0);
    $type = $_POST['adjustment_type'] ?? '';
    $qty = (int)($_POST['quantity'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    if ($productId <= 0 || !in_array($type, ['in', 'out'], true) || $qty <= 0 || $reason === '') {
        $error = 'Please fill in all required fields.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
        $stmt->execute([$productId]);
        $product = $stmt->fetch();

        if (!$product) {
            $error = 'Product not found.';
        } elseif ($type === 'out' && $qty > $product['stock_qty']) {
            $error = 'Cannot remove more than the current stock (' . $product['stock_qty'] . ' available).';
        } else {
            $previousStock = (int)$product['stock_qty'];
            $newStock = $type === 'in' ? $previousStock + $qty : $previousStock - $qty;

            $stmt = $pdo->prepare("UPDATE products SET stock_qty = ? WHERE id = ?");
            $stmt->execute([$newStock, $productId]);

            $stmt = $pdo->prepare("INSERT INTO stock_adjustments (product_id, user_id, adjustment_type, quantity, previous_stock, new_stock, reason, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$productId, $_SESSION['user_id'], $type, $qty, $previousStock, $newStock, $reason, $notes ?: null]);

            log_audit($pdo, $_SESSION['user_id'], 'Stock Adjustment',
                ($type === 'in' ? 'Added ' : 'Removed ') . $qty . ' of "' . $product['name'] . '" (' . $reason . ') - stock ' . $previousStock . ' -> ' . $newStock);

            $message = 'Stock adjusted successfully.';
        }
    }
}

$products = $pdo->query("SELECT * FROM products ORDER BY name")->fetchAll();

$dateFrom = $_GET['from'] ?? date('Y-m-01');
$dateTo = $_GET['to'] ?? date('Y-m-d');

$stmt = $pdo->prepare("SELECT sa.*, p.name as product_name, u.full_name FROM stock_adjustments sa
    LEFT JOIN products p ON sa.product_id = p.id
    LEFT JOIN users u ON sa.user_id = u.id
    WHERE DATE(sa.created_at) BETWEEN ? AND ?
    ORDER BY sa.created_at DESC");
$stmt->execute([$dateFrom, $dateTo]);
$history = $stmt->fetchAll();

$pageTitle = 'Stock Adjustment';
require 'includes/header.php';
?>

<div class="row g-3">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Adjust Stock</div>
            <div class="card-body">
                <?php if ($message): ?><div class="alert alert-success py-2"><?= htmlspecialchars($message) ?></div><?php endif; ?>
                <?php if ($error): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div><?php endif; ?>
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label">Product</label>
                        <select name="product_id" class="form-select" required>
                            <option value="">Select a product</option>
                            <?php foreach ($products as $p): ?>
                                <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?> (current: <?= $p['stock_qty'] ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Type</label>
                        <select name="adjustment_type" class="form-select" required>
                            <option value="in">Stock In (add)</option>
                            <option value="out">Stock Out (remove)</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Quantity</label>
                        <input type="number" name="quantity" class="form-control" min="1" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Reason</label>
                        <select name="reason" class="form-select" required>
                            <option value="">Select a reason</option>
                            <option value="New Delivery">New Delivery</option>
                            <option value="Spoilage/Damage">Spoilage/Damage</option>
                            <option value="Inventory Count Correction">Inventory Count Correction</option>
                            <option value="Internal Use">Internal Use</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notes <span class="text-muted">(optional)</span></label>
                        <textarea name="notes" class="form-control" rows="2"></textarea>
                    </div>
                    <button type="submit" name="adjust_stock" class="btn btn-primary w-100">Save Adjustment</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Adjustment History</div>
            <div class="card-body">
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

                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Product</th>
                                <th>Type</th>
                                <th>Qty</th>
                                <th>Stock Before → After</th>
                                <th>Reason</th>
                                <th>By</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$history): ?>
                                <tr><td colspan="7" class="text-center text-muted py-4">No stock adjustments in this range.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($history as $h): ?>
                            <tr>
                                <td><?= htmlspecialchars($h['product_name'] ?? '(deleted product)') ?></td>
                                <td>
                                    <?php if ($h['adjustment_type'] === 'in'): ?>
                                        <span class="badge bg-success">Stock In</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">Stock Out</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= $h['quantity'] ?></td>
                                <td><?= $h['previous_stock'] ?> &rarr; <?= $h['new_stock'] ?></td>
                                <td>
                                    <?= htmlspecialchars($h['reason']) ?>
                                    <?php if (!empty($h['notes'])): ?>
                                        <div class="text-muted small"><?= htmlspecialchars($h['notes']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($h['full_name'] ?? '-') ?></td>
                                <td><?= date('M d, Y h:i A', strtotime($h['created_at'])) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require 'includes/footer.php'; ?>
