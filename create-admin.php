<?php
require_once __DIR__ . '/config/database.php';

$username = 'admin';
$email = 'admin@ebursary.test';
$password = 'admin123';

try {
    // Check if admin already exists
    $stmt = $pdo->prepare("SELECT id, username, email FROM users WHERE role = 'super_admin' OR username = ? LIMIT 1");
    $stmt->execute([$username]);
    $existingAdmin = $stmt->fetch();

    if ($existingAdmin) {
        echo "<h2>Administrator Account Already Exists</h2>";
        echo "<p>Username: <strong>" . htmlspecialchars($existingAdmin['username']) . "</strong></p>";
        echo "<p>Email: <strong>" . htmlspecialchars($existingAdmin['email']) . "</strong></p>";
        echo "<p>Status: Ready for login.</p>";
        echo "<p><a href='login.php'>Go to Unified Login</a></p>";
    } else {
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        $ins = $pdo->prepare("
            INSERT INTO users (username, email, password, role, is_active, created_at)
            VALUES (?, ?, ?, 'super_admin', 1, NOW())
        ");
        $ins->execute([$username, $email, $hashedPassword]);

        echo "<h2>Admin account created successfully.</h2>";
        echo "<p>Username: <strong>admin</strong></p>";
        echo "<p>Password: <strong>admin123</strong></p>";
        echo "<p><a href='login.php'>Go to Login</a></p>";
    }

} catch (PDOException $e) {
    echo "<h2>Could not create admin account.</h2>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
}