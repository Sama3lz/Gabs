<div class="page-content">
    <h1>System Reports Dashboard</h1>

     <?php
         if (!empty($msg)) echo "<div class='message success'>{$msg}</div>";
         if (!empty($error)) echo "<div class='message error'>{$error}</div>";
    ?>

     <div class="sub-nav">
        <a href="reports.php" class="<?= ($current_page == 'reports.php') ? 'active-tab' : '' ?>">Sales Reports</a>
             <a href="ingredienthistory.php" class="<?= ($current_page == 'ingredienthistory.php') ? 'active-tab' : '' ?>">Ingredient Audit</a>
             <a href="producthistory.php" class="<?= ($current_page == 'producthistory.php') ? 'active-tab' : '' ?>">Product Audit</a>
         <a href="receipt.php" class="<?= ($current_page == 'receipt.php') ? 'active-tab' : '' ?>">Delivery Receipts</a>
    </div>

    <h2>Date Filter</h2>
    <form method="GET" action="reports.php" class="sub-nav no-print" style="border-bottom: 1px solid #eee; margin-bottom: 15px; padding-bottom: 10px; gap: 15px; justify-content: flex-start;">
        <div style="display: flex; flex-direction: column; flex-grow: 0; min-width: 150px;">
            <label for="date_from" style="font-weight: bold; margin-bottom: 5px; color: #333; font-size: 0.95em;">Date From:</label>
            <input type="date" id="date_from" name="date_from" value="<?= htmlspecialchars($date_from) ?>" 
                 style="padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-size: 0.9em;">
        </div>
        
        <div style="display: flex; flex-direction: column; flex-grow: 0; min-width: 150px;">
            <label for="date_to" style="font-weight: bold; margin-bottom: 5px; color: #333; font-size: 0.95em;">Date To:</label>
            <input type="date" id="date_to" name="date_to" value="<?= htmlspecialchars($date_to) ?>" 
                 style="padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-size: 0.9em;">
        </div>
        
        <div style="display: flex; align-items: flex-end; gap: 10px;">
            <button type="submit" class="logout-link" 
                 style="margin-left: 0; background-color: #f2a154 !important; color: white !important; height: 38px; line-height: 18px; padding: 10px 15px; align-self: flex-end; border: none;">Apply Filter</button>
            
            <button type="reset" onclick="window.location.href='reports.php';" class="logout-link" 
                 style="margin-left: 0; background-color: #ddd !important; color: #333 !important; height: 38px; line-height: 18px; padding: 10px 15px; align-self: flex-end; border: none;">Reset</button>
        </div>
    </form>

    <div class="export-container no-print">
        <button onclick="exportToPDF()" class="pdf-export-btn">ðŸ“„ Export to PDF</button>
    </div>

    <h2>Branch Sales Subtotals</h2>
    <p style="text-align: center;">
    <?php if (!empty($date_from) || !empty($date_to)): ?>
        <strong>Filter Applied:</strong> Showing sales from <strong><?= htmlspecialchars($date_from ?: 'start of time') ?></strong> to <strong><?= htmlspecialchars($date_to ?: 'today') ?></strong>.
    <?php endif; ?>
    </p>
    <div class="table-container">
        <table class="branch-sales-table" id="branchSalesTable">
            <thead>
                <tr>
                    <th>Branch Name</th>
                    <th>Location</th>
                    <th>Total Sales</th>
                    <th>Total Cost (COGS)</th>
                    <th>Total Income (Profit)</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($branch_sales_report && $branch_sales_report->num_rows > 0): ?>
                    <?php 
                    $grand_total_branch = 0; 
                    $grand_total_branch_cost = 0;
                    $grand_total_branch_profit = 0;
                    ?>
                    <?php while($row = $branch_sales_report->fetch_assoc()): ?>
                    <?php 
                        $grand_total_branch += $row['branch_subtotal']; 
                        $grand_total_branch_cost += $row['branch_total_cost'];
                        $grand_total_branch_profit += $row['branch_total_profit'];
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($row['branch_name']) ?></td>
                        <td><?= htmlspecialchars($row['branch_location'] ?? 'N/A') ?></td>
                        <td class="col-money">â‚±<?= number_format($row['branch_subtotal'], 2) ?></td>
                        <td class="col-money">â‚±<?= number_format($row['branch_total_cost'], 2) ?></td>
                        <td class="col-money" style="font-weight:bold; <?= $row['branch_total_profit'] < 0 ? 'color:red;' : 'color:green;' ?>">â‚±<?= number_format($row['branch_total_profit'], 2) ?></td>
                    </tr>
                    <?php endwhile; ?>
                    <tr class="grand-total-row">
                        <td colspan="2">GRAND TOTAL (ALL BRANCHES SHOWN):</td>
                        <td class="col-money">â‚±<?= number_format($grand_total_branch, 2) ?></td>
                        <td class="col-money">â‚±<?= number_format($grand_total_branch_cost, 2) ?></td>
                        <td class="col-money" style="font-weight:bold; <?= $grand_total_branch_profit < 0 ? 'color:red;' : 'color:green;' ?>">â‚±<?= number_format($grand_total_branch_profit, 2) ?></td>
                    </tr>
                <?php else: ?>
                    <tr><td colspan="5">No delivered sales records found.</td></tr>
                <?php endif; ?>
                 <?php if($stmt_branch) $stmt_branch->close(); ?>
            </tbody>
        </table>
    </div>

    <hr>

    <h2>Sales Report by Product</h2>
    <p style="text-align: center;">
    <?php if (!empty($date_from) || !empty($date_to)): ?>
        <strong>Filter Applied:</strong> Showing sales from <strong><?= htmlspecialchars($date_from ?: 'start of time') ?></strong> to <strong><?= htmlspecialchars($date_to ?: 'today') ?></strong>.
    <?php endif; ?>
    </p>
    
    <div class="table-container">
        <table class="product-sales-table" id="productSalesTable">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Units Sold</th>
                    <th>Unit Cost</th>
                    <th>Total Cost (COGS)</th>
                    <th>Total Sales Value</th>
                    <th>Total Income (Profit)</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($sales_report && $sales_report->num_rows > 0): ?>
                    <?php 
                    $grand_total_product = 0; 
                    $grand_total_cost = 0;
                    $grand_total_profit = 0;
                    ?>
                    <?php while($row = $sales_report->fetch_assoc()): ?>
                    <?php 
                        $grand_total_product += $row['total_sales_value']; 
                        $grand_total_cost += $row['total_cost_of_goods'];
                        $grand_total_profit += $row['total_profit'];
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($row['product_name']) ?></td>
                        <td class="col-numeric"><?= number_format($row['total_quantity_sold']) ?></td>
                        <td class="col-money">â‚±<?= number_format($row['unit_cost'], 2) ?></td>
                        <td class="col-money">â‚±<?= number_format($row['total_cost_of_goods'], 2) ?></td>
                        <td class="col-money">â‚±<?= number_format($row['total_sales_value'], 2) ?></td>
                        <td class="col-money" style="font-weight:bold; <?= $row['total_profit'] < 0 ? 'color:red;' : 'color:green;' ?>">â‚±<?= number_format($row['total_profit'], 2) ?></td>
                    </tr>
                    <?php endwhile; ?>
                    <tr class="grand-total-row">
                        <td colspan="2">GRAND TOTAL (ALL PRODUCTS):</td>
                        <td class="col-money">---</td>
                        <td class="col-money">â‚±<?= number_format($grand_total_cost, 2) ?></td>
                        <td class="col-money">â‚±<?= number_format($grand_total_product, 2) ?></td>
                        <td class="col-money" style="font-weight:bold; <?= $grand_total_profit < 0 ? 'color:red;' : 'color:green;' ?>">â‚±<?= number_format($grand_total_profit, 2) ?></td>
                    </tr>
                <?php else: ?>
                    <tr><td colspan="6">No delivered sales records found for any product.</td></tr>
                <?php endif; ?>
                 <?php if($stmt_product ?? null) $stmt_product->close(); ?>
            </tbody>
        </table>
    </div>

</div>