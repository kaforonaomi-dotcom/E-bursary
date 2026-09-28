<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('super_admin');

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token. Please try again.';
    } else {
        $systemName = trim($_POST['system_name'] ?? '');
        $appStatus  = trim($_POST['application_status'] ?? 'open');
        $deadline   = trim($_POST['application_deadline'] ?? '');

        if (empty($systemName)) {
            $errors[] = 'System name cannot be blank.';
        }
        if (!in_array($appStatus, ['open', 'closed'])) {
            $errors[] = 'Invalid application status value.';
        }

        if (empty($errors)) {
            updateSetting($pdo, 'system_name', $systemName);
            updateSetting($pdo, 'application_status', $appStatus);
            updateSetting($pdo, 'application_deadline', !empty($deadline) ? $deadline : null);

            setFlash('success', 'System settings updated successfully.');
            header('Location: settings.php');
            exit;
        }
    }
}

// Current settings
$currentSystemName = getSetting($pdo, 'system_name', 'E-Bursary Management System');
$currentAppStatus  = getSetting($pdo, 'application_status', 'open');
$currentDeadline   = getSetting($pdo, 'application_deadline', '');

$pageTitle = 'System Settings';
$activeNav = 'settings';
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
            <span class="topbar-title">System Settings & Configuration</span>
        </div>
        <div class="topbar-user">
            <span class="text-muted d-none d-md-inline">Administrator:</span>
            <strong><?php echo e($_SESSION['username'] ?? 'Admin'); ?></strong>
        </div>
    </header>

    <div class="content-body">
        <div class="container-fluid p-0" style="max-width: 850px;">
            <?php echo renderFlash(); ?>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <ul class="mb-0 ps-3">
                        <?php foreach ($errors as $err): ?>
                            <li><?php echo e($err); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="card-custom">
                <div class="card-header-custom">
                    <div>
                        <h5 class="fw-bold mb-1"><i class="bi bi-gear-fill text-success me-2"></i>Global Bursary Configuration</h5>
                        <p class="text-muted small mb-0">Control application intake periods, official system naming, and global deadlines.</p>
                    </div>
                </div>

                <div class="card-body-custom">
                    <form action="settings.php" method="POST">
                        <?php echo csrfInput(); ?>
                        <input type="hidden" name="save_settings" value="1">

                        <!-- System Name -->
                        <div class="mb-4">
                            <label class="form-label fw-bold">System / County Name <span class="text-danger">*</span></label>
                            <input type="text" name="system_name" class="form-control form-control-lg" value="<?php echo e($currentSystemName); ?>" required>
                            <small class="text-muted">Displayed on the application portal, page titles, and receipts.</small>
                        </div>

                        <!-- Application Intake Status -->
                        <div class="mb-4">
                            <label class="form-label fw-bold">Global Application Intake Status <span class="text-danger">*</span></label>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <div class="p-3 border rounded <?php echo ($currentAppStatus === 'open') ? 'border-success bg-light' : ''; ?>">
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="application_status" id="statusOpen" value="open" <?php echo ($currentAppStatus === 'open') ? 'checked' : ''; ?>>
                                            <label class="form-check-label fw-bold text-success" for="statusOpen">
                                                <i class="bi bi-door-open-fill me-1"></i> Open for Applications
                                            </label>
                                            <p class="text-muted small mb-0 mt-1">Applicants can submit new applications and edit drafts.</p>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="p-3 border rounded <?php echo ($currentAppStatus === 'closed') ? 'border-danger bg-light' : ''; ?>">
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="application_status" id="statusClosed" value="closed" <?php echo ($currentAppStatus === 'closed') ? 'checked' : ''; ?>>
                                            <label class="form-check-label fw-bold text-danger" for="statusClosed">
                                                <i class="bi bi-door-closed-fill me-1"></i> Closed (Intake Suspended)
                                            </label>
                                            <p class="text-muted small mb-0 mt-1">Prevents new applications from being created or submitted.</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Application Deadline -->
                        <div class="mb-4">
                            <label class="form-label fw-bold">General Application Closing Deadline</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="bi bi-calendar-event"></i></span>
                                <input type="date" name="application_deadline" class="form-control" value="<?php echo e($currentDeadline); ?>">
                            </div>
                            <small class="text-muted">Applications submitted after midnight on this date will be automatically blocked by the system.</small>
                        </div>

                        <div class="pt-3 border-top d-flex justify-content-end">
                            <button type="submit" class="btn btn-green px-4 py-2">
                                <i class="bi bi-check2-circle me-1"></i> Save Configuration
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

