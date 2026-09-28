<?php
/**
 * Global Page Header
 * E-Bursary Management System
 */

if (!isset($basePath)) {
    $basePath = (strpos($_SERVER['SCRIPT_NAME'], '/admin/') !== false || strpos($_SERVER['SCRIPT_NAME'], '/applicant/') !== false) ? '../' : './';
}

$systemName = isset($pdo) ? getSetting($pdo, 'system_name', 'E-Bursary Management System') : 'E-Bursary Management System';
$pageTitle = isset($pageTitle) ? $pageTitle . ' - ' . $systemName : $systemName;

$unreadCount = 0;
if (isset($pdo) && isset($_SESSION['user_id'])) {
    $unreadCount = getUnreadNotificationCount($pdo, (int)$_SESSION['user_id']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($pageTitle); ?></title>

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

    <!-- Custom Theme CSS -->
    <link rel="stylesheet" href="<?php echo $basePath; ?>assets/style.css">
</head>
<body>
<div class="d-flex">

