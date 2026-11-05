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

// Check if applicant is minor (age < 18)
$isMinor = false;
if (!empty($applicant['date_of_birth'])) {
    $dob = new DateTime($applicant['date_of_birth']);
    $today = new DateTime();
    $age = $today->diff($dob)->y;
    if ($age < 18) {
        $isMinor = true;
    }
}

// Fetch existing guardian record for this applicant
$stmt = $pdo->prepare("SELECT * FROM guardians WHERE applicant_id = ? ORDER BY id DESC LIMIT 1");
$stmt->execute([$applicant['id']]);
$guardian = $stmt->fetch() ?: [];

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token. Please refresh and try again.';
    } else {
        $firstName    = trim($_POST['first_name'] ?? '');
        $middleName   = trim($_POST['middle_name'] ?? '');
        $lastName     = trim($_POST['last_name'] ?? '');
        $relationship = trim($_POST['relationship'] ?? '');
        $otherRel     = trim($_POST['other_relationship'] ?? '');
        $phone        = trim($_POST['phone'] ?? '');
        $altPhone     = trim($_POST['alternative_phone'] ?? '');
        $idNumber     = trim($_POST['id_number'] ?? '');
        $email        = trim($_POST['email'] ?? '');
        $occupation   = trim($_POST['occupation'] ?? '');
        $employer     = trim($_POST['employer_business'] ?? '');
        $county       = trim($_POST['county'] ?? '');
        $subCounty    = trim($_POST['sub_county'] ?? '');
        $ward         = trim($_POST['ward'] ?? '');
        $address      = trim($_POST['physical_address'] ?? '');

        // Validation
        if (empty($firstName)) {
            $errors[] = 'Guardian First Name is required.';
        }
        if (empty($lastName)) {
            $errors[] = 'Guardian Last Name is required.';
        }
        if (empty($relationship)) {
            $errors[] = 'Relationship to applicant is required.';
        }
        if ($relationship === 'Other' && empty($otherRel)) {
            $errors[] = 'Please specify the "Other" relationship.';
        }
        if (empty($phone)) {
            $errors[] = 'Guardian Phone number is required.';
        }
        if ($isMinor && empty($idNumber)) {
            $errors[] = 'National ID is required for parent/guardian of a minor applicant.';
        }
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        }

        if (empty($errors)) {
            try {
                if (!empty($guardian['id'])) {
                    // Update existing
                    $updateStmt = $pdo->prepare("
                        UPDATE guardians SET
                            first_name = ?,
                            middle_name = ?,
                            last_name = ?,
                            relationship = ?,
                            other_relationship = ?,
                            phone = ?,
                            alternative_phone = ?,
                            id_number = ?,
                            email = ?,
                            occupation = ?,
                            employer_business = ?,
                            county = ?,
                            sub_county = ?,
                            ward = ?,
                            physical_address = ?,
                            updated_at = NOW()
                        WHERE id = ? AND applicant_id = ?
                    ");

                    $updateStmt->execute([
                        $firstName,
                        $middleName ?: null,
                        $lastName,
                        $relationship,
                        ($relationship === 'Other') ? $otherRel : null,
                        $phone,
                        $altPhone ?: null,
                        $idNumber ?: null,
                        $email ?: null,
                        $occupation ?: null,
                        $employer ?: null,
                        $county ?: null,
                        $subCounty ?: null,
                        $ward ?: null,
                        $address ?: null,
                        $guardian['id'],
                        $applicant['id']
                    ]);
                } else {
                    // Insert new
                    $insertStmt = $pdo->prepare("
                        INSERT INTO guardians (
                            applicant_id, first_name, middle_name, last_name, relationship,
                            other_relationship, phone, alternative_phone, id_number, email,
                            occupation, employer_business, county, sub_county, ward, physical_address,
                            created_at
                        ) VALUES (
                            ?, ?, ?, ?, ?,
                            ?, ?, ?, ?, ?,
                            ?, ?, ?, ?, ?, ?,
                            NOW()
                        )
                    ");

                    $insertStmt->execute([
                        $applicant['id'],
                        $firstName,
                        $middleName ?: null,
                        $lastName,
                        $relationship,
                        ($relationship === 'Other') ? $otherRel : null,
                        $phone,
                        $altPhone ?: null,
                        $idNumber ?: null,
                        $email ?: null,
                        $occupation ?: null,
                        $employer ?: null,
                        $county ?: null,
                        $subCounty ?: null,
                        $ward ?: null,
                        $address ?: null
                    ]);
                }

                $actionNext = $_POST['action_next'] ?? 'continue';
                if ($actionNext === 'stay') {
                    setFlash('success', 'Parent / Guardian details saved successfully!');
                    header('Location: guardian.php');
                    exit;
                } else {
                    setFlash('success', 'Parent / Guardian details saved successfully! Proceeding to Step 4: Bursary Application Request.');
                    header('Location: application.php');
                    exit;
                }

            } catch (PDOException $e) {
                $errors[] = 'Database error: ' . $e->getMessage();
            }
        }
    }
}

$wizardSteps = getApplicantStepsStatus($pdo, $applicant['id'], $applicant);

$pageTitle = 'Parent / Guardian Information';
$activeNav = 'guardian';
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
            <span class="topbar-title">Parent / Guardian Information</span>
        </div>
        <div class="topbar-user">
            <span class="text-muted d-none d-md-inline">Logged in as:</span>
            <strong><?php echo e($applicant['first_name'] . ' ' . $applicant['last_name']); ?></strong>
        </div>
    </header>

    <div class="content-body">
        <div class="container-fluid p-0" style="max-width: 950px;">
            <?php echo renderFlash(); ?>
            <?php echo renderApplicantWizardHtml('guardian', $wizardSteps); ?>

            <?php if ($isMinor): ?>
                <div class="alert alert-info d-flex align-items-center mb-4" role="alert">
                    <i class="bi bi-info-circle-fill fs-4 me-3"></i>
                    <div>
                        <strong>Notice:</strong> As an applicant under 18 years of age, parent or legal guardian details and identification are strictly required.
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
                        <h5 class="fw-bold mb-1"><i class="bi bi-people-fill text-success me-2"></i>Guardian / Parent Details</h5>
                        <p class="text-muted small mb-0">Provide primary contact and background information of your parent or legal guardian.</p>
                    </div>
                </div>

                <div class="card-body-custom">
                    <form action="guardian.php" method="POST">
                        <?php echo csrfInput(); ?>

                        <h6 class="fw-bold text-success border-bottom pb-2 mb-3">1. Guardian Identity</h6>
                        <div class="row g-3 mb-4">
                            <div class="col-md-4">
                                <label class="form-label">First Name <span class="text-danger">*</span></label>
                                <input type="text" name="first_name" class="form-control" value="<?php echo e($guardian['first_name'] ?? ''); ?>" required placeholder="e.g. Mary">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Middle Name</label>
                                <input type="text" name="middle_name" class="form-control" value="<?php echo e($guardian['middle_name'] ?? ''); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Last Name <span class="text-danger">*</span></label>
                                <input type="text" name="last_name" class="form-control" value="<?php echo e($guardian['last_name'] ?? ''); ?>" required placeholder="e.g. Wanjiku">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Relationship <span class="text-danger">*</span></label>
                                <select name="relationship" id="relationship" class="form-select" required>
                                    <option value="">Select Relationship</option>
                                    <option value="Father" <?php echo (($guardian['relationship'] ?? '') === 'Father') ? 'selected' : ''; ?>>Father</option>
                                    <option value="Mother" <?php echo (($guardian['relationship'] ?? '') === 'Mother') ? 'selected' : ''; ?>>Mother</option>
                                    <option value="Guardian" <?php echo (($guardian['relationship'] ?? '') === 'Guardian') ? 'selected' : ''; ?>>Legal Guardian</option>
                                    <option value="Sibling" <?php echo (($guardian['relationship'] ?? '') === 'Sibling') ? 'selected' : ''; ?>>Sibling</option>
                                    <option value="Other" <?php echo (($guardian['relationship'] ?? '') === 'Other') ? 'selected' : ''; ?>>Other</option>
                                </select>
                            </div>

                            <div class="col-md-6" id="other-relationship-container" style="display: <?php echo (($guardian['relationship'] ?? '') === 'Other') ? 'block' : 'none'; ?>;">
                                <label class="form-label">Specify Other Relationship <span class="text-danger">*</span></label>
                                <input type="text" name="other_relationship" class="form-control" value="<?php echo e($guardian['other_relationship'] ?? ''); ?>" placeholder="e.g. Uncle / Sponsor">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">National ID Number <?php echo $isMinor ? '<span class="text-danger">*</span>' : ''; ?></label>
                                <input type="text" name="id_number" class="form-control" value="<?php echo e($guardian['id_number'] ?? ''); ?>" placeholder="Guardian ID Number">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Email Address</label>
                                <input type="email" name="email" class="form-control" value="<?php echo e($guardian['email'] ?? ''); ?>" placeholder="guardian@example.com">
                            </div>
                        </div>

                        <h6 class="fw-bold text-success border-bottom pb-2 mb-3">2. Contact & Employment</h6>
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label class="form-label">Primary Phone <span class="text-danger">*</span></label>
                                <input type="tel" name="phone" class="form-control" value="<?php echo e($guardian['phone'] ?? ''); ?>" required placeholder="07XXXXXXXX">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Alternative Phone</label>
                                <input type="tel" name="alternative_phone" class="form-control" value="<?php echo e($guardian['alternative_phone'] ?? ''); ?>" placeholder="07XXXXXXXX">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Occupation / Source of Livelihood</label>
                                <input type="text" name="occupation" class="form-control" value="<?php echo e($guardian['occupation'] ?? ''); ?>" placeholder="e.g. Farmer / Teacher / Business Owner / Retired">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Employer / Business Name</label>
                                <input type="text" name="employer_business" class="form-control" value="<?php echo e($guardian['employer_business'] ?? ''); ?>" placeholder="e.g. Self Employed / Ministry of Education">
                            </div>
                        </div>

                        <h6 class="fw-bold text-success border-bottom pb-2 mb-3">3. Residential Address</h6>
                        <div class="row g-3 mb-4">
                            <div class="col-md-4">
                                <label class="form-label">County</label>
                                <input type="text" name="county" class="form-control" value="<?php echo e($guardian['county'] ?? ''); ?>" placeholder="County">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Sub-County</label>
                                <input type="text" name="sub_county" class="form-control" value="<?php echo e($guardian['sub_county'] ?? ''); ?>" placeholder="Sub-County">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Ward</label>
                                <input type="text" name="ward" class="form-control" value="<?php echo e($guardian['ward'] ?? ''); ?>" placeholder="Ward">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Physical Address</label>
                                <textarea name="physical_address" rows="2" class="form-control" placeholder="Village / Plot / House number"><?php echo e($guardian['physical_address'] ?? ''); ?></textarea>
                            </div>
                        </div>

                        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-center gap-3 pt-3 border-top">
                            <a href="education.php" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-left me-1"></i> Back to Education
                            </a>
                            <div class="d-flex align-items-center gap-2">
                                <?php if (!$isMinor): ?>
                                    <a href="application.php" class="btn btn-link text-muted text-decoration-none me-1">
                                        Skip for Now <i class="bi bi-chevron-right"></i>
                                    </a>
                                <?php endif; ?>
                                <button type="submit" name="action_next" value="stay" class="btn btn-outline-secondary">
                                    <i class="bi bi-save me-1"></i> Save Draft
                                </button>
                                <button type="submit" name="action_next" value="continue" class="btn btn-green px-4 pulse-btn">
                                    Save & Continue to Bursary Request <i class="bi bi-arrow-right ms-1"></i>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

