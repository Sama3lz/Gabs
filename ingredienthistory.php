<?php
session_start();
include("gabsdb.php"); // Include DB connection
require_once __DIR__ . "/includes/auth_helpers.php";

// --- 1. Embedded Access Control ---
// Access Control: Allow Admin and Moderator only
require_login(['Admin', 'Moderator']);
$user_id = $_SESSION['user_ID']; // Needed for potential future actions
$user_role = $_SESSION['role'] ?? '';

// --- 2. Embedded Session Message Handling ---
$msg = "";
$error = "";
if (isset($_SESSION['message'])) {
    // Wrap simple messages, assume complex ones already have divs
    if (strpos($_SESSION['message'], 'error') !== false || strpos($_SESSION['message'], '❌') !== false) {
         if (strpos($_SESSION['message'], '<div') === false) { $error = "<div class='message error'>" . htmlspecialchars($_SESSION['message']) . "</div>"; } else { $error = $_SESSION['message']; }
    } else { // *** FIX: Removed stray 'd' from here ***
         if (strpos($_SESSION['message'], '<div') === false) { $msg = "<div class='message success'>" . htmlspecialchars($_SESSION['message']) . "</div>"; } else { $msg = $_SESSION['message']; }
    }
    unset($_SESSION['message']);
}


// --- 3. Page Variables & Navbar Logic ---
$page_title = "Ingredient Audit Report - Gab's Bakeshop";
$current_page = basename($_SERVER['PHP_SELF']);

$nav = get_nav_permissions($user_role);
$show_inventory_link = $nav['show_inventory_link'];
$show_reports_link = $nav['show_reports_link'];
$show_accounts_link = $nav['show_accounts_link'];
$show_orders_link = $nav['show_orders_link'];
$show_settings_link = $nav['show_settings_link'];


// --- SECURE DATE FILTER LOGIC ---
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$date_filters_sql = ""; // SQL part
$date_params = [];    // Parameters for binding
$date_types = "";     // Parameter types
$filter_applied = false;

// Ensure $conn is available before using it
if (isset($conn) && $conn instanceof mysqli) {
    if (!empty($start_date) && DateTime::createFromFormat('Y-m-d', $start_date)) {
        $date_filters_sql .= " AND ih.action_date >= ?";
        $date_params[] = $start_date . " 00:00:00";
        $date_types .= "s";
        $filter_applied = true;
    }
    if (!empty($end_date) && DateTime::createFromFormat('Y-m-d', $end_date)) {
        $date_filters_sql .= " AND ih.action_date <= ?";
        $date_params[] = $end_date . " 23:59:59";
        $date_types .= "s";
        $filter_applied = true;
    }
} else {
    // Handle case where DB connection failed earlier
    $error .= " | ❌ Database connection is not available for filtering.";
    $inventory_history = false; // Prevent query execution
}


// --- NEW: Pagination Logic ---
$limit = 50; // Items per page
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;


// --- Fetch Inventory History ---
// Only proceed if DB connection is valid
if (isset($conn) && $conn instanceof mysqli && !isset($inventory_history)) { // Check if not already set to false
    
    // --- 1. Get TOTAL count for pagination ---
    $sql_where = " WHERE 1=1 {$date_filters_sql}";
    $sql_count = "SELECT COUNT(*) FROM inventory_history ih {$sql_where}";
    $stmt_count = $conn->prepare($sql_count);
    if (!$stmt_count) {
         $error .= " | ❌ Error preparing count query: " . htmlspecialchars($conn->error);
         $total_results = 0;
         $total_pages = 0;
         $inventory_history = false; // Don't try to fetch results
    } else {
        if (!empty($date_params)) {
            $stmt_count->bind_param($date_types, ...$date_params);
        }
        $stmt_count->execute();
        $total_results = $stmt_count->get_result()->fetch_row()[0];
        $total_pages = ceil($total_results / $limit);
        $stmt_count->close();
    }
    // --- END: Pagination Count ---
    
    // --- 2. Get Paginated RESULTS (if count was successful) ---
    if (!isset($inventory_history)) {
        $sql_inventory_history = "
            SELECT
                ih.action_date AS timestamp,
                i.ingredients AS ingredient_name,
                i.type AS ingredient_type,
                ih.action AS activity_type,
                ih.old_stock,
                ih.new_stock,
                (ih.new_stock - ih.old_stock) AS quantity_change,
                a.name AS user_name,
                p.product_name AS produced_product_name
            FROM inventory_history ih
            JOIN ingredients i ON ih.ingredients_ID = i.ingredients_ID
            JOIN accounts a ON ih.user_ID = a.user_ID
            LEFT JOIN products p ON ih.product_ID = p.product_ID
            {$sql_where} -- Inject date filter
            ORDER BY ih.action_date DESC
            LIMIT ? OFFSET ?"; // <-- MODIFIED: Replaced hardcoded limit
        
        $stmt = $conn->prepare($sql_inventory_history);
        
        if (!$stmt) {
             $error .= " | ❌ Error preparing history query: " . htmlspecialchars($conn->error);
             $inventory_history = false;
        } else {
            // Bind date parameters if they exist
            $current_params = $date_params; // Copy date params
            $current_types = $date_types;   // Copy date types
            
            // Add pagination params
            $current_params[] = $limit;
            $current_params[] = $offset;
            $current_types .= "ii";

            $stmt->bind_param($current_types, ...$current_params);
            
            $stmt->execute();
            $inventory_history = $stmt->get_result();
        }
    }

} elseif (!isset($inventory_history)) {
     // Ensure $inventory_history is defined even if DB connection failed
     $inventory_history = false;
     $total_pages = 0; // Ensure pagination doesn't run
}


// Formatting functions (Keep for display)
function format_stock_display($value, $type) {
    $value = (float)$value;
    $unit_label = '';
    $details = '';
    if ($type === 'Dry') {
        $unit_label = 'kg';
    } elseif ($type === 'Wet') {
        $unit_label = 'gal';
    }
     // Format main value with appropriate precision
    return number_format($value, 4) . " " . $unit_label;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
     <!-- ****** RESPONSIVE: Added Viewport Meta Tag ****** -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?></title>
    <link rel="stylesheet" href="ingredienthistory.css">
</head>
<body>
    <!-- ****** EMBEDDED Navbar HTML ****** -->
    <?php include __DIR__ . '/includes/navbar.php'; ?>

<div class="page-content">
        <h1>System Reports Dashboard</h1>

         <?php
            // Display messages if any
             if (!empty($msg)) echo $msg; // Assumes $msg is safe or already HTML
             if (!empty($error)) echo $error; // Assumes $error is safe or already HTML
        ?>

        <div class="sub-nav">
             <!-- *** FIX: Correct active tab logic *** -->
            <a href="reports.php" class="<?= ($current_page == 'reports.php') ? 'active-tab' : '' ?>">Sales Reports</a>
            <?php if (in_array($user_role, ['Admin', 'Moderator'])): ?>
                <a href="ingredienthistory.php" class="<?= ($current_page == 'ingredienthistory.php') ? 'active-tab' : '' ?>">Ingredient Audit</a>
                <a href="producthistory.php" class="<?= ($current_page == 'producthistory.php') ? 'active-tab' : '' ?>">Product Audit</a>
            <?php endif; ?>
             <!-- Receipt link visible to Branch too -->
             <a href="receipt.php" class="<?= ($current_page == 'receipt.php') ? 'active-tab' : '' ?>">Delivery Receipts</a>
        </div>

        <?php if (in_array($user_role, ['Admin', 'Moderator'])): ?>
            <h2>Ingredient Inventory History</h2>

             <?php // Filter Form ?>
             <div class="filter-form">
                 <form method="GET" action="ingredienthistory.php">
                     <label for="start_date">Filter by Date Range:</label>
                     <label for="start_date">Start:</label>
                     <input type="date" id="start_date" name="start_date" value="<?= htmlspecialchars($start_date) ?>">
                     <label for="end_date">End:</label>
                     <input type="date" id="end_date" name="end_date" value="<?= htmlspecialchars($end_date) ?>">
                     <button type="submit">Filter</button>
                     <a href="ingredienthistory.php" class="btn">Reset</a>
                 </form>
             </div>
             
            <?php if ($filter_applied): ?>
                <p style="text-align: center; font-weight: bold;">
                    Showing results from <?= htmlspecialchars($start_date) ?> to <?= htmlspecialchars($end_date) ?>.
                </p>
            <?php endif; ?>

             <!-- Responsive Table Container -->
             <div class="table-container">
                 <table>
                     <thead>
                         <tr>
                             <th>Timestamp</th>
                             <th>Ingredient</th>
                             <th>Activity Type</th>
                             <th>Details / Product</th>
                             <th>Old Stock</th>
                             <th>New Stock</th>
                             <th>Change</th>
                             <th>User</th>
                         </tr>
                     </thead>
                     <tbody>
                         <?php if ($inventory_history && $inventory_history->num_rows > 0): ?>
                             <?php while($row = $inventory_history->fetch_assoc()):
                                 $change = $row['quantity_change']; // Use value from DB
                                 $change_class = $change >= 0 ? 'change-positive' : 'change-negative';
                                 $change_sign = $change >= 0 ? '+' : '';
                                 $activity_type_display = htmlspecialchars(str_replace('_', ' ', $row['activity_type']));
                             ?>
                             <tr>
                                 <td><?= htmlspecialchars(date('M d, Y H:i:s', strtotime($row['timestamp']))) ?></td>
                                 <td><?= htmlspecialchars($row['ingredient_name']) ?></td>
                                 <td><?= $activity_type_display ?></td>
                                 <td>
                                     <?php
                                         if (!empty($row['produced_product_name'])) {
                                             echo "Used for: " . htmlspecialchars($row['produced_product_name']);
                                         } else {
                                             echo "N/A"; // Or show activity type again if preferred
                                         }
                                     ?>
                                 </td>
                                 <td><?= format_stock_display($row['old_stock'], $row['ingredient_type']) ?></td>
                                 <td><?= format_stock_display($row['new_stock'], $row['ingredient_type']) ?></td>
                                 <td class="<?= $change_class ?>"><?= $change_sign . format_stock_display(abs($change), $row['ingredient_type']) ?></td>
                                 <td><?= htmlspecialchars($row['user_name']) ?></td>
                             </tr>
                             <?php endwhile; ?>
                         <?php else: ?>
                             <tr><td colspan="8">No ingredient history records found<?php if(!empty($date_params)) echo " for the selected date range"; ?>.</td></tr>
                         <?php endif; ?>
                          <?php if ($stmt ?? null) $stmt->close(); // Close statement if it exists ?>
                     </tbody>
                 </table>
             </div> <!-- /table-container -->
             
            <!-- --- NEW: Pagination Links --- -->
            <div class="pagination">
                <?php
                // --- Build query string for pagination links ---
                $query_params = [];
                if ($filter_applied) {
                    $query_params['start_date'] = $start_date;
                    $query_params['end_date'] = $end_date;
                }
    
                // Previous Button
                if ($page > 1) {
                    $query_params['page'] = $page - 1;
                    echo '<a href="ingredienthistory.php?' . http_build_query($query_params) . '">&laquo; Previous</a>';
                } else {
                    echo '<span class="disabled">&laquo; Previous</span>';
                }
    
                // Page Numbers
                $window = 2;
                for ($i = 1; $i <= $total_pages; $i++) {
                    if ($i == 1 || $i == $total_pages || ($i >= $page - $window && $i <= $page + $window)) {
                        if ($i == $page) {
                            echo '<span class="current">' . $i . '</span>';
                        } else {
                            $query_params['page'] = $i;
                            echo '<a href="ingredienthistory.php?' . http_build_query($query_params) . '">' . $i . '</a>';
                        }
                    } elseif ($i == $page - $window - 1 || $i == $page + $window + 1) {
                        echo '<span>...</span>'; // Add ellipsis
                    }
                }
    
                // Next Button
                if ($page < $total_pages) {
                    $query_params['page'] = $page + 1;
                    echo '<a href="ingredienthistory.php?' . http_build_query($query_params) . '">Next &raquo;</a>';
                } else {
                    echo '<span class="disabled">Next &raquo;</span>';
                }
                ?>
            </div>
            <!-- --- END: Pagination Links --- -->

        <?php else: ?>
            <p>Access denied. Ingredient history is available only for Admin and Moderator roles.</p>
        <?php endif; ?>

    </div> <!-- /page-content -->

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