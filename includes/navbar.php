<?php
$nav_current_page = $current_page ?? basename($_SERVER['PHP_SELF']);
$nav_user_role = $user_role ?? ($_SESSION['role'] ?? '');

$inv_link = ($nav_user_role === 'Branch') ? 'productinventory.php' : 'inventory.php';
$inv_active = in_array($nav_current_page, ['inventory.php', 'productinventory.php'], true) ? 'active' : '';
$orders_active_class = ($nav_current_page === 'orders.php') ? 'active' : '';
$is_report_section = in_array($nav_current_page, ['reports.php', 'ingredienthistory.php', 'producthistory.php', 'receipt.php'], true);
$reports_active_class = $is_report_section ? 'active' : '';
$accounts_active_class = ($nav_current_page === 'accounts.php') ? 'active' : '';
$settings_active_class = ($nav_current_page === 'settings.php') ? 'active' : '';
?>
<div class="header-bar">
    <div class="logo-container">
        <h1>Gab <span style="color: #c0392b;">Shawn</span></h1>
    </div>
    <nav class="navbar">
        <div class="main-nav" id="main-nav-links">
            <a href="welcome.php" class="<?= ($nav_current_page === 'welcome.php') ? 'active' : '' ?>">Dashboard</a>

            <?php if (!empty($show_inventory_link)): ?>
                <a href="<?= $inv_link ?>" class="<?= $inv_active ?>">Inventory</a>
            <?php endif; ?>

            <?php if (!empty($show_orders_link)): ?>
                <a href="orders.php" class="<?= $orders_active_class ?>">Orders</a>
            <?php endif; ?>

            <?php if (!empty($show_reports_link)): ?>
                <a href="reports.php" class="<?= $reports_active_class ?>">Reports</a>
            <?php endif; ?>

            <?php if (!empty($show_accounts_link)): ?>
                <a href="accounts.php" class="<?= $accounts_active_class ?>">Accounts</a>
            <?php endif; ?>

            <?php if (!empty($show_settings_link)): ?>
                <a href="settings.php" class="<?= $settings_active_class ?>">Settings</a>
            <?php endif; ?>

            <a href="logout.php" class="logout-link">Logout</a>
        </div>
        <button class="hamburger" id="hamburger-button" aria-label="Toggle navigation menu">&#9776;</button>
    </nav>
</div>
