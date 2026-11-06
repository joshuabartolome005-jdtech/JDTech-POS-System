<?php
require 'config/database.php';
require 'config/auth.php';
require 'includes/audit.php';
requireAdmin();

$id = (int)($_GET['id'] ?? 0);

if ($id > 0) {
    $stmt = $pdo->prepare("SELECT sc.*, u.full_name FROM shift_closings sc JOIN users u ON sc.user_id = u.id WHERE sc.id = ?");
    $stmt->execute([$id]);
    $closing = $stmt->fetch();

    if ($closing) {
        $stmt = $pdo->prepare("DELETE FROM shift_closings WHERE id = ?");
        $stmt->execute([$id]);

        log_audit(
            $pdo,
            $_SESSION['user_id'],
            'Shift Closing Undone',
            'Undid shift closing #' . $id . ' for ' . $closing['full_name'] .
                ' (was: Sales ' . number_format($closing['total_sales'], 2) .
                ', Variance ' . number_format($closing['variance'], 2) . ')'
        );
    }
}

header('Location: shift_closing?undone=1');
exit;