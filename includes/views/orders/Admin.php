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
    </div>

    <?php if (empty($batches)): ?>
        <div class="message info" id="no-orders-message">No orders found for the selected filters.</div>
    <?php endif; ?>

    <div id="batches-container">
        <?php include __DIR__ . '/_batches_admin.php'; ?>
    </div>

    <?php include __DIR__ . '/_pagination.php'; ?>
</div>
<?php include __DIR__ . '/scripts/Admin.php'; ?>
