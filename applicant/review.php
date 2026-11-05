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

// Fetch Education
$eduStmt = $pdo->prepare("SELECT * FROM education WHERE applicant_id = ? ORDER BY id DESC LIMIT 1");
$eduStmt->execute([$applicant['id']]);
$education = $eduStmt->fetch();

// Fetch Guardian
$guardStmt = $pdo->prepare("SELECT * FROM guardians WHERE applicant_id = ? ORDER BY id DESC LIMIT 1");
$guardStmt->execute([$applicant['id']]);
$guardian = $guardStmt->fetch();

// Fetch Application
$appStmt = $pdo->prepare("
    SELECT a.*, p.name AS program_name, p.maximum_amount AS program_max
    FROM applications a
    LEFT JOIN programs p ON a.program_id = p.id
    WHERE a.applicant_id = ?
    ORDER BY a.id DESC LIMIT 1
");
$appStmt->execute([$applicant['id']]);
$application = $appStmt->fetch();

// If no application at all, redirect to create one
if (!$application) {
    header('Location: application.php');
    exit;
}

// If already submitted, redirect to status
if ($application['status'] !== 'draft') {
    header('Location: status.php');
    exit;
}

// Check deadline
$sysAppStatus = getSetting($pdo, 'application_status', 'open');
$sysDeadline  = getSetting($pdo, 'application_deadline', '');
$isClosed     = ($sysAppStatus === 'closed') || (!empty($sysDeadline) && strtotime($sysDeadline) < strtotime(date('Y-m-d')));

// Fetch Documents
$docStmt = $pdo->prepare("SELECT * FROM documents WHERE applicant_id = ?");
$docStmt->execute([$applicant['id']]);
$docs = [];
while ($d = $docStmt->fetch()) {
    $docs[$d['document_type']] = $d;
}

// Check mandatory requirements
$missingItems = [];
if (empty($applicant['first_name']) || empty($applicant['last_name']) || empty($applicant['id_number']) || empty($applicant['phone'])) {
    $missingItems[] = 'Personal Information is incomplete. <a href="profile.php" class="alert-link">Edit Profile</a>';
}
if (!$education || empty($education['institution_name'])) {
    $missingItems[] = 'Education Information is missing. <a href="education.php" class="alert-link">Add Education Details</a>';
}

// Minor check
if (!empty($applicant['date_of_birth'])) {
    $age = (new DateTime())->diff(new DateTime($applicant['date_of_birth']))->y;
    if ($age < 18 && (!$guardian || empty($guardian['first_name']) || empty($guardian['id_number']))) {
        $missingItems[] = 'Guardian Information & ID is mandatory for applicants under 18. <a href="guardian.php" class="alert-link">Add Guardian Info</a>';
    }
}

// Mandatory documents check
$mandatoryDocKeys = ['passport_photo', 'identification', 'fee_structure', 'admission_proof'];
foreach ($mandatoryDocKeys as $reqDoc) {
    if (!isset($docs[$reqDoc])) {
        $readableNames = [
            'passport_photo'  => 'Passport Photo',
            'identification'  => 'National ID / Birth Certificate',
            'fee_structure'   => 'Fee Structure / Fee Statement',
            'admission_proof' => 'Admission Letter / Student ID'
        ];
        $missingItems[] = "Mandatory document missing: <strong>{$readableNames[$reqDoc]}</strong>. <a href=\"documents.php\" class=\"alert-link\">Upload Document</a>";
    }
}

$canSubmit = empty($missingItems) && !$isClosed;
$errors = [];

// Handle Final Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canSubmit) {
        $errors[] = 'You cannot submit the application until all required fields and documents are provided.';
    } elseif (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token. Please try again.';
    } elseif (!isset($_POST['confirm_declaration'])) {
        $errors[] = 'You must confirm the declaration statement before submitting.';
    } else {
        try {
            $pdo->beginTransaction();

            // Transition application status to submitted
            $submitStmt = $pdo->prepare("
                UPDATE applications SET
                    status = 'submitted',
                    submitted_at = NOW(),
                    updated_at = NOW()
                WHERE id = ? AND applicant_id = ?
            ");
            $submitStmt->execute([$application['id'], $applicant['id']]);

            // Link documents to this application
            $linkDocStmt = $pdo->prepare("
                UPDATE documents SET application_id = ?
                WHERE applicant_id = ? AND (application_id IS NULL OR application_id = ?)
            ");
            $linkDocStmt->execute([$application['id'], $applicant['id'], $application['id']]);

            // Create notification for applicant
            createNotification(
                $pdo,
                $userId,
                'Application Submitted Successfully',
                "Your bursary application ({$application['application_number']}) for '{$application['program_name']}' has been submitted. It will be reviewed by the bursary committee."
            );

            $pdo->commit();

            setFlash('success', 'Congratulations! Your bursary application has been submitted successfully.');
            header('Location: status.php');
            exit;

        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Failed to submit application: ' . $e->getMessage();
        }
    }
}

$wizardSteps = getApplicantStepsStatus($pdo, $applicant['id'], $applicant);

$pageTitle = 'Review & Final Submit';
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
            <span class="topbar-title">Review & Submit Application</span>
        </div>
        <div class="topbar-user">
            <span class="text-muted d-none d-md-inline">Applicant:</span>
            <strong><?php echo e($applicant['first_name'] . ' ' . $applicant['last_name']); ?></strong>
        </div>
    </header>

    <div class="content-body">
        <div class="container-fluid p-0" style="max-width: 950px;">
            <?php echo renderFlash(); ?>
            <?php echo renderApplicantWizardHtml('review', $wizardSteps); ?>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <h6 class="alert-heading fw-bold mb-1">Submission Error:</h6>
                    <ul class="mb-0 ps-3">
                        <?php foreach ($errors as $err): ?>
                            <li><?php echo e($err); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if (!empty($missingItems)): ?>
                <div class="alert alert-warning mb-4" role="alert">
                    <h6 class="alert-heading fw-bold mb-2"><i class="bi bi-exclamation-triangle-fill me-2"></i>Incomplete Application Checklist</h6>
                    <p class="small mb-2">Please complete the following mandatory items before you can submit your bursary application:</p>
                    <ul class="mb-0 ps-3 small">
                        <?php foreach ($missingItems as $item): ?>
                            <li class="mb-1"><?php echo $item; ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="card-custom">
                <div class="card-header-custom">
                    <div>
                        <h5 class="fw-bold mb-1"><i class="bi bi-check2-circle text-success me-2"></i>Application Summary Review</h5>
                        <p class="text-muted small mb-0">Carefully review your information before final submission. Once submitted, editing is locked.</p>
                    </div>
                    <span class="badge bg-secondary">Application Ref: <?php echo e($application['application_number']); ?></span>
                </div>

                <div class="card-body-custom">
                    <!-- SECTION 1: PERSONAL INFO -->
                    <div class="d-flex justify-content-between align-items-center mb-2 pb-1 border-bottom">
                        <h6 class="fw-bold text-success mb-0"><i class="bi bi-person me-1"></i> 1. Personal Details</h6>
                        <a href="profile.php" class="btn btn-sm btn-outline-secondary py-0">Edit</a>
                    </div>
                    <div class="row g-2 mb-4 small">
                        <div class="col-md-4"><strong>Full Name:</strong> <?php echo e($applicant['first_name'] . ' ' . $applicant['middle_name'] . ' ' . $applicant['last_name']); ?></div>
                        <div class="col-md-4"><strong>National ID / Cert:</strong> <?php echo e($applicant['id_number']); ?></div>
                        <div class="col-md-4"><strong>Gender / DOB:</strong> <?php echo e($applicant['gender']); ?> &bull; <?php echo e($applicant['date_of_birth']); ?></div>
                        <div class="col-md-4"><strong>Phone:</strong> <?php echo e($applicant['phone']); ?></div>
                        <div class="col-md-4"><strong>Email:</strong> <?php echo e($applicant['email']); ?></div>
                        <div class="col-md-4"><strong>County / Ward:</strong> <?php echo e($applicant['county'] ?? 'N/A'); ?> / <?php echo e($applicant['ward'] ?? 'N/A'); ?></div>
                    </div>

                    <!-- SECTION 2: EDUCATION -->
                    <div class="d-flex justify-content-between align-items-center mb-2 pb-1 border-bottom">
                        <h6 class="fw-bold text-success mb-0"><i class="bi bi-book me-1"></i> 2. Academic Information</h6>
                        <a href="education.php" class="btn btn-sm btn-outline-secondary py-0">Edit</a>
                    </div>
                    <?php if ($education): ?>
                        <div class="row g-2 mb-4 small">
                            <div class="col-md-6"><strong>Institution:</strong> <?php echo e($education['institution_name']); ?> (<?php echo e(ucwords(str_replace('_', ' ', $education['education_level']))); ?>)</div>
                            <div class="col-md-6"><strong>Course / Grade:</strong> <?php echo e($education['course_programme'] ?? $education['class_grade']); ?></div>
                            <div class="col-md-6"><strong>Adm / Reg Number:</strong> <?php echo e($education['registration_number'] ?? $education['admission_number']); ?></div>
                            <div class="col-md-6"><strong>Academic Year / Stage:</strong> <?php echo e($education['academic_year']); ?> &bull; <?php echo e($education['year_of_study'] ?? $education['class_grade']); ?></div>
                        </div>
                    <?php else: ?>
                        <p class="text-danger small mb-4">No education information recorded.</p>
                    <?php endif; ?>

                    <!-- SECTION 3: GUARDIAN -->
                    <div class="d-flex justify-content-between align-items-center mb-2 pb-1 border-bottom">
                        <h6 class="fw-bold text-success mb-0"><i class="bi bi-people me-1"></i> 3. Parent / Guardian Details</h6>
                        <a href="guardian.php" class="btn btn-sm btn-outline-secondary py-0">Edit</a>
                    </div>
                    <?php if ($guardian): ?>
                        <div class="row g-2 mb-4 small">
                            <div class="col-md-4"><strong>Name:</strong> <?php echo e($guardian['first_name'] . ' ' . $guardian['last_name']); ?></div>
                            <div class="col-md-4"><strong>Relationship:</strong> <?php echo e($guardian['relationship']); ?></div>
                            <div class="col-md-4"><strong>Phone:</strong> <?php echo e($guardian['phone']); ?></div>
                            <div class="col-md-4"><strong>ID Number:</strong> <?php echo e($guardian['id_number'] ?: 'N/A'); ?></div>
                            <div class="col-md-4"><strong>Occupation:</strong> <?php echo e($guardian['occupation'] ?: 'N/A'); ?></div>
                        </div>
                    <?php else: ?>
                        <p class="text-muted small mb-4">No guardian information recorded.</p>
                    <?php endif; ?>

                    <!-- SECTION 4: DOCUMENTS -->
                    <div class="d-flex justify-content-between align-items-center mb-2 pb-1 border-bottom">
                        <h6 class="fw-bold text-success mb-0"><i class="bi bi-paperclip me-1"></i> 4. Supporting Documents</h6>
                        <a href="documents.php" class="btn btn-sm btn-outline-secondary py-0">Edit</a>
                    </div>
                    <div class="row g-2 mb-4 small">
                        <?php
                        $docNames = [
                            'passport_photo'  => 'Passport Photo',
                            'identification'  => 'National ID / Birth Cert',
                            'fee_structure'   => 'Fee Structure',
                            'admission_proof' => 'Admission Letter / Student ID'
                        ];
                        foreach ($docNames as $dKey => $dTitle):
                            $hasDoc = isset($docs[$dKey]);
                        ?>
                            <div class="col-md-6 d-flex align-items-center gap-2">
                                <i class="bi <?php echo $hasDoc ? 'bi-check-circle-fill text-success' : 'bi-x-circle-fill text-danger'; ?>"></i>
                                <span><strong><?php echo $dTitle; ?>:</strong> <?php echo $hasDoc ? e($docs[$dKey]['original_filename']) : '<span class="text-danger">Not Uploaded</span>'; ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- SECTION 5: BURSARY APPLICATION -->
                    <div class="d-flex justify-content-between align-items-center mb-2 pb-1 border-bottom">
                        <h6 class="fw-bold text-success mb-0"><i class="bi bi-cash-stack me-1"></i> 5. Bursary Request Details</h6>
                        <a href="application.php" class="btn btn-sm btn-outline-secondary py-0">Edit</a>
                    </div>
                    <div class="row g-2 mb-4 small">
                        <div class="col-md-6"><strong>Program Name:</strong> <?php echo e($application['program_name'] ?? 'General Bursary'); ?></div>
                        <div class="col-md-6"><strong>Requested Amount:</strong> <span class="fw-bold text-success"><?php echo formatMoney((float)$application['requested_amount']); ?></span></div>
                        <div class="col-12 mt-2">
                            <strong>Financial Need Statement:</strong>
                            <p class="text-muted mb-2"><?php echo nl2br(e($application['financial_need'])); ?></p>
                        </div>
                        <div class="col-12">
                            <strong>Reason for Application:</strong>
                            <p class="text-muted mb-0"><?php echo nl2br(e($application['reason_for_application'])); ?></p>
                        </div>
                    </div>

                    <!-- FINAL SUBMISSION FORM -->
                    <form action="review.php" method="POST" class="mt-4 pt-3 border-top">
                        <?php echo csrfInput(); ?>

                        <div class="form-check p-3 bg-light rounded border mb-4">
                            <input class="form-check-input ms-0 me-2" type="checkbox" name="confirm_declaration" id="confirm_declaration" value="1" required <?php echo !$canSubmit ? 'disabled' : ''; ?>>
                            <label class="form-check-label fw-semibold small" for="confirm_declaration">
                                I hereby declare that all information and supporting documents provided in this application are genuine, true, and correct. I understand that submitting false or misleading information will result in immediate disqualification and potential legal action.
                            </label>
                        </div>

                        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-center gap-3">
                            <a href="documents.php" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-left me-1"></i> Back to Documents
                            </a>
                            <button type="submit" class="btn btn-green px-4 py-2 fs-6 <?php echo $canSubmit ? 'pulse-btn' : ''; ?>" <?php echo !$canSubmit ? 'disabled' : ''; ?> data-confirm="Are you sure you want to submit your bursary application? Once submitted, you cannot edit your details.">
                                <i class="bi bi-send-check-fill me-2"></i> Submit Application Now
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

