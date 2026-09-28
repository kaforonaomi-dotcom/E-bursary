<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('applicant');

$userId = (int)$_SESSION['user_id'];
$applicant = getCurrentApplicant($pdo);

// Mark all as read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_all_read'])) {
    if (verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $updateStmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
        $updateStmt->execute([$userId]);
        setFlash('success', 'All notifications marked as read.');
        header('Location: notifications.php');
        exit;
    }
}

// Fetch all notifications
$stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC");
$stmt->execute([$userId]);
$notifications = $stmt->fetchAll();

$pageTitle = 'Notifications';
$activeNav = 'notifications';
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
            <span class="topbar-title">Notifications</span>
        </div>
        <div class="topbar-user">
            <span class="text-muted d-none d-md-inline">Applicant:</span>
            <strong><?php echo e($applicant['first_name'] . ' ' . $applicant['last_name']); ?></strong>
        </div>
    </header>

    <div class="content-body">
        <div class="container-fluid p-0" style="max-width: 900px;">
            <?php echo renderFlash(); ?>

            <div class="card-custom">
                <div class="card-header-custom">
                    <div>
                        <h5 class="fw-bold mb-0 text-dark"><i class="bi bi-bell-fill text-success me-2"></i>My Notifications</h5>
                        <small class="text-muted">Updates regarding your applications, documents, and disbursements</small>
                    </div>
                    <?php if (!empty($notifications)): ?>
                        <form action="notifications.php" method="POST">
                            <?php echo csrfInput(); ?>
                            <button type="submit" name="mark_all_read" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-check2-all me-1"></i> Mark All as Read
                            </button>
                        </form>
                    <?php endif; ?>
                </div>

                <div class="card-body-custom p-0">
                    <?php if (empty($notifications)): ?>
                        <div class="text-center py-5 text-muted">
                            <i class="bi bi-bell-slash fs-1 d-block mb-2 text-muted"></i>
                            <p class="mb-0">You have no notifications at this time.</p>
                        </div>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($notifications as $n):
                                $isUnread = !$n['is_read'];
                            ?>
                                <div class="list-group-item p-3 <?php echo $isUnread ? 'bg-light border-start border-success border-4' : ''; ?>">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <h6 class="fw-bold mb-0 <?php echo $isUnread ? 'text-success' : 'text-dark'; ?>">
                                            <?php echo e($n['title']); ?>
                                            <?php if ($isUnread): ?>
                                                <span class="badge bg-danger ms-1" style="font-size: 10px;">New</span>
                                            <?php endif; ?>
                                        </h6>
                                        <small class="text-muted"><?php echo date('d M Y, h:i A', strtotime($n['created_at'])); ?></small>
                                    </div>
                                    <p class="mb-0 text-muted small"><?php echo nl2br(e($n['message'])); ?></p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

