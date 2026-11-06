<?php
$pageTitle = 'Audit Log';
require 'config/database.php';
require 'config/auth.php';
requireAdmin();

$dateFrom = $_GET['from'] ?? date('Y-m-01');
$dateTo = $_GET['to'] ?? date('Y-m-d');
$filterUser = (int)($_GET['user_id'] ?? 0);
$filterAction = trim($_GET['action'] ?? '');

$sql = "SELECT al.*, u.full_name FROM audit_log al LEFT JOIN users u ON al.user_id = u.id WHERE DATE(al.created_at) BETWEEN ? AND ?";
$params = [$dateFrom, $dateTo];

if ($filterUser > 0) {
    $sql .= " AND al.user_id = ?";
    $params[] = $filterUser;
}
if ($filterAction !== '') {
    $sql .= " AND al.action = ?";
    $params[] = $filterAction;
}
$sql .= " ORDER BY al.created_at DESC LIMIT 500";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

$employees = $pdo->query("SELECT id, full_name FROM users ORDER BY full_name")->fetchAll();
$actionTypes = $pdo->query("SELECT DISTINCT action FROM audit_log ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);

$pageTitle = 'Audit Log';
require 'includes/header.php';
?>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold">System Audit Log</div>
    <div class="card-body">
        <form class="d-flex gap-2 mb-3 flex-wrap align-items-end" method="GET">
            <div>
                <label class="form-label small mb-1">User</label>
                <select name="user_id" class="form-select">
                    <option value="0">All Users</option>
                    <?php foreach ($employees as $e): ?>
                        <option value="<?= $e['id'] ?>" <?= $filterUser == $e['id'] ? 'selected' : '' ?>><?= htmlspecialchars($e['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label small mb-1">Action Type</label>
                <select name="action" class="form-select">
                    <option value="">All Actions</option>
                    <?php foreach ($actionTypes as $a): ?>
                        <option value="<?= htmlspecialchars($a) ?>" <?= $filterAction === $a ? 'selected' : '' ?>><?= htmlspecialchars($a) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label small mb-1">From</label>
                <input type="date" name="from" class="form-control" value="<?= htmlspecialchars($dateFrom) ?>">
            </div>
            <div>
                <label class="form-label small mb-1">To</label>
                <input type="date" name="to" class="form-control" value="<?= htmlspecialchars($dateTo) ?>">
            </div>
            <button class="btn btn-primary">Filter</button>
        </form>

        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Date/Time</th>
                        <th>User</th>
                        <th>Action</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$logs): ?>
                        <tr><td colspan="4" class="text-center text-muted py-4">No audit records in this range.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($logs as $log): ?>
                    <tr>
                        <td><?= date('M d, Y h:i A', strtotime($log['created_at'])) ?></td>
                        <td><?= htmlspecialchars($log['full_name'] ?? 'System') ?></td>
                        <td><span class="badge bg-secondary"><?= htmlspecialchars($log['action']) ?></span></td>
                        <td><?= htmlspecialchars($log['details']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="text-muted small mt-2 mb-0">Showing the most recent 500 matching entries.</p>
    </div>
</div>

<?php require 'includes/footer.php'; ?>
