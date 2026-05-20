<?php include __DIR__ . '/_core.php'; ?>
<script>
    function buildBatchHTML(batch, orders, batchKey) {
        const orderDate = new Date(batch.order_date);
        const formattedDate = orderDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) + ' ' +
            orderDate.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
        let html = `<div class="batch-card" data-batch-key="${escapeHtml(batchKey)}"><div class="batch-header"><h3>${escapeHtml(batch.branch_name)}<span>(Batch from: ${formattedDate})</span></h3><div class="batch-actions">`;
        if (batch.is_batch_pending == 1) {
            html += `<form method="post" action="orders.php" style="display:inline;" data-confirm="Cancel this batch?"><input type="hidden" name="batch_branch_id" value="${batch.branch_ID}"><input type="hidden" name="batch_date" value="${escapeHtml(batch.order_date)}"><button type="submit" name="cancel_batch" class="btn btn-cancel">Cancel Batch</button></form>`;
        }
        if (batch.receipt_ID) {
            html += `<a href="receipt.php?receipt_id=${batch.receipt_ID}" class="btn btn-view">View Receipt</a>`;
        }
        html += `</div></div><div class="batch-body batch-body-scrollable"><table><thead><tr><th>Order ID</th><th>Product</th><th>Qty</th><th>Total Price</th><th>Status</th><th>Actions</th></tr></thead><tbody>`;
        orders.forEach(item => {
            html += `<tr><td>${item.order_ID}</td><td>${escapeHtml(item.product_name)}</td><td>${item.quantity}</td><td>₱${parseFloat(item.total_price).toFixed(2)}</td><td><span class="status-badge status-${item.status.toUpperCase()}">${escapeHtml(item.status)}</span></td><td></td></tr>`;
        });
        html += `<tr class="batch-total-row"><td colspan="3">Batch Total:</td><td colspan="3">₱${parseFloat(batch.batch_total).toFixed(2)}</td></tr></tbody></table></div></div>`;
        return html;
    }

    document.addEventListener('DOMContentLoaded', function() {
        const modal = document.getElementById('new-order-modal');
        const newOrderBtn = document.getElementById('new-order-btn');
        const closeBtn = document.getElementById('modal-close-btn');
        const cancelBtn = document.getElementById('modal-cancel-btn');
        const productSelect = document.getElementById('product-select');
        const quantityInput = document.getElementById('product-quantity');
        const addItemBtn = document.getElementById('add-item-btn');
        const orderTbody = document.getElementById('order-items-tbody');
        const totalDisplay = document.getElementById('modal-total-display');
        const orderForm = document.getElementById('place-order-form');
        const orderJsonInput = document.getElementById('order-items-json');
        let productsData = [];
        let currentOrder = [];

        function fetchProducts() {
            if (productsData.length === 0) {
                fetch('orders.php?action=fetch_products')
                    .then(r => r.json())
                    .then(data => {
                        if (data.error) alert('Error fetching products: ' + data.error);
                        else { productsData = data.products; populateProductSelect(); }
                    });
            }
        }
        function populateProductSelect() {
            productSelect.innerHTML = '<option value="">Select a product...</option>';
            productsData.forEach(p => {
                productSelect.innerHTML += `<option value="${p.product_ID}" data-price="${p.price}">${p.product_name} (₱${p.price})</option>`;
            });
        }
        function renderOrderTable() {
            orderTbody.innerHTML = currentOrder.length ? '' : '<tr><td colspan="5" style="text-align:center;">No items added yet.</td></tr>';
            currentOrder.forEach((item, index) => {
                const subtotal = item.price * item.quantity;
                orderTbody.innerHTML += `<tr><td>${item.name}</td><td style="text-align:right;">₱${item.price.toFixed(2)}</td><td style="text-align:right;">${item.quantity}</td><td style="text-align:right;">₱${subtotal.toFixed(2)}</td><td style="text-align:center;"><button type="button" class="btn-remove-item" data-index="${index}">&times;</button></td></tr>`;
            });
            document.querySelectorAll('.btn-remove-item').forEach(btn => {
                btn.addEventListener('click', function() {
                    currentOrder.splice(parseInt(this.dataset.index), 1);
                    renderOrderTable();
                    updateTotal();
                });
            });
        }
        function updateTotal() {
            const total = currentOrder.reduce((acc, item) => acc + item.price * item.quantity, 0);
            totalDisplay.textContent = `Total: ₱${total.toFixed(2)}`;
        }

        if (newOrderBtn) newOrderBtn.addEventListener('click', () => { modal.style.display = 'flex'; fetchProducts(); stopAutoUpdate(); });
        if (closeBtn) closeBtn.addEventListener('click', () => { modal.style.display = 'none'; startAutoUpdate(); });
        if (cancelBtn) cancelBtn.addEventListener('click', () => { modal.style.display = 'none'; startAutoUpdate(); });
        window.addEventListener('click', e => { if (e.target === modal) { modal.style.display = 'none'; startAutoUpdate(); } });
        if (addItemBtn) addItemBtn.addEventListener('click', function() {
            const opt = productSelect.options[productSelect.selectedIndex];
            if (!opt.value) { alert('Please select a product.'); return; }
            const id = parseInt(opt.value);
            const name = opt.text.split(' (₱')[0];
            const price = parseFloat(opt.dataset.price);
            const quantity = parseInt(quantityInput.value);
            if (isNaN(quantity) || quantity < 1) { alert('Please enter a valid quantity.'); return; }
            const existing = currentOrder.find(i => i.id === id);
            if (existing) existing.quantity += quantity;
            else currentOrder.push({ id, name, price, quantity });
            renderOrderTable();
            updateTotal();
            productSelect.value = '';
            quantityInput.value = '1';
        });
        if (orderForm) orderForm.addEventListener('submit', function(e) {
            if (currentOrder.length === 0) { e.preventDefault(); alert('Add at least one product.'); return; }
            orderJsonInput.value = JSON.stringify(currentOrder);
            startAutoUpdate();
        });
        renderOrderTable();
    });
</script>
