<?php
session_start();
// ** Use include for consistency **
include("gabsdb.php"); // Assumes $conn is available globally
require_once __DIR__ . "/includes/auth_helpers.php";

// --- ACCESS CONTROL (Kept the original logic for receipt.php) ---
require_login(['Admin', 'Moderator', 'Branch']);

// Ensure $conn is available after include
if (!isset($conn) || !$conn instanceof mysqli) {
    error_log("Database connection failed in receipt.php");
    $_SESSION['message'] = "<div class='message error'>❌ Database connection is unavailable.</div>";
    header("Location: welcome.php");
    exit();
}


$user_id = $_SESSION['user_ID'];
$user_role = $_SESSION['role'] ?? ''; // Use null coalescing for safety
$current_page = basename($_SERVER['PHP_SELF']); // For navbar and sub-nav active state

// --- Page Variables & Navbar Logic (Copied from ingredienthistory.php) ---
$page_title = "Receipts - Gab's Bakeshop"; // Updated title
$nav = get_nav_permissions($user_role);
$show_inventory_link = $nav['show_inventory_link'];
$show_reports_link = $nav['show_reports_link'];
$show_accounts_link = $nav['show_accounts_link'];
$show_orders_link = $nav['show_orders_link'];
$show_settings_link = $nav['show_settings_link'];

// --- DETERMINE MODE: ARCHIVE or DETAIL ---
$is_detail_view = isset($_GET['receipt_id']);
$receipt_id = $is_detail_view ? intval($_GET['receipt_id']) : null;

// --- DETAIL VIEW LOGIC ---
if ($is_detail_view) {
    $page_title = "Receipt #" . htmlspecialchars($receipt_id) . " - Gab's Bakeshop"; // Specific title for detail view

    // ** Use Prepared Statement **
    $sql = "SELECT r.*, a.name AS branch_name, a.Location, u.username AS approved_by_name, r.proof_image AS receipt_image_path
            FROM receipt r
            JOIN accounts a ON r.branch_ID = a.user_ID
            JOIN accounts u ON r.approved_by = u.user_ID
            WHERE r.receipt_ID = ?";
    $stmt_detail = $conn->prepare($sql);
    if (!$stmt_detail) {
        error_log("Error preparing detail query: " . $conn->error);
        $_SESSION['message'] = "<div class='message error'>❌ Could not load receipt details right now.</div>";
        header("Location: receipt.php");
        exit();
    }
    $stmt_detail->bind_param("i", $receipt_id);
    $stmt_detail->execute();
    $receipt_result = $stmt_detail->get_result();
    $receipt = $receipt_result->fetch_assoc();
    $stmt_detail->close();


    if (!$receipt) {
        $_SESSION['message'] = "<div class='message error'>❌ Receipt #{$receipt_id} not found.</div>";
        header("Location: receipt.php");
        exit();
    }

    // Branch access restriction
    if ($user_role === 'Branch' && $receipt['branch_ID'] != $user_id) {
        $_SESSION['message'] = "<div class='message error'>❌ Access denied to Receipt #{$receipt_id}.</div>";
        header("Location: receipt.php");
        exit();
    }

    // --- GET ORDER ITEMS FOR THE SPECIFIC RECEIPT ---
    // ** PATCHED: Only include Approved and Delivered orders **
    $order_query = "SELECT o.order_ID, p.product_name, p.price, p.Cost, o.quantity, (o.quantity * p.price) AS subtotal
                    FROM orders o
                    JOIN products p ON o.product_ID = p.product_ID
                    WHERE o.branch_ID = ? AND o.order_date = ? AND o.status IN ('Approved', 'Delivered')";
    $stmt_items = $conn->prepare($order_query);
     if (!$stmt_items) {
         error_log("Error preparing order items query: " . $conn->error);
         $_SESSION['message'] = "<div class='message error'>❌ Could not load receipt items right now.</div>";
         header("Location: receipt.php");
         exit();
     }
    $stmt_items->bind_param("is", $receipt['branch_ID'], $receipt['order_date']);
    $stmt_items->execute();
    $order_result = $stmt_items->get_result();
    
    // --- ** NEW: Fetch all items into an array ** ---
    $order_items = [];
    $grand_total = 0;
    $grand_cost_total = 0;
    $grand_income_total = 0;

    if ($order_result && $order_result->num_rows > 0) {
        // Fetch all rows into an array first
        $order_items = $order_result->fetch_all(MYSQLI_ASSOC);
        
        // Calculate totals first
        foreach ($order_items as $item_row) {
            $subtotal = $item_row['subtotal'];
            $grand_total += $subtotal;
            
            $item_cost = $item_row['Cost'] ?? 0;
            $cost_subtotal = $item_cost * $item_row['quantity'];
            $income_subtotal = $subtotal - $cost_subtotal;
            $grand_cost_total += $cost_subtotal;
            $grand_income_total += $income_subtotal;
        }
    }
    if ($stmt_items ?? null) $stmt_items->close();
    // --- ** END: New fetch logic ** ---

}
// --- ARCHIVE VIEW LOGIC ---
else {
    $page_title = "Receipt Archive - Gab's Bakeshop"; // Specific title for archive view
    $archive_msg = "";
    if (isset($_SESSION['message'])) {
        $archive_msg = $_SESSION['message'];
        unset($_SESSION['message']);
    }

    // --- NEW: Filter & Pagination Logic ---
    $date_from = $_GET['date_from'] ?? '';
    $date_to = $_GET['date_to'] ?? '';
    $filter_applied = !empty($date_from) && !empty($date_to);

    $limit = 50; // 50 receipts per page
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    if ($page < 1) $page = 1;
    $offset = ($page - 1) * $limit;

    $where_clauses = [];
    $params = [];
    $types = "";

    // *** PATCHED: Add status filter for calculation ***
    // This ensures we only sum items that are part of the sale.
    $where_clauses[] = "o.status IN ('Approved', 'Delivered')";

    // Role-based filter
    if ($user_role === 'Branch') {
        $where_clauses[] = "r.branch_ID = ?";
        $params[] = $user_id;
        $types .= "i";
    }

    // Date filter (filters by receipt_date)
    if ($filter_applied) {
        $date_from_sql = date('Y-m-d 00:00:00', strtotime($date_from));
        $date_to_sql = date('Y-m-d 23:59:59', strtotime($date_to));
        $where_clauses[] = "r.receipt_date BETWEEN ? AND ?";
        $params[] = $date_from_sql;
        $params[] = $date_to_sql;
        $types .= "ss";
    }

    // --- Build WHERE string ---
    $sql_where = "";
    if (!empty($where_clauses)) {
        $sql_where = " WHERE " . implode(" AND ", $where_clauses);
    }

    // --- 1. Get TOTAL count for pagination ---
    // *** MODIFIED: Must join orders to filter by status before counting ***
    $sql_count = "SELECT COUNT(DISTINCT r.receipt_ID) 
                  FROM receipt r 
                  LEFT JOIN orders o ON r.branch_ID = o.branch_ID AND r.order_date = o.order_date
                  " . $sql_where;
    $stmt_count = $conn->prepare($sql_count);
    if (!$stmt_count) {
         error_log("Error preparing count query: " . $conn->error);
         $total_results = 0;
         $total_pages = 0;
    } else {
    if (!empty($params)) {
        $stmt_count->bind_param($types, ...$params);
    }
    $stmt_count->execute();
    $total_results = $stmt_count->get_result()->fetch_row()[0];
    $total_pages = ceil($total_results / $limit);
    $stmt_count->close();
    }

    // --- 2. Get paginated RESULTS ---
    // *** PATCHED: This query now calculates the total live from Approved/Delivered orders only ***
    $sql_archive = "SELECT r.receipt_ID, a.name AS branch_name, r.order_date, 
                           SUM(o.quantity * p.price) AS total_amount, -- <-- PATCHED: Calculate live total
                           u.username AS approved_by_name, r.receipt_date
                    FROM receipt r
                    JOIN accounts a ON r.branch_ID = a.user_ID
                    JOIN accounts u ON r.approved_by = u.user_ID
                    -- PATCHED: Join orders and products to calculate live total
                    LEFT JOIN orders o ON r.branch_ID = o.branch_ID AND r.order_date = o.order_date
                    LEFT JOIN products p ON o.product_ID = p.product_ID
                    $sql_where
                    GROUP BY r.receipt_ID, a.name, r.order_date, u.username, r.receipt_date -- <-- PATCHED: Add GROUP BY
                    ORDER BY r.receipt_date DESC
                    LIMIT ? OFFSET ?";
    
    // Add LIMIT and OFFSET to params
    $params[] = $limit;
    $params[] = $offset;
    $types .= "ii";

    $stmt_archive = $conn->prepare($sql_archive);
     if (!$stmt_archive) {
         error_log("Error preparing archive query: " . $conn->error);
         $archive_result = false;
    } else {
    if (!empty($params)) {
        $stmt_archive->bind_param($types, ...$params);
     }
    $stmt_archive->execute();
    $archive_result = $stmt_archive->get_result();
    }
    // Close later
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <!-- ****** RESPONSIVE: Added Viewport Meta Tag ****** -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?></title>
    <link rel="stylesheet" href="receipt.css">
</head>
<body>

    <!-- NEW: PDF Loader HTML -->
    <div class="loader-overlay" id="loader-overlay">
        <div class="loader-spinner"></div>
        <p>Generating PDF, please wait...</p>
    </div>

    <!-- ****** EMBEDDED Navbar HTML ****** -->
    <?php include __DIR__ . '/includes/navbar.php'; ?>

<div class="page-content">

        <!-- ** ADDED Sub-navbar for Reports Section ** -->
        <h1>System Reports Dashboard</h1>
        <div class="sub-nav">
            <?php if (in_array($user_role, ['Admin', 'Moderator'])): ?>
                <a href="reports.php" class="<?= ($current_page == 'reports.php') ? 'active-tab' : '' ?>">Sales Reports</a>
                <a href="ingredienthistory.php" class="<?= ($current_page == 'ingredienthistory.php') ? 'active-tab' : '' ?>">Ingredient Audit</a>
                <a href="producthistory.php" class="<?= ($current_page == 'producthistory.php') ? 'active-tab' : '' ?>">Product Audit</a>
            <?php endif; ?>
             <?php // Receipt link visible to Branch too ?>
            <a href="receipt.php" class="<?= ($current_page == 'receipt.php') ? 'active-tab' : '' ?>">Delivery Receipts</a>
        </div>
        <!-- ** END Sub-navbar ** -->


        <?php // --- ARCHIVE VIEW --- ?>
        <?php if (!$is_detail_view): ?>
            <h2>Receipt Archive</h2>

            <?php if (!empty($archive_msg)) echo $archive_msg; ?>

            <!-- --- NEW: Filter Form --- -->
            <form method="GET" action="receipt.php" class="filter-form">
                <div>
                    <label for="date_from">Date From:</label>
                    <input type="date" id="date_from" name="date_from" value="<?= htmlspecialchars($date_from) ?>" >
                </div>
                <div>
                    <label for="date_to">Date To:</label>
                    <input type="date" id="date_to" name="date_to" value="<?= htmlspecialchars($date_to) ?>" >
                </div>
                <button type="submit" class="btn" style="background-color: #5F8B4C;">Apply Filter</button>
                <a href="receipt.php" class="btn" style="background-color: #6c757d;">Reset</a>
            </form>

            <?php if ($filter_applied): ?>
                <p style="text-align: center; font-weight: bold;">
                    Showing results for receipts issued from <?= htmlspecialchars($date_from) ?> to <?= htmlspecialchars($date_to) ?>.
                </p>
            <?php endif; ?>
            <!-- --- END: Filter Form --- -->

             <!-- Responsive Table Container -->
             <div class="table-container">
                <table class="archive-table">
                    <thead>
                        <tr>
                            <th>Receipt ID</th>
                            <th>Branch</th>
                            <th>Order Date</th>
                            <th>Total Amount</th>
                            <th>Approved By</th>
                            <th>Date Issued</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($archive_result && $archive_result->num_rows > 0): ?>
                            <?php while ($row = $archive_result->fetch_assoc()): ?>
                                <tr>
                                    <td><?= intval($row['receipt_ID']) ?></td>
                                    <td><?= htmlspecialchars($row['branch_name']) ?></td>
                                    <td><?= htmlspecialchars(date('M j, Y g:i A', strtotime($row['order_date']))) ?></td>
                                    <td>₱<?= number_format($row['total_amount'], 2) ?></td>
                                    <td><?= htmlspecialchars($row['approved_by_name']) ?></td>
                                    <td><?= htmlspecialchars(date('M j, Y g:i A', strtotime($row['receipt_date']))) ?></td>
                                    <td class="action-cell">
                                        <a class="btn" href="receipt.php?receipt_id=<?= intval($row['receipt_ID']) ?>" style="background-color: #3498db;">View</a>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="7">No receipts found<?php if($filter_applied) echo " for the selected date range"; ?>.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($stmt_archive ?? null) $stmt_archive->close(); ?>

            <!-- --- NEW: Pagination Links --- -->
            <div class="pagination">
                <?php
                // --- Build query string for pagination links ---
                $query_params = [];
                if ($filter_applied) {
                    $query_params['date_from'] = $date_from;
                    $query_params['date_to'] = $date_to;
                }

                // Previous Button
                if ($page > 1) {
                    $query_params['page'] = $page - 1;
                    echo '<a href="receipt.php?' . http_build_query($query_params) . '">&laquo; Previous</a>';
                } else {
                    echo '<span class="disabled">&laquo; Previous</span>';
                }

                // Page Numbers (simplified example)
                // Let's show first, last, and a window around the current
                $window = 2;
                for ($i = 1; $i <= $total_pages; $i++) {
                    if ($i == 1 || $i == $total_pages || ($i >= $page - $window && $i <= $page + $window)) {
                        if ($i == $page) {
                            echo '<span class="current">' . $i . '</span>';
                        } else {
                            $query_params['page'] = $i;
                            echo '<a href="receipt.php?' . http_build_query($query_params) . '">' . $i . '</a>';
                        }
                    } elseif ($i == $page - $window - 1 || $i == $page + $window + 1) {
                        echo '<span>...</span>'; // Add ellipsis
                    }
                }

                // Next Button
                if ($page < $total_pages) {
                    $query_params['page'] = $page + 1;
                    echo '<a href="receipt.php?' . http_build_query($query_params) . '">Next &raquo;</a>';
                } else {
                    echo '<span class="disabled">Next &raquo;</span>';
                }
                ?>
            </div>
            <!-- --- END: Pagination Links --- -->


        <?php // --- DETAIL VIEW --- ?>
        <?php else: ?>
            <div class="receipt-detail">
                <h2>Gab's Bakeshop - Official Receipt</h2>

                <!-- ** REVERTED: Block 1 for Receipt Info (now just <p> tags) ** -->
                <p><strong>Receipt ID:</strong> <?= intval($receipt['receipt_ID']); ?></p>
                <p><strong>Branch:</strong> <?= htmlspecialchars($receipt['branch_name']); ?></p>
                <p><strong>Location:</strong> <?= htmlspecialchars($receipt['Location'] ?? 'N/A'); ?></p>
                <p><strong>Order Date:</strong> <?= htmlspecialchars(date('F j, Y g:i A', strtotime($receipt['order_date']))); ?></p>
                <p><strong>Approved By:</strong> <?= htmlspecialchars($receipt['approved_by_name']); ?></p>
                <p><strong>Receipt Date:</strong> <?= htmlspecialchars(date('F j, Y g:i A', strtotime($receipt['receipt_date']))); ?></p>


                <!-- ** NEW: Side-by-side table layout ** -->
                <div class="tables-layout">
                    
                    <!-- Table 1: Public Order Items -->
                    <div class="public-table-block">
                        <h3>Order Items</h3>
                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Product</th>
                                        <th>Price</th>
                                        <th>Qty</th>
                                        <th>Subtotal</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (empty($order_items)): ?>
                                    <tr><td colspan="4">No items found for this order batch.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($order_items as $item_row): ?>
                                    <tr>
                                        <td style="text-align: left;"><?= htmlspecialchars($item_row['product_name']); ?></td>
                                        <td>₱<?= number_format($item_row['price'], 2); ?></td>
                                        <td><?= intval($item_row['quantity']); ?></td>
                                        <td style="text-align: right;">₱<?= number_format($item_row['subtotal'], 2); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <td colspan="3" style="text-align: right;">Grand Total:</td>
                                        <td style="text-align: right;">₱<?= number_format($grand_total, 2); ?></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div> <!-- end .public-table-block -->

                    <!-- Table 2: Admin-Only Financial Summary -->
                    <?php if (in_array($user_role, ['Admin', 'Moderator'])): ?>
                    <div class="admin-table-block" id="admin-financial-table">
                        <h3>Financial Summary</h3>
                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Product</th>
                                        <th>Unit Cost</th>
                                        <th>Total Cost</th>
                                        <th>Total Income</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (empty($order_items)): ?>
                                    <tr><td colspan="4">No items to calculate.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($order_items as $item_row): 
                                        $item_cost = $item_row['Cost'] ?? 0;
                                        $cost_subtotal = $item_cost * $item_row['quantity'];
                                        $income_subtotal = $item_row['subtotal'] - $cost_subtotal;
                                    ?>
                                    <tr>
                                        <td style="text-align: left;"><?= htmlspecialchars($item_row['product_name']); ?></td>
                                        <td>₱<?= number_format($item_cost, 2); ?></td>
                                        <td style="text-align: right;">₱<?= number_format($cost_subtotal, 2); ?></td>
                                        <td style="text-align: right;">₱<?= number_format($income_subtotal, 2); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <td colspan="2" style="text-align: right;">Cost Total:</td>
                                        <td style="text-align: right;">₱<?= number_format($grand_cost_total, 2); ?></td>
                                        <td></td>
                                    </tr>
                                    <tr>
                                        <td colspan="3" style="text-align: right;">Income Total:</td>
                                        <td style="text-align: right;">₱<?= number_format($grand_income_total, 2); ?></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div> <!-- end .admin-table-block -->
                    <?php endif; ?>

                </div> <!-- ** END: .tables-layout ** -->


                 <!-- ** NEW: Wrapper div for proof of delivery (MOVED) ** -->
                 <div id="proof-of-delivery-section">
                     <?php // --- Display Proof Image ---
                     $image_path = $receipt['receipt_image_path'] ?? null;
                     if (!empty($image_path) && file_exists($image_path)): ?>
                         <h3>Delivery Proof</h3>
                         <div class="proof-image">
                             <a href="<?= htmlspecialchars($image_path) ?>" target="_blank" title="View full image">
                                 <img src="<?= htmlspecialchars($image_path) ?>" alt="Delivery Proof for Receipt <?= intval($receipt['receipt_ID']) ?>">
                             </a>
                         </div>
                     <?php elseif (!empty($image_path)): ?>
                         <p style="color: red;">Proof image recorded (<?= htmlspecialchars($image_path) ?>) but file not found on server.</p>
                     <?php else: ?>
                        <p><em>No delivery proof image uploaded for this receipt.</em></p>
                     <?php endif; ?>
                 </div> <!-- ** END: Wrapper div ** -->

                <div class="receipt-actions">
                    <!-- ** MODIFIED: Replaced CSV button with PDF button ** -->
                    <button class="btn" id="download-pdf-btn" style="background-color: #d9534f;">Download as PDF</button>
                    <a class="btn" href="receipt.php" style="background-color: #6c757d;">Back to Archive</a>
                </div>
            </div>
        <?php endif; // End Detail View ?>

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

    <!-- ****** NEW: jsPDF and html2canvas for PDF Generation ****** -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js" crossorigin="anonymous" referrerpolicy="no-referrer"></script>

    <script>
        <?php if ($is_detail_view): // Only add this script on the detail page ?>
        document.addEventListener('DOMContentLoaded', function() {
            const pdfButton = document.getElementById('download-pdf-btn');
            const loader = document.getElementById('loader-overlay');
            const receiptElement = document.querySelector('.receipt-detail');
            
            // ** MODIFIED: Get elements to hide **
            const receiptActions = receiptElement ? receiptElement.querySelector('.receipt-actions') : null;
            const proofElement = receiptElement ? document.getElementById('proof-of-delivery-section') : null;
            // ** NEW: Get the entire admin table block **
            const adminTable = receiptElement ? document.getElementById('admin-financial-table') : null;


            if (pdfButton && receiptElement && receiptActions) {
                pdfButton.addEventListener('click', function() {
                    
                    if(loader) loader.style.display = 'flex'; // Show loader

                    // ** NEW: Hide elements before screenshot **
                    receiptActions.style.display = 'none';
                    if (proofElement) {
                        proofElement.style.display = 'none';
                    }
                    // ** NEW: Hide the entire admin table **
                    if (adminTable) {
                        adminTable.style.display = 'none';
                    }

                    // Use html2canvas to capture the receipt element
                    html2canvas(receiptElement, {
                        scale: 2 // Increase scale for better resolution
                    }).then(function(canvas) {
                        const imgData = canvas.toDataURL('image/png');
                        
                        // ** RESTORED: PDF Generation Logic **
                        const { jsPDF } = window.jspdf;
                        
                        // A4 paper dimensions: 210 x 297 mm
                        const pdfWidth = 210;
                        const pdfHeight = 297;
                        
                        // Calculate aspect ratio
                        const canvasWidth = canvas.width;
                        const canvasHeight = canvas.height;
                        const imgRatio = canvasWidth / canvasHeight;
                        
                        let imgWidth = pdfWidth - 20; // 10mm margins
                        let imgHeight = imgWidth / imgRatio;
                        
                        // Check if height exceeds page, adjust if necessary
                        if (imgHeight > pdfHeight - 20) {
                            imgHeight = pdfHeight - 20; // 10mm margins
                            imgWidth = imgHeight * imgRatio;
                        }

                        // Center the image
                        const x = (pdfWidth - imgWidth) / 2;
                        const y = 10; // 10mm top margin

                        const doc = new jsPDF('p', 'mm', 'a4');
                        doc.addImage(imgData, 'PNG', x, y, imgWidth, imgHeight);
                        
                        // Generate filename
                        const receiptId = <?= intval($receipt_id); ?>;
                        const branchName = "<?= htmlspecialchars(addslashes($receipt['branch_name'] ?? 'Receipt')); ?>".replace(/ /g, '_');
                        const fileName = `Receipt-${receiptId}-${branchName}.pdf`;

                        doc.save(fileName);
                        // ** END: Restored Logic **
                        
                        if(loader) loader.style.display = 'none'; // Hide loader

                        // ** NEW: Show elements again **
                        receiptActions.style.display = 'block';
                        if (proofElement) {
                            proofElement.style.display = 'block';
                        }
                        // ** NEW: Show the admin table again **
                        if (adminTable) {
                            adminTable.style.display = 'block';
                        }

                    }).catch(function(error) {
                        console.error('Error generating PDF:', error);
                        // Hide loader on error
                        if (loader) {
                            loader.style.display = 'none';
                        }
                        
                        // ** NEW: Show elements again, even on error **
                        receiptActions.style.display = 'block';
                        if (proofElement) {
                            proofElement.style.display = 'block';
                        }
                        // ** NEW: Show the admin table again on error **
                        if (adminTable) {
                            adminTable.style.display = 'block';
                        }

                        alert('Sorry, there was an error generating the PDF. Please try again.');
                    });
                });
            }
        });
        <?php endif; ?>
    </script>

</body>
</html>