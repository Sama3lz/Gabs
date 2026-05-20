<?php
$servername = getenv('DB_HOST') ?: "localhost";
$username = getenv('DB_USER') ?: "root";
$password = getenv('DB_PASS') ?: "";
$dbname = getenv('DB_NAME') ?: "gabsdatabase";

$conn = null;

mysqli_report(MYSQLI_REPORT_OFF);

$server = @new mysqli($servername, $username, $password);
if ($server->connect_error) {
    error_log("Database server connection failed: " . $server->connect_error);
    return;
}

$safeDb = $server->real_escape_string($dbname);
if (!$server->query("CREATE DATABASE IF NOT EXISTS `{$safeDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) {
    error_log("Could not create database '{$dbname}': " . $server->error);
    $server->close();
    return;
}

$conn = @new mysqli($servername, $username, $password, $dbname);
$server->close();

if ($conn->connect_error) {
    error_log("Database connection failed: " . $conn->connect_error);
    $conn = null;
    return;
}

$conn->set_charset("utf8mb4");

$tableCheck = $conn->query("SHOW TABLES LIKE 'accounts'");
if ($tableCheck && $tableCheck->num_rows === 0) {
    $schemaFile = __DIR__ . '/database/gabsdatabase.sql';
    if (is_readable($schemaFile)) {
        $sql = file_get_contents($schemaFile);
        $sql = preg_replace('/^CREATE DATABASE.*?;/ims', '', $sql);
        $sql = preg_replace('/^USE\s+`?[\w]+`?\s*;/im', '', $sql);
        if ($conn->multi_query($sql)) {
            while ($conn->more_results() && $conn->next_result()) {
                // flush remaining results from multi_query
            }
        } else {
            error_log("Schema import failed: " . $conn->error);
        }
    }
}
if ($tableCheck) {
    $tableCheck->close();
}
