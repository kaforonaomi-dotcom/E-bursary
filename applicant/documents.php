<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('applicant');

$userId = (int)$_SESSION['user_id'];
$applicant = getCurrentApplicant($pdo);

if (!$applicant) {
    header('Location: profile.php');
    exit;
}

// Document definitions and their target folders
$docTypes = [
    'passport_photo' => [
        'title'       => 'Passport Photo',
        'description' => 'Recent clear passport-size photograph (JPG, PNG).',
        'folder'      => __DIR__ . '/../uploads/passports/',
        'allowed'     => ['jpg', 'jpeg', 'png'],
        'required'    => true
    ],
    'identification' => [
        'title'       => 'National ID / Birth Certificate',
        'description' => 'Applicant National ID or Birth Certificate (PDF, JPG, PNG).',
        'folder'      => __DIR__ . '/../uploads/identification/',
        'allowed'     => ['pdf', 'jpg', 'jpeg', 'png'],
        'required'    => true
    ],
    'fee_structure' => [
        'title'       => 'Current Fee Structure / Fee Statement',
        'description' => 'Official fee statement from your institution indicating outstanding balance.',
        'folder'      => __DIR__ . '/../uploads/fee-structures/',
        'allowed'     => ['pdf', 'jpg', 'jpeg', 'png'],
        'required'    => true
    ],
    'admission_proof' => [
        'title'       => 'Admission Letter / Student ID Card',
        'description' => 'Proof of admission or current student identification card.',
        'folder'      => __DIR__ . '/../uploads/supporting-documents/',
        'allowed'     => ['pdf', 'jpg', 'jpeg', 'png'],
        'required'    => true
    ],
    'guardian_identification' => [
        'title'       => 'Parent / Guardian National ID',
        'description' => 'Identification card of parent or legal guardian.',
        'folder'      => __DIR__ . '/../uploads/identification/',
        'allowed'     => ['pdf', 'jpg', 'jpeg', 'png'],
        'required'    => false
    ],
    'school_details' => [
        'title'       => 'Academic Report Card / Transcript',
        'description' => 'Latest academic results, report card or university transcript.',
        'folder'      => __DIR__ . '/../uploads/supporting-documents/',
        'allowed'     => ['pdf', 'jpg', 'jpeg', 'png'],
        'required'    => false
    ],
    'supporting_document' => [
        'title'       => 'Other Supporting Documents',
        'description' => 'Death certificate of parent (if orphan), medical reports, or recommendation letters.',
        'folder'      => __DIR__ . '/../uploads/supporting-documents/',
        'allowed'     => ['pdf', 'jpg', 'jpeg', 'png'],
        'required'    => false
    ]
];

$errors = [];

// Handle Document Upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_doc'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token. Please refresh and try again.';
    } else {
        $type = trim($_POST['document_type'] ?? '');
        if (!isset($docTypes[$type])) {
            $errors[] = 'Invalid document type selected.';
        } elseif (!isset($_FILES['document_file']) || $_FILES['document_file']['error'] === UPLOAD_ERR_NO_FILE) {
            $errors[] = 'Please select a file to upload.';
        } else {
            $config = $docTypes[$type];
            $result = handleFileUpload(
                $_FILES['document_file'],
                $config['allowed'],
                $config['folder'],
                5242880 // 5MB limit
            );

            if (!$result['success']) {
                $errors[] = $result['error'];
            } else {
                try {
                    // Check if a document record of this type already exists for this applicant
                    $checkStmt = $pdo->prepare("SELECT id, file_path FROM documents WHERE applicant_id = ? AND document_type = ? LIMIT 1");
                    $checkStmt->execute([$applicant['id'], $type]);
                    $existingDoc = $checkStmt->fetch();

                    if ($existingDoc) {
                        // Delete old file if exists on disk
                        if (!empty($existingDoc['file_path']) && file_exists($existingDoc['file_path'])) {
                            @unlink($existingDoc['file_path']);
                        }

                        // Update record
                        $updateStmt = $pdo->prepare("
                            UPDATE documents SET
                                original_filename = ?,
                                stored_filename = ?,
                                file_path = ?,
                                file_type = ?,
                                file_size = ?,
                                status = 'submitted',
                                admin_comment = NULL,
                                uploaded_at = NOW()
                            WHERE id = ? AND applicant_id = ?
                        ");
                        $updateStmt->execute([
                            $result['original_name'],
                            $result['stored_filename'],
                            $result['file_path'],
                            $result['file_type'],
                            $result['file_size'],
                            $existingDoc['id'],
                            $applicant['id']
                        ]);
                    } else {
                        // Insert new record
                        $insertStmt = $pdo->prepare("
                            INSERT INTO documents (
                                applicant_id, document_type, original_filename, stored_filename,
                                file_path, file_type, file_size, status, uploaded_at
                            ) VALUES (
                                ?, ?, ?, ?,
                                ?, ?, ?, 'submitted', NOW()
                            )
                        ");
                        $insertStmt->execute([
                            $applicant['id'],
                            $type,
                            $result['original_name'],
                            $result['stored_filename'],
                            $result['file_path'],
                            $result['file_type'],
                            $result['file_size']
                        ]);
                    }

                    setFlash('success', $config['title'] . ' uploaded successfully!');
                    header('Location: documents.php');
                    exit;

                } catch (PDOException $e) {
                    $errors[] = 'Database error: ' . $e->getMessage();
                }
            }
        }
    }
}

// Fetch all uploaded documents for this applicant
$docStmt = $pdo->prepare("SELECT * FROM documents WHERE applicant_id = ?");
$docStmt->execute([$applicant['id']]);
$uploadedDocs = [];
while ($row = $docStmt->fetch()) {
    $uploadedDocs[$row['document_type']] = $row;
}

$pageTitle = 'Supporting Documents';
$activeNav = 'documents';
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
            <span class="topbar-title">Supporting Documents</span>
        </div>
        <div class="topbar-user">
            <span class="text-muted d-none d-md-inline">Logged in as:</span>
            <strong><?php echo e($applicant['first_name'] . ' ' . $applicant['last_name']); ?></strong>
        </div>
    </header>

    <div class="content-body">
        <div class="container-fluid p-0" style="max-width: 1050px;">
            <?php echo renderFlash(); ?>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <h6 class="alert-heading fw-bold mb-1">Upload Error:</h6>
                    <ul class="mb-0 ps-3">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo e($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="card-custom">
                <div class="card-header-custom">
                    <div>
                        <h5 class="fw-bold mb-1"><i class="bi bi-paperclip text-success me-2"></i>Document Verification Portal</h5>
                        <p class="text-muted small mb-0">Upload clean, legible copies of all required documents. Max file size: 5MB (PDF, PNG, JPG).</p>
                    </div>
                </div>

                <div class="card-body-custom">
                    <div class="table-responsive">
                        <table class="table table-custom">
                            <thead>
                                <tr>
                                    <th style="width: 28%;">Document Type</th>
                                    <th style="width: 25%;">Uploaded File</th>
                                    <th style="width: 15%;">Status</th>
                                    <th style="width: 17%;">Admin Notes</th>
                                    <th style="width: 15%;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($docTypes as $key => $meta):
                                    $uploaded = $uploadedDocs[$key] ?? null;
                                    $status = $uploaded ? $uploaded['status'] : 'not_submitted';
                                ?>
                                    <tr>
                                        <td>
                                            <div class="fw-bold text-dark">
                                                <?php echo e($meta['title']); ?>
                                                <?php if ($meta['required']): ?>
                                                    <span class="text-danger">*</span>
                                                <?php endif; ?>
                                            </div>
                                            <small class="text-muted"><?php echo e($meta['description']); ?></small>
                                        </td>
                                        <td>
                                            <?php if ($uploaded): ?>
                                                <div class="d-flex align-items-center gap-2">
                                                    <i class="bi bi-file-earmark-check text-success fs-5"></i>
                                                    <div>
                                                        <div class="small fw-semibold text-truncate" style="max-width: 180px;" title="<?php echo e($uploaded['original_filename']); ?>">
                                                            <?php echo e($uploaded['original_filename']); ?>
                                                        </div>
                                                        <small class="text-muted">
                                                            <?php echo round($uploaded['file_size'] / 1024, 1); ?> KB &bull; <?php echo date('d M Y', strtotime($uploaded['uploaded_at'])); ?>
                                                        </small>
                                                    </div>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-muted fst-italic small">No document uploaded yet</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php echo renderStatusBadge($status); ?>
                                        </td>
                                        <td>
                                            <?php if ($uploaded && !empty($uploaded['admin_comment'])): ?>
                                                <small class="text-danger d-block"><?php echo e($uploaded['admin_comment']); ?></small>
                                            <?php else: ?>
                                                <span class="text-muted small">&mdash;</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <!-- Upload/Replace Modal Trigger -->
                                            <button type="button" class="btn btn-sm <?php echo $uploaded ? 'btn-outline-secondary' : 'btn-green'; ?>" data-bs-toggle="modal" data-bs-target="#uploadModal-<?php echo e($key); ?>">
                                                <i class="bi bi-cloud-arrow-up me-1"></i> <?php echo $uploaded ? 'Replace' : 'Upload'; ?>
                                            </button>
                                        </td>
                                    </tr>

                                    <!-- Upload Modal for each document -->
                                    <div class="modal fade" id="uploadModal-<?php echo e($key); ?>" tabindex="-1" aria-labelledby="modalLabel-<?php echo e($key); ?>" aria-hidden="true">
                                        <div class="modal-dialog modal-dialog-centered">
                                            <div class="modal-content">
                                                <form action="documents.php" method="POST" enctype="multipart/form-data">
                                                    <?php echo csrfInput(); ?>
                                                    <input type="hidden" name="upload_doc" value="1">
                                                    <input type="hidden" name="document_type" value="<?php echo e($key); ?>">

                                                    <div class="modal-header">
                                                        <h5 class="modal-title fw-bold" id="modalLabel-<?php echo e($key); ?>">
                                                            Upload <?php echo e($meta['title']); ?>
                                                        </h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <p class="text-muted small mb-3"><?php echo e($meta['description']); ?></p>
                                                        <div class="mb-3">
                                                            <label class="form-label">Select File <span class="text-danger">*</span></label>
                                                            <input type="file" name="document_file" class="form-control" required accept=".<?php echo implode(', .', $meta['allowed']); ?>">
                                                            <small class="text-muted">Allowed formats: <?php echo strtoupper(implode(', ', $meta['allowed'])); ?> (Max 5MB)</small>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                                                        <button type="submit" class="btn btn-green btn-sm">
                                                            <i class="bi bi-upload me-1"></i> Confirm Upload
                                                        </button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>

                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="d-flex justify-content-between align-items-center pt-3 border-top mt-4">
                        <a href="guardian.php" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left me-1"></i> Back to Guardian
                        </a>
                        <a href="application.php" class="btn btn-green px-4">
                            Continue to Bursary Application <i class="bi bi-arrow-right ms-1"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

