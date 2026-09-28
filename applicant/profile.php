<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('applicant');

$userId = (int)$_SESSION['user_id'];
$applicant = getCurrentApplicant($pdo);

if (!$applicant) {
    // If somehow missing applicant record, create a basic record from users table
    $stmt = $pdo->prepare("SELECT email, username FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $u = $stmt->fetch();
    $insertStmt = $pdo->prepare("INSERT INTO applicants (user_id, first_name, last_name, email) VALUES (?, ?, '', ?)");
    $insertStmt->execute([$userId, $u['username'] ?? 'Applicant', $u['email'] ?? '']);
    $applicant = getCurrentApplicant($pdo);
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token. Please refresh and try again.';
    } else {
        $firstName    = trim($_POST['first_name'] ?? '');
        $middleName   = trim($_POST['middle_name'] ?? '');
        $lastName     = trim($_POST['last_name'] ?? '');
        $otherName    = trim($_POST['other_name'] ?? '');
        $idNumber     = trim($_POST['id_number'] ?? '');
        $dob          = trim($_POST['dob'] ?? '');
        $gender       = trim($_POST['gender'] ?? '');
        $phone        = trim($_POST['phone'] ?? '');
        $altPhone     = trim($_POST['alternative_phone'] ?? '');
        $email        = trim($_POST['email'] ?? '');
        $county       = trim($_POST['county'] ?? '');
        $subCounty    = trim($_POST['sub_county'] ?? '');
        $ward         = trim($_POST['ward'] ?? '');
        $address      = trim($_POST['physical_address'] ?? '');

        // Validation
        if (empty($firstName)) {
            $errors[] = 'First Name is required.';
        }
        if (empty($lastName)) {
            $errors[] = 'Last Name is required.';
        }
        if (empty($idNumber)) {
            $errors[] = 'National ID or Birth Certificate number is required.';
        }
        if (empty($dob)) {
            $errors[] = 'Date of birth is required.';
        }
        if (empty($gender) || !in_array($gender, ['Male', 'Female', 'Other'])) {
            $errors[] = 'Please select a valid gender.';
        }
        if (empty($phone)) {
            $errors[] = 'Phone number is required.';
        }
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'A valid email address is required.';
        }

        // Check if ID number belongs to another applicant
        if (!empty($idNumber)) {
            $checkIdStmt = $pdo->prepare("SELECT id FROM applicants WHERE id_number = ? AND id != ? LIMIT 1");
            $checkIdStmt->execute([$idNumber, $applicant['id']]);
            if ($checkIdStmt->fetch()) {
                $errors[] = 'This National ID / ID Number is already registered by another applicant.';
            }
        }

        if (empty($errors)) {
            try {
                $updateStmt = $pdo->prepare("
                    UPDATE applicants SET
                        first_name = ?,
                        middle_name = ?,
                        last_name = ?,
                        other_name = ?,
                        id_number = ?,
                        date_of_birth = ?,
                        gender = ?,
                        phone = ?,
                        alternative_phone = ?,
                        email = ?,
                        county = ?,
                        sub_county = ?,
                        ward = ?,
                        physical_address = ?,
                        updated_at = NOW()
                    WHERE id = ? AND user_id = ?
                ");

                $updateStmt->execute([
                    $firstName,
                    $middleName ?: null,
                    $lastName,
                    $otherName ?: null,
                    $idNumber,
                    $dob ?: null,
                    $gender,
                    $phone,
                    $altPhone ?: null,
                    $email,
                    $county ?: null,
                    $subCounty ?: null,
                    $ward ?: null,
                    $address ?: null,
                    $applicant['id'],
                    $userId
                ]);

                // Also update email in users table if changed
                $userEmailStmt = $pdo->prepare("UPDATE users SET email = ? WHERE id = ?");
                $userEmailStmt->execute([$email, $userId]);

                setFlash('success', 'Personal information updated successfully!');
                header('Location: profile.php');
                exit;

            } catch (PDOException $e) {
                $errors[] = 'Database update failed: ' . $e->getMessage();
            }
        }
    }
}

$pageTitle = 'Personal Information';
$activeNav = 'profile';
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
            <span class="topbar-title">Personal Information</span>
        </div>
        <div class="topbar-user">
            <span class="text-muted d-none d-md-inline">Logged in as:</span>
            <strong><?php echo e($applicant['first_name'] ? $applicant['first_name'] . ' ' . $applicant['last_name'] : $_SESSION['username']); ?></strong>
        </div>
    </header>

    <div class="content-body">
        <div class="container-fluid p-0" style="max-width: 950px;">
            <?php echo renderFlash(); ?>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <h6 class="alert-heading fw-bold mb-1">Please correct the following errors:</h6>
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
                        <h5 class="fw-bold mb-1"><i class="bi bi-person-lines-fill text-success me-2"></i>Applicant Profile</h5>
                        <p class="text-muted small mb-0">Ensure all details match your official identification documents.</p>
                    </div>
                </div>

                <div class="card-body-custom">
                    <form action="profile.php" method="POST">
                        <?php echo csrfInput(); ?>

                        <h6 class="fw-bold text-success border-bottom pb-2 mb-3">1. Identification Details</h6>
                        <div class="row g-3 mb-4">
                            <div class="col-md-3">
                                <label class="form-label">First Name <span class="text-danger">*</span></label>
                                <input type="text" name="first_name" class="form-control" value="<?php echo e($applicant['first_name']); ?>" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Middle Name</label>
                                <input type="text" name="middle_name" class="form-control" value="<?php echo e($applicant['middle_name']); ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Last Name <span class="text-danger">*</span></label>
                                <input type="text" name="last_name" class="form-control" value="<?php echo e($applicant['last_name']); ?>" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Other Name</label>
                                <input type="text" name="other_name" class="form-control" value="<?php echo e($applicant['other_name']); ?>">
                            </div>

                            <div class="col-md-4">
                                <label class="form-label">National ID / Birth Cert No <span class="text-danger">*</span></label>
                                <input type="text" name="id_number" class="form-control" value="<?php echo e($applicant['id_number']); ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Date of Birth <span class="text-danger">*</span></label>
                                <input type="date" name="dob" class="form-control" value="<?php echo e($applicant['date_of_birth']); ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Gender <span class="text-danger">*</span></label>
                                <select name="gender" class="form-select" required>
                                    <option value="">Select Gender</option>
                                    <option value="Male" <?php echo ($applicant['gender'] === 'Male') ? 'selected' : ''; ?>>Male</option>
                                    <option value="Female" <?php echo ($applicant['gender'] === 'Female') ? 'selected' : ''; ?>>Female</option>
                                    <option value="Other" <?php echo ($applicant['gender'] === 'Other') ? 'selected' : ''; ?>>Other</option>
                                </select>
                            </div>
                        </div>

                        <h6 class="fw-bold text-success border-bottom pb-2 mb-3">2. Contact Information</h6>
                        <div class="row g-3 mb-4">
                            <div class="col-md-4">
                                <label class="form-label">Primary Phone <span class="text-danger">*</span></label>
                                <input type="tel" name="phone" class="form-control" value="<?php echo e($applicant['phone']); ?>" required placeholder="07XXXXXXXX">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Alternative Phone</label>
                                <input type="tel" name="alternative_phone" class="form-control" value="<?php echo e($applicant['alternative_phone']); ?>" placeholder="07XXXXXXXX">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Email Address <span class="text-danger">*</span></label>
                                <input type="email" name="email" class="form-control" value="<?php echo e($applicant['email']); ?>" required>
                            </div>
                        </div>

                        <h6 class="fw-bold text-success border-bottom pb-2 mb-3">3. Location & Residence</h6>
                        <div class="row g-3 mb-4">
                            <div class="col-md-4">
                                <label class="form-label">County</label>
                                <input type="text" name="county" class="form-control" value="<?php echo e($applicant['county']); ?>" placeholder="e.g. Nairobi / Kiambu / Kakamega">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Sub-County</label>
                                <input type="text" name="sub_county" class="form-control" value="<?php echo e($applicant['sub_county']); ?>" placeholder="e.g. Westlands">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Ward</label>
                                <input type="text" name="ward" class="form-control" value="<?php echo e($applicant['ward']); ?>" placeholder="e.g. Parklands">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Physical Address / Village / Estate</label>
                                <textarea name="physical_address" rows="2" class="form-control" placeholder="Describe physical address, street or village name"><?php echo e($applicant['physical_address']); ?></textarea>
                            </div>
                        </div>

                        <div class="d-flex justify-content-between align-items-center pt-3 border-top">
                            <span class="text-muted small">* Indicates mandatory fields</span>
                            <button type="submit" class="btn btn-green px-4">
                                <i class="bi bi-save-fill me-1"></i> Save Information
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

