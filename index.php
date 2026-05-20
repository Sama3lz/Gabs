<?php
session_start();
include("gabsdb.php"); // Ensure your database connection is included

// Redirect if the user is already logged in
if (isset($_SESSION['user_ID'])) {
    header("Location: welcome.php");
    exit();
}

$error = "";
$username_or_email = ""; // Initialize to prevent PHP notices

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username_or_email = $_POST['username_or_email'] ?? '';
    $password = $_POST['password'] ?? ''; // Plain text password from form

    if (!$conn instanceof mysqli) {
        $error = "Database is unavailable. Ensure MySQL is running and import database/gabsdatabase.sql if needed.";
    } elseif (empty($username_or_email) || empty($password)) {
        $error = "Username/Email and Password are required.";
    } else {
        // --- 1. Find the User by username OR email ---
        $stmt = $conn->prepare("SELECT user_ID, password, name FROM accounts WHERE username = ? OR email = ?");
        if (!$stmt) {
             $error = "Database error: Could not prepare statement.";
        } else {
            $stmt->bind_param("ss", $username_or_email, $username_or_email);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 1) {
                $user = $result->fetch_assoc();
                $stored_password_hash = $user['password']; // Hashed password from DB

                // *** SECURE: Use password_verify() to check hashed password ***
                if (password_verify($password, $stored_password_hash)) {
                    // --- 2. Fetch the Role ID ---
                    $role_stmt = $conn->prepare("SELECT role_ID FROM userroles WHERE user_ID = ?");
                     if (!$role_stmt) {
                        $error = "Database error: Could not fetch role.";
                     } else {
                        $role_stmt->bind_param("i", $user['user_ID']);
                        $role_stmt->execute();
                        $role_result = $role_stmt->get_result();

                        if ($role_result->num_rows === 1) {
                            $role_data = $role_result->fetch_assoc();
                            $role_id = $role_data['role_ID'];

                            // --- 3. Fetch the Role Name ---
                            $name_stmt = $conn->prepare("SELECT role FROM roles WHERE role_ID = ?");
                             if(!$name_stmt) {
                                $error = "Database error: Could not fetch role name.";
                             } else {
                                $name_stmt->bind_param("i", $role_id);
                                $name_stmt->execute();
                                $role_name_result = $name_stmt->get_result();
                                $role_name_data = $role_name_result->fetch_assoc();
                                $role_name = $role_name_data['role'] ?? null;
                                $name_stmt->close();

                                if ($role_name) {
                                    // --- 4. Success: Set Session Variables and Redirect ---
                                    $_SESSION['user_ID'] = $user['user_ID'];
                                    $_SESSION['name'] = $user['name'];
                                    $_SESSION['role'] = $role_name;

                                    // Optional: Rehash password if needed (future-proofing)
                                    if (password_needs_rehash($stored_password_hash, PASSWORD_DEFAULT)) {
                                        $new_hash = password_hash($password, PASSWORD_DEFAULT);
                                        $update_stmt = $conn->prepare("UPDATE accounts SET password = ? WHERE user_ID = ?");
                                        if ($update_stmt) {
                                            $update_stmt->bind_param("si", $new_hash, $user['user_ID']);
                                            $update_stmt->execute();
                                            $update_stmt->close();
                                        }
                                    }

                                    header("Location: welcome.php");
                                    exit();
                                } else {
                                     $error = "Role name not found for assigned role ID.";
                                }
                             }
                        } else {
                            // User exists but has no role assigned
                            $error = "Login successful, but user role not assigned. Please contact administrator.";
                        }
                        $role_stmt->close();
                     }
                } else {
                    $error = "Invalid username/email or password."; // Keep generic error
                }
            } else {
                $error = "Invalid username/email or password."; // Keep generic error
            }
            $stmt->close();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Gab's Bakeshop</title>
    <link rel="stylesheet" href="index.css">
</head>
<body>
<div class="login-box">
    <div class="logo">
        <img src="gabs_logo.png" alt="Gab's Bakeshop Logo" onerror="this.style.display='none'">
    </div>
    <h2>Welcome!</h2>

    <?php if (!empty($error)): ?>
        <p class="error-message"><?php echo htmlspecialchars($error); ?></p>
    <?php endif; ?>

    <form action="index.php" method="POST">
        <div class="input-group">
             <label for="username_or_email">Username</label>
            <input
                type="text"
                id="username_or_email"
                name="username_or_email"
                placeholder="Enter your username or email"
                required
                value="<?php echo htmlspecialchars($username_or_email); ?>"
                aria-label="Username or Email"
            >
        </div>
        <div class="input-group">
             <label for="password">Password</label>
            <input
                type="password"
                id="password"
                name="password"
                placeholder="Enter your password"
                required
                aria-label="Password"
            >
        </div>

        <button type="submit" class="login-btn">Login</button>
    </form>
</div>
</body>
</html>