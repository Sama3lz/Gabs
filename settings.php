<?php
session_start();
include("gabsdb.php"); // Assumes $conn is available globally
require_once __DIR__ . "/includes/auth_helpers.php";

// --- 1. Embedded Access Control ---
// Ensure $conn is available after include
if (!isset($conn) || !$conn instanceof mysqli) {
    error_log("Database connection failed in settings.php");
    $_SESSION['message'] = "<div class='message error'>❌ Database connection is unavailable.</div>";
    header("Location: index.php");
    exit();
}

// Access Control: Must be logged in
require_login();

$user_id = $_SESSION['user_ID'];
$user_role = $_SESSION['role'] ?? ''; // Use null coalescing

// --- 2. Embedded Session Message Handling ---
$msg = "";
$error = "";
if (isset($_SESSION['message'])) {
    // Basic check, assumes messages are already wrapped in divs or simple strings
    if (strpos($_SESSION['message'], 'error') !== false || strpos($_SESSION['message'], '❌') !== false) {
         if (strpos($_SESSION['message'], '<div') === false) { $error = "<div class='message error'>" . htmlspecialchars($_SESSION['message']) . "</div>"; } else { $error = $_SESSION['message']; }
    } else {
         if (strpos($_SESSION['message'], '<div') === false) { $msg = "<div class='message success'>" . htmlspecialchars($_SESSION['message']) . "</div>"; } else { $msg = $_SESSION['message']; }
    }
    unset($_SESSION['message']);
}

// --- 3. Page Variables & Navbar Logic ---
$page_title = "User Settings - Gab's Bakeshop";
$current_page = basename($_SERVER['PHP_SELF']);

apply_nav_permissions($user_role);


// --- 4. Fetch current user data ---
// Use prepared statement for security
$fetch_user_sql = "SELECT username, email, name, Location FROM accounts WHERE user_ID = ?";
$stmt_fetch = $conn->prepare($fetch_user_sql);
$user_data = null; // Initialize
if ($stmt_fetch) {
    $stmt_fetch->bind_param("i", $user_id);
    $stmt_fetch->execute();
    $user_result = $stmt_fetch->get_result();
    $user_data = $user_result->fetch_assoc();
    $stmt_fetch->close();
    if (!$user_data) {
        // Handle case where user data couldn't be fetched
        session_unset();
        session_destroy();
        header("Location: index.php");
        exit("User data not found. Please log in again.");
    }
} else {
    $error .= " | ❌ Error preparing user data query: " . htmlspecialchars($conn->error);
}


// --- 5. Handle Profile Update ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $new_name = trim($_POST['new_name'] ?? '');
    $new_username = trim($_POST['new_username'] ?? '');
    // Location update depends on role
    $new_location = (in_array($user_role, ['Branch', 'Admin'])) ? trim($_POST['new_location'] ?? '') : ($user_data['Location'] ?? null);

    if (empty($new_name) || empty($new_username)) {
         $error = "<div class='message error'>❌ Display Name and Username cannot be empty.</div>";
    } else {
        $update_sql = "UPDATE accounts SET name = ?, username = ?";
        $params = [$new_name, $new_username];
        $types = "ss";

        if (in_array($user_role, ['Branch', 'Admin'])) {
            $update_sql .= ", Location = ?";
            $params[] = $new_location; // Add location param
            $types .= "s";
        }

        $update_sql .= " WHERE user_ID = ?";
        $params[] = $user_id; // Add user_id param
        $types .= "i";

        $stmt_update = $conn->prepare($update_sql);
        if ($stmt_update) {
            $stmt_update->bind_param($types, ...$params);

            if ($stmt_update->execute()) {
                $msg = "<div class='message success'>✅ Profile details updated successfully! Some changes might require re-login.</div>";
                // Update session variables immediately
                $_SESSION['name'] = $new_name;
                // Re-assign user_data array to reflect changes
                $user_data['name'] = $new_name;
                $user_data['username'] = $new_username;
                if (in_array($user_role, ['Branch', 'Admin'])) {
                    $user_data['Location'] = $new_location;
                }
            } else {
                if ($conn->errno == 1062) {
                     $error = "<div class='message error'>❌ Error updating profile: The username '{$new_username}' is already taken.</div>";
                } else {
                     $error = "<div class='message error'>❌ Error updating profile: " . htmlspecialchars($stmt_update->error) . "</div>";
                }
            }
            $stmt_update->close();
        } else {
            $error = "<div class='message error'>❌ Error preparing profile update: " . htmlspecialchars($conn->error) . "</div>";
        }
    }
}

// --- 6. Handle Password Change (secure hash with legacy support) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $error = "<div class='message error'>❌ All password fields are required.</div>";
    } elseif ($new_password !== $confirm_password) {
        $error = "<div class='message error'>❌ New password and confirmation do not match.</div>";
    } else {
        // 1. Read current password hash/value
        $verify_sql = "SELECT password FROM accounts WHERE user_ID = ?";
        $stmt_v = $conn->prepare($verify_sql);
        $db_password = null;
        if($stmt_v) {
            $stmt_v->bind_param("i", $user_id);
            $stmt_v->execute();
            $stmt_v->bind_result($db_password);
            $stmt_v->fetch();
            $stmt_v->close();
        } else {
             $error = "<div class='message error'>❌ Error preparing password verification: " . htmlspecialchars($conn->error) . "</div>";
        }

        $is_verified = false;
        if ($db_password) {
            $password_info = password_get_info($db_password);
            if (($password_info['algo'] ?? 0) !== 0) {
                $is_verified = password_verify($current_password, $db_password);
            } else {
                // Legacy plain-text rows: verify once, then migrate to hash on update.
                $is_verified = hash_equals((string)$db_password, $current_password);
            }
        }

        if ($is_verified) {
            // 2. Always store as hash
            $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);

            // 3. Update password in DB
            $update_pass_sql = "UPDATE accounts SET password = ? WHERE user_ID = ?";
            $stmt_p = $conn->prepare($update_pass_sql);
            if ($stmt_p) {
                $stmt_p->bind_param("si", $new_password_hash, $user_id);
                if ($stmt_p->execute()) {
                    $msg = "<div class='message success'>✅ Password changed successfully!</div>";
                } else {
                    $error = "<div class='message error'>❌ Database error: Could not change password. " . htmlspecialchars($stmt_p->error)."</div>";
                }
                $stmt_p->close();
            } else {
                $error = "<div class='message error'>❌ Error preparing password update: " . htmlspecialchars($conn->error) . "</div>";
            }
        } elseif ($db_password) {
            // Verification failed
             $error = "<div class='message error'>❌ Current password entered is incorrect.</div>";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <!-- ****** RESPONSIVE: Added Viewport Meta Tag ****** -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?></title>
    <link rel="stylesheet" href="settings.css">
</head>
<body>
    <!-- ****** EMBEDDED Navbar HTML ****** -->
    <?php include_role_navbar(); ?>
    <?php include_role_view('settings'); ?>
    <?php include_hamburger_script(); ?>

</body>
</html>

