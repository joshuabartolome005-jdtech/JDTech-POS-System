<?php
require_once 'config/database.php';
require_once 'config/auth.php';
require_once 'includes/audit.php';
requireAdmin();

$pageTitle = 'Holidays';

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_holiday'])) {
    $date =$_POST['holiday_date'] ?? '';
    $name = trim($_POST['name'] ?? '');
    $type = ($_POST['type'] ?? '') === 'special' ? 'special' : 'regular';

    if ($date &&$name) {
        $stmt =$pdo->prepare("INSERT INTO holidays (holiday_date, name, type) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE name = VALUES(name), type = VALUES(type)");
        $stmt->execute([$date, $name,$type]);
        log_audit($pdo,$_SESSION['user_id'], 'Holiday Saved', "$name ($date,$type)");
        $message = 'Holiday saved successfully.';
    }
}

if (isset($_GET['delete'])) {
    $stmt =$pdo->prepare("DELETE FROM holidays WHERE id = ?");
    $stmt->execute([(int)$_GET['delete']]);
    header('Location: holidays');
    exit;
}

require 'includes/header.php';

$holidays =$pdo->query("SELECT * FROM holidays ORDER BY holiday_date DESC")->fetchAll();
?>

<!-- SweetAlert2 CSS -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">

<div class="row g-3">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Add / Update Holiday</div>
            <div class="card-body">
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label">Date</label>
                        <input type="date" name="holiday_date" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Holiday Name</label>
                        <input type="text" name="name" class="form-control" placeholder="e.g. Araw ng Kagitingan" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Type</label>
                        <select name="type" class="form-select">
                            <option value="regular">Regular Holiday</option>
                            <option value="special">Special Non-Working Day</option>
                        </select>
                    </div>
                    <button type="submit" name="add_holiday" class="btn btn-primary w-100">Save</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-md-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Holiday List</div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light"><tr><th>Date</th><th>Name</th><th>Type</th><th class="text-end">Action</th></tr></thead>
                    <tbody>
                        <?php if (!$holidays): ?><tr><td colspan="4" class="text-center text-muted py-4">No holidays added yet.</td></tr><?php endif; ?>
                        <?php foreach ($holidays as$h): ?>
                        <tr>
                            <td><?= date('M d, Y (D)', strtotime($h['holiday_date'])) ?></td>
                            <td><?= htmlspecialchars($h['name']) ?></td>
                            <td><span class="badge <?= $h['type'] === 'regular' ? 'bg-primary' : 'bg-info text-dark' ?>"><?= $h['type'] === 'regular' ? 'Regular' : 'Special' ?></span></td>
                            <td class="text-end">
                                <a href="holidays?delete=<?= $h['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="confirmDelete(event, '<?= htmlspecialchars($h['name'], ENT_QUOTES) ?>')">
                                    <i class="bi bi-trash"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- SweetAlert2 JS -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
// Trigger success notification upon saving
<?php if ($message): ?>
Swal.fire({
    title: 'Saved!',
    text: <?= json_encode($message) ?>,
    icon: 'success',
    confirmButtonColor: '#0d6efd'
});
<?php endif; ?>

// Custom SweetAlert2 for Delete Confirmation
function confirmDelete(event, holidayName) {
    event.preventDefault();
    const targetUrl = event.currentTarget.getAttribute('href');

    Swal.fire({
        title: 'Delete Holiday?',
        text: `Are you sure you want to delete "${holidayName}"? This action cannot be undone.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, delete it'
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = targetUrl;
        }
    });
}
</script>

<?php require 'includes/footer.php'; ?>