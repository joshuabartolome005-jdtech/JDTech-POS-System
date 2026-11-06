<?php $current = basename($_SERVER['PHP_SELF']); ?>
<div class="sidebar no-print" id="sidebar">
    <div class="sidebar-brand">
        <img src="<?= htmlspecialchars(BUSINESS_LOGO) ?>" alt="<?= htmlspecialchars(BUSINESS_NAME) ?>" class="sidebar-logo">
    </div>
    <ul class="nav flex-column sidebar-nav">
        <li class="nav-item">
            <a class="nav-link <?= $current === 'dashboard.php' ? 'active' : '' ?>" href="dashboard">
                <i class="bi bi-speedometer2"></i> Dashboard
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $current === 'pos.php' ? 'active' : '' ?>" href="pos">
                <i class="bi bi-cash-coin"></i> POS (Cashier)
            </a>
        </li>
        <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
        <li class="nav-item">
            <a class="nav-link <?= $current === 'products.php' ? 'active' : '' ?>" href="products">
                <i class="bi bi-box-seam"></i> Products
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $current === 'categories.php' ? 'active' : '' ?>" href="categories">
                <i class="bi bi-tags"></i> Categories
            </a>
        </li>
        <?php endif; ?>
        <li class="nav-item">
            <a class="nav-link <?= $current === 'sales_history.php' ? 'active' : '' ?>" href="sales_history">
                <i class="bi bi-receipt"></i> Sales History
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $current === 'attendance.php' ? 'active' : '' ?>" href="attendance">
                <i class="bi bi-calendar-check"></i> Attendance
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $current === 'shift_closing.php' ? 'active' : '' ?>" href="shift_closing">
                <i class="bi bi-cash-stack"></i> Shift Closing
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $current === 'expenses.php' ? 'active' : '' ?>" href="expenses">
                <i class="bi bi-receipt-cutoff"></i> Expenses
            </a>
        </li>
        <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
        <li class="nav-item">
            <a class="nav-link <?= $current === 'stock_adjustment.php' ? 'active' : '' ?>" href="stock_adjustment">
                <i class="bi bi-box-arrow-in-down"></i> Stock Adjustment
            </a>
        </li>
        <?php endif; ?>
        
        <!-- Accessible to both Admin and Cashier -->
        <li class="nav-item">
            <a class="nav-link <?= $current === 'inventory_monitoring.php' ? 'active' : '' ?>" href="inventory_monitoring">
                <i class="bi bi-clipboard-data"></i> Inventory Monitoring
            </a>
        </li>
        
        <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
        <li class="nav-item">
            <a class="nav-link <?= $current === 'sales_analytics.php' ? 'active' : '' ?>" href="sales_analytics">
                <i class="bi bi-bar-chart-line"></i> Sales Analytics
            </a>
        </li>
        <?php endif; ?>
        
        <!-- Accessible to both Admin and Cashier -->
        <li class="nav-item">
            <a class="nav-link <?= $current === 'reports.php' ? 'active' : '' ?>" href="reports">
                <i class="bi bi-graph-up"></i> Reports
            </a>
        </li>

        <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
        <li class="nav-item">
            <a class="nav-link <?= $current === 'audit_log.php' ? 'active' : '' ?>" href="audit_log">
                <i class="bi bi-shield-check"></i> Audit Log
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $current === 'payroll.php' ? 'active' : '' ?>" href="payroll">
                <i class="bi bi-wallet2"></i> Payroll
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $current === 'holidays.php' ? 'active' : '' ?>" href="holidays">
                <i class="bi bi-calendar-event"></i> Holidays
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $current === 'violations.php' ? 'active' : '' ?>" href="violations">
                <i class="bi bi-exclamation-triangle"></i> Violations
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $current === 'users.php' ? 'active' : '' ?>" href="users">
                <i class="bi bi-people"></i> Users
            </a>
        </li>
        <?php endif; ?>
    </ul>

    <div class="sidebar-dev-credit">
        <img src="<?= htmlspecialchars(DEV_COMPANY_LOGO) ?>" alt="<?= htmlspecialchars(DEV_COMPANY_NAME) ?>">
        <span>Powered by <?= htmlspecialchars(DEV_COMPANY_NAME) ?></span>
    </div>
</div>