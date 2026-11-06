<?php
require 'config/database.php';
require 'config/auth.php';
require 'config/settings.php';
requireLogin();

$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT sc.*, u.full_name FROM shift_closings sc JOIN users u ON sc.user_id = u.id WHERE sc.id = ?");
$stmt->execute([$id]);
$sc = $stmt->fetch();

if (!$sc) {
    die('Z-Reading not found.');
}

// Pull the expense line items that were part of this closing's period
$stmt = $pdo->prepare("SELECT * FROM expenses WHERE user_id = ? AND created_at BETWEEN ? AND ? ORDER BY created_at ASC");
$stmt->execute([$sc['user_id'], $sc['period_start'], $sc['period_end']]);
$expenseItems = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Z-Reading #<?= $sc['id'] ?></title>
<style>
    * { box-sizing: border-box; }
    body {
        font-family: 'Courier New', Courier, monospace;
        font-size: 13px;
        color: #000;
        width: 280px;
        margin: 10px auto;
    }
    .center { text-align: center; }
    .bold { font-weight: 700; }
    hr { border: none; border-top: 1px dashed #000; margin: 8px 0; }
    table { width: 100%; border-collapse: collapse; }
    td { padding: 2px 0; }
    .t-value { text-align: right; }
    .grand-total { font-size: 15px; font-weight: 700; border-top: 1px dashed #000; padding-top: 6px; }
    .receipt-logo { display: block; max-width: 180px; width: 100%; height: auto; margin: 0 auto 6px; }
    .print-btn-wrap { text-align: center; margin-top: 16px; }
    .print-btn-wrap button { font-family: inherit; padding: 8px 18px; border: 1px solid #333; background: #fff; cursor: pointer; border-radius: 6px; }
    .expense-line { font-size: 11px; }
    @media print {
        .no-print { display: none !important; }
        body { width: 80mm; margin: 0; }
        @page { size: 80mm auto; margin: 0; }
    }
</style>
</head>
<body>

    <img src="<?= htmlspecialchars(BUSINESS_LOGO) ?>" alt="" class="receipt-logo">
    <div class="center bold">CASH HAND OVER FORM</div>
    <div class="center">Z-Reading #<?= $sc['id'] ?></div>
    <hr>
    <table>
        <tr><td>Cashier</td><td class="t-value"><?= htmlspecialchars($sc['full_name']) ?></td></tr>
        <tr><td>Period</td><td class="t-value" style="font-size:11px;"><?= date('m/d h:i A', strtotime($sc['period_start'])) ?> -<br><?= date('m/d h:i A', strtotime($sc['period_end'])) ?></td></tr>
        <tr><td>Closed At</td><td class="t-value"><?= date('M d, Y h:i A', strtotime($sc['created_at'])) ?></td></tr>
    </table>
    <hr>
    <table>
        <tr><td>Transactions</td><td class="t-value"><?= $sc['transaction_count'] ?></td></tr>
        <tr><td>Cash Sales</td><td class="t-value"><?= number_format($sc['cash_sales'], 2) ?></td></tr>
        <tr><td>GCash Sales</td><td class="t-value"><?= number_format($sc['gcash_sales'], 2) ?></td></tr>
        <tr><td>GoTyme Sales</td><td class="t-value"><?= number_format($sc['gotyme_sales'], 2) ?></td></tr>
        <tr><td>Card Sales</td><td class="t-value"><?= number_format($sc['card_sales'], 2) ?></td></tr>
        <tr><td>Grab Sales</td><td class="t-value"><?= number_format($sc['grab_sales'], 2) ?></td></tr>
        <tr><td>FoodPanda Sales</td><td class="t-value"><?= number_format($sc['foodpanda_sales'], 2) ?></td></tr>
        <tr><td>Wrapnatics Account</td><td class="t-value"><?= number_format($sc['wrapnatics_account_sales'], 2) ?></td></tr>
        <tr><td>Discounts Given</td><td class="t-value">-<?= number_format($sc['total_discount'], 2) ?></td></tr>
        <tr class="grand-total"><td>Total Sales</td><td class="t-value">&#8369;<?= number_format($sc['total_sales'], 2) ?></td></tr>
    </table>

    <?php if ($expenseItems): ?>
    <hr>
    <div class="bold">Expenses:</div>
    <table>
        <?php foreach ($expenseItems as $exp): ?>
        <tr class="expense-line">
            <td><?= htmlspecialchars($exp['description']) ?></td>
            <td class="t-value"><?= number_format($exp['amount'], 2) ?></td>
        </tr>
        <?php endforeach; ?>
        <tr class="bold">
            <td>Total Expenses</td>
            <td class="t-value">-<?= number_format($sc['total_expenses'], 2) ?></td>
        </tr>
    </table>
    <?php endif; ?>

    <hr>
    <table>
        <tr><td>Beginning Cash Float</td><td class="t-value"><?= number_format($sc['starting_cash'], 2) ?></td></tr>
        <tr><td>+ Cash Sales</td><td class="t-value"><?= number_format($sc['cash_sales'], 2) ?></td></tr>
        <tr><td>- Remit (kay Sir)</td><td class="t-value"><?= number_format($sc['remit_amount'], 2) ?></td></tr>
        <tr><td>- Total Expenses</td><td class="t-value"><?= number_format($sc['total_expenses'], 2) ?></td></tr>
        <tr class="grand-total"><td>Total Cash Expected</td><td class="t-value"><?= number_format($sc['expected_cash'], 2) ?></td></tr>
        <tr><td>Actual Cash Counted</td><td class="t-value"><?= number_format($sc['counted_cash'], 2) ?></td></tr>
        <tr class="grand-total">
            <td>Cash Over</td>
            <td class="t-value"><?= $sc['variance'] > 0 ? number_format($sc['variance'], 2) : '0.00' ?></td>
        </tr>
        <tr class="grand-total">
            <td>Cash Short</td>
            <td class="t-value"><?= $sc['variance'] < 0 ? number_format(abs($sc['variance']), 2) : '0.00' ?></td>
        </tr>
    </table>
    <?php if (!empty($sc['notes'])): ?>
    <hr>
    <div><strong>Notes:</strong> <?= htmlspecialchars($sc['notes']) ?></div>
    <?php endif; ?>

    <div class="print-btn-wrap no-print">
        <button onclick="window.print()">Print</button>
    </div>

<script>
    window.addEventListener('load', function () {
        setTimeout(function () { window.print(); }, 250);
    });
    window.addEventListener('afterprint', function () {
        window.close();
    });
</script>

</body>
</html>