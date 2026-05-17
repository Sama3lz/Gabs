<?php
session_start();
include("gabsdb.php"); // Include DB connection (might be needed for future dashboard elements)
require_once __DIR__ . "/includes/auth_helpers.php";

// --- 1. Embedded Access Control (from security_utils.php logic) ---
require_login();
// Roles check isn't strictly needed here as all logged-in users can see welcome.
$user_id   = $_SESSION['user_ID'];
$user_name = $_SESSION['name'] ?? 'User'; // Provide default
$user_role = $_SESSION['role'] ?? null; // Get role

// --- 2. Embedded Session Message Handling (from security_utils.php logic) ---
$msg = "";
$error = "";
if (isset($_SESSION['message'])) {
    // Differentiate based on content/class presence
    if (strpos($_SESSION['message'], 'error') !== false || strpos($_SESSION['message'], '❌') !== false) {
        $error = $_SESSION['message'];
    } else {
        $msg = $_SESSION['message']; // Assume success if not error
    }
    unset($_SESSION['message']); // Clear message
}

// --- 3. Page Variables ---
$page_title = "Dashboard - Gab's Bakeshop";
$current_page = basename($_SERVER['PHP_SELF']); // For navbar active state

$nav = get_nav_permissions($user_role);
$show_inventory_link = $nav['show_inventory_link'];
$show_reports_link = $nav['show_reports_link'];
$show_accounts_link = $nav['show_accounts_link'];
$show_orders_link = $nav['show_orders_link'];
$show_settings_link = $nav['show_settings_link'];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?></title>
    <link rel="stylesheet" href="welcome.css">
</head>
<body>
    <!-- ****** EMBEDDED Navbar HTML (Copied from orders.php) ****** -->
    <?php include __DIR__ . '/includes/navbar.php'; ?>

<div class="page-content">
        <div class="dashboard-content">
            <h1>Welcome to Gab's Bakeshop System</h1>
            <p class="user-info">
                Logged in as: <strong><?= htmlspecialchars($user_name); ?></strong>
                (Role: <?= htmlspecialchars($user_role ?? 'Unknown') ?>)
            </p>

            <?php
                // Display messages if any
                 if (!empty($msg) && strpos($msg, '<div') === false) { echo "<div class='message success'>" . htmlspecialchars($msg) . "</div>"; } elseif (!empty($msg)) { echo $msg; }
                 if (!empty($error) && strpos($error, '<div') === false) { echo "<div class='message error'>" . htmlspecialchars($error) . "</div>"; } elseif (!empty($error)) { echo $error; }
            ?>

            <div class="welcome-card">
                <h2>System Overview</h2>
                <p>Welcome! Use the navigation bar above to access different sections based on your role.</p>
                <hr>
                <p style="font-style: italic; color: #555;">
                <?php
                if ($user_role === 'Admin') { echo "As an <b>Admin</b>, you have full access to manage inventory, approve/deny orders, generate reports, handle user accounts, and configure system settings."; }
                elseif ($user_role === 'Moderator') { echo "As a <b>Moderator</b>, you can manage inventory, approve or deny pending orders, and view various system reports."; }
                elseif ($user_role === 'Branch') { echo "As a <b>Branch</b> user, you can view product inventory, place new orders for your branch, and view sales reports specific to your location."; }
                elseif ($user_role === 'Delivery') { echo "As a <b>Delivery</b> user, you can view approved orders assigned to you and mark them as delivered after uploading proof."; }
                else { echo "Navigate using the links above to begin managing the system based on your assigned permissions."; }
                ?>
                </p>
            </div>
        </div>
    </div>

    <!-- ****** EMBEDDED JavaScript for Hamburger Menu ****** -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const hamburgerButton = document.getElementById('hamburger-button');
            const navLinks = document.getElementById('main-nav-links');

            if (hamburgerButton && navLinks) {
                hamburgerButton.addEventListener('click', function() {
                    navLinks.classList.toggle('show-menu');
                });
            }
        });
    </script>

</body>
</html>

