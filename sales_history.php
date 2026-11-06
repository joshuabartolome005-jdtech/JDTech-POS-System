<?php
$pageTitle = 'Sales History';
require 'includes/header.php';

$dateFrom = $_GET['from'] ?? date('Y-m-01');
$dateTo = $_GET['to'] ?? date('Y-m-d');
$isAdmin = ($_SESSION['role'] ?? '') === 'admin';

$stmt = $pdo->prepare("SELECT s.*, u.full_name FROM sales s LEFT JOIN users u ON s.user_id = u.id WHERE DATE(s.created_at) BETWEEN ? AND ? ORDER BY s.created_at DESC");
$stmt->execute([$dateFrom, $dateTo]);
$sales = $stmt->fetchAll();

// Only count completed (non-voided) sales toward the total
$totalAmount = 0;
foreach ($sales as $s) {
    if ($s['status'] !== 'voided') {
        $totalAmount += $s['total_amount'];
    }
}
?>

<?php if (isset($_GET['voided'])): ?>
    <div class="alert alert-success">Transaction voided successfully. Stock has been restored.</div>
<?php elseif (isset($_GET['void_error'])): ?>
    <div class="alert alert-danger">Unable to void: <?= htmlspecialchars($_GET['void_error']) ?></div>
<?php endif; ?>

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
    <a class="btn btn-outline-success" href="export_sales_csv?from=<?= urlencode($dateFrom) ?>&to=<?= urlencode($dateTo) ?>">
        <i class="bi bi-filetype-csv"></i> Export CSV
    </a>
    <a class="btn btn-outline-success" href="export_sales_xlsx?from=<?= urlencode($dateFrom) ?>&to=<?= urlencode($dateTo) ?>">
        <i class="bi bi-file-earmark-excel"></i> Export Excel
    </a>
</form>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body d-flex justify-content-between align-items-center">
        <span class="text-muted">Total sales for the selected range (excludes voided)</span>
        <h4 class="mb-0">&#8369;<?= number_format($totalAmount, 2) ?></h4>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Invoice #</th>
                    <th>Cashier</th>
                    <th>Total</th>
                    <th>Discount</th>
                    <th>Payment</th>
                    <th>Status</th>
                    <th>Date</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$sales): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No transactions in the selected date range.</td></tr>
                <?php endif; ?>
                <?php foreach ($sales as $s): ?>
                <tr class="<?= $s['status'] === 'voided' ? 'text-muted' : '' ?>">
                    <td class="<?= $s['status'] === 'voided' ? 'text-decoration-line-through' : '' ?>"><?= htmlspecialchars($s['invoice_no']) ?></td>
                    <td><?= htmlspecialchars($s['full_name'] ?? '-') ?></td>
                    <td class="<?= $s['status'] === 'voided' ? 'text-decoration-line-through' : '' ?>">&#8369;<?= number_format($s['total_amount'], 2) ?></td>
                    <td>
                        <?php if ($s['discount_type'] !== 'none'): ?>
                            <span class="badge bg-info text-dark text-capitalize"><?= htmlspecialchars($s['discount_type']) ?></span>
                        <?php else: ?>
                            <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge bg-secondary"><?= htmlspecialchars($s['payment_method']) ?></span></td>
                    <td>
                        <?php if ($s['status'] === 'voided'): ?>
                            <span class="badge bg-danger">Voided</span>
                        <?php else: ?>
                            <span class="badge bg-success">Completed</span>
                        <?php endif; ?>
                    </td>
                    <td><?= date('M d, Y h:i A', strtotime($s['created_at'])) ?></td>
                    <td class="text-end">
                        <a href="sale_view?id=<?= $s['id'] ?>" class="btn btn-sm btn-outline-primary">View</a>
                        <?php if ($isAdmin && $s['status'] !== 'voided'): ?>
                        <button type="button" class="btn btn-sm btn-outline-danger btn-void-trigger"
                            data-sale-id="<?= $s['id'] ?>" data-invoice-no="<?= htmlspecialchars($s['invoice_no']) ?>">
                            Void
                        </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($isAdmin): ?>
<!-- Void Confirmation Modal -->
<div class="modal fade" id="voidModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="void_sale" onsubmit="return validateVoidForm()">
                <input type="hidden" name="sale_id" id="void_sale_id" value="0">
                <input type="hidden" name="redirect" value="sales_history?from=<?= urlencode($dateFrom) ?>&to=<?= urlencode($dateTo) ?>">
                <div class="modal-header">
                    <h5 class="modal-title">Void Transaction <span id="void_invoice_no" class="text-muted"></span></h5>
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
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Void Transaction</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('.btn-void-trigger').forEach(function (btn) {
    btn.addEventListener('click', function () {
        const saleId = this.getAttribute('data-sale-id');
        const invoiceNo = this.getAttribute('data-invoice-no');

        document.getElementById('void_sale_id').value = saleId;
        document.getElementById('void_invoice_no').innerText = '- ' + invoiceNo;

        const modalEl = document.getElementById('voidModal');
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        modal.show();
    });
});

function validateVoidForm() {
    const id = parseInt(document.getElementById('void_sale_id').value, 10);
    if (!id || id <= 0) {
        alert('Something went wrong selecting this transaction. Please close this window and click Void again.');
        return false;
    }
    return true;
}
</script>
<?php endif; ?>

<?php require 'includes/footer.php'; ?>