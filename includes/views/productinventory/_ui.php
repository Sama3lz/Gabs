<div class="page-content">
    <h1>Inventory Management</h1>
    <?php if (!empty($msg)) echo $msg; ?>
    <div class="sub-nav">
        <?php if (!empty($pi_show_ingredients_tab)): ?>
            <a href="inventory.php">Ingredients</a>
        <?php endif; ?>
        <a href="productinventory.php" class="active-tab">Products</a>
    </div>
    <h2>Products</h2>
    <?php if (!empty($pi_can_delete)): ?>
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
                <?php if (!empty($pi_can_manage)): ?>
                <th class="action-cell">Update</th>
                <th class="action-cell">Recipe Management</th>
                <th class="action-cell">Production/Delete</th>
                <?php endif; ?>
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
                                    <span>• " . htmlspecialchars($r['ingredients']) . " (" . format_recipe_quantity($r['qty_per_unit'], $r['type']) . ")</span>";
                             if (!empty($pi_can_manage)) {
                                 echo "<form method='post' style='display: inline; margin: 0;'>
                                        <input type='hidden' name='pi_id' value='{$r['id']}'>
                                        <button type='submit' name='remove_recipe_item' class='recipe-remove-btn'>x</button>
                                    </form>";
                             }
                             echo "</div>";
                        }
                    } else echo "No recipe defined.";
                    $recipe_q->close();
                    ?>
                    </div>
                </td>
                <?php if (!empty($pi_can_manage)): ?>
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
                    <?php if (!empty($pi_can_delete)): ?>
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
<?php include __DIR__ . '/_scripts.php'; ?>
