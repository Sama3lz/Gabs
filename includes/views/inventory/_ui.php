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
    <?php if (!empty($inv_can_add)): ?>
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
                    <?php if (!empty($inv_can_delete)): ?><th rowspan="2">Delete</th><?php endif; ?>
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
                        <?php if (!empty($inv_can_update)): ?>
                        <form method="post" action="inventory.php?filter=<?= htmlspecialchars($filter) ?>&search=<?= htmlspecialchars($search) ?>" onsubmit="event.preventDefault(); openModal('updateModal', this);">
                            <input type="hidden" name="ingredients_ID" value="<?= $row['ingredients_ID'] ?>">
                            <input type="number" step="any" name="stock" value="<?= floatval($row['stock']) ?>" min="0" required>
                            <button name="update_ingredient">Update</button>
                        </form>
                        <?php else: ?>
                        <span>View Only</span>
                        <?php endif; ?>
                    </td>
                    <?php if (!empty($inv_can_delete)): ?>
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
                <?php
                    $colspan = (!empty($inv_can_delete))
                        ? ($filter === 'all' ? 5 : 6)
                        : ($filter === 'all' ? 4 : 5);
                ?>
                <tr id="no-ingredients-row"><td colspan="<?= $colspan ?>">No ingredients found<?php if(!empty($search)) echo " matching '" . htmlspecialchars($search) . "'"; ?>.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php if (!empty($inv_can_add) || !empty($inv_can_update) || !empty($inv_can_delete)): include __DIR__ . '/_modals.php'; endif; ?>
<?php include __DIR__ . '/_scripts.php'; ?>
