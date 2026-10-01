<?php
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

// If already logged in, redirect to respective dashboard
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] === 'super_admin') {
        header('Location: admin/dashboard.php');
        exit;
    } else {
        header('Location: applicant/dashboard.php');
        exit;
    }
}

$errors = [];
$formData = [
    'first_name' => '',
    'last_name'  => '',
    'id_number'  => '',
    'dob'        => '',
    'gender'     => '',
    'phone'      => '',
    'email'      => '',
    'username'   => ''
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formData['first_name'] = trim($_POST['first_name'] ?? '');
    $formData['last_name']  = trim($_POST['last_name'] ?? '');
    $formData['id_number']  = trim($_POST['id_number'] ?? '');
    $formData['dob']        = trim($_POST['dob'] ?? '');
    $formData['gender']     = trim($_POST['gender'] ?? '');
    $formData['phone']      = trim($_POST['phone'] ?? '');
    $formData['email']      = trim($_POST['email'] ?? '');
    $formData['username']   = trim($_POST['username'] ?? '');
    $password               = $_POST['password'] ?? '';
    $confirmPassword        = $_POST['confirm_password'] ?? '';

    // Validation
    if (empty($formData['first_name'])) {
        $errors[] = 'First Name is required.';
    }
    if (empty($formData['last_name'])) {
        $errors[] = 'Last Name is required.';
    }
    if (empty($formData['id_number'])) {
        $errors[] = 'National ID or Student/Birth Certificate Number is required.';
    }
    if (empty($formData['dob'])) {
        $errors[] = 'Date of Birth is required.';
    }
    if (empty($formData['gender']) || !in_array($formData['gender'], ['Male', 'Female', 'Other'])) {
        $errors[] = 'Please select a valid gender.';
    }
    if (empty($formData['phone'])) {
        $errors[] = 'Phone number is required.';
    }
    if (empty($formData['email']) || !filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'A valid email address is required.';
    }
    if (empty($formData['username']) || strlen($formData['username']) < 3) {
        $errors[] = 'Username must be at least 3 characters.';
    }
    if (strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters.';
    }
    if ($password !== $confirmPassword) {
        $errors[] = 'Passwords do not match.';
    }

    if (empty($errors)) {
        try {
            // Check if username or email is already registered
            $checkStmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1");
            $checkStmt->execute([$formData['username'], $formData['email']]);
            if ($checkStmt->fetch()) {
                $errors[] = 'The username or email address is already registered.';
            }

            // Check if ID number is already registered
            if (empty($errors)) {
                $idStmt = $pdo->prepare("SELECT id FROM applicants WHERE id_number = ? LIMIT 1");
                $idStmt->execute([$formData['id_number']]);
                if ($idStmt->fetch()) {
                    $errors[] = 'The ID Number is already registered.';
                }
            }

            if (empty($errors)) {
                // Begin atomic transaction
                $pdo->beginTransaction();

                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

                // Insert into users
                $userInsert = $pdo->prepare("
                    INSERT INTO users (username, email, password, role, is_active, created_at)
                    VALUES (?, ?, ?, 'applicant', 1, NOW())
                ");
                $userInsert->execute([
                    $formData['username'],
                    $formData['email'],
                    $hashedPassword
                ]);
                $newUserId = (int)$pdo->lastInsertId();

                // Insert into applicants
                $applicantInsert = $pdo->prepare("
                    INSERT INTO applicants (
                        user_id, first_name, last_name, id_number,
                        date_of_birth, gender, phone, email, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $applicantInsert->execute([
                    $newUserId,
                    $formData['first_name'],
                    $formData['last_name'],
                    $formData['id_number'],
                    $formData['dob'],
                    $formData['gender'],
                    $formData['phone'],
                    $formData['email']
                ]);

                // Welcome notification
                createNotification(
                    $pdo,
                    $newUserId,
                    'Welcome to E-Bursary',
                    'Your account has been registered successfully. Please complete your profile, education, and guardian details to prepare your bursary application.'
                );

                $pdo->commit();

                setFlash('success', 'Registration successful! You may now log in with your credentials.');
                header('Location: login.php?registered=1');
                exit;
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Registration failed due to a database error: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Applicant Registration - E-Bursary Management System</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/style.css">
    <style>
        .reg-wrapper {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 15px;
            background: #f5f7f6;
        }
        .reg-card {
            width: 100%;
            max-width: 680px;
            background: #ffffff;
            padding: 35px 40px;
            border-radius: 15px;
            border: 1px solid #e5e7eb;
            box-shadow: 0 5px 25px rgba(0, 0, 0, 0.07);
        }
        .logo {
            width: 65px;
            height: 65px;
            background: #e8f5ee;
            color: #198754;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            font-size: 28px;
        }
    </style>
</head>
<body>

<div class="reg-wrapper">
    <div class="reg-card">
        <div class="logo">
            <i class="bi bi-mortarboard-fill"></i>
        </div>

        <div class="text-center mb-4">
            <h2 class="fw-bold text-dark mb-1">Applicant Registration</h2>
            <p class="text-muted">Create your student account to apply for bursary support</p>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <ul class="mb-0 ps-3">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo e($error); ?></li>
                    <?php endforeach; ?>
                </ul>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <form action="register.php" method="POST" novalidate>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">First Name <span class="text-danger">*</span></label>
                    <input type="text" name="first_name" class="form-control" value="<?php echo e($formData['first_name']); ?>" required placeholder="e.g. John">
                </div>

                <div class="col-md-6">
                    <label class="form-label">Last Name <span class="text-danger">*</span></label>
                    <input type="text" name="last_name" class="form-control" value="<?php echo e($formData['last_name']); ?>" required placeholder="e.g. Doe">
                </div>

                <div class="col-md-6">
                    <label class="form-label">National ID / Birth Cert No <span class="text-danger">*</span></label>
                    <input type="text" name="id_number" class="form-control" value="<?php echo e($formData['id_number']); ?>" required placeholder="e.g. 38291048">
                </div>

                <div class="col-md-3">
                    <label class="form-label">Date of Birth <span class="text-danger">*</span></label>
                    <input type="date" name="dob" class="form-control" value="<?php echo e($formData['dob']); ?>" required>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Gender <span class="text-danger">*</span></label>
                    <select name="gender" class="form-select" required>
                        <option value="">Select</option>
                        <option value="Male" <?php echo ($formData['gender'] === 'Male') ? 'selected' : ''; ?>>Male</option>
                        <option value="Female" <?php echo ($formData['gender'] === 'Female') ? 'selected' : ''; ?>>Female</option>
                        <option value="Other" <?php echo ($formData['gender'] === 'Other') ? 'selected' : ''; ?>>Other</option>
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Phone Number <span class="text-danger">*</span></label>
                    <input type="tel" name="phone" class="form-control" value="<?php echo e($formData['phone']); ?>" required placeholder="e.g. 0712345678">
                </div>

                <div class="col-md-6">
                    <label class="form-label">Email Address <span class="text-danger">*</span></label>
                    <input type="email" name="email" class="form-control" value="<?php echo e($formData['email']); ?>" required placeholder="e.g. student@example.com">
                </div>

                <div class="col-12">
                    <label class="form-label">Choose Username <span class="text-danger">*</span></label>
                    <input type="text" name="username" class="form-control" value="<?php echo e($formData['username']); ?>" required placeholder="e.g. johndoe">
                </div>

                <div class="col-md-6">
                    <label class="form-label">Password <span class="text-danger">*</span></label>
                    <input type="password" name="password" class="form-control" required placeholder="At least 6 characters">
                </div>

                <div class="col-md-6">
                    <label class="form-label">Confirm Password <span class="text-danger">*</span></label>
                    <input type="password" name="confirm_password" class="form-control" required placeholder="Repeat password">
                </div>

                <div class="col-12 mt-4">
                    <button type="submit" class="btn btn-green w-100 py-2 fs-6">
                        <i class="bi bi-person-check-fill me-2"></i> Register Account
                    </button>
                </div>
            </div>
        </form>

        <div class="text-center mt-4 pt-3 border-top">
            <p class="text-muted mb-2">Already have an account? <a href="login.php" class="text-success fw-bold text-decoration-none">Sign In here</a></p>
            <a href="index.php" class="text-muted small text-decoration-none">
                <i class="bi bi-arrow-left"></i> Back to Homepage
            </a>
    </div>
</div>

<script src="assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>

