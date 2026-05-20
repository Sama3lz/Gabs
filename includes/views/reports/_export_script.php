<script>
    function exportToPDF() {
        const { jsPDF } = window.jspdf;
        const doc = new jsPDF('l', 'mm', 'a4');
        const dateFrom = '<?= htmlspecialchars($date_from ?: "start of time") ?>';
        const dateTo = '<?= htmlspecialchars($date_to ?: "today") ?>';
        doc.setFontSize(18);
        doc.setTextColor(40);
        doc.text("Gab's Bakeshop - Sales Report", 148, 15, { align: 'center' });
        doc.setFontSize(11);
        doc.text(`Report Period: ${dateFrom} to ${dateTo}`, 148, 22, { align: 'center' });
        doc.text(`Generated: ${new Date().toLocaleString()}`, 148, 28, { align: 'center' });
        let yPosition = 35;
        const branchTable = document.getElementById('branchSalesTable');
        if (branchTable) {
            doc.setFontSize(14);
            doc.text('Branch Sales Subtotals', 14, yPosition);
            yPosition += 5;
            const branchRows = [];
            branchTable.querySelectorAll('tbody tr').forEach(row => {
                const cells = row.querySelectorAll('td');
                if (cells.length > 0) branchRows.push(Array.from(cells).map(c => c.textContent.trim()));
            });
            doc.autoTable({
                startY: yPosition,
                head: [['Branch Name', 'Location', 'Total Sales', 'Total Cost (COGS)', 'Total Income (Profit)']],
                body: branchRows,
                theme: 'striped',
                headStyles: { fillColor: [242, 161, 84], textColor: 255, fontStyle: 'bold' }
            });
            yPosition = doc.lastAutoTable.finalY + 15;
        }
        const productTable = document.getElementById('productSalesTable');
        if (productTable) {
            doc.setFontSize(14);
            doc.text('Sales Report by Product', 14, yPosition);
            yPosition += 5;
            const productRows = [];
            productTable.querySelectorAll('tbody tr').forEach(row => {
                const cells = row.querySelectorAll('td');
                if (cells.length > 0) productRows.push(Array.from(cells).map(c => c.textContent.trim()));
            });
            doc.autoTable({
                startY: yPosition,
                head: [['Product', 'Units Sold', 'Unit Cost', 'Total Cost (COGS)', 'Total Sales Value', 'Total Income (Profit)']],
                body: productRows,
                theme: 'striped',
                headStyles: { fillColor: [242, 161, 84], textColor: 255, fontStyle: 'bold' }
            });
        }
        doc.save(`Sales_Report_${dateFrom}_to_${dateTo}_${Date.now()}.pdf`);
    }
</script>
