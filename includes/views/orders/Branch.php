<div class="page-content">
    <h1>Order Management</h1>
    <?php if (!empty($message)) echo $message; ?>

    <div class="message info" style="background: #e8f5e9; border-color: #c8e6c9; color: #2e7d32;">
        <strong>💡 Need to place an order?</strong> Click the <strong>"📋 Order Here - Place New Order"</strong> button below to create your batch order!
    </div>

    <div class="filter-bar">
        <div class="filter-group">
            <label for="status-filter">Status</label>
            <select id="status-filter" name="status" onchange="window.location.href = this.value;">
                <?php
                $statuses = ['all' => 'All Orders', 'pending' => 'Pending', 'approved' => 'Approved', 'delivered' => 'Delivered', 'denied' => 'Denied', 'cancelled' => 'Cancelled'];
                $base_url = "orders.php?";
                foreach ($statuses as $key => $value) {
                    $url = $base_url . http_build_query(['status' => $key]);
                    $selected = ($status_filter == $key) ? 'selected' : '';
                    echo "<option value=\"$url\" $selected>" . htmlspecialchars($value) . "</option>";
                }
                ?>
            </select>
        </div>
        <div class="filter-group" style="margin-left: auto;">
            <label>&nbsp;</label>
            <button id="new-order-btn" class="btn btn-primary" style="font-size: 1.1em; padding: 10px 20px;">
                📋 Order Here - Place New Order
            </button>
        </div>
    </div>

    <?php if (empty($batches)): ?>
        <div class="message info" id="no-orders-message">No orders found for the selected filters.</div>
    <?php endif; ?>

    <div id="batches-container">
        <?php include __DIR__ . '/_batches_branch.php'; ?>
    </div>

    <?php include __DIR__ . '/_pagination.php'; ?>
</div>

<div id="new-order-modal" class="modal">
    <div class="modal-content">
        <span class="modal-close" id="modal-close-btn">&times;</span>
        <h3>Place New Batch Order</h3>
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
                <tbody id="order-items-tbody"></tbody>
            </table>
        </div>
        <div id="modal-total-display">Total: ₱0.00</div>
        <form method="post" action="orders.php" id="place-order-form">
            <input type="hidden" name="order_items_json" id="order-items-json">
            <div class="modal-footer">
                <button type="button" id="modal-cancel-btn" class="btn btn-cancel">Cancel</button>
                <button type="submit" name="place_batch_order" class="btn btn-approve">Place Batch Order</button>
            </div>
        </form>
    </div>
</div>
<?php include __DIR__ . '/scripts/Branch.php'; ?>
