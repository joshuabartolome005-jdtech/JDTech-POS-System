<?php
require 'config/database.php';
require 'config/auth.php';
require 'includes/audit.php';
requireLogin();

$id = (int)($_GET['id'] ?? 0);
if ($id > 0) {
    $nameStmt = $pdo->prepare("SELECT name FROM products WHERE id = ?");
    $nameStmt->execute([$id]);
    $name = $nameStmt->fetchColumn();

    $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
    $stmt->execute([$id]);

    if ($name) {
        log_audit($pdo, $_SESSION['user_id'], 'Product Deleted', 'Deleted product "' . $name . '"');
    }
}
header('Location: products');
exit;
