<?php
$pageTitle = 'Transaction Details';
require 'includes/header.php';

$id = (int)($_GET['id'] ?? 0);
$isAdmin = ($_SESSION['role'] ?? '') === 'admin';

$stmt = $pdo->prepare("SELECT s.*, u.full_name FROM sales s LEFT JOIN users u ON s.user_id = u.id WHERE s.id = ?");
$stmt->execute([$id]);
$sale = $stmt->fetch();

if (!$sale) {
    echo '<div class="alert alert-danger">Transaction not found.</div>';
    require 'includes/footer.php';
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM sale_items WHERE sale_id = ?");
$stmt->execute([$id]);
$items = $stmt->fetchAll();

$voidedByName = null;
if ($sale['status'] === 'voided' && $sale['voided_by']) {
    $stmt = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
    $stmt->execute([$sale['voided_by']]);
    $voidedByName = $stmt->fetchColumn();
}

// Data Array para sa JS
$salePayload = [
    'invoice_no'      => $sale['invoice_no'],
    'created_at'      => date('M d, Y h:i A', strtotime($sale['created_at'])),
    'cashier_name'    => $sale['full_name'] ?? 'Cashier_1',
    'payment_method'  => $sale['payment_method'],
    'subtotal_amount' => (float)$sale['subtotal_amount'],
    'discount_type'   => $sale['discount_type'] ?? 'none',
    'discount_amount' => (float)($sale['discount_amount'] ?? 0),
    'total_amount'    => (float)$sale['total_amount'],
    'amount_paid'     => (float)$sale['amount_paid'],
    'change_amount'   => (float)$sale['change_amount'],
    'items'           => array_map(function($i) {
        return [
            'name'     => $i['product_name'],
            'qty'      => (int)$i['qty'],
            'price'    => (float)$i['price'],
            'subtotal' => (float)$i['subtotal']
        ];
    }, $items)
];
?>

<!-- Naka-embed ang JSON para iwas JavaScript Escaping Syntax Errors -->
<script id="saleDataJson" type="application/json">
<?= json_encode($salePayload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>
</script>

<?php if ($sale['status'] === 'voided'): ?>
    <div class="alert alert-danger">
        <strong>This transaction was voided.</strong><br>
        Reason: <?= htmlspecialchars($sale['void_reason']) ?><br>
        Voided by <?= htmlspecialchars($voidedByName ?? '-') ?> on <?= date('M d, Y h:i A', strtotime($sale['voided_at'])) ?>
    </div>
<?php endif; ?>

<div class="card border-0 shadow-sm" style="max-width:600px;">
    <div class="card-body">
        <div class="text-center mb-3">
            <h4 class="mb-0"><i class="bi bi-receipt"></i> Receipt</h4>
            <small class="text-muted">Invoice #<?= htmlspecialchars($sale['invoice_no']) ?></small>
            <div class="mt-1">
                <?php if ($sale['status'] === 'voided'): ?>
                    <span class="badge bg-danger">Voided</span>
                <?php else: ?>
                    <span class="badge bg-success">Completed</span>
                <?php endif; ?>
            </div>
        </div>
        <p class="mb-1"><strong>Cashier:</strong> <?= htmlspecialchars($sale['full_name'] ?? '-') ?></p>
        <p class="mb-3"><strong>Date:</strong> <?= date('M d, Y h:i A', strtotime($sale['created_at'])) ?></p>

        <table class="table table-sm">
            <thead><tr><th>Item</th><th>Price</th><th>Qty</th><th class="text-end">Subtotal</th></tr></thead>
            <tbody>
                <?php foreach ($items as $it): ?>
                <tr>
                    <td><?= htmlspecialchars($it['product_name']) ?></td>
                    <td>&#8369;<?= number_format($it['price'], 2) ?></td>
                    <td><?= $it['qty'] ?></td>
                    <td class="text-end">&#8369;<?= number_format($it['subtotal'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="d-flex justify-content-between"><span>Subtotal</span><span>&#8369;<?= number_format($sale['subtotal_amount'], 2) ?></span></div>
        <?php if ($sale['discount_amount'] > 0): ?>
        <div class="d-flex justify-content-between text-danger">
            <span>Discount (<?= htmlspecialchars(ucfirst($sale['discount_type'])) ?> - <?= htmlspecialchars($sale['discount_name']) ?>, ID: <?= htmlspecialchars($sale['discount_id_number']) ?>)</span>
            <span>-&#8369;<?= number_format($sale['discount_amount'], 2) ?></span>
        </div>
        <?php endif; ?>
        <div class="d-flex justify-content-between"><span>Total</span><strong>&#8369;<?= number_format($sale['total_amount'], 2) ?></strong></div>
        <div class="d-flex justify-content-between"><span>Paid (<?= htmlspecialchars($sale['payment_method']) ?>)</span><span>&#8369;<?= number_format($sale['amount_paid'], 2) ?></span></div>
        <div class="d-flex justify-content-between"><span>Change</span><span>&#8369;<?= number_format($sale['change_amount'], 2) ?></span></div>

        <div class="d-flex gap-2 mt-3">
            <button type="button" class="btn btn-outline-primary w-50" onclick="directRawbtPrint('customer')">
                <i class="bi bi-printer"></i> Print Receipt
            </button>
            <button type="button" class="btn btn-outline-warning w-50" onclick="directRawbtPrint('kitchen')">
                <i class="bi bi-egg-fried"></i> Kitchen Copy
            </button>
        </div>
        <?php if ($isAdmin && $sale['status'] !== 'voided'): ?>
        <button type="button" class="btn btn-outline-danger w-100 mt-2" data-bs-toggle="modal" data-bs-target="#voidModal">
            Void This Transaction
        </button>
        <?php endif; ?>
        <a href="sales_history" class="btn btn-outline-secondary w-100 mt-2">Back</a>
    </div>
</div>

<?php if ($isAdmin && $sale['status'] !== 'voided'): ?>
<div class="modal fade" id="voidModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="void_sale">
                <input type="hidden" name="sale_id" value="<?= $sale['id'] ?>">
                <input type="hidden" name="redirect" value="sale_view?id=<?= $sale['id'] ?>">
                <div class="modal-header">
                    <h5 class="modal-title">Void This Transaction</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning py-2 mb-3">
                        This will restore stock for all items in this transaction. This action is recorded and cannot be undone.
                    </div>
                    <label class="form-label">Reason for voiding</label>
                    <textarea name="void_reason" class="form-control" rows="2" required placeholder="e.g. Customer changed order, wrong item added"></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-modal="dismiss">Cancel</button>
                    <button type="submit" class="btn btn-danger">Void Transaction</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function directRawbtPrint(type) {
    try {
        const jsonText = document.getElementById('saleDataJson').textContent;
        const saleData = JSON.parse(jsonText);

        const W = 28;
        const line = (str = '') => str + '\n';
        const center = (str) => {
            let pad = Math.floor((W - str.length) / 2);
            return ' '.repeat(pad > 0 ? pad : 0) + str;
        };
        const row = (left, right) => {
            let space = W - left.length - right.length;
            if (space < 1) space = 1;
            return left + ' '.repeat(space) + right;
        };

        let p = '';

        if (type === 'kitchen') {
            p += line(center('============================'));
            p += line(center('*** KITCHEN ORDER ***'));
            p += line(center('============================'));
            p += line();
            p += line(center('Receipt #' + saleData.invoice_no));
            p += line(center(saleData.created_at));
            p += line('----------------------------');
            p += line('ITEM            QTY');
            p += line('----------------------------');

            saleData.items.forEach(i => {
                let nameLine = i.name.length > 20 ? i.name.substring(0, 20) : i.name;
                let qtyStr = String(i.qty).padStart(4, ' ');
                p += line(row(nameLine, qtyStr));
            });

            p += line('----------------------------');
            p += line(center('*** PREPARE IMMEDIATELY ***'));
            p += line('\n\n');
        } else {
            p += line(center('============================'));
            p += line(center('*** WRAP NATICS ***'));
            p += line(center('SHAWARMA STATION'));
            p += line(center('============================'));
            p += line();
            p += line(center('ORDER'));
            p += line(center('Receipt #' + saleData.invoice_no));
            p += line();
            p += line(center('Guaranteed the Best.'));
            p += line(center('Robinsons Starmills Pampanga'));
            p += line(center(saleData.created_at));
            p += line('----------------------------');

            p += line('Cashier : ' + saleData.cashier_name);
            p += line('Payment : ' + saleData.payment_method);
            p += line('----------------------------');

            p += line('ITEM            QTY    COST');
            p += line('----------------------------');

            saleData.items.forEach(i => {
                let nameLine = i.name.length > 15 ? i.name.substring(0, 15) : i.name;
                let qtyStr = String(i.qty).padStart(3, ' ');
                let costStr = (i.subtotal).toFixed(2).padStart(8, ' ');
                p += line(row(nameLine, qtyStr + costStr));
            });

            p += line('----------------------------');

            p += line(row('Sub Total', saleData.subtotal_amount.toFixed(2)));
            if (saleData.discount_amount > 0) {
                p += line(row('Discount (' + saleData.discount_type + ')', '-' + saleData.discount_amount.toFixed(2)));
            }
            p += line('----------------------------');
            p += line(row('Order Total', 'P' + saleData.total_amount.toFixed(2)));
            p += line(row('Paid (' + saleData.payment_method + ')', saleData.amount_paid.toFixed(2)));
            p += line(row('Change', saleData.change_amount.toFixed(2)));
            p += line();

            p += line('****************************');
            p += line(center('THANK YOU!'));
            p += line('****************************');
            p += line(center('Please come again!'));
            p += line(center('Powered by JDTech Solutions'));
            p += line('\n\n');
        }

        // Direct na ipapadala sa RawBT (Direktang mag-pi-print sa thermal printer)
        let base64Data = btoa(unescape(encodeURIComponent(p)));
        window.location.href = "rawbt:base64," + base64Data;

    } catch (e) {
        alert("Print Error: " + e.message);
    }
}
</script>

<?php require 'includes/footer.php'; ?>