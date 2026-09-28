<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('super_admin');

$errors = [];

// Handle Application Review POST Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['review_action'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token. Please try again.';
    } else {
        $appId = (int)$_POST['application_id'];
        $newStatus = trim($_POST['status'] ?? '');
        $approvedAmount = !empty($_POST['approved_amount']) ? (float)$_POST['approved_amount'] : null;
        $rejectionReason = trim($_POST['rejection_reason'] ?? '');
        $adminComment = trim($_POST['admin_comment'] ?? '');

        if (!in_array($newStatus, ['submitted', 'pending', 'under_review', 'approved', 'rejected'])) {
            $errors[] = 'Invalid status selected.';
        } elseif ($newStatus === 'rejected' && empty($rejectionReason)) {
            $errors[] = 'Please provide a reason for rejecting the application.';
        } elseif ($newStatus === 'approved' && (!$approvedAmount || $approvedAmount <= 0)) {
            $errors[] = 'Please provide a valid approved amount for the bursary.';
        } else {
            try {
                $pdo->beginTransaction();

                // Fetch applicant user id for notification
                $appCheck = $pdo->prepare("
                    SELECT a.*, ap.user_id, ap.first_name, ap.last_name, p.name AS program_name
                    FROM applications a
                    INNER JOIN applicants ap ON a.applicant_id = ap.id
                    LEFT JOIN programs p ON a.program_id = p.id
                    WHERE a.id = ?
                ");
                $appCheck->execute([$appId]);
                $curApp = $appCheck->fetch();

                if (!$curApp) {
                    throw new Exception('Application not found.');
                }

                // Update application
                $upStmt = $pdo->prepare("
                    UPDATE applications SET
                        status = ?,
                        reviewed_at = NOW(),
                        rejection_reason = ?,
                        admin_comment = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $upStmt->execute([
                    $newStatus,
                    ($newStatus === 'rejected') ? $rejectionReason : null,
                    $adminComment ?: null,
                    $appId
                ]);

                // If approved, create or update allocation record
                if ($newStatus === 'approved') {
                    $allocCheck = $pdo->prepare("SELECT id FROM allocations WHERE application_id = ?");
                    $allocCheck->execute([$appId]);
                    $existingAlloc = $allocCheck->fetch();

                    if ($existingAlloc) {
                        $upAlloc = $pdo->prepare("
                            UPDATE allocations SET
                                approved_amount = ?,
                                status = 'approved',
                                allocation_date = CURDATE(),
                                updated_at = NOW()
                            WHERE id = ?
                        ");
                        $upAlloc->execute([$approvedAmount, $existingAlloc['id']]);
                    } else {
                        $insAlloc = $pdo->prepare("
                            INSERT INTO allocations (
                                application_id, approved_amount, allocation_date, academic_year, status, notes, created_at
                            ) VALUES (
                                ?, ?, CURDATE(), ?, 'approved', ?, NOW()
                            )
                        ");
                        $insAlloc->execute([
                            $appId,
                            $approvedAmount,
                            date('Y') . '/' . (date('Y') + 1),
                            'Approved during committee review.'
                        ]);
                    }
                }

                // Send notification to applicant
                $statusTitles = [
                    'under_review' => 'Application Under Review',
                    'approved'     => 'Bursary Application Approved!',
                    'rejected'     => 'Bursary Application Status Update',
                    'pending'      => 'Application Pending Review'
                ];
                $title = $statusTitles[$newStatus] ?? 'Application Status Update';
                $msg = "Your bursary application ({$curApp['application_number']}) status has been updated to: " . strtoupper($newStatus) . ".";
                if ($newStatus === 'approved') {
                    $msg .= " Approved amount: " . formatMoney($approvedAmount) . ".";
                } elseif ($newStatus === 'rejected') {
                    $msg .= " Reason: " . $rejectionReason;
                }

                createNotification($pdo, (int)$curApp['user_id'], $title, $msg);

                $pdo->commit();

                setFlash('success', "Application #{$curApp['application_number']} status updated to " . ucfirst($newStatus) . " successfully.");
                header("Location: applications.php?review=$appId");
                exit;

            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = 'Failed to update application: ' . $e->getMessage();
            }
        }
    }
}

// Single application review view
$reviewId = isset($_GET['review']) ? (int)$_GET['review'] : null;
$reviewData = null;

if ($reviewId) {
    $rStmt = $pdo->prepare("
        SELECT a.*, p.name AS program_name, p.maximum_amount AS program_max,
               ap.first_name, ap.middle_name, ap.last_name, ap.id_number, ap.date_of_birth,
               ap.gender, ap.phone, ap.alternative_phone, ap.email AS applicant_email,
               ap.county, ap.sub_county, ap.ward, ap.physical_address,
               e.education_level, e.institution_name, e.course_programme, e.class_grade,
               e.admission_number, e.registration_number, e.year_of_study, e.semester,
               e.study_mode, e.boarding_type, e.school_contact,
               g.first_name AS g_first, g.last_name AS g_last, g.relationship AS g_rel,
               g.other_relationship AS g_other_rel, g.phone AS g_phone, g.id_number AS g_id,
               g.occupation AS g_occ, g.employer_business AS g_emp
        FROM applications a
        INNER JOIN applicants ap ON a.applicant_id = ap.id
        LEFT JOIN programs p ON a.program_id = p.id
        LEFT JOIN education e ON e.applicant_id = ap.id
        LEFT JOIN guardians g ON g.applicant_id = ap.id
        WHERE a.id = ?
        LIMIT 1
    ");
    $rStmt->execute([$reviewId]);
    $reviewData = $rStmt->fetch();

    if ($reviewData) {
        // Fetch applicant's uploaded documents
        $dStmt = $pdo->prepare("SELECT * FROM documents WHERE applicant_id = ? ORDER BY id ASC");
        $dStmt->execute([$reviewData['applicant_id']]);
        $reviewDocs = $dStmt->fetchAll();

        // Fetch existing allocation if approved
        $alStmt = $pdo->prepare("SELECT * FROM allocations WHERE application_id = ? LIMIT 1");
        $alStmt->execute([$reviewId]);
        $reviewAlloc = $alStmt->fetch();
    }
}

// Search and Filter for Applications list
$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$programFilter = !empty($_GET['program_id']) ? (int)$_GET['program_id'] : null;

$sql = "
    SELECT a.*, ap.first_name, ap.last_name, ap.id_number, ap.phone, ap.county,
           p.name AS program_name,
           (SELECT education_level FROM education WHERE applicant_id = ap.id LIMIT 1) AS edu_level
    FROM applications a
    INNER JOIN applicants ap ON a.applicant_id = ap.id
    LEFT JOIN programs p ON a.program_id = p.id
    WHERE 1=1
";
$params = [];

if (!empty($search)) {
    $sql .= " AND (a.application_number LIKE ? OR ap.first_name LIKE ? OR ap.last_name LIKE ? OR ap.id_number LIKE ?)";
    $term = "%$search%";
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}

if (!empty($statusFilter)) {
    $sql .= " AND a.status = ?";
    $params[] = $statusFilter;
}

if ($programFilter) {
    $sql .= " AND a.program_id = ?";
    $params[] = $programFilter;
}

$sql .= " ORDER BY a.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$applications = $stmt->fetchAll();

// Programs for filter dropdown
$programs = $pdo->query("SELECT id, name FROM programs ORDER BY name ASC")->fetchAll();

$pageTitle = 'Manage Applications';
$activeNav = 'applications';
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
            <span class="topbar-title">Application Management & Review</span>
        </div>
        <div class="topbar-user">
            <span class="text-muted d-none d-md-inline">Administrator:</span>
            <strong><?php echo e($_SESSION['username'] ?? 'Admin'); ?></strong>
        </div>
    </header>

    <div class="content-body">
        <div class="container-fluid p-0">
            <?php echo renderFlash(); ?>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <ul class="mb-0 ps-3">
                        <?php foreach ($errors as $e): ?>
                            <li><?php echo e($e); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if ($reviewData): ?>
                <!-- APPLICATION REVIEW DETAIL VIEW -->
                <div class="mb-3 d-flex justify-content-between align-items-center">
                    <a href="applications.php" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-arrow-left me-1"></i> Back to All Applications
                    </a>
                    <div class="d-flex align-items-center gap-2">
                        <span class="text-muted small">Status:</span>
                        <?php echo renderStatusBadge($reviewData['status']); ?>
                    </div>
                </div>

                <div class="row g-4">
                    <!-- LEFT COLUMN: APPLICANT DOSSIER -->
                    <div class="col-lg-8">
                        <div class="card-custom mb-4">
                            <div class="card-header-custom">
                                <div>
                                    <span class="text-muted small">Application Number</span>
                                    <h4 class="fw-bold mb-0 text-dark"><?php echo e($reviewData['application_number']); ?></h4>
                                </div>
                                <span class="badge bg-light text-dark border">
                                    Submitted: <?php echo $reviewData['submitted_at'] ? date('d M Y, h:i A', strtotime($reviewData['submitted_at'])) : 'Draft'; ?>
                                </span>
                            </div>

                            <div class="card-body-custom">
                                <!-- 1. APPLICANT PERSONAL -->
                                <h6 class="fw-bold text-success border-bottom pb-2 mb-3"><i class="bi bi-person me-2"></i>1. Applicant Information</h6>
                                <div class="row g-2 small mb-4">
                                    <div class="col-md-6"><strong>Full Name:</strong> <?php echo e($reviewData['first_name'] . ' ' . $reviewData['middle_name'] . ' ' . $reviewData['last_name']); ?></div>
                                    <div class="col-md-6"><strong>National ID / Cert:</strong> <?php echo e($reviewData['id_number']); ?></div>
                                    <div class="col-md-6"><strong>Phone:</strong> <?php echo e($reviewData['phone']); ?></div>
                                    <div class="col-md-6"><strong>Email:</strong> <?php echo e($reviewData['applicant_email']); ?></div>
                                    <div class="col-md-6"><strong>DOB & Gender:</strong> <?php echo e($reviewData['date_of_birth']); ?> &bull; <?php echo e($reviewData['gender']); ?></div>
                                    <div class="col-md-6"><strong>County / Ward:</strong> <?php echo e($reviewData['county'] ?? 'N/A'); ?> / <?php echo e($reviewData['ward'] ?? 'N/A'); ?></div>
                                    <div class="col-12"><strong>Address:</strong> <?php echo e($reviewData['physical_address'] ?? 'N/A'); ?></div>
                                </div>

                                <!-- 2. EDUCATION -->
                                <h6 class="fw-bold text-success border-bottom pb-2 mb-3"><i class="bi bi-mortarboard me-2"></i>2. Academic Profile</h6>
                                <div class="row g-2 small mb-4">
                                    <div class="col-md-6"><strong>Institution:</strong> <?php echo e($reviewData['institution_name'] ?? 'N/A'); ?> (<?php echo e(ucwords(str_replace('_', ' ', $reviewData['education_level'] ?? 'N/A'))); ?>)</div>
                                    <div class="col-md-6"><strong>Course / Grade:</strong> <?php echo e($reviewData['course_programme'] ?? ($reviewData['class_grade'] ?? 'N/A')); ?></div>
                                    <div class="col-md-6"><strong>Adm / Reg Number:</strong> <?php echo e($reviewData['registration_number'] ?? ($reviewData['admission_number'] ?? 'N/A')); ?></div>
                                    <div class="col-md-6"><strong>Year / Semester:</strong> <?php echo e($reviewData['year_of_study'] ?? 'N/A'); ?> &bull; <?php echo e($reviewData['semester'] ?? 'N/A'); ?></div>
                                    <div class="col-12"><strong>School Contact:</strong> <?php echo e($reviewData['school_contact'] ?? 'N/A'); ?></div>
                                </div>

                                <!-- 3. GUARDIAN -->
                                <h6 class="fw-bold text-success border-bottom pb-2 mb-3"><i class="bi bi-people me-2"></i>3. Parent / Guardian</h6>
                                <div class="row g-2 small mb-4">
                                    <div class="col-md-6"><strong>Name:</strong> <?php echo e($reviewData['g_first'] ? $reviewData['g_first'] . ' ' . $reviewData['g_last'] : 'N/A'); ?></div>
                                    <div class="col-md-6"><strong>Relationship:</strong> <?php echo e($reviewData['g_rel'] ?? 'N/A'); ?></div>
                                    <div class="col-md-6"><strong>Phone:</strong> <?php echo e($reviewData['g_phone'] ?? 'N/A'); ?></div>
                                    <div class="col-md-6"><strong>Occupation / Employer:</strong> <?php echo e($reviewData['g_occ'] ?? 'N/A'); ?> (<?php echo e($reviewData['g_emp'] ?? 'N/A'); ?>)</div>
                                </div>

                                <!-- 4. BURSARY REQUEST & FINANCIAL NEED -->
                                <h6 class="fw-bold text-success border-bottom pb-2 mb-3"><i class="bi bi-cash-stack me-2"></i>4. Bursary Request & Justification</h6>
                                <div class="row g-2 small mb-3">
                                    <div class="col-md-6"><strong>Bursary Program:</strong> <?php echo e($reviewData['program_name'] ?? 'General Bursary'); ?></div>
                                    <div class="col-md-6"><strong>Requested Amount:</strong> <span class="fw-bold text-success fs-6"><?php echo formatMoney((float)$reviewData['requested_amount']); ?></span></div>
                                    <div class="col-12 mt-2">
                                        <strong>Financial Need Statement:</strong>
                                        <div class="p-3 bg-light rounded mt-1"><?php echo nl2br(e($reviewData['financial_need'])); ?></div>
                                    </div>
                                    <div class="col-12 mt-2">
                                        <strong>Reason for Bursary:</strong>
                                        <div class="p-3 bg-light rounded mt-1"><?php echo nl2br(e($reviewData['reason_for_application'])); ?></div>
                                    </div>
                                </div>

                                <!-- 5. SUPPORTING DOCUMENTS -->
                                <h6 class="fw-bold text-success border-bottom pb-2 mb-3"><i class="bi bi-paperclip me-2"></i>5. Uploaded Supporting Documents</h6>
                                <?php if (empty($reviewDocs)): ?>
                                    <p class="text-muted small">No documents submitted for this application.</p>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-bordered align-middle small">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Document Type</th>
                                                    <th>File Name</th>
                                                    <th>Status</th>
                                                    <th>Review Action</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($reviewDocs as $rd): ?>
                                                    <tr>
                                                        <td class="fw-bold"><?php echo e(ucwords(str_replace('_', ' ', $rd['document_type']))); ?></td>
                                                        <td><?php echo e($rd['original_filename']); ?></td>
                                                        <td><?php echo renderStatusBadge($rd['status']); ?></td>
                                                        <td>
                                                            <a href="documents.php?review=<?php echo $rd['id']; ?>" class="btn btn-xs btn-outline-primary py-0 px-2">
                                                                <i class="bi bi-file-earmark-check me-1"></i> Verify / Reject
                                                            </a>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- RIGHT COLUMN: COMMITTEE DECISION PANEL -->
                    <div class="col-lg-4">
                        <div class="card-custom sticky-top" style="top: 90px;">
                            <div class="card-header-custom">
                                <h5 class="fw-bold mb-0 text-dark"><i class="bi bi-check2-square text-success me-2"></i>Committee Action</h5>
                            </div>
                            <div class="card-body-custom">
                                <form action="applications.php?review=<?php echo $reviewData['id']; ?>" method="POST">
                                    <?php echo csrfInput(); ?>
                                    <input type="hidden" name="review_action" value="1">
                                    <input type="hidden" name="application_id" value="<?php echo $reviewData['id']; ?>">

                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Application Decision <span class="text-danger">*</span></label>
                                        <select name="status" id="decision_status" class="form-select" required>
                                            <option value="under_review" <?php echo ($reviewData['status'] === 'under_review') ? 'selected' : ''; ?>>Under Review</option>
                                            <option value="approved" <?php echo ($reviewData['status'] === 'approved') ? 'selected' : ''; ?>>Approve Application</option>
                                            <option value="rejected" <?php echo ($reviewData['status'] === 'rejected') ? 'selected' : ''; ?>>Reject Application</option>
                                            <option value="pending" <?php echo ($reviewData['status'] === 'pending') ? 'selected' : ''; ?>>Mark as Pending</option>
                                        </select>
                                    </div>

                                    <!-- APPROVED AMOUNT FIELD -->
                                    <div class="mb-3" id="approved_amount_group" style="display: <?php echo ($reviewData['status'] === 'approved') ? 'block' : 'none'; ?>;">
                                        <label class="form-label fw-bold text-success">Approved Allocation Amount (KES) <span class="text-danger">*</span></label>
                                        <input type="number" step="0.01" min="1000" name="approved_amount" class="form-control" value="<?php echo e($reviewAlloc['approved_amount'] ?? $reviewData['requested_amount']); ?>" placeholder="Enter approved amount">
                                        <small class="text-muted">Requested: <?php echo formatMoney((float)$reviewData['requested_amount']); ?></small>
                                    </div>

                                    <!-- REJECTION REASON FIELD -->
                                    <div class="mb-3" id="rejection_reason_group" style="display: <?php echo ($reviewData['status'] === 'rejected') ? 'block' : 'none'; ?>;">
                                        <label class="form-label fw-bold text-danger">Rejection Reason <span class="text-danger">*</span></label>
                                        <textarea name="rejection_reason" rows="3" class="form-control" placeholder="Provide reason for rejecting this application (visible to applicant)..."><?php echo e($reviewData['rejection_reason'] ?? ''); ?></textarea>
                                    </div>

                                    <!-- ADMIN COMMENT -->
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Internal Admin Notes / Comments</label>
                                        <textarea name="admin_comment" rows="3" class="form-control" placeholder="Committee internal remarks or instructions..."><?php echo e($reviewData['admin_comment'] ?? ''); ?></textarea>
                                    </div>

                                    <button type="submit" class="btn btn-green w-100 py-2 fw-bold" data-confirm="Are you sure you want to update this application's decision?">
                                        <i class="bi bi-save me-1"></i> Save Decision & Notify Applicant
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        const statusSelect = document.getElementById('decision_status');
                        const amountGroup  = document.getElementById('approved_amount_group');
                        const rejectGroup  = document.getElementById('rejection_reason_group');

                        function updateVisibility() {
                            const val = statusSelect.value;
                            if (amountGroup) amountGroup.style.display = (val === 'approved') ? 'block' : 'none';
                            if (rejectGroup) rejectGroup.style.display = (val === 'rejected') ? 'block' : 'none';
                        }
                        if (statusSelect) {
                            statusSelect.addEventListener('change', updateVisibility);
                        }
                    });
                </script>

            <?php else: ?>

                <!-- APPLICATIONS LIST VIEW -->
                <div class="card-custom">
                    <div class="card-header-custom">
                        <div>
                            <h5 class="fw-bold mb-1"><i class="bi bi-file-earmark-text-fill text-success me-2"></i>Bursary Applications</h5>
                            <p class="text-muted small mb-0">Review, verify documents, approve or reject submitted bursary applications.</p>
                        </div>
                        <span class="badge bg-success fs-6"><?php echo count($applications); ?> Applications</span>
                    </div>

                    <div class="card-body-custom">
                        <!-- SEARCH & FILTERS -->
                        <form action="applications.php" method="GET" class="row g-3 mb-4">
                            <div class="col-md-4">
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="bi bi-search"></i></span>
                                    <input type="text" name="search" class="form-control" placeholder="Search App #, Name, ID..." value="<?php echo e($search); ?>">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <select name="status" class="form-select">
                                    <option value="">-- Filter by Status --</option>
                                    <option value="draft" <?php echo ($statusFilter === 'draft') ? 'selected' : ''; ?>>Draft</option>
                                    <option value="submitted" <?php echo ($statusFilter === 'submitted') ? 'selected' : ''; ?>>Submitted</option>
                                    <option value="under_review" <?php echo ($statusFilter === 'under_review') ? 'selected' : ''; ?>>Under Review</option>
                                    <option value="approved" <?php echo ($statusFilter === 'approved') ? 'selected' : ''; ?>>Approved</option>
                                    <option value="rejected" <?php echo ($statusFilter === 'rejected') ? 'selected' : ''; ?>>Rejected</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <select name="program_id" class="form-select">
                                    <option value="">-- Filter by Program --</option>
                                    <?php foreach ($programs as $pr): ?>
                                        <option value="<?php echo $pr['id']; ?>" <?php echo ($programFilter == $pr['id']) ? 'selected' : ''; ?>>
                                            <?php echo e($pr['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2 d-flex gap-2">
                                <button type="submit" class="btn btn-green w-100">Filter</button>
                                <?php if (!empty($search) || !empty($statusFilter) || !empty($programFilter)): ?>
                                    <a href="applications.php" class="btn btn-outline-secondary" title="Clear Filters"><i class="bi bi-arrow-counterclockwise"></i></a>
                                <?php endif; ?>
                            </div>
                        </form>

                        <!-- TABLE -->
                        <div class="table-responsive">
                            <table class="table table-custom">
                                <thead>
                                    <tr>
                                        <th>Application #</th>
                                        <th>Applicant Name</th>
                                        <th>ID Number</th>
                                        <th>Program</th>
                                        <th>Requested</th>
                                        <th>Status</th>
                                        <th>Submitted Date</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($applications)): ?>
                                        <tr>
                                            <td colspan="8" class="text-center py-5 text-muted">
                                                <i class="bi bi-folder-x fs-2 d-block mb-2 text-muted"></i>
                                                No bursary applications found matching your criteria.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($applications as $app): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo e($app['application_number']); ?></strong>
                                                </td>
                                                <td>
                                                    <div class="fw-bold text-dark">
                                                        <?php echo e($app['first_name'] . ' ' . $app['last_name']); ?>
                                                    </div>
                                                    <small class="text-muted"><?php echo e($app['phone']); ?></small>
                                                </td>
                                                <td><code><?php echo e($app['id_number']); ?></code></td>
                                                <td><?php echo e($app['program_name'] ?? 'General Bursary'); ?></td>
                                                <td class="fw-bold text-success"><?php echo formatMoney((float)$app['requested_amount']); ?></td>
                                                <td><?php echo renderStatusBadge($app['status']); ?></td>
                                                <td><?php echo $app['submitted_at'] ? date('d M Y', strtotime($app['submitted_at'])) : '<span class="text-muted">Draft</span>'; ?></td>
                                                <td>
                                                    <a href="applications.php?review=<?php echo $app['id']; ?>" class="btn btn-sm btn-green py-1">
                                                        <i class="bi bi-eye me-1"></i> Review
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

            <?php endif; ?>
        </div>
    </div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

