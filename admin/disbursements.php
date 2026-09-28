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
        $disbId    = !empty($_POST['disbursement_id']) ? (int)$_POST['disbursement_id'] : null;
        $allocId   = !empty($_POST['allocation_id']) ? (int)$_POST['allocation_id'] : null;
        $amount    = !empty($_POST['amount']) ? (float)$_POST['amount'] : null;
        $disbDate  = !empty($_POST['disbursement_date']) ? $_POST['disbursement_date'] : date('Y-m-d');
        $payRef    = trim($_POST['payment_reference'] ?? '');
        $payMethod = trim($_POST['payment_method'] ?? 'Bank Transfer');
        $status    = trim($_POST['status'] ?? 'pending');
        $notes     = trim($_POST['notes'] ?? '');

        if (!$amount || $amount <= 0) {
            $errors[] = 'Please enter a valid disbursement amount.';
        }
        if (empty($payRef)) {
            $errors[] = 'Payment reference / Cheque / Transaction number is required.';
        }
        if (!in_array($status, ['pending', 'processing', 'completed', 'failed'])) {
            $errors[] = 'Invalid disbursement status.';
        }

        if (empty($errors)) {
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
                            status = ?,
                            notes = ?,
                            updated_at = NOW()
                        WHERE id = ?
                    ");
                    $upStmt->execute([$amount, $disbDate, $payRef, $payMethod, $status, $notes ?: null, $disbId]);
                } else {
                    if (!$allocId) {
                        throw new Exception('Allocation ID is required.');
                    }
                    // Insert
                    $insStmt = $pdo->prepare("
                        INSERT INTO disbursements (
                            allocation_id, amount, disbursement_date, payment_reference,
                            payment_method, status, notes, created_at
                        ) VALUES (
                            ?, ?, ?, ?,
                            ?, ?, ?, NOW()
                        )
                    ");
                    $insStmt->execute([$allocId, $amount, $disbDate, $payRef, $payMethod, $status, $notes ?: null]);
                }

                // Get allocation details & update allocation status based on cumulative completed disbursements
                $targetAllocId = $allocId;
                if (!$targetAllocId && $disbId) {
                    $targetAllocId = (int)$pdo->query("SELECT allocation_id FROM disbursements WHERE id = $disbId")->fetchColumn();
                }

                $allocStmt = $pdo->prepare("
                    SELECT al.*, a.application_number, ap.user_id, ap.first_name, ap.last_name
                    FROM allocations al
                    INNER JOIN applications a ON al.application_id = a.id
                    INNER JOIN applicants ap ON a.applicant_id = ap.id
                    WHERE al.id = ?
                ");
                $allocStmt->execute([$targetAllocId]);
                $allocInfo = $allocStmt->fetch();

                if ($allocInfo) {
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

                    // If completed, notify applicant
                    if ($status === 'completed') {
                        createNotification(
                            $pdo,
                            (int)$allocInfo['user_id'],
                            'Bursary Disbursement Processed',
                            "A bursary payment of " . formatMoney($amount) . " has been completed for your application ({$allocInfo['application_number']}) via {$payMethod}. Ref: {$payRef}."
                        );
                    }
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
$sql = "
    SELECT d.*, al.approved_amount, a.application_number,
           ap.first_name, ap.last_name, ap.id_number,
           p.name AS program_name
    FROM disbursements d
    INNER JOIN allocations al ON d.allocation_id = al.id
    INNER JOIN applications a ON al.application_id = a.id
    INNER JOIN applicants ap ON a.applicant_id = ap.id
    LEFT JOIN programs p ON a.program_id = p.id
    WHERE 1=1
";
$params = [];
if (!empty($statusFilter)) {
    $sql .= " AND d.status = ?";
    $params[] = $statusFilter;
}
$sql .= " ORDER BY d.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$disbursements = $stmt->fetchAll();

// Allocations available for new disbursement
$allocationsForDisb = $pdo->query("
    SELECT al.id, al.approved_amount, a.application_number, ap.first_name, ap.last_name,
           (SELECT COALESCE(SUM(amount), 0) FROM disbursements WHERE allocation_id = al.id AND status = 'completed') AS already_disbursed
    FROM allocations al
    INNER JOIN applications a ON al.application_id = a.id
    INNER JOIN applicants ap ON a.applicant_id = ap.id
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

            <div class="card-custom">
                <div class="card-header-custom">
                    <div>
                        <h5 class="fw-bold mb-1"><i class="bi bi-credit-card-fill text-success me-2"></i>Disbursement Vouchers & Cheques</h5>
                        <p class="text-muted small mb-0">Record and track payment releases, bank vouchers, EFTs, and cheque numbers sent to institutions.</p>
                    </div>

                    <form action="disbursements.php" method="GET" class="d-flex align-items-center gap-2">
                        <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="">All Payment Statuses</option>
                            <option value="pending" <?php echo ($statusFilter === 'pending') ? 'selected' : ''; ?>>Pending</option>
                            <option value="processing" <?php echo ($statusFilter === 'processing') ? 'selected' : ''; ?>>Processing</option>
                            <option value="completed" <?php echo ($statusFilter === 'completed') ? 'selected' : ''; ?>>Completed</option>
                            <option value="failed" <?php echo ($statusFilter === 'failed') ? 'selected' : ''; ?>>Failed</option>
                        </select>
                        <?php if (!empty($statusFilter)): ?>
                            <a href="disbursements.php" class="btn btn-sm btn-outline-secondary" title="Reset"><i class="bi bi-x"></i></a>
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
                                    <th>Amount</th>
                                    <th>Payment Method</th>
                                    <th>Payment Ref / Cheque</th>
                                    <th>Disbursement Date</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($disbursements)): ?>
                                    <tr>
                                        <td colspan="8" class="text-center py-5 text-muted">
                                            <i class="bi bi-receipt-cutoff fs-2 d-block mb-2 text-muted"></i>
                                            No disbursement records found. Click "Record Disbursement" to release funds.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($disbursements as $d): ?>
                                        <tr>
                                            <td><strong><?php echo e($d['application_number']); ?></strong></td>
                                            <td>
                                                <div class="fw-bold text-dark"><?php echo e($d['first_name'] . ' ' . $d['last_name']); ?></div>
                                                <small class="text-muted">ID: <?php echo e($d['id_number']); ?></small>
                                            </td>
                                            <td class="fw-bold text-success"><?php echo formatMoney((float)$d['amount']); ?></td>
                                            <td><?php echo e($d['payment_method']); ?></td>
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

                                                        <div class="modal-header">
                                                            <h5 class="modal-title fw-bold">Edit Disbursement Voucher #<?php echo $d['id']; ?></h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <div class="mb-3">
                                                                <label class="form-label text-muted small mb-1">Applicant</label>
                                                                <input type="text" class="form-control bg-light" value="<?php echo e($d['first_name'] . ' ' . $d['last_name']); ?> (App: <?php echo e($d['application_number']); ?>)" readonly>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label fw-bold">Amount (KES) <span class="text-danger">*</span></label>
                                                                <input type="number" step="0.01" name="amount" class="form-control" value="<?php echo e($d['amount']); ?>" required>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label fw-bold">Payment Method <span class="text-danger">*</span></label>
                                                                <select name="payment_method" class="form-select" required>
                                                                    <option value="Cheque" <?php echo ($d['payment_method'] === 'Cheque') ? 'selected' : ''; ?>>Cheque</option>
                                                                    <option value="Bank Transfer / EFT" <?php echo ($d['payment_method'] === 'Bank Transfer / EFT') ? 'selected' : ''; ?>>Bank Transfer / EFT</option>
                                                                    <option value="M-Pesa" <?php echo ($d['payment_method'] === 'M-Pesa') ? 'selected' : ''; ?>>M-Pesa</option>
                                                                    <option value="Direct Institutional Deposit" <?php echo ($d['payment_method'] === 'Direct Institutional Deposit') ? 'selected' : ''; ?>>Direct Institutional Deposit</option>
                                                                </select>
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
                    <form action="disbursements.php" method="POST">
                        <?php echo csrfInput(); ?>
                        <input type="hidden" name="save_disbursement" value="1">

                        <div class="modal-header">
                            <h5 class="modal-title fw-bold">Record Bursary Disbursement</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Select Allocation <span class="text-danger">*</span></label>
                                <select name="allocation_id" class="form-select" required>
                                    <option value="">-- Choose Allocation --</option>
                                    <?php foreach ($allocationsForDisb as $af):
                                        $remaining = (float)$af['approved_amount'] - (float)$af['already_disbursed'];
                                        $sel = ($preAllocId && $preAllocId == $af['id']) ? 'selected' : '';
                                    ?>
                                        <option value="<?php echo $af['id']; ?>" <?php echo $sel; ?>>
                                            <?php echo e($af['application_number']); ?> - <?php echo e($af['first_name'] . ' ' . $af['last_name']); ?> (Approved: <?php echo formatMoney((float)$af['approved_amount']); ?>, Remaining: <?php echo formatMoney($remaining); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold">Disbursement Amount (KES) <span class="text-danger">*</span></label>
                                <input type="number" step="0.01" min="100" name="amount" class="form-control" required placeholder="e.g. 20000">
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold">Payment Method <span class="text-danger">*</span></label>
                                <select name="payment_method" class="form-select" required>
                                    <option value="Cheque">Cheque</option>
                                    <option value="Bank Transfer / EFT">Bank Transfer / EFT</option>
                                    <option value="M-Pesa">M-Pesa</option>
                                    <option value="Direct Institutional Deposit">Direct Institutional Deposit</option>
                                </select>
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
                            <button type="submit" class="btn btn-green btn-sm">Process & Save</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

