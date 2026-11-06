<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/settings.php';
requireLogin();
$user = currentUser();
$page = $page ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= isset($pageTitle) ? htmlspecialchars($pageTitle) . ' - ' : '' ?><?= htmlspecialchars(BUSINESS_NAME) ?></title>
    <link rel="icon" type="image/png" href="<?= htmlspecialchars(DEV_COMPANY_LOGO) ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/vendor/bootstrap-icons/bootstrap-icons.css">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
<div class="d-flex">
    <?php include __DIR__ . '/sidebar.php'; ?>

    <div class="main-content flex-grow-1">
        <nav class="topbar d-flex align-items-center justify-content-between px-4 no-print">
            <button class="btn btn-sm btn-outline-secondary d-lg-none" id="sidebarToggle"><i class="bi bi-list"></i></button>
            <h5 class="mb-0 fw-semibold"><?= htmlspecialchars($pageTitle ?? '') ?></h5>
            <div class="dropdown">
                <button class="btn btn-light dropdown-toggle d-flex align-items-center gap-2" data-bs-toggle="dropdown">
                    <i class="bi bi-person-circle fs-5"></i>
                    <span><?= htmlspecialchars($user['name'] ?? 'User') ?></span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><span class="dropdown-item-text text-muted small text-capitalize"><i class="bi bi-shield-lock me-1"></i> Role: <?= htmlspecialchars($user['role'] ?? 'cashier') ?></span></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="logout"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
                </ul>
            </div>
        </nav>
        <div class="content-area p-4">