<?php
session_start();
include("gabsdb.php"); // Assume $conn is the mysqli connection

// Access Control: Must be logged in to export.
if (!isset($_SESSION['user_ID']) || !in_array($_SESSION['role'], ['Admin', 'Moderator', 'Branch'])) {
    header("HTTP/1.1 403 Forbidden");
    exit("Access Denied.");
}

$user_id = $_SESSION['user_ID'] ?? '';
$user_role = $_SESSION['role'] ?? '';

// --- BATCH-SPECIFIC FILTER LOGIC ---
if (!isset($_GET['batch_branch_id']) || !isset($_GET['batch_date']) || !is_object($conn)) {
    header("HTTP/1.1 400 Bad Request");
    exit("Missing required batch information.");
}

$batch_branch_id = (int)($_GET['batch_branch_id'] ?? 0);
$batch_date = trim($_GET['batch_date'] ?? '');

if (
    $batch_branch_id <= 0 ||
    !preg_match('/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}:\d{2})?$/', $batch_date)
) {
    header("HTTP/1.1 400 Bad Request");
    exit("Invalid batch information.");
}

// Security check: A Branch user can ONLY export their own receipts.
if ($user_role === 'Branch' && $batch_branch_id != $user_id) {
    header("HTTP/1.1 403 Forbidden");
    exit("Access Denied: You do not have permission to export this receipt.");
}

// Fetch branch details for the filename and header
$branch_info_stmt = $conn->prepare("SELECT name, Location FROM accounts WHERE user_ID = ? LIMIT 1");
if (!$branch_info_stmt) {
    header("HTTP/1.1 500 Internal Server Error");
    exit("Database query preparation failed.");
}
$branch_info_stmt->bind_param("i", $batch_branch_id);
$branch_info_stmt->execute();
$branch_info_result = $branch_info_stmt->get_result();
$branch_info = $branch_info_result ? $branch_info_result->fetch_assoc() : null;
$branch_info_stmt->close();
$branch_name = $branch_info['name'] ?? 'UnknownBranch';

// --- SQL QUERY TO FETCH THE BATCH DATA ---
$sql_batch_data = "
    SELECT
        o.order_ID,
        p.product_name,
        p.price,
        o.quantity,
        (o.quantity * p.price) AS subtotal,
        o.status
    FROM
        orders o
    JOIN
        products p ON o.product_ID = p.product_ID
    WHERE
        o.branch_ID = ? AND o.order_date = ?
    ORDER BY
        o.order_ID ASC";

$batch_data_stmt = $conn->prepare($sql_batch_data);
if (!$batch_data_stmt) {
    header("HTTP/1.1 500 Internal Server Error");
    exit("Database query preparation failed.");
}
$batch_data_stmt->bind_param("is", $batch_branch_id, $batch_date);
$batch_data_stmt->execute();
$result = $batch_data_stmt->get_result();

if (!$result) {
    header("HTTP/1.1 500 Internal Server Error");
    // Log the actual error for debugging
    error_log("Database query failed in export_receipt.php: " . $conn->error); 
    exit("Database query failed.");
}

// --- GENERATE CSV AND TRIGGER DOWNLOAD ---
// Sanitize branch name for filename
$safe_branch_name = preg_replace('/[^a-zA-Z0-9_-]/', '', $branch_name);
$filename = "Receipt_" . $safe_branch_name . "_" . date('Ymd_His', strtotime($batch_date)) . ".csv";

header('Content-Type: text/csv; charset=utf-8'); // Added charset
header('Content-Disposition: attachment; filename="' . $filename . '"');

// Open a file handle to the output stream
$output = fopen('php://output', 'w');

// --- WRITE CSV HEADERS ---
// Add BOM for better Excel compatibility with UTF-8 characters if needed
// fwrite($output, "\xEF\xBB\xBF"); 

fputcsv($output, ['Gab\'s Bakeshop - Order Receipt']);
fputcsv($output, ['Branch:', $branch_info['name'] ?? 'N/A']);
fputcsv($output, ['Location:', $branch_info['Location'] ?? 'N/A']);
fputcsv($output, ['Order Date:', date('F j, Y, g:i:s A', strtotime($batch_date))]);
fputcsv($output, []); // Add a blank line for spacing

fputcsv($output, [
    'Order ID',
    'Product',
    'Unit Price',
    'Quantity',
    'Subtotal',
    'Status'
]);

// --- WRITE CSV DATA ROWS ---
$grand_total = 0;
while ($row = $result->fetch_assoc()) {
    $grand_total += $row['subtotal'];
    fputcsv($output, [
        $row['order_ID'],
        $row['product_name'],
        number_format($row['price'], 2),
        $row['quantity'],
        number_format($row['subtotal'], 2),
        $row['status']
    ]);
}

// --- WRITE CSV FOOTER ---
fputcsv($output, []); // Blank line
fputcsv($output, ['', '', '', 'GRAND TOTAL:', number_format($grand_total, 2)]); // Added colon for clarity

fclose($output);
$batch_data_stmt->close();
exit();
?>

