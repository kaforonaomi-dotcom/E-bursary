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

// Check deadline & system application status
$sysAppStatus = getSetting($pdo, 'application_status', 'open');
$sysDeadline  = getSetting($pdo, 'application_deadline', '');
$isSystemClosed = ($sysAppStatus === 'closed') || (!empty($sysDeadline) && strtotime($sysDeadline) < strtotime(date('Y-m-d')));

// Fetch education details to match eligible programs
$eduStmt = $pdo->prepare("SELECT * FROM education WHERE applicant_id = ? ORDER BY id DESC LIMIT 1");
$eduStmt->execute([$applicant['id']]);
$education = $eduStmt->fetch();

// Fetch applicant's existing application (if any)
$appStmt = $pdo->prepare("
    SELECT a.*, p.name AS program_name, p.maximum_amount AS program_max, p.application_close_date
    FROM applications a
    LEFT JOIN programs p ON a.program_id = p.id
    WHERE a.applicant_id = ?
    ORDER BY a.id DESC LIMIT 1
");
$appStmt->execute([$applicant['id']]);
$application = $appStmt->fetch() ?: null;

$isSubmitted = $application && in_array($application['status'], ['submitted', 'pending', 'under_review', 'approved', 'rejected']);

// If already submitted, redirect to status page
if ($isSubmitted && !isset($_GET['view'])) {
    header('Location: status.php');
    exit;
}

// Fetch active programs matching education level (or the program already selected in draft)
$eduLevel = $education['education_level'] ?? 'all';
$progSql = "
    SELECT * FROM programs
    WHERE (
        status = 'active'
        AND (education_level = ? OR education_level = 'all')
        AND (application_close_date IS NULL OR application_close_date >= CURDATE())
    )
";
$progParams = [$eduLevel];
if ($application && !empty($application['program_id'])) {
    $progSql .= " OR id = ?";
    $progParams[] = (int)$application['program_id'];
}
$progSql .= " ORDER BY name ASC";
$progStmt = $pdo->prepare($progSql);
$progStmt->execute($progParams);
$availablePrograms = $progStmt->fetchAll();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isSubmitted) {
    if ($isSystemClosed) {
        $errors[] = 'The application window is currently closed. New applications or updates cannot be saved.';
    } elseif (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token. Please try again.';
    } else {
        $programId       = !empty($_POST['program_id']) ? (int)$_POST['program_id'] : null;
        $requestedAmount = !empty($_POST['requested_amount']) ? (float)$_POST['requested_amount'] : null;
        $financialNeed   = trim($_POST['financial_need'] ?? '');
        $reason          = trim($_POST['reason_for_application'] ?? '');

        if (!$programId) {
            $errors[] = 'Please select a bursary program.';
        }
        if (!$requestedAmount || $requestedAmount <= 0) {
            $errors[] = 'Please enter a valid requested amount greater than 0.';
        }
        if (empty($financialNeed)) {
            $errors[] = 'Please describe your family/personal financial need.';
        }
        if (empty($reason)) {
            $errors[] = 'Please describe your reason for applying for this bursary.';
        }

        // Validate program limit
        if ($programId) {
            $pCheck = $pdo->prepare("SELECT maximum_amount, status, application_close_date FROM programs WHERE id = ?");
            $pCheck->execute([$programId]);
            $progData = $pCheck->fetch();

            if (!$progData || $progData['status'] !== 'active') {
                $errors[] = 'The selected bursary program is currently not active.';
            } elseif (!empty($progData['application_close_date']) && strtotime($progData['application_close_date']) < strtotime(date('Y-m-d'))) {
                $errors[] = 'The deadline for the selected program has passed.';
            } elseif (!empty($progData['maximum_amount']) && $requestedAmount > (float)$progData['maximum_amount']) {
                $errors[] = 'Requested amount cannot exceed the program maximum of ' . formatMoney((float)$progData['maximum_amount']) . '.';
            }
        }

        if (empty($errors)) {
            try {
                if ($application && $application['status'] === 'draft') {
                    // Update existing draft
                    $updateStmt = $pdo->prepare("
                        UPDATE applications SET
                            program_id = ?,
                            requested_amount = ?,
                            financial_need = ?,
                            reason_for_application = ?,
                            updated_at = NOW()
                        WHERE id = ? AND applicant_id = ?
                    ");
                    $updateStmt->execute([
                        $programId,
                        $requestedAmount,
                        $financialNeed,
                        $reason,
                        $application['id'],
                        $applicant['id']
                    ]);
                } else {
                    // Create new draft application
                    $appNumber = generateApplicationNumber($pdo);
                    $insertStmt = $pdo->prepare("
                        INSERT INTO applications (
                            applicant_id, program_id, application_number, requested_amount,
                            financial_need, reason_for_application, status, created_at
                        ) VALUES (
                            ?, ?, ?, ?,
                            ?, ?, 'draft', NOW()
                        )
                    ");
                    $insertStmt->execute([
                        $applicant['id'],
                        $programId,
                        $appNumber,
                        $requestedAmount,
                        $financialNeed,
                        $reason
                    ]);
                }

                $actionNext = $_POST['action_next'] ?? (isset($_POST['save_and_documents']) || isset($_POST['save_and_review']) ? 'continue' : 'stay');
                if ($actionNext === 'stay' || isset($_POST['save_draft'])) {
                    setFlash('success', 'Bursary application details saved as draft.');
                    header('Location: application.php');
                    exit;
                } else {
                    setFlash('success', 'Bursary application details saved! Proceeding to Step 5: Supporting Documents.');
                    header('Location: documents.php');
                    exit;
                }

            } catch (PDOException $e) {
                $errors[] = 'Database error: ' . $e->getMessage();
            }
        }
    }
}

$wizardSteps = getApplicantStepsStatus($pdo, $applicant['id'], $applicant);

$pageTitle = 'Bursary Application';
$activeNav = 'application';
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
            <span class="topbar-title">Bursary Application</span>
        </div>
        <div class="topbar-user">
            <span class="text-muted d-none d-md-inline">Applicant:</span>
            <strong><?php echo e($applicant['first_name'] . ' ' . $applicant['last_name']); ?></strong>
        </div>
    </header>

    <div class="content-body">
        <div class="container-fluid p-0" style="max-width: 950px;">
            <?php echo renderFlash(); ?>
            <?php echo renderApplicantWizardHtml('application', $wizardSteps); ?>

            <!-- Application Deadline Banner -->
            <?php if ($isSystemClosed): ?>
                <div class="alert alert-danger d-flex align-items-center mb-4" role="alert">
                    <i class="bi bi-exclamation-octagon-fill fs-3 me-3"></i>
                    <div>
                        <h6 class="alert-heading fw-bold mb-1">Application Period Closed</h6>
                        <span>The bursary application period is currently closed. New applications or modifications are not permitted at this time.</span>
                        <?php if (!empty($sysDeadline)): ?>
                            <div class="small fw-semibold mt-1">Closing Date was: <?php echo date('F d, Y', strtotime($sysDeadline)); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php elseif (!empty($sysDeadline)): ?>
                <div class="alert alert-info d-flex align-items-center mb-4" role="alert">
                    <i class="bi bi-calendar-event-fill fs-4 me-3 text-primary"></i>
                    <div>
                        <strong>Notice:</strong> Application Deadline is <strong><?php echo date('F d, Y', strtotime($sysDeadline)); ?></strong>. Ensure your application is completed and submitted before this date.
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!$education): ?>
                <div class="alert alert-warning d-flex align-items-center mb-4" role="alert">
                    <i class="bi bi-mortarboard fs-4 me-3 text-warning"></i>
                    <div>
                        <strong>Incomplete Academic Profile:</strong> Please complete your <a href="education.php" class="alert-link">Education Information</a> before proceeding with this application.
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <h6 class="alert-heading fw-bold mb-1">Please correct the following:</h6>
                    <ul class="mb-0 ps-3">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo e($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="card-custom">
                <div class="card-header-custom">
                    <div>
                        <h5 class="fw-bold mb-1"><i class="bi bi-pencil-square text-success me-2"></i>Bursary Application Request</h5>
                        <p class="text-muted small mb-0">Select a bursary program and detail your financial need.</p>
                    </div>
                    <?php if ($application): ?>
                        <span class="badge bg-secondary">
                            Ref: <?php echo e($application['application_number']); ?> (<?php echo strtoupper($application['status']); ?>)
                        </span>
                    <?php endif; ?>
                </div>

                <div class="card-body-custom">
                    <form action="application.php" method="POST">
                        <?php echo csrfInput(); ?>

                        <div class="mb-4">
                            <label class="form-label fs-6 fw-bold">Select Bursary Program <span class="text-danger">*</span></label>
                            <?php if (empty($availablePrograms)): ?>
                                <div class="alert alert-warning mb-0">
                                    No active bursary programs are currently open for your education level (<?php echo e(ucfirst($eduLevel)); ?>). Please check back later.
                                </div>
                            <?php else: ?>
                                <select name="program_id" id="program_id" class="form-select form-select-lg" required <?php echo $isSystemClosed ? 'disabled' : ''; ?>>
                                    <option value="">-- Choose Active Bursary Program --</option>
                                    <?php foreach ($availablePrograms as $prog):
                                        $selected = ($application && $application['program_id'] == $prog['id']) ? 'selected' : '';
                                        $maxTxt = $prog['maximum_amount'] ? ' - Max: ' . formatMoney((float)$prog['maximum_amount']) : '';
                                    ?>
                                        <option value="<?php echo $prog['id']; ?>" data-max="<?php echo (float)$prog['maximum_amount']; ?>" <?php echo $selected; ?>>
                                            <?php echo e($prog['name']); ?><?php echo $maxTxt; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                        </div>

                        <div class="mb-4">
                            <label class="form-label fs-6 fw-bold">Requested Bursary Amount (KES) <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light fw-bold text-success">KES</span>
                                <input type="number" step="0.01" min="1000" name="requested_amount" id="requested_amount" class="form-control form-control-lg" value="<?php echo e($application['requested_amount'] ?? ''); ?>" required placeholder="e.g. 25000" <?php echo $isSystemClosed ? 'disabled' : ''; ?>>
                            </div>
                            <small class="text-muted" id="max-amount-help">Amount requested must be in line with your outstanding institution fee balance.</small>
                        </div>

                        <div class="mb-4">
                            <label class="form-label fs-6 fw-bold">Family / Personal Financial Need <span class="text-danger">*</span></label>
                            <textarea name="financial_need" rows="4" class="form-control" required placeholder="Describe your family background, parents' employment or income status, number of siblings currently schooling, and any financial hardships..." <?php echo $isSystemClosed ? 'disabled' : ''; ?>><?php echo e($application['financial_need'] ?? ''); ?></textarea>
                        </div>

                        <div class="mb-4">
                            <label class="form-label fs-6 fw-bold">Reason for Application & Academic Aspirations <span class="text-danger">*</span></label>
                            <textarea name="reason_for_application" rows="4" class="form-control" required placeholder="Explain why you deserve this bursary support and how it will assist in completing your education..." <?php echo $isSystemClosed ? 'disabled' : ''; ?>><?php echo e($application['reason_for_application'] ?? ''); ?></textarea>
                        </div>

                        <?php if (!$isSystemClosed && !empty($availablePrograms)): ?>
                            <div class="d-flex flex-column flex-sm-row justify-content-between align-items-center gap-3 pt-3 border-top">
                                <a href="guardian.php" class="btn btn-outline-secondary">
                                    <i class="bi bi-arrow-left me-1"></i> Back to Guardian Details
                                </a>
                                <div class="d-flex gap-2">
                                    <button type="submit" name="action_next" value="stay" class="btn btn-outline-secondary">
                                        <i class="bi bi-save me-1"></i> Save as Draft
                                    </button>
                                    <button type="submit" name="action_next" value="continue" class="btn btn-green px-4 pulse-btn">
                                        Save & Continue to Documents <i class="bi bi-arrow-right ms-1"></i>
                                    </button>
                                </div>
                            </div>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>
    </div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

