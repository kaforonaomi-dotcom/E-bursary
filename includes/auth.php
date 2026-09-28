<?php
/**
 * Authentication and Access Control Helpers
 * E-Bursary Management System
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Check if the user is currently logged in.
 */
function isLoggedIn(): bool {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Require a user to be logged in. If not, redirect to login page.
 */
function requireLogin(string $redirectPath = '../login.php'): void {
    if (!isLoggedIn()) {
        header("Location: $redirectPath");
        exit;
    }
}

/**
 * Require a specific role ('applicant' or 'super_admin').
 * If user lacks the role, redirect safely.
 */
function requireRole(string $role, string $redirectPath = '../login.php'): void {
    requireLogin($redirectPath);
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== $role) {
        // Redirect to appropriate area if wrong role
        if (isset($_SESSION['role']) && $_SESSION['role'] === 'super_admin') {
            header('Location: ../admin/dashboard.php');
            exit;
        } elseif (isset($_SESSION['role']) && $_SESSION['role'] === 'applicant') {
            header('Location: ../applicant/dashboard.php');
            exit;
        }
        header("Location: $redirectPath?error=unauthorized");
        exit;
    }
}

/**
 * Get current authenticated user row from database.
 */
function getCurrentUser(PDO $pdo): ?array {
    if (!isLoggedIn()) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT id, username, email, role, is_active, created_at FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch() ?: null;
}

/**
 * Get applicant record for the currently logged-in applicant user.
 * Ensures an applicant can ONLY access their own record.
 */
function getCurrentApplicant(PDO $pdo): ?array {
    if (!isLoggedIn() || $_SESSION['role'] !== 'applicant') {
        return null;
    }
    $stmt = $pdo->prepare("SELECT * FROM applicants WHERE user_id = ? LIMIT 1");
    $stmt->execute([$_SESSION['user_id']]);
    $applicant = $stmt->fetch();
    return $applicant ?: null;
}

