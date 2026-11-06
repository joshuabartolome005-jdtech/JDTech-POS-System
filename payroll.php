<?php
require_once 'config/database.php';
require_once 'config/auth.php';
require_once 'includes/payroll_calc.php';
requireAdmin();

$pageTitle = 'Payroll';

$employees = $pdo->query("SELECT * FROM users ORDER BY full_name")->fetchAll();

$employeeId = (int)($_GET['employee_id'] ?? 0);
if (!$employeeId && $employees) {
    $employeeId = $employees[0]['id'];
}

$dateFrom = $_GET['from'] ?? date('Y-m-01');
$dateTo = $_GET['to'] ?? date('Y-m-15');

$payslip = null;
if ($employeeId) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$employeeId]);
    $employee = $stmt->fetch();
    if ($employee) {
        $payslip = buildPayslip($pdo, $employee, $dateFrom, $dateTo);
    }
}

require 'includes/header.php';
?>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <form class="d-flex gap-2 flex-wrap align-items-end" method="GET">
            <div>
                <label class="form-label small mb-1">Employee</label>
                <select name="employee_id" class="form-select">
                    <?php foreach ($employees as $e): ?>
                        <option value="<?= $e['id'] ?>" <?= $e['id'] == $employeeId ? 'selected' : '' ?>><?= htmlspecialchars($e['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label small mb-1">From</label>
                <input type="date" name="from" id="payFrom" class="form-control" value="<?= htmlspecialchars($dateFrom) ?>">
            </div>
            <div>
                <label class="form-label small mb-1">To</label>
                <input type="date" name="to" id="payTo" class="form-control" value="<?= htmlspecialchars($dateTo) ?>">
            </div>
            <button class="btn btn-primary">Compute</button>
            <button type="button" class="btn btn-outline-secondary" onclick="setCutoff('first')">1st - 15th</button>
            <button type="button" class="btn btn-outline-secondary" onclick="setCutoff('second')">16th - End</button>
        </form>
    </div>
</div>

<?php if ($payslip): ?>
<div class="row g-3">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                <span>Daily Breakdown - <?= htmlspecialchars($payslip['employee']['full_name']) ?></span>
                <a href="print_payslip?employee_id=<?= $employeeId ?>&from=<?= $dateFrom ?>&to=<?= $dateTo ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-printer"></i> Print Payslip
                </a>
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Time In</th>
                            <th>Time Out</th>
                            <th>Hours</th>
                            <th>Day Type</th>
                            <th class="text-end">Basic</th>
                            <th class="text-end">OT</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payslip['daily'] as $d): ?>
                        <tr class="<?= $d['label'] === 'Absent' ? 'text-muted' : '' ?>">
                            <td><?= date('M d', strtotime($d['date'])) ?> (<?= $d['day_name'] ?>)</td>
                            <td><?= $d['time_in'] ?></td>
                            <td><?= $d['time_out'] ?></td>
                            <td><?= $d['hours'] > 0 ? number_format($d['hours'], 2) : '-' ?></td>
                            <td><small><?= htmlspecialchars($d['label']) ?></small></td>
                            <td class="text-end"><?= $d['basic'] > 0 ? number_format($d['basic'], 2) : '-' ?></td>
                            <td class="text-end"><?= $d['ot'] > 0 ? number_format($d['ot'], 2) : '-' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Summary</div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr><td>Total Basic Pay</td><td class="text-end">&#8369;<?= number_format($payslip['total_basic'], 2) ?></td></tr>
                    <tr><td>Total Overtime Pay</td><td class="text-end">&#8369;<?= number_format($payslip['total_ot'], 2) ?></td></tr>
                    <tr><td>Reward (gross)</td><td class="text-end">&#8369;<?= number_format($payslip['reward_gross'], 2) ?></td></tr>
                    <tr><td class="text-danger">Violation Deductions</td><td class="text-end text-danger">-&#8369;<?= number_format($payslip['deductions'], 2) ?></td></tr>
                    <tr><td>Reward (net)</td><td class="text-end">&#8369;<?= number_format($payslip['reward_net'], 2) ?></td></tr>
                    <tr class="fw-bold fs-5"><td>Grand Total</td><td class="text-end">&#8369;<?= number_format($payslip['grand_total'], 2) ?></td></tr>
                </table>

                <?php if ($payslip['violations']): ?>
                <hr>
                <div class="fw-semibold small mb-2">Violations this period:</div>
                <?php foreach ($payslip['violations'] as $v): ?>
                    <div class="small text-muted mb-1">
                        <?= date('M d', strtotime($v['violation_date'])) ?> - <?= htmlspecialchars($v['description']) ?>
                        (-&#8369;<?= number_format($v['deduction_amount'], 2) ?>)
                    </div>
                <?php endforeach; ?>
                <?php endif; ?>

                <p class="text-muted small mt-3 mb-0">
                    Daily rate: &#8369;<?= number_format($payslip['employee']['daily_rate'], 2) ?> |
                    COLA: &#8369;<?= number_format($payslip['employee']['cola_rate'], 2) ?> |
                    Rest day: <?= $payslip['employee']['rest_day'] !== null ? ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'][$payslip['employee']['rest_day']] : 'Not set' ?>
                </p>
            </div>
        </div>
    </div>
</div>
<?php else: ?>
    <div class="alert alert-secondary">No employees found. Add users first.</div>
<?php endif; ?>

<script>
function setCutoff(half) {
    const today = new Date();
    const y = today.getFullYear();
    const m = today.getMonth();
    let from, to;
    if (half === 'first') {
        from = new Date(y, m, 1);
        to = new Date(y, m, 15);
    } else {
        from = new Date(y, m, 16);
        to = new Date(y, m + 1, 0);
    }
    document.getElementById('payFrom').value = from.toISOString().slice(0, 10);
    document.getElementById('payTo').value = to.toISOString().slice(0, 10);
}
</script>

<?php require 'includes/footer.php'; ?>