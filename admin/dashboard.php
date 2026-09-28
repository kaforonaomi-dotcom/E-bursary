<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('super_admin');

// 1. Statistics
$stmt = $pdo->query("SELECT COUNT(*) FROM applicants a INNER JOIN users u ON a.user_id = u.id WHERE u.role = 'applicant'");
$totalApplicants = (int)$stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM applications");
$totalApplications = (int)$stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM applications WHERE status IN ('pending', 'submitted', 'under_review')");
$pendingApplications = (int)$stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM applications WHERE status = 'approved'");
$approvedApplications = (int)$stmt->fetchColumn();

$totalAllocated = (float)$pdo->query("SELECT COALESCE(SUM(approved_amount), 0) FROM allocations WHERE status != 'pending'")->fetchColumn();
$totalDisbursed = (float)$pdo->query("SELECT COALESCE(SUM(amount), 0) FROM disbursements WHERE status = 'completed'")->fetchColumn();

// 2. Recent Applications
$recentAppsStmt = $pdo->query("
    SELECT a.*, ap.first_name, ap.last_name, ap.id_number, p.name AS program_name
    FROM applications a
    INNER JOIN applicants ap ON a.applicant_id = ap.id
    LEFT JOIN programs p ON a.program_id = p.id
    ORDER BY a.created_at DESC
    LIMIT 6
");
$recentApplications = $recentAppsStmt->fetchAll();

$pageTitle = 'Administrator Dashboard';
$activeNav = 'dashboard';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/admin-sidebar.php';
?>

<div class="main-wrapper">
    <!-- TOPBAR -->
    <header class="topbar">
        <div class="d-flex align-items-center gap-3">
            <button class="mobile-toggle-btn" id="mobileToggleBtn" aria-label="Toggle Sidebar">
                <i class="bi bi-list"></i>
            </button>
            <span class="topbar-title">Administrator Dashboard</span>
        </div>
        <div class="topbar-user">
            <span class="text-muted d-none d-md-inline">Signed in as:</span>
            <strong><?php echo e($_SESSION['username'] ?? 'Administrator'); ?></strong>
        </div>
    </header>

    <div class="content-body">
        <div class="container-fluid p-0">
            <?php echo renderFlash(); ?>

            <!-- WELCOME HEADER -->
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
                <div>
                    <h3 class="fw-bold text-dark mb-1">Welcome back, <?php echo e($_SESSION['username'] ?? 'Admin'); ?>!</h3>
                    <p class="text-muted mb-0">System overview, applicant status, fund allocations, and recent submissions.</p>
                </div>
                <div class="d-flex gap-2">
                    <a href="applications.php" class="btn btn-green">
                        <i class="bi bi-file-earmark-text me-1"></i> Review Applications
                    </a>
                    <a href="reports.php" class="btn btn-outline-secondary">
                        <i class="bi bi-graph-up me-1"></i> View Reports
                    </a>
                </div>
            </div>

            <!-- STATISTICS CARDS -->
            <div class="row g-3 mb-4">
                <div class="col-sm-6 col-xl-3">
                    <div class="stat-card">
                        <div class="stat-title">Total Applicants</div>
                        <div class="stat-value"><?php echo $totalApplicants; ?></div>
                        <div class="stat-subtitle">Registered student profiles</div>
                    </div>
                </div>

                <div class="col-sm-6 col-xl-3">
                    <div class="stat-card">
                        <div class="stat-title">Total Applications</div>
                        <div class="stat-value"><?php echo $totalApplications; ?></div>
                        <div class="stat-subtitle">Across all bursary categories</div>
                    </div>
                </div>

                <div class="col-sm-6 col-xl-3">
                    <div class="stat-card">
                        <div class="stat-title">Pending Review</div>
                        <div class="stat-value text-warning"><?php echo $pendingApplications; ?></div>
                        <div class="stat-subtitle">Awaiting committee decision</div>
                    </div>
                </div>

                <div class="col-sm-6 col-xl-3">
                    <div class="stat-card">
                        <div class="stat-title">Approved Awards</div>
                        <div class="stat-value text-success"><?php echo $approvedApplications; ?></div>
                        <div class="stat-subtitle"><?php echo formatMoney($totalAllocated); ?> allocated</div>
                    </div>
                </div>
            </div>

            <!-- QUICK ACTIONS -->
            <div class="card-custom mb-4">
                <div class="card-header-custom">
                    <h5 class="fw-bold mb-0 text-dark"><i class="bi bi-lightning-charge-fill text-success me-2"></i>Quick Administration Navigation</h5>
                </div>
                <div class="card-body-custom">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <a href="applicants.php" class="btn btn-outline-green w-100 py-3 d-flex align-items-center justify-content-center gap-2">
                                <i class="bi bi-people-fill fs-5"></i> Manage Applicants
                            </a>
                        </div>
                        <div class="col-md-3">
                            <a href="applications.php" class="btn btn-outline-green w-100 py-3 d-flex align-items-center justify-content-center gap-2">
                                <i class="bi bi-file-earmark-check fs-5"></i> Review Applications
                            </a>
                        </div>
                        <div class="col-md-3">
                            <a href="documents.php" class="btn btn-outline-green w-100 py-3 d-flex align-items-center justify-content-center gap-2">
                                <i class="bi bi-paperclip fs-5"></i> Verify Documents
                            </a>
                        </div>
                        <div class="col-md-3">
                            <a href="allocations.php" class="btn btn-outline-green w-100 py-3 d-flex align-items-center justify-content-center gap-2">
                                <i class="bi bi-cash-coin fs-5"></i> Fund Allocations
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- RECENT SUBMITTED APPLICATIONS -->
            <div class="card-custom">
                <div class="card-header-custom">
                    <div>
                        <h5 class="fw-bold mb-0 text-dark"><i class="bi bi-clock-history text-success me-2"></i>Recent Bursary Applications</h5>
                        <small class="text-muted">Latest student applications submitted to the portal</small>
                    </div>
                    <a href="applications.php" class="btn btn-sm btn-outline-secondary">View All Applications</a>
                </div>
                <div class="card-body-custom p-0">
                    <div class="table-responsive">
                        <table class="table table-custom mb-0">
                            <thead>
                                <tr>
                                    <th>Ref #</th>
                                    <th>Applicant Name</th>
                                    <th>ID Number</th>
                                    <th>Program</th>
                                    <th>Requested</th>
                                    <th>Status</th>
                                    <th>Submitted Date</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($recentApplications)): ?>
                                    <tr>
                                        <td colspan="8" class="text-center py-4 text-muted">
                                            No applications submitted yet.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($recentApplications as $rapp): ?>
                                        <tr>
                                            <td><strong><?php echo e($rapp['application_number']); ?></strong></td>
                                            <td class="fw-semibold text-dark"><?php echo e($rapp['first_name'] . ' ' . $rapp['last_name']); ?></td>
                                            <td><code><?php echo e($rapp['id_number']); ?></code></td>
                                            <td><?php echo e($rapp['program_name'] ?? 'General Bursary'); ?></td>
                                            <td class="fw-bold text-success"><?php echo formatMoney((float)$rapp['requested_amount']); ?></td>
                                            <td><?php echo renderStatusBadge($rapp['status']); ?></td>
                                            <td class="small text-muted"><?php echo $rapp['submitted_at'] ? date('d M Y', strtotime($rapp['submitted_at'])) : 'Draft'; ?></td>
                                            <td>
                                                <a href="applications.php?review=<?php echo $rapp['id']; ?>" class="btn btn-xs btn-outline-green py-1 px-2">
                                                    <i class="bi bi-search me-1"></i> Review
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>