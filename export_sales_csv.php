<?php
require 'config/database.php';
require 'config/auth.php';
requireLogin();

$dateFrom = $_GET['from'] ?? date('Y-m-01');
$dateTo = $_GET['to'] ?? date('Y-m-d');

$stmt = $pdo->prepare("SELECT s.*, u.full_name FROM sales s LEFT JOIN users u ON s.user_id = u.id WHERE DATE(s.created_at) BETWEEN ? AND ? ORDER BY s.created_at ASC");
$stmt->execute([$dateFrom, $dateTo]);
$sales = $stmt->fetchAll();

$filename = 'sales_' . $dateFrom . '_to_' . $dateTo . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');

// UTF-8 BOM so Excel opens the file with the correct character set
fwrite($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

fputcsv($output, ['Invoice #', 'Cashier', 'Subtotal (PHP)', 'Discount Type', 'Discount (PHP)', 'Total (PHP)', 'Paid (PHP)', 'Change (PHP)', 'Payment Method', 'Status', 'Date']);

foreach ($sales as $s) {
    fputcsv($output, [
        $s['invoice_no'],
        $s['full_name'] ?? '-',
        number_format($s['subtotal_amount'], 2, '.', ''),
        $s['discount_type'],
        number_format($s['discount_amount'], 2, '.', ''),
        number_format($s['total_amount'], 2, '.', ''),
        number_format($s['amount_paid'], 2, '.', ''),
        number_format($s['change_amount'], 2, '.', ''),
        $s['payment_method'],
        ucfirst($s['status']),
        date('Y-m-d h:i A', strtotime($s['created_at'])),
    ]);
}

fclose($output);
exit;
