<div class="form-section">
    <h2>Change Password</h2>
    <form method="POST" action="settings.php">
        <label for="current_password">Current Password:</label>
        <input type="password" id="current_password" name="current_password" required>
        <label for="new_password">New Password:</label>
        <input type="password" id="new_password" name="new_password" required pattern=".{8,}" title="Password must be at least 8 characters long">
        <label for="confirm_password">Confirm New Password:</label>
        <input type="password" id="confirm_password" name="confirm_password" required>
        <button type="submit" name="change_password">Change Password</button>
    </form>
</div>
