<div class="page-content">
    <h1>Order Management</h1>
    <?php if (!empty($message)) echo $message; ?>

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
    </div>

    <?php if (empty($batches)): ?>
        <div class="message info" id="no-orders-message">No orders found for the selected filters.</div>
    <?php endif; ?>

    <div id="batches-container">
        <?php include __DIR__ . '/_batches_delivery.php'; ?>
    </div>

    <?php include __DIR__ . '/_pagination.php'; ?>
</div>
<?php include __DIR__ . '/scripts/Delivery.php'; ?>
