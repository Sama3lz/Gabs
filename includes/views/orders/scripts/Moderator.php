<?php include __DIR__ . '/_core.php'; ?>
<script>
    function buildBatchHTML(batch, orders, batchKey) {
        const orderDate = new Date(batch.order_date);
        const formattedDate = orderDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) + ' ' +
            orderDate.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
        let html = `<div class="batch-card" data-batch-key="${escapeHtml(batchKey)}"><div class="batch-header"><h3>${escapeHtml(batch.branch_name)}<span>(Batch from: ${formattedDate})</span></h3><div class="batch-actions">`;
        if (batch.is_batch_pending == 1) {
            html += `<form method="post" action="orders.php" style="display:inline;" data-confirm="Approve ALL pending items?"><input type="hidden" name="batch_branch_id" value="${batch.branch_ID}"><input type="hidden" name="batch_date" value="${escapeHtml(batch.order_date)}"><button type="submit" name="approve_batch" class="btn btn-approve">Approve Batch</button></form>`;
            html += `<form method="post" action="orders.php" style="display:inline;" data-confirm="Deny ALL pending items?"><input type="hidden" name="batch_branch_id" value="${batch.branch_ID}"><input type="hidden" name="batch_date" value="${escapeHtml(batch.order_date)}"><button type="submit" name="deny_batch" class="btn btn-deny">Deny Batch</button></form>`;
        }
        if (batch.receipt_ID) {
            html += `<a href="receipt.php?receipt_id=${batch.receipt_ID}" class="btn btn-view">View Receipt</a>`;
        }
        html += `</div></div><div class="batch-body batch-body-scrollable"><table><thead><tr><th>Order ID</th><th>Product</th><th>Qty</th><th>Stock</th><th>Total Price</th><th>Status</th><th>Actions</th></tr></thead><tbody>`;
        orders.forEach(item => {
            html += `<tr><td>${item.order_ID}</td><td>${escapeHtml(item.product_name)}</td><td>${item.quantity}</td><td>${item.stock}</td><td>₱${parseFloat(item.total_price).toFixed(2)}</td><td><span class="status-badge status-${item.status.toUpperCase()}">${escapeHtml(item.status)}</span></td><td>`;
            if (item.status === 'Pending') {
                html += `<form method="post" action="orders.php" style="display:inline;" data-confirm="Approve?"><input type="hidden" name="order_ID" value="${item.order_ID}"><button type="submit" name="approve_order" class="btn btn-approve">Approve</button></form>`;
                html += `<form method="post" action="orders.php" style="display:inline;" data-confirm="Deny?"><input type="hidden" name="order_ID" value="${item.order_ID}"><button type="submit" name="deny_order" class="btn btn-deny">Deny</button></form>`;
            }
            html += `</td></tr>`;
        });
        html += `<tr class="batch-total-row"><td colspan="4">Batch Total:</td><td colspan="3">₱${parseFloat(batch.batch_total).toFixed(2)}</td></tr></tbody></table></div></div>`;
        return html;
    }
</script>
