<?php
$pageTitle = 'Shift Closing';
require 'config/database.php';
require 'config/auth.php';
require 'includes/audit.php';
requireLogin();

$userId = (int)$_SESSION['user_id'];
$isAdmin = ($_SESSION['role'] ?? '') === 'admin';

// 1. HANAPIN ANG LAST CLOSING TIME & PINAKA-MAAGANG UNCLOSED SALE
$stmt = $pdo->prepare("SELECT period_end FROM shift_closings WHERE user_id = ? ORDER BY period_end DESC LIMIT 1");
$stmt->execute([$userId]);
$lastClosing = $stmt->fetchColumn();

if ($lastClosing) {
    $stmtFirst = $pdo->prepare("SELECT MIN(created_at) FROM sales WHERE user_id = ? AND status = 'completed' AND created_at > ?");
    $stmtFirst->execute([$userId, $lastClosing]);
} else {
    $stmtFirst = $pdo->prepare("SELECT MIN(created_at) FROM sales WHERE user_id = ? AND status = 'completed'");
    $stmtFirst->execute([$userId]);
}
$firstUnclosedSale = $stmtFirst->fetchColumn();

// 2. DATE-BOUNDED LOGIC: ALAMIN KUNG MAY SHIFT MULA SA NAKARAANG ARAW
$today = $pdo->query("SELECT CURDATE()")->fetchColumn();
$isBackdatedShift = false;

if ($firstUnclosedSale && date('Y-m-d', strtotime($firstUnclosedSale)) < $today) {
    // May unclosed shift mula sa nakaraang araw (e.g. Sep 23)
    $targetDate = date('Y-m-d', strtotime($firstUnclosedSale));
    $periodStart = $lastClosing ? $lastClosing : $targetDate . ' 00:00:00';
    $periodEnd = $targetDate . ' 23:59:59'; // Hanggang dulo lang ng araw na iyon
    $isBackdatedShift = true;
} else {
    // Shift closing ngayong araw
    $targetDate = $today;
    $periodStart = $lastClosing ? $lastClosing : $today . ' 00:00:00';
    $periodEnd = $pdo->query("SELECT NOW()")->fetchColumn();
}

// Check kung na-close na ang shift sa target date
$stmt = $pdo->prepare("SELECT * FROM shift_closings WHERE user_id = ? AND DATE(period_end) = ? ORDER BY created_at DESC LIMIT 1");
$stmt->execute([$userId, $targetDate]);
$todayClosing = $stmt->fetch();

// 3. PULL COMPLETED SALES PARA SA SPECIFIC PERIOD BOUNDARY
$stmt = $pdo->prepare("SELECT * FROM sales WHERE user_id = ? AND status = 'completed' AND created_at BETWEEN ? AND ?");
$stmt->execute([$userId, $periodStart, $periodEnd]);
$sales = $stmt->fetchAll();

$transactionCount = count($sales);
$totalSales = 0;
$totalDiscount = 0;
$cashSales = 0;
$gcashSales = 0;
$cardSales = 0;
$gotymeSales = 0;
$grabSales = 0;
$foodpandaSales = 0;
$wrapnaticsAccountSales = 0;

foreach ($sales as $s) {
    $totalSales += $s['total_amount'];
    $totalDiscount += $s['discount_amount'];
    switch ($s['payment_method']) {
        case 'Cash': $cashSales += $s['total_amount']; break;
        case 'GCash': $gcashSales += $s['total_amount']; break;
        case 'Card': $cardSales += $s['total_amount']; break;
        case 'GoTyme': $gotymeSales += $s['total_amount']; break;
        case 'Grab': $grabSales += $s['total_amount']; break;
        case 'FoodPanda': $foodpandaSales +=$s['total_amount']; break;
        case 'Wrapnatics Account': $wrapnaticsAccountSales +=$s['total_amount']; break;
    }
}

// PULL EXPENSES WITHIN THE PERIOD
$stmt = $pdo->prepare("SELECT * FROM expenses WHERE user_id = ? AND created_at BETWEEN ? AND ? ORDER BY created_at ASC");
$stmt->execute([$userId, $periodStart, $periodEnd]);
$periodExpenses = $stmt->fetchAll();
$totalExpenses = array_sum(array_column($periodExpenses, 'amount'));

$error = '';

// 4. FORM SUBMISSION PROCESS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['close_shift'])) {
    if ($todayClosing && !$isBackdatedShift) {
        $error = 'You already closed your shift today. Ask an admin to undo it if you need to close again.';
    } else {
        $startingCash = (float)($_POST['starting_cash'] ?? 0);
        $remitAmount = (float)($_POST['remit_amount'] ?? 0);
        $countedCash = (float)($_POST['counted_cash'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');

        if ($transactionCount === 0 && $startingCash == 0 && $countedCash == 0) {
            $error = 'Nothing to close - no transactions and no cash entered.';
        } else {
            $expectedCash = $startingCash + $cashSales - $remitAmount - $totalExpenses;
            $variance = $countedCash - $expectedCash;

            $stmt = $pdo->prepare("INSERT INTO shift_closings
                (user_id, period_start, period_end, starting_cash, remit_amount, cash_sales, gcash_sales, card_sales, gotyme_sales, grab_sales, foodpanda_sales, wrapnatics_account_sales, total_sales, total_discount, total_expenses, transaction_count, expected_cash, counted_cash, variance, notes, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $userId, $periodStart, $periodEnd, $startingCash, $remitAmount, 
                $cashSales, $gcashSales, $cardSales, $gotymeSales, $grabSales, 
                $foodpandaSales, $wrapnaticsAccountSales, $totalSales, $totalDiscount, 
                $totalExpenses, $transactionCount, $expectedCash, $countedCash, 
                $variance, $notes ?: null, $periodEnd
            ]);
            $newId = $pdo->lastInsertId();

            log_audit($pdo, $userId, 'Shift Closing', 'Closed shift #' . $newId . ' for date ' . $targetDate . ' - Total sales: ' . number_format($totalSales, 2) . ', Expenses: ' . number_format($totalExpenses, 2) . ', Variance: ' . number_format($variance, 2));

            header('Location: shift_closing?closed=' . $newId);
            exit;
        }
    }
}

// 5. SHIFT CLOSING HISTORY
$histFrom = $_GET['from'] ?? date('Y-m-01');
$histTo = $_GET['to'] ?? date('Y-m-d');

if ($isAdmin) {
    $stmt = $pdo->prepare("SELECT sc.*, u.full_name FROM shift_closings sc JOIN users u ON sc.user_id = u.id WHERE DATE(sc.created_at) BETWEEN ? AND ? ORDER BY sc.created_at DESC");
    $stmt->execute([$histFrom, $histTo]);
} else {
    $stmt = $pdo->prepare("SELECT sc.*, u.full_name FROM shift_closings sc JOIN users u ON sc.user_id = u.id WHERE sc.user_id = ? AND DATE(sc.created_at) BETWEEN ? AND ? ORDER BY sc.created_at DESC");
    $stmt->execute([$userId, $histFrom, $histTo]);
}
$closings = $stmt->fetchAll();

require 'includes/header.php';
?>

<!-- SweetAlert2 CSS -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">

<?php if (isset($_GET['closed'])): ?>
    <div class="alert alert-success d-flex justify-content-between align-items-center">
        <span>Shift closed successfully.</span>
        <a href="print_zreading?id=<?= (int)$_GET['closed'] ?>" target="_blank" class="btn btn-sm btn-outline-success"><i class="bi bi-printer"></i> Print Z-Reading</a>
    </div>
<?php elseif (isset($_GET['undone'])): ?>
    <div class="alert alert-success">Shift closing undone. The cashier can now close their shift again.</div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Cash Hand Over Form</div>
            <div class="card-body">
                <?php if ($error): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div><?php endif; ?>

                <?php if ($isBackdatedShift): ?>
                    <div class="alert alert-warning border-warning py-2 mb-3">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i>
                        <strong>Unclosed Shift Detected:</strong> Closing shift for 
                        <strong><?= date('M d, Y', strtotime($targetDate)) ?></strong>.
                    </div>
                <?php endif; ?>

                <?php if ($todayClosing && !$isBackdatedShift): ?>
                    <div class="alert alert-secondary">
                        <i class="bi bi-lock-fill"></i> You already closed your shift today at
                        <strong><?= date('h:i A', strtotime($todayClosing['created_at'])) ?></strong>.
                        <?php if (!$isAdmin): ?>
                            <div class="small mt-1">Ask an admin to undo it if you need to close again today.</div>
                        <?php endif; ?>
                    </div>
                    <table class="table table-sm mb-3">
                        <tr><td>Transactions</td><td class="text-end fw-semibold"><?= $todayClosing['transaction_count'] ?></td></tr>
                        <tr><td>Total Sales</td><td class="text-end">&#8369;<?= number_format($todayClosing['total_sales'], 2) ?></td></tr>
                        <tr><td>Total Expenses</td><td class="text-end text-danger">-&#8369;<?= number_format($todayClosing['total_expenses'], 2) ?></td></tr>
                        <tr><td>Total Cash Expected</td><td class="text-end">&#8369;<?= number_format($todayClosing['expected_cash'], 2) ?></td></tr>
                        <tr><td>Actual Cash Counted</td><td class="text-end">&#8369;<?= number_format($todayClosing['counted_cash'], 2) ?></td></tr>
                        <tr class="fw-bold">
                            <td>Cash Over</td>
                            <td class="text-end"><?= $todayClosing['variance'] > 0 ? '&#8369;' . number_format($todayClosing['variance'], 2) : '&#8369;0.00' ?></td>
                        </tr>
                        <tr class="fw-bold">
                            <td>Cash Short</td>
                            <td class="text-end"><?= $todayClosing['variance'] < 0 ? '&#8369;' . number_format(abs($todayClosing['variance']), 2) : '&#8369;0.00' ?></td>
                        </tr>
                    </table>
                    <div class="d-flex gap-2">
                        <a href="print_zreading?id=<?= $todayClosing['id'] ?>" target="_blank" class="btn btn-outline-primary flex-grow-1">
                            <i class="bi bi-printer"></i> Print Z-Reading
                        </a>
                        <?php if ($isAdmin): ?>
                        <a href="undo_shift_closing?id=<?= $todayClosing['id'] ?>" class="btn btn-outline-danger flex-grow-1"
                           onclick="confirmUndoShift(event)">
                            <i class="bi bi-arrow-counterclockwise"></i> Undo Closing
                        </a>
                        <?php endif; ?>
                    </div>

                <?php else: ?>

                <p class="text-muted small mb-3">
                    Covering <?= date('M d, Y h:i A', strtotime($periodStart)) ?> to <?= date('M d, Y h:i A', strtotime($periodEnd)) ?>
                </p>

                <form method="POST" id="closeShiftForm" onsubmit="confirmCloseShift(event)">
                    <table class="table table-sm mb-0">
                        <tr>
                            <td>Beginning Cash Float</td>
                            <td class="text-end" style="width:140px;">
                                <input type="number" step="0.01" name="starting_cash" id="startingCash" class="form-control form-control-sm text-end" value="0" required>
                            </td>
                        </tr>
                        <tr>
                            <td>Remit (kay Sir)</td>
                            <td class="text-end">
                                <input type="number" step="0.01" name="remit_amount" id="remitAmount" class="form-control form-control-sm text-end" value="0">
                            </td>
                        </tr>
                        <tr><td>Cash Sales</td><td class="text-end">&#8369;<?= number_format($cashSales, 2) ?></td></tr>
                        <tr><td>GCash Sales</td><td class="text-end">&#8369;<?= number_format($gcashSales, 2) ?></td></tr>
                        <tr><td>GoTyme Sales</td><td class="text-end">&#8369;<?= number_format($gotymeSales, 2) ?></td></tr>
                        <tr><td>Card Sales</td><td class="text-end">&#8369;<?= number_format($cardSales, 2) ?></td></tr>
                        <tr><td>Grab Sales</td><td class="text-end">&#8369;<?= number_format($grabSales, 2) ?></td></tr>
                        <tr><td>FoodPanda Sales</td><td class="text-end">&#8369;<?= number_format($foodpandaSales, 2) ?></td></tr>
                        <tr><td>Wrapnatics Account Sales</td><td class="text-end">&#8369;<?= number_format($wrapnaticsAccountSales, 2) ?></td></tr>
                        <tr><td>Total Discounts Given</td><td class="text-end text-danger">-&#8369;<?= number_format($totalDiscount, 2) ?></td></tr>
                        <tr><td>Total Expenses</td><td class="text-end text-danger">-&#8369;<?= number_format($totalExpenses, 2) ?></td></tr>
                        <tr class="fw-bold"><td>Total Sales</td><td class="text-end">&#8369;<?= number_format($totalSales, 2) ?></td></tr>
                    </table>

                    <?php if ($periodExpenses): ?>
                    <div class="small text-muted mb-3">
                        <?php foreach ($periodExpenses as$exp): ?>
                            <div>&bull; <?= htmlspecialchars($exp['description']) ?> - &#8369;<?= number_format($exp['amount'], 2) ?></div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <hr>

                    <div class="mb-2 d-flex justify-content-between">
                        <span class="text-muted">Total Cash Expected</span>
                        <strong id="expectedCash">&#8369;<?= number_format($cashSales -$totalExpenses, 2) ?></strong>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Actual Cash Counted</label>
                        <input type="number" step="0.01" name="counted_cash" id="countedCash" class="form-control" required>
                    </div>
                    <div class="mb-2 d-flex justify-content-between">
                        <span class="text-muted">Cash Over</span>
                        <strong id="cashOverDisplay">&#8369;0.00</strong>
                    </div>
                    <div class="mb-3 d-flex justify-content-between">
                        <span class="text-muted">Cash Short</span>
                        <strong id="cashShortDisplay">&#8369;0.00</strong>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notes <span class="text-muted">(optional)</span></label>
                        <textarea name="notes" class="form-control" rows="2"></textarea>
                    </div>
                    <button type="submit" name="close_shift" class="btn btn-primary w-100">Close Shift</button>
                </form>

                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold"><?= $isAdmin ? 'All Shift Closings' : 'My Shift Closing History' ?></div>
            <div class="card-body">
                <form class="d-flex gap-2 mb-3 flex-wrap align-items-end" method="GET">
                    <div>
                        <label class="form-label small mb-1">From</label>
                        <input type="date" name="from" class="form-control" value="<?= htmlspecialchars($histFrom) ?>">
                    </div>
                    <div>
                        <label class="form-label small mb-1">To</label>
                        <input type="date" name="to" class="form-control" value="<?= htmlspecialchars($histTo) ?>">
                    </div>
                    <button class="btn btn-primary">Filter</button>
                </form>

                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <?php if ($isAdmin): ?><th>Cashier</th><?php endif; ?>
                                <th>Date</th>
                                <th>Sales</th>
                                <th>Expenses</th>
                                <th>Expected</th>
                                <th>Counted</th>
                                <th>Variance</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$closings): ?>
                                <tr><td colspan="<?= $isAdmin ? 8 : 7 ?>" class="text-center text-muted py-4">No shift closings in this range.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($closings as$c): ?>
                            <tr>
                                <?php if ($isAdmin): ?><td><?= htmlspecialchars($c['full_name']) ?></td><?php endif; ?>
                                <td><?= date('M d, Y h:i A', strtotime($c['created_at'])) ?></td>
                                <td>&#8369;<?= number_format($c['total_sales'], 2) ?></td>
                                <td>&#8369;<?= number_format($c['total_expenses'], 2) ?></td>
                                <td>&#8369;<?= number_format($c['expected_cash'], 2) ?></td>
                                <td>&#8369;<?= number_format($c['counted_cash'], 2) ?></td>
                                <td>
                                    <?php if (abs($c['variance']) < 0.01): ?>
                                        <span class="badge bg-success">Balanced</span>
                                    <?php elseif ($c['variance'] > 0): ?>
                                        <span class="badge bg-info text-dark">+&#8369;<?= number_format($c['variance'], 2) ?> over</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">-&#8369;<?= number_format(abs($c['variance']), 2) ?> short</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="print_zreading?id=<?= $c['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="Print"><i class="bi bi-printer"></i></a>
                                    <?php if ($isAdmin): ?>
                                    <a href="undo_shift_closing?id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-danger" title="Undo"
                                       onclick="confirmUndoShift(event, '<?= htmlspecialchars($c['full_name'], ENT_QUOTES) ?>')">
                                        <i class="bi bi-arrow-counterclockwise"></i>
                                    </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- SweetAlert2 JS -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
const cashSales = <?= json_encode((float)$cashSales) ?>;
const totalExpenses = <?= json_encode((float)$totalExpenses) ?>;

function updateVariance() {
    const starting = parseFloat(document.getElementById('startingCash').value) || 0;
    const remit = parseFloat(document.getElementById('remitAmount').value) || 0;
    const counted = parseFloat(document.getElementById('countedCash').value) || 0;
    const expected = starting + cashSales - remit - totalExpenses;
    const variance = counted - expected;

    document.getElementById('expectedCash').innerText = '\u20B1' + expected.toFixed(2);
    document.getElementById('cashOverDisplay').innerText = '\u20B1' + (variance > 0 ? variance.toFixed(2) : '0.00');
    document.getElementById('cashShortDisplay').innerText = '\u20B1' + (variance < 0 ? Math.abs(variance).toFixed(2) : '0.00');
}

const startingCashInput = document.getElementById('startingCash');
const remitAmountInput = document.getElementById('remitAmount');
const countedCashInput = document.getElementById('countedCash');
if (startingCashInput && countedCashInput) {
    startingCashInput.addEventListener('input', updateVariance);
    remitAmountInput.addEventListener('input', updateVariance);
    countedCashInput.addEventListener('input', updateVariance);
}

// Custom SweetAlert2 for Closing Shift
function confirmCloseShift(event) {
    event.preventDefault();
    const form = event.target;

    Swal.fire({
        title: 'Close Shift?',
        text: 'Are you sure you want to close this shift? This action cannot be undone.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#0d6efd',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, close shift'
    }).then((result) => {
        if (result.isConfirmed) {
            const hiddenSubmit = document.createElement('input');
            hiddenSubmit.type = 'hidden';
            hiddenSubmit.name = 'close_shift';
            hiddenSubmit.value = '1';
            form.appendChild(hiddenSubmit);
            form.submit();
        }
    });
}

// Custom SweetAlert2 for Undoing Shift Closing
function confirmUndoShift(event, cashierName = '') {
    event.preventDefault();
    const targetUrl = event.currentTarget.getAttribute('href');
    const message = cashierName ? `Undo this shift closing for ${cashierName}?` : 'Undo this shift closing? The cashier will be able to close again.';

    Swal.fire({
        title: 'Undo Shift Closing?',
        text: message,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, undo it'
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = targetUrl;
        }
    });
}
</script>

<?php require 'includes/footer.php'; ?>