<?php
/**
 * Administrator Navigation Sidebar
 * E-Bursary Management System
 */

$activeNav = $activeNav ?? 'dashboard';
?>
<!-- ADMIN SIDEBAR -->
<aside class="sidebar">
    <a href="dashboard.php" class="brand">
        <i class="bi bi-shield-check text-success fs-3"></i>
        <span>E-Bursary Admin</span>
    </a>

    <nav class="sidebar-nav">
        <a href="dashboard.php" class="nav-link <?php echo ($activeNav === 'dashboard') ? 'active' : ''; ?>">
            <i class="bi bi-speedometer2"></i>
            <span>Dashboard</span>
        </a>

        <a href="applicants.php" class="nav-link <?php echo ($activeNav === 'applicants') ? 'active' : ''; ?>">
            <i class="bi bi-people"></i>
            <span>Applicants</span>
        </a>

        <a href="applications.php" class="nav-link <?php echo ($activeNav === 'applications') ? 'active' : ''; ?>">
            <i class="bi bi-file-earmark-text"></i>
            <span>Applications</span>
        </a>

        <a href="programs.php" class="nav-link <?php echo ($activeNav === 'programs') ? 'active' : ''; ?>">
            <i class="bi bi-mortarboard"></i>
            <span>Programs</span>
        </a>

        <a href="allocations.php" class="nav-link <?php echo ($activeNav === 'allocations') ? 'active' : ''; ?>">
            <i class="bi bi-cash-stack"></i>
            <span>Allocations</span>
        </a>

        <a href="documents.php" class="nav-link <?php echo ($activeNav === 'documents') ? 'active' : ''; ?>">
            <i class="bi bi-file-earmark-check"></i>
            <span>Documents</span>
        </a>

        <a href="disbursements.php" class="nav-link <?php echo ($activeNav === 'disbursements') ? 'active' : ''; ?>">
            <i class="bi bi-credit-card"></i>
            <span>Disbursements</span>
        </a>

        <a href="reports.php" class="nav-link <?php echo ($activeNav === 'reports') ? 'active' : ''; ?>">
            <i class="bi bi-graph-up"></i>
            <span>Reports</span>
        </a>

        <a href="settings.php" class="nav-link <?php echo ($activeNav === 'settings') ? 'active' : ''; ?>">
            <i class="bi bi-gear"></i>
            <span>Settings</span>
        </a>
    </nav>

    <div class="sidebar-footer">
        <a href="../logout.php" class="nav-link text-danger">
            <i class="bi bi-box-arrow-right"></i>
            <span>Logout</span>
        </a>
    </div>
</aside>

