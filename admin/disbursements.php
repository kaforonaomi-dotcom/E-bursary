<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('super_admin');

$errors = [];

// Handle Record / Edit Disbursement
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_disbursement'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token. Please try again.';
    } else {
        $disbId           = !empty($_POST['disbursement_id']) ? (int)$_POST['disbursement_id'] : null;
        $allocId          = !empty($_POST['allocation_id']) ? (int)$_POST['allocation_id'] : null;
        $amount           = !empty($_POST['amount']) ? (float)$_POST['amount'] : null;
        $disbDate         = !empty($_POST['disbursement_date']) ? $_POST['disbursement_date'] : date('Y-m-d');
        $payRef           = trim($_POST['payment_reference'] ?? '');
        $payMethod        = trim($_POST['payment_method'] ?? 'Direct Institution Account');
        $recipientType    = trim($_POST['recipient_type'] ?? 'institution');
        $recipientAccount = trim($_POST['recipient_account'] ?? '');
        $status           = trim($_POST['status'] ?? 'pending');
        $notes            = trim($_POST['notes'] ?? '');

        if (!$amount || $amount <= 0) {
            $errors[] = 'Please enter a valid disbursement amount.';
        }
        if (empty($payRef)) {
            $errors[] = 'Payment reference / Cheque / Transaction number is required.';
        }
        if (!in_array($status, ['pending', 'processing', 'completed', 'failed'])) {
            $errors[] = 'Invalid disbursement status.';
        }

        // Determine target allocation ID
        $targetAllocId = $allocId;
        if (!$targetAllocId && $disbId) {
            $targetAllocId = (int)$pdo->query("SELECT allocation_id FROM disbursements WHERE id = $disbId")->fetchColumn();
        }

        if (!$targetAllocId) {
            $errors[] = 'Allocation ID is required.';
        }

        // Fetch student's education level and details for policy enforcement
        $allocInfo = null;
        if ($targetAllocId) {
            $eduStmt = $pdo->prepare("
                SELECT al.*, a.application_number, ap.id AS applicant_id, ap.user_id,
                       ap.first_name, ap.last_name, ap.phone,
                       e.education_level, e.institution_name, e.admission_number, e.registration_number
                FROM allocations al
                INNER JOIN applications a ON al.application_id = a.id
                INNER JOIN applicants ap ON a.applicant_id = ap.id
                LEFT JOIN education e ON ap.id = e.applicant_id
                WHERE al.id = ?
            ");
            $eduStmt->execute([$targetAllocId]);
            $allocInfo = $eduStmt->fetch();
        }

        if (!$allocInfo) {
            $errors[] = 'Specified allocation could not be found.';
        }

        if (empty($errors) && $allocInfo) {
            $eduLevel = $allocInfo['education_level'] ?? 'university';
            $instName = $allocInfo['institution_name'] ?: 'School/Institution';
            $admNo = $allocInfo['admission_number'] ?: ($allocInfo['registration_number'] ?: 'N/A');

            // POLICY ENFORCEMENT:
            // High School & Primary School -> MUST be direct to institution account / school cheque / school EFT
            if ($eduLevel === 'primary' || $eduLevel === 'high_school') {
                $recipientType = 'institution';
                // Enforce institutional payment methods
                $validInstMethods = [
                    'Direct Institution Account',
                    'School Cheque / Treasury Voucher',
                    'Bank Transfer / EFT (To School Account)',
                    'Direct Institutional Deposit'
                ];
                if (!in_array($payMethod, $validInstMethods)) {
                    $payMethod = 'Direct Institution Account';
                }
                if (empty($recipientAccount)) {
                    $recipientAccount = $instName . " (Adm: " . $admNo . ")";
                }
            } else {
                // University / College -> All other options available
                // If method contains Student or M-Pesa, mark recipient_type as 'student'
                if (stripos($payMethod, 'Student') !== false || stripos($payMethod, 'M-Pesa') !== false) {
                    $recipientType = 'student';
                    if (empty($recipientAccount) && !empty($allocInfo['phone'])) {
                        $recipientAccount = 'Student Phone: ' . $allocInfo['phone'];
                    }
                } else {
                    $recipientType = 'institution';
                    if (empty($recipientAccount)) {
                        $recipientAccount = $instName . " (Reg: " . $admNo . ")";
                    }
                }
            }

            try {
                $pdo->beginTransaction();

                if ($disbId) {
                    // Update
                    $upStmt = $pdo->prepare("
                        UPDATE disbursements SET
                            amount = ?,
                            disbursement_date = ?,
                            payment_reference = ?,
                            payment_method = ?,
                            recipient_type = ?,
                            recipient_account = ?,
                            status = ?,
                            notes = ?,
                            updated_at = NOW()
                        WHERE id = ?
                    ");
                    $upStmt->execute([
                        $amount, $disbDate, $payRef, $payMethod,
                        $recipientType, $recipientAccount ?: null,
                        $status, $notes ?: null, $disbId
                    ]);
                } else {
                    // Insert
                    $insStmt = $pdo->prepare("
                        INSERT INTO disbursements (
                            allocation_id, amount, disbursement_date, payment_reference,
                            payment_method, recipient_type, recipient_account, status, notes, created_at
                        ) VALUES (
                            ?, ?, ?, ?,
                            ?, ?, ?, ?, ?, NOW()
                        )
                    ");
                    $insStmt->execute([
                        $allocId, $amount, $disbDate, $payRef,
                        $payMethod, $recipientType, $recipientAccount ?: null,
                        $status, $notes ?: null
                    ]);
                }

                // Update allocation status based on cumulative completed disbursements
                $sumStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM disbursements WHERE allocation_id = ? AND status = 'completed'");
                $sumStmt->execute([$targetAllocId]);
                $totalDisbursedSoFar = (float)$sumStmt->fetchColumn();

                $newAllocStatus = $allocInfo['status'];
                if ($totalDisbursedSoFar >= (float)$allocInfo['approved_amount']) {
                    $newAllocStatus = 'fully_disbursed';
                } elseif ($totalDisbursedSoFar > 0) {
                    $newAllocStatus = 'partially_disbursed';
                }

                $upAlloc = $pdo->prepare("UPDATE allocations SET status = ?, updated_at = NOW() WHERE id = ?");
                $upAlloc->execute([$newAllocStatus, $targetAllocId]);

                // Notification to applicant with tailored channel details
                if ($status === 'completed') {
                    if ($eduLevel === 'primary' || $eduLevel === 'high_school') {
                        $notifMessage = "A bursary disbursement of " . formatMoney($amount) . " has been sent directly to your school account at {$instName} (Adm No: {$admNo}) via {$payMethod}. Ref: {$payRef}.";
                    } else {
                        $channelDesc = ($recipientType === 'institution') ? "University Tuition Account ({$instName})" : "Direct Student Payment ({$payMethod})";
                        $notifMessage = "A bursary disbursement of " . formatMoney($amount) . " has been released via {$payMethod} [{$channelDesc}]. Ref: {$payRef}.";
                    }

                    createNotification(
                        $pdo,
                        (int)$allocInfo['user_id'],
                        'Bursary Disbursement Processed',
                        $notifMessage
                    );
                }

                $pdo->commit();

                setFlash('success', 'Disbursement record processed successfully.');
                header('Location: disbursements.php');
                exit;

            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = 'Failed to process disbursement: ' . $e->getMessage();
            }
        }
    }
}

// Pre-selected allocation if clicked from allocations page
$preAllocId = isset($_GET['allocation_id']) ? (int)$_GET['allocation_id'] : null;

// Filter
$statusFilter = trim($_GET['status'] ?? '');
$levelFilter  = trim($_GET['level'] ?? '');

$sql = "
    SELECT d.*, al.approved_amount, a.application_number,
           ap.first_name, ap.last_name, ap.id_number, ap.phone,
           p.name AS program_name,
           e.education_level, e.institution_name, e.admission_number, e.registration_number
    FROM disbursements d
    INNER JOIN allocations al ON d.allocation_id = al.id
    INNER JOIN applications a ON al.application_id = a.id
    INNER JOIN applicants ap ON a.applicant_id = ap.id
    LEFT JOIN education e ON ap.id = e.applicant_id
    LEFT JOIN programs p ON a.program_id = p.id
    WHERE 1=1
";
$params = [];
if (!empty($statusFilter)) {
    $sql .= " AND d.status = ?";
    $params[] = $statusFilter;
}
if (!empty($levelFilter)) {
    $sql .= " AND e.education_level = ?";
    $params[] = $levelFilter;
}
$sql .= " ORDER BY d.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$disbursements = $stmt->fetchAll();

// Allocations available for new disbursement
$allocationsForDisb = $pdo->query("
    SELECT al.id, al.approved_amount, a.application_number, ap.first_name, ap.last_name, ap.phone,
           (SELECT COALESCE(SUM(amount), 0) FROM disbursements WHERE allocation_id = al.id AND status = 'completed') AS already_disbursed,
           e.education_level, e.institution_name, e.admission_number, e.registration_number
    FROM allocations al
    INNER JOIN applications a ON al.application_id = a.id
    INNER JOIN applicants ap ON a.applicant_id = ap.id
    LEFT JOIN education e ON ap.id = e.applicant_id
    WHERE al.status != 'fully_disbursed'
    ORDER BY al.id DESC
")->fetchAll();

$pageTitle = 'Manage Disbursements';
$activeNav = 'disbursements';
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
            <span class="topbar-title">Disbursement Management</span>
        </div>
        <div class="topbar-user">
            <?php if (!empty($allocationsForDisb)): ?>
                <button class="btn btn-green btn-sm" data-bs-toggle="modal" data-bs-target="#newDisbModal">
                    <i class="bi bi-plus-circle me-1"></i> Record Disbursement
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

            <!-- POLICY HIGHLIGHT BANNER -->
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <div class="card h-100 border-0 shadow-sm" style="background: #eff6ff; border-left: 4px solid #3b82f6 !important;">
                        <div class="card-body py-3">
                            <div class="d-flex align-items-center gap-3">
                                <div class="bg-primary text-white p-2 rounded-circle fs-4">
                                    <i class="bi bi-building-check"></i>
                                </div>
                                <div>
                                    <h6 class="fw-bold mb-1 text-primary">Primary & High School Policy</h6>
                                    <p class="small text-muted mb-0">Funds are remitted <strong>strictly to Direct Institution Accounts</strong> (School Bank Account or School Cheque) with student admission number referenced.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card h-100 border-0 shadow-sm" style="background: #f0fdf4; border-left: 4px solid #10b981 !important;">
                        <div class="card-body py-3">
                            <div class="d-flex align-items-center gap-3">
                                <div class="bg-success text-white p-2 rounded-circle fs-4">
                                    <i class="bi bi-mortarboard"></i>
                                </div>
                                <div>
                                    <h6 class="fw-bold mb-1 text-success">University / College Flexibility</h6>
                                    <p class="small text-muted mb-0">University students are eligible for <strong>all disbursement channels</strong> (University Fee Account, Student Bank Account, M-Pesa, or Cheque).</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card-custom">
                <div class="card-header-custom d-flex flex-wrap justify-content-between align-items-center gap-3">
                    <div>
                        <h5 class="fw-bold mb-1"><i class="bi bi-credit-card-fill text-success me-2"></i>Disbursement Vouchers & Cheques</h5>
                        <p class="text-muted small mb-0">Record and track payment releases, bank vouchers, EFTs, and cheques sent to institutions and students.</p>
                    </div>

                    <form action="disbursements.php" method="GET" class="d-flex align-items-center gap-2 flex-wrap">
                        <select name="level" class="form-select form-select-sm" onchange="this.form.submit()" style="width: auto;">
                            <option value="">All Education Levels</option>
                            <option value="primary" <?php echo ($levelFilter === 'primary') ? 'selected' : ''; ?>>Primary School</option>
                            <option value="high_school" <?php echo ($levelFilter === 'high_school') ? 'selected' : ''; ?>>High School</option>
                            <option value="university" <?php echo ($levelFilter === 'university') ? 'selected' : ''; ?>>University / College</option>
                        </select>
                        <select name="status" class="form-select form-select-sm" onchange="this.form.submit()" style="width: auto;">
                            <option value="">All Payment Statuses</option>
                            <option value="pending" <?php echo ($statusFilter === 'pending') ? 'selected' : ''; ?>>Pending</option>
                            <option value="processing" <?php echo ($statusFilter === 'processing') ? 'selected' : ''; ?>>Processing</option>
                            <option value="completed" <?php echo ($statusFilter === 'completed') ? 'selected' : ''; ?>>Completed</option>
                            <option value="failed" <?php echo ($statusFilter === 'failed') ? 'selected' : ''; ?>>Failed</option>
                        </select>
                        <?php if (!empty($statusFilter) || !empty($levelFilter)): ?>
                            <a href="disbursements.php" class="btn btn-sm btn-outline-secondary" title="Reset Filters"><i class="bi bi-x-circle me-1"></i>Reset</a>
                        <?php endif; ?>
                    </form>
                </div>

                <div class="card-body-custom">
                    <div class="table-responsive">
                        <table class="table table-custom align-middle">
                            <thead>
                                <tr>
                                    <th>App #</th>
                                    <th>Applicant & Level</th>
                                    <th>Disbursement Channel & Account</th>
                                    <th>Amount</th>
                                    <th>Payment Method</th>
                                    <th>Reference / Cheque</th>
                                    <th>Disbursement Date</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($disbursements)): ?>
                                    <tr>
                                        <td colspan="9" class="text-center py-5 text-muted">
                                            <i class="bi bi-receipt-cutoff fs-2 d-block mb-2 text-muted"></i>
                                            No disbursement records found matching your criteria.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($disbursements as $d):
                                        $edLevel = $d['education_level'] ?? 'university';
                                        $isSchool = ($edLevel === 'primary' || $edLevel === 'high_school');
                                        $admNum = $d['admission_number'] ?: ($d['registration_number'] ?: 'N/A');
                                    ?>
                                        <tr>
                                            <td><strong><?php echo e($d['application_number']); ?></strong></td>
                                            <td>
                                                <div class="fw-bold text-dark"><?php echo e($d['first_name'] . ' ' . $d['last_name']); ?></div>
                                                <small class="text-muted d-block">ID: <?php echo e($d['id_number']); ?></small>
                                                <?php if ($edLevel === 'primary'): ?>
                                                    <span class="badge bg-info text-dark" style="font-size: 11px;">Primary School</span>
                                                <?php elseif ($edLevel === 'high_school'): ?>
                                                    <span class="badge bg-warning text-dark" style="font-size: 11px;">High School</span>
                                                <?php else: ?>
                                                    <span class="badge bg-success" style="font-size: 11px;">University / College</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($isSchool): ?>
                                                    <!-- High School / Primary: Strictly Direct Institution Account -->
                                                    <div class="d-inline-flex align-items-center gap-1 text-primary fw-bold small">
                                                        <i class="bi bi-building-check"></i> Direct Institution Account
                                                    </div>
                                                    <div class="fw-semibold text-dark small mt-1">
                                                        <i class="bi bi-mortarboard me-1 text-muted"></i><?php echo e($d['institution_name'] ?: 'School Account'); ?>
                                                    </div>
                                                    <div class="text-muted small">
                                                        Adm No: <span class="fw-semibold text-dark"><?php echo e($admNum); ?></span>
                                                    </div>
                                                    <?php if (!empty($d['recipient_account'])): ?>
                                                        <div class="text-muted small font-monospace mt-1">
                                                            <i class="bi bi-bank me-1 text-primary"></i><?php echo e($d['recipient_account']); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <!-- University: Can be Institution Account OR Student Personal (M-Pesa / Bank) -->
                                                    <?php if ($d['recipient_type'] === 'student'): ?>
                                                        <div class="d-inline-flex align-items-center gap-1 text-success fw-bold small">
                                                            <i class="bi bi-person-check-fill"></i> Direct to Student
                                                        </div>
                                                        <div class="text-muted small mt-1">
                                                            Recipient: <span class="fw-semibold text-dark"><?php echo e($d['first_name'] . ' ' . $d['last_name']); ?></span>
                                                        </div>
                                                        <?php if (!empty($d['recipient_account'])): ?>
                                                            <div class="text-dark small font-monospace fw-semibold mt-1">
                                                                <i class="bi bi-phone me-1 text-success"></i><?php echo e($d['recipient_account']); ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <div class="d-inline-flex align-items-center gap-1 text-primary fw-bold small">
                                                            <i class="bi bi-building"></i> University Tuition Account
                                                        </div>
                                                        <div class="fw-semibold text-dark small mt-1">
                                                            <i class="bi bi-mortarboard me-1 text-muted"></i><?php echo e($d['institution_name'] ?: 'University Account'); ?>
                                                        </div>
                                                        <div class="text-muted small">
                                                            Reg No: <span class="fw-semibold text-dark"><?php echo e($admNum); ?></span>
                                                        </div>
                                                        <?php if (!empty($d['recipient_account'])): ?>
                                                            <div class="text-muted small font-monospace mt-1">
                                                                <i class="bi bi-bank me-1 text-primary"></i><?php echo e($d['recipient_account']); ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </td>
                                            <td class="fw-bold text-success fs-6"><?php echo formatMoney((float)$d['amount']); ?></td>
                                            <td>
                                                <span class="badge bg-light text-dark border">
                                                    <?php echo e($d['payment_method']); ?>
                                                </span>
                                            </td>
                                            <td><code><?php echo e($d['payment_reference']); ?></code></td>
                                            <td><?php echo $d['disbursement_date'] ? date('d M Y', strtotime($d['disbursement_date'])) : 'Pending'; ?></td>
                                            <td><?php echo renderStatusBadge($d['status']); ?></td>
                                            <td>
                                                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editDisb-<?php echo $d['id']; ?>" title="Edit Disbursement">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                            </td>
                                        </tr>

                                        <!-- EDIT DISBURSEMENT MODAL -->
                                        <div class="modal fade" id="editDisb-<?php echo $d['id']; ?>" tabindex="-1" aria-hidden="true">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <form action="disbursements.php" method="POST">
                                                        <?php echo csrfInput(); ?>
                                                        <input type="hidden" name="save_disbursement" value="1">
                                                        <input type="hidden" name="disbursement_id" value="<?php echo $d['id']; ?>">
                                                        <input type="hidden" name="recipient_type" value="<?php echo e($d['recipient_type'] ?? ($isSchool ? 'institution' : 'student')); ?>">

                                                        <div class="modal-header">
                                                            <h5 class="modal-title fw-bold">Edit Disbursement Voucher #<?php echo $d['id']; ?></h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <div class="mb-3">
                                                                <label class="form-label text-muted small mb-1">Applicant</label>
                                                                <input type="text" class="form-control bg-light" value="<?php echo e($d['first_name'] . ' ' . $d['last_name']); ?> (App: <?php echo e($d['application_number']); ?>)" readonly>
                                                            </div>

                                                            <!-- Policy Notice -->
                                                            <?php if ($isSchool): ?>
                                                                <div class="alert alert-info py-2 px-3 small mb-3">
                                                                    <div class="fw-bold text-primary mb-1"><i class="bi bi-building-check me-1"></i>Direct Institution Account (<?php echo ucfirst(str_replace('_', ' ', $edLevel)); ?>)</div>
                                                                    <div>Funds are sent directly to <strong><?php echo e($d['institution_name']); ?></strong> (Adm No: <?php echo e($admNum); ?>).</div>
                                                                </div>
                                                            <?php else: ?>
                                                                <div class="alert alert-success py-2 px-3 small mb-3">
                                                                    <div class="fw-bold text-success mb-1"><i class="bi bi-mortarboard me-1"></i>University / College Disbursement</div>
                                                                    <div>All payment channels (Institution Account, Student Bank Account, M-Pesa, or Cheque) are available.</div>
                                                                </div>
                                                            <?php endif; ?>

                                                            <div class="mb-3">
                                                                <label class="form-label fw-bold">Amount (KES) <span class="text-danger">*</span></label>
                                                                <input type="number" step="0.01" name="amount" class="form-control" value="<?php echo e($d['amount']); ?>" required>
                                                            </div>

                                                            <div class="mb-3">
                                                                <label class="form-label fw-bold">Payment Method <span class="text-danger">*</span></label>
                                                                <select name="payment_method" class="form-select" required>
                                                                    <?php if ($isSchool): ?>
                                                                        <option value="Direct Institution Account" <?php echo ($d['payment_method'] === 'Direct Institution Account') ? 'selected' : ''; ?>>Direct Institution Account</option>
                                                                        <option value="School Cheque / Treasury Voucher" <?php echo ($d['payment_method'] === 'School Cheque / Treasury Voucher' || $d['payment_method'] === 'Cheque') ? 'selected' : ''; ?>>School Cheque / Treasury Voucher</option>
                                                                        <option value="Bank Transfer / EFT (To School Account)" <?php echo ($d['payment_method'] === 'Bank Transfer / EFT (To School Account)' || $d['payment_method'] === 'Bank Transfer / EFT') ? 'selected' : ''; ?>>Bank Transfer / EFT (To School Account)</option>
                                                                        <option value="Direct Institutional Deposit" <?php echo ($d['payment_method'] === 'Direct Institutional Deposit') ? 'selected' : ''; ?>>Direct Institutional Deposit</option>
                                                                    <?php else: ?>
                                                                        <option value="Direct Institution Account" <?php echo ($d['payment_method'] === 'Direct Institution Account') ? 'selected' : ''; ?>>Direct Institution Account (University Account)</option>
                                                                        <option value="Bank Transfer / EFT (Student Personal Account)" <?php echo ($d['payment_method'] === 'Bank Transfer / EFT (Student Personal Account)') ? 'selected' : ''; ?>>Bank Transfer / EFT (Student Personal Account)</option>
                                                                        <option value="Bank Transfer / EFT (University Account)" <?php echo ($d['payment_method'] === 'Bank Transfer / EFT (University Account)' || $d['payment_method'] === 'Bank Transfer / EFT') ? 'selected' : ''; ?>>Bank Transfer / EFT (University Account)</option>
                                                                        <option value="M-Pesa / Mobile Money (Direct to Student)" <?php echo ($d['payment_method'] === 'M-Pesa / Mobile Money (Direct to Student)' || $d['payment_method'] === 'M-Pesa') ? 'selected' : ''; ?>>M-Pesa / Mobile Money (Direct to Student)</option>
                                                                        <option value="Cheque" <?php echo ($d['payment_method'] === 'Cheque') ? 'selected' : ''; ?>>Cheque</option>
                                                                        <option value="County Cashier / Voucher" <?php echo ($d['payment_method'] === 'County Cashier / Voucher') ? 'selected' : ''; ?>>County Cashier / Voucher</option>
                                                                        <option value="Direct Institutional Deposit" <?php echo ($d['payment_method'] === 'Direct Institutional Deposit') ? 'selected' : ''; ?>>Direct Institutional Deposit</option>
                                                                    <?php endif; ?>
                                                                </select>
                                                            </div>

                                                            <div class="mb-3">
                                                                <label class="form-label fw-bold">
                                                                    <?php echo $isSchool ? 'School Bank & Account No / Payee Details' : 'Recipient Account / Phone / Bank Details'; ?>
                                                                </label>
                                                                <input type="text" name="recipient_account" class="form-control" value="<?php echo e($d['recipient_account']); ?>" placeholder="<?php echo $isSchool ? 'e.g. KCB Bank Acc #12345678 (' . e($d['institution_name']) . ')' : 'e.g. M-Pesa 07XX-XXX-XXX or Bank Account'; ?>">
                                                            </div>

                                                            <div class="mb-3">
                                                                <label class="form-label fw-bold">Payment Reference / Cheque No <span class="text-danger">*</span></label>
                                                                <input type="text" name="payment_reference" class="form-control" value="<?php echo e($d['payment_reference']); ?>" required>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label fw-bold">Disbursement Date</label>
                                                                <input type="date" name="disbursement_date" class="form-control" value="<?php echo e($d['disbursement_date']); ?>">
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label fw-bold">Status <span class="text-danger">*</span></label>
                                                                <select name="status" class="form-select" required>
                                                                    <option value="completed" <?php echo ($d['status'] === 'completed') ? 'selected' : ''; ?>>Completed (Funds Dispatched)</option>
                                                                    <option value="processing" <?php echo ($d['status'] === 'processing') ? 'selected' : ''; ?>>Processing</option>
                                                                    <option value="pending" <?php echo ($d['status'] === 'pending') ? 'selected' : ''; ?>>Pending</option>
                                                                    <option value="failed" <?php echo ($d['status'] === 'failed') ? 'selected' : ''; ?>>Failed</option>
                                                                </select>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label">Notes</label>
                                                                <textarea name="notes" rows="2" class="form-control"><?php echo e($d['notes']); ?></textarea>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                                                            <button type="submit" class="btn btn-green btn-sm">Update Disbursement</button>
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

    <!-- NEW DISBURSEMENT MODAL -->
    <?php if (!empty($allocationsForDisb)): ?>
        <div class="modal fade" id="newDisbModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form action="disbursements.php" method="POST" id="newDisbForm">
                        <?php echo csrfInput(); ?>
                        <input type="hidden" name="save_disbursement" value="1">
                        <input type="hidden" name="recipient_type" id="newDisbRecipientType" value="institution">

                        <div class="modal-header">
                            <h5 class="modal-title fw-bold">Record Bursary Disbursement</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Select Allocation <span class="text-danger">*</span></label>
                                <select name="allocation_id" id="newDisbAllocationSelect" class="form-select" required onchange="handleAllocationSelection(this)">
                                    <option value="">-- Choose Allocation --</option>
                                    <?php foreach ($allocationsForDisb as $af):
                                        $remaining = (float)$af['approved_amount'] - (float)$af['already_disbursed'];
                                        $sel = ($preAllocId && $preAllocId == $af['id']) ? 'selected' : '';
                                        $level = $af['education_level'] ?? 'university';
                                        $inst = $af['institution_name'] ?: 'School/Institution';
                                        $adm = $af['admission_number'] ?: ($af['registration_number'] ?: 'N/A');
                                    ?>
                                        <option value="<?php echo $af['id']; ?>"
                                                data-level="<?php echo e($level); ?>"
                                                data-institution="<?php echo e($inst); ?>"
                                                data-admission="<?php echo e($adm); ?>"
                                                data-remaining="<?php echo $remaining; ?>"
                                                data-phone="<?php echo e($af['phone'] ?? ''); ?>"
                                                data-applicant="<?php echo e($af['first_name'] . ' ' . $af['last_name']); ?>"
                                                <?php echo $sel; ?>>
                                            <?php echo e($af['application_number']); ?> - <?php echo e($af['first_name'] . ' ' . $af['last_name']); ?> [<?php echo ucfirst(str_replace('_', ' ', $level)); ?>] (Remaining: <?php echo formatMoney($remaining); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Dynamic Education Level Channel Banner -->
                            <div id="channelNoticeBox" class="p-3 rounded mb-3" style="display: none;"></div>

                            <div class="mb-3">
                                <label class="form-label fw-bold">Disbursement Amount (KES) <span class="text-danger">*</span></label>
                                <input type="number" step="0.01" min="100" name="amount" id="newDisbAmount" class="form-control" required placeholder="e.g. 20000">
                                <small class="text-muted" id="amountHelperText"></small>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-bold" id="payMethodLabel">Payment Method <span class="text-danger">*</span></label>
                                <select name="payment_method" id="newDisbPayMethod" class="form-select" required onchange="handlePaymentMethodChange(this)">
                                    <option value="Direct Institution Account">Direct Institution Account</option>
                                    <option value="School Cheque / Treasury Voucher">School Cheque / Treasury Voucher</option>
                                    <option value="Bank Transfer / EFT (To School Account)">Bank Transfer / EFT (To School Account)</option>
                                    <option value="Direct Institutional Deposit">Direct Institutional Deposit</option>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-bold" id="recipientAccountLabel">School Bank & Account No / Cheque Payee Details <span class="text-danger">*</span></label>
                                <input type="text" name="recipient_account" id="newDisbRecipientAccount" class="form-control" placeholder="e.g. Equity Bank Acc #12345678 (Kiamaina Primary School)" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-bold">Payment Reference / Cheque No <span class="text-danger">*</span></label>
                                <input type="text" name="payment_reference" class="form-control" required placeholder="e.g. CHQ-928401 or EFT-2026-384">
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold">Disbursement Date</label>
                                <input type="date" name="disbursement_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold">Status <span class="text-danger">*</span></label>
                                <select name="status" class="form-select" required>
                                    <option value="completed">Completed (Release Payment)</option>
                                    <option value="processing">Processing</option>
                                    <option value="pending">Pending</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Notes</label>
                                <textarea name="notes" rows="2" class="form-control" placeholder="Optional voucher notes..."></textarea>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-green btn-sm">Process & Save Disbursement</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script>
        function handleAllocationSelection(selectEl) {
            const selectedOpt = selectEl.options[selectEl.selectedIndex];
            const noticeBox = document.getElementById('channelNoticeBox');
            const payMethodSelect = document.getElementById('newDisbPayMethod');
            const recipientTypeInput = document.getElementById('newDisbRecipientType');
            const recipientAccountLabel = document.getElementById('recipientAccountLabel');
            const recipientAccountInput = document.getElementById('newDisbRecipientAccount');
            const amountInput = document.getElementById('newDisbAmount');
            const helperText = document.getElementById('amountHelperText');

            if (!selectedOpt || !selectedOpt.value) {
                noticeBox.style.display = 'none';
                return;
            }

            const level = selectedOpt.getAttribute('data-level');
            const inst = selectedOpt.getAttribute('data-institution') || 'School/Institution';
            const adm = selectedOpt.getAttribute('data-admission') || 'N/A';
            const remaining = selectedOpt.getAttribute('data-remaining');
            const phone = selectedOpt.getAttribute('data-phone') || '';

            if (remaining && !amountInput.value) {
                amountInput.value = parseFloat(remaining).toFixed(2);
            }
            if (remaining) {
                helperText.textContent = `Remaining allocation to disburse: KES ${Number(remaining).toLocaleString()}`;
            }

            noticeBox.style.display = 'block';

            if (level === 'primary' || level === 'high_school') {
                // PRIMARY & HIGH SCHOOL: Mandatory Direct Institution Account
                recipientTypeInput.value = 'institution';
                noticeBox.className = 'alert alert-info py-2 px-3 small mb-3 border-start border-4 border-primary';
                noticeBox.innerHTML = `
                    <div class="fw-bold text-primary mb-1"><i class="bi bi-building-check me-1"></i>Direct Institution Account Required (${level === 'primary' ? 'Primary School' : 'High School'})</div>
                    <div>Policy: Funds must be remitted directly to the school account or issued via school cheque.</div>
                    <div class="mt-1"><strong>School:</strong> ${inst} • <strong>Admission No:</strong> ${adm}</div>
                `;

                // Set options for Primary & High School
                payMethodSelect.innerHTML = `
                    <option value="Direct Institution Account">Direct Institution Account (School Bank Transfer/EFT)</option>
                    <option value="School Cheque / Treasury Voucher">School Cheque / Treasury Voucher</option>
                    <option value="Direct Institutional Deposit">Direct Institutional Deposit</option>
                    <option value="Bank Transfer / EFT (To School Account)">Bank Transfer / EFT (To School Account)</option>
                `;

                recipientAccountLabel.innerHTML = `School Bank & Account No / Cheque Payee Details <span class="text-danger">*</span>`;
                recipientAccountInput.placeholder = `e.g. KCB Bank Acc #12345678 (${inst})`;
                if (!recipientAccountInput.value) {
                    recipientAccountInput.value = `${inst} Account (Adm: ${adm})`;
                }

            } else {
                // UNIVERSITY: All options available
                recipientTypeInput.value = 'institution';
                noticeBox.className = 'alert alert-success py-2 px-3 small mb-3 border-start border-4 border-success';
                noticeBox.innerHTML = `
                    <div class="fw-bold text-success mb-1"><i class="bi bi-mortarboard-fill me-1"></i>All Disbursement Options Available (University / College)</div>
                    <div>University students can be paid via University Tuition Account, Student Bank Account, M-Pesa, or Cheque.</div>
                    <div class="mt-1"><strong>University:</strong> ${inst} • <strong>Reg No:</strong> ${adm}</div>
                `;

                // Set full range of options for University
                payMethodSelect.innerHTML = `
                    <option value="Direct Institution Account">Direct Institution Account (University Fees Account)</option>
                    <option value="Bank Transfer / EFT (Student Personal Account)">Bank Transfer / EFT (Student Personal Account)</option>
                    <option value="Bank Transfer / EFT (University Account)">Bank Transfer / EFT (University Account)</option>
                    <option value="M-Pesa / Mobile Money (Direct to Student)">M-Pesa / Mobile Money (Direct to Student)</option>
                    <option value="Cheque (Payable to University)">Cheque (Payable to University)</option>
                    <option value="Cheque (Payable to Student)">Cheque (Payable to Student)</option>
                    <option value="County Cashier / Voucher">County Cashier / Voucher</option>
                `;

                recipientAccountLabel.innerHTML = `Recipient Account / Phone / Bank Details <span class="text-danger">*</span>`;
                recipientAccountInput.placeholder = `e.g. M-Pesa 07XX-XXX-XXX or University Bank Account`;
                if (!recipientAccountInput.value) {
                    recipientAccountInput.value = `${inst} Tuition Account (Reg: ${adm})`;
                }
            }
        }

        function handlePaymentMethodChange(methodSelect) {
            const val = methodSelect.value;
            const recipientTypeInput = document.getElementById('newDisbRecipientType');
            const recipientAccountLabel = document.getElementById('recipientAccountLabel');
            const recipientAccountInput = document.getElementById('newDisbRecipientAccount');

            const selectEl = document.getElementById('newDisbAllocationSelect');
            const selectedOpt = selectEl.options[selectEl.selectedIndex];
            const phone = selectedOpt ? selectedOpt.getAttribute('data-phone') : '';
            const inst = selectedOpt ? selectedOpt.getAttribute('data-institution') : 'University';
            const adm = selectedOpt ? selectedOpt.getAttribute('data-admission') : '';

            if (val.includes('M-Pesa')) {
                recipientTypeInput.value = 'student';
                recipientAccountLabel.innerHTML = `Student M-Pesa Phone Number <span class="text-danger">*</span>`;
                recipientAccountInput.placeholder = `e.g. 0712345678`;
                if (phone) recipientAccountInput.value = phone;
            } else if (val.includes('Student Personal')) {
                recipientTypeInput.value = 'student';
                recipientAccountLabel.innerHTML = `Student Bank Name & Account Number <span class="text-danger">*</span>`;
                recipientAccountInput.placeholder = `e.g. Equity Bank Acc #1100223344`;
            } else {
                recipientTypeInput.value = 'institution';
                recipientAccountLabel.innerHTML = `Institution Bank & Account Number / Payee Details <span class="text-danger">*</span>`;
                recipientAccountInput.placeholder = `e.g. Co-op Bank Acc #0112938475 (${inst})`;
            }
        }

        // Auto initialize if allocation pre-selected via query string
        document.addEventListener('DOMContentLoaded', function() {
            const selectEl = document.getElementById('newDisbAllocationSelect');
            if (selectEl && selectEl.value) {
                handleAllocationSelection(selectEl);
                <?php if (!empty($preAllocId)): ?>
                const modalEl = document.getElementById('newDisbModal');
                if (modalEl) {
                    const disbModal = new bootstrap.Modal(modalEl);
                    disbModal.show();
                }
                <?php endif; ?>
            }
        });
        </script>
    <?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
