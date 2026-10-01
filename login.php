<?php
session_start();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Login - E-Bursary Management System</title>

    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/bootstrap-icons.min.css" rel="stylesheet">

    <style>
        body {
            background: #f5f7f6;
            font-family: Arial, sans-serif;
        }

        .login-wrapper {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .login-card {
            width: 100%;
            max-width: 450px;
            background: #ffffff;
            padding: 40px;
            border-radius: 15px;
            border: 1px solid #e5e7eb;
            box-shadow: 0 5px 25px rgba(0, 0, 0, 0.07);
        }

        .logo {
            width: 70px;
            height: 70px;
            background: #e8f5ee;
            color: #198754;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 30px;
        }

        h2 {
            color: #1f2937;
            font-weight: 700;
        }

        .subtitle {
            color: #6b7280;
        }

        .form-label {
            font-weight: 600;
            color: #374151;
        }

        .form-control {
            padding: 12px;
            border-radius: 8px;
        }

        .btn-login {
            background: #198754;
            color: white;
            border: none;
            width: 100%;
            padding: 12px;
            border-radius: 8px;
            font-weight: 600;
        }

        .btn-login:hover {
            background: #157347;
            color: white;
        }

        .back-link {
            color: #198754;
            text-decoration: none;
        }
    </style>
</head>

<body>

<div class="login-wrapper">

    <div class="login-card">

        <div class="logo">
            🎓
        </div>

        <div class="text-center mb-4">
            <h2>Welcome Back</h2>

            <p class="subtitle">
                Sign in to the E-Bursary Management System
            </p>
        </div>

        <?php
        require_once __DIR__ . '/includes/functions.php';
        $flash = getFlash();
        if ($flash): ?>
            <div class="alert alert-<?php echo e($flash['type']); ?> alert-dismissible fade show" role="alert">
                <?php echo e($flash['message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php
                if ($_GET['error'] === 'empty') echo 'Please enter both your email/username and password.';
                elseif ($_GET['error'] === 'invalid') echo 'Invalid credentials or inactive account.';
                elseif ($_GET['error'] === 'unauthorized') echo 'You do not have permission to access that resource.';
                else echo 'Authentication error. Please try again.';
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['registered'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                Registration successful! Please sign in with your credentials.
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <form action="authenticate.php" method="POST">

            <div class="mb-3">
                <label class="form-label">
                    Email or Username
                </label>

                <input
                    type="text"
                    name="login"
                    class="form-control"
                    placeholder="Enter your email or username"
                    required
                >
            </div>

            <div class="mb-3">
                <label class="form-label">
                    Password
                </label>

                <input
                    type="password"
                    name="password"
                    class="form-control"
                    placeholder="Enter your password"
                    required
                >
            </div>

            <button type="submit" class="btn btn-login">
                Sign In
            </button>

        </form>

        <div class="text-center mt-4">
            <p class="mb-2 text-muted">
                Don't have an account? <a href="register.php" class="back-link fw-bold">Register as Applicant</a>
            </p>

            <a href="index.php" class="back-link small">
                ← Back to Home
            </a>

        </div>

    </div>

</div>

    <script src="assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>