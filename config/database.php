<?php
// ============================================
// Database Configuration
// ============================================

// Siguraduhing Philippine Time (GMT+8) ang gagamitin sa buong system
date_default_timezone_set('Asia/Manila');

$host = 'localhost';
$dbname = 'u892683572_db_HCmiyhs1';
$username = 'u892683572_usr_HCmiyhs1';
$password = '9>Rt^MOz0';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    // I-sync din ang MySQL session time sa Philippine Time (+08:00)
    $pdo->exec("SET time_zone = '+08:00';");

} catch (PDOException $e) {
    // SECURITY: Huwag ipakita ang totoong $e->getMessage() sa live web page para hindi ma-expose ang credentials/paths
    error_log("Database Connection Error: " . $e->getMessage());
    die("Database connection failed. Please try again later or contact the system administrator.");
}