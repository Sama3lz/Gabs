<?php
session_start();
include("gabsdb.php");
require_once __DIR__ . "/includes/auth_helpers.php";

// Restrict access
require_login(['Admin', 'Moderator', 'Branch']);

$user_id = $_SESSION['user_ID'];
$user_role = $_SESSION['role'] ?? '';
$current_page = basename($_SERVER['PHP_SELF']);
apply_nav_permissions($user_role);
$msg = "";

if (isset($_SESSION['message'])) {
    $msg = $_SESSION['message'];
    unset($_SESSION['message']);
}

/* ============================================
    1. UNIT CONVERSION HELPERS
    ============================================ */
function convert_to_base_unit($quantity, $unit, $type) {
    if ($type === 'Dry') {
        switch ($unit) {
            case 'g': return $quantity / 1000;      // grams → kg
            case 'mg': return $quantity / 1000000;  // milligrams → kg
            case 'kg': default: return $quantity;   // kg stays the same
        }
    } elseif ($type === 'Wet') {
        switch ($unit) {
            case 'ml': return $quantity / 3785.41;  // ml → gallons
            case 'l': return $quantity / 3.78541;   // liters → gallons
            case 'gal': default: return $quantity;  // gallons stay the same
        }
    }
    return $quantity;
}

function format_recipe_quantity($quantity_in_base, $type) {
    $quantity_in_base = (float)$quantity_in_base;
    $format_number = function($num) {
        // Remove trailing zeros and the decimal point if it's not needed (e.g., 120.00 -> 120)
        return rtrim(rtrim(number_format($num, 2), '0'), '.');
    };

    if ($type === 'Dry') { // Base unit is kg
        if ($quantity_in_base > 0 && $quantity_in_base < 1) {
            $grams = $quantity_in_base * 1000;
            if ($grams > 0 && $grams < 1) {
                $milligrams = $grams * 1000;
                return $format_number($milligrams) . ' mg';
            }
            return $format_number($grams) . ' g';
        }
        return $format_number($quantity_in_base) . ' kg';
    } elseif ($type === 'Wet') { // Base unit is gal
        if ($quantity_in_base > 0 && $quantity_in_base < 1) {
            $liters = $quantity_in_base * 3.78541;
            if ($liters > 0 && $liters < 1) {
                $milliliters = $liters * 1000;
                return $format_number($milliliters) . ' ml';
            }
            return $format_number($liters) . ' L';
        }
        return $format_number($quantity_in_base) . ' gal';
    }
    return $format_number($quantity_in_base); // Fallback
}


/* ============================================
    2. PRODUCT CRUD
    ============================================ */
if ($user_role === 'Admin') {
    if (isset($_POST['add_product'])) {
        $name = trim($_POST['product_name'] ?? '');
        $stock = intval($_POST['stock'] ?? 0);
        $price = floatval($_POST['price'] ?? 0);

        if ($name === '' || $stock < 0 || $price < 0) {
            $_SESSION['message'] = "<div class='message error'>❌ Invalid product details.</div>";
            header("Location: productinventory.php");
            exit();
        }

        $stmt = $conn->prepare("INSERT INTO products (product_name, stock, price) VALUES (?, ?, ?)");
        $stmt->bind_param("sid", $name, $stock, $price);
        $stmt->execute();
        $stmt->close();

        $_SESSION['message'] = "<div class='message'>✅ Product added.</div>";
        header("Location: productinventory.php");
        exit();
    }
}

if (in_array($user_role, ['Admin', 'Moderator'])) {
    if (isset($_POST['update_product'])) {
        $pid = intval($_POST['product_ID'] ?? 0);
        $newStock = intval($_POST['stock'] ?? 0);
        $newPrice = floatval($_POST['price'] ?? 0);

        if ($pid <= 0 || $newStock < 0 || $newPrice < 0) {
            $_SESSION['message'] = "<div class='message error'>❌ Invalid update values.</div>";
            header("Location: productinventory.php");
            exit();
        }

        $stmt = $conn->prepare("UPDATE products SET stock=?, price=? WHERE product_ID=?");
        $stmt->bind_param("idi", $newStock, $newPrice, $pid);
        $stmt->execute();
        $stmt->close();

        $_SESSION['message'] = "<div class='message'>✅ Product updated.</div>";
        header("Location: productinventory.php");
        exit();
    }

    if (isset($_POST['delete_product']) && $user_role === 'Admin') {
        $pid = intval($_POST['product_ID'] ?? 0);
        if ($pid <= 0) {
            $_SESSION['message'] = "<div class='message error'>❌ Invalid product ID.</div>";
            header("Location: productinventory.php");
            exit();
        }
        $conn->begin_transaction();
        try {
            $tables = ['orders', 'product_ingredients', 'product_history'];
            foreach ($tables as $tbl) {
                $stmt = $conn->prepare("DELETE FROM $tbl WHERE product_ID=?");
                $stmt->bind_param("i", $pid);
                $stmt->execute();
                $stmt->close();
            }

            $stmt = $conn->prepare("DELETE FROM products WHERE product_ID=?");
            $stmt->bind_param("i", $pid);
            $stmt->execute();
            $stmt->close();

            $conn->commit();
            $_SESSION['message'] = "<div class='message'>✅ Product and related data deleted.</div>";
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['message'] = "<div class='message error'>❌ Deletion failed: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
        header("Location: productinventory.php");
        exit();
    }
}

/* ============================================
    3. RECIPE (Add or Update Ingredient)
    ============================================ */
if (in_array($user_role, ['Admin', 'Moderator'])) {
    if (isset($_POST['add_recipe_item'])) {
        $pid = intval($_POST['product_ID'] ?? 0);
        $iid = intval($_POST['ingredient_ID'] ?? 0);
        $qty_input = floatval($_POST['qty_per_unit'] ?? 0);
        $unit = $_POST['unit'] ?? '';
        $allowed_units = ['kg', 'g', 'lb', 'oz', 'gal', 'l', 'ml'];

        if ($pid <= 0 || $iid <= 0 || $qty_input <= 0 || !in_array($unit, $allowed_units, true)) {
            $_SESSION['message'] = "<div class='message error'>❌ Invalid recipe item details.</div>";
            header("Location: productinventory.php");
            exit();
        }

        $type_q = $conn->prepare("SELECT type FROM ingredients WHERE ingredients_ID = ?");
        $type_q->bind_param("i", $iid);
        $type_q->execute();
        $type_q->bind_result($ingredient_type);
        $type_q->fetch();
        $type_q->close();

        $qty_in_base_unit = convert_to_base_unit($qty_input, $unit, $ingredient_type);

        $check = $conn->prepare("SELECT id FROM product_ingredients WHERE product_ID=? AND ingredient_ID=?");
        $check->bind_param("ii", $pid, $iid);
        $check->execute();
        $result = $check->get_result();
        $check->close();

        if ($result->num_rows > 0) {
            $update = $conn->prepare("UPDATE product_ingredients SET qty_per_unit=? WHERE product_ID=? AND ingredient_ID=?");
            $update->bind_param("dii", $qty_in_base_unit, $pid, $iid);
            $update->execute();
            $update->close();
            $_SESSION['message'] = "<div class='message'>✅ Ingredient quantity updated successfully.</div>";
        } else {
            $insert = $conn->prepare("INSERT INTO product_ingredients (product_ID, ingredient_ID, qty_per_unit) VALUES (?, ?, ?)");
            $insert->bind_param("iid", $pid, $iid, $qty_in_base_unit);
            $insert->execute();
            $insert->close();
            $_SESSION['message'] = "<div class='message'>✅ Ingredient added successfully.</div>";
        }

        header("Location: productinventory.php");
        exit();
    }

    if (isset($_POST['remove_recipe_item'])) {
        $pi_id = intval($_POST['pi_id'] ?? 0);
        if ($pi_id <= 0) {
            $_SESSION['message'] = "<div class='message error'>❌ Invalid recipe item ID.</div>";
            header("Location: productinventory.php");
            exit();
        }
        $stmt = $conn->prepare("DELETE FROM product_ingredients WHERE id=?");
        $stmt->bind_param("i", $pi_id);
        $stmt->execute();
        $stmt->close();
        $_SESSION['message'] = "<div class='message'>✅ Ingredient removed.</div>";
        header("Location: productinventory.php");
        exit();
    }
}

/* ============================================
    4. PRODUCTION LOGIC
    ============================================ */
if (isset($_POST['produce_product']) && in_array($user_role, ['Admin', 'Moderator'])) {
    $pid = intval($_POST['product_ID'] ?? 0);
    $qty_made = intval($_POST['quantity_made'] ?? 0);
    if ($pid <= 0 || $qty_made <= 0) {
        $_SESSION['message'] = "<div class='message error'>❌ Invalid production request.</div>";
        header("Location: productinventory.php");
        exit();
    }

    $conn->begin_transaction();
    try {
        $recipe_q = $conn->prepare("SELECT ingredient_ID, qty_per_unit FROM product_ingredients WHERE product_ID = ?");
        $recipe_q->bind_param("i", $pid);
        $recipe_q->execute();
        $recipe_result = $recipe_q->get_result();
        if ($recipe_result->num_rows === 0) throw new Exception("No recipe found.");

        $ingredients_to_process = [];
        while ($row = $recipe_result->fetch_assoc()) {
            $ingredients_to_process[] = ['id' => $row['ingredient_ID'], 'needed' => $row['qty_per_unit'] * $qty_made];
        }
        $recipe_q->close();

        foreach ($ingredients_to_process as &$item) {
            $stock_q = $conn->prepare("SELECT stock, ingredients FROM ingredients WHERE ingredients_ID=? FOR UPDATE");
            $stock_q->bind_param("i", $item['id']);
            $stock_q->execute();
            $stock_q->bind_result($current_stock, $name);
            $stock_q->fetch();
            $stock_q->close();

            if ($current_stock < $item['needed']) throw new Exception("Insufficient stock for " . htmlspecialchars($name));
            $item['old_stock'] = $current_stock;
        }
        unset($item);

        foreach ($ingredients_to_process as $item) {
            $new_stock = $item['old_stock'] - $item['needed'];
            $deduct_q = $conn->prepare("UPDATE ingredients SET stock=? WHERE ingredients_ID=?");
            $deduct_q->bind_param("di", $new_stock, $item['id']);
            $deduct_q->execute();
            $deduct_q->close();

            $log_q = $conn->prepare("INSERT INTO inventory_history (ingredients_ID, action, old_stock, new_stock, user_ID)
                                        VALUES (?, 'USED IN PRODUCTION', ?, ?, ?)");
            $log_q->bind_param("iddi", $item['id'], $item['old_stock'], $new_stock, $user_id);
            $log_q->execute();
            $log_q->close();
        }

        $prod_stock_q = $conn->prepare("SELECT stock FROM products WHERE product_ID=? FOR UPDATE");
        $prod_stock_q->bind_param("i", $pid);
        $prod_stock_q->execute();
        $prod_stock_q->bind_result($old_stock);
        $prod_stock_q->fetch();
        $prod_stock_q->close();

        $new_stock = $old_stock + $qty_made;
        $update_product = $conn->prepare("UPDATE products SET stock=? WHERE product_ID=?");
        $update_product->bind_param("ii", $new_stock, $pid);
        $update_product->execute();
        $update_product->close();

        $log_prod_q = $conn->prepare("INSERT INTO product_history (product_ID, action, old_stock, new_stock, user_ID)
                                          VALUES (?, 'PRODUCED', ?, ?, ?)");
        $log_prod_q->bind_param("iddi", $pid, $old_stock, $new_stock, $user_id);
        $log_prod_q->execute();
        $log_prod_q->close();

        $conn->commit();
        $_SESSION['message'] = "<div class='message'>✅ Successfully produced $qty_made unit(s).</div>";
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['message'] = "<div class='message error'>❌ Production Failed: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
    header("Location: productinventory.php");
    exit();
}

/* ============================================
    5. FETCH DATA FOR DISPLAY
    ============================================ */
$ingredients = $conn->query("SELECT ingredients_ID, ingredients, type FROM ingredients ORDER BY ingredients");
$products = $conn->query("SELECT * FROM products ORDER BY product_name");
?>
<!DOCTYPE html>
<html>
<head>
    <title>Inventory Management - Products</title>
    <link rel="stylesheet" href="productinventory.css">
</head>
<body>
    <?php include_role_navbar(); ?>
    <?php include_role_view('productinventory'); ?>
</body>
</html>
