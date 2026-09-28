<?php

session_start();

require_once __DIR__ . '/config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.php');
    exit;
}

$login = trim($_POST['login'] ?? '');
$password = $_POST['password'] ?? '';

if ($login === '' || $password === '') {
    header('Location: login.php?error=empty');
    exit;
}

try {

    // Find user using either username or email
    $stmt = $pdo->prepare("
        SELECT id, username, email, password, role, is_active
        FROM users
        WHERE username = :login
           OR email = :login
        LIMIT 1
    ");

    $stmt->execute([
        ':login' => $login
    ]);

    $user = $stmt->fetch();

    // Check user exists and password is correct
    if (!$user || !$user['is_active'] || !password_verify($password, $user['password'])) {
        header('Location: login.php?error=invalid');
        exit;
    }

    // Store authenticated user information
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['role'] = $user['role'];

    // Redirect according to role
    if ($user['role'] === 'super_admin') {

        header('Location: admin/dashboard.php');
        exit;

    }

    if ($user['role'] === 'applicant') {

        header('Location: applicant/dashboard.php');
        exit;

    }

    // Unknown role
    session_unset();
    session_destroy();

    header('Location: login.php?error=role');
    exit;

} catch (PDOException $e) {

    die("Authentication error: " . htmlspecialchars($e->getMessage()));
}