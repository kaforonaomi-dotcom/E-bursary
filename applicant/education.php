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

// Fetch existing education record for this applicant
$stmt = $pdo->prepare("SELECT * FROM education WHERE applicant_id = ? ORDER BY id DESC LIMIT 1");
$stmt->execute([$applicant['id']]);
$edu = $stmt->fetch() ?: [];

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token. Please refresh and try again.';
    } else {
        $eduLevel = trim($_POST['education_level'] ?? '');
        if (!in_array($eduLevel, ['primary', 'high_school', 'university'])) {
            $errors[] = 'Please select a valid education level.';
        }

        $institutionName = trim($_POST['institution_name'] ?? '');
        if (empty($institutionName)) {
            $errors[] = 'School or Institution name is required.';
        }

        $county          = trim($_POST['county'] ?? '');
        $subCounty       = trim($_POST['sub_county'] ?? '');
        $ward            = trim($_POST['ward'] ?? '');
        $academicYear    = trim($_POST['academic_year'] ?? '');
        $schoolContact   = trim($_POST['school_contact'] ?? '');

        // Specific fields
        $institutionType = trim($_POST['institution_type'] ?? '');
        $campus          = trim($_POST['campus'] ?? '');
        $courseProgramme = trim($_POST['course_programme'] ?? '');
        $department      = trim($_POST['department'] ?? '');
        $yearOfStudy     = trim($_POST['year_of_study'] ?? '');
        $semester        = trim($_POST['semester'] ?? '');
        $studyMode       = trim($_POST['study_mode'] ?? '');
        $gradYear        = trim($_POST['expected_graduation_year'] ?? '');

        $classGrade   = '';
        $admissionNo  = '';
        $regNo        = '';
        $boardingType = '';

        // Validation and field assignment by level
        if ($eduLevel === 'primary') {
            $classGrade  = trim($_POST['primary_class_grade'] ?? $_POST['class_grade'] ?? '');
            $admissionNo = trim($_POST['primary_admission_number'] ?? $_POST['admission_number'] ?? '');

            if (empty($classGrade)) $errors[] = 'Class / Grade is required for Primary school.';
            if (empty($admissionNo)) $errors[] = 'Admission / Student Number is required.';
        } elseif ($eduLevel === 'high_school') {
            $classGrade   = trim($_POST['high_school_class_grade'] ?? $_POST['class_grade'] ?? '');
            $admissionNo  = trim($_POST['high_school_admission_number'] ?? $_POST['admission_number'] ?? '');
            $boardingType = trim($_POST['boarding_type'] ?? '');

            if (empty($classGrade)) $errors[] = 'Form / Grade is required for High school.';
            if (empty($admissionNo)) $errors[] = 'Admission Number is required.';
            if (empty($boardingType) || !in_array($boardingType, ['boarding', 'day'])) {
                $errors[] = 'Please indicate Boarding or Day.';
            }
        } elseif ($eduLevel === 'university') {
            $regNo       = trim($_POST['registration_number'] ?? '');
            $admissionNo = $regNo;

            if (empty($courseProgramme)) $errors[] = 'Course / Programme is required.';
            if (empty($regNo)) $errors[] = 'Registration / Admission Number is required.';
            if (empty($yearOfStudy)) $errors[] = 'Year of study is required.';
            if (!empty($studyMode) && !in_array($studyMode, ['full_time', 'part_time', 'distance'])) {
                $errors[] = 'Invalid study mode selected.';
            }
        }

        if (empty($errors)) {
            try {
                if (!empty($edu['id'])) {
                    // Update existing
                    $updateStmt = $pdo->prepare("
                        UPDATE education SET
                            education_level = ?,
                            institution_name = ?,
                            institution_type = ?,
                            campus = ?,
                            county = ?,
                            sub_county = ?,
                            ward = ?,
                            course_programme = ?,
                            department = ?,
                            class_grade = ?,
                            admission_number = ?,
                            registration_number = ?,
                            year_of_study = ?,
                            semester = ?,
                            academic_year = ?,
                            study_mode = ?,
                            boarding_type = ?,
                            expected_graduation_year = ?,
                            school_contact = ?,
                            updated_at = NOW()
                        WHERE id = ? AND applicant_id = ?
                    ");

                    $updateStmt->execute([
                        $eduLevel,
                        $institutionName,
                        $institutionType ?: null,
                        $campus ?: null,
                        $county ?: null,
                        $subCounty ?: null,
                        $ward ?: null,
                        $courseProgramme ?: null,
                        $department ?: null,
                        $classGrade ?: null,
                        $admissionNo ?: null,
                        $regNo ?: null,
                        $yearOfStudy ?: null,
                        $semester ?: null,
                        $academicYear ?: null,
                        $studyMode ?: null,
                        $boardingType ?: null,
                        !empty($gradYear) ? (int)$gradYear : null,
                        $schoolContact ?: null,
                        $edu['id'],
                        $applicant['id']
                    ]);
                } else {
                    // Insert new
                    $insertStmt = $pdo->prepare("
                        INSERT INTO education (
                            applicant_id, education_level, institution_name, institution_type,
                            campus, county, sub_county, ward, course_programme, department,
                            class_grade, admission_number, registration_number, year_of_study,
                            semester, academic_year, study_mode, boarding_type,
                            expected_graduation_year, school_contact, created_at
                        ) VALUES (
                            ?, ?, ?, ?,
                            ?, ?, ?, ?, ?, ?,
                            ?, ?, ?, ?,
                            ?, ?, ?, ?,
                            ?, ?, NOW()
                        )
                    ");

                    $insertStmt->execute([
                        $applicant['id'],
                        $eduLevel,
                        $institutionName,
                        $institutionType ?: null,
                        $campus ?: null,
                        $county ?: null,
                        $subCounty ?: null,
                        $ward ?: null,
                        $courseProgramme ?: null,
                        $department ?: null,
                        $classGrade ?: null,
                        $admissionNo ?: null,
                        $regNo ?: null,
                        $yearOfStudy ?: null,
                        $semester ?: null,
                        $academicYear ?: null,
                        $studyMode ?: null,
                        $boardingType ?: null,
                        !empty($gradYear) ? (int)$gradYear : null,
                        $schoolContact ?: null
                    ]);
                }

                $actionNext = $_POST['action_next'] ?? 'continue';
                if ($actionNext === 'stay') {
                    setFlash('success', 'Education details saved successfully!');
                    header('Location: education.php');
                    exit;
                } else {
                    setFlash('success', 'Education details saved successfully! Proceeding to Step 3: Parent / Guardian Details.');
                    header('Location: guardian.php');
                    exit;
                }

            } catch (PDOException $e) {
                $errors[] = 'Database error: ' . $e->getMessage();
            }
        }
    }
}

$selectedLevel = $_POST['education_level'] ?? ($edu['education_level'] ?? 'university');

$wizardSteps = getApplicantStepsStatus($pdo, $applicant['id'], $applicant);

$pageTitle = 'Education Information';
$activeNav = 'education';
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
            <span class="topbar-title">Education Information</span>
        </div>
        <div class="topbar-user">
            <span class="text-muted d-none d-md-inline">Logged in as:</span>
            <strong><?php echo e($applicant['first_name'] . ' ' . $applicant['last_name']); ?></strong>
        </div>
    </header>

    <div class="content-body">
        <div class="container-fluid p-0" style="max-width: 950px;">
            <?php echo renderFlash(); ?>
            <?php echo renderApplicantWizardHtml('education', $wizardSteps); ?>

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
                        <h5 class="fw-bold mb-1"><i class="bi bi-mortarboard-fill text-success me-2"></i>Academic Profile</h5>
                        <p class="text-muted small mb-0">Select your education level to display the relevant institution and study fields.</p>
                    </div>
                </div>

                <div class="card-body-custom">
                    <form action="education.php" method="POST">
                        <?php echo csrfInput(); ?>

                        <div class="mb-4">
                            <label class="form-label fs-6 fw-bold">Select Education Level <span class="text-danger">*</span></label>
                            <select name="education_level" id="education_level" class="form-select form-select-lg" required>
                                <option value="primary" <?php echo ($selectedLevel === 'primary') ? 'selected' : ''; ?>>Primary School</option>
                                <option value="high_school" <?php echo ($selectedLevel === 'high_school') ? 'selected' : ''; ?>>High School / Secondary</option>
                                <option value="university" <?php echo ($selectedLevel === 'university') ? 'selected' : ''; ?>>University / College / TVET</option>
                            </select>
                        </div>

                        <!-- COMMON FIELDS -->
                        <h6 class="fw-bold text-success border-bottom pb-2 mb-3">Institution & Location Details</h6>
                        <div class="row g-3 mb-4">
                            <div class="col-md-8">
                                <label class="form-label">School / Institution Name <span class="text-danger">*</span></label>
                                <input type="text" name="institution_name" class="form-control" value="<?php echo e($edu['institution_name'] ?? ''); ?>" required placeholder="e.g. University of Nairobi or Alliance High School">
                            </div>

                            <div class="col-md-4">
                                <label class="form-label">Academic Year</label>
                                <input type="text" name="academic_year" class="form-control" value="<?php echo e($edu['academic_year'] ?? date('Y')); ?>" placeholder="e.g. 2025/2026">
                            </div>

                            <div class="col-md-4">
                                <label class="form-label">County</label>
                                <input type="text" name="county" class="form-control" value="<?php echo e($edu['county'] ?? ''); ?>" placeholder="Institution County">
                            </div>

                            <div class="col-md-4">
                                <label class="form-label">Sub-County</label>
                                <input type="text" name="sub_county" class="form-control" value="<?php echo e($edu['sub_county'] ?? ''); ?>" placeholder="Institution Sub-County">
                            </div>

                            <div class="col-md-4">
                                <label class="form-label">Ward</label>
                                <input type="text" name="ward" class="form-control" value="<?php echo e($edu['ward'] ?? ''); ?>" placeholder="Institution Ward">
                            </div>

                            <div class="col-md-12">
                                <label class="form-label">School Contact (Phone / Official Email)</label>
                                <input type="text" name="school_contact" class="form-control" value="<?php echo e($edu['school_contact'] ?? ''); ?>" placeholder="e.g. info@school.ac.ke / 07XXXXXXXX">
                            </div>
                        </div>

                        <!-- PRIMARY SCHOOL FIELDS -->
                        <div id="fields-primary" style="display: <?php echo ($selectedLevel === 'primary') ? 'block' : 'none'; ?>;">
                            <h6 class="fw-bold text-success border-bottom pb-2 mb-3">Primary School Academic Details</h6>
                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <label class="form-label">Class / Grade <span class="text-danger">*</span></label>
                                    <input type="text" name="primary_class_grade" class="form-control" value="<?php echo e($edu['class_grade'] ?? ''); ?>" placeholder="e.g. Grade 7 / Class 8">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Admission / Student Number <span class="text-danger">*</span></label>
                                    <input type="text" name="primary_admission_number" class="form-control" value="<?php echo e($edu['admission_number'] ?? ''); ?>" placeholder="e.g. 4589">
                                </div>
                            </div>
                        </div>

                        <!-- HIGH SCHOOL FIELDS -->
                        <div id="fields-high_school" style="display: <?php echo ($selectedLevel === 'high_school') ? 'block' : 'none'; ?>;">
                            <h6 class="fw-bold text-success border-bottom pb-2 mb-3">High School Academic Details</h6>
                            <div class="row g-3 mb-4">
                                <div class="col-md-4">
                                    <label class="form-label">Form / Grade <span class="text-danger">*</span></label>
                                    <select name="high_school_class_grade" class="form-select">
                                        <option value="">Select Form</option>
                                        <option value="Form 1" <?php echo (($edu['class_grade'] ?? '') === 'Form 1') ? 'selected' : ''; ?>>Form 1</option>
                                        <option value="Form 2" <?php echo (($edu['class_grade'] ?? '') === 'Form 2') ? 'selected' : ''; ?>>Form 2</option>
                                        <option value="Form 3" <?php echo (($edu['class_grade'] ?? '') === 'Form 3') ? 'selected' : ''; ?>>Form 3</option>
                                        <option value="Form 4" <?php echo (($edu['class_grade'] ?? '') === 'Form 4') ? 'selected' : ''; ?>>Form 4</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Admission Number <span class="text-danger">*</span></label>
                                    <input type="text" name="high_school_admission_number" class="form-control" value="<?php echo e($edu['admission_number'] ?? ''); ?>" placeholder="e.g. ADM-1029">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Boarding / Day <span class="text-danger">*</span></label>
                                    <select name="boarding_type" class="form-select">
                                        <option value="">Select Type</option>
                                        <option value="boarding" <?php echo (($edu['boarding_type'] ?? '') === 'boarding') ? 'selected' : ''; ?>>Boarding</option>
                                        <option value="day" <?php echo (($edu['boarding_type'] ?? '') === 'day') ? 'selected' : ''; ?>>Day</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- UNIVERSITY / COLLEGE FIELDS -->
                        <div id="fields-university" style="display: <?php echo ($selectedLevel === 'university') ? 'block' : 'none'; ?>;">
                            <h6 class="fw-bold text-success border-bottom pb-2 mb-3">Higher Education Academic Details</h6>
                            <div class="row g-3 mb-4">
                                <div class="col-md-4">
                                    <label class="form-label">Institution Type</label>
                                    <select name="institution_type" class="form-select">
                                        <option value="University" <?php echo (($edu['institution_type'] ?? '') === 'University') ? 'selected' : ''; ?>>University</option>
                                        <option value="College" <?php echo (($edu['institution_type'] ?? '') === 'College') ? 'selected' : ''; ?>>College</option>
                                        <option value="TVET" <?php echo (($edu['institution_type'] ?? '') === 'TVET') ? 'selected' : ''; ?>>TVET / Polytechnic</option>
                                        <option value="National Polytechnic" <?php echo (($edu['institution_type'] ?? '') === 'National Polytechnic') ? 'selected' : ''; ?>>National Polytechnic</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Campus</label>
                                    <input type="text" name="campus" class="form-control" value="<?php echo e($edu['campus'] ?? ''); ?>" placeholder="e.g. Main Campus">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Registration / Student No <span class="text-danger">*</span></label>
                                    <input type="text" name="registration_number" class="form-control" value="<?php echo e($edu['registration_number'] ?? ($edu['admission_number'] ?? '')); ?>" placeholder="e.g. P15/28491/2023">
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label">Course / Programme <span class="text-danger">*</span></label>
                                    <input type="text" name="course_programme" class="form-control" value="<?php echo e($edu['course_programme'] ?? ''); ?>" placeholder="e.g. Bachelor of Science in Computer Science">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Department / Faculty</label>
                                    <input type="text" name="department" class="form-control" value="<?php echo e($edu['department'] ?? ''); ?>" placeholder="e.g. School of Computing & Informatics">
                                </div>

                                <div class="col-md-3">
                                    <label class="form-label">Year of Study <span class="text-danger">*</span></label>
                                    <select name="year_of_study" class="form-select">
                                        <option value="Year 1" <?php echo (($edu['year_of_study'] ?? '') === 'Year 1') ? 'selected' : ''; ?>>Year 1</option>
                                        <option value="Year 2" <?php echo (($edu['year_of_study'] ?? '') === 'Year 2') ? 'selected' : ''; ?>>Year 2</option>
                                        <option value="Year 3" <?php echo (($edu['year_of_study'] ?? '') === 'Year 3') ? 'selected' : ''; ?>>Year 3</option>
                                        <option value="Year 4" <?php echo (($edu['year_of_study'] ?? '') === 'Year 4') ? 'selected' : ''; ?>>Year 4</option>
                                        <option value="Year 5+" <?php echo (($edu['year_of_study'] ?? '') === 'Year 5+') ? 'selected' : ''; ?>>Year 5+</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Semester / Term</label>
                                    <select name="semester" class="form-select">
                                        <option value="Semester 1" <?php echo (($edu['semester'] ?? '') === 'Semester 1') ? 'selected' : ''; ?>>Semester 1</option>
                                        <option value="Semester 2" <?php echo (($edu['semester'] ?? '') === 'Semester 2') ? 'selected' : ''; ?>>Semester 2</option>
                                        <option value="Trimester 3" <?php echo (($edu['semester'] ?? '') === 'Trimester 3') ? 'selected' : ''; ?>>Trimester 3</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Study Mode</label>
                                    <select name="study_mode" class="form-select">
                                        <option value="full_time" <?php echo (($edu['study_mode'] ?? '') === 'full_time') ? 'selected' : ''; ?>>Full Time</option>
                                        <option value="part_time" <?php echo (($edu['study_mode'] ?? '') === 'part_time') ? 'selected' : ''; ?>>Part Time</option>
                                        <option value="distance" <?php echo (($edu['study_mode'] ?? '') === 'distance') ? 'selected' : ''; ?>>Distance / E-Learning</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Graduation Year</label>
                                    <input type="number" min="2020" max="2035" name="expected_graduation_year" class="form-control" value="<?php echo e($edu['expected_graduation_year'] ?? (date('Y') + 2)); ?>">
                                </div>
                            </div>
                        </div>

                        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-center gap-3 pt-3 border-top">
                            <a href="profile.php" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-left me-1"></i> Back to Personal Info
                            </a>
                            <div class="d-flex gap-2">
                                <button type="submit" name="action_next" value="stay" class="btn btn-outline-secondary">
                                    <i class="bi bi-save me-1"></i> Save Draft
                                </button>
                                <button type="submit" name="action_next" value="continue" class="btn btn-green px-4 pulse-btn">
                                    Save & Continue to Guardian <i class="bi bi-arrow-right ms-1"></i>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

