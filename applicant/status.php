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

// Fetch all applications for this applicant
$stmt = $pdo->prepare("
    SELECT a.*, p.name AS program_name, p.maximum_amount AS program_max
    FROM applications a
    LEFT JOIN programs p ON a.program_id = p.id
    WHERE a.applicant_id = ?
    ORDER BY a.id DESC
");
$stmt->execute([$applicant['id']]);
$applications = $stmt->fetchAll();

// Get the latest application
$currentApp = $applications[0] ?? null;

// If current application is approved, fetch allocation details
$allocation = null;
$disbursements = [];
if ($currentApp) {
    $allocStmt = $pdo->prepare("SELECT * FROM allocations WHERE application_id = ? LIMIT 1");
    $allocStmt->execute([$currentApp['id']]);
    $allocation = $allocStmt->fetch();

    if ($allocation) {
        $disbStmt = $pdo->prepare("SELECT * FROM disbursements WHERE allocation_id = ? ORDER BY id DESC");
        $disbStmt->execute([$allocation['id']]);
        $disbursements = $disbStmt->fetchAll();
    }
}

$pageTitle = 'Application Status';
$activeNav = 'status';
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
            <span class="topbar-title">Application Status Tracking</span>
        </div>
        <div class="topbar-user">
            <span class="text-muted d-none d-md-inline">Applicant:</span>
            <strong><?php echo e($applicant['first_name'] . ' ' . $applicant['last_name']); ?></strong>
        </div>
    </header>

    <div class="content-body">
        <div class="container-fluid p-0" style="max-width: 950px;">
            <?php echo renderFlash(); ?>

            <?php if (!$currentApp): ?>
                <div class="card-custom text-center py-5">
                    <div class="py-4">
                        <i class="bi bi-folder2-open text-muted" style="font-size: 4rem;"></i>
                        <h4 class="fw-bold mt-3">No Application Found</h4>
                        <p class="text-muted mb-4">You have not created or submitted a bursary application yet.</p>
                        <a href="application.php" class="btn btn-green">
                            <i class="bi bi-plus-circle me-1"></i> Start New Application
                        </a>
                    </div>
                </div>
            <?php else: ?>

                <!-- Current Application Main Card -->
                <div class="card-custom">
                    <div class="card-header-custom">
                        <div>
                            <span class="text-muted small">Application Reference</span>
                            <h5 class="fw-bold mb-0 text-dark"><?php echo e($currentApp['application_number']); ?></h5>
                        </div>
                        <div class="text-end">
                            <span class="d-block small text-muted mb-1">Current Status</span>
                            <div><?php echo renderStatusBadge($currentApp['status']); ?></div>
                        </div>
                    </div>

                    <div class="card-body-custom">
                        <!-- Status Alert Box -->
                        <?php if ($currentApp['status'] === 'draft'): ?>
                            <div class="alert alert-secondary d-flex align-items-center justify-content-between">
                                <div>
                                    <i class="bi bi-pencil-square me-2"></i>
                                    <strong>Application in Draft:</strong> You have not submitted this application yet.
                                </div>
                                <a href="application.php" class="btn btn-sm btn-green">Complete & Submit</a>
                            </div>
                        <?php elseif ($currentApp['status'] === 'submitted' || $currentApp['status'] === 'pending'): ?>
                            <div class="alert alert-info d-flex align-items-center">
                                <i class="bi bi-hourglass-split fs-4 me-3"></i>
                                <div>
                                    <h6 class="alert-heading fw-bold mb-1">Application Submitted & Pending Verification</h6>
                                    <span>Your application was submitted on <?php echo date('F d, Y h:i A', strtotime($currentApp['submitted_at'])); ?> and is currently awaiting review by the bursary committee.</span>
                                </div>
                            </div>
                        <?php elseif ($currentApp['status'] === 'under_review'): ?>
                            <div class="alert alert-primary d-flex align-items-center">
                                <i class="bi bi-search fs-4 me-3"></i>
                                <div>
                                    <h6 class="alert-heading fw-bold mb-1">Under Active Committee Review</h6>
                                    <span>Your application documents and financial verification are currently being evaluated by the review board.</span>
                                </div>
                            </div>
                        <?php elseif ($currentApp['status'] === 'approved'): ?>
                            <div class="alert alert-success d-flex align-items-center">
                                <i class="bi bi-check-circle-fill fs-3 me-3 text-success"></i>
                                <div>
                                    <h6 class="alert-heading fw-bold mb-1">Bursary Application Approved!</h6>
                                    <span>Congratulations! Your application has been approved for funding support. Check the allocation breakdown below.</span>
                                </div>
                            </div>
                        <?php elseif ($currentApp['status'] === 'rejected'): ?>
                            <div class="alert alert-danger d-flex align-items-center">
                                <i class="bi bi-x-circle-fill fs-3 me-3 text-danger"></i>
                                <div>
                                    <h6 class="alert-heading fw-bold mb-1">Application Not Successful</h6>
                                    <span>Unfortunately, your application was not approved for funding during this cycle.</span>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Details Grid -->
                        <div class="row g-3 my-2">
                            <div class="col-md-6">
                                <div class="p-3 border rounded bg-light">
                                    <small class="text-muted d-block mb-1">Bursary Program</small>
                                    <strong class="fs-6"><?php echo e($currentApp['program_name'] ?? 'General Bursary'); ?></strong>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="p-3 border rounded bg-light">
                                    <small class="text-muted d-block mb-1">Requested Amount</small>
                                    <strong class="fs-6 text-success"><?php echo formatMoney((float)$currentApp['requested_amount']); ?></strong>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="p-3 border rounded bg-light">
                                    <small class="text-muted d-block mb-1">Date Submitted</small>
                                    <strong><?php echo $currentApp['submitted_at'] ? date('F d, Y h:i A', strtotime($currentApp['submitted_at'])) : 'Not Submitted'; ?></strong>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="p-3 border rounded bg-light">
                                    <small class="text-muted d-block mb-1">Date Reviewed</small>
                                    <strong><?php echo $currentApp['reviewed_at'] ? date('F d, Y', strtotime($currentApp['reviewed_at'])) : 'Pending Review'; ?></strong>
                                </div>
                            </div>
                        </div>

                        <!-- Feedback / Rejection Reason -->
                        <?php if (!empty($currentApp['rejection_reason'])): ?>
                            <div class="p-3 border border-danger rounded bg-white mt-3">
                                <h6 class="text-danger fw-bold mb-1"><i class="bi bi-exclamation-triangle-fill me-1"></i>Reason for Rejection:</h6>
                                <p class="mb-0 text-dark"><?php echo nl2br(e($currentApp['rejection_reason'])); ?></p>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($currentApp['admin_comment'])): ?>
                            <div class="p-3 border rounded bg-white mt-3">
                                <h6 class="text-primary fw-bold mb-1"><i class="bi bi-chat-left-text me-1"></i>Administrator Notes:</h6>
                                <p class="mb-0 text-muted"><?php echo nl2br(e($currentApp['admin_comment'])); ?></p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ALLOCATION DETAILS (If Approved) -->
                <?php if ($allocation): ?>
                    <div class="card-custom">
                        <div class="card-header-custom">
                            <div>
                                <h5 class="fw-bold mb-0 text-success"><i class="bi bi-cash-coin me-2"></i>Bursary Allocation Details</h5>
                                <small class="text-muted">Funds allocated by the Bursary Committee</small>
                            </div>
                            <?php echo renderStatusBadge($allocation['status']); ?>
                        </div>
                        <div class="card-body-custom">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <small class="text-muted d-block">Approved Amount</small>
                                    <span class="fs-4 fw-bold text-success"><?php echo formatMoney((float)$allocation['approved_amount']); ?></span>
                                </div>
                                <div class="col-md-4">
                                    <small class="text-muted d-block">Allocation Date</small>
                                    <strong><?php echo $allocation['allocation_date'] ? date('d M Y', strtotime($allocation['allocation_date'])) : 'Pending'; ?></strong>
                                </div>
                                <div class="col-md-4">
                                    <small class="text-muted d-block">Academic Year</small>
                                    <strong><?php echo e($allocation['academic_year'] ?? 'Current'); ?></strong>
                                </div>
                                <?php if (!empty($allocation['notes'])): ?>
                                    <div class="col-12">
                                        <small class="text-muted d-block">Allocation Notes</small>
                                        <p class="mb-0 text-muted"><?php echo e($allocation['notes']); ?></p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- DISBURSEMENT TRACKING -->
                    <div class="card-custom">
                        <div class="card-header-custom">
                            <h5 class="fw-bold mb-0 text-dark"><i class="bi bi-credit-card-2-front me-2"></i>Disbursement Records</h5>
                            <small class="text-muted">Payment status to institution / bank</small>
                        </div>
                        <div class="card-body-custom">
                            <?php if (empty($disbursements)): ?>
                                <div class="text-center py-4 text-muted">
                                    <i class="bi bi-clock-history fs-3 d-block mb-2"></i>
                                    Funds are allocated and awaiting disbursement processing by the County Treasury.
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-custom">
                                        <thead>
                                            <tr>
                                                <th>Disbursement Date</th>
                                                <th>Amount</th>
                                                <th>Method</th>
                                                <th>Payment Ref / Cheque No</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($disbursements as $disb): ?>
                                                <tr>
                                                    <td><?php echo $disb['disbursement_date'] ? date('d M Y', strtotime($disb['disbursement_date'])) : 'Pending'; ?></td>
                                                    <td class="fw-bold text-success"><?php echo formatMoney((float)$disb['amount']); ?></td>
                                                    <td><?php echo e($disb['payment_method'] ?? 'Bank Transfer'); ?></td>
                                                    <td><code><?php echo e($disb['payment_reference'] ?? 'Pending'); ?></code></td>
                                                    <td><?php echo renderStatusBadge($disb['status']); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

            <?php endif; ?>
        </div>
    </div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

