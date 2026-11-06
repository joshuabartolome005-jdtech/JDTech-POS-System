<?php
$pageTitle = 'Users';
require 'config/database.php';
require 'config/auth.php';
require 'includes/audit.php';
requireAdmin();

$message = $_SESSION['flash_message'] ?? '';
$error = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_message'], $_SESSION['flash_error']);

// 1. ADD USER
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $fullName = trim($_POST['full_name']);
    $role = $_POST['role'];

    if ($username === '' || $password === '' || $fullName === '') {
        $_SESSION['flash_error'] = 'Please fill in all fields.';
    } else {
        $check = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $check->execute([$username]);
        if ($check->fetch()) {
            $_SESSION['flash_error'] = 'This username is already taken.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (username, password, full_name, role) VALUES (?, ?, ?, ?)");
            $stmt->execute([$username, $hash, $fullName, $role]);
            log_audit($pdo, $_SESSION['user_id'], 'User Added', "Added user \"$fullName\" ($username) with role: $role");
            $_SESSION['flash_message'] = 'User added successfully.';
        }
    }
    header('Location: users');
    exit;
}

// 2. RESET PASSWORD
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password'])) {
    $resetId = (int)($_POST['reset_user_id'] ?? 0);
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($resetId <= 0) {
        $_SESSION['flash_error'] = 'Invalid user selected.';
    } elseif (strlen($newPassword) < 6) {
        $_SESSION['flash_error'] = 'New password must be at least 6 characters.';
    } elseif ($newPassword !== $confirmPassword) {
        $_SESSION['flash_error'] = 'Passwords do not match.';
    } else {
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
        $updated = $stmt->execute([$hash, $resetId]);

        if ($updated) {
            $targetName = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
            $targetName->execute([$resetId]);
            $fetchedName = $targetName->fetchColumn();

            log_audit($pdo, $_SESSION['user_id'], 'Password Reset', 'Reset password for "' . $fetchedName . '"');
            $_SESSION['flash_message'] = 'Password reset successfully.';
        } else {
            $_SESSION['flash_error'] = 'Failed to reset password in database.';
        }
    }
    header('Location: users');
    exit;
}

// 3. UPDATE PAY RATE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_pay_rate'])) {
    $payUserId = (int)($_POST['pay_user_id'] ?? 0);
    $dailyRate = (float)($_POST['daily_rate'] ?? 0);
    $colaRate = (float)($_POST['cola_rate'] ?? 0);
    $monthlyReward = (float)($_POST['monthly_reward'] ?? 0);
    $restDay = ($_POST['rest_day'] === '' || $_POST['rest_day'] === null) ? null : (int)$_POST['rest_day'];

    if ($payUserId <= 0 || $dailyRate <= 0) {
        $_SESSION['flash_error'] = 'Please select a valid employee and daily rate.';
    } else {
        $stmt = $pdo->prepare("UPDATE users SET daily_rate = ?, cola_rate = ?, monthly_reward = ?, rest_day = ? WHERE id = ?");
        
        // Explicit binding para sa null value sa rest_day
        $stmt->bindValue(1, $dailyRate);
        $stmt->bindValue(2, $colaRate);
        $stmt->bindValue(3, $monthlyReward);
        if ($restDay === null) {
            $stmt->bindValue(4, null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(4, $restDay, PDO::PARAM_INT);
        }
        $stmt->bindValue(5, $payUserId, PDO::PARAM_INT);
        $stmt->execute();

        $targetName = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
        $targetName->execute([$payUserId]);
        log_audit($pdo, $_SESSION['user_id'], 'Pay Rate Updated', 'Updated pay rate for "' . $targetName->fetchColumn() . '" - Daily: ' . number_format($dailyRate, 2));

        $_SESSION['flash_message'] = 'Pay rate updated successfully.';
    }
    header('Location: users');
    exit;
}

// 4. DELETE USER
if (isset($_GET['delete'])) {
    $delId = (int)$_GET['delete'];
    if ($delId !== (int)$_SESSION['user_id']) {
        $targetName = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
        $targetName->execute([$delId]);
        $name = $targetName->fetchColumn();

        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$delId]);

        log_audit($pdo, $_SESSION['user_id'], 'User Deleted', 'Deleted user "' . $name . '"');
        $_SESSION['flash_message'] = 'User deleted successfully.';
    }
    header('Location: users');
    exit;
}

$users = $pdo->query("SELECT * FROM users ORDER BY id")->fetchAll();

require 'includes/header.php';

$dayNames = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
?>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<div class="row g-3">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Add User</div>
            <div class="card-body">
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label">Full Name</label>
                        <input type="text" name="full_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Username</label>
                        <input type="text" name="username" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Password</label>
                        <input type="password" name="password" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Role</label>
                        <select name="role" class="form-select">
                            <option value="cashier">Cashier</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                    <button type="submit" name="add_user" class="btn btn-primary w-100">Add</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">User List</div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light">
                        <tr><th>Name</th><th>Username</th><th>Role</th><th>Daily Rate</th><th class="text-end">Action</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u): ?>
                        <tr>
                            <td><?= htmlspecialchars($u['full_name']) ?></td>
                            <td><?= htmlspecialchars($u['username']) ?></td>
                            <td><span class="badge bg-info text-dark text-capitalize"><?= htmlspecialchars($u['role']) ?></span></td>
                            <td>&#8369;<?= number_format($u['daily_rate'] ?? 0, 2) ?></td>
                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-outline-success"
                                    onclick='openPayRateModal(<?= json_encode($u) ?>)'
                                    data-bs-toggle="modal" data-bs-target="#payRateModal" title="Edit Pay Rate">
                                    <i class="bi bi-cash-coin"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary"
                                    onclick="openResetModal(<?= $u['id'] ?>, <?= htmlspecialchars(json_encode($u['full_name']), ENT_QUOTES, 'UTF-8') ?>)"
                                    title="Reset Password">
                                    <i class="bi bi-key"></i>
                                </button>
                                <?php if ($u['id'] != $_SESSION['user_id']): ?>
                                <button type="button" class="btn btn-sm btn-outline-danger" onclick="confirmDelete(<?= $u['id'] ?>)" title="Delete User">
                                    <i class="bi bi-trash"></i>
                                </button>
                                <?php else: ?>
                                <span class="text-muted small">This is you</span>
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

<!-- Reset Password Modal -->
<div class="modal fade" id="resetPasswordModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Reset Password <span id="reset_user_name" class="text-muted"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="reset_user_id" id="reset_user_id">
                    <div class="mb-3">
                        <label class="form-label">New Password</label>
                        <input type="password" name="new_password" class="form-control" minlength="6" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Confirm New Password</label>
                        <input type="password" name="confirm_password" class="form-control" minlength="6" required>
                    </div>
                    <p class="text-muted small mb-0">Minimum 6 characters.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="reset_password" class="btn btn-primary">Reset Password</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Pay Rate Modal -->
<div class="modal fade" id="payRateModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Pay Rate <span id="pay_user_name" class="text-muted"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="pay_user_id" id="pay_user_id">
                    <div class="mb-3">
                        <label class="form-label">Daily Rate (&#8369;)</label>
                        <input type="number" step="0.01" name="daily_rate" id="pay_daily_rate" class="form-control" required>
                        <div class="form-text">Hourly rate = Daily Rate &divide; 8</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">COLA (&#8369; per day)</label>
                        <input type="number" step="0.01" name="cola_rate" id="pay_cola_rate" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Monthly Uniform/Gear Reward (&#8369;)</label>
                        <input type="number" step="0.01" name="monthly_reward" id="pay_monthly_reward" class="form-control">
                        <div class="form-text">Split in half automatically for each semi-monthly payroll.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Rest Day</label>
                        <select name="rest_day" id="pay_rest_day" class="form-select">
                            <option value="">Not set</option>
                            <?php foreach ($dayNames as $idx => $dn): ?>
                                <option value="<?= $idx ?>"><?= $dn ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_pay_rate" class="btn btn-primary">Save Pay Rate</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openResetModal(id, name) {
    document.getElementById('reset_user_id').value = id;
    document.getElementById('reset_user_name').innerText = '- ' + name;

    // Trigger modal safely gamit ang Bootstrap JS API para siguradong may value na ang reset_user_id bago mag-show ang modal
    const modalElement = document.getElementById('resetPasswordModal');
    const modalInstance = bootstrap.Modal.getOrCreateInstance(modalElement);
    modalInstance.show();
}

function openPayRateModal(u) {
    document.getElementById('pay_user_id').value = u.id;
    document.getElementById('pay_user_name').innerText = '- ' + u.full_name;
    document.getElementById('pay_daily_rate').value = u.daily_rate || 0;
    document.getElementById('pay_cola_rate').value = u.cola_rate || 0;
    document.getElementById('pay_monthly_reward').value = u.monthly_reward || 0;
    document.getElementById('pay_rest_day').value = u.rest_day === null ? '' : u.rest_day;
}

function confirmDelete(userId) {
    Swal.fire({
        title: 'Delete user?',
        text: "This action cannot be undone.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, delete it!'
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = 'users?delete=' + userId;
        }
    });
}

<?php if ($message): ?>
Swal.fire({
    icon: 'success',
    title: 'Success',
    text: <?= json_encode($message) ?>,
    timer: 2500,
    showConfirmButton: false
});
<?php endif; ?>

<?php if ($error): ?>
Swal.fire({
    icon: 'error',
    title: 'Error',
    text: <?= json_encode($error) ?>
});
<?php endif; ?>
</script>

<?php require 'includes/footer.php'; ?>