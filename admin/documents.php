<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('super_admin');

$errors = [];

// Handle Download / View File
if (isset($_GET['download']) && !empty($_GET['id'])) {
    $docId = (int)$_GET['id'];
    $stmt = $pdo->prepare("SELECT * FROM documents WHERE id = ? LIMIT 1");
    $stmt->execute([$docId]);
    $doc = $stmt->fetch();

    if ($doc && file_exists($doc['file_path'])) {
        header('Content-Description: File Transfer');
        header('Content-Type: ' . ($doc['file_type'] ?: 'application/octet-stream'));
        header('Content-Disposition: inline; filename="' . basename($doc['original_filename']) . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($doc['file_path']));
        readfile($doc['file_path']);
        exit;
    } else {
        setFlash('danger', 'The requested file could not be found on the server.');
        header('Location: documents.php');
        exit;
    }
}

// Handle Document Verification / Rejection Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['review_doc'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token. Please try again.';
    } else {
        $docId   = (int)$_POST['document_id'];
        $status  = trim($_POST['status'] ?? '');
        $comment = trim($_POST['admin_comment'] ?? '');

        if (!in_array($status, ['submitted', 'under_review', 'verified', 'rejected'])) {
            $errors[] = 'Invalid document status.';
        } elseif ($status === 'rejected' && empty($comment)) {
            $errors[] = 'Please provide an explanatory comment when rejecting a document.';
        } else {
            try {
                // Fetch applicant user id
                $check = $pdo->prepare("
                    SELECT d.*, ap.user_id, ap.first_name, ap.last_name
                    FROM documents d
                    INNER JOIN applicants ap ON d.applicant_id = ap.id
                    WHERE d.id = ?
                ");
                $check->execute([$docId]);
                $docData = $check->fetch();

                if (!$docData) {
                    throw new Exception('Document record not found.');
                }

                $upStmt = $pdo->prepare("
                    UPDATE documents SET
                        status = ?,
                        admin_comment = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $upStmt->execute([
                    $status,
                    $comment ?: null,
                    $docId
                ]);

                // Notify applicant if rejected or verified
                $readableType = ucwords(str_replace('_', ' ', $docData['document_type']));
                if ($status === 'rejected') {
                    createNotification(
                        $pdo,
                        (int)$docData['user_id'],
                        "Document Rejected: {$readableType}",
                        "Your uploaded {$readableType} was rejected. Reason: {$comment}. Please re-upload a clear copy in your Documents portal."
                    );
                } elseif ($status === 'verified') {
                    createNotification(
                        $pdo,
                        (int)$docData['user_id'],
                        "Document Verified: {$readableType}",
                        "Your uploaded {$readableType} has been verified and accepted by the administration."
                    );
                }

                setFlash('success', "Document '{$readableType}' updated to " . ucfirst(str_replace('_', ' ', $status)) . ".");
                header('Location: documents.php');
                exit;

            } catch (Exception $e) {
                $errors[] = 'Database error: ' . $e->getMessage();
            }
        }
    }
}

// Search and Filter
$statusFilter = trim($_GET['status'] ?? '');
$typeFilter   = trim($_GET['type'] ?? '');
$search       = trim($_GET['search'] ?? '');

$sql = "
    SELECT d.*, ap.first_name, ap.last_name, ap.id_number, ap.phone,
           a.application_number
    FROM documents d
    INNER JOIN applicants ap ON d.applicant_id = ap.id
    LEFT JOIN applications a ON d.application_id = a.id
    WHERE 1=1
";
$params = [];

if (!empty($statusFilter)) {
    $sql .= " AND d.status = ?";
    $params[] = $statusFilter;
}
if (!empty($typeFilter)) {
    $sql .= " AND d.document_type = ?";
    $params[] = $typeFilter;
}
if (!empty($search)) {
    $sql .= " AND (ap.first_name LIKE ? OR ap.last_name LIKE ? OR ap.id_number LIKE ? OR d.original_filename LIKE ?)";
    $term = "%$search%";
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}
$sql .= " ORDER BY d.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$documents = $stmt->fetchAll();

$pageTitle = 'Document Review Portal';
$activeNav = 'documents';
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
            <span class="topbar-title">Document Verification & Review</span>
        </div>
        <div class="topbar-user">
            <span class="text-muted d-none d-md-inline">Administrator:</span>
            <strong><?php echo e($_SESSION['username'] ?? 'Admin'); ?></strong>
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
                        <h5 class="fw-bold mb-1"><i class="bi bi-file-earmark-check-fill text-success me-2"></i>Submitted Applicant Documents</h5>
                        <p class="text-muted small mb-0">Inspect uploaded fee structures, IDs, and transcripts to verify authenticity.</p>
                    </div>
                    <span class="badge bg-success fs-6"><?php echo count($documents); ?> Documents</span>
                </div>

                <div class="card-body-custom">
                    <!-- SEARCH & FILTER -->
                    <form action="documents.php" method="GET" class="row g-3 mb-4">
                        <div class="col-md-4">
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="bi bi-search"></i></span>
                                <input type="text" name="search" class="form-control" placeholder="Search applicant, ID, filename..." value="<?php echo e($search); ?>">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <select name="status" class="form-select">
                                <option value="">-- All Statuses --</option>
                                <option value="submitted" <?php echo ($statusFilter === 'submitted') ? 'selected' : ''; ?>>Submitted</option>
                                <option value="under_review" <?php echo ($statusFilter === 'under_review') ? 'selected' : ''; ?>>Under Review</option>
                                <option value="verified" <?php echo ($statusFilter === 'verified') ? 'selected' : ''; ?>>Verified</option>
                                <option value="rejected" <?php echo ($statusFilter === 'rejected') ? 'selected' : ''; ?>>Rejected</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <select name="type" class="form-select">
                                <option value="">-- All Document Types --</option>
                                <option value="passport_photo" <?php echo ($typeFilter === 'passport_photo') ? 'selected' : ''; ?>>Passport Photo</option>
                                <option value="identification" <?php echo ($typeFilter === 'identification') ? 'selected' : ''; ?>>National ID / Birth Cert</option>
                                <option value="fee_structure" <?php echo ($typeFilter === 'fee_structure') ? 'selected' : ''; ?>>Fee Structure</option>
                                <option value="admission_proof" <?php echo ($typeFilter === 'admission_proof') ? 'selected' : ''; ?>>Admission Proof</option>
                                <option value="school_details" <?php echo ($typeFilter === 'school_details') ? 'selected' : ''; ?>>School Details / Report</option>
                                <option value="guardian_identification" <?php echo ($typeFilter === 'guardian_identification') ? 'selected' : ''; ?>>Guardian ID</option>
                                <option value="supporting_document" <?php echo ($typeFilter === 'supporting_document') ? 'selected' : ''; ?>>Other Supporting</option>
                            </select>
                        </div>
                        <div class="col-md-2 d-flex gap-2">
                            <button type="submit" class="btn btn-green w-100">Filter</button>
                            <?php if (!empty($search) || !empty($statusFilter) || !empty($typeFilter)): ?>
                                <a href="documents.php" class="btn btn-outline-secondary" title="Reset"><i class="bi bi-arrow-counterclockwise"></i></a>
                            <?php endif; ?>
                        </div>
                    </form>

                    <!-- TABLE -->
                    <div class="table-responsive">
                        <table class="table table-custom">
                            <thead>
                                <tr>
                                    <th>Applicant Name</th>
                                    <th>Document Type</th>
                                    <th>File Details</th>
                                    <th>Status</th>
                                    <th>Admin Notes</th>
                                    <th>Uploaded</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($documents)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-5 text-muted">
                                            <i class="bi bi-file-earmark-x fs-2 d-block mb-2 text-muted"></i>
                                            No documents found matching the criteria.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($documents as $doc): ?>
                                        <tr>
                                            <td>
                                                <div class="fw-bold text-dark"><?php echo e($doc['first_name'] . ' ' . $doc['last_name']); ?></div>
                                                <small class="text-muted">ID: <?php echo e($doc['id_number']); ?></small>
                                            </td>
                                            <td>
                                                <span class="badge bg-light text-dark border">
                                                    <?php echo e(ucwords(str_replace('_', ' ', $doc['document_type']))); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-center gap-2">
                                                    <i class="bi bi-file-earmark text-primary"></i>
                                                    <span class="small text-truncate" style="max-width: 170px;" title="<?php echo e($doc['original_filename']); ?>">
                                                        <?php echo e($doc['original_filename']); ?>
                                                    </span>
                                                </div>
                                                <small class="text-muted"><?php echo round(($doc['file_size'] ?? 0) / 1024, 1); ?> KB</small>
                                            </td>
                                            <td><?php echo renderStatusBadge($doc['status']); ?></td>
                                            <td>
                                                <?php if (!empty($doc['admin_comment'])): ?>
                                                    <small class="text-muted d-block" style="max-width: 180px;"><?php echo e($doc['admin_comment']); ?></small>
                                                <?php else: ?>
                                                    <span class="text-muted small">&mdash;</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="small text-muted"><?php echo date('d M Y', strtotime($doc['uploaded_at'])); ?></td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <!-- View / Download -->
                                                    <a href="documents.php?download=1&id=<?php echo $doc['id']; ?>" target="_blank" class="btn btn-outline-primary" title="View / Download File">
                                                        <i class="bi bi-box-arrow-up-right"></i>
                                                    </a>
                                                    <!-- Review Modal Trigger -->
                                                    <button type="button" class="btn btn-outline-success" data-bs-toggle="modal" data-bs-target="#reviewModal-<?php echo $doc['id']; ?>" title="Change Status / Comment">
                                                        <i class="bi bi-pencil-square"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>

                                        <!-- MODAL REVIEW -->
                                        <div class="modal fade" id="reviewModal-<?php echo $doc['id']; ?>" tabindex="-1" aria-hidden="true">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <form action="documents.php" method="POST">
                                                        <?php echo csrfInput(); ?>
                                                        <input type="hidden" name="review_doc" value="1">
                                                        <input type="hidden" name="document_id" value="<?php echo $doc['id']; ?>">

                                                        <div class="modal-header">
                                                            <h5 class="modal-title fw-bold">Review Document</h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <div class="mb-3">
                                                                <label class="form-label text-muted small mb-1">Applicant</label>
                                                                <h6><?php echo e($doc['first_name'] . ' ' . $doc['last_name']); ?> (ID: <?php echo e($doc['id_number']); ?>)</h6>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label text-muted small mb-1">Document</label>
                                                                <div><strong><?php echo e(ucwords(str_replace('_', ' ', $doc['document_type']))); ?></strong></div>
                                                                <small class="text-muted"><?php echo e($doc['original_filename']); ?></small>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label fw-bold">Verification Decision <span class="text-danger">*</span></label>
                                                                <select name="status" class="form-select" required>
                                                                    <option value="verified" <?php echo ($doc['status'] === 'verified') ? 'selected' : ''; ?>>Verified (Approve Document)</option>
                                                                    <option value="under_review" <?php echo ($doc['status'] === 'under_review') ? 'selected' : ''; ?>>Under Review</option>
                                                                    <option value="rejected" <?php echo ($doc['status'] === 'rejected') ? 'selected' : ''; ?>>Reject Document (Requires Resubmission)</option>
                                                                    <option value="submitted" <?php echo ($doc['status'] === 'submitted') ? 'selected' : ''; ?>>Mark as Submitted</option>
                                                                </select>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label fw-bold">Admin Feedback / Reason</label>
                                                                <textarea name="admin_comment" rows="3" class="form-control" placeholder="Provide feedback or reason if rejecting..."><?php echo e($doc['admin_comment'] ?? ''); ?></textarea>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                                                            <button type="submit" class="btn btn-green btn-sm">Save & Notify Applicant</button>
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

