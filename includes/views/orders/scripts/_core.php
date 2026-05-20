<script>
    let updateCheckInterval = null;
    const currentStatusFilter = '<?= $status_filter ?>';
    const currentBranchFilter = '<?= $branch_filter ?? 'all' ?>';

    function fetchAndUpdateOrders() {
        const params = new URLSearchParams({
            action: 'fetch_orders',
            status: currentStatusFilter,
            branch: currentBranchFilter
        });
        fetch(`orders.php?${params}`)
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    console.error('Error:', data.error);
                    return;
                }
                updateOrdersDisplay(data);
            })
            .catch(error => console.error('Error fetching orders:', error));
    }

    function updateOrdersDisplay(data) {
        const container = document.getElementById('batches-container');
        const noOrdersMsg = document.getElementById('no-orders-message');
        if (data.batches.length === 0) {
            container.innerHTML = '';
            if (noOrdersMsg) noOrdersMsg.style.display = 'block';
            return;
        }
        if (noOrdersMsg) noOrdersMsg.style.display = 'none';
        let html = '';
        data.batches.forEach(batch => {
            const batchKey = `${batch.branch_ID}_${batch.order_date}`;
            html += buildBatchHTML(batch, data.orders[batchKey] || [], batchKey);
        });
        if (container.innerHTML !== html) {
            container.innerHTML = html;
            attachFormListeners();
        }
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function attachFormListeners() {
        document.querySelectorAll('form:not(#place-order-form)').forEach(form => {
            form.removeEventListener('submit', handleFormSubmit);
            form.addEventListener('submit', handleFormSubmit);
        });
    }

    function handleFormSubmit(e) {
        const confirmMessage = this.dataset.confirm;
        if (confirmMessage && !confirm(confirmMessage)) {
            e.preventDefault();
        }
    }

    function startAutoUpdate() {
        updateCheckInterval = setInterval(fetchAndUpdateOrders, 60000);
    }

    function stopAutoUpdate() {
        if (updateCheckInterval) {
            clearInterval(updateCheckInterval);
            updateCheckInterval = null;
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        startAutoUpdate();
        attachFormListeners();
    });

    window.addEventListener('beforeunload', stopAutoUpdate);
</script>
