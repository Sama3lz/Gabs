<?php
session_start();
// ** Use include for consistency **
include("gabsdb.php"); // Assumes $conn is available globally
require_once __DIR__ . "/includes/auth_helpers.php";

// --- 1. Embedded Access Control ---
// Access Control: Allow Admin and Moderator only
require_login(['Admin', 'Moderator']);

// Ensure $conn is available
if (!isset($conn) || !$conn instanceof mysqli) {
    error_log("Database connection failed in producthistory.php");
    $_SESSION['message'] = "<div class='message error'>❌ Database connection is unavailable.</div>";
    header("Location: reports.php");
    exit();
}

$user_id = $_SESSION['user_ID']; // Needed for potential future actions
$user_role = $_SESSION['role'] ?? '';

// --- 2. Embedded Session Message Handling ---
$msg = "";
$error = "";
if (isset($_SESSION['message'])) {
    // Wrap simple messages, assume complex ones already have divs
    if (strpos($_SESSION['message'], 'error') !== false || strpos($_SESSION['message'], '❌') !== false) {
         if (strpos($_SESSION['message'], '<div') === false) { $error = "<div class='message error'>" . htmlspecialchars($_SESSION['message']) . "</div>"; } else { $error = $_SESSION['message']; }
    } else {
         if (strpos($_SESSION['message'], '<div') === false) { $msg = "<div class='message success'>" . htmlspecialchars($_SESSION['message']) . "</div>"; } else { $msg = $_SESSION['message']; }
    }
    unset($_SESSION['message']);
}

// --- 3. Page Variables & Navbar Logic ---
$page_title = "Product Audit Report - Gab's Bakeshop";
$current_page = basename($_SERVER['PHP_SELF']);

apply_nav_permissions($user_role);


// --- SECURE DATE FILTER LOGIC ---
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$date_filters_sql = ""; // SQL part
$date_params = [];    // Parameters for binding
$date_types = "";     // Parameter types
$filter_applied = false;

if (!empty($start_date) && DateTime::createFromFormat('Y-m-d', $start_date)) {
    // ** Use 'ph' alias for product_history table **
    $date_filters_sql .= " AND ph.action_date >= ?";
    $date_params[] = $start_date . " 00:00:00";
    $date_types .= "s";
    $filter_applied = true;
}
if (!empty($end_date) && DateTime::createFromFormat('Y-m-d', $end_date)) {
    // ** Use 'ph' alias for product_history table **
    $date_filters_sql .= " AND ph.action_date <= ?";
    $date_params[] = $end_date . " 23:59:59";
    $date_types .= "s";
    $filter_applied = true;
}

// --- NEW: Pagination Logic ---
$limit = 50; // Items per page
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

// --- 1. Get TOTAL count for pagination ---
$sql_where = " WHERE 1=1 {$date_filters_sql}";
$sql_count = "SELECT COUNT(*) FROM product_history ph {$sql_where}";
$stmt_count = $conn->prepare($sql_count);
if (!$stmt_count) {
     $error .= " | ❌ Error preparing count query: " . htmlspecialchars($conn->error);
     $total_results = 0;
     $total_pages = 0;
} else {
    if (!empty($date_params)) {
        $stmt_count->bind_param($date_types, ...$date_params);
    }
    $stmt_count->execute();
    $total_results = $stmt_count->get_result()->fetch_row()[0];
    $total_pages = ceil($total_results / $limit);
    $stmt_count->close();
}
// --- END: Pagination Logic ---


// --- Fetch Product History ---
// ** Use Prepared Statement for Date Filter **
$sql_product_history = "
    SELECT
        ph.action_date AS timestamp,
        p.product_name,
        ph.action AS activity_type,
        ph.old_stock,
        ph.new_stock,
        (ph.new_stock - ph.old_stock) AS quantity_change,
        a.name AS user_name
        -- Add reference_info if that column exists and is populated
        -- ph.reference_info
    FROM
        product_history ph
    JOIN
        products p ON ph.product_ID = p.product_ID
    JOIN
        accounts a ON ph.user_ID = a.user_ID
    {$sql_where} -- Inject the date filter SQL part
    ORDER BY
        ph.action_date DESC
    LIMIT ? OFFSET ?"; // <-- MODIFIED: Replaced hardcoded limit

$stmt = $conn->prepare($sql_product_history);
if (!$stmt) {
     $error .= " | ❌ Error preparing history query: " . htmlspecialchars($conn->error);
     $product_history = false;
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
    $product_history = $stmt->get_result();
    // Don't close yet
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
     <!-- ****** RESPONSIVE: Added Viewport Meta Tag ****** -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?></title>
    <link rel="stylesheet" href="producthistory.css">
</head>
<body>
    <!-- ****** EMBEDDED Navbar HTML ****** -->
    <?php include_role_navbar(); ?>

<div class="page-content">
    <h1>System Reports Dashboard</h1>

     <?php
         // Display messages if any
          if (!empty($msg) && strpos($msg, '<div') === false) { echo "<div class='message success'>" . htmlspecialchars($msg) . "</div>"; } elseif (!empty($msg)) { echo $msg; }
          if (!empty($error) && strpos($error, '<div') === false) { echo "<div class='message error'>" . htmlspecialchars($error) . "</div>"; } elseif (!empty($error)) { echo $error; }
     ?>

    <div class="sub-nav">
         <!-- *** Corrected active tab logic *** -->
        <a href="reports.php" class="<?= ($current_page == 'reports.php') ? 'active-tab' : '' ?>">Sales Reports</a>
        <?php if (in_array($user_role, ['Admin', 'Moderator'])): ?>
            <a href="ingredienthistory.php" class="<?= ($current_page == 'ingredienthistory.php') ? 'active-tab' : '' ?>">Ingredient Audit</a>
            <a href="producthistory.php" class="<?= ($current_page == 'producthistory.php') ? 'active-tab' : '' ?>">Product Audit</a>
        <?php endif; ?>
         <!-- Receipt link visible to Branch too -->
         <a href="receipt.php" class="<?= ($current_page == 'receipt.php') ? 'active-tab' : '' ?>">Delivery Receipts</a>
    </div>

    <?php if (in_array($user_role, ['Admin', 'Moderator'])): ?>

    <h2>Product Production/Stock History</h2>

    <?php // Filter Form ?>
    <div class="filter-form">
        <form method="GET" action="producthistory.php">
            <label for="start_date">Filter by Date Range:</label>
            <label for="start_date">Start:</label>
            <input type="date" id="start_date" name="start_date" value="<?= htmlspecialchars($start_date) ?>">
            <label for="end_date">End:</label>
            <input type="date" id="end_date" name="end_date" value="<?= htmlspecialchars($end_date) ?>">
            <button type="submit">Filter</button>
            <a href="producthistory.php" class="btn">Reset</a>
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
                    <th>Product</th>
                    <th>Activity Type</th>
                    <th>Old Stock</th>
                    <th>New Stock</th>
                    <th>Change</th>
                    <th>User</th>
                    <!-- Add Reference Info column if needed -->
                    <!-- <th>Details</th> -->
                </tr>
            </thead>
            <tbody>
                <?php if ($product_history && $product_history->num_rows > 0): ?>
                    <?php while($row = $product_history->fetch_assoc()):
                        $change = $row['quantity_change'];
                        $change_class = $change >= 0 ? 'change-positive' : 'change-negative';
                        $change_sign = $change >= 0 ? '+' : '';
                        $activity_type_display = htmlspecialchars(str_replace('_', ' ', $row['activity_type']));
                    ?>
                    <tr>
                        <td><?= htmlspecialchars(date('M d, Y H:i:s', strtotime($row['timestamp']))) ?></td>
                        <td><?= htmlspecialchars($row['product_name']) ?></td>
                        <td><?= $activity_type_display ?></td>
                        <td><?= number_format($row['old_stock']) ?></td>
                        <td><?= number_format($row['new_stock']) ?></td>
                        <td class="<?= $change_class ?>"><?= $change_sign . number_format($change) ?></td>
                        <td><?= htmlspecialchars($row['user_name']) ?></td>
                         <!-- Display reference info if added to query and exists -->
                         <!-- <td><?= htmlspecialchars($row['reference_info'] ?? '') ?></td> -->
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="7">No product history records found<?php if($filter_applied) echo " for the selected date range"; ?>.</td></tr>
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
                echo '<a href="producthistory.php?' . http_build_query($query_params) . '">&laquo; Previous</a>';
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
                        echo '<a href="producthistory.php?' . http_build_query($query_params) . '">' . $i . '</a>';
                    }
                } elseif ($i == $page - $window - 1 || $i == $page + $window + 1) {
                    echo '<span>...</span>'; // Add ellipsis
                }
            }

            // Next Button
            if ($page < $total_pages) {
                $query_params['page'] = $page + 1;
                echo '<a href="producthistory.php?' . http_build_query($query_params) . '">Next &raquo;</a>';
            } else {
                echo '<span class="disabled">Next &raquo;</span>';
            }
            ?>
        </div>
        <!-- --- END: Pagination Links --- -->


    <?php else: ?>
        <h2>Access Denied</h2>
        <p>Access to this report is restricted to <strong>Admin</strong> and <strong>Moderator</strong> roles.</p>
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