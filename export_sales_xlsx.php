<?php
require 'config/database.php';
require 'config/auth.php';
require 'includes/xlsx_writer.php';
requireLogin();

$dateFrom = $_GET['from'] ?? date('Y-m-01');
$dateTo = $_GET['to'] ?? date('Y-m-d');

$stmt = $pdo->prepare("SELECT s.*, u.full_name FROM sales s LEFT JOIN users u ON s.user_id = u.id WHERE DATE(s.created_at) BETWEEN ? AND ? ORDER BY s.created_at ASC");
$stmt->execute([$dateFrom, $dateTo]);
$sales = $stmt->fetchAll();

$headers = ['Invoice #', 'Cashier', 'Subtotal (PHP)', 'Discount Type', 'Discount (PHP)', 'Total (PHP)', 'Paid (PHP)', 'Change (PHP)', 'Payment Method', 'Status', 'Date'];
$rows = [];
foreach ($sales as $s) {
    $rows[] = [
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
    ];
}

$tmpFile = tempnam(sys_get_temp_dir(), 'xlsx');
$success = write_simple_xlsx($tmpFile, $headers, $rows);

if (!$success) {
    die('Unable to generate the Excel file. The PHP Zip extension (php_zip) may not be enabled in your XAMPP php.ini.');
}

$filename = 'sales_' . $dateFrom . '_to_' . $dateTo . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($tmpFile));
readfile($tmpFile);
unlink($tmpFile);
exit;
