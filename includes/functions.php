<?php
/**
 * Utility and Helper Functions
 * E-Bursary Management System
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Escape HTML output to prevent XSS attacks.
 */
function e(?string $string): string {
    return htmlspecialchars((string)$string, ENT_QUOTES, 'UTF-8');
}

/**
 * Generate and store a CSRF token in the session.
 */
function generateCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Render a hidden input element containing the CSRF token.
 */
function csrfInput(): string {
    $token = generateCsrfToken();
    return '<input type="hidden" name="csrf_token" value="' . e($token) . '">';
}

/**
 * Verify submitted CSRF token.
 */
function verifyCsrfToken(?string $token): bool {
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Set a flash notification message in the session.
 * @param string $type success | danger | warning | info
 */
function setFlash(string $type, string $message): void {
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message
    ];
}

/**
 * Retrieve and clear the flash notification message.
 */
function getFlash(): ?array {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

/**
 * Render HTML flash message if one exists.
 */
function renderFlash(): string {
    $flash = getFlash();
    if (!$flash) {
        return '';
    }
    $type = e($flash['type']);
    $msg = e($flash['message']);
    return "
    <div class=\"alert alert-{$type} alert-dismissible fade show\" role=\"alert\">
        {$msg}
        <button type=\"button\" class=\"btn-close\" data-bs-dismiss=\"alert\" aria-label=\"Close\"></button>
    </div>";
}

/**
 * Format currency in Kenyan Shillings (KES).
 */
function formatMoney(?float $amount): string {
    if ($amount === null) {
        return 'KES 0.00';
    }
    return 'KES ' . number_format($amount, 2);
}

/**
 * Render Bootstrap badge for various statuses.
 */
function renderStatusBadge(string $status): string {
    $statusLower = strtolower($status);
    $map = [
        'draft'               => ['bg-secondary', 'Draft'],
        'submitted'           => ['bg-info text-dark', 'Submitted'],
        'pending'             => ['bg-warning text-dark', 'Pending'],
        'under_review'        => ['bg-primary', 'Under Review'],
        'approved'            => ['bg-success', 'Approved'],
        'rejected'            => ['bg-danger', 'Rejected'],
        'active'              => ['bg-success', 'Active'],
        'inactive'            => ['bg-secondary', 'Inactive'],
        'closed'              => ['bg-dark', 'Closed'],
        'verified'            => ['bg-success', 'Verified'],
        'not_submitted'       => ['bg-secondary', 'Not Submitted'],
        'partially_disbursed' => ['bg-info text-dark', 'Partially Disbursed'],
        'fully_disbursed'     => ['bg-success', 'Fully Disbursed'],
        'processing'          => ['bg-warning text-dark', 'Processing'],
        'completed'           => ['bg-success', 'Completed'],
        'failed'              => ['bg-danger', 'Failed']
    ];

    if (isset($map[$statusLower])) {
        return '<span class="badge ' . $map[$statusLower][0] . '">' . $map[$statusLower][1] . '</span>';
    }

    return '<span class="badge bg-secondary">' . e(ucwords(str_replace('_', ' ', $status))) . '</span>';
}

/**
 * Create a notification in the database for a specific user.
 */
function createNotification(PDO $pdo, int $userId, string $title, string $message): bool {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO notifications (user_id, title, message, is_read, created_at)
            VALUES (?, ?, ?, 0, NOW())
        ");
        return $stmt->execute([$userId, $title, $message]);
    } catch (PDOException $e) {
        error_log("Failed to create notification: " . $e->getMessage());
        return false;
    }
}

/**
 * Get count of unread notifications for a user.
 */
function getUnreadNotificationCount(PDO $pdo, int $userId): int {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

/**
 * Get setting value by key.
 */
function getSetting(PDO $pdo, string $key, string $default = ''): string {
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        return ($val !== false && $val !== null) ? (string)$val : $default;
    } catch (PDOException $e) {
        return $default;
    }
}

/**
 * Update or insert setting value.
 */
function updateSetting(PDO $pdo, string $key, ?string $value): bool {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO settings (setting_key, setting_value)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
        ");
        return $stmt->execute([$key, $value]);
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Generate a unique application number, e.g. BUR-2026-00123
 */
function generateApplicationNumber(PDO $pdo): string {
    $year = date('Y');
    $prefix = "BUR-{$year}-";
    $stmt = $pdo->query("SELECT MAX(id) FROM applications");
    $maxId = (int)$stmt->fetchColumn() + 1;
    return $prefix . str_pad((string)$maxId, 5, '0', STR_PAD_LEFT);
}

/**
 * Securely validate and upload a file.
 * Returns array: ['success' => bool, 'stored_filename' => string, 'file_path' => string, 'file_type' => string, 'file_size' => int, 'error' => string]
 */
function handleFileUpload(array $file, array $allowedExtensions, string $destinationDir, int $maxBytes = 5242880): array {
    if (!isset($file['error']) || is_array($file['error'])) {
        return ['success' => false, 'error' => 'Invalid upload parameters.'];
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $uploadErrors = [
            UPLOAD_ERR_INI_SIZE   => 'The uploaded file exceeds the upload_max_filesize directive in php.ini.',
            UPLOAD_ERR_FORM_SIZE  => 'The uploaded file exceeds the MAX_FILE_SIZE directive in the HTML form.',
            UPLOAD_ERR_PARTIAL    => 'The uploaded file was only partially uploaded.',
            UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
            UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the file upload.',
        ];
        return ['success' => false, 'error' => $uploadErrors[$file['error']] ?? 'Unknown upload error.'];
    }

    if ($file['size'] > $maxBytes) {
        return ['success' => false, 'error' => 'File size exceeds maximum limit of ' . round($maxBytes / 1048576, 1) . 'MB.'];
    }

    $originalName = $file['name'];
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if (!in_array($extension, $allowedExtensions, true)) {
        return ['success' => false, 'error' => 'Invalid file extension. Allowed extensions: ' . implode(', ', $allowedExtensions)];
    }

    // Verify MIME type using finfo
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);
    $allowedMimes = [
        'pdf'  => 'application/pdf',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png'
    ];

    if (!in_array($mimeType, array_values($allowedMimes), true)) {
        return ['success' => false, 'error' => 'Invalid file content/type. Only PDF, JPG, and PNG documents are allowed.'];
    }

    // Generate safe randomized filename
    if (!is_dir($destinationDir)) {
        mkdir($destinationDir, 0755, true);
    }

    $safeFilename = 'doc_' . date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '.' . $extension;
    $targetPath = rtrim($destinationDir, '/\\') . DIRECTORY_SEPARATOR . $safeFilename;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        return ['success' => false, 'error' => 'Failed to save uploaded file.'];
    }

    return [
        'success'         => true,
        'original_name'   => $originalName,
        'stored_filename' => $safeFilename,
        'file_path'       => $targetPath,
        'file_type'       => $mimeType,
        'file_size'       => $file['size']
    ];
}

