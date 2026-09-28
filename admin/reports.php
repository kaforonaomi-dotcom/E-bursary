<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('super_admin');

// 1. High-level Summary Metrics
$totalApplicants = (int)$pdo->query("SELECT COUNT(*) FROM applicants a INNER JOIN users u ON a.user_id = u.id WHERE u.role = 'applicant'")->fetchColumn();
$totalApplications = (int)$pdo->query("SELECT COUNT(*) FROM applications")->fetchColumn();
$pendingApps = (int)$pdo->query("SELECT COUNT(*) FROM applications WHERE status IN ('submitted', 'pending')")->fetchColumn();
$underReviewApps = (int)$pdo->query("SELECT COUNT(*) FROM applications WHERE status = 'under_review'")->fetchColumn();
$approvedApps = (int)$pdo->query("SELECT COUNT(*) FROM applications WHERE status = 'approved'")->fetchColumn();
$rejectedApps = (int)$pdo->query("SELECT COUNT(*) FROM applications WHERE status = 'rejected'")->fetchColumn();

$totalRequested = (float)$pdo->query("SELECT COALESCE(SUM(requested_amount), 0) FROM applications WHERE status != 'draft'")->fetchColumn();
$totalAllocated = (float)$pdo->query("SELECT COALESCE(SUM(approved_amount), 0) FROM allocations WHERE status != 'pending'")->fetchColumn();
$totalDisbursed = (float)$pdo->query("SELECT COALESCE(SUM(amount), 0) FROM disbursements WHERE status = 'completed'")->fetchColumn();

// 2. Breakdown by Education Level
$eduBreakdown = $pdo->query("
    SELECT
        COALESCE(e.education_level, 'Not Specified') AS level,
        COUNT(a.id) AS total_apps,
        COALESCE(SUM(a.requested_amount), 0) AS requested_total,
        SUM(CASE WHEN a.status = 'approved' THEN 1 ELSE 0 END) AS approved_count
    FROM applications a
    LEFT JOIN education e ON a.applicant_id = e.applicant_id
    GROUP BY e.education_level
")->fetchAll();

// 3. Breakdown by Program
$progBreakdown = $pdo->query("
    SELECT
        COALESCE(p.name, 'Unassigned') AS program_name,
        COUNT(a.id) AS total_apps,
        COALESCE(SUM(a.requested_amount), 0) AS total_requested,
        SUM(CASE WHEN a.status = 'approved' THEN 1 ELSE 0 END) AS approved_apps,
        COALESCE((
            SELECT SUM(al.approved_amount)
            FROM allocations al
            INNER JOIN applications ap2 ON al.application_id = ap2.id
            WHERE ap2.program_id = p.id
        ), 0) AS allocated_amount
    FROM programs p
    LEFT JOIN applications a ON p.id = a.program_id
    GROUP BY p.id, p.name
")->fetchAll();

// 4. Breakdown by County / Region
$countyBreakdown = $pdo->query("
    SELECT
        COALESCE(ap.county, 'Unspecified') AS county_name,
        COUNT(a.id) AS app_count,
        SUM(CASE WHEN a.status = 'approved' THEN 1 ELSE 0 END) AS approved_count,
        COALESCE(SUM(a.requested_amount), 0) AS total_requested
    FROM applications a
    INNER JOIN applicants ap ON a.applicant_id = ap.id
    GROUP BY ap.county
    ORDER BY app_count DESC
    LIMIT 10
")->fetchAll();

$pageTitle = 'Executive Reports & Analytics';
$activeNav = 'reports';
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
            <span class="topbar-title">Reports & System Analytics</span>
        </div>
        <div class="topbar-user">
            <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-printer me-1"></i> Print Report
            </button>
        </div>
    </header>

    <div class="content-body">
        <div class="container-fluid p-0">
            <?php echo renderFlash(); ?>

            <!-- REPORT HEADER -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h3 class="fw-bold text-dark mb-1">Executive Bursary Performance Report</h3>
                    <p class="text-muted mb-0">Generated on <?php echo date('F d, Y \a\t h:i A'); ?> &bull; System Financial & Intake Summary</p>
                </div>
            </div>

            <!-- KPI STATS CARDS -->
            <div class="row g-3 mb-4">
                <div class="col-sm-6 col-xl-3">
                    <div class="stat-card">
                        <div class="stat-title">Total Registered Applicants</div>
                        <div class="stat-value"><?php echo $totalApplicants; ?></div>
                        <div class="stat-subtitle">Individual student accounts</div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="stat-card">
                        <div class="stat-title">Total Applications</div>
                        <div class="stat-value"><?php echo $totalApplications; ?></div>
                        <div class="stat-subtitle">Submitted & Draft records</div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="stat-card">
                        <div class="stat-title">Pending & Under Review</div>
                        <div class="stat-value text-warning"><?php echo ($pendingApps + $underReviewApps); ?></div>
                        <div class="stat-subtitle"><?php echo $pendingApps; ?> pending, <?php echo $underReviewApps; ?> under review</div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="stat-card">
                        <div class="stat-title">Approved Bursaries</div>
                        <div class="stat-value text-success"><?php echo $approvedApps; ?></div>
                        <div class="stat-subtitle"><?php echo $rejectedApps; ?> rejected applications</div>
                    </div>
                </div>
            </div>

            <!-- FINANCIAL SUMMARY CARDS -->
            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <div class="stat-card border-success">
                        <div class="stat-title">Total Funds Requested</div>
                        <div class="stat-value text-dark"><?php echo formatMoney($totalRequested); ?></div>
                        <div class="stat-subtitle">Cumulative student need</div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-card border-success">
                        <div class="stat-title">Total Funds Allocated</div>
                        <div class="stat-value text-success"><?php echo formatMoney($totalAllocated); ?></div>
                        <div class="stat-subtitle">Approved by bursary committee</div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-card border-success">
                        <div class="stat-title">Total Funds Disbursed</div>
                        <div class="stat-value text-primary"><?php echo formatMoney($totalDisbursed); ?></div>
                        <div class="stat-subtitle">Paid to institutions (<?php echo $totalAllocated > 0 ? round(($totalDisbursed / $totalAllocated) * 100, 1) : 0; ?>% released)</div>
                    </div>
                </div>
            </div>

            <!-- SECTION 1: BREAKDOWN BY EDUCATION LEVEL -->
            <div class="card-custom mb-4">
                <div class="card-header-custom">
                    <h5 class="fw-bold mb-0 text-dark"><i class="bi bi-mortarboard-fill text-success me-2"></i>Applications by Education Level</h5>
                </div>
                <div class="card-body-custom">
                    <div class="table-responsive">
                        <table class="table table-custom">
                            <thead>
                                <tr>
                                    <th>Education Level</th>
                                    <th>Total Applications</th>
                                    <th>Approved</th>
                                    <th>Approval Rate</th>
                                    <th>Total Requested</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($eduBreakdown)): ?>
                                    <tr><td colspan="5" class="text-center py-3 text-muted">No data available.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($eduBreakdown as $ed):
                                        $rate = $ed['total_apps'] > 0 ? round(($ed['approved_count'] / $ed['total_apps']) * 100, 1) : 0;
                                    ?>
                                        <tr>
                                            <td class="fw-bold"><?php echo renderStatusBadge($ed['level']); ?></td>
                                            <td><?php echo $ed['total_apps']; ?></td>
                                            <td><?php echo $ed['approved_count']; ?></td>
                                            <td>
                                                <div class="d-flex align-items-center gap-2">
                                                    <span><?php echo $rate; ?>%</span>
                                                    <div class="progress flex-grow-1" style="height: 6px; max-width: 100px;">
                                                        <div class="progress-bar bg-success" style="width: <?php echo $rate; ?>%;"></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="fw-bold text-success"><?php echo formatMoney((float)$ed['requested_total']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- SECTION 2: BREAKDOWN BY PROGRAM -->
            <div class="card-custom mb-4">
                <div class="card-header-custom">
                    <h5 class="fw-bold mb-0 text-dark"><i class="bi bi-folder-fill text-success me-2"></i>Applications & Allocations by Program</h5>
                </div>
                <div class="card-body-custom">
                    <div class="table-responsive">
                        <table class="table table-custom">
                            <thead>
                                <tr>
                                    <th>Program Name</th>
                                    <th>Applications</th>
                                    <th>Approved</th>
                                    <th>Requested Amount</th>
                                    <th>Allocated Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($progBreakdown)): ?>
                                    <tr><td colspan="5" class="text-center py-3 text-muted">No program data available.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($progBreakdown as $pb): ?>
                                        <tr>
                                            <td class="fw-bold"><?php echo e($pb['program_name']); ?></td>
                                            <td><?php echo $pb['total_apps']; ?></td>
                                            <td><?php echo $pb['approved_apps']; ?></td>
                                            <td><?php echo formatMoney((float)$pb['total_requested']); ?></td>
                                            <td class="fw-bold text-success"><?php echo formatMoney((float)$pb['allocated_amount']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- SECTION 3: TOP REGIONAL INTAKE -->
            <div class="card-custom">
                <div class="card-header-custom">
                    <h5 class="fw-bold mb-0 text-dark"><i class="bi bi-geo-alt-fill text-success me-2"></i>Intake by County / Sub-Region</h5>
                </div>
                <div class="card-body-custom">
                    <div class="table-responsive">
                        <table class="table table-custom">
                            <thead>
                                <tr>
                                    <th>County / Region</th>
                                    <th>Applications</th>
                                    <th>Approved</th>
                                    <th>Total Requested</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($countyBreakdown)): ?>
                                    <tr><td colspan="4" class="text-center py-3 text-muted">No regional data available.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($countyBreakdown as $cb): ?>
                                        <tr>
                                            <td class="fw-bold"><?php echo e($cb['county_name']); ?></td>
                                            <td><?php echo $cb['app_count']; ?></td>
                                            <td><?php echo $cb['approved_count']; ?></td>
                                            <td class="text-success fw-bold"><?php echo formatMoney((float)$cb['total_requested']); ?></td>
                                        </tr>
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

