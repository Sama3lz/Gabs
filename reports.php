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

$nav = get_nav_permissions($user_role);
$show_inventory_link = $nav['show_inventory_link'];
$show_reports_link = $nav['show_reports_link'];
$show_accounts_link = $nav['show_accounts_link'];
$show_orders_link = $nav['show_orders_link'];
$show_settings_link = $nav['show_settings_link'];

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
    <?php include __DIR__ . '/includes/navbar.php'; ?>

<div class="page-content">
    <h1>System Reports Dashboard</h1>

     <?php
         if (!empty($msg)) echo "<div class='message success'>{$msg}</div>";
         if (!empty($error)) echo "<div class='message error'>{$error}</div>";
    ?>

     <div class="sub-nav">
        <a href="reports.php" class="<?= ($current_page == 'reports.php') ? 'active-tab' : '' ?>">Sales Reports</a>
        <?php if (in_array($user_role, ['Admin', 'Moderator'])): ?>
             <a href="ingredienthistory.php" class="<?= ($current_page == 'ingredienthistory.php') ? 'active-tab' : '' ?>">Ingredient Audit</a>
             <a href="producthistory.php" class="<?= ($current_page == 'producthistory.php') ? 'active-tab' : '' ?>">Product Audit</a>
        <?php endif; ?>
         <a href="receipt.php" class="<?= ($current_page == 'receipt.php') ? 'active-tab' : '' ?>">Delivery Receipts</a>
    </div>

    <h2>Date Filter</h2>
    <form method="GET" action="reports.php" class="sub-nav no-print" style="border-bottom: 1px solid #eee; margin-bottom: 15px; padding-bottom: 10px; gap: 15px; justify-content: flex-start;">
        <div style="display: flex; flex-direction: column; flex-grow: 0; min-width: 150px;">
            <label for="date_from" style="font-weight: bold; margin-bottom: 5px; color: #333; font-size: 0.95em;">Date From:</label>
            <input type="date" id="date_from" name="date_from" value="<?= htmlspecialchars($date_from) ?>" 
                 style="padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-size: 0.9em;">
        </div>
        
        <div style="display: flex; flex-direction: column; flex-grow: 0; min-width: 150px;">
            <label for="date_to" style="font-weight: bold; margin-bottom: 5px; color: #333; font-size: 0.95em;">Date To:</label>
            <input type="date" id="date_to" name="date_to" value="<?= htmlspecialchars($date_to) ?>" 
                 style="padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-size: 0.9em;">
        </div>
        
        <div style="display: flex; align-items: flex-end; gap: 10px;">
            <button type="submit" class="logout-link" 
                 style="margin-left: 0; background-color: #f2a154 !important; color: white !important; height: 38px; line-height: 18px; padding: 10px 15px; align-self: flex-end; border: none;">Apply Filter</button>
            
            <button type="reset" onclick="window.location.href='reports.php';" class="logout-link" 
                 style="margin-left: 0; background-color: #ddd !important; color: #333 !important; height: 38px; line-height: 18px; padding: 10px 15px; align-self: flex-end; border: none;">Reset</button>
        </div>
    </form>

    <div class="export-container no-print">
        <button onclick="exportToPDF()" class="pdf-export-btn">📄 Export to PDF</button>
    </div>

    <h2>Branch Sales Subtotals</h2>
    <p style="text-align: center;">
    <?php if (!empty($date_from) || !empty($date_to)): ?>
        <strong>Filter Applied:</strong> Showing sales from <strong><?= htmlspecialchars($date_from ?: 'start of time') ?></strong> to <strong><?= htmlspecialchars($date_to ?: 'today') ?></strong>.
    <?php endif; ?>
    </p>
    <div class="table-container">
        <table class="branch-sales-table" id="branchSalesTable">
            <thead>
                <tr>
                    <th>Branch Name</th>
                    <th>Location</th>
                    <th>Total Sales</th>
                    <th>Total Cost (COGS)</th>
                    <th>Total Income (Profit)</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($branch_sales_report && $branch_sales_report->num_rows > 0): ?>
                    <?php 
                    $grand_total_branch = 0; 
                    $grand_total_branch_cost = 0;
                    $grand_total_branch_profit = 0;
                    ?>
                    <?php while($row = $branch_sales_report->fetch_assoc()): ?>
                    <?php 
                        $grand_total_branch += $row['branch_subtotal']; 
                        $grand_total_branch_cost += $row['branch_total_cost'];
                        $grand_total_branch_profit += $row['branch_total_profit'];
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($row['branch_name']) ?></td>
                        <td><?= htmlspecialchars($row['branch_location'] ?? 'N/A') ?></td>
                        <td class="col-money">₱<?= number_format($row['branch_subtotal'], 2) ?></td>
                        <td class="col-money">₱<?= number_format($row['branch_total_cost'], 2) ?></td>
                        <td class="col-money" style="font-weight:bold; <?= $row['branch_total_profit'] < 0 ? 'color:red;' : 'color:green;' ?>">₱<?= number_format($row['branch_total_profit'], 2) ?></td>
                    </tr>
                    <?php endwhile; ?>
                    <tr class="grand-total-row">
                        <td colspan="2">GRAND TOTAL (ALL BRANCHES SHOWN):</td>
                        <td class="col-money">₱<?= number_format($grand_total_branch, 2) ?></td>
                        <td class="col-money">₱<?= number_format($grand_total_branch_cost, 2) ?></td>
                        <td class="col-money" style="font-weight:bold; <?= $grand_total_branch_profit < 0 ? 'color:red;' : 'color:green;' ?>">₱<?= number_format($grand_total_branch_profit, 2) ?></td>
                    </tr>
                <?php else: ?>
                    <tr><td colspan="5">No delivered sales records found.</td></tr>
                <?php endif; ?>
                 <?php if($stmt_branch) $stmt_branch->close(); ?>
            </tbody>
        </table>
    </div>

    <hr>

    <?php
    if (in_array($user_role, ['Admin', 'Moderator'])): ?>
    <h2>Sales Report by Product</h2>
    <p style="text-align: center;">
    <?php if (!empty($date_from) || !empty($date_to)): ?>
        <strong>Filter Applied:</strong> Showing sales from <strong><?= htmlspecialchars($date_from ?: 'start of time') ?></strong> to <strong><?= htmlspecialchars($date_to ?: 'today') ?></strong>.
    <?php endif; ?>
    </p>
    
    <div class="table-container">
        <table class="product-sales-table" id="productSalesTable">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Units Sold</th>
                    <th>Unit Cost</th>
                    <th>Total Cost (COGS)</th>
                    <th>Total Sales Value</th>
                    <th>Total Income (Profit)</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($sales_report && $sales_report->num_rows > 0): ?>
                    <?php 
                    $grand_total_product = 0; 
                    $grand_total_cost = 0;
                    $grand_total_profit = 0;
                    ?>
                    <?php while($row = $sales_report->fetch_assoc()): ?>
                    <?php 
                        $grand_total_product += $row['total_sales_value']; 
                        $grand_total_cost += $row['total_cost_of_goods'];
                        $grand_total_profit += $row['total_profit'];
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($row['product_name']) ?></td>
                        <td class="col-numeric"><?= number_format($row['total_quantity_sold']) ?></td>
                        <td class="col-money">₱<?= number_format($row['unit_cost'], 2) ?></td>
                        <td class="col-money">₱<?= number_format($row['total_cost_of_goods'], 2) ?></td>
                        <td class="col-money">₱<?= number_format($row['total_sales_value'], 2) ?></td>
                        <td class="col-money" style="font-weight:bold; <?= $row['total_profit'] < 0 ? 'color:red;' : 'color:green;' ?>">₱<?= number_format($row['total_profit'], 2) ?></td>
                    </tr>
                    <?php endwhile; ?>
                    <tr class="grand-total-row">
                        <td colspan="2">GRAND TOTAL (ALL PRODUCTS):</td>
                        <td class="col-money">---</td>
                        <td class="col-money">₱<?= number_format($grand_total_cost, 2) ?></td>
                        <td class="col-money">₱<?= number_format($grand_total_product, 2) ?></td>
                        <td class="col-money" style="font-weight:bold; <?= $grand_total_profit < 0 ? 'color:red;' : 'color:green;' ?>">₱<?= number_format($grand_total_profit, 2) ?></td>
                    </tr>
                <?php else: ?>
                    <tr><td colspan="6">No delivered sales records found for any product.</td></tr>
                <?php endif; ?>
                 <?php if($stmt_product ?? null) $stmt_product->close(); ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

</div> 

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

    function exportToPDF() {
        const { jsPDF } = window.jspdf;
        const doc = new jsPDF('l', 'mm', 'a4'); // Landscape orientation
        
        // Get date filter info
        const dateFrom = '<?= htmlspecialchars($date_from ?: "start of time") ?>';
        const dateTo = '<?= htmlspecialchars($date_to ?: "today") ?>';
        
        // Add header
        doc.setFontSize(18);
        doc.setTextColor(40);
        doc.text("Gab's Bakeshop - Sales Report", 148, 15, { align: 'center' });
        
        doc.setFontSize(11);
        doc.text(`Report Period: ${dateFrom} to ${dateTo}`, 148, 22, { align: 'center' });
        doc.text(`Generated: ${new Date().toLocaleString()}`, 148, 28, { align: 'center' });
        
        let yPosition = 35;
        
        // Branch Sales Table
        const branchTable = document.getElementById('branchSalesTable');
        if (branchTable) {
            doc.setFontSize(14);
            doc.setTextColor(40);
            doc.text('Branch Sales Subtotals', 14, yPosition);
            yPosition += 5;
            
            const branchRows = [];
            const tbody = branchTable.querySelector('tbody');
            const rows = tbody.querySelectorAll('tr');
            
            rows.forEach(row => {
                const cells = row.querySelectorAll('td');
                if (cells.length > 0) {
                    const rowData = Array.from(cells).map(cell => {
                        // Remove peso sign and preserve the text
                        return cell.textContent.trim();
                    });
                    branchRows.push(rowData);
                }
            });
            
            doc.autoTable({
                startY: yPosition,
                head: [['Branch Name', 'Location', 'Total Sales', 'Total Cost (COGS)', 'Total Income (Profit)']],
                body: branchRows,
                theme: 'striped',
                headStyles: { fillColor: [242, 161, 84], textColor: 255, fontStyle: 'bold' },
                styles: { fontSize: 9, cellPadding: 3 },
                columnStyles: {
                    0: { cellWidth: 45 },
                    1: { cellWidth: 45 },
                    2: { cellWidth: 35, halign: 'right' },
                    3: { cellWidth: 40, halign: 'right' },
                    4: { cellWidth: 40, halign: 'right' }
                },
                didParseCell: function(data) {
                    // Style the grand total row
                    if (data.row.index === branchRows.length - 1 && data.row.raw[0].includes('GRAND TOTAL')) {
                        data.cell.styles.fillColor = [255, 230, 204];
                        data.cell.styles.fontStyle = 'bold';
                    }
                }
            });
            
            yPosition = doc.lastAutoTable.finalY + 15;
        }
        
        // Product Sales Table (if exists)
        const productTable = document.getElementById('productSalesTable');
        if (productTable) {
            doc.setFontSize(14);
            doc.setTextColor(40);
            doc.text('Sales Report by Product', 14, yPosition);
            yPosition += 5;
            
            const productRows = [];
            const tbody = productTable.querySelector('tbody');
            const rows = tbody.querySelectorAll('tr');
            
            rows.forEach(row => {
                const cells = row.querySelectorAll('td');
                if (cells.length > 0) {
                    const rowData = Array.from(cells).map(cell => {
                        return cell.textContent.trim();
                    });
                    productRows.push(rowData);
                }
            });
            
            doc.autoTable({
                startY: yPosition,
                head: [['Product', 'Units Sold', 'Unit Cost', 'Total Cost (COGS)', 'Total Sales Value', 'Total Income (Profit)']],
                body: productRows,
                theme: 'striped',
                headStyles: { fillColor: [242, 161, 84], textColor: 255, fontStyle: 'bold' },
                styles: { fontSize: 9, cellPadding: 3 },
                columnStyles: {
                    0: { cellWidth: 55 },
                    1: { cellWidth: 25, halign: 'center' },
                    2: { cellWidth: 30, halign: 'right' },
                    3: { cellWidth: 35, halign: 'right' },
                    4: { cellWidth: 35, halign: 'right' },
                    5: { cellWidth: 35, halign: 'right' }
                },
                didParseCell: function(data) {
                    // Style the grand total row
                    if (data.row.index === productRows.length - 1 && data.row.raw[0].includes('GRAND TOTAL')) {
                        data.cell.styles.fillColor = [255, 230, 204];
                        data.cell.styles.fontStyle = 'bold';
                    }
                }
            });
        }
        
        // Save the PDF
        const filename = `Sales_Report_${dateFrom}_to_${dateTo}_${new Date().getTime()}.pdf`;
        doc.save(filename);
    }
</script>

</body>
</html>