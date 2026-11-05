<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('super_admin');

$errors = [];

// Handle Create or Update Program
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_program'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token. Please try again.';
    } else {
        $programId = !empty($_POST['program_id']) ? (int)$_POST['program_id'] : null;
        $name      = trim($_POST['name'] ?? '');
        $desc      = trim($_POST['description'] ?? '');
        $eduLevel  = trim($_POST['education_level'] ?? 'all');
        $maxAmount = !empty($_POST['maximum_amount']) ? (float)$_POST['maximum_amount'] : null;
        $totalBudget = !empty($_POST['total_budget']) ? (float)$_POST['total_budget'] : 0.00;
        $openDate  = !empty($_POST['application_open_date']) ? $_POST['application_open_date'] : null;
        $closeDate = !empty($_POST['application_close_date']) ? $_POST['application_close_date'] : null;
        $status    = trim($_POST['status'] ?? 'active');

        if (empty($name)) {
            $errors[] = 'Program name is required.';
        }
        if (!in_array($eduLevel, ['primary', 'high_school', 'university', 'all'])) {
            $errors[] = 'Invalid education level selected.';
        }
        if (!in_array($status, ['active', 'inactive', 'closed'])) {
            $errors[] = 'Invalid status selected.';
        }
        if ($openDate && $closeDate && strtotime($closeDate) < strtotime($openDate)) {
            $errors[] = 'Application close date cannot be earlier than open date.';
        }

        if (empty($errors)) {
            try {
                if ($programId) {
                    // Update
                    $stmt = $pdo->prepare("
                        UPDATE programs SET
                            name = ?,
                            description = ?,
                            education_level = ?,
                            maximum_amount = ?,
                            total_budget = ?,
                            application_open_date = ?,
                            application_close_date = ?,
                            status = ?,
                            updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $name,
                        $desc ?: null,
                        $eduLevel,
                        $maxAmount,
                        $totalBudget,
                        $openDate,
                        $closeDate,
                        $status,
                        $programId
                    ]);
                    setFlash('success', "Program '$name' updated successfully.");
                } else {
                    // Create
                    $stmt = $pdo->prepare("
                        INSERT INTO programs (
                            name, description, education_level, maximum_amount, total_budget,
                            application_open_date, application_close_date, status, created_at
                        ) VALUES (
                            ?, ?, ?, ?, ?,
                            ?, ?, ?, NOW()
                        )
                    ");
                    $stmt->execute([
                        $name,
                        $desc ?: null,
                        $eduLevel,
                        $maxAmount,
                        $totalBudget,
                        $openDate,
                        $closeDate,
                        $status
                    ]);
                    setFlash('success', "New bursary program '$name' created successfully.");
                }
                header('Location: programs.php');
                exit;

            } catch (PDOException $e) {
                $errors[] = 'Database error: ' . $e->getMessage();
            }
        }
    }
}

// Handle Quick Toggle Status
if (isset($_GET['toggle_status']) && isset($_GET['id'])) {
    $pId = (int)$_GET['id'];
    $cur = $pdo->prepare("SELECT status FROM programs WHERE id = ?");
    $cur->execute([$pId]);
    $currentStatus = $cur->fetchColumn();

    if ($currentStatus !== false) {
        $nextStatus = ($currentStatus === 'active') ? 'inactive' : 'active';
        $up = $pdo->prepare("UPDATE programs SET status = ?, updated_at = NOW() WHERE id = ?");
        $up->execute([$nextStatus, $pId]);
        setFlash('success', "Program status changed to $nextStatus.");
    }
    header('Location: programs.php');
    exit;
}

// Handle Delete Program
if (isset($_GET['delete']) && isset($_GET['id'])) {
    $pId = (int)$_GET['id'];
    $del = $pdo->prepare("DELETE FROM programs WHERE id = ?");
    $del->execute([$pId]);
    setFlash('success', 'Bursary program removed successfully.');
    header('Location: programs.php');
    exit;
}

// Fetch all programs with application count
$programs = $pdo->query("
    SELECT p.*,
           (SELECT COUNT(*) FROM applications WHERE program_id = p.id) AS total_apps,
           (SELECT COALESCE(SUM(al.approved_amount), 0)
            FROM allocations al
            INNER JOIN applications ap ON al.application_id = ap.id
            WHERE ap.program_id = p.id) AS total_allocated
    FROM programs p
    ORDER BY p.id DESC
")->fetchAll();

$fundStats = getCategoryFundStats($pdo);

$pageTitle = 'Manage Bursary Programs';
$activeNav = 'programs';
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
            <span class="topbar-title">Bursary Programs</span>
        </div>
        <div class="topbar-user">
            <button class="btn btn-green btn-sm" data-bs-toggle="modal" data-bs-target="#newProgramModal">
                <i class="bi bi-plus-circle me-1"></i> Add New Program
            </button>
        </div>
    </header>

    <div class="content-body">
        <div class="container-fluid p-0">
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
                        <h5 class="fw-bold mb-1"><i class="bi bi-mortarboard-fill text-success me-2"></i>Bursary Programs Directory</h5>
                        <p class="text-muted small mb-0">Manage funding categories, education level requirements, budget caps, and application windows.</p>
                    </div>
                    <span class="badge bg-success fs-6"><?php echo count($programs); ?> Active & Inactive Programs</span>
                </div>

                <div class="card-body-custom">
                    <div class="table-responsive">
                        <table class="table table-custom">
                            <thead>
                                <tr>
                                    <th>Program Name</th>
                                    <th>Target Education</th>
                                    <th>Max Cap</th>
                                    <th>Application Period</th>
                                    <th>Status</th>
                                    <th>Stats</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($programs)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-5 text-muted">
                                            <i class="bi bi-mortarboard fs-2 d-block mb-2 text-muted"></i>
                                            No bursary programs found. Click "Add New Program" to establish one.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($programs as $prog): ?>
                                        <tr>
                                            <td>
                                                <strong class="text-dark"><?php echo e($prog['name']); ?></strong>
                                                <?php if (!empty($prog['description'])): ?>
                                                    <small class="text-muted d-block" style="max-width: 260px;"><?php echo e($prog['description']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo renderStatusBadge($prog['education_level']); ?></td>
                                            <td class="fw-bold text-success">
                                                <?php echo $prog['maximum_amount'] ? formatMoney((float)$prog['maximum_amount']) : 'No Limit'; ?>
                                            </td>
                                            <td class="small">
                                                <div><strong>Open:</strong> <?php echo $prog['application_open_date'] ? date('d M Y', strtotime($prog['application_open_date'])) : 'Anytime'; ?></div>
                                                <div><strong>Close:</strong> <?php echo $prog['application_close_date'] ? date('d M Y', strtotime($prog['application_close_date'])) : 'Open'; ?></div>
                                            </td>
                                            <td><?php echo renderStatusBadge($prog['status']); ?></td>
                                            <td class="small">
                                                <div><?php echo $prog['total_apps']; ?> Applicants</div>
                                                <div class="text-success fw-semibold"><?php echo formatMoney((float)$prog['total_allocated']); ?> Allocated</div>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editModal-<?php echo $prog['id']; ?>" title="Edit Program">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    <a href="programs.php?toggle_status=1&id=<?php echo $prog['id']; ?>" class="btn btn-outline-<?php echo $prog['status'] === 'active' ? 'warning' : 'success'; ?>" title="<?php echo $prog['status'] === 'active' ? 'Deactivate' : 'Activate'; ?>">
                                                        <i class="bi <?php echo $prog['status'] === 'active' ? 'bi-pause-circle' : 'bi-play-circle'; ?>"></i>
                                                    </a>
                                                    <a href="programs.php?delete=1&id=<?php echo $prog['id']; ?>" class="btn btn-outline-danger" title="Remove Program" data-confirm="Are you sure you want to remove this bursary program?">
                                                        <i class="bi bi-trash"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>

                                        <!-- EDIT MODAL FOR EACH PROGRAM -->
                                        <div class="modal fade" id="editModal-<?php echo $prog['id']; ?>" tabindex="-1" aria-hidden="true">
                                            <div class="modal-dialog modal-lg">
                                                <div class="modal-content">
                                                    <form action="programs.php" method="POST">
                                                        <?php echo csrfInput(); ?>
                                                        <input type="hidden" name="save_program" value="1">
                                                        <input type="hidden" name="program_id" value="<?php echo $prog['id']; ?>">

                                                        <div class="modal-header">
                                                            <h5 class="modal-title fw-bold">Edit Program: <?php echo e($prog['name']); ?></h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <div class="row g-3">
                                                                <div class="col-md-8">
                                                                    <label class="form-label">Program Name <span class="text-danger">*</span></label>
                                                                    <input type="text" name="name" class="form-control" value="<?php echo e($prog['name']); ?>" required>
                                                                </div>
                                                                <div class="col-md-4">
                                                                    <label class="form-label">Status <span class="text-danger">*</span></label>
                                                                    <select name="status" class="form-select" required>
                                                                        <option value="active" <?php echo ($prog['status'] === 'active') ? 'selected' : ''; ?>>Active</option>
                                                                        <option value="inactive" <?php echo ($prog['status'] === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                                                                        <option value="closed" <?php echo ($prog['status'] === 'closed') ? 'selected' : ''; ?>>Closed</option>
                                                                    </select>
                                                                </div>
                                                                <div class="col-md-6">
                                                                    <label class="form-label">Eligible Education Level <span class="text-danger">*</span></label>
                                                                    <select name="education_level" class="form-select" required>
                                                                        <option value="all" <?php echo ($prog['education_level'] === 'all') ? 'selected' : ''; ?>>All Levels (Open to All)</option>
                                                                        <option value="primary" <?php echo ($prog['education_level'] === 'primary') ? 'selected' : ''; ?>>Primary School</option>
                                                                        <option value="high_school" <?php echo ($prog['education_level'] === 'high_school') ? 'selected' : ''; ?>>High School / Secondary</option>
                                                                        <option value="university" <?php echo ($prog['education_level'] === 'university') ? 'selected' : ''; ?>>University / College</option>
                                                                    </select>
                                                                </div>
                                                                <div class="col-md-6">
                                                                    <label class="form-label">Maximum Award Amount (KES)</label>
                                                                    <input type="number" step="0.01" name="maximum_amount" class="form-control" value="<?php echo e($prog['maximum_amount']); ?>" placeholder="Leave blank for unlimited">
                                                                </div>
                                                                <div class="col-md-6">
                                                                    <label class="form-label">Application Open Date</label>
                                                                    <input type="date" name="application_open_date" class="form-control" value="<?php echo e($prog['application_open_date']); ?>">
                                                                </div>
                                                                <div class="col-md-6">
                                                                    <label class="form-label">Application Closing Date</label>
                                                                    <input type="date" name="application_close_date" class="form-control" value="<?php echo e($prog['application_close_date']); ?>">
                                                                </div>
                                                                <div class="col-12">
                                                                    <label class="form-label">Description / Guidelines</label>
                                                                    <textarea name="description" rows="3" class="form-control"><?php echo e($prog['description']); ?></textarea>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                                                            <button type="submit" class="btn btn-green btn-sm">Save Changes</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>

                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- CREATE NEW PROGRAM MODAL -->
    <div class="modal fade" id="newProgramModal" tabindex="-1" aria-labelledby="newProgramModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form action="programs.php" method="POST">
                    <?php echo csrfInput(); ?>
                    <input type="hidden" name="save_program" value="1">

                    <div class="modal-header">
                        <h5 class="modal-title fw-bold" id="newProgramModalLabel"><i class="bi bi-plus-circle text-success me-2"></i>Create New Bursary Program</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-8">
                                <label class="form-label">Program Name <span class="text-danger">*</span></label>
                                <input type="text" name="name" class="form-control" required placeholder="e.g. County Governor's Scholarship Fund">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Status <span class="text-danger">*</span></label>
                                <select name="status" class="form-select" required>
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                    <option value="closed">Closed</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Eligible Education Level <span class="text-danger">*</span></label>
                                <select name="education_level" class="form-select" required>
                                    <option value="all">All Levels (Open to All)</option>
                                    <option value="primary">Primary School</option>
                                    <option value="high_school">High School / Secondary</option>
                                    <option value="university">University / College</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Maximum Award Amount (KES)</label>
                                <input type="number" step="0.01" name="maximum_amount" class="form-control" placeholder="e.g. 50000">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Application Open Date</label>
                                <input type="date" name="application_open_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Application Closing Date</label>
                                <input type="date" name="application_close_date" class="form-control" value="<?php echo date('Y-m-d', strtotime('+30 days')); ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Description / Eligibility Notes</label>
                                <textarea name="description" rows="3" class="form-control" placeholder="Eligibility guidelines and priority criteria for this bursary program..."></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-green btn-sm"><i class="bi bi-check-lg me-1"></i> Create Program</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

