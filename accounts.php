<?php
session_start();
include("gabsdb.php"); // Assumes $conn is available globally
require_once __DIR__ . "/includes/auth_helpers.php";

// --- 1. Embedded Access Control ---
// Ensure $conn is available after include
if (!isset($conn) || !$conn instanceof mysqli) {
    error_log("Database connection failed in accounts.php");
    $_SESSION['message'] = "<div class='message error'>❌ Database connection is unavailable.</div>";
    header("Location: index.php");
    exit();
}

$user_role_session = $_SESSION['role'] ?? '';
require_login(['Admin']);

$user_id = $_SESSION['user_ID']; // Needed for logging potentially

// --- 2. Embedded Session Message Handling ---
$msg = "";
$error = "";
if (isset($_SESSION['message'])) {
    if (strpos($_SESSION['message'], 'error') !== false || strpos($_SESSION['message'], '❌') !== false) {
         if (strpos($_SESSION['message'], '<div') === false) { $error = "<div class='message error'>" . htmlspecialchars($_SESSION['message']) . "</div>"; } else { $error = $_SESSION['message']; }
    } else {
         if (strpos($_SESSION['message'], '<div') === false) { $msg = "<div class='message success'>" . htmlspecialchars($_SESSION['message']) . "</div>"; } else { $msg = $_SESSION['message']; }
    }
    unset($_SESSION['message']);
}


// --- 3. Page Variables & Navbar Logic ---
$page_title = "Account Management - Gab's Bakeshop";
$current_page = basename($_SERVER['PHP_SELF']);

$nav = get_nav_permissions($user_role_session);
$show_inventory_link = $nav['show_inventory_link'];
$show_reports_link = $nav['show_reports_link'];
$show_accounts_link = $nav['show_accounts_link'];
$show_orders_link = $nav['show_orders_link'];
$show_settings_link = $nav['show_settings_link'];


// --- Helper function to get Role ID ---
function get_role_id($conn, $role_name) {
    $stmt = $conn->prepare("SELECT role_ID FROM roles WHERE role = ?");
    if(!$stmt) return null; // Handle prepare error
    $stmt->bind_param("s", $role_name);
    $stmt->execute();
    $result = $stmt->get_result();
    $role_id = null;
    if ($row = $result->fetch_assoc()) {
        $role_id = $row['role_ID'];
    }
    $stmt->close();
    return $role_id;
}

// --- ACTION: Create account (WITH PASSWORD HASHING) ---
if (isset($_POST['add_account'])) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? ''; // Get plain password
    $account_role_name = trim($_POST['role'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $name = trim($_POST['name'] ?? $username); // Use name field, fallback to username
    $email = trim($_POST['email'] ?? ($username . "@gabs.com")); // Allow optional email, generate if missing

    // ** SECURE: Hash the password **
    if (empty($username) || empty($account_role_name)) {
        $_SESSION['message'] = "<div class='message error'>❌ Username and role are required.</div>";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['message'] = "<div class='message error'>❌ Please provide a valid email address.</div>";
    } elseif (empty($password)) {
        $_SESSION['message'] = "<div class='message error'>❌ Password cannot be empty.</div>";
    } else {
        $password_hash = password_hash($password, PASSWORD_DEFAULT); // Hash the password

        $conn->begin_transaction();
        try {
            $stmt_acc = $conn->prepare("INSERT INTO accounts (username, password, name, Location, email) VALUES (?, ?, ?, ?, ?)");
            if (!$stmt_acc) throw new Exception("Prepare failed (insert account): " . $conn->error);

            // ** SECURE: Bind the HASHED password **
            $stmt_acc->bind_param("sssss", $username, $password_hash, $name, $location, $email);
            if (!$stmt_acc->execute()) {
                  if ($conn->errno == 1062) {
                      throw new Exception("Username '{$username}' already exists.");
                  } else {
                      throw new Exception("Execute failed (insert account): " . $stmt_acc->error);
                  }
            }
            $new_user_id = $conn->insert_id;
            $stmt_acc->close();

            if (!$new_user_id) {
                throw new Exception("Failed to get new user ID after insert.");
            }

            $role_id = get_role_id($conn, $account_role_name);
            if (!$role_id) {
                throw new Exception("Invalid role selected: '{$account_role_name}'.");
            }

            $stmt_ur = $conn->prepare("INSERT INTO userroles (user_ID, role_ID) VALUES (?, ?)");
            if (!$stmt_ur) throw new Exception("Prepare failed (insert userroles): " . $conn->error);
            $stmt_ur->bind_param("ii", $new_user_id, $role_id);
            if (!$stmt_ur->execute()) {
                throw new Exception("Execute failed (insert userroles): " . $stmt_ur->error);
            }
            $stmt_ur->close();

            $conn->commit();
            $_SESSION['message'] = "<div class='message success'>✅ Account '{$username}' created successfully.</div>";
        } catch (Exception $e) {
            if ($conn->ping()) $conn->rollback();
            $_SESSION['message'] = "<div class='message error'>❌ Error creating account: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
    header("Location: accounts.php");
    exit();
}


// --- ACTION: Update account (WITH PASSWORD HASHING) ---
if (isset($_POST['update_account'])) {
    $id = intval($_POST['user_ID']); // Ensure integer
    $username = trim($_POST['username'] ?? '');
    $account_role_name = trim($_POST['role'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $name = trim($_POST['name'] ?? $username);
    $new_password = $_POST['password'] ?? ''; // Get potential new password

    if ($id <= 0 || empty($username) || empty($account_role_name)) {
        $_SESSION['message'] = "<div class='message error'>❌ Invalid account update request.</div>";
        header("Location: accounts.php");
        exit();
    }

    $conn->begin_transaction();
    try {
        $sql_update_acc = "";
        $types_acc = "";
        $params_acc = [];

        // Conditionally update password if provided
        if (!empty($new_password)) {
             // ** SECURE: Hash the new password **
            $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $sql_update_acc = "UPDATE accounts SET username=?, password=?, name=?, Location=? WHERE user_ID=?";
            $types_acc = "ssssi";
            $params_acc = [$username, $new_password_hash, $name, $location, $id]; // Use hashed password
        } else {
            // Update without changing password
            $sql_update_acc = "UPDATE accounts SET username=?, name=?, Location=? WHERE user_ID=?";
            $types_acc = "sssi";
            $params_acc = [$username, $name, $location, $id];
        }

        $stmt_acc = $conn->prepare($sql_update_acc);
        if (!$stmt_acc) throw new Exception("Prepare failed (update account): " . $conn->error);
        $stmt_acc->bind_param($types_acc, ...$params_acc);
        if (!$stmt_acc->execute()) {
            if ($conn->errno == 1062) {
                throw new Exception("Username '{$username}' already exists.");
            } else {
                throw new Exception("Execute failed (update account): " . $stmt_acc->error);
            }
        }
        $stmt_acc->close();

        // Update Role (UPSERT logic)
        $new_role_id = get_role_id($conn, $account_role_name);
        if (!$new_role_id) {
            throw new Exception("Invalid role selected: '{$account_role_name}'.");
        }

        $check_ur = $conn->prepare("SELECT user_ID FROM userroles WHERE user_ID = ?");
        if (!$check_ur) throw new Exception("Prepare failed (check userroles): " . $conn->error);
        $check_ur->bind_param("i", $id);
        $check_ur->execute();
        $ur_result = $check_ur->get_result();
        $check_ur->close();

        if ($ur_result->num_rows > 0) {
            $stmt_ur = $conn->prepare("UPDATE userroles SET role_ID = ? WHERE user_ID = ?");
            if (!$stmt_ur) throw new Exception("Prepare failed (update userroles): " . $conn->error);
            $stmt_ur->bind_param("ii", $new_role_id, $id);
        } else {
            $stmt_ur = $conn->prepare("INSERT INTO userroles (user_ID, role_ID) VALUES (?, ?)");
            if (!$stmt_ur) throw new Exception("Prepare failed (insert userroles): " . $conn->error);
            $stmt_ur->bind_param("ii", $id, $new_role_id);
        }
        if (!$stmt_ur->execute()) {
            throw new Exception("Execute failed (update/insert userroles): " . $stmt_ur->error);
        }
        $stmt_ur->close();

        $conn->commit();
        $_SESSION['message'] = "<div class='message success'>✅ Account '{$username}' updated successfully.</div>";
    } catch (Exception $e) {
        if ($conn->ping()) $conn->rollback();
        $_SESSION['message'] = "<div class='message error'>❌ Error updating account: " . htmlspecialchars($e->getMessage()) . "</div>";
    }

    header("Location: accounts.php");
    exit();
}

// --- ACTION: Delete account ---
if (isset($_POST['delete_account'])) {
    $id = intval($_POST['user_ID']); // Ensure integer

    if ($id == 1) {
        $_SESSION['message'] = "<div class='message error'>❌ Cannot delete the main administrator account.</div>";
    } else {
        $conn->begin_transaction();
        try {
            // Delete from userroles first
            $stmt_ur = $conn->prepare("DELETE FROM userroles WHERE user_ID = ?");
            if (!$stmt_ur) throw new Exception("Prepare failed (delete userroles): " . $conn->error);
            $stmt_ur->bind_param("i", $id);
            $stmt_ur->execute();
            $stmt_ur->close();

            // Delete from accounts
            $stmt_acc = $conn->prepare("DELETE FROM accounts WHERE user_ID = ?");
            if (!$stmt_acc) throw new Exception("Prepare failed (delete account): " . $conn->error);
            $stmt_acc->bind_param("i", $id);
            $stmt_acc->execute();

            if ($stmt_acc->affected_rows === 0) {
                throw new Exception("Account not found or already deleted (ID: {$id}).");
            }
            $stmt_acc->close();

            $conn->commit();
            $_SESSION['message'] = "<div class='message success'>✅ Account deleted successfully.</div>";
        } catch (Exception $e) {
            if ($conn->ping()) $conn->rollback();
            $_SESSION['message'] = "<div class='message error'>❌ Error deleting account: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }

    header("Location: accounts.php");
    exit();
}

// Fetch all roles for dropdowns
$roles_result = $conn->query("SELECT role FROM roles ORDER BY role_ID");
$roles_list = [];
if ($roles_result) { // Check query success
    while ($role_row = $roles_result->fetch_assoc()) {
        $roles_list[] = $role_row['role'];
    }
    $roles_result->close();
} else {
     $error .= " | ❌ Error fetching roles list: " . htmlspecialchars($conn->error);
}

// Fetch existing accounts for display
$sql_users = "SELECT a.user_ID, a.username, a.name, a.Location, a.email, r.role
            FROM accounts a
            LEFT JOIN userroles ur ON a.user_ID = ur.user_ID
            LEFT JOIN roles r ON ur.role_ID = r.role_ID
            ORDER BY a.name";
$users_result = $conn->query($sql_users);
// Check errors for user list query if needed

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?></title>
    <link rel="stylesheet" href="accounts.css">
</head>
<body>
    <!-- Navbar -->
    <?php include __DIR__ . '/includes/navbar.php'; ?>

<div class="page-content">
    <h1>Account Management</h1>

    <?php
        if (!empty($msg) && strpos($msg, '<div') === false) { echo "<div class='message success'>" . htmlspecialchars($msg) . "</div>"; } elseif (!empty($msg)) { echo $msg; }
         if (!empty($error) && strpos($error, '<div') === false) { echo "<div class='message error'>" . htmlspecialchars($error) . "</div>"; } elseif (!empty($error)) { echo $error; }
    ?>

    <h3>Existing Accounts</h3>
    
    <!-- Add Account Button -->
    <div style="margin-bottom: 20px;">
        <button id="openAddAccountModal" class="btn btn-approve" style="padding: 10px 20px; font-size: 1em;">
            ➕ Add New Account
        </button>
    </div>

    <!-- Add Account Modal -->
    <div id="addAccountModal" class="modal">
        <div class="modal-content">
            <span class="close-modal">&times;</span>
            <h3 style="margin-top: 0;">Add New Account</h3>
            <form method="POST" action="accounts.php" class="add-account-form" style="border: none; padding: 0; background: transparent;" onsubmit="return confirmAction('add account')">
                <label for="name">Display Name:</label>
                <div class="input-wrapper">
                    <input type="text" id="name" name="name" class="required-field" placeholder="Full Name or Branch Name" required>
                    <span class="required-indicator">*</span>
                </div>

                <label for="username">Username:</label>
                <div class="input-wrapper">
                    <input type="text" id="username" name="username" class="required-field" placeholder="Login Username" required>
                    <span class="required-indicator">*</span>
                </div>

                <label for="password">Password:</label>
                <div class="input-wrapper">
                    <input type="password" id="password" name="password" class="required-field" placeholder="Password" required>
                    <span class="required-indicator">*</span>
                </div>

                <label for="email">Email:</label>
                <input type="email" id="email" name="email" placeholder="user@example.com (Optional)">

                <label for="role">Role:</label>
                <div class="input-wrapper">
                    <select name="role" id="role" class="required-field" required>
                        <option value="">-- Select Role --</option>
                        <?php foreach ($roles_list as $role): ?>
                            <option value="<?= htmlspecialchars($role) ?>"><?= htmlspecialchars($role) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="required-indicator">*</span>
                </div>

                <label for="location">Location (for Branches):</label>
                <input type="text" id="location" name="location" placeholder="e.g., Main Office, Downtown Branch">

                <button type="submit" name="add_account">Add Account</button>
            </form>
        </div>
    </div>

    <!-- Responsive Table Container -->
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Username</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Location</th>
                    <th class="action-cell">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($users_result && $users_result->num_rows > 0): ?>
                    <?php while ($row = $users_result->fetch_assoc()): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['name']) ?></td>
                        <td><?= htmlspecialchars($row['username']) ?></td>
                        <td><?= htmlspecialchars($row['email'] ?? '') ?></td>
                        <td><?= htmlspecialchars($row['role'] ?? 'N/A') ?></td>
                        <td><?= htmlspecialchars($row['Location'] ?? '') ?></td>
                        <td class="action-cell">
                            <form method='POST' action='accounts.php' onsubmit="return confirmAction(event.submitter.name === 'delete_account' ? 'delete this account' : 'update this account')">
                                <input type='hidden' name='user_ID' value='<?= $row['user_ID'] ?>'>
                                <div class='update-form-fields'>
                                    <input type='text' name='name' value='<?= htmlspecialchars($row['name']) ?>' required placeholder='Name' title="Display Name">
                                    <input type='text' name='username' value='<?= htmlspecialchars($row['username']) ?>' required placeholder='Username' title="Username">
                                    <input type='password' name='password' placeholder='New password (opt)' title="Leave blank to keep current password">
                                    <select name='role' required title="Role">
                                        <?php
                                        foreach ($roles_list as $role) {
                                            $selected = ($row['role'] == $role) ? 'selected' : '';
                                            echo "<option value='" . htmlspecialchars($role) . "' $selected>" . htmlspecialchars($role) . "</option>";
                                        }
                                        ?>
                                    </select>
                                    <input type='text' name='location' value='<?= htmlspecialchars($row['Location'] ?? '') ?>' placeholder='Location' title="Location (for Branches)">
                                </div>
                                <div class='action-buttons'>
                                    <?php if ($row['user_ID'] != 1): ?>
                                        <button type='submit' name='delete_account' class='btn btn-deny' title="Delete Account">Delete</button>
                                    <?php else: ?>
                                        <span style='color: #999; font-size: 0.85em;'>(Cannot Delete)</span>
                                    <?php endif; ?>
                                    <button type='submit' name='update_account' class='btn btn-update' title="Update Account">Update</button>
                                </div>
                            </form>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                 <?php else: ?>
                    <tr><td colspan="6">No accounts found.</td></tr>
                <?php endif; ?>
                 <?php if ($users_result ?? null) $users_result->close(); ?>
            </tbody>
        </table>
    </div>

    <!-- Confirmation Modal -->
    <div id="confirmModal" class="modal confirm-modal">
        <div class="modal-content">
            <h3>Confirm Action</h3>
            <p id="confirmMessage">Are you sure you want to proceed?</p>
            <div class="modal-buttons">
                <button class="btn-yes" id="confirmYes">Yes</button>
                <button class="btn-no" id="confirmNo">No</button>
            </div>
        </div>
    </div>

</div>

<script>
    // Store form reference and submitter for confirmation
    let pendingForm = null;
    let pendingSubmitter = null;
    let isConfirmed = false;

    document.addEventListener('DOMContentLoaded', function() {
        // Hamburger menu functionality
        const hamburgerButton = document.getElementById('hamburger-button');
        const navLinks = document.getElementById('main-nav-links');

        if (hamburgerButton && navLinks) {
            hamburgerButton.addEventListener('click', function() {
                navLinks.classList.toggle('show-menu');
            });
        }

        // Add Account Modal functionality
        const modal = document.getElementById('addAccountModal');
        const openBtn = document.getElementById('openAddAccountModal');
        const closeBtn = document.querySelector('.close-modal');

        if (openBtn && modal) {
            openBtn.addEventListener('click', function() {
                modal.style.display = 'block';
            });
        }

        if (closeBtn && modal) {
            closeBtn.addEventListener('click', function() {
                modal.style.display = 'none';
            });
        }

        if (modal) {
            window.addEventListener('click', function(event) {
                if (event.target === modal) {
                    modal.style.display = 'none';
                }
            });
        }

        // Confirmation Modal functionality
        const confirmModal = document.getElementById('confirmModal');
        const confirmYes = document.getElementById('confirmYes');
        const confirmNo = document.getElementById('confirmNo');

        // Handle all form submissions with confirmation
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                // Skip if already confirmed
                if (isConfirmed) {
                    isConfirmed = false;
                    return true;
                }

                // Check if form has onsubmit attribute (our confirmation forms)
                if (this.hasAttribute('onsubmit')) {
                    e.preventDefault();
                    pendingForm = this;
                    pendingSubmitter = e.submitter;
                    
                    // Determine action based on which button was clicked
                    let action = 'proceed';
                    
                    if (e.submitter) {
                        if (e.submitter.name === 'add_account') {
                            action = 'add this account';
                        } else if (e.submitter.name === 'update_account') {
                            action = 'update this account';
                        } else if (e.submitter.name === 'delete_account') {
                            action = 'delete this account';
                        }
                    }
                    
                    const message = document.getElementById('confirmMessage');
                    message.textContent = `Are you sure you want to ${action}?`;
                    confirmModal.style.display = 'block';
                }
            });
        });

        // Yes button - submit the form
        if (confirmYes) {
            confirmYes.addEventListener('click', function() {
                if (pendingForm) {
                    isConfirmed = true;
                    
                    // Create a hidden input for the submitter button name if needed
                    if (pendingSubmitter && pendingSubmitter.name) {
                        const hiddenInput = document.createElement('input');
                        hiddenInput.type = 'hidden';
                        hiddenInput.name = pendingSubmitter.name;
                        hiddenInput.value = pendingSubmitter.value || '1';
                        pendingForm.appendChild(hiddenInput);
                    }
                    
                    pendingForm.submit();
                }
                confirmModal.style.display = 'none';
                pendingForm = null;
                pendingSubmitter = null;
            });
        }

        // No button - cancel
        if (confirmNo) {
            confirmNo.addEventListener('click', function() {
                pendingForm = null;
                pendingSubmitter = null;
                confirmModal.style.display = 'none';
            });
        }

        // Close confirmation modal when clicking outside
        window.addEventListener('click', function(event) {
            if (event.target === confirmModal) {
                pendingForm = null;
                pendingSubmitter = null;
                confirmModal.style.display = 'none';
            }
        });
    });
</script>

</body>
</html>