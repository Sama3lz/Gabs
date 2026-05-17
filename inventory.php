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
$nav = get_nav_permissions($user_role);
$show_inventory_link = $nav['show_inventory_link'];
$show_reports_link = $nav['show_reports_link'];
$show_accounts_link = $nav['show_accounts_link'];
$show_orders_link = $nav['show_orders_link'];
$show_settings_link = $nav['show_settings_link'];

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
    <?php include __DIR__ . '/includes/navbar.php'; ?>

<div class="page-content">
    <h1>Inventory Management</h1>
    
    <?php
        if (!empty($msg)) echo $msg;
        if (!empty($error)) echo $error;
    ?>

    <div class="sub-nav">
        <a href="inventory.php" class="active-tab">Ingredients</a>
        <a href="productinventory.php">Supply</a>
    </div>

    <h2>Ingredients</h2>

    <?php if($user_role === 'Admin'): ?>
    <h3>Add New Ingredient</h3>
    <form method="post" class="add-ingredient-form" id="addIngredientForm" onsubmit="event.preventDefault(); openModal('addModal', this);">
        <div>
            <label for="ing_name">Name:</label>
            <input type="text" id="ing_name" name="ingredient_name" placeholder="Ingredient Name" required>
        </div>
        <div>
            <label for="ing_stock">Initial Stock:</label>
            <input type="number" id="ing_stock" step="any" name="stock" min="0" value="0" required>
        </div>
         <div>
            <label for="ing_type">Type:</label>
            <select name="type" id="ing_type" required>
                <option value="">-- Select --</option>
                <option value="Dry">Dry (Kilos)</option>
                <option value="Wet">Wet (Gallons)</option>
            </select>
        </div>
        <div>
             <label>&nbsp;</label>
             <button type="submit" name="add_ingredient">Add Ingredient</button>
        </div>
    </form>
    <?php endif; ?>

    <!-- Search and Export Bar -->
    <form method="GET" class="search-export-bar">
        <div>
            <label for="search_box">Search Ingredient:</label>
            <input type="text" id="search_box" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="e.g. Flour..." list="ingredient-list">
            <datalist id="ingredient-list"></datalist>
        </div>
        <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
        <button type="submit">Search</button>
        <a href="inventory.php?filter=<?= htmlspecialchars($filter) ?>" class="btn btn-reset">↺ Reset</a>
        <a href="?export=csv&filter=<?= htmlspecialchars($filter) ?>&search=<?= htmlspecialchars($search) ?>" class="btn btn-export">Export to CSV</a>
    </form>

    <div class="filter-nav">
        <h3>Filter by Type</h3>
        <form method="get">
            <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
            <label><input type="radio" name="filter" value="all" onchange="this.form.submit()" <?= ($filter === 'all') ? 'checked' : '' ?>> All</label>
            <label><input type="radio" name="filter" value="dry" onchange="this.form.submit()" <?= ($filter === 'dry') ? 'checked' : '' ?>> Dry</label>
            <label><input type="radio" name="filter" value="wet" onchange="this.form.submit()" <?= ($filter === 'wet') ? 'checked' : '' ?>> Wet</label>
        </form>
    </div>

    <div class="table-container">
        <table id="ingredients-table">
            <thead>
                <tr>
                    <th rowspan="2">Name</th>
                    <th colspan="<?= ($filter === 'all') ? 2 : 3 ?>">Current Stock</th>
                    <th rowspan="2">Update Stock</th>
                    <?php if($user_role === 'Admin'): ?><th rowspan="2">Delete</th><?php endif; ?>
                </tr>
                <tr>
                    <?php if ($filter === 'dry'): ?>
                        <th>Kilos (kg)</th><th>Grams (g)</th><th>Milligrams (mg)</th>
                    <?php elseif ($filter === 'wet'): ?>
                        <th>Gallons (gal)</th><th>Liters (L)</th><th>Milliliters (ml)</th>
                    <?php else: ?>
                        <th>Kilos (kg)</th><th>Gallons (gal)</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody id="ingredients-tbody">
            <?php if ($ingredients_result && $ingredients_result->num_rows > 0): ?>
                <?php while($row = $ingredients_result->fetch_assoc()): ?>
                <tr data-ingredient-id="<?= $row['ingredients_ID'] ?>">
                    <td><?= htmlspecialchars($row['ingredients']) ?></td>
                    <?php if ($filter === 'dry'): ?>
                        <?php $u = get_dry_conversions($row['stock']); ?>
                        <td><?= number_format($u['kg'], 3) ?></td>
                        <td><?= number_format($u['g'], 2) ?></td>
                        <td><?= number_format($u['mg'], 0) ?></td>
                    <?php elseif ($filter === 'wet'): ?>
                        <?php $u = get_wet_conversions($row['stock']); ?>
                        <td><?= number_format($u['gal'], 3) ?></td>
                        <td><?= number_format($u['l'], 2) ?></td>
                        <td><?= number_format($u['ml'], 0) ?></td>
                    <?php else: ?>
                        <td><?= ($row['type'] === 'Dry') ? number_format($row['stock'], 3) : '---' ?></td>
                        <td><?= ($row['type'] === 'Wet') ? number_format($row['stock'], 3) : '---' ?></td>
                    <?php endif; ?>

                    <td class="action-cell">
                        <?php if($user_role === 'Admin' || $user_role === 'Moderator'): ?>
                        <form method="post" action="inventory.php?filter=<?= htmlspecialchars($filter) ?>&search=<?= htmlspecialchars($search) ?>" onsubmit="event.preventDefault(); openModal('updateModal', this);">
                            <input type="hidden" name="ingredients_ID" value="<?= $row['ingredients_ID'] ?>">
                            <input type="number" step="any" name="stock" value="<?= floatval($row['stock']) ?>" min="0" required>
                            <button name="update_ingredient">Update</button>
                        </form>
                        <?php else: ?>
                        <span>View Only</span>
                        <?php endif; ?>
                    </td>
                    <?php if($user_role === 'Admin'): ?>
                    <td class="action-cell">
                        <form method="post" onsubmit="event.preventDefault(); document.getElementById('deleteModalText').textContent = 'Are you sure you want to delete <?= htmlspecialchars(addslashes($row['ingredients'])) ?>? This cannot be undone!'; openModal('deleteModal', this);">
                            <input type="hidden" name="ingredients_ID" value="<?= $row['ingredients_ID'] ?>">
                            <button name="delete_ingredient">Delete</button>
                        </form>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endwhile; ?>
             <?php else: ?>
                <tr id="no-ingredients-row"><td colspan="<?= ($user_role === 'Admin') ? ($filter === 'all' ? 5 : 6) : ($filter === 'all' ? 4 : 5) ?>">No ingredients found<?php if(!empty($search)) echo " matching '" . htmlspecialchars($search) . "'"; ?>.</td></tr>
            <?php endif; ?>
             <?php if ($stmt_results ?? null) $stmt_results->close(); ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal for Add Ingredient -->
<div class="modal-overlay" id="addModal">
    <div class="modal-content">
        <h3>Confirm Action</h3>
        <p>Are you sure you want to add this ingredient?</p>
        <div class="modal-buttons">
            <button class="btn-yes" onclick="confirmAddIngredient()">Yes</button>
            <button class="btn-no" onclick="closeModal('addModal')">No</button>
        </div>
    </div>
</div>

<!-- Modal for Update Stock -->
<div class="modal-overlay" id="updateModal">
    <div class="modal-content">
        <h3>Confirm Action</h3>
        <p>Are you sure you want to update this stock?</p>
        <div class="modal-buttons">
            <button class="btn-yes" onclick="confirmUpdate()">Yes</button>
            <button class="btn-no" onclick="closeModal('updateModal')">No</button>
        </div>
    </div>
</div>

<!-- Modal for Delete Ingredient -->
<div class="modal-overlay" id="deleteModal">
    <div class="modal-content">
        <h3>Confirm Action</h3>
        <p id="deleteModalText">Are you sure you want to delete this ingredient?</p>
        <div class="modal-buttons">
            <button class="btn-yes" onclick="confirmDelete()">Yes</button>
            <button class="btn-no" onclick="closeModal('deleteModal')">No</button>
        </div>
    </div>
</div>

<script>
    // Modal handling
    let pendingForm = null;
    
    function openModal(modalId, form = null) {
        // Check if form is already confirmed (to prevent re-opening modal on submit)
        if (form && form.getAttribute('data-confirmed') === 'true') {
            return;
        }
        pendingForm = form;
        document.getElementById(modalId).classList.add('active');
    }
    
    function closeModal(modalId) {
        document.getElementById(modalId).classList.remove('active');
        pendingForm = null;
    }
    
    function confirmAddIngredient() {
        if (pendingForm) {
            // Create hidden input for the submit button
            const submitInput = document.createElement('input');
            submitInput.type = 'hidden';
            submitInput.name = 'add_ingredient';
            submitInput.value = '1';
            pendingForm.appendChild(submitInput);
            
            // Submit the original form with a flag to skip modal
            pendingForm.setAttribute('data-confirmed', 'true');
            pendingForm.submit();
        }
    }
    
    function confirmUpdate() {
        if (pendingForm) {
            // Create hidden input for the submit button
            const submitInput = document.createElement('input');
            submitInput.type = 'hidden';
            submitInput.name = 'update_ingredient';
            submitInput.value = '1';
            pendingForm.appendChild(submitInput);
            
            // Submit the original form with a flag to skip modal
            pendingForm.setAttribute('data-confirmed', 'true');
            pendingForm.submit();
        }
    }
    
    function confirmDelete() {
        if (pendingForm) {
            // Create hidden input for the submit button
            const submitInput = document.createElement('input');
            submitInput.type = 'hidden';
            submitInput.name = 'delete_ingredient';
            submitInput.value = '1';
            pendingForm.appendChild(submitInput);
            
            // Submit the original form with a flag to skip modal
            pendingForm.setAttribute('data-confirmed', 'true');
            pendingForm.submit();
        }
    }
    
    // Auto-update functionality
    let updateCheckInterval = null;
    const userRole = '<?= $user_role ?>';
    const currentFilter = '<?= $filter ?>';
    const currentSearch = '<?= htmlspecialchars($search, ENT_QUOTES) ?>';
    
    function fetchAndUpdateIngredients() {
        const params = new URLSearchParams({
            action: 'fetch_ingredients',
            filter: currentFilter,
            search: currentSearch
        });
        
        fetch(`inventory.php?${params}`)
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    console.error('Error:', data.error);
                    return;
                }
                
                updateIngredientsDisplay(data.ingredients);
            })
            .catch(error => {
                console.error('Error fetching ingredients:', error);
            });
    }
    
    function updateIngredientsDisplay(ingredients) {
        const tbody = document.getElementById('ingredients-tbody');
        if (!tbody) return;
        
        if (ingredients.length === 0) {
            const colSpan = (userRole === 'Admin') ? (currentFilter === 'all' ? 5 : 6) : (currentFilter === 'all' ? 4 : 5);
            tbody.innerHTML = `<tr id="no-ingredients-row"><td colspan="${colSpan}">No ingredients found${currentSearch ? ` matching '${escapeHtml(currentSearch)}'` : ''}.</td></tr>`;
            return;
        }
        
        // Build HTML for all ingredients
        let html = '';
        ingredients.forEach(ingredient => {
            html += buildIngredientRow(ingredient);
        });
        
        // Only update if content changed
        if (tbody.innerHTML !== html) {
            tbody.innerHTML = html;
            attachFormListeners();
        }
    }
    
    function buildIngredientRow(ingredient) {
        let stockCells = '';
        
        if (currentFilter === 'dry') {
            const kg = parseFloat(ingredient.stock);
            const g = kg * 1000;
            const mg = g * 1000;
            stockCells = `
                <td>${formatNumber(kg, 3)}</td>
                <td>${formatNumber(g, 2)}</td>
                <td>${formatNumber(mg, 0)}</td>
            `;
        } else if (currentFilter === 'wet') {
            const gal = parseFloat(ingredient.stock);
            const l = gal * 3.78541;
            const ml = l * 1000;
            stockCells = `
                <td>${formatNumber(gal, 3)}</td>
                <td>${formatNumber(l, 2)}</td>
                <td>${formatNumber(ml, 0)}</td>
            `;
        } else {
            const isDry = ingredient.type === 'Dry';
            stockCells = `
                <td>${isDry ? formatNumber(parseFloat(ingredient.stock), 3) : '---'}</td>
                <td>${!isDry ? formatNumber(parseFloat(ingredient.stock), 3) : '---'}</td>
            `;
        }
        
        let actionCell = '';
        if (userRole === 'Admin' || userRole === 'Moderator') {
            actionCell = `
                <form method="post" action="inventory.php?filter=${escapeHtml(currentFilter)}&search=${escapeHtml(currentSearch)}" onsubmit="event.preventDefault(); openModal('updateModal', this);">
                    <input type="hidden" name="ingredients_ID" value="${ingredient.ingredients_ID}">
                    <input type="number" step="any" name="stock" value="${parseFloat(ingredient.stock)}" min="0" required>
                    <button name="update_ingredient">Update</button>
                </form>
            `;
        } else {
            actionCell = '<span>View Only</span>';
        }
        
        let deleteCell = '';
        if (userRole === 'Admin') {
            deleteCell = `
                <td class="action-cell">
                    <form method="post" onsubmit="event.preventDefault(); document.getElementById('deleteModalText').textContent = 'Are you sure you want to delete ${escapeHtml(ingredient.ingredients)}? This cannot be undone!'; openModal('deleteModal', this);">
                        <input type="hidden" name="ingredients_ID" value="${ingredient.ingredients_ID}">
                        <button name="delete_ingredient">Delete</button>
                    </form>
                </td>
            `;
        }
        
        return `
            <tr data-ingredient-id="${ingredient.ingredients_ID}">
                <td>${escapeHtml(ingredient.ingredients)}</td>
                ${stockCells}
                <td class="action-cell">${actionCell}</td>
                ${deleteCell}
            </tr>
        `;
    }
    
    function formatNumber(num, decimals) {
        return num.toLocaleString('en-US', {
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals
        });
    }
    
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    function attachFormListeners() {
        // Re-attach modal handlers for dynamically loaded forms
        const updateForms = document.querySelectorAll('form[name="update_ingredient"]');
        updateForms.forEach(form => {
            form.onsubmit = function(e) {
                e.preventDefault();
                openModal('updateModal', this);
            };
        });
        
        const deleteForms = document.querySelectorAll('button[name="delete_ingredient"]');
        deleteForms.forEach(button => {
            const form = button.closest('form');
            if (form) {
                form.onsubmit = function(e) {
                    e.preventDefault();
                    const ingredientName = this.closest('tr').querySelector('td:first-child').textContent;
                    document.getElementById('deleteModalText').textContent = 
                        `Are you sure you want to delete ${ingredientName}? This cannot be undone!`;
                    openModal('deleteModal', this);
                };
            }
        });
    }
    
    function startAutoUpdate() {
        updateCheckInterval = setInterval(fetchAndUpdateIngredients, 60000);
    }
    
    function stopAutoUpdate() {
        if (updateCheckInterval) {
            clearInterval(updateCheckInterval);
            updateCheckInterval = null;
        }
    }
    
    document.addEventListener('DOMContentLoaded', function() {
        // Start auto-update
        startAutoUpdate();
        attachFormListeners();
        
        const hamburgerButton = document.getElementById('hamburger-button');
        const navLinks = document.getElementById('main-nav-links');

        if (hamburgerButton && navLinks) {
            hamburgerButton.addEventListener('click', function() {
                navLinks.classList.toggle('show-menu');
            });
        }

        // Autocomplete
        const datalist = document.getElementById('ingredient-list');
        async function fetchIngredientNames() {
            if (!datalist) return;
            try {
                const response = await fetch('inventory.php?fetch_ingredients_json=1');
                if (!response.ok) return;
                const ingredients = await response.json();
                if (ingredients && ingredients.length > 0) {
                    datalist.innerHTML = '';
                    ingredients.forEach(name => {
                        const option = document.createElement('option');
                        option.value = name;
                        datalist.appendChild(option);
                    });
                }
            } catch (error) {
                console.error('Autocomplete error:', error);
            }
        }
        fetchIngredientNames();
    });
    
    // Stop polling when user leaves the page
    window.addEventListener('beforeunload', function() {
        stopAutoUpdate();
    });
</script>

</body>
</html>
