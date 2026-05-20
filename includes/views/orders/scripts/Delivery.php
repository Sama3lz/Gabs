<?php include __DIR__ . '/_core.php'; ?>
<script>
    function buildBatchHTML(batch, orders, batchKey) {
        const orderDate = new Date(batch.order_date);
        const formattedDate = orderDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) + ' ' +
            orderDate.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
        let html = `<div class="batch-card" data-batch-key="${escapeHtml(batchKey)}"><div class="batch-header"><h3>${escapeHtml(batch.branch_name)}<span>(Batch from: ${formattedDate})</span></h3><div class="batch-actions">`;
        if (batch.has_approved == 1 && batch.all_items_delivered == 0) {
            html += `<form method="post" action="orders.php" class="batch-delivery-form" enctype="multipart/form-data" data-confirm="Mark as Delivered?"><input type="hidden" name="batch_branch_id" value="${batch.branch_ID}"><input type="hidden" name="batch_date" value="${escapeHtml(batch.order_date)}"><div><label style="font-size:0.8em;font-weight:bold;color:#c0392b;">Proof Image (Required)*:</label><input type="file" name="delivery_proof" required></div><button type="submit" name="deliver_batch" class="btn btn-deliver">Mark as Delivered</button></form>`;
        }
        html += `</div></div><div class="batch-body batch-body-scrollable"><table><thead><tr><th>Order ID</th><th>Product</th><th>Qty</th><th>Total Price</th><th>Status</th><th>Actions</th></tr></thead><tbody>`;
        orders.forEach(item => {
            html += `<tr><td>${item.order_ID}</td><td>${escapeHtml(item.product_name)}</td><td>${item.quantity}</td><td>₱${parseFloat(item.total_price).toFixed(2)}</td><td><span class="status-badge status-${item.status.toUpperCase()}">${escapeHtml(item.status)}</span></td><td></td></tr>`;
        });
        html += `<tr class="batch-total-row"><td colspan="3">Batch Total:</td><td colspan="3">₱${parseFloat(batch.batch_total).toFixed(2)}</td></tr></tbody></table></div></div>`;
        return html;
    }
</script>
