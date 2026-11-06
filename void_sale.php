<?php
require 'config/database.php';
require 'config/auth.php';
require 'includes/audit.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: sales_history');
    exit;
}

$saleId = (int)($_POST['sale_id'] ?? 0);
$reason = trim($_POST['void_reason'] ?? '');
$redirect = $_POST['redirect'] ?? 'sales_history';

if ($saleId <= 0) {
    header('Location: sales_history?void_error=' . urlencode('No transaction was selected (missing sale ID). Please click the Void button again.'));
    exit;
}
if ($reason === '') {
    header('Location: sales_history?void_error=' . urlencode('Please enter a reason for voiding before submitting.'));
    exit;
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT * FROM sales WHERE id = ? FOR UPDATE");
    $stmt->execute([$saleId]);
    $sale = $stmt->fetch();

    if (!$sale) {
        throw new Exception('Transaction not found.');
    }
    if ($sale['status'] === 'voided') {
        throw new Exception('This transaction has already been voided.');
    }

    // Restore stock for every item in this sale
    $stmt = $pdo->prepare("SELECT * FROM sale_items WHERE sale_id = ?");
    $stmt->execute([$saleId]);
    $items = $stmt->fetchAll();

    foreach ($items as $item) {
        if ($item['product_id']) {
            $stmt = $pdo->prepare("UPDATE products SET stock_qty = stock_qty + ? WHERE id = ?");
            $stmt->execute([$item['qty'], $item['product_id']]);
        }
    }

    $stmt = $pdo->prepare("UPDATE sales SET status = 'voided', voided_at = NOW(), voided_by = ?, void_reason = ? WHERE id = ?");
    $stmt->execute([$_SESSION['user_id'], $reason, $saleId]);

    $cashierStmt = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
    $cashierStmt->execute([$sale['user_id']]);
    $cashierName = $cashierStmt->fetchColumn() ?: 'Unknown';

    $itemsSummary = implode(', ', array_map(
        fn($it) => $it['qty'] . 'x ' . $it['product_name'],
        $items
    ));

    log_audit($pdo, $_SESSION['user_id'], 'Void Transaction',
        "Voided sale #$saleId ({$sale['invoice_no']}), originally checked out by $cashierName - Items: $itemsSummary - Total: " . number_format($sale['total_amount'], 2) . " - Reason: $reason");

    $pdo->commit();

    header('Location: ' . $redirect . ($saleId ? (strpos($redirect, '?') !== false ? '&' : '?') . 'voided=1' : ''));
    exit;
} catch (Exception $e) {
    $pdo->rollBack();
    header('Location: sales_history?void_error=' . urlencode($e->getMessage()));
    exit;
}
