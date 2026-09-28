<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('super_admin');

// Handle single applicant detail view
$viewId = isset($_GET['view']) ? (int)$_GET['view'] : null;
$viewApplicant = null;
$viewEducation = null;
$viewGuardian  = null;
$viewDocuments = [];
$viewApplications = [];

if ($viewId) {
    // Fetch applicant details - ONLY where user role is applicant
    $stmt = $pdo->prepare("
        SELECT a.*, u.username, u.is_active, u.created_at AS registered_at
        FROM applicants a
        INNER JOIN users u ON a.user_id = u.id
        WHERE a.id = ? AND u.role = 'applicant'
        LIMIT 1
    ");
    $stmt->execute([$viewId]);
    $viewApplicant = $stmt->fetch();

    if ($viewApplicant) {
        // Fetch education
        $eduStmt = $pdo->prepare("SELECT * FROM education WHERE applicant_id = ? ORDER BY id DESC LIMIT 1");
        $eduStmt->execute([$viewApplicant['id']]);
        $viewEducation = $eduStmt->fetch();

        // Fetch guardian
        $guardStmt = $pdo->prepare("SELECT * FROM guardians WHERE applicant_id = ? ORDER BY id DESC LIMIT 1");
        $guardStmt->execute([$viewApplicant['id']]);
        $viewGuardian = $guardStmt->fetch();

        // Fetch documents
        $docStmt = $pdo->prepare("SELECT * FROM documents WHERE applicant_id = ? ORDER BY uploaded_at DESC");
        $docStmt->execute([$viewApplicant['id']]);
        $viewDocuments = $docStmt->fetchAll();

        // Fetch applications
        $appStmt = $pdo->prepare("
            SELECT a.*, p.name AS program_name
            FROM applications a
            LEFT JOIN programs p ON a.program_id = p.id
            WHERE a.applicant_id = ?
            ORDER BY a.created_at DESC
        ");
        $appStmt->execute([$viewApplicant['id']]);
        $viewApplications = $appStmt->fetchAll();
    }
}

// Search and Filter for Applicants list
$search = trim($_GET['search'] ?? '');
$countyFilter = trim($_GET['county'] ?? '');

$sql = "
    SELECT a.*, u.username, u.is_active, u.created_at AS registered_at,
           (SELECT COUNT(*) FROM applications WHERE applicant_id = a.id) AS total_apps,
           (SELECT education_level FROM education WHERE applicant_id = a.id ORDER BY id DESC LIMIT 1) AS edu_level
    FROM applicants a
    INNER JOIN users u ON a.user_id = u.id
    WHERE u.role = 'applicant'
";

$params = [];

if (!empty($search)) {
    $sql .= " AND (a.first_name LIKE ? OR a.last_name LIKE ? OR a.id_number LIKE ? OR a.email LIKE ? OR a.phone LIKE ?)";
    $term = "%$search%";
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}

if (!empty($countyFilter)) {
    $sql .= " AND a.county = ?";
    $params[] = $countyFilter;
}

$sql .= " ORDER BY a.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$applicants = $stmt->fetchAll();

// Get unique counties for filter dropdown
$counties = $pdo->query("SELECT DISTINCT county FROM applicants WHERE county IS NOT NULL AND county != '' ORDER BY county ASC")->fetchAll(PDO::FETCH_COLUMN);

$pageTitle = 'Manage Applicants';
$activeNav = 'applicants';
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
            <span class="topbar-title">Applicants Directory</span>
        </div>
        <div class="topbar-user">
            <span class="text-muted d-none d-md-inline">Administrator:</span>
            <strong><?php echo e($_SESSION['username'] ?? 'Admin'); ?></strong>
        </div>
    </header>

    <div class="content-body">
        <div class="container-fluid p-0">
            <?php echo renderFlash(); ?>

            <?php if ($viewApplicant): ?>
                <!-- DETAIL VIEW FOR SINGLE APPLICANT -->
                <div class="mb-3">
                    <a href="applicants.php" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-arrow-left me-1"></i> Back to Applicants List
                    </a>
                </div>

                <div class="card-custom">
                    <div class="card-header-custom">
                        <div>
                            <span class="text-muted small">Applicant ID #<?php echo $viewApplicant['id']; ?></span>
                            <h4 class="fw-bold mb-0 text-dark">
                                <?php echo e($viewApplicant['first_name'] . ' ' . $viewApplicant['middle_name'] . ' ' . $viewApplicant['last_name']); ?>
                            </h4>
                        </div>
                        <div class="text-end">
                            <span class="badge bg-success">Role: Applicant</span>
                            <span class="badge <?php echo $viewApplicant['is_active'] ? 'bg-primary' : 'bg-danger'; ?> ms-1">
                                <?php echo $viewApplicant['is_active'] ? 'Active User' : 'Inactive'; ?>
                            </span>
                        </div>
                    </div>

                    <div class="card-body-custom">
                        <div class="row g-4">
                            <!-- PERSONAL DETAILS -->
                            <div class="col-md-6">
                                <div class="p-3 border rounded h-100 bg-white">
                                    <h6 class="fw-bold text-success border-bottom pb-2 mb-3"><i class="bi bi-person me-2"></i>Personal & Contact Information</h6>
                                    <div class="row g-2 small">
                                        <div class="col-6"><strong>National ID / Cert:</strong> <?php echo e($viewApplicant['id_number'] ?: 'N/A'); ?></div>
                                        <div class="col-6"><strong>Gender:</strong> <?php echo e($viewApplicant['gender'] ?: 'N/A'); ?></div>
                                        <div class="col-6"><strong>Date of Birth:</strong> <?php echo e($viewApplicant['date_of_birth'] ?: 'N/A'); ?></div>
                                        <div class="col-6"><strong>Primary Phone:</strong> <?php echo e($viewApplicant['phone'] ?: 'N/A'); ?></div>
                                        <div class="col-6"><strong>Alternative Phone:</strong> <?php echo e($viewApplicant['alternative_phone'] ?: 'N/A'); ?></div>
                                        <div class="col-6"><strong>Email:</strong> <?php echo e($viewApplicant['email'] ?: 'N/A'); ?></div>
                                        <div class="col-6"><strong>County:</strong> <?php echo e($viewApplicant['county'] ?: 'N/A'); ?></div>
                                        <div class="col-6"><strong>Sub-County:</strong> <?php echo e($viewApplicant['sub_county'] ?: 'N/A'); ?></div>
                                        <div class="col-6"><strong>Ward:</strong> <?php echo e($viewApplicant['ward'] ?: 'N/A'); ?></div>
                                        <div class="col-12"><strong>Address:</strong> <?php echo e($viewApplicant['physical_address'] ?: 'N/A'); ?></div>
                                        <div class="col-12 text-muted mt-2 border-top pt-2">Registered: <?php echo date('d M Y, h:i A', strtotime($viewApplicant['registered_at'])); ?></div>
                                    </div>
                                </div>
                            </div>

                            <!-- EDUCATION DETAILS -->
                            <div class="col-md-6">
                                <div class="p-3 border rounded h-100 bg-white">
                                    <h6 class="fw-bold text-success border-bottom pb-2 mb-3"><i class="bi bi-mortarboard me-2"></i>Academic Record</h6>
                                    <?php if ($viewEducation): ?>
                                        <div class="row g-2 small">
                                            <div class="col-12"><strong>Level:</strong> <?php echo renderStatusBadge($viewEducation['education_level']); ?></div>
                                            <div class="col-12"><strong>Institution:</strong> <?php echo e($viewEducation['institution_name']); ?></div>
                                            <div class="col-6"><strong>Course / Grade:</strong> <?php echo e($viewEducation['course_programme'] ?? $viewEducation['class_grade']); ?></div>
                                            <div class="col-6"><strong>Adm / Reg No:</strong> <?php echo e($viewEducation['registration_number'] ?? $viewEducation['admission_number']); ?></div>
                                            <div class="col-6"><strong>Academic Year:</strong> <?php echo e($viewEducation['academic_year']); ?></div>
                                            <div class="col-6"><strong>Stage:</strong> <?php echo e($viewEducation['year_of_study'] ?? $viewEducation['class_grade']); ?></div>
                                            <div class="col-6"><strong>County/Campus:</strong> <?php echo e($viewEducation['county'] ?? $viewEducation['campus']); ?></div>
                                            <div class="col-6"><strong>Contact:</strong> <?php echo e($viewEducation['school_contact'] ?? 'N/A'); ?></div>
                                        </div>
                                    <?php else: ?>
                                        <p class="text-muted small mb-0">No academic record submitted yet.</p>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- GUARDIAN DETAILS -->
                            <div class="col-md-6">
                                <div class="p-3 border rounded h-100 bg-white">
                                    <h6 class="fw-bold text-success border-bottom pb-2 mb-3"><i class="bi bi-people me-2"></i>Parent / Guardian Details</h6>
                                    <?php if ($viewGuardian): ?>
                                        <div class="row g-2 small">
                                            <div class="col-6"><strong>Full Name:</strong> <?php echo e($viewGuardian['first_name'] . ' ' . $viewGuardian['last_name']); ?></div>
                                            <div class="col-6"><strong>Relationship:</strong> <?php echo e($viewGuardian['relationship'] === 'Other' ? $viewGuardian['other_relationship'] : $viewGuardian['relationship']); ?></div>
                                            <div class="col-6"><strong>Phone:</strong> <?php echo e($viewGuardian['phone']); ?></div>
                                            <div class="col-6"><strong>National ID:</strong> <?php echo e($viewGuardian['id_number'] ?: 'N/A'); ?></div>
                                            <div class="col-6"><strong>Occupation:</strong> <?php echo e($viewGuardian['occupation'] ?: 'N/A'); ?></div>
                                            <div class="col-6"><strong>Employer/Biz:</strong> <?php echo e($viewGuardian['employer_business'] ?: 'N/A'); ?></div>
                                        </div>
                                    <?php else: ?>
                                        <p class="text-muted small mb-0">No parent / guardian details recorded.</p>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- SUBMITTED DOCUMENTS -->
                            <div class="col-md-6">
                                <div class="p-3 border rounded h-100 bg-white">
                                    <h6 class="fw-bold text-success border-bottom pb-2 mb-3"><i class="bi bi-paperclip me-2"></i>Submitted Documents</h6>
                                    <?php if (empty($viewDocuments)): ?>
                                        <p class="text-muted small mb-0">No supporting documents uploaded.</p>
                                    <?php else: ?>
                                        <ul class="list-group list-group-flush small">
                                            <?php foreach ($viewDocuments as $vDoc): ?>
                                                <li class="list-group-item px-0 d-flex justify-content-between align-items-center">
                                                    <div>
                                                        <span class="fw-semibold text-dark"><?php echo e(ucwords(str_replace('_', ' ', $vDoc['document_type']))); ?></span>
                                                        <br>
                                                        <span class="text-muted"><?php echo e($vDoc['original_filename']); ?></span>
                                                    </div>
                                                    <div>
                                                        <?php echo renderStatusBadge($vDoc['status']); ?>
                                                        <a href="documents.php?review=<?php echo $vDoc['id']; ?>" class="btn btn-sm btn-outline-secondary ms-1 py-0">Review</a>
                                                    </div>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- APPLICATION HISTORY -->
                            <div class="col-12">
                                <div class="p-3 border rounded bg-white">
                                    <h6 class="fw-bold text-success border-bottom pb-2 mb-3"><i class="bi bi-clock-history me-2"></i>Bursary Application History</h6>
                                    <?php if (empty($viewApplications)): ?>
                                        <p class="text-muted small mb-0">No applications created by this applicant yet.</p>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-custom">
                                                <thead>
                                                    <tr>
                                                        <th>App #</th>
                                                        <th>Program</th>
                                                        <th>Requested</th>
                                                        <th>Status</th>
                                                        <th>Submitted Date</th>
                                                        <th>Action</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($viewApplications as $vApp): ?>
                                                        <tr>
                                                            <td><strong><?php echo e($vApp['application_number']); ?></strong></td>
                                                            <td><?php echo e($vApp['program_name'] ?? 'General Bursary'); ?></td>
                                                            <td class="text-success fw-bold"><?php echo formatMoney((float)$vApp['requested_amount']); ?></td>
                                                            <td><?php echo renderStatusBadge($vApp['status']); ?></td>
                                                            <td><?php echo $vApp['submitted_at'] ? date('d M Y, h:i A', strtotime($vApp['submitted_at'])) : 'Draft'; ?></td>
                                                            <td>
                                                                <a href="applications.php?review=<?php echo $vApp['id']; ?>" class="btn btn-sm btn-green py-1">
                                                                    <i class="bi bi-search me-1"></i> Review
                                                                </a>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            <?php else: ?>

                <!-- APPLICANTS LIST VIEW -->
                <div class="card-custom">
                    <div class="card-header-custom">
                        <div>
                            <h5 class="fw-bold mb-1"><i class="bi bi-people-fill text-success me-2"></i>Registered Applicants</h5>
                            <p class="text-muted small mb-0">View student profiles, education levels, and application records.</p>
                        </div>
                        <span class="badge bg-success fs-6"><?php echo count($applicants); ?> Applicants</span>
                    </div>

                    <div class="card-body-custom">
                        <!-- SEARCH & FILTER -->
                        <form action="applicants.php" method="GET" class="row g-3 mb-4">
                            <div class="col-md-6">
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="bi bi-search"></i></span>
                                    <input type="text" name="search" class="form-control" placeholder="Search by name, ID number, phone, email..." value="<?php echo e($search); ?>">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <select name="county" class="form-select">
                                    <option value="">-- Filter by County --</option>
                                    <?php foreach ($counties as $c): ?>
                                        <option value="<?php echo e($c); ?>" <?php echo ($countyFilter === $c) ? 'selected' : ''; ?>>
                                            <?php echo e($c); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2 d-flex gap-2">
                                <button type="submit" class="btn btn-green w-100">Filter</button>
                                <?php if (!empty($search) || !empty($countyFilter)): ?>
                                    <a href="applicants.php" class="btn btn-outline-secondary" title="Reset Filters"><i class="bi bi-arrow-counterclockwise"></i></a>
                                <?php endif; ?>
                            </div>
                        </form>

                        <!-- APPLICANTS TABLE -->
                        <div class="table-responsive">
                            <table class="table table-custom">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>ID / Cert Number</th>
                                        <th>Phone & Email</th>
                                        <th>County / Ward</th>
                                        <th>Education Level</th>
                                        <th>Applications</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($applicants)): ?>
                                        <tr>
                                            <td colspan="7" class="text-center py-5 text-muted">
                                                <i class="bi bi-inbox fs-2 d-block mb-2 text-muted"></i>
                                                No applicants found matching your criteria.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($applicants as $app): ?>
                                            <tr>
                                                <td>
                                                    <div class="fw-bold text-dark">
                                                        <?php echo e($app['first_name'] . ' ' . $app['last_name']); ?>
                                                    </div>
                                                    <small class="text-muted">Username: @<?php echo e($app['username']); ?></small>
                                                </td>
                                                <td><code><?php echo e($app['id_number'] ?: 'Pending'); ?></code></td>
                                                <td>
                                                    <div><?php echo e($app['phone']); ?></div>
                                                    <small class="text-muted"><?php echo e($app['email']); ?></small>
                                                </td>
                                                <td>
                                                    <?php echo e($app['county'] ?: 'N/A'); ?>
                                                    <small class="text-muted d-block"><?php echo e($app['ward'] ?: ''); ?></small>
                                                </td>
                                                <td>
                                                    <?php echo $app['edu_level'] ? renderStatusBadge($app['edu_level']) : '<span class="badge bg-light text-dark">Not Set</span>'; ?>
                                                </td>
                                                <td>
                                                    <span class="badge bg-secondary"><?php echo $app['total_apps']; ?> Applied</span>
                                                </td>
                                                <td>
                                                    <a href="applicants.php?view=<?php echo $app['id']; ?>" class="btn btn-sm btn-outline-green">
                                                        <i class="bi bi-eye me-1"></i> View Profile
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            <?php endif; ?>
        </div>
    </div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

