<?php
require 'config/database.php';
require 'config/auth.php';
require 'config/settings.php';
require 'includes/payroll_calc.php';
requireAdmin();

$employeeId = (int)($_GET['employee_id'] ?? 0);
$dateFrom = $_GET['from'] ?? date('Y-m-01');
$dateTo = $_GET['to'] ?? date('Y-m-15');

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$employeeId]);
$employee = $stmt->fetch();

if (!$employee) {
    die('Employee not found.');
}

$payslip = buildPayslip($pdo, $employee, $dateFrom, $dateTo);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Payslip - <?= htmlspecialchars($employee['full_name']) ?></title>
<style>
    * { box-sizing: border-box; }
    body {
        font-family: 'Courier New', Courier, monospace;
        font-size: 12px;
        color: #000;
        width: 320px;
        margin: 10px auto;
    }
    .center { text-align: center; }
    .bold { font-weight: 700; }
    hr { border: none; border-top: 1px dashed #000; margin: 6px 0; }
    table { width: 100%; border-collapse: collapse; }
    td, th { padding: 2px 0; text-align: left; }
    .t-end { text-align: right; }
    .grand { font-size: 14px; font-weight: 700; border-top: 1px dashed #000; padding-top: 4px; }
    .receipt-logo { display: block; max-width: 160px; width: 100%; height: auto; margin: 0 auto 6px; }
    .print-btn-wrap { text-align: center; margin-top: 16px; }
    .print-btn-wrap button { font-family: inherit; padding: 8px 18px; border: 1px solid #333; background: #fff; cursor: pointer; border-radius: 6px; }
    .daily-row { font-size: 10px; }
    @media print {
        .no-print { display: none !important; }
        body { width: 80mm; margin: 0; }
        @page { size: 80mm auto; margin: 0; }
    }
</style>
</head>
<body>

    <img src="<?= htmlspecialchars(BUSINESS_LOGO) ?>" alt="" class="receipt-logo">
    <div class="center bold">PAYSLIP</div>
    <hr>
    <table>
        <tr><td>Employee</td><td class="t-end"><?= htmlspecialchars($employee['full_name']) ?></td></tr>
        <tr><td>Period</td><td class="t-end"><?= date('M d', strtotime($dateFrom)) ?> - <?= date('M d, Y', strtotime($dateTo)) ?></td></tr>
    </table>
    <hr>

    <table>
        <thead>
            <tr class="daily-row"><th>Date</th><th>Hrs</th><th class="t-end">Basic</th><th class="t-end">OT</th></tr>
        </thead>
        <tbody>
            <?php foreach ($payslip['daily'] as $d): ?>
                <?php if ($d['basic'] > 0 || $d['ot'] > 0): ?>
                <tr class="daily-row">
                    <td><?= date('m/d', strtotime($d['date'])) ?></td>
                    <td><?= $d['hours'] > 0 ? number_format($d['hours'], 1) : '-' ?></td>
                    <td class="t-end"><?= number_format($d['basic'], 2) ?></td>
                    <td class="t-end"><?= $d['ot'] > 0 ? number_format($d['ot'], 2) : '-' ?></td>
                </tr>
                <?php endif; ?>
            <?php endforeach; ?>
        </tbody>
    </table>

    <hr>
    <table>
        <tr><td>Total Basic Pay</td><td class="t-end"><?= number_format($payslip['total_basic'], 2) ?></td></tr>
        <tr><td>Total Overtime Pay</td><td class="t-end"><?= number_format($payslip['total_ot'], 2) ?></td></tr>
        <tr><td>Reward (gross)</td><td class="t-end"><?= number_format($payslip['reward_gross'], 2) ?></td></tr>
        <tr><td>Violation Deductions</td><td class="t-end">-<?= number_format($payslip['deductions'], 2) ?></td></tr>
        <tr><td>Reward (net)</td><td class="t-end"><?= number_format($payslip['reward_net'], 2) ?></td></tr>
        <tr class="grand"><td>GRAND TOTAL</td><td class="t-end">&#8369;<?= number_format($payslip['grand_total'], 2) ?></td></tr>
    </table>

    <?php if ($payslip['violations']): ?>
    <hr>
    <div class="bold">Violations:</div>
    <?php foreach ($payslip['violations'] as $v): ?>
        <div class="daily-row"><?= date('m/d', strtotime($v['violation_date'])) ?> - <?= htmlspecialchars($v['description']) ?> (-<?= number_format($v['deduction_amount'], 2) ?>)</div>
    <?php endforeach; ?>
    <?php endif; ?>

    <hr>
    <div class="center" style="font-size:10px;">Generated <?= date('M d, Y h:i A') ?></div>

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