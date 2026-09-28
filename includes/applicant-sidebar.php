<?php
/**
 * Applicant Navigation Sidebar
 * E-Bursary Management System
 */

$activeNav = $activeNav ?? 'dashboard';
$unreadCount = $unreadCount ?? 0;
?>
<!-- APPLICANT SIDEBAR -->
<aside class="sidebar">
    <a href="dashboard.php" class="brand">
        <i class="bi bi-mortarboard-fill text-success fs-3"></i>
        <span>E-Bursary</span>
    </a>

    <nav class="sidebar-nav">
        <a href="dashboard.php" class="nav-link <?php echo ($activeNav === 'dashboard') ? 'active' : ''; ?>">
            <i class="bi bi-speedometer2"></i>
            <span>Dashboard</span>
        </a>

        <a href="application.php" class="nav-link <?php echo ($activeNav === 'application') ? 'active' : ''; ?>">
            <i class="bi bi-file-earmark-text"></i>
            <span>My Application</span>
        </a>

        <a href="profile.php" class="nav-link <?php echo ($activeNav === 'profile') ? 'active' : ''; ?>">
            <i class="bi bi-person"></i>
            <span>Personal Information</span>
        </a>

        <a href="education.php" class="nav-link <?php echo ($activeNav === 'education') ? 'active' : ''; ?>">
            <i class="bi bi-book"></i>
            <span>Education</span>
        </a>

        <a href="guardian.php" class="nav-link <?php echo ($activeNav === 'guardian') ? 'active' : ''; ?>">
            <i class="bi bi-people"></i>
            <span>Guardian</span>
        </a>

        <a href="documents.php" class="nav-link <?php echo ($activeNav === 'documents') ? 'active' : ''; ?>">
            <i class="bi bi-paperclip"></i>
            <span>Documents</span>
        </a>

        <a href="status.php" class="nav-link <?php echo ($activeNav === 'status') ? 'active' : ''; ?>">
            <i class="bi bi-clipboard-check"></i>
            <span>Application Status</span>
        </a>

        <a href="notifications.php" class="nav-link <?php echo ($activeNav === 'notifications') ? 'active' : ''; ?>">
            <i class="bi bi-bell"></i>
            <span>Notifications</span>
            <?php if ($unreadCount > 0): ?>
                <span class="badge bg-danger ms-auto"><?php echo $unreadCount; ?></span>
            <?php endif; ?>
        </a>
    </nav>

    <div class="sidebar-footer">
        <a href="../logout.php" class="nav-link text-danger">
            <i class="bi bi-box-arrow-right"></i>
            <span>Logout</span>
        </a>
    </div>
</aside>

