<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('super_admin');

$errors = [];

// Handle Create / Edit Allocation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_allocation'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token. Please try again.';
    } else {
        $allocId      = !empty($_POST['allocation_id']) ? (int)$_POST['allocation_id'] : null;
        $appId        = !empty($_POST['application_id']) ? (int)$_POST['application_id'] : null;
        $amount       = !empty($_POST['approved_amount']) ? (float)$_POST['approved_amount'] : null;
        $allocDate    = !empty($_POST['allocation_date']) ? $_POST['allocation_date'] : date('Y-m-d');
        $academicYear = trim($_POST['academic_year'] ?? '');
        $status       = trim($_POST['status'] ?? 'pending');
        $notes        = trim($_POST['notes'] ?? '');

        if (!$amount || $amount <= 0) {
            $errors[] = 'Please enter a valid allocation amount.';
        }
        if (!in_array($status, ['pending', 'approved', 'partially_disbursed', 'fully_disbursed'])) {
            $errors[] = 'Invalid allocation status.';
        }

        if (empty($errors)) {
            try {
                if ($allocId) {
                    $upStmt = $pdo->prepare("
                        UPDATE allocations SET
                            approved_amount = ?,
                            allocation_date = ?,
                            academic_year = ?,
                            status = ?,
                            notes = ?,
                            updated_at = NOW()
                        WHERE id = ?
                    ");
                    $upStmt->execute([$amount, $allocDate, $academicYear ?: null, $status, $notes ?: null, $allocId]);
                    setFlash('success', 'Allocation record updated successfully.');
                } else {
                    if (!$appId) {
                        $errors[] = 'Application ID is required.';
                    } else {
                        $insStmt = $pdo->prepare("
                            INSERT INTO allocations (
                                application_id, approved_amount, allocation_date, academic_year, status, notes, created_at
                            ) VALUES (
                                ?, ?, ?, ?, ?, ?, NOW()
                            )
                        ");
                        $insStmt->execute([$appId, $amount, $allocDate, $academicYear ?: null, $status, $notes ?: null]);

                        // Ensure application is approved
                        $pdo->prepare("UPDATE applications SET status = 'approved', updated_at = NOW() WHERE id = ?")->execute([$appId]);

                        setFlash('success', 'New allocation created successfully.');
                    }
                }
                header('Location: allocations.php');
                exit;

            } catch (PDOException $e) {
                $errors[] = 'Database error: ' . $e->getMessage();
            }
        }
    }
}

// Filter
$statusFilter = trim($_GET['status'] ?? '');
$sql = "
    SELECT al.*, a.application_number, a.requested_amount,
           ap.first_name, ap.last_name, ap.id_number,
           p.name AS program_name,
           (SELECT COALESCE(SUM(amount), 0) FROM disbursements WHERE allocation_id = al.id AND status = 'completed') AS total_disbursed
    FROM allocations al
    INNER JOIN applications a ON al.application_id = a.id
    INNER JOIN applicants ap ON a.applicant_id = ap.id
    LEFT JOIN programs p ON a.program_id = p.id
    WHERE 1=1
";
$params = [];
if (!empty($statusFilter)) {
    $sql .= " AND al.status = ?";
    $params[] = $statusFilter;
}
$sql .= " ORDER BY al.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$allocations = $stmt->fetchAll();

// Fetch approved applications that don't have an allocation yet
$unallocatedApps = $pdo->query("
    SELECT a.id, a.application_number, a.requested_amount, ap.first_name, ap.last_name, p.name AS program_name
    FROM applications a
    INNER JOIN applicants ap ON a.applicant_id = ap.id
    LEFT JOIN programs p ON a.program_id = p.id
    WHERE a.status = 'approved' AND a.id NOT IN (SELECT application_id FROM allocations)
    ORDER BY a.id DESC
")->fetchAll();

// Summary stats
$totalAllocated = (float)$pdo->query("SELECT COALESCE(SUM(approved_amount), 0) FROM allocations WHERE status != 'pending'")->fetchColumn();
$totalDisbursed = (float)$pdo->query("SELECT COALESCE(SUM(amount), 0) FROM disbursements WHERE status = 'completed'")->fetchColumn();

$pageTitle = 'Manage Allocations';
$activeNav = 'allocations';
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
            <span class="topbar-title">Fund Allocations</span>
        </div>
        <div class="topbar-user">
            <?php if (!empty($unallocatedApps)): ?>
                <button class="btn btn-green btn-sm" data-bs-toggle="modal" data-bs-target="#newAllocModal">
                    <i class="bi bi-plus-circle me-1"></i> Allocate Funds (<?php echo count($unallocatedApps); ?> Pending)
                </button>
            <?php endif; ?>
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

            <!-- STATS ROW -->
            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <div class="stat-card">
                        <div class="stat-title">Total Approved Allocations</div>
                        <div class="stat-value text-success"><?php echo formatMoney($totalAllocated); ?></div>
                        <div class="stat-subtitle"><?php echo count($allocations); ?> allocation records</div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-card">
                        <div class="stat-title">Disbursed Funds</div>
                        <div class="stat-value text-primary"><?php echo formatMoney($totalDisbursed); ?></div>
                        <div class="stat-subtitle">Successfully released payments</div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-card">
                        <div class="stat-title">Undisbursed Balance</div>
                        <div class="stat-value text-warning"><?php echo formatMoney(max(0, $totalAllocated - $totalDisbursed)); ?></div>
                        <div class="stat-subtitle">Committed funds awaiting release</div>
                    </div>
                </div>
            </div>

            <div class="card-custom">
                <div class="card-header-custom">
                    <div>
                        <h5 class="fw-bold mb-1"><i class="bi bi-cash-stack text-success me-2"></i>Allocations Ledger</h5>
                        <p class="text-muted small mb-0">Record of approved bursary funds assigned to eligible student applications.</p>
                    </div>

                    <!-- Filter by status -->
                    <form action="allocations.php" method="GET" class="d-flex align-items-center gap-2">
                        <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="">All Statuses</option>
                            <option value="pending" <?php echo ($statusFilter === 'pending') ? 'selected' : ''; ?>>Pending</option>
                            <option value="approved" <?php echo ($statusFilter === 'approved') ? 'selected' : ''; ?>>Approved</option>
                            <option value="partially_disbursed" <?php echo ($statusFilter === 'partially_disbursed') ? 'selected' : ''; ?>>Partially Disbursed</option>
                            <option value="fully_disbursed" <?php echo ($statusFilter === 'fully_disbursed') ? 'selected' : ''; ?>>Fully Disbursed</option>
                        </select>
                        <?php if (!empty($statusFilter)): ?>
                            <a href="allocations.php" class="btn btn-sm btn-outline-secondary" title="Reset"><i class="bi bi-x"></i></a>
                        <?php endif; ?>
                    </form>
                </div>

                <div class="card-body-custom">
                    <div class="table-responsive">
                        <table class="table table-custom">
                            <thead>
                                <tr>
                                    <th>App #</th>
                                    <th>Applicant Name</th>
                                    <th>Program</th>
                                    <th>Approved Amount</th>
                                    <th>Disbursed</th>
                                    <th>Academic Year</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($allocations)): ?>
                                    <tr>
                                        <td colspan="8" class="text-center py-5 text-muted">
                                            <i class="bi bi-wallet2 fs-2 d-block mb-2 text-muted"></i>
                                            No allocation records found.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($allocations as $al): ?>
                                        <tr>
                                            <td><strong><?php echo e($al['application_number']); ?></strong></td>
                                            <td>
                                                <div class="fw-bold text-dark"><?php echo e($al['first_name'] . ' ' . $al['last_name']); ?></div>
                                                <small class="text-muted">ID: <?php echo e($al['id_number']); ?></small>
                                            </td>
                                            <td><?php echo e($al['program_name'] ?? 'General'); ?></td>
                                            <td class="fw-bold text-success"><?php echo formatMoney((float)$al['approved_amount']); ?></td>
                                            <td><?php echo formatMoney((float)$al['total_disbursed']); ?></td>
                                            <td><?php echo e($al['academic_year'] ?? 'Current'); ?></td>
                                            <td><?php echo renderStatusBadge($al['status']); ?></td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editAlloc-<?php echo $al['id']; ?>" title="Edit Allocation">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    <a href="disbursements.php?allocation_id=<?php echo $al['id']; ?>" class="btn btn-outline-green" title="Process Disbursement">
                                                        <i class="bi bi-credit-card"></i> Disburse
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>

                                        <!-- EDIT ALLOCATION MODAL -->
                                        <div class="modal fade" id="editAlloc-<?php echo $al['id']; ?>" tabindex="-1" aria-hidden="true">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <form action="allocations.php" method="POST">
                                                        <?php echo csrfInput(); ?>
                                                        <input type="hidden" name="save_allocation" value="1">
                                                        <input type="hidden" name="allocation_id" value="<?php echo $al['id']; ?>">

                                                        <div class="modal-header">
                                                            <h5 class="modal-title fw-bold">Edit Allocation #<?php echo $al['id']; ?></h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <div class="mb-3">
                                                                <label class="form-label">Applicant</label>
                                                                <input type="text" class="form-control bg-light" value="<?php echo e($al['first_name'] . ' ' . $al['last_name']); ?>" readonly>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label">Approved Amount (KES) <span class="text-danger">*</span></label>
                                                                <input type="number" step="0.01" name="approved_amount" class="form-control" value="<?php echo e($al['approved_amount']); ?>" required>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label">Allocation Date</label>
                                                                <input type="date" name="allocation_date" class="form-control" value="<?php echo e($al['allocation_date']); ?>">
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label">Academic Year</label>
                                                                <input type="text" name="academic_year" class="form-control" value="<?php echo e($al['academic_year']); ?>">
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label">Status <span class="text-danger">*</span></label>
                                                                <select name="status" class="form-select" required>
                                                                    <option value="pending" <?php echo ($al['status'] === 'pending') ? 'selected' : ''; ?>>Pending</option>
                                                                    <option value="approved" <?php echo ($al['status'] === 'approved') ? 'selected' : ''; ?>>Approved</option>
                                                                    <option value="partially_disbursed" <?php echo ($al['status'] === 'partially_disbursed') ? 'selected' : ''; ?>>Partially Disbursed</option>
                                                                    <option value="fully_disbursed" <?php echo ($al['status'] === 'fully_disbursed') ? 'selected' : ''; ?>>Fully Disbursed</option>
                                                                </select>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label">Notes</label>
                                                                <textarea name="notes" rows="2" class="form-control"><?php echo e($al['notes']); ?></textarea>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                                                            <button type="submit" class="btn btn-green btn-sm">Update Allocation</button>
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

    <!-- NEW ALLOCATION MODAL -->
    <?php if (!empty($unallocatedApps)): ?>
        <div class="modal fade" id="newAllocModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form action="allocations.php" method="POST">
                        <?php echo csrfInput(); ?>
                        <input type="hidden" name="save_allocation" value="1">

                        <div class="modal-header">
                            <h5 class="modal-title fw-bold">Create New Fund Allocation</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label">Select Approved Application <span class="text-danger">*</span></label>
                                <select name="application_id" class="form-select" required>
                                    <option value="">-- Choose Application --</option>
                                    <?php foreach ($unallocatedApps as $uApp): ?>
                                        <option value="<?php echo $uApp['id']; ?>">
                                            <?php echo e($uApp['application_number']); ?> - <?php echo e($uApp['first_name'] . ' ' . $uApp['last_name']); ?> (Req: <?php echo formatMoney((float)$uApp['requested_amount']); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Approved Allocation Amount (KES) <span class="text-danger">*</span></label>
                                <input type="number" step="0.01" min="1000" name="approved_amount" class="form-control" required placeholder="e.g. 20000">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Allocation Date</label>
                                <input type="date" name="allocation_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Academic Year</label>
                                <input type="text" name="academic_year" class="form-control" value="<?php echo date('Y') . '/' . (date('Y') + 1); ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Status <span class="text-danger">*</span></label>
                                <select name="status" class="form-select" required>
                                    <option value="approved">Approved</option>
                                    <option value="pending">Pending</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Notes</label>
                                <textarea name="notes" rows="2" class="form-control" placeholder="Allocation notes or voucher reference..."></textarea>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-green btn-sm">Confirm Allocation</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

