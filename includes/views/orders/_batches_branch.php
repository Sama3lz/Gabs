<?php foreach ($batches as $batch):
    $batch_key = $batch['branch_ID'] . "_" . $batch['order_date'];
    $batch_items = $orders[$batch_key] ?? [];
?>
    <div class="batch-card" data-batch-key="<?= htmlspecialchars($batch_key) ?>">
        <div class="batch-header">
            <h3>
                <?= htmlspecialchars($batch['branch_name']) ?>
                <span>(Batch from: <?= htmlspecialchars(date('M j, Y g:i A', strtotime($batch['order_date']))) ?>)</span>
            </h3>
            <div class="batch-actions">
                <?php if ($batch['is_batch_pending']): ?>
                    <form method="post" action="orders.php" style="display:inline;" data-confirm="Cancel this entire batch order? This cannot be undone.">
                        <input type="hidden" name="batch_branch_id" value="<?= $batch['branch_ID'] ?>">
                        <input type="hidden" name="batch_date" value="<?= htmlspecialchars($batch['order_date']) ?>">
                        <button type="submit" name="cancel_batch" class="btn btn-cancel">Cancel Batch</button>
                    </form>
                <?php endif; ?>
                <?php if ($batch['receipt_ID']): ?>
                    <a href="receipt.php?receipt_id=<?= $batch['receipt_ID'] ?>" class="btn btn-view">View Receipt</a>
                <?php endif; ?>
            </div>
        </div>
        <div class="batch-body batch-body-scrollable">
            <table>
                <thead>
                    <tr>
                        <th style="min-width: 70px;">Order ID</th>
                        <th style="min-width: 180px;">Product</th>
                        <th style="min-width: 80px;">Qty</th>
                        <th style="min-width: 100px;">Total Price</th>
                        <th style="min-width: 110px;">Status</th>
                        <th style="min-width: 180px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($batch_items as $item): ?>
                        <tr>
                            <td><?= $item['order_ID'] ?></td>
                            <td><?= htmlspecialchars($item['product_name']) ?></td>
                            <td><?= $item['quantity'] ?></td>
                            <td>₱<?= number_format($item['total_price'], 2) ?></td>
                            <td><span class="status-badge status-<?= strtoupper($item['status']) ?>"><?= htmlspecialchars($item['status']) ?></span></td>
                            <td></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="batch-total-row">
                        <td colspan="3">Batch Total:</td>
                        <td colspan="3">₱<?= number_format($batch['batch_total'], 2) ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
<?php endforeach; ?>
