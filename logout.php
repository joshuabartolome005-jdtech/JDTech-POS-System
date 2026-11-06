<?php
require 'config/database.php';
session_start();

if (isset($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("UPDATE users SET current_session_token = NULL WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
}

session_unset();
session_destroy();
header('Location: login');
exit;