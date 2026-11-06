<?php
require 'config/database.php';
require 'config/auth.php';
require 'config/settings.php';
requireLogin();

$id = (int)($_GET['id'] ?? 0);
$allowedTypes = ['customer', 'kitchen', 'both'];
$type = in_array($_GET['type'] ?? 'both', $allowedTypes) ? $_GET['type'] : 'both';

$stmt = $pdo->prepare("SELECT s.*, u.full_name FROM sales s LEFT JOIN users u ON s.user_id = u.id WHERE s.id = ?");
$stmt->execute([$id]);
$sale = $stmt->fetch();

if (!$sale) {
    die('Receipt not found.');
}

$stmt = $pdo->prepare("SELECT si.*, p.sku FROM sale_items si LEFT JOIN products p ON si.product_id = p.id WHERE si.sale_id = ?");
$stmt->execute([$id]);
$items = $stmt->fetchAll();

$dateStr = date('M d, Y', strtotime($sale['created_at']));
$timeStr = date('h:i A', strtotime($sale['created_at']));
$isVoided = $sale['status'] === 'voided';

$showCustomer = ($type === 'customer' || $type === 'both');
$showKitchen  = ($type === 'kitchen' || $type === 'both');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Receipt #<?= htmlspecialchars($sale['invoice_no']) ?></title>
<style>
    * { box-sizing: border-box; }
    body {
        font-family: 'Courier New', Courier, monospace;
        font-size: 13px;
        color: #000;
        margin: 10px auto;
        padding: 0;
    }
    .receipt-page {
        width: 280px;
        margin: 0 auto;
    }
    .center { text-align: center; }
    .right { text-align: right; }
    .bold { font-weight: 700; }
    .label-sm {
        font-size: 11px;
        letter-spacing: 1px;
        text-transform: uppercase;
        color: #333;
    }
    .receipt-no {
        font-size: 18px;
        font-weight: 700;
        margin-bottom: 6px;
    }
    .shop-name {
        font-size: 15px;
        font-weight: 700;
    }
    hr {
        border: none;
        border-top: 1px dashed #000;
        margin: 8px 0;
    }
    .info-table td { padding: 1px 0; vertical-align: top; }
    .info-table .info-label { width: 75px; }
    table.items { width: 100%; border-collapse: collapse; }
    table.items th, table.items td {
        text-align: left;
        padding: 3px 2px;
        font-size: 12px;
        vertical-align: top;
    }
    table.items th {
        border-bottom: 1px dashed #000;
        font-size: 11px;
        text-transform: uppercase;
    }
    .col-qty { text-align: center; width: 28px; }
    .col-cost { text-align: right; width: 60px; }
    .totals-table { width: 100%; margin-top: 4px; }
    .totals-table td { padding: 2px 0; }
    .totals-table .t-label { text-align: left; }
    .totals-table .t-value { text-align: right; }
    .grand-total td {
        font-size: 16px;
        font-weight: 700;
        border-top: 1px dashed #000;
        padding-top: 6px;
    }
    .stars {
        letter-spacing: 2px;
        margin: 10px 0 4px;
    }
    .thank-you {
        font-size: 16px;
        font-weight: 700;
        margin: 4px 0;
    }
    .tagline {
        font-size: 11px;
        color: #333;
    }
    .print-btn-wrap {
        text-align: center;
        margin-top: 16px;
        margin-bottom: 16px;
    }
    .print-btn-wrap button {
        font-family: inherit;
        padding: 8px 18px;
        border: 1px solid #333;
        background: #fff;
        cursor: pointer;
        border-radius: 6px;
    }
    .receipt-logo {
        display: block;
        max-width: 200px;
        width: 100%;
        height: auto;
        margin: 0 auto 6px;
    }
    .dev-credit-print {
        text-align: center;
        font-size: 9px;
        color: #666;
        margin-top: 10px;
    }
    /* Kitchen slip specific styling - big and easy to scan */
    .kitchen-banner {
        text-align: center;
        font-size: 20px;
        font-weight: 700;
        letter-spacing: 2px;
        border: 2px solid #000;
        padding: 6px 0;
        margin-bottom: 8px;
    }
    table.kitchen-items { width: 100%; border-collapse: collapse; }
    table.kitchen-items td {
        padding: 8px 2px;
        border-bottom: 1px dashed #000;
        vertical-align: top;
    }
    .kitchen-qty {
        font-size: 22px;
        font-weight: 700;
        width: 44px;
        text-align: center;
    }
    .kitchen-name {
        font-size: 16px;
        font-weight: 700;
        text-transform: uppercase;
    }
    .voided-stamp {
        text-align: center;
        font-size: 22px;
        font-weight: 700;
        letter-spacing: 4px;
        border: 3px solid #000;
        padding: 8px 0;
        margin-bottom: 10px;
        transform: rotate(-3deg);
    }
    .page-break {
        page-break-after: always;
        break-after: page;
    }
    @media print {
        .no-print { display: none !important; }
        body { width: 80mm; margin: 0; }
        @page { size: 80mm auto; margin: 0; }
    }
</style>
</head>
<body>

<?php if ($showCustomer): ?>
    <div class="receipt-page <?= $showKitchen ? 'page-break' : '' ?>">

        <?php if ($isVoided): ?>
            <div class="voided-stamp">VOIDED</div>
        <?php endif; ?>

        <img src="<?= htmlspecialchars(BUSINESS_LOGO) ?>" alt="<?= htmlspecialchars(BUSINESS_NAME) ?>" class="receipt-logo">

        <div class="center">
            <div class="label-sm">Order</div>
            <div class="receipt-no">Receipt #<?= htmlspecialchars($sale['invoice_no']) ?></div>
        </div>

        <div class="center">
            <div><?= htmlspecialchars(BUSINESS_TAGLINE) ?></div>
            <div><?= htmlspecialchars(BUSINESS_ADDRESS) ?></div>
            <div><?= $dateStr ?> <?= $timeStr ?></div>
        </div>

        <hr>

        <table class="info-table">
            <tr><td class="info-label">Cashier</td><td>: <?= htmlspecialchars($sale['full_name'] ?? '-') ?></td></tr>
            <tr><td class="info-label">Payment</td><td>: <?= htmlspecialchars($sale['payment_method']) ?></td></tr>
        </table>

        <hr>

        <table class="items">
            <thead>
                <tr>
                    <th>Item</th>
                    <th class="col-qty">Qty</th>
                    <th class="col-cost">Cost</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $it): ?>
                <tr>
                    <td>
                        <?= htmlspecialchars($it['product_name']) ?><br>
                        <span class="label-sm"><?= htmlspecialchars($it['sku'] ?? '-') ?></span>
                    </td>
                    <td class="col-qty"><?= $it['qty'] ?></td>
                    <td class="col-cost"><?= number_format($it['subtotal'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <table class="totals-table">
            <tr>
                <td class="t-label">Sub Total</td>
                <td class="t-value"><?= number_format($sale['subtotal_amount'], 2) ?></td>
            </tr>
            <?php if ($sale['discount_amount'] > 0): ?>
            <tr>
                <td class="t-label">Discount (<?= htmlspecialchars(ucfirst($sale['discount_type'])) ?>)</td>
                <td class="t-value">-<?= number_format($sale['discount_amount'], 2) ?></td>
            </tr>
            <?php endif; ?>
            <tr class="grand-total">
                <td class="t-label">Order Total</td>
                <td class="t-value">&#8369;<?= number_format($sale['total_amount'], 2) ?></td>
            </tr>
            <tr>
                <td class="t-label">Paid (<?= htmlspecialchars($sale['payment_method']) ?>)</td>
                <td class="t-value"><?= number_format($sale['amount_paid'], 2) ?></td>
            </tr>
            <tr>
                <td class="t-label">Change</td>
                <td class="t-value"><?= number_format($sale['change_amount'], 2) ?></td>
            </tr>
        </table>

        <?php if ($sale['discount_amount'] > 0): ?>
        <div class="center label-sm">
            <?= htmlspecialchars(ucfirst($sale['discount_type'])) ?> ID: <?= htmlspecialchars($sale['discount_id_number']) ?><br>
            Name: <?= htmlspecialchars($sale['discount_name']) ?>
        </div>
        <?php endif; ?>

        <div class="center stars">********************************</div>
        <div class="center thank-you">THANK YOU!</div>
        <div class="center stars">********************************</div>
        <div class="center tagline">Please come again!</div>

        <div class="dev-credit-print">Powered by <?= htmlspecialchars(DEV_COMPANY_NAME) ?></div>

    </div>
<?php endif; ?>

<?php if ($showKitchen): ?>
    <div class="receipt-page">

        <?php if ($isVoided): ?>
            <div class="voided-stamp">VOIDED</div>
        <?php endif; ?>

        <div class="kitchen-banner">KITCHEN ORDER</div>

        <div class="center">
            <div class="receipt-no">#<?= htmlspecialchars($sale['invoice_no']) ?></div>
            <div><?= $dateStr ?> <?= $timeStr ?></div>
        </div>

        <hr>

        <table class="kitchen-items">
            <?php foreach ($items as $it): ?>
            <tr>
                <td class="kitchen-qty"><?= $it['qty'] ?>x</td>
                <td class="kitchen-name"><?= htmlspecialchars($it['product_name']) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>

        <hr>

        <div class="center label-sm">No prices shown - kitchen copy only</div>

    </div>
<?php endif; ?>

    <div class="print-btn-wrap no-print">
        <button onclick="triggerRawBTPrint()">Print</button>
    </div>

<script>
    function triggerRawBTPrint() {
        // Kunin ang plain text ng resibo nang walang mga HTML elements o buttons
        let printContent = document.body.innerText.replace(/Print/g, '').trim();
        
        // I-convert sa Base64 para sa RawBT intent
        let base64Data = btoa(unescape(encodeURIComponent(printContent)));
        
        // I-trigger ang RawBT Direct Intent na may browser fallback para hindi mag-redirect sa Play Store
        let intentUrl = "intent:base64," + base64Data + "#Intent;scheme=rawbt;package=ru.a404m.rawbt;S.browser_fallback_url=;end;";
        
        window.location.href = intentUrl;

        // Isara ang tab pagkalipas ng 1.5 segundo
        setTimeout(function () {
            window.close();
        }, 1500);
    }

    // Auto-trigger sa pag-load ng page
    window.addEventListener('load', function () {
        setTimeout(triggerRawBTPrint, 300);
    });
</script>

</body>
</html>