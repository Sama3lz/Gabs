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
apply_nav_permissions($user_role);

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
            $_SESSION['message'] = "<div class='message success'>âœ… Batch order placed successfully!</div>";
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
            $_SESSION['message'] = "<div class='message success'>âœ… Order #{$order_ID} approved and stock updated.</div>";
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
            $_SESSION['message'] = "<div class='message success'>âœ… Batch approved and all stocks updated.</div>";
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
            $_SESSION['message'] = "<div class='message success'>âœ… Batch marked as delivered.</div>";
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
            $_SESSION['message'] = "<div class='message success'>âœ… Order #{$order_ID} has been denied.</div>";
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
            $_SESSION['message'] = "<div class='message success'>âœ… All pending items in the batch have been denied.</div>";
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
            $_SESSION['message'] = "<div class='message success'>âœ… Your batch order has been cancelled.</div>";
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
        $_SESSION['message'] = "<div class='message error'>âŒ " . $e->getMessage() . "</div>";
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
        $_SESSION['message'] = "<div class='message error'>âŒ Upload Failed: " . $e->getMessage() . "</div>";
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

    <?php include_role_navbar(); ?>
    <?php include_role_view('orders'); ?>

</body>
</html>
