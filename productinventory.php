<?php
session_start();
include("gabsdb.php");
require_once __DIR__ . "/includes/auth_helpers.php";

// Restrict access
require_login(['Admin', 'Moderator', 'Branch']);

$user_id = $_SESSION['user_ID'];
$user_role = $_SESSION['role'] ?? '';
$current_page = basename($_SERVER['PHP_SELF']);
$nav = get_nav_permissions($user_role);
$show_inventory_link = $nav['show_inventory_link'];
$show_reports_link = $nav['show_reports_link'];
$show_accounts_link = $nav['show_accounts_link'];
$show_orders_link = $nav['show_orders_link'];
$show_settings_link = $nav['show_settings_link'];
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
<div class="header-bar">
        <div class="logo-container"><h1>Gab <span style="color: #c0392b; font-size: 30px;">Shawn</span></h1></div>
        <nav class="navbar">
            <a href="welcome.php">Dashboard</a>
            <a href="inventory.php" class="active">Inventory</a>
            <a href="orders.php">Orders</a>
            <?php if (in_array($user_role, ['Admin', 'Moderator', 'Branch'])): ?><a href="reports.php">Reports</a><?php endif; ?>
            <?php if ($user_role === 'Admin'): ?><a href="accounts.php">Account Management</a><?php endif; ?>
            <a href="settings.php">Settings</a>
            <a href="logout.php" class="logout">Logout</a>
        </nav>
    </div>
<div class="page-content">
    <h1>Inventory Management</h1>
    
    <?php if (!empty($msg)) echo $msg; ?>
    
    <div class="sub-nav">
        <?php if (in_array($user_role, ['Admin', 'Moderator'])): ?>
            <a href="inventory.php">Ingredients</a>
        <?php endif; ?>
        <a href="productinventory.php" class="active-tab">Products</a>
    </div>

    <h2>Products</h2>
    <?php if($user_role === 'Admin'): ?>
    <h3>Add Product</h3>
    <form method="post" style="display:flex; gap: 10px; align-items:center;">
        <input type="text" name="product_name" placeholder="Product Name" required style="width: 25%;">
        <input type="number" name="stock" min="0" value="0" placeholder="Initial Stock" required style="width: 15%;">
        <input type="number" step="0.01" name="price" placeholder="Selling Price (₱)" required style="width: 15%;">
        <button type="submit" name="add_product" style="width: 20%;">Add Product</button>
    </form>
    <?php endif; ?>

    <table>
        <thead>
            <tr>
                <th>Name</th>
                <th>Stock</th>
                <th>Price</th>
                <th>Recipe</th>
                <?php if(in_array($user_role,['Admin','Moderator'])):?>
                <th class="action-cell">Update</th>
                <th class="action-cell">Recipe Management</th>
                <th class="action-cell">Production/Delete</th>
                <?php endif;?>
            </tr>
        </thead>
        <tbody>
        <?php while($row = $products->fetch_assoc()): ?>
            <tr>
                <td><?= htmlspecialchars($row['product_name']) ?></td>
                <td><?= intval($row['stock']) ?></td>
                <td>₱<?= number_format($row['price'], 2) ?></td>
                <td>
                    <div class="recipe-list">
                    <?php
                    $recipe_q = $conn->prepare("SELECT pi.id, i.ingredients, pi.qty_per_unit, i.type 
                                                 FROM product_ingredients pi
                                                 JOIN ingredients i ON pi.ingredient_ID=i.ingredients_ID
                                                 WHERE pi.product_ID=?");
                    $recipe_q->bind_param("i", $row['product_ID']);
                    $recipe_q->execute();
                    $result = $recipe_q->get_result();
                    if ($result->num_rows > 0) {
                        while ($r = $result->fetch_assoc()) {
                             echo "<div class='recipe-list-item'>
                                    <span>• " . htmlspecialchars($r['ingredients']) . " (" . format_recipe_quantity($r['qty_per_unit'], $r['type']) . ")</span>
                                    <form method='post' style='display: inline; margin: 0;'>
                                        <input type='hidden' name='pi_id' value='{$r['id']}'>
                                        <button type='submit' name='remove_recipe_item' class='recipe-remove-btn'>x</button>
                                    </form>
                                  </div>";
                        }
                    } else echo "No recipe defined.";
                    $recipe_q->close();
                    ?>
                    </div>
                </td>
                <?php if(in_array($user_role,['Admin','Moderator'])):?>
                <td class="action-cell">
                    <form method="post">
                        <input type="hidden" name="product_ID" value="<?= $row['product_ID'] ?>">
                        <label>Stock:</label><input type="number" name="stock" value="<?= intval($row['stock']) ?>" min="0" required>
                        <label>Price (₱):</label><input type="number" step="0.01" name="price" value="<?= number_format($row['price'], 2, '.', '') ?>" required>
                        <button name="update_product">Update</button>
                    </form>
                </td>

                <td class="action-cell">
                    <form method="post">
                        <input type="hidden" name="product_ID" value="<?= $row['product_ID'] ?>">
                        <select name="ingredient_ID" required onchange="updateUnitOptions(this)">
                            <option value="">-- Add/Update Ingredient --</option>
                            <?php 
                                $ingredients->data_seek(0);
                                while ($i_row = $ingredients->fetch_assoc()) {
                                    echo "<option value='{$i_row['ingredients_ID']}' data-type='{$i_row['type']}'>" . htmlspecialchars($i_row['ingredients']) . "</option>";
                                }
                            ?>
                        </select>
                        <input type="number" step="any" name="qty_per_unit" placeholder="Qty" required>
                        <select name="unit" required>
                            <option value="kg" class="dry-unit">kg</option>
                            <option value="g" class="dry-unit">g</option>
                            <option value="mg" class="dry-unit">mg</option>
                            <option value="gal" class="wet-unit" style="display:none;">gal</option>
                            <option value="l" class="wet-unit" style="display:none;">L</option>
                            <option value="ml" class="wet-unit" style="display:none;">ml</option>
                        </select>
                        <button name="add_recipe_item" style="background: #2ecc71;">Add/Update Ingredient</button>
                    </form>
                </td>
                
                <td class="action-cell">
                    <form method="post">
                        <input type="hidden" name="product_ID" value="<?= $row['product_ID'] ?>">
                        <input type="number" name="quantity_made" placeholder="Quantity to Produce" min="1" required>
                        <button name="produce_product" style="background: #e67e22;">Produce</button>
                    </form>
                    
                    <?php if($user_role==='Admin'): ?>
                    <form method="post" onsubmit="return confirm('WARNING: Delete this product and ALL linked records?');" style="margin-top: 5px;">
                        <input type="hidden" name="product_ID" value="<?= $row['product_ID'] ?>">
                        <button name="delete_product" style="background: red;">Delete</button>
                    </form>
                    <?php endif; ?>
                </td>
                <?php endif; ?>
            </tr>
        <?php endwhile; ?>
        </tbody>
    </table>
</div>

<script>
    function updateUnitOptions(selectElement) {
        const selectedOption = selectElement.options[selectElement.selectedIndex];
        const ingredientType = selectedOption.getAttribute('data-type');
        const form = selectElement.closest('form');
        const unitSelect = form.querySelector('select[name="unit"]');
        
        const dryUnits = unitSelect.querySelectorAll('.dry-unit');
        const wetUnits = unitSelect.querySelectorAll('.wet-unit');

        if (ingredientType === 'Dry') {
            dryUnits.forEach(opt => opt.style.display = 'block');
            wetUnits.forEach(opt => opt.style.display = 'none');
            unitSelect.value = 'g'; // Default to g
        } else if (ingredientType === 'Wet') {
            dryUnits.forEach(opt => opt.style.display = 'none');
            wetUnits.forEach(opt => opt.style.display = 'block');
            unitSelect.value = 'ml'; // Default to ml
        } else {
            dryUnits.forEach(opt => opt.style.display = 'block');
            wetUnits.forEach(opt => opt.style.display = 'none');
            unitSelect.value = 'g'; 
        }
    }
    document.addEventListener('DOMContentLoaded', function() {
        const allIngredientSelectors = document.querySelectorAll('select[name="ingredient_ID"]');
        allIngredientSelectors.forEach(selector => {
            if(selector.closest('form')) {
                 updateUnitOptions(selector);
            }
        });
    });
</script>

</body>
</html>
