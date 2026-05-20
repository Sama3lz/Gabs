<div class="page-content">
    <div class="dashboard-content">
        <h1>Welcome to Gab's Bakeshop System</h1>
        <p class="user-info">
            Logged in as: <strong><?= htmlspecialchars($user_name); ?></strong>
            (Role: Delivery)
        </p>
        <?php
            if (!empty($msg) && strpos($msg, '<div') === false) { echo "<div class='message success'>" . htmlspecialchars($msg) . "</div>"; } elseif (!empty($msg)) { echo $msg; }
            if (!empty($error) && strpos($error, '<div') === false) { echo "<div class='message error'>" . htmlspecialchars($error) . "</div>"; } elseif (!empty($error)) { echo $error; }
        ?>
        <div class="welcome-card">
            <h2>System Overview</h2>
            <p>Welcome! Use the navigation bar above to access different sections based on your role.</p>
            <hr>
            <p style="font-style: italic; color: #555;">
                As a <b>Delivery</b> user, you can view approved orders assigned to you and mark them as delivered after uploading proof.
            </p>
        </div>
    </div>
</div>
