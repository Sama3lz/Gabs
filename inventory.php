<?php
session_start();
include("gabsdb.php");
require_once __DIR__ . "/includes/auth_helpers.php";

// --- CSV EXPORT HANDLER (Must be before any HTML output) ---
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    // Security check
    if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['Admin', 'Moderator', 'Branch'])) {
        header("HTTP/1.1 403 Forbidden");
        exit('Access Denied');
    }

    // Get filter and search parameters
    $filter = $_GET['filter'] ?? 'all';
    $search = $_GET['search'] ?? '';

    $sql_params = [];
    $sql_types = "";
    $sql_where = [];

    if ($filter === 'wet') {
        $sql_where[] = "type = ?";
        $sql_params[] = 'Wet';
        $sql_types .= 's';
    } elseif ($filter === 'dry') {
        $sql_where[] = "type = ?";
        $sql_params[] = 'Dry';
        $sql_types .= 's';
    }

    if (!empty($search)) {
        $sql_where[] = "ingredients LIKE ?";
        $sql_params[] = '%' . $search . '%';
        $sql_types .= 's';
    }

    $sql = "SELECT ingredients, stock, type FROM ingredients";
    if (!empty($sql_where)) {
        $sql .= " WHERE " . implode(" AND ", $sql_where);
    }
    $sql .= " ORDER BY ingredients";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if (!empty($sql_params)) {
            $stmt->bind_param($sql_types, ...$sql_params);
        }
        $stmt->execute();
        $result = $stmt->get_result();

        // Set headers for CSV download
        $filename = "ingredients_export_" . date('Y-m-d_His') . ".csv";
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        // Create output stream
        $output = fopen('php://output', 'w');

        // Add BOM for proper Excel UTF-8 support
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

        // CSV Headers
        if ($filter === 'dry') {
            fputcsv($output, ['Ingredient Name', 'Stock (Kilos)', 'Stock (Grams)', 'Stock (Milligrams)', 'Type']);
        } elseif ($filter === 'wet') {
            fputcsv($output, ['Ingredient Name', 'Stock (Gallons)', 'Stock (Liters)', 'Stock (Milliliters)', 'Type']);
        } else {
            fputcsv($output, ['Ingredient Name', 'Stock', 'Unit', 'Type']);
        }

        // Data rows
        while ($row = $result->fetch_assoc()) {
            if ($filter === 'dry') {
                $kg = $row['stock'];
                $g = $kg * 1000;
                $mg = $g * 1000;
                fputcsv($output, [
                    $row['ingredients'],
                    number_format($kg, 3, '.', ''),
                    number_format($g, 2, '.', ''),
                    number_format($mg, 0, '.', ''),
                    $row['type']
                ]);
            } elseif ($filter === 'wet') {
                $gal = $row['stock'];
                $l = $gal * 3.78541;
                $ml = $l * 1000;
                fputcsv($output, [
                    $row['ingredients'],
                    number_format($gal, 3, '.', ''),
                    number_format($l, 2, '.', ''),
                    number_format($ml, 0, '.', ''),
                    $row['type']
                ]);
            } else {
                $unit = ($row['type'] === 'Dry') ? 'kg' : 'gal';
                fputcsv($output, [
                    $row['ingredients'],
                    number_format($row['stock'], 3, '.', ''),
                    $unit,
                    $row['type']
                ]);
            }
        }

        fclose($output);
        $stmt->close();
        exit();
    } else {
        header("HTTP/1.1 500 Internal Server Error");
        exit('Export failed');
    }
}
// --- END CSV EXPORT HANDLER ---

// --- AJAX Handler for Ingredient Autocomplete ---
if (isset($_GET['fetch_ingredients_json'])) {
    if (!isset($conn) || !$conn instanceof mysqli) {
        header("HTTP/1.1 500 Internal Server Error");
        echo json_encode(['error' => 'Database connection failed.']);
        exit();
    }
    if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['Admin', 'Moderator', 'Branch'])) {
         header("HTTP/1.1 403 Forbidden");
         echo json_encode(['error' => 'Access Denied.']);
         exit();
    }

    header('Content-Type: application/json');
    $result = $conn->query("SELECT ingredients FROM ingredients ORDER BY ingredients");
    $ingredients = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $ingredients[] = $row['ingredients'];
        }
        $result->close();
    }
    echo json_encode($ingredients);
    exit();
}

// --- NEW: AJAX Handler for fetching live ingredient data ---
if (isset($_GET['action']) && $_GET['action'] === 'fetch_ingredients') {
    if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['Admin', 'Moderator', 'Branch'])) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Unauthorized']);
        exit();
    }
    
    $filter = $_GET['filter'] ?? 'all';
    $search = $_GET['search'] ?? '';
    
    $sql_params = [];
    $sql_types = "";
    $sql_where = [];

    if ($filter === 'wet') {
        $sql_where[] = "type = ?";
        $sql_params[] = 'Wet';
        $sql_types .= 's';
    } elseif ($filter === 'dry') {
        $sql_where[] = "type = ?";
        $sql_params[] = 'Dry';
        $sql_types .= 's';
    }

    if (!empty($search)) {
        $sql_where[] = "ingredients LIKE ?";
        $sql_params[] = '%' . $search . '%';
        $sql_types .= 's';
    }

    $sql = "SELECT * FROM ingredients";
    if (!empty($sql_where)) {
        $sql .= " WHERE " . implode(" AND ", $sql_where);
    }
    $sql .= " ORDER BY ingredients";

    $stmt = $conn->prepare($sql);
    $ingredients = [];
    
    if ($stmt) {
        if (!empty($sql_params)) {
            $stmt->bind_param($sql_types, ...$sql_params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $ingredients[] = $row;
        }
        $stmt->close();
    }
    
    header('Content-Type: application/json');
    echo json_encode([
        'ingredients' => $ingredients,
        'current_time' => date('Y-m-d H:i:s')
    ]);
    exit();
}

// --- Access Control ---
require_login(['Admin', 'Moderator', 'Branch']);
$user_id = $_SESSION['user_ID'];
$user_role = $_SESSION['role'] ?? '';

// --- Session Message Handling ---
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

// --- Page Variables ---
$page_title = "Inventory - Ingredients";
$current_page = basename($_SERVER['PHP_SELF']);
apply_nav_permissions($user_role);

// --- Unit Conversion Functions ---
function get_dry_conversions($kilos) {
    $grams = $kilos * 1000;
    $milligrams = $grams * 1000;
    return ['kg' => $kilos, 'g' => $grams, 'mg' => $milligrams];
}
function get_wet_conversions($gallons) {
    $liters = $gallons * 3.78541;
    $milliliters = $liters * 1000;
    return ['gal' => $gallons, 'l' => $liters, 'ml' => $milliliters];
}

// --- INGREDIENT CRUD OPERATIONS ---
if ($user_role === 'Admin' || $user_role === 'Moderator') {
    if (isset($_POST['update_ingredient'])) {
        $iid = intval($_POST['ingredients_ID']);
        $newStock = floatval($_POST['stock']);

        $old_stock = 0;
        $old_stock_stmt = $conn->prepare("SELECT stock FROM ingredients WHERE ingredients_ID = ?");
        if ($old_stock_stmt) {
            $old_stock_stmt->bind_param("i", $iid);
            $old_stock_stmt->execute();
            $old_stock_res = $old_stock_stmt->get_result();
            if ($old_stock_res && $old_stock_res->num_rows > 0) {
                $old_stock = $old_stock_res->fetch_assoc()['stock'];
            }
            $old_stock_stmt->close();
        }

        $stmt = $conn->prepare("UPDATE ingredients SET stock=? WHERE ingredients_ID=?");
        if ($stmt) {
            $stmt->bind_param("di", $newStock, $iid);
            if ($stmt->execute()) {
                $action_str = "Stock Update";
                $action_date = date('Y-m-d H:i:s');
                $log_stmt = $conn->prepare("INSERT INTO inventory_history (ingredients_ID, product_ID, user_ID, `action`, old_stock, new_stock, action_date) VALUES (?, 0, ?, ?, ?, ?, ?)");
                if ($log_stmt) {
                    $log_stmt->bind_param("iisdds", $iid, $user_id, $action_str, $old_stock, $newStock, $action_date);
                    $log_stmt->execute();
                    $log_stmt->close();
                }
                $_SESSION['message'] = "✅ Ingredient stock updated successfully.";
            } else {
                $_SESSION['message'] = "❌ Error updating stock: " . htmlspecialchars($stmt->error);
            }
            $stmt->close();
        }
        header("Location: inventory.php"); exit();
    }
}

if ($user_role === 'Admin') {
    if (isset($_POST['add_ingredient'])) {
        $name = trim($_POST['ingredient_name']);
        $stock = floatval($_POST['stock']);
        $type = $_POST['type'];

        if (!in_array($type, ['Dry', 'Wet'])) {
             $_SESSION['message'] = "❌ Invalid ingredient type selected.";
        } else {
            $stmt = $conn->prepare("INSERT INTO ingredients (ingredients, stock, type) VALUES (?, ?, ?)");
            if ($stmt) {
                $stmt->bind_param("sds", $name, $stock, $type);
                if ($stmt->execute()) {
                     $new_iid = $conn->insert_id;
                     if ($stock > 0) {
                         $action_str = "Initial Stock";
                         $action_date = date('Y-m-d H:i:s');
                         $log_stmt = $conn->prepare("INSERT INTO inventory_history (ingredients_ID, product_ID, user_ID, `action`, old_stock, new_stock, action_date) VALUES (?, 0, ?, ?, 0, ?, ?)");
                         if ($log_stmt) {
                             $log_stmt->bind_param("iisds", $new_iid, $user_id, $action_str, $stock, $action_date);
                             $log_stmt->execute();
                             $log_stmt->close();
                         }
                     }
                    $_SESSION['message'] = "✅ Ingredient added successfully.";
                } else {
                    if ($conn->errno == 1062) {
                         $_SESSION['message'] = "❌ Error: Ingredient '{$name}' already exists.";
                    } else {
                         $_SESSION['message'] = "❌ Error adding ingredient: " . htmlspecialchars($stmt->error);
                    }
                }
                $stmt->close();
            }
        }
        header("Location: inventory.php"); exit();
    }

    if (isset($_POST['delete_ingredient'])) {
        $iid = intval($_POST['ingredients_ID']);
        $conn->begin_transaction();
        try {
            $check = $conn->prepare("SELECT COUNT(*) FROM product_ingredients WHERE ingredient_ID=?");
            if (!$check) throw new Exception("Prepare failed: ".$conn->error);
            $check->bind_param("i", $iid);
            $check->execute();
            $check->bind_result($count);
            $check->fetch();
            $check->close();
            if ($count > 0) {
                throw new Exception("Cannot delete: Ingredient is part of $count recipe(s).");
            }

            $del_hist = $conn->prepare("DELETE FROM inventory_history WHERE ingredients_ID = ?");
            if ($del_hist) {
                $del_hist->bind_param("i", $iid);
                $del_hist->execute();
                $del_hist->close();
            }

            $stmt = $conn->prepare("DELETE FROM ingredients WHERE ingredients_ID=?");
            if (!$stmt) throw new Exception("Prepare failed: ".$conn->error);
            $stmt->bind_param("i", $iid);
            $stmt->execute();
             if ($stmt->affected_rows === 0) {
                 throw new Exception("Ingredient not found.");
             }
            $stmt->close();

            $conn->commit();
            $_SESSION['message'] = "✅ Ingredient deleted.";
        } catch (Exception $e) {
            if ($conn->ping()) $conn->rollback();
            $_SESSION['message'] = "❌ " . htmlspecialchars($e->getMessage());
        }
        header("Location: inventory.php"); exit();
    }
}

// --- Fetch Data ---
$filter = $_GET['filter'] ?? 'all';
$search = $_GET['search'] ?? '';

$sql_params = [];
$sql_types = "";
$sql_where = [];

if ($filter === 'wet') {
    $sql_where[] = "type = ?";
    $sql_params[] = 'Wet';
    $sql_types .= 's';
} elseif ($filter === 'dry') {
    $sql_where[] = "type = ?";
    $sql_params[] = 'Dry';
    $sql_types .= 's';
}

if (!empty($search)) {
    $sql_where[] = "ingredients LIKE ?";
    $sql_params[] = '%' . $search . '%';
    $sql_types .= 's';
}

$sql = "SELECT * FROM ingredients";
if (!empty($sql_where)) {
    $sql .= " WHERE " . implode(" AND ", $sql_where);
}
$sql .= " ORDER BY ingredients";

$stmt_results = $conn->prepare($sql);
if ($stmt_results) {
    if (!empty($sql_params)) {
        $stmt_results->bind_param($sql_types, ...$sql_params);
    }
    $stmt_results->execute();
    $ingredients_result = $stmt_results->get_result();
} else {
    $error .= "Error: " . $conn->error;
    $ingredients_result = false;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?></title>
    <link rel="stylesheet" href="inventory.css">
</head>
<body>
    <?php include_role_navbar(); ?>
    <?php include_role_view('inventory'); ?>
</body>
</html>
