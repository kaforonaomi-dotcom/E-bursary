<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('applicant');

$userId = (int)$_SESSION['user_id'];
$applicant = getCurrentApplicant($pdo);

if (!$applicant) {
    header('Location: profile.php');
    exit;
}

// Fetch latest application
$appStmt = $pdo->prepare("
    SELECT a.*, p.name AS program_name
    FROM applications a
    LEFT JOIN programs p ON a.program_id = p.id
    WHERE a.applicant_id = ?
    ORDER BY a.id DESC LIMIT 1
");
$appStmt->execute([$applicant['id']]);
$application = $appStmt->fetch();

// Fetch allocation if approved
$allocation = null;
if ($application && $application['status'] === 'approved') {
    $allocStmt = $pdo->prepare("SELECT * FROM allocations WHERE application_id = ? LIMIT 1");
    $allocStmt->execute([$application['id']]);
    $allocation = $allocStmt->fetch();
}

// Fetch education details
$eduStmt = $pdo->prepare("SELECT * FROM education WHERE applicant_id = ? ORDER BY id DESC LIMIT 1");
$eduStmt->execute([$applicant['id']]);
$education = $eduStmt->fetch();

// Fetch guardian details
$guardStmt = $pdo->prepare("SELECT * FROM guardians WHERE applicant_id = ? ORDER BY id DESC LIMIT 1");
$guardStmt->execute([$applicant['id']]);
$guardian = $guardStmt->fetch();

// Count uploaded documents
$docCountStmt = $pdo->prepare("SELECT COUNT(*) FROM documents WHERE applicant_id = ?");
$docCountStmt->execute([$applicant['id']]);
$uploadedDocsCount = (int)$docCountStmt->fetchColumn();

// Fetch recent notifications
$notifStmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 3");
$notifStmt->execute([$userId]);
$recentNotifications = $notifStmt->fetchAll();

// Calculate completion progress
$completedSteps = 1; // Profile created upon registration
if (!empty($applicant['first_name']) && !empty($applicant['id_number']) && !empty($applicant['phone']) && !empty($applicant['county'])) {
    $completedSteps++;
}
if ($education) {
    $completedSteps++;
}
if ($guardian) {
    $completedSteps++;
}
if ($uploadedDocsCount >= 3) {
    $completedSteps++;
}
$progressPercent = min(100, round(($completedSteps / 5) * 100));

$pageTitle = 'Applicant Dashboard';
$activeNav = 'dashboard';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/applicant-sidebar.php';
?>

<div class="main-wrapper">
    <!-- TOPBAR -->
    <header class="topbar">
        <div class="d-flex align-items-center gap-3">
            <button class="mobile-toggle-btn" id="mobileToggleBtn" aria-label="Toggle Sidebar">
                <i class="bi bi-list"></i>
            </button>
            <span class="topbar-title">Applicant Dashboard</span>
        </div>
        <div class="topbar-user">
            <a href="notifications.php" class="notification-badge me-2" title="Notifications">
                <i class="bi bi-bell"></i>
                <?php if ($unreadCount > 0): ?>
                    <span class="badge-count"><?php echo $unreadCount; ?></span>
                <?php endif; ?>
            </a>
            <span class="text-muted d-none d-md-inline">Welcome,</span>
            <strong><?php echo e($applicant['first_name'] . ' ' . $applicant['last_name']); ?></strong>
        </div>
    </header>

    <div class="content-body">
        <div class="container-fluid p-0">
            <?php echo renderFlash(); ?>

            <!-- WELCOME BANNER -->
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
                <div>
                    <h3 class="fw-bold text-dark mb-1">Hello, <?php echo e($applicant['first_name']); ?>! 👋</h3>
                    <p class="text-muted mb-0">Track your bursary application progress and manage your profile details.</p>
                </div>
                <div>
                    <?php if (!$application): ?>
                        <a href="application.php" class="btn btn-green">
                            <i class="bi bi-plus-circle me-1"></i> Apply for Bursary
                        </a>
                    <?php elseif ($application['status'] === 'draft'): ?>
                        <a href="review.php" class="btn btn-green">
                            <i class="bi bi-send-check me-1"></i> Review & Submit Application
                        </a>
                    <?php else: ?>
                        <a href="status.php" class="btn btn-outline-green">
                            <i class="bi bi-clipboard-check me-1"></i> View Application Status
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- STATS CARDS -->
            <div class="row g-3 mb-4">
                <div class="col-sm-6 col-xl-3">
                    <div class="stat-card">
                        <div class="stat-title">Application Status</div>
                        <div class="stat-value fs-4">
                            <?php echo $application ? renderStatusBadge($application['status']) : '<span class="badge bg-secondary">Not Started</span>'; ?>
                        </div>
                        <div class="stat-subtitle">
                            <?php echo $application ? 'Ref: ' . e($application['application_number']) : 'No active application'; ?>
                        </div>
                    </div>
                </div>

                <div class="col-sm-6 col-xl-3">
                    <div class="stat-card">
                        <div class="stat-title">Requested Amount</div>
                        <div class="stat-value">
                            <?php echo $application ? formatMoney((float)$application['requested_amount']) : 'KES 0.00'; ?>
                        </div>
                        <div class="stat-subtitle">
                            <?php echo $application ? e($application['program_name'] ?? 'General Bursary') : 'Apply to request'; ?>
                        </div>
                    </div>
                </div>

                <div class="col-sm-6 col-xl-3">
                    <div class="stat-card">
                        <div class="stat-title">Approved Allocation</div>
                        <div class="stat-value text-success">
                            <?php echo $allocation ? formatMoney((float)$allocation['approved_amount']) : 'KES 0.00'; ?>
                        </div>
                        <div class="stat-subtitle">
                            <?php echo $allocation ? 'Status: ' . ucfirst($allocation['status']) : 'Pending committee approval'; ?>
                        </div>
                    </div>
                </div>

                <div class="col-sm-6 col-xl-3">
                    <div class="stat-card">
                        <div class="stat-title">Uploaded Documents</div>
                        <div class="stat-value">
                            <?php echo $uploadedDocsCount; ?> / 4
                        </div>
                        <div class="stat-subtitle">
                            <?php echo $uploadedDocsCount >= 4 ? 'All mandatory docs provided' : 'Action needed on documents'; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- APPLICATION READINESS PROGRESS -->
            <div class="card-custom mb-4">
                <div class="card-body-custom">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="fw-bold text-dark"><i class="bi bi-list-check text-success me-2"></i>Application Profile Completion</span>
                        <span class="fw-bold text-success"><?php echo $progressPercent; ?>%</span>
                    </div>
                    <div class="progress" style="height: 10px;">
                        <div class="progress-bar bg-success" role="progressbar" style="width: <?php echo $progressPercent; ?>%;" aria-valuenow="<?php echo $progressPercent; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                    </div>
                    <small class="text-muted d-block mt-2">Complete all profile sections, academic records, and document uploads to qualify for consideration.</small>
                </div>
            </div>

            <!-- QUICK NAVIGATION TILES -->
            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <a href="profile.php" class="text-decoration-none">
                        <div class="stat-card d-flex align-items-center gap-3">
                            <div class="p-3 bg-light rounded-circle text-success fs-3">
                                <i class="bi bi-person-fill"></i>
                            </div>
                            <div>
                                <h6 class="fw-bold text-dark mb-1">Personal Details</h6>
                                <small class="text-muted">Identification & contacts</small>
                            </div>
                        </div>
                    </a>
                </div>

                <div class="col-md-4">
                    <a href="education.php" class="text-decoration-none">
                        <div class="stat-card d-flex align-items-center gap-3">
                            <div class="p-3 bg-light rounded-circle text-success fs-3">
                                <i class="bi bi-mortarboard-fill"></i>
                            </div>
                            <div>
                                <h6 class="fw-bold text-dark mb-1">Academic Info</h6>
                                <small class="text-muted"><?php echo $education ? e($education['institution_name']) : 'Add your school/institution'; ?></small>
                            </div>
                        </div>
                    </a>
                </div>

                <div class="col-md-4">
                    <a href="documents.php" class="text-decoration-none">
                        <div class="stat-card d-flex align-items-center gap-3">
                            <div class="p-3 bg-light rounded-circle text-success fs-3">
                                <i class="bi bi-paperclip"></i>
                            </div>
                            <div>
                                <h6 class="fw-bold text-dark mb-1">Document Uploads</h6>
                                <small class="text-muted"><?php echo $uploadedDocsCount; ?> files uploaded</small>
                            </div>
                        </div>
                    </a>
                </div>
            </div>

            <!-- RECENT NOTIFICATIONS -->
            <div class="card-custom">
                <div class="card-header-custom">
                    <h5 class="fw-bold mb-0 text-dark"><i class="bi bi-bell me-2 text-success"></i>Recent Notifications</h5>
                    <a href="notifications.php" class="btn btn-sm btn-outline-secondary">View All</a>
                </div>
                <div class="card-body-custom p-0">
                    <?php if (empty($recentNotifications)): ?>
                        <div class="text-center py-4 text-muted small">No notifications yet.</div>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($recentNotifications as $notif): ?>
                                <div class="list-group-item p-3">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <strong class="text-dark small"><?php echo e($notif['title']); ?></strong>
                                        <small class="text-muted"><?php echo date('d M, h:i A', strtotime($notif['created_at'])); ?></small>
                                    </div>
                                    <p class="text-muted small mb-0"><?php echo e($notif['message']); ?></p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

