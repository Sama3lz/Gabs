<?php
$nav_current_page = $current_page ?? basename($_SERVER['PHP_SELF']);
$inv_active = in_array($nav_current_page, ['inventory.php', 'productinventory.php'], true) ? 'active' : '';
$orders_active = ($nav_current_page === 'orders.php') ? 'active' : '';
$is_report_section = in_array($nav_current_page, ['reports.php', 'ingredienthistory.php', 'producthistory.php', 'receipt.php'], true);
$reports_active = $is_report_section ? 'active' : '';
$accounts_active = ($nav_current_page === 'accounts.php') ? 'active' : '';
$settings_active = ($nav_current_page === 'settings.php') ? 'active' : '';
?>
<div class="header-bar">
    <div class="logo-container">
        <h1>Gab <span style="color: #c0392b;">Shawn</span></h1>
    </div>
    <nav class="navbar">
        <div class="main-nav" id="main-nav-links">
            <a href="welcome.php" class="<?= ($nav_current_page === 'welcome.php') ? 'active' : '' ?>">Dashboard</a>
            <a href="inventory.php" class="<?= $inv_active ?>">Inventory</a>
            <a href="orders.php" class="<?= $orders_active ?>">Orders</a>
            <a href="reports.php" class="<?= $reports_active ?>">Reports</a>
            <a href="accounts.php" class="<?= $accounts_active ?>">Accounts</a>
            <a href="settings.php" class="<?= $settings_active ?>">Settings</a>
            <a href="logout.php" class="logout-link">Logout</a>
        </div>
        <button class="hamburger" id="hamburger-button" aria-label="Toggle navigation menu">&#9776;</button>
    </nav>
</div>
