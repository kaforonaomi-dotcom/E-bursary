<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>E-Bursary Management System</title>

    <!-- Bootstrap 5 -->
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <style>
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f5f7f6;
        }

        .topbar {
            background: #ffffff;
            border-bottom: 1px solid #e5e7eb;
            padding: 18px 50px;
        }

        .brand {
            color: #198754;
            font-size: 24px;
            font-weight: 700;
        }

        .hero {
            min-height: calc(100vh - 75px);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
        }

        .hero-content {
            width: 100%;
            max-width: 1000px;
            text-align: center;
        }

        .hero h1 {
            font-size: 42px;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 15px;
        }

        .hero p {
            color: #6b7280;
            font-size: 18px;
            margin-bottom: 40px;
        }

        .login-card {
            background: #ffffff;
            border-radius: 15px;
            padding: 35px;
            border: 1px solid #e5e7eb;
            box-shadow: 0 5px 20px rgba(0,0,0,0.06);
            height: 100%;
        }

        .login-icon {
            width: 65px;
            height: 65px;
            background: #e8f5ee;
            color: #198754;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 28px;
        }

        .login-card h3 {
            color: #1f2937;
            font-weight: 600;
        }

        .login-card p {
            font-size: 15px;
            margin-bottom: 25px;
        }

        .btn-green {
            background: #198754;
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 8px;
            font-weight: 600;
        }

        .btn-green:hover {
            background: #157347;
            color: white;
        }

        .footer {
            text-align: center;
            color: #6b7280;
            font-size: 14px;
            padding: 20px;
        }
    </style>
</head>

<body>

    <!-- Header -->
    <div class="topbar">
        <div class="brand">
            E-Bursary Management System
        </div>
    </div>

    <!-- Main -->
    <section class="hero">

        <div class="hero-content">

            <h1>Welcome to the E-Bursary System</h1>

            <p>
                Apply for bursary support, track your application,
                and manage bursary applications online.
            </p>

            <div class="row g-4 justify-content-center">

                <!-- Applicant -->
                <div class="col-md-5">

                    <div class="login-card">

                        <div class="login-icon">
                            🎓
                        </div>

                        <h3>Applicant</h3>

                        <p>
                            Students can apply for bursary support,
                            submit required documents and track
                            their application status.
                        </p>

                        <div class="d-flex justify-content-center gap-2">
                            <a href="login.php" class="btn btn-green">
                                Applicant Login
                            </a>
                            <a href="register.php" class="btn btn-outline-success px-3">
                                Register
                            </a>
                        </div>

                    </div>

                </div>

                <!-- Administrator -->
                <div class="col-md-5">

                    <div class="login-card">

                        <div class="login-icon">
                            ⚙️
                        </div>

                        <h3>Administrator</h3>

                        <p>
                            Administrators can manage applicants,
                            review applications, approve bursaries
                            and manage disbursements.
                        </p>

                        <a href="login.php" class="btn btn-green">
                            Administrator Login
                        </a>

                    </div>

                </div>

            </div>

            <div class="footer">
                © <?php echo date('Y'); ?> E-Bursary Management System
            </div>

        </div>

    </section>

</body>
</html>