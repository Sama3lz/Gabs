<div class="page-content">
    <h1>User Settings</h1>
    <?php
        if (!empty($msg) && strpos($msg, '<div') === false) { echo "<div class='message success'>" . htmlspecialchars($msg) . "</div>"; } elseif (!empty($msg)) { echo $msg; }
        if (!empty($error) && strpos($error, '<div') === false) { echo "<div class='message error'>" . htmlspecialchars($error) . "</div>"; } elseif (!empty($error)) { echo $error; }
    ?>
    <div class="form-section">
        <h2>Personal Profile Details</h2>
        <form method="POST" action="settings.php">
            <label for="new_name">Display Name:</label>
            <input type="text" id="new_name" name="new_name" value="<?= htmlspecialchars($user_data['name'] ?? '') ?>" required>
            <label for="new_username">Username:</label>
            <input type="text" id="new_username" name="new_username" value="<?= htmlspecialchars($user_data['username'] ?? '') ?>" required>
            <?php if (!empty($user_data['email'])): ?>
            <label for="email">Email:</label>
            <input type="email" id="email" name="email" value="<?= htmlspecialchars($user_data['email'] ?? '') ?>" readonly disabled title="Email cannot be changed here">
            <?php endif; ?>
            <button type="submit" name="update_profile">Update Profile Details</button>
        </form>
    </div>
    <?php include __DIR__ . '/_password_form.php'; ?>
</div>
