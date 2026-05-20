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

apply_nav_permissions($user_role);

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
    <?php include_role_navbar(); ?>
    <?php include_role_view('welcome'); ?>
    <?php include_hamburger_script(); ?>

</body>
</html>

