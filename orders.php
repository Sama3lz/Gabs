<?php
session_start();
include("gabsdb.php"); // Includes the database connection ($conn)
require_once __DIR__ . "/includes/auth_helpers.php";

function isValidBatchDate($value) {
    return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}:\d{2})?$/', $value);
}

function fetchOrdersForBatches($conn, $batches) {
    $orders = [];
    if (empty($batches)) {
        return $orders;
    }

    $batch_clauses = [];
    $batch_params = [];
    $batch_types = "";
    foreach ($batches as $batch) {
        if (!isset($batch['branch_ID'], $batch['order_date'])) {
            continue;
        }
        $batch_clauses[] = "(o.branch_ID = ? AND o.order_date = ?)";
        $batch_params[] = (int)$batch['branch_ID'];
        $batch_params[] = $batch['order_date'];
        $batch_types .= "is";
    }

    if (empty($batch_clauses)) {
        return $orders;
    }

    $sql_orders = "SELECT
                       o.order_ID, o.branch_ID, o.order_date, o.product_ID, o.quantity,
                       (o.quantity * p.price) AS total_price, o.status,
                       p.product_name, p.stock
                   FROM orders o
                   JOIN products p ON o.product_ID = p.product_ID
                   WHERE " . implode(" OR ", $batch_clauses) . "
                   ORDER BY o.order_ID ASC";

    $stmt_orders = $conn->prepare($sql_orders);
    if (!$stmt_orders) {
        return $orders;
    }
    $stmt_orders->bind_param($batch_types, ...$batch_params);
    $stmt_orders->execute();
    $orders_result = $stmt_orders->get_result();
    if ($orders_result) {
        while ($order_row = $orders_result->fetch_assoc()) {
            $batch_key = $order_row['branch_ID'] . "_" . $order_row['order_date'];
            if (!isset($orders[$batch_key])) {
                $orders[$batch_key] = [];
            }
            $orders[$batch_key][] = $order_row;
        }
    }
    $stmt_orders->close();

    return $orders;
}

// --- AJAX Handler for fetching products (must be before any HTML output) ---
if (isset($_GET['action']) && $_GET['action'] === 'fetch_products') {
    // Check if user is logged in and is a Branch
    if (!isset($_SESSION['user_ID']) || $_SESSION['role'] !== 'Branch') {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Unauthorized']);
        exit();
    }
    
    // Fetch all products with their prices
    $sql = "SELECT product_ID, product_name, price FROM products ORDER BY product_name";
    $result = $conn->query($sql);
    
    $products = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $products[] = [
                'product_ID' => $row['product_ID'],
                'product_name' => $row['product_name'],
                'price' => floatval($row['price'])
            ];
        }
    }
    
    header('Content-Type: application/json');
    echo json_encode(['products' => $products]);
    exit(); // Stop execution after sending JSON
}

// --- NEW: AJAX Handler for fetching live order data ---
if (isset($_GET['action']) && $_GET['action'] === 'fetch_orders') {
    if (!isset($_SESSION['user_ID'])) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Unauthorized']);
        exit();
    }
    
    $user_id = $_SESSION['user_ID'];
    $user_role = $_SESSION['role'];
    $status_filter = $_GET['status'] ?? 'all';
    $branch_filter = $_GET['branch'] ?? 'all';
    
    // Build WHERE clauses
    $where_clauses = [];
    $params = [];
    $types = "";
    
    if ($user_role === 'Branch') {
        $where_clauses[] = "o.branch_ID = ?";
        $params[] = $user_id;
        $types .= "i";
    } elseif ($user_role === 'Delivery') {
        $where_clauses[] = "o.status IN ('Approved', 'Delivered')";
    } elseif ($branch_filter !== 'all') {
        $where_clauses[] = "o.branch_ID = ?";
        $params[] = (int)$branch_filter;
        $types .= "i";
    }
    
    // Status filtering
    switch ($status_filter) {
        case 'approved':
            $where_clauses[] = "o.status = 'Approved'";
            break;
        case 'delivered':
            $where_clauses[] = "o.status = 'Delivered'";
            break;
        case 'denied':
            $where_clauses[] = "o.status = 'Denied'";
            break;
        case 'cancelled':
            $where_clauses[] = "o.status = 'Cancelled'";
            break;
        case 'pending':
            $where_clauses[] = "o.status = 'Pending'";
            break;
    }
    
    $sql_where = "";
    if (!empty($where_clauses)) {
        $sql_where = " WHERE " . implode(" AND ", $where_clauses);
    }
    
    // Get batches
    $sql_batches = "SELECT 
                        o.branch_ID, 
                        MAX(a.name) AS branch_name, 
                        o.order_date,
                        SUM(o.quantity * p.price) AS batch_total,
                        MAX(CASE WHEN o.status = 'Pending' THEN 1 ELSE 0 END) AS is_batch_pending,
                        MAX(CASE WHEN o.status = 'Approved' THEN 1 ELSE 0 END) AS has_approved,
                        MIN(CASE WHEN o.status IN ('Delivered', 'Denied', 'Cancelled') THEN 1 ELSE 0 END) AS all_items_delivered,
                        MAX(r.receipt_ID) AS receipt_ID
                    FROM orders o
                    JOIN accounts a ON o.branch_ID = a.user_ID
                    JOIN products p ON o.product_ID = p.product_ID
                    LEFT JOIN receipt r ON o.branch_ID = r.branch_ID AND o.order_date = r.order_date
                    $sql_where
                    GROUP BY o.branch_ID, o.order_date
                    ORDER BY o.order_date DESC
                    LIMIT 10";
    
    $stmt_batches = $conn->prepare($sql_batches);
    if (!empty($params)) {
        $stmt_batches->bind_param($types, ...$params);
    }
    $stmt_batches->execute();
    $batches_result = $stmt_batches->get_result();
    
    $batches = [];
    while ($batch_row = $batches_result->fetch_assoc()) {
        $batches[] = $batch_row;
    }
    $stmt_batches->close();
    
    // Get order items for each batch
    $orders = fetchOrdersForBatches($conn, $batches);
    
    header('Content-Type: application/json');
    echo json_encode([
        'batches' => $batches,
        'orders' => $orders,
        'user_role' => $user_role,
        'current_time' => date('Y-m-d H:i:s')
    ]);
    exit();
}

// --- ACCESS CONTROL ---
require_login(['Admin', 'Moderator', 'Branch', 'Delivery']);

$user_id = $_SESSION['user_ID'];
$user_role = $_SESSION['role'];
$current_page = basename($_SERVER['PHP_SELF']); // For active navbar link

// --- Page Variables & Navbar Logic ---
$page_title = "Manage Orders - Gab's Bakeshop";
$nav = get_nav_permissions($user_role);
$show_inventory_link = $nav['show_inventory_link'];
$show_reports_link = $nav['show_reports_link'];
$show_accounts_link = $nav['show_accounts_link'];
$show_orders_link = $nav['show_orders_link'];
$show_settings_link = $nav['show_settings_link'];

// --- NOTIFICATION & ERROR HANDLING ---
$message = ''; // For success/error messages
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    unset($_SESSION['message']);
}

// --- PHP ACTIONS (POST Request Handling) ---
// This block handles all form submissions (Approve, Deny, etc.)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($conn)) {
    
    // DEBUG: Log what action was attempted
    error_log("POST REQUEST RECEIVED - keys: " . implode(',', array_keys($_POST)));
    error_log("POST REQUEST FILE COUNT: " . count($_FILES));
    error_log("User Role: " . $user_role);
    
    try {
        $conn->begin_transaction(); // Start transaction for all actions

        // --- ACTION 1: Place Batch Order (Branch) ---
        if (isset($_POST['place_batch_order']) && $user_role === 'Branch') {
            error_log("ACTION 1: Place Batch Order");
            $items_json = $_POST['order_items_json'] ?? '';
            $items = json_decode($items_json, true);
            $order_date = date('Y-m-d H:i:s'); // Use current time as the batch identifier

            if (empty($items)) {
                throw new Exception("Cannot place an order with no items.");
            }

            $stmt = $conn->prepare("INSERT INTO orders (branch_ID, product_ID, quantity, order_date, status) VALUES (?, ?, ?, ?, 'Pending')");
            if (!$stmt) {
                 throw new Exception("Database prepare error: " . $conn->error);
            }
            
            foreach ($items as $item) {
                $product_id = (int)$item['id'];
                $quantity = (int)$item['quantity'];

                $stmt->bind_param("iiis", $user_id, $product_id, $quantity, $order_date);
                if(!$stmt->execute()) {
                    throw new Exception("Failed to insert order item: " . $stmt->error);
                }
            }
            $stmt->close();
            
            $conn->commit();
            $_SESSION['message'] = "<div class='message success'>✅ Batch order placed successfully!</div>";
            header("Location: orders.php");
            exit();
        }

        // --- ACTION 2: Approve Single Order (Admin/Moderator) ---
        if (isset($_POST['approve_order']) && in_array($user_role, ['Admin', 'Moderator'])) {
            error_log("ACTION 2: Approve Single Order - Order ID: " . ($_POST['order_ID'] ?? 'missing'));
            $order_ID = (int)($_POST['order_ID'] ?? 0);
            if ($order_ID <= 0) {
                throw new Exception("Invalid order ID.");
            }

            // 1. Get order details and lock the row
            $order_stmt = $conn->prepare("SELECT product_ID, quantity FROM orders WHERE order_ID = ? AND status = 'Pending' FOR UPDATE");
            $order_stmt->bind_param("i", $order_ID);
            $order_stmt->execute();
            $order_result = $order_stmt->get_result();
            $order = $order_result->fetch_assoc();
            $order_stmt->close();

            if (!$order) {
                throw new Exception("Order not found or is not pending.");
            }

            $product_ID = $order['product_ID'];
            $quantity = $order['quantity'];

            // 2. Check stock and lock the product row
            $stock_stmt = $conn->prepare("SELECT stock FROM products WHERE product_ID = ? FOR UPDATE");
            $stock_stmt->bind_param("i", $product_ID);
            $stock_stmt->execute();
            $stock_result = $stock_stmt->get_result();
            $product = $stock_result->fetch_assoc();
            $stock_stmt->close();

            if (!$product) {
                throw new Exception("Product not found.");
            }

            $current_stock = $product['stock'];

            // 3. Update stock
            $new_stock = $current_stock - $quantity;
            $update_stock_stmt = $conn->prepare("UPDATE products SET stock = ? WHERE product_ID = ?");
            $update_stock_stmt->bind_param("ii", $new_stock, $product_ID);
            $update_stock_stmt->execute();
            $update_stock_stmt->close();

            // 4. Update order status
            $update_order_stmt = $conn->prepare("UPDATE orders SET status = 'Approved' WHERE order_ID = ?");
            $update_order_stmt->bind_param("i", $order_ID);
            $update_order_stmt->execute();
            $update_order_stmt->close();

            // 5. Add to product history
            $history_stmt = $conn->prepare("INSERT INTO product_history (product_ID, user_ID, action, old_stock, new_stock, action_date) VALUES (?, ?, 'Order Approved', ?, ?, NOW())");
            $history_stmt->bind_param("iiii", $product_ID, $user_id, $current_stock, $new_stock);
            $history_stmt->execute();
            $history_stmt->close();
            
            if ($batch_info) {
                createOrLinkReceiptForBatch($conn, $batch_info['branch_ID'], $batch_info['order_date'], $user_id);
            }

            $conn->commit();
            error_log("Order #$order_ID approved successfully");
            $_SESSION['message'] = "<div class='message success'>✅ Order #{$order_ID} approved and stock updated.</div>";
            header("Location: orders.php");
            exit();
        }

        // --- ACTION 3: Approve Batch Order (Admin/Moderator) ---
        if (isset($_POST['approve_batch']) && in_array($user_role, ['Admin', 'Moderator'])) {
            error_log("ACTION 3: Approve Batch - Branch: " . ($_POST['batch_branch_id'] ?? 'missing') . ", Date: " . ($_POST['batch_date'] ?? 'missing'));
            $batch_branch_id = (int)($_POST['batch_branch_id'] ?? 0);
            $batch_date = trim($_POST['batch_date'] ?? '');
            if ($batch_branch_id <= 0 || !isValidBatchDate($batch_date)) {
                throw new Exception("Invalid batch details.");
            }
            
            // 1. Find all 'Pending' orders in the batch and lock them
            $find_stmt = $conn->prepare("SELECT o.order_ID, o.product_ID, o.quantity, p.stock, p.product_name 
                                         FROM orders o 
                                         JOIN products p ON o.product_ID = p.product_ID 
                                         WHERE o.branch_ID=? AND o.order_date=? AND o.status='Pending' 
                                         FOR UPDATE");
            if (!$find_stmt) {
                throw new Exception("Prepare failed (find_stmt): " . $conn->error);
            }
            $find_stmt->bind_param("is", $batch_branch_id, $batch_date);
            $find_stmt->execute();
            $pending_orders = $find_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $find_stmt->close();

            if (empty($pending_orders)) {
                throw new Exception("No pending orders found for this batch to approve.");
            }

            error_log("Found " . count($pending_orders) . " pending orders in batch");

            $order_ids_to_approve = [];
            $product_stock_changes = [];

            // 2. Prepare stock changes
            foreach ($pending_orders as $order) {
                $product_ID = $order['product_ID'];
                $quantity_needed = $order['quantity'];

                $stock_stmt = $conn->prepare("SELECT stock FROM products WHERE product_ID = ? FOR UPDATE");
                if (!$stock_stmt) {
                    throw new Exception("Prepare failed (stock_stmt): " . $conn->error);
                }
                $stock_stmt->bind_param("i", $product_ID);
                $stock_stmt->execute();
                $product = $stock_stmt->get_result()->fetch_assoc();
                $stock_stmt->close();
                
                if (!$product) {
                     throw new Exception("Product '{$order['product_name']}' (ID: {$product_ID}) not found.");
                }

                $current_stock = $product['stock'];

                $order_ids_to_approve[] = $order['order_ID'];
                
                if (!isset($product_stock_changes[$product_ID])) {
                    $product_stock_changes[$product_ID] = [
                        'total_qty' => 0,
                        'old_stock' => $current_stock
                    ];
                }
                $product_stock_changes[$product_ID]['total_qty'] += $quantity_needed;
            }

            // 3. Update all product stocks and create history logs
            $update_stock_stmt = $conn->prepare("UPDATE products SET stock = stock - ? WHERE product_ID = ?");
            $history_stmt = $conn->prepare("INSERT INTO product_history (product_ID, user_ID, action, old_stock, new_stock, action_date) VALUES (?, ?, 'Batch Order Approved', ?, ?, NOW())");
            
            foreach ($product_stock_changes as $product_ID => $change_data) {
                $total_quantity_deducted = $change_data['total_qty'];
                $old_stock = $change_data['old_stock'];
                
                if (!$update_stock_stmt) {
                    throw new Exception("Prepare failed (update_stock_stmt): " . $conn->error);
                }
                $update_stock_stmt->bind_param("ii", $total_quantity_deducted, $product_ID);
                $update_stock_stmt->execute();
                
                $stock_stmt = $conn->prepare("SELECT stock FROM products WHERE product_ID = ?");
                if (!$stock_stmt) {
                    throw new Exception("Prepare failed (stock_stmt 2): " . $conn->error);
                }
                $stock_stmt->bind_param("i", $product_ID);
                $stock_stmt->execute();
                $new_stock = $stock_stmt->get_result()->fetch_row()[0];
                $stock_stmt->close();
                
                if (!$history_stmt) {
                    throw new Exception("Prepare failed (history_stmt): " . $conn->error);
                }
                $history_stmt->bind_param("iiii", $product_ID, $user_id, $old_stock, $new_stock);
                $history_stmt->execute();
            }
            $update_stock_stmt->close();
            $history_stmt->close();

            // 4. Update all order statuses
            if (!empty($order_ids_to_approve)) {
                $ids_placeholder = implode(',', array_fill(0, count($order_ids_to_approve), '?'));
                $types = str_repeat('i', count($order_ids_to_approve));
                
                $approve_stmt = $conn->prepare("UPDATE orders SET status='Approved' WHERE order_ID IN ($ids_placeholder) AND status='Pending'");
                if (!$approve_stmt) {
                    throw new Exception("Prepare failed (approve_stmt): " . $conn->error);
                }
                $approve_stmt->bind_param($types, ...$order_ids_to_approve);
                $approve_stmt->execute();
                $approve_stmt->close();
            }
            
            // 5. Create or link the receipt
            createOrLinkReceiptForBatch($conn, $batch_branch_id, $batch_date, $user_id);

            $conn->commit();
            error_log("Batch approved successfully");
            $_SESSION['message'] = "<div class='message success'>✅ Batch approved and all stocks updated.</div>";
            header("Location: orders.php");
            exit();
        }

        // --- ACTION 4: Deliver Batch (Admin/Delivery) ---
        // --- ACTION 4: Deliver Batch (Admin/Delivery) ---
        if (isset($_POST['deliver_batch']) && in_array($user_role, ['Admin', 'Delivery'])) {
        error_log("ACTION 4: Deliver Batch - Branch: " . ($_POST['batch_branch_id'] ?? 'missing') . ", Date: " . ($_POST['batch_date'] ?? 'missing'));
        error_log("FILES: " . print_r($_FILES, true));
            
            $batch_branch_id = (int)($_POST['batch_branch_id'] ?? 0);
            $batch_date = trim($_POST['batch_date'] ?? '');
            if ($batch_branch_id <= 0 || !isValidBatchDate($batch_date)) {
                throw new Exception("Invalid batch details.");
            }
            
            // REQUIRE proof image upload
            if (!isset($_FILES['delivery_proof']) || $_FILES['delivery_proof']['error'] != 0) {
                throw new Exception("Proof of delivery image is required. Please upload a photo before marking as delivered.");
            }
            
            // Handle file upload for proof
            $image_path = null;
            if (isset($_FILES['delivery_proof']) && $_FILES['delivery_proof']['error'] == 0) {
                error_log("File upload detected, processing...");
                $image_path = handleProofUpload($_FILES['delivery_proof'], $batch_branch_id, $batch_date);
                if ($image_path === false) {
                     throw new Exception("File upload failed.");
                } else {
                    error_log("File uploaded successfully to: " . $image_path);
                }
            } else if (isset($_FILES['delivery_proof']) && $_FILES['delivery_proof']['error'] != 4) {
                 error_log("File upload error code: " . $_FILES['delivery_proof']['error']);
                 // Don't throw exception for file upload errors - just log them
                 error_log("File upload skipped due to error, continuing with delivery...");
            } else {
                error_log("No file uploaded (optional)");
            }

            // 1. Update all 'Approved' orders to 'Delivered'
            $update_stmt = $conn->prepare("UPDATE orders SET status = 'Delivered' WHERE branch_ID = ? AND order_date = ? AND status = 'Approved'");
            if (!$update_stmt) {
                throw new Exception("Prepare failed (update_stmt): " . $conn->error);
            }
            $update_stmt->bind_param("is", $batch_branch_id, $batch_date);
            $update_stmt->execute();
            $affected_rows = $update_stmt->affected_rows;
            $update_stmt->close();
            
            error_log("Updated $affected_rows orders to Delivered status");
            
            // 2. Update the corresponding receipt with the proof image path
            if ($image_path) {
                $receipt_stmt = $conn->prepare("UPDATE receipt SET proof_image = ? WHERE branch_ID = ? AND order_date = ?");
                 if (!$receipt_stmt) {
                    throw new Exception("Prepare failed (receipt_stmt): " . $conn->error);
                }
                $receipt_stmt->bind_param("sis", $image_path, $batch_branch_id, $batch_date);
                $receipt_stmt->execute();
                $receipt_stmt->close();
                error_log("Receipt updated with proof image");
            }

            $conn->commit();
            $_SESSION['message'] = "<div class='message success'>✅ Batch marked as delivered.</div>";
            header("Location: orders.php");
            exit();
        }

        // --- ACTION 5: Deny Individual Order (Admin/Moderator) ---
        if (isset($_POST['deny_order']) && in_array($user_role, ['Admin', 'Moderator'])) {
            error_log("ACTION 5: Deny Order - Order ID: " . ($_POST['order_ID'] ?? 'missing'));
            $order_ID = (int)($_POST['order_ID'] ?? 0);
            if ($order_ID <= 0) {
                throw new Exception("Invalid order ID.");
            }

            $stmt = $conn->prepare("SELECT 1 FROM orders WHERE order_ID = ? AND status = 'Pending' FOR UPDATE");
            $stmt->bind_param("i", $order_ID);
            $stmt->execute();
            $result = $stmt->get_result();
            $stmt->close();
            
            if ($result->num_rows === 0) {
                 throw new Exception("Order not found or is not pending. Cannot deny.");
            }

            $update_stmt = $conn->prepare("UPDATE orders SET status = 'Denied' WHERE order_ID = ?");
            if (!$update_stmt) {
                throw new Exception("Prepare failed (update_stmt): " . $conn->error);
            }
            $update_stmt->bind_param("i", $order_ID);
            $update_stmt->execute();
            $update_stmt->close();

            $conn->commit();
            error_log("Order #$order_ID denied successfully");
            $_SESSION['message'] = "<div class='message success'>✅ Order #{$order_ID} has been denied.</div>";
            header("Location: orders.php");
            exit();
        }
        
        // --- ACTION 6: Deny Batch Order (Admin/Moderator) ---
        if (isset($_POST['deny_batch']) && in_array($user_role, ['Admin', 'Moderator'])) {
            error_log("ACTION 6: Deny Batch - Branch: " . ($_POST['batch_branch_id'] ?? 'missing') . ", Date: " . ($_POST['batch_date'] ?? 'missing'));
            $batch_branch_id = (int)($_POST['batch_branch_id'] ?? 0);
            $batch_date = trim($_POST['batch_date'] ?? '');
            if ($batch_branch_id <= 0 || !isValidBatchDate($batch_date)) {
                throw new Exception("Invalid batch details.");
            }
            
            $find_stmt = $conn->prepare("SELECT 1 FROM orders WHERE branch_ID=? AND order_date=? AND status='Pending' FOR UPDATE");
            $find_stmt->bind_param("is", $batch_branch_id, $batch_date);
            $find_stmt->execute();
            $find_stmt->close();

            $update_stmt = $conn->prepare("UPDATE orders SET status = 'Denied' WHERE branch_ID = ? AND order_date = ? AND status = 'Pending'");
            if (!$update_stmt) {
                throw new Exception("Prepare failed (update_stmt): " . $conn->error);
            }
            $update_stmt->bind_param("is", $batch_branch_id, $batch_date);
            $update_stmt->execute();
            $affected_rows = $update_stmt->affected_rows;
            $update_stmt->close();

            $conn->commit();
            error_log("Batch denied, $affected_rows orders updated");
            $_SESSION['message'] = "<div class='message success'>✅ All pending items in the batch have been denied.</div>";
            header("Location: orders.php");
            exit();
        }
        
        // --- ACTION 7: Cancel Batch Order (Branch) ---
        if (isset($_POST['cancel_batch']) && $user_role === 'Branch') {
            error_log("ACTION 7: Cancel Batch - Branch: " . ($_POST['batch_branch_id'] ?? 'missing') . ", Date: " . ($_POST['batch_date'] ?? 'missing'));
            $batch_branch_id = (int)($_POST['batch_branch_id'] ?? 0);
            $batch_date = trim($_POST['batch_date'] ?? '');
            if ($batch_branch_id <= 0 || !isValidBatchDate($batch_date)) {
                throw new Exception("Invalid batch details.");
            }

            if ($batch_branch_id !== $user_id) {
                 throw new Exception("You can only cancel your own batch orders.");
            }
            
            $find_stmt = $conn->prepare("SELECT 1 FROM orders WHERE branch_ID=? AND order_date=? AND status='Pending' FOR UPDATE");
            $find_stmt->bind_param("is", $batch_branch_id, $batch_date);
            $find_stmt->execute();
            $find_stmt->close();
            
            $update_stmt = $conn->prepare("UPDATE orders SET status = 'Cancelled' WHERE branch_ID = ? AND order_date = ? AND status = 'Pending'");
            if (!$update_stmt) {
                throw new Exception("Prepare failed (update_stmt): " . $conn->error);
            }
            $update_stmt->bind_param("is", $batch_branch_id, $batch_date);
            $update_stmt->execute();
            $affected_rows = $update_stmt->affected_rows;
            $update_stmt->close();

            $conn->commit();
            error_log("Batch cancelled, $affected_rows orders updated");
            $_SESSION['message'] = "<div class='message success'>✅ Your batch order has been cancelled.</div>";
            header("Location: orders.php");
            exit();
        }

        // If no action was matched, log it
        error_log("No action matched for POST request");
        $conn->commit();

    } catch (Exception $e) {
        $conn->rollback();
        error_log("ERROR in orders.php: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        $_SESSION['message'] = "<div class='message error'>❌ " . $e->getMessage() . "</div>";
        header("Location: orders.php");
        exit();
    }
}

// --- HELPER FUNCTION: Create or Link Receipt ---
function createOrLinkReceiptForBatch($conn, $batch_branch_id, $batch_date, $admin_user_id) {
    // 1. Check if a receipt *already* exists for this batch
    $find_receipt_stmt = $conn->prepare("SELECT receipt_ID FROM receipt WHERE branch_ID = ? AND order_date = ?");
    if (!$find_receipt_stmt) {
        throw new Exception("Prepare failed (find_receipt_stmt): " . $conn->error);
    }
    $find_receipt_stmt->bind_param("is", $batch_branch_id, $batch_date);
    $find_receipt_stmt->execute();
    $receipt_result = $find_receipt_stmt->get_result();
    $existing_receipt = $receipt_result->fetch_assoc();
    $find_receipt_stmt->close();

    // If receipt exists, we're done.
    if ($existing_receipt) {
        return $existing_receipt['receipt_ID'];
    }

    // 2. If not, create a new one. First, calculate the total_amount.
    $total_stmt = $conn->prepare("SELECT SUM(o.quantity * p.price) AS total_amount 
                                  FROM orders o
                                  JOIN products p ON o.product_ID = p.product_ID
                                  WHERE o.branch_ID = ? AND o.order_date = ? AND o.status IN ('Approved', 'Delivered')");
    if (!$total_stmt) {
        throw new Exception("Prepare failed (total_stmt): " . $conn->error);
    }
    $total_stmt->bind_param("is", $batch_branch_id, $batch_date);
    $total_stmt->execute();
    $total_result = $total_stmt->get_result()->fetch_assoc();
    $total_stmt->close();
    
    $total_amount = $total_result['total_amount'] ?? 0;

    // Get the Branch's name for the 'Names' column
    $name_stmt = $conn->prepare("SELECT name FROM accounts WHERE user_ID = ?");
    if (!$name_stmt) {
        throw new Exception("Prepare failed (name_stmt): " . $conn->error);
    }
    $name_stmt->bind_param("i", $batch_branch_id);
    $name_stmt->execute();
    $branch_name = $name_stmt->get_result()->fetch_row()[0];
    $name_stmt->close();

    if (empty($branch_name)) {
        throw new Exception("Could not find branch name for user_ID: $batch_branch_id");
    }

    // 3. Insert the new receipt
    $receipt_date = date('Y-m-d H:i:s'); // Now
    $insert_receipt_stmt = $conn->prepare("INSERT INTO receipt (branch_ID, order_date, total_amount, approved_by, receipt_date, Names) VALUES (?, ?, ?, ?, ?, ?)");
    if (!$insert_receipt_stmt) {
        throw new Exception("Prepare failed (insert_receipt_stmt): " . $conn->error);
    }
    $insert_receipt_stmt->bind_param("isdiss", $batch_branch_id, $batch_date, $total_amount, $admin_user_id, $receipt_date, $branch_name);
    $insert_receipt_stmt->execute();
    $new_receipt_ID = $insert_receipt_stmt->insert_id;
    $insert_receipt_stmt->close();

    return $new_receipt_ID;
}

// --- HELPER FUNCTION: Handle Proof Upload ---
function handleProofUpload($file, $branch_id, $order_date) {
    try {
        error_log("handleProofUpload called - Branch: $branch_id, Date: $order_date");
        error_log("File details: " . print_r($file, true));
        
        $target_dir = "uploads/receipt_proofs/" . date('Y/m/d') . "/";
        
        // Check if directory exists, create if not
        if (!is_dir($target_dir)) {
            error_log("Creating directory: $target_dir");
            if (!mkdir($target_dir, 0755, true)) {
                throw new Exception("Failed to create upload directory: $target_dir");
            }
        }
        
        // Verify directory is writable
        if (!is_writable($target_dir)) {
            throw new Exception("Upload directory is not writable: $target_dir");
        }

        $safe_order_date = preg_replace('/[^a-zA-Z0-9_-]/', '', str_replace([' ', ':'], '', $order_date));
        $file_extension = strtolower(pathinfo($file["name"], PATHINFO_EXTENSION));
        $safe_filename = "proof_batch_" . $branch_id . "_" . $safe_order_date . "_" . uniqid() . "." . $file_extension;
        $target_file = $target_dir . $safe_filename;
        
        error_log("Target file path: $target_file");

        // Check file type
        $allowed_types = ['jpg', 'jpeg', 'png', 'gif'];
        if (!in_array($file_extension, $allowed_types)) {
            throw new Exception("Sorry, only JPG, JPEG, PNG, & GIF files are allowed. Got: $file_extension");
        }

        // Check file size (e.g., 5MB limit)
        if ($file["size"] > 5000000) {
            throw new Exception("Sorry, your file is too large (5MB limit). Size: " . $file["size"]);
        }
        
        // Verify the uploaded file exists
        if (!file_exists($file["tmp_name"])) {
            throw new Exception("Uploaded file does not exist at: " . $file["tmp_name"]);
        }

        // Attempt to move file
        if (move_uploaded_file($file["tmp_name"], $target_file)) {
            error_log("File uploaded successfully to: $target_file");
            // Verify file was actually created
            if (file_exists($target_file)) {
                error_log("File verified to exist at destination");
                return $target_file;
            } else {
                throw new Exception("File move reported success but file does not exist at destination");
            }
        } else {
            throw new Exception("move_uploaded_file() failed. Check directory permissions.");
        }
    } catch (Exception $e) {
        error_log("Upload error: " . $e->getMessage());
        $_SESSION['message'] = "<div class='message error'>❌ Upload Failed: " . $e->getMessage() . "</div>";
        return false;
    }
}


// --- DATA FETCHING (GET Request) ---
// This block runs after all POST actions to get the data to display

// --- 1. Get Filters ---
$status_filter = $_GET['status'] ?? 'all'; // Default to pending
$branch_filter = $_GET['branch'] ?? 'all';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$limit = 10; // 10 batches per page
$offset = ($page - 1) * $limit;

// --- 2. Build WHERE clauses for filtering batches ---
$where_clauses = [];
$params = [];
$types = "";

// Role-based filtering
if ($user_role === 'Branch') {
    // Branch sees only their own orders
    $where_clauses[] = "o.branch_ID = ?";
    $params[] = $user_id;
    $types .= "i";
} elseif ($user_role === 'Delivery') {
    // Delivery sees only 'Approved' or 'Delivered' orders
    $where_clauses[] = "o.status IN ('Approved', 'Delivered')";
} elseif ($branch_filter !== 'all') {
    // Admin/Mod filtering by a specific branch
    $where_clauses[] = "o.branch_ID = ?";
    $params[] = (int)$branch_filter;
    $types .= "i";
}

// Status filtering
switch ($status_filter) {
    case 'all':
        break;
    case 'approved':
        $where_clauses[] = "o.status = 'Approved'";
        break;
    case 'delivered':
        $where_clauses[] = "o.status = 'Delivered'";
        break;
    case 'denied':
        $where_clauses[] = "o.status = 'Denied'";
        break;
    case 'cancelled':
        $where_clauses[] = "o.status = 'Cancelled'";
        break;
    
    case 'pending':
        $where_clauses[] = "o.status = 'Pending'";
        break;
}

$sql_where = "";
if (!empty($where_clauses)) {
    $sql_where = " WHERE " . implode(" AND ", $where_clauses);
}

// --- 3. Get TOTAL count of batches for pagination ---
$sql_count = "SELECT COUNT(DISTINCT CONCAT(o.branch_ID, '-', o.order_date)) 
              FROM orders o
              JOIN accounts a ON o.branch_ID = a.user_ID
              JOIN products p ON o.product_ID = p.product_ID" . $sql_where;

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


// --- 4. Get all BATCHES (grouped by branch_ID and order_date) ---
$sql_batches = "SELECT 
                    o.branch_ID, 
                    MAX(a.name) AS branch_name, 
                    o.order_date,
                    SUM(o.quantity * p.price) AS batch_total,
                    MAX(CASE WHEN o.status = 'Pending' THEN 1 ELSE 0 END) AS is_batch_pending,
                    MAX(CASE WHEN o.status = 'Approved' THEN 1 ELSE 0 END) AS has_approved,
                    MIN(CASE WHEN o.status IN ('Delivered', 'Denied', 'Cancelled') THEN 1 ELSE 0 END) AS all_items_delivered,
                    MAX(r.receipt_ID) AS receipt_ID
                FROM orders o
                JOIN accounts a ON o.branch_ID = a.user_ID
                JOIN products p ON o.product_ID = p.product_ID
                LEFT JOIN receipt r ON o.branch_ID = r.branch_ID AND o.order_date = r.order_date
                $sql_where
                GROUP BY o.branch_ID, o.order_date
                ORDER BY o.order_date DESC
                LIMIT ? OFFSET ?";

// Add LIMIT and OFFSET to params
$params[] = $limit;
$params[] = $offset;
$types .= "ii";

$stmt_batches = $conn->prepare($sql_batches);
if (!$stmt_batches) {
    error_log("Error preparing batch query: " . $conn->error);
    $batches = [];
} else {
// Bind parameters if any exist
if (!empty($params)) {
    $stmt_batches->bind_param($types, ...$params);
}
$stmt_batches->execute();
$batches_result = $stmt_batches->get_result();

$batches = [];
while ($batch_row = $batches_result->fetch_assoc()) {
    $batches[] = $batch_row;
}
$stmt_batches->close();
}


// --- 5. Get all ORDER ITEMS for the fetched batches ---
$orders = fetchOrdersForBatches($conn, $batches);

// --- 6. Get list of branches for filter dropdown (if Admin/Mod) ---
$branches = [];
if (in_array($user_role, ['Admin', 'Moderator'])) {
    $branch_sql = "SELECT a.user_ID, a.name 
                   FROM accounts a
                   JOIN userroles ur ON a.user_ID = ur.user_ID
                   JOIN roles r ON ur.role_ID = r.role_ID
                   WHERE r.role = 'Branch'
                   ORDER BY a.name";
    $branch_result = $conn->query($branch_sql);
    if ($branch_result) {
        while ($row = $branch_result->fetch_assoc()) {
            $branches[] = $row;
        }
    }   
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate" />
    <meta http-equiv="Pragma" content="no-cache" />
    <meta http-equiv="Expires" content="0" />
    <title><?= htmlspecialchars($page_title) ?></title>
    <link rel="stylesheet" href="orders.css">
</head>
<body>

    <!-- NEW: Update notification banner -->
    <div id="update-notification" onclick="location.reload();">
        <span class="update-pulse"></span>
        <span id="update-message">New orders available! Click to refresh.</span>
    </div>

    <?php include __DIR__ . '/includes/navbar.php'; ?>

<div class="page-content">

        <h1>Order Management</h1>
        
        <?php if (!empty($message)) echo $message; ?>
        
        <?php if ($user_role === 'Branch'): ?>
        <div class="message info" style="background: #e8f5e9; border-color: #c8e6c9; color: #2e7d32;">
            <strong>💡 Need to place an order?</strong> Click the <strong>"📋 Order Here - Place New Order"</strong> button below to create your batch order!
        </div>
        <?php endif; ?>

        <div class="filter-bar">
            <div class="filter-group">
                <label for="status-filter">Status</label>
                <select id="status-filter" name="status" onchange="window.location.href = this.value;">
                    <?php
                    $statuses = ['all' => 'All Orders', 'pending' => 'Pending', 'approved' => 'Approved', 'delivered' => 'Delivered', 'denied' => 'Denied', 'cancelled' => 'Cancelled'];
                    $base_url = "orders.php?";
                    $query_params = [];
                    if ($branch_filter !== 'all') $query_params['branch'] = $branch_filter;

                    foreach ($statuses as $key => $value) {
                        $query_params['status'] = $key;
                        $url = $base_url . http_build_query($query_params);
                        $selected = ($status_filter == $key) ? 'selected' : '';
                        echo "<option value=\"$url\" $selected>" . htmlspecialchars($value) . "</option>";
                    }
                    ?>
                </select>
            </div>

            <?php if (in_array($user_role, ['Admin', 'Moderator'])): ?>
            <div class="filter-group">
                <label for="branch-filter">Branch</label>
                <select id="branch-filter" name="branch" onchange="window.location.href = this.value;">
                    <?php
                    $query_params = [];
                    if ($status_filter !== 'all') $query_params['status'] = $status_filter;
                    
                    $query_params['branch'] = 'all';
                    $url = $base_url . http_build_query($query_params);
                    $selected = ($branch_filter == 'all') ? 'selected' : '';
                    echo "<option value=\"$url\" $selected>All Branches</option>";
                    
                    foreach ($branches as $branch) {
                        $query_params['branch'] = $branch['user_ID'];
                        $url = $base_url . http_build_query($query_params);
                        $selected = ($branch_filter == $branch['user_ID']) ? 'selected' : '';
                        echo "<option value=\"$url\" $selected>" . htmlspecialchars($branch['name']) . "</option>";
                    }
                    ?>
                </select>
            </div>
            <?php endif; ?>

            <?php if ($user_role === 'Branch'): ?>
            <div class="filter-group" style="margin-left: auto;">
                 <label>&nbsp;</label>
                <button id="new-order-btn" class="btn btn-primary" style="font-size: 1.1em; padding: 10px 20px;">
                    📋 Order Here - Place New Order
                </button>
            </div>
            <?php endif; ?>
        </div>
        
        <?php if (empty($batches)): ?>
            <div class="message info" id="no-orders-message">No orders found for the selected filters.</div>
        <?php endif; ?>
        
        <!-- Container for dynamically loaded batches -->
        <div id="batches-container">
        <?php foreach ($batches as $batch):
            $batch_key = $batch['branch_ID'] . "_" . $batch['order_date'];
            $batch_items = $orders[$batch_key] ?? [];
        ?>
            <div class="batch-card" data-batch-key="<?= htmlspecialchars($batch_key) ?>">
                <div class="batch-header">
                    <h3>
                        <?= htmlspecialchars($batch['branch_name']) ?>
                        <span>(Batch from: <?= htmlspecialchars(date('M j, Y g:i A', strtotime($batch['order_date']))) ?>)</span>
                    </h3>
                    <div class="batch-actions">
                    
                        <?php if ($user_role === 'Branch' && $batch['is_batch_pending']): ?>
                            <form method="post" action="orders.php" style="display:inline;" data-confirm="Cancel this entire batch order? This cannot be undone.">
                                <input type="hidden" name="batch_branch_id" value="<?= $batch['branch_ID'] ?>">
                                <input type="hidden" name="batch_date" value="<?= htmlspecialchars($batch['order_date']) ?>">
                                <button type="submit" name="cancel_batch" class="btn btn-cancel">Cancel Batch</button>
                            </form>
                        <?php endif; ?>

                        <?php if (in_array($user_role, ['Admin', 'Moderator']) && $batch['is_batch_pending']): ?>
                            <form method="post" action="orders.php" style="display:inline;" data-confirm="Approve ALL pending items for <?= htmlspecialchars(addslashes($batch['branch_name'])) ?> from <?= date('M j H:i', strtotime($batch['order_date'])) ?>? This deducts stock.">
                                <input type="hidden" name="batch_branch_id" value="<?= $batch['branch_ID'] ?>">
                                <input type="hidden" name="batch_date" value="<?= htmlspecialchars($batch['order_date']) ?>">
                                <button type="submit" name="approve_batch" class="btn btn-approve">Approve Batch</button>
                            </form>
                            <form method="post" action="orders.php" style="display:inline;" data-confirm="Deny ALL pending items for <?= htmlspecialchars(addslashes($batch['branch_name'])) ?> from <?= date('M j H:i', strtotime($batch['order_date'])) ?>?">
                                <input type="hidden" name="batch_branch_id" value="<?= $batch['branch_ID'] ?>">
                                <input type="hidden" name="batch_date" value="<?= htmlspecialchars($batch['order_date']) ?>">
                                <button type="submit" name="deny_batch" class="btn btn-deny">Deny Batch</button>
                            </form>
                        <?php endif; ?>
                        
                        <?php if (in_array($user_role, ['Admin', 'Delivery']) && $batch['has_approved'] && !$batch['all_items_delivered']): ?>
                            <form method="post" action="orders.php" class="batch-delivery-form" enctype="multipart/form-data" data-confirm="Mark ALL approved items in this batch as Delivered?">
                                <input type="hidden" name="batch_branch_id" value="<?= $batch['branch_ID'] ?>">
                                <input type="hidden" name="batch_date" value="<?= htmlspecialchars($batch['order_date']) ?>">
                                <div>
                                    <label for="proof_<?= $batch_key ?>" style="font-size: 0.8em; font-weight: bold; color: #c0392b;">Proof Image (Required)*:</label>
                                    <input type="file" name="delivery_proof" id="proof_<?= $batch_key ?>" required>
                                </div>
                                <button type="submit" name="deliver_batch" class="btn btn-deliver">Mark as Delivered</button>
                            </form>
                        <?php endif; ?>

                        <?php if ($batch['receipt_ID'] && $user_role !== 'Delivery'): ?>
                            <a href="receipt.php?receipt_id=<?= $batch['receipt_ID'] ?>" class="btn btn-view">View Receipt</a>
                        <?php endif; ?>
                        
                    </div>
                </div>
                
                <div class="batch-body batch-body-scrollable">
                        <table>
                            <thead>
                                <tr>
                                    <th style="min-width: 70px;">Order ID</th>
                                    <th style="min-width: 180px;">Product</th>
                                    <th style="min-width: 80px;">Qty</th>
                                    <?php if (in_array($user_role, ['Admin', 'Moderator'])): ?>
                                        <th style="min-width: 80px;">Stock</th>
                                    <?php endif; ?>
                                    <th style="min-width: 100px;">Total Price</th>
                                    <th style="min-width: 110px;">Status</th>
                                    <th style="min-width: 180px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($batch_items as $item): ?>
                                    <tr>
                                        <td><?= $item['order_ID'] ?></td>
                                        <td><?= htmlspecialchars($item['product_name']) ?></td>
                                        <td><?= $item['quantity'] ?></td>
                                        <?php if (in_array($user_role, ['Admin', 'Moderator'])): ?>
                                            <td><?= $item['stock'] ?></td>
                                        <?php endif; ?>
                                        <td>₱<?= number_format($item['total_price'], 2) ?></td>
                                        <td><span class="status-badge status-<?= strtoupper($item['status']) ?>"><?= htmlspecialchars($item['status']) ?></span></td>
                                        <td>
                                            <?php if ($item['status'] === 'Pending' && in_array($user_role, ['Admin', 'Moderator'])): ?>
                                                <form method="post" action="orders.php" style="display:inline;" data-confirm="Approve order #<?= $item['order_ID'] ?>? This deducts stock.">
                                                    <input type="hidden" name="order_ID" value="<?= $item['order_ID'] ?>">
                                                    <button type="submit" name="approve_order" class="btn btn-approve">Approve</button>
                                                </form>
                                                <form method="post" action="orders.php" style="display:inline;" data-confirm="Deny order #<?= $item['order_ID'] ?>?">
                                                    <input type="hidden" name="order_ID" value="<?= $item['order_ID'] ?>">
                                                    <button type="submit" name="deny_order" class="btn btn-deny">Deny</button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr class="batch-total-row">
                                    <?php
                                        $first_colspan = in_array($user_role, ['Admin', 'Moderator']) ? 4 : 3;
                                        $second_colspan = 3;
                                    ?>
                                    <td colspan="<?= $first_colspan ?>">Batch Total:</td>
                                    <td colspan="<?= $second_colspan ?>">₱<?= number_format($batch['batch_total'], 2) ?></td>
                                </tr>
                            </tbody>
                        </table>
                </div>

            </div>
        <?php endforeach; ?>
        </div>
        
        <div class="pagination">
            <?php
            $query_params = [];
            if ($status_filter !== 'pending') $query_params['status'] = $status_filter;
            if ($branch_filter !== 'all') $query_params['branch'] = $branch_filter;

            if ($page > 1) {
                $query_params['page'] = $page - 1;
                echo '<a href="orders.php?' . http_build_query($query_params) . '">&laquo; Previous</a>';
            } else {
                echo '<span class="disabled">&laquo; Previous</span>';
            }

            $window = 2;
            for ($i = 1; $i <= $total_pages; $i++) {
                if ($i == 1 || $i == $total_pages || ($i >= $page - $window && $i <= $page + $window)) {
                    if ($i == $page) {
                        echo '<span class="current">' . $i . '</span>';
                    } else {
                        $query_params['page'] = $i;
                        echo '<a href="orders.php?' . http_build_query($query_params) . '">' . $i . '</a>';
                    }
                } elseif ($i == $page - $window - 1 || $i == $page + $window + 1) {
                    echo '<span>...</span>';
                }
            }

            if ($page < $total_pages) {
                $query_params['page'] = $page + 1;
                echo '<a href="orders.php?' . http_build_query($query_params) . '">Next &raquo;</a>';
            } else {
                echo '<span class="disabled">Next &raquo;</span>';
            }
            ?>
        </div>

    </div>

    <?php if ($user_role === 'Branch'): ?>
    <div id="new-order-modal" class="modal">
        <div class="modal-content">
            <span class="modal-close" id="modal-close-btn">&times;</span>
            <h3>Place New Batch Order</h3>
            <!-- Show the branch name -->
            <p style="text-align: center; font-size: 1.1em; color: #333; margin-top: -10px; margin-bottom: 15px;">
                Ordering for: <strong><?= htmlspecialchars($_SESSION['name'] ?? 'Your Branch') ?></strong>
            </p>
            
            <div class="add-item-controls">
                <select id="product-select">
                    <option value="">Select a product...</option>
                </select>
                <input type="number" id="product-quantity" min="1" value="1" style="max-width: 100px;">
                <button id="add-item-btn" class="btn btn-primary">Add Item</button>
            </div>
            
            <div id="order-items-table-container">
                <table id="order-items-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th style="text-align: right;">Price</th>
                            <th style="text-align: right;">Quantity</th>
                            <th style="text-align: right;">Subtotal</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="order-items-tbody">
                    </tbody>
                </table>
            </div>
            
            <div id="modal-total-display">
                Total: ₱0.00
            </div>

            <form method="post" action="orders.php" id="place-order-form">
                <input type="hidden" name="order_items_json" id="order-items-json">
                <div class="modal-footer">
                    <button type="button" id="modal-cancel-btn" class="btn btn-cancel">Cancel</button>
                    <button type="submit" name="place_batch_order" class="btn btn-approve">Place Batch Order</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <script>
        // NEW: Auto-update functionality with live data injection
        let lastCheckTime = new Date().toISOString().slice(0, 19).replace('T', ' ');
        let updateCheckInterval = null;
        const userRole = '<?= $user_role ?>';
        const currentStatusFilter = '<?= $status_filter ?>';
        const currentBranchFilter = '<?= $branch_filter ?>';
        
        function fetchAndUpdateOrders() {
            const params = new URLSearchParams({
                action: 'fetch_orders',
                status: currentStatusFilter,
                branch: currentBranchFilter
            });
            
            fetch(`orders.php?${params}`)
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        console.error('Error:', data.error);
                        return;
                    }
                    
                    updateOrdersDisplay(data);
                    lastCheckTime = data.current_time;
                })
                .catch(error => {
                    console.error('Error fetching orders:', error);
                });
        }
        
        function updateOrdersDisplay(data) {
            const container = document.getElementById('batches-container');
            const noOrdersMsg = document.getElementById('no-orders-message');
            
            if (data.batches.length === 0) {
                container.innerHTML = '';
                if (noOrdersMsg) {
                    noOrdersMsg.style.display = 'block';
                }
                return;
            }
            
            if (noOrdersMsg) {
                noOrdersMsg.style.display = 'none';
            }
            
            // Build HTML for all batches
            let html = '';
            data.batches.forEach(batch => {
                const batchKey = `${batch.branch_ID}_${batch.order_date}`;
                const batchOrders = data.orders[batchKey] || [];
                
                html += buildBatchHTML(batch, batchOrders, batchKey);
            });
            
            // Check if content has changed before updating
            const tempDiv = document.createElement('div');
            tempDiv.innerHTML = html;
            
            if (container.innerHTML !== html) {
                container.innerHTML = html;
                // Re-attach event listeners after updating DOM
                attachFormListeners();
            }
        }
        
        function buildBatchHTML(batch, orders, batchKey) {
            const orderDate = new Date(batch.order_date);
            const formattedDate = orderDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) + ' ' + 
                                  orderDate.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
            
            let html = `<div class="batch-card" data-batch-key="${escapeHtml(batchKey)}">
                <div class="batch-header">
                    <h3>
                        ${escapeHtml(batch.branch_name)}
                        <span>(Batch from: ${formattedDate})</span>
                    </h3>
                    <div class="batch-actions">`;
            
            // Branch cancel button
            if (userRole === 'Branch' && batch.is_batch_pending == 1) {
                html += `<form method="post" action="orders.php" style="display:inline;" data-confirm="Cancel this entire batch order? This cannot be undone.">
                    <input type="hidden" name="batch_branch_id" value="${batch.branch_ID}">
                    <input type="hidden" name="batch_date" value="${escapeHtml(batch.order_date)}">
                    <button type="submit" name="cancel_batch" class="btn btn-cancel">Cancel Batch</button>
                </form>`;
            }
            
            // Admin/Moderator approve/deny buttons
            if ((userRole === 'Admin' || userRole === 'Moderator') && batch.is_batch_pending == 1) {
                html += `<form method="post" action="orders.php" style="display:inline;" data-confirm="Approve ALL pending items for ${escapeHtml(batch.branch_name)}? This deducts stock.">
                    <input type="hidden" name="batch_branch_id" value="${batch.branch_ID}">
                    <input type="hidden" name="batch_date" value="${escapeHtml(batch.order_date)}">
                    <button type="submit" name="approve_batch" class="btn btn-approve">Approve Batch</button>
                </form>
                <form method="post" action="orders.php" style="display:inline;" data-confirm="Deny ALL pending items for ${escapeHtml(batch.branch_name)}?">
                    <input type="hidden" name="batch_branch_id" value="${batch.branch_ID}">
                    <input type="hidden" name="batch_date" value="${escapeHtml(batch.order_date)}">
                    <button type="submit" name="deny_batch" class="btn btn-deny">Deny Batch</button>
                </form>`;
            }
            
            // Delivery button
            if ((userRole === 'Admin' || userRole === 'Delivery') && batch.has_approved == 1 && batch.all_items_delivered == 0) {
                html += `<form method="post" action="orders.php" class="batch-delivery-form" enctype="multipart/form-data" data-confirm="Mark ALL approved items in this batch as Delivered?">
                    <input type="hidden" name="batch_branch_id" value="${batch.branch_ID}">
                    <input type="hidden" name="batch_date" value="${escapeHtml(batch.order_date)}">
                    <div>
                        <label for="proof_${batchKey}" style="font-size: 0.8em; font-weight: bold; color: #c0392b;">Proof Image (Required)*:</label>
                        <input type="file" name="delivery_proof" id="proof_${batchKey}" required>
                    </div>
                    <button type="submit" name="deliver_batch" class="btn btn-deliver">Mark as Delivered</button>
                </form>`;
            }
            
            // View receipt button
            if (batch.receipt_ID && userRole !== 'Delivery') {
                html += `<a href="receipt.php?receipt_id=${batch.receipt_ID}" class="btn btn-view">View Receipt</a>`;
            }
            
            html += `</div></div><div class="batch-body batch-body-scrollable"><table><thead><tr>
                <th style="min-width: 70px;">Order ID</th>
                <th style="min-width: 180px;">Product</th>
                <th style="min-width: 80px;">Qty</th>`;
            
            if (userRole === 'Admin' || userRole === 'Moderator') {
                html += `<th style="min-width: 80px;">Stock</th>`;
            }
            
            html += `<th style="min-width: 100px;">Total Price</th>
                <th style="min-width: 110px;">Status</th>
                <th style="min-width: 180px;">Actions</th>
            </tr></thead><tbody>`;
            
            // Order items
            orders.forEach(item => {
                html += `<tr>
                    <td>${item.order_ID}</td>
                    <td>${escapeHtml(item.product_name)}</td>
                    <td>${item.quantity}</td>`;
                
                if (userRole === 'Admin' || userRole === 'Moderator') {
                    html += `<td>${item.stock}</td>`;
                }
                
                html += `<td>₱${parseFloat(item.total_price).toFixed(2)}</td>
                    <td><span class="status-badge status-${item.status.toUpperCase()}">${escapeHtml(item.status)}</span></td>
                    <td>`;
                
                if (item.status === 'Pending' && (userRole === 'Admin' || userRole === 'Moderator')) {
                    html += `<form method="post" action="orders.php" style="display:inline;" data-confirm="Approve order #${item.order_ID}? This deducts stock.">
                        <input type="hidden" name="order_ID" value="${item.order_ID}">
                        <button type="submit" name="approve_order" class="btn btn-approve">Approve</button>
                    </form>
                    <form method="post" action="orders.php" style="display:inline;" data-confirm="Deny order #${item.order_ID}?">
                        <input type="hidden" name="order_ID" value="${item.order_ID}">
                        <button type="submit" name="deny_order" class="btn btn-deny">Deny</button>
                    </form>`;
                }
                
                html += `</td></tr>`;
            });
            
            // Total row
            const firstColspan = (userRole === 'Admin' || userRole === 'Moderator') ? 4 : 3;
            html += `<tr class="batch-total-row">
                <td colspan="${firstColspan}">Batch Total:</td>
                <td colspan="3">₱${parseFloat(batch.batch_total).toFixed(2)}</td>
            </tr></tbody></table></div></div>`;
            
            return html;
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        function attachFormListeners() {
            const allForms = document.querySelectorAll('form:not(#place-order-form)');
            
            allForms.forEach(form => {
                form.removeEventListener('submit', handleFormSubmit); // Remove old listeners
                form.addEventListener('submit', handleFormSubmit);
            });
        }
        
        function handleFormSubmit(e) {
            const confirmMessage = this.dataset.confirm;
            if (confirmMessage) {
                if (!confirm(confirmMessage)) {
                    e.preventDefault();
                    return false;
                }
            }
        }
        
        // Start polling every 5 seconds
        function startAutoUpdate() {
            updateCheckInterval = setInterval(fetchAndUpdateOrders, 60000);
        }
        
        // Stop polling (useful when modal is open)
        function stopAutoUpdate() {
            if (updateCheckInterval) {
                clearInterval(updateCheckInterval);
                updateCheckInterval = null;
            }
        }
        
        // Start auto-update when page loads
        document.addEventListener('DOMContentLoaded', function() {
            startAutoUpdate();
            attachFormListeners(); // Attach to initial forms
            
            const hamburgerButton = document.getElementById('hamburger-button');
            const navLinks = document.getElementById('main-nav-links');

            if (hamburgerButton && navLinks) {
                hamburgerButton.addEventListener('click', function() {
                    navLinks.classList.toggle('show-menu');
                });
            }

            <?php if ($user_role === 'Branch'): ?>
            const modal = document.getElementById('new-order-modal');
            const newOrderBtn = document.getElementById('new-order-btn');
            const closeBtn = document.getElementById('modal-close-btn');
            const cancelBtn = document.getElementById('modal-cancel-btn');
            
            const productSelect = document.getElementById('product-select');
            const quantityInput = document.getElementById('product-quantity');
            const addItemBtn = document.getElementById('add-item-btn');
            const orderTbody = document.getElementById('order-items-tbody');
            const totalDisplay = document.getElementById('modal-total-display');
            const orderForm = document.getElementById('place-order-form');
            const orderJsonInput = document.getElementById('order-items-json');

            let productsData = [];
            let currentOrder = [];

            if (newOrderBtn) {
                newOrderBtn.addEventListener('click', function() {
                    modal.style.display = 'flex';
                    fetchProducts();
                    stopAutoUpdate(); // Stop polling while modal is open
                });
            }
            if (closeBtn) {
                closeBtn.addEventListener('click', () => {
                    modal.style.display = 'none';
                    startAutoUpdate(); // Resume polling
                });
            }
            if (cancelBtn) {
                cancelBtn.addEventListener('click', () => {
                    modal.style.display = 'none';
                    startAutoUpdate(); // Resume polling
                });
            }
            window.addEventListener('click', function(event) {
                if (event.target == modal) {
                    modal.style.display = 'none';
                    startAutoUpdate(); // Resume polling
                }
            });

            function fetchProducts() {
                if (productsData.length === 0) {
                    fetch('orders.php?action=fetch_products')
                        .then(response => response.json())
                        .then(data => {
                            if (data.error) {
                                alert('Error fetching products: ' + data.error);
                            } else {
                                productsData = data.products;
                                populateProductSelect();
                            }
                        })
                        .catch(error => {
                            console.error('Fetch error:', error);
                            alert('Failed to load product list. Please try again.');
                        });
                }
            }

            function populateProductSelect() {
                productSelect.innerHTML = '<option value="">Select a product...</option>';
                productsData.forEach(product => {
                    productSelect.innerHTML += `<option value="${product.product_ID}" data-price="${product.price}">${product.product_name} (₱${product.price})</option>`;
                });
            }

            if (addItemBtn) {
                addItemBtn.addEventListener('click', function() {
                    const selectedOption = productSelect.options[productSelect.selectedIndex];
                    if (!selectedOption.value) {
                        alert('Please select a product.');
                        return;
                    }
                    const id = parseInt(selectedOption.value);
                    const name = selectedOption.text.split(' (₱')[0];
                    const price = parseFloat(selectedOption.dataset.price);
                    const quantity = parseInt(quantityInput.value);

                    if (isNaN(quantity) || quantity < 1) {
                        alert('Please enter a valid quantity.');
                        return;
                    }

                    const existingItem = currentOrder.find(item => item.id === id);
                    if (existingItem) {
                        existingItem.quantity += quantity;
                    } else {
                        currentOrder.push({ id, name, price, quantity });
                    }
                    
                    renderOrderTable();
                    updateTotal();
                    
                    productSelect.value = '';
                    quantityInput.value = '1';
                });
            }

            function renderOrderTable() {
                orderTbody.innerHTML = '';
                if (currentOrder.length === 0) {
                    orderTbody.innerHTML = '<tr><td colspan="5" style="text-align: center;">No items added yet.</td></tr>';
                    return;
                }
                
                currentOrder.forEach((item, index) => {
                    const subtotal = item.price * item.quantity;
                    const row = `
                        <tr>
                            <td>${item.name}</td>
                            <td style="text-align: right;">₱${item.price.toFixed(2)}</td>
                            <td style="text-align: right;">${item.quantity}</td>
                            <td style="text-align: right;">₱${subtotal.toFixed(2)}</td>
                            <td style="text-align: center;">
                                <button type="button" class="btn-remove-item" data-index="${index}">&times;</button>
                            </td>
                        </tr>
                    `;
                    orderTbody.innerHTML += row;
                });

                document.querySelectorAll('.btn-remove-item').forEach(btn => {
                    btn.addEventListener('click', function() {
                        const indexToRemove = parseInt(this.dataset.index);
                        currentOrder.splice(indexToRemove, 1);
                        renderOrderTable();
                        updateTotal();
                    });
                });
            }

            function updateTotal() {
                const total = currentOrder.reduce((acc, item) => acc + (item.price * item.quantity), 0);
                totalDisplay.textContent = `Total: ₱${total.toFixed(2)}`;
            }

            if (orderForm) {
                orderForm.addEventListener('submit', function(e) {
                    if (currentOrder.length === 0) {
                        e.preventDefault();
                        alert('Cannot place an order with no items. Please add products to the order.');
                        return;
                    }
                    orderJsonInput.value = JSON.stringify(currentOrder);
                    startAutoUpdate(); // Resume polling after submission
                });
            }
            
            renderOrderTable();
            <?php endif; ?>
        });
        
        // Stop polling when user leaves the page
        window.addEventListener('beforeunload', function() {
            stopAutoUpdate();
        });
    </script>

</body>
</html>
