<?php
session_start();
include("gabsdb.php");
require_once __DIR__ . "/includes/auth_helpers.php";

// --- 1. Embedded Access Control ---
// Access Control: ALLOW Admin, Moderator, AND Branch to view reports
require_login(['Admin', 'Moderator', 'Branch']);
$user_id = $_SESSION['user_ID']; // Needed for branch filter potentially
$user_role = $_SESSION['role'] ?? '';

// *** NEW: Redirect Branch users directly to their receipts page ***
if ($user_role === 'Branch') {
    header("Location: receipt.php"); // Lowercase and singular
    exit();
}
// *** End of new logic ***


// --- 2. Embedded Session Message Handling ---
$msg = "";
$error = "";
if (isset($_SESSION['message'])) {
    if (strpos($_SESSION['message'], 'error') !== false || strpos($_SESSION['message'], '❌') !== false) {
         if (strpos($_SESSION['message'], '<div') === false) { $error = "<div class='message error'>" . htmlspecialchars($_SESSION['message']) . "</div>"; } else { $error = $_SESSION['message']; }
    } else {
         if (strpos($_SESSION['message'], '<div') === false) { $msg = "<div class='message success'>" . htmlspecialchars($_SESSION['message']) . "</div>"; } else { $msg = $_SESSION['message']; }
    }
    unset($_SESSION['message']);
}

// --- 3. Page Variables & Navbar Logic ---
$page_title = "Sales Reports - Gab's Bakeshop";
$current_page = basename($_SERVER['PHP_SELF']);

apply_nav_permissions($user_role);

// --- 4. Date Filtering Logic (NEW) ---
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Variables for Prepared Statements
$date_filter_clause = "";
$params = [];
$types = "";

// Build the date filter and parameters
if (!empty($date_from) && !empty($date_to)) {
    $date_filter_clause = " AND o.order_date BETWEEN ? AND ?";
    $params[] = $date_from; 
    $params[] = $date_to . ' 23:59:59'; // Include the entire end day
    $types .= "ss";
} else if (!empty($date_from)) {
    $date_filter_clause = " AND o.order_date >= ?";
    $params[] = $date_from;
    $types .= "s";
} else if (!empty($date_to)) {
    $date_filter_clause = " AND o.order_date <= ?";
    $params[] = $date_to . ' 23:59:59'; // Include the entire end day
    $types .= "s";
}

// Branch filter is technically obsolete due to redirect, but kept for logic structure
$branch_filter_clause = ""; 
if ($user_role === 'Branch') {
    $branch_filter_clause = " AND a.user_ID = ? ";
    // Rebuild parameters: Branch ID first, then the dates
    array_unshift($params, $user_id);
    $types = "i" . $types;
}
// --- END Date Filtering Logic ---


// --- 1. Fetch Branch/Location Sales Subtotal Report (MODIFIED FOR COST/PROFIT) ---
$sql_branch_sales = "
    SELECT
        a.name AS branch_name,
        a.Location AS branch_location,
        SUM(o.quantity * p.price) AS branch_subtotal,
        SUM(o.quantity * p.Cost) AS branch_total_cost,
        (SUM(o.quantity * p.price) - SUM(o.quantity * p.Cost)) AS branch_total_profit
    FROM orders o
    JOIN products p ON o.product_ID = p.product_ID
    JOIN accounts a ON o.branch_ID = a.user_ID
    WHERE o.status = 'Delivered'
    {$branch_filter_clause}
    {$date_filter_clause}
    GROUP BY a.user_ID, a.name, a.Location
    ORDER BY branch_subtotal DESC";

$stmt_branch = $conn->prepare($sql_branch_sales);
if (!$stmt_branch) {
    $error .= " | ❌ DB Error preparing branch sales query: " . htmlspecialchars($conn->error);
    $branch_sales_report = false;
} else {
    // Bind parameters for Branch ID (if applicable) AND Dates
    if (!empty($params)) {
        // Use call_user_func_array as $params is already an array
        $bind_params = [$types];
        foreach ($params as $key => $value) {
            $bind_params[] = &$params[$key];
        }
        call_user_func_array([$stmt_branch, 'bind_param'], $bind_params);
    }
    
    $stmt_branch->execute();
    $branch_sales_report = $stmt_branch->get_result();
}


// --- 2. Fetch Sales Report by Product (MODIFIED FOR COST/PROFIT) ---
$sales_report = false; // Initialize
if ($user_role !== 'Branch') {
    // Parameters for product report are just the dates (since no branch filter)
    $product_params = $params;
    $product_types = $types;

    // If branch filter was active, we remove the first parameter (user_id)
    if ($user_role === 'Admin' || $user_role === 'Moderator') {
        // If the query was filtered by Branch ID, we remove it for the overall product report
        if (strpos($types, 'i') === 0 && count($params) > 1) {
            $product_types = substr($types, 1);
            $product_params = array_slice($params, 1);
        } else if (strpos($types, 'i') === 0 && count($params) == 1) {
            // Only user_id was set, but no date filters
            $product_types = "";
            $product_params = [];
        } else {
            // Only date filters were set, which is correct for Admin/Mod product view
        }
    }
    
    $sql_sales_report = "
        SELECT
            p.product_name,
            p.Cost AS unit_cost,
            SUM(o.quantity) AS total_quantity_sold,
            SUM(o.quantity * p.price) AS total_sales_value,
            SUM(o.quantity * p.Cost) AS total_cost_of_goods,
            (SUM(o.quantity * p.price) - SUM(o.quantity * p.Cost)) AS total_profit
        FROM orders o
        JOIN products p ON o.product_ID = p.product_ID
        WHERE o.status = 'Delivered'
        {$date_filter_clause}
        GROUP BY p.product_name, p.Cost
        ORDER BY total_sales_value DESC";

     $stmt_product = $conn->prepare($sql_sales_report);
     if (!$stmt_product) {
          $error .= " | ❌ DB Error preparing product sales query: " . htmlspecialchars($conn->error);
     } else {
        // Bind parameters for Dates only
        if (!empty($product_params)) {
             // Use call_user_func_array
             $bind_params_product = [$product_types];
             foreach ($product_params as $key => $value) {
                 $bind_params_product[] = &$product_params[$key];
             }
             call_user_func_array([$stmt_product, 'bind_param'], $bind_params_product);
        }
        
        $stmt_product->execute();
        $sales_report = $stmt_product->get_result();
     }
}


?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?></title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.31/jspdf.plugin.autotable.min.js"></script>
    <link rel="stylesheet" href="reports.css">
</head>
<body>
    <?php include_role_navbar(); ?>
    <?php include_role_view('reports'); ?>
    <?php include __DIR__ . '/includes/views/reports/_export_script.php'; ?>
    <?php include_hamburger_script(); ?>
</body>
</html>
