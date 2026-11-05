<?php
/**
 * Utility and Helper Functions
 * E-Bursary Management System
 */

if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}

/**
 * Escape HTML output to prevent XSS attacks.
 */
function e(?string $string): string {
    return htmlspecialchars((string)$string, ENT_QUOTES, 'UTF-8');
}

/**
 * Generate and store a CSRF token in the session.
 */
function generateCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Render a hidden input element containing the CSRF token.
 */
function csrfInput(): string {
    $token = generateCsrfToken();
    return '<input type="hidden" name="csrf_token" value="' . e($token) . '">';
}

/**
 * Verify submitted CSRF token.
 */
function verifyCsrfToken(?string $token): bool {
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Set a flash notification message in the session.
 * @param string $type success | danger | warning | info
 */
function setFlash(string $type, string $message): void {
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message
    ];
}

/**
 * Retrieve and clear the flash notification message.
 */
function getFlash(): ?array {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

/**
 * Render HTML flash message if one exists.
 */
function renderFlash(): string {
    $flash = getFlash();
    if (!$flash) {
        return '';
    }
    $type = e($flash['type']);
    $msg = e($flash['message']);
    return "
    <div class=\"alert alert-{$type} alert-dismissible fade show\" role=\"alert\">
        {$msg}
        <button type=\"button\" class=\"btn-close\" data-bs-dismiss=\"alert\" aria-label=\"Close\"></button>
    </div>";
}

/**
 * Format currency in Kenyan Shillings (KES).
 */
function formatMoney(?float $amount): string {
    if ($amount === null) {
        return 'KES 0.00';
    }
    return 'KES ' . number_format($amount, 2);
}

/**
 * Render Bootstrap badge for various statuses.
 */
function renderStatusBadge(string $status): string {
    $statusLower = strtolower($status);
    $map = [
        'draft'               => ['bg-secondary', 'Draft'],
        'submitted'           => ['bg-info text-dark', 'Submitted'],
        'pending'             => ['bg-warning text-dark', 'Pending'],
        'under_review'        => ['bg-primary', 'Under Review'],
        'approved'            => ['bg-success', 'Approved'],
        'rejected'            => ['bg-danger', 'Rejected'],
        'active'              => ['bg-success', 'Active'],
        'inactive'            => ['bg-secondary', 'Inactive'],
        'closed'              => ['bg-dark', 'Closed'],
        'verified'            => ['bg-success', 'Verified'],
        'not_submitted'       => ['bg-secondary', 'Not Submitted'],
        'partially_disbursed' => ['bg-info text-dark', 'Partially Disbursed'],
        'fully_disbursed'     => ['bg-success', 'Fully Disbursed'],
        'processing'          => ['bg-warning text-dark', 'Processing'],
        'completed'           => ['bg-success', 'Completed'],
        'failed'              => ['bg-danger', 'Failed']
    ];

    if (isset($map[$statusLower])) {
        return '<span class="badge ' . $map[$statusLower][0] . '">' . $map[$statusLower][1] . '</span>';
    }

    return '<span class="badge bg-secondary">' . e(ucwords(str_replace('_', ' ', $status))) . '</span>';
}

/**
 * Create a notification in the database for a specific user.
 */
function createNotification(PDO $pdo, int $userId, string $title, string $message): bool {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO notifications (user_id, title, message, is_read, created_at)
            VALUES (?, ?, ?, 0, NOW())
        ");
        return $stmt->execute([$userId, $title, $message]);
    } catch (PDOException $e) {
        error_log("Failed to create notification: " . $e->getMessage());
        return false;
    }
}

/**
 * Get count of unread notifications for a user.
 */
function getUnreadNotificationCount(PDO $pdo, int $userId): int {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

/**
 * Get setting value by key.
 */
function getSetting(PDO $pdo, string $key, string $default = ''): string {
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        return ($val !== false && $val !== null) ? (string)$val : $default;
    } catch (PDOException $e) {
        return $default;
    }
}

/**
 * Update or insert setting value.
 */
function updateSetting(PDO $pdo, string $key, ?string $value): bool {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO settings (setting_key, setting_value)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
        ");
        return $stmt->execute([$key, $value]);
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Generate a unique application number, e.g. BUR-2026-00123
 */
function generateApplicationNumber(PDO $pdo): string {
    $year = date('Y');
    $prefix = "BUR-{$year}-";
    $stmt = $pdo->query("SELECT MAX(id) FROM applications");
    $maxId = (int)$stmt->fetchColumn() + 1;
    return $prefix . str_pad((string)$maxId, 5, '0', STR_PAD_LEFT);
}

/**
 * Securely validate and upload a file.
 * Returns array: ['success' => bool, 'stored_filename' => string, 'file_path' => string, 'file_type' => string, 'file_size' => int, 'error' => string]
 */
function handleFileUpload(array $file, array $allowedExtensions, string $destinationDir, int $maxBytes = 5242880): array {
    if (!isset($file['error']) || is_array($file['error'])) {
        return ['success' => false, 'error' => 'Invalid upload parameters.'];
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $uploadErrors = [
            UPLOAD_ERR_INI_SIZE   => 'The uploaded file exceeds the upload_max_filesize directive in php.ini.',
            UPLOAD_ERR_FORM_SIZE  => 'The uploaded file exceeds the MAX_FILE_SIZE directive in the HTML form.',
            UPLOAD_ERR_PARTIAL    => 'The uploaded file was only partially uploaded.',
            UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
            UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the file upload.',
        ];
        return ['success' => false, 'error' => $uploadErrors[$file['error']] ?? 'Unknown upload error.'];
    }

    if ($file['size'] > $maxBytes) {
        return ['success' => false, 'error' => 'File size exceeds maximum limit of ' . round($maxBytes / 1048576, 1) . 'MB.'];
    }

    $originalName = $file['name'];
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if (!in_array($extension, $allowedExtensions, true)) {
        return ['success' => false, 'error' => 'Invalid file extension. Allowed extensions: ' . implode(', ', $allowedExtensions)];
    }

    // Verify MIME type using finfo
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);
    $allowedMimes = [
        'pdf'  => 'application/pdf',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png'
    ];

    if (!in_array($mimeType, array_values($allowedMimes), true)) {
        return ['success' => false, 'error' => 'Invalid file content/type. Only PDF, JPG, and PNG documents are allowed.'];
    }

    // Generate safe randomized filename
    if (!is_dir($destinationDir)) {
        mkdir($destinationDir, 0755, true);
    }

    $safeFilename = 'doc_' . date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '.' . $extension;
    $targetPath = rtrim($destinationDir, '/\\') . DIRECTORY_SEPARATOR . $safeFilename;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        return ['success' => false, 'error' => 'Failed to save uploaded file.'];
    }

    return [
        'success'         => true,
        'original_name'   => $originalName,
        'stored_filename' => $safeFilename,
        'file_path'       => $targetPath,
        'file_type'       => $mimeType,
        'file_size'       => $file['size']
    ];
}

/**
 * Retrieve comprehensive status of all application wizard steps for an applicant.
 */
function getApplicantStepsStatus(PDO $pdo, int $applicantId, ?array $applicant = null): array {
    if (!$applicant || !isset($applicant['id_number'])) {
        $stmt = $pdo->prepare("SELECT * FROM applicants WHERE id = ?");
        $stmt->execute([$applicantId]);
        $applicant = $stmt->fetch() ?: [];
    }

    // Step 1: Personal Profile
    $personalMissing = [];
    if (empty($applicant['first_name'])) $personalMissing[] = 'First Name';
    if (empty($applicant['last_name'])) $personalMissing[] = 'Last Name';
    if (empty($applicant['id_number'])) $personalMissing[] = 'ID / Birth Certificate No';
    if (empty($applicant['date_of_birth'])) $personalMissing[] = 'Date of Birth';
    if (empty($applicant['gender'])) $personalMissing[] = 'Gender';
    if (empty($applicant['phone'])) $personalMissing[] = 'Primary Phone';
    if (empty($applicant['email'])) $personalMissing[] = 'Email Address';
    $personalComplete = empty($personalMissing);

    // Step 2: Academic / Education Details
    $eduStmt = $pdo->prepare("SELECT * FROM education WHERE applicant_id = ? ORDER BY id DESC LIMIT 1");
    $eduStmt->execute([$applicantId]);
    $education = $eduStmt->fetch() ?: null;
    $eduMissing = [];
    if (!$education) {
        $eduMissing[] = 'Education Information';
    } else {
        if (empty($education['institution_name'])) $eduMissing[] = 'School / Institution Name';
        if (empty($education['education_level'])) $eduMissing[] = 'Education Level';
    }
    $eduComplete = ($education !== null) && empty($eduMissing);

    // Step 3: Parent / Guardian
    $guardStmt = $pdo->prepare("SELECT * FROM guardians WHERE applicant_id = ? ORDER BY id DESC LIMIT 1");
    $guardStmt->execute([$applicantId]);
    $guardian = $guardStmt->fetch() ?: null;

    $isMinor = false;
    if (!empty($applicant['date_of_birth'])) {
        try {
            $dob = new DateTime($applicant['date_of_birth']);
            $age = (new DateTime())->diff($dob)->y;
            if ($age < 18) {
                $isMinor = true;
            }
        } catch (Exception $e) {
            $isMinor = false;
        }
    }

    $guardMissing = [];
    if ($isMinor) {
        if (!$guardian) {
            $guardMissing[] = 'Guardian Details (Mandatory for applicants under 18)';
        } else {
            if (empty($guardian['first_name'])) $guardMissing[] = 'Guardian First Name';
            if (empty($guardian['last_name'])) $guardMissing[] = 'Guardian Last Name';
            if (empty($guardian['relationship'])) $guardMissing[] = 'Relationship to Applicant';
            if (empty($guardian['phone'])) $guardMissing[] = 'Guardian Phone';
            if (empty($guardian['id_number'])) $guardMissing[] = 'Guardian National ID';
        }
        $guardComplete = ($guardian !== null) && empty($guardMissing);
    } else {
        // For adult applicants, if filled it is complete, but not strictly mandatory to block
        $guardComplete = ($guardian !== null);
    }

    // Step 4: Bursary Application Request
    $appStmt = $pdo->prepare("
        SELECT a.*, p.name AS program_name, p.maximum_amount AS program_max
        FROM applications a
        LEFT JOIN programs p ON a.program_id = p.id
        WHERE a.applicant_id = ?
        ORDER BY a.id DESC LIMIT 1
    ");
    $appStmt->execute([$applicantId]);
    $application = $appStmt->fetch() ?: null;

    $appMissing = [];
    if (!$application) {
        $appMissing[] = 'Bursary Program Selection & Request Details';
    } else {
        if (empty($application['program_id'])) $appMissing[] = 'Bursary Program';
        if (empty($application['requested_amount']) || (float)$application['requested_amount'] <= 0) $appMissing[] = 'Requested Amount';
        if (empty($application['financial_need'])) $appMissing[] = 'Financial Need Description';
        if (empty($application['reason_for_application'])) $appMissing[] = 'Reason for Application';
    }
    $appComplete = ($application !== null) && empty($appMissing);
    $isSubmitted = $application && in_array($application['status'], ['submitted', 'pending', 'under_review', 'approved', 'rejected']);

    // Step 5: Supporting Documents
    $docStmt = $pdo->prepare("SELECT document_type, original_filename, status FROM documents WHERE applicant_id = ?");
    $docStmt->execute([$applicantId]);
    $docs = [];
    while ($d = $docStmt->fetch()) {
        $docs[$d['document_type']] = $d;
    }

    $mandatoryTypes = [
        'passport_photo'  => 'Passport Photo',
        'identification'  => 'National ID / Birth Certificate',
        'fee_structure'   => 'Fee Structure / Statement',
        'admission_proof' => 'Admission Letter / Student ID'
    ];

    $missingDocs = [];
    foreach ($mandatoryTypes as $typeKey => $typeName) {
        if (!isset($docs[$typeKey])) {
            $missingDocs[$typeKey] = $typeName;
        }
    }
    $docsUploadedCount = count($mandatoryTypes) - count($missingDocs);
    $docsTotalRequired = count($mandatoryTypes);
    $docsComplete = empty($missingDocs);

    // Step 6: Review & Final Submit
    $canSubmit = $personalComplete && $eduComplete && (!$isMinor || $guardComplete) && $appComplete && $docsComplete && !$isSubmitted;

    $steps = [
        'profile' => [
            'step_num'    => 1,
            'title'       => 'Personal',
            'label'       => 'Personal Details',
            'url'         => 'profile.php',
            'completed'   => $personalComplete,
            'missing'     => $personalMissing
        ],
        'education' => [
            'step_num'    => 2,
            'title'       => 'Education',
            'label'       => 'Academic Info',
            'url'         => 'education.php',
            'completed'   => $eduComplete,
            'missing'     => $eduMissing
        ],
        'guardian' => [
            'step_num'    => 3,
            'title'       => 'Guardian',
            'label'       => 'Guardian Details',
            'url'         => 'guardian.php',
            'completed'   => $guardComplete,
            'missing'     => $guardMissing,
            'optional'    => !$isMinor
        ],
        'application' => [
            'step_num'    => 4,
            'title'       => 'Bursary Request',
            'label'       => 'Bursary Form',
            'url'         => 'application.php',
            'completed'   => $appComplete,
            'missing'     => $appMissing
        ],
        'documents' => [
            'step_num'         => 5,
            'title'            => 'Documents',
            'label'            => 'Supporting Documents',
            'url'              => 'documents.php',
            'completed'        => $docsComplete,
            'missing'          => array_values($missingDocs),
            'missing_docs'     => $missingDocs,
            'uploaded_count'   => $docsUploadedCount,
            'total_required'   => $docsTotalRequired
        ],
        'review' => [
            'step_num'    => 6,
            'title'       => 'Review & Submit',
            'label'       => 'Final Review',
            'url'         => 'review.php',
            'completed'   => $isSubmitted,
            'can_submit'  => $canSubmit
        ]
    ];

    // Find the next incomplete step
    $nextStepKey = 'review';
    $nextStepUrl = 'review.php';
    $nextStepTitle = 'Review & Submit';

    if ($isSubmitted) {
        $nextStepKey = 'review';
        $nextStepUrl = 'status.php';
        $nextStepTitle = 'View Status';
    } else {
        foreach ($steps as $key => $sData) {
            // For adult applicants who skipped guardian, don't force them back to guardian if they already filled bursary application
            if ($key === 'guardian' && !$isMinor && !$sData['completed'] && $appComplete) {
                continue;
            }
            if (!$sData['completed'] && $key !== 'review') {
                $nextStepKey = $key;
                $nextStepUrl = $sData['url'];
                $nextStepTitle = $sData['title'];
                break;
            }
        }
    }

    $completedCount = 0;
    foreach ($steps as $k => $d) {
        if ($d['completed']) {
            $completedCount++;
        }
    }
    $percentage = (int)round(($completedCount / count($steps)) * 100);

    return [
        'steps'             => $steps,
        'applicant'         => $applicant,
        'education'         => $education,
        'guardian'          => $guardian,
        'application'       => $application,
        'docs'              => $docs,
        'is_minor'          => $isMinor,
        'is_submitted'      => $isSubmitted,
        'can_submit'        => $canSubmit,
        'next_step_key'     => $nextStepKey,
        'next_step_url'     => $nextStepUrl,
        'next_step_title'   => $nextStepTitle,
        'percentage'        => $percentage,
        'missing_docs'      => $missingDocs
    ];
}

/**
 * Render the HTML stepper wizard for the applicant portal.
 */
function renderApplicantWizardHtml(string $currentStepKey, array $stepsInfo): string {
    $steps = $stepsInfo['steps'];
    $currentStepNum = $steps[$currentStepKey]['step_num'] ?? 1;
    $totalSteps = count($steps);

    $html = '<div class="wizard-container mb-4 shadow-sm">';
    
    // Top summary row
    $html .= '<div class="d-flex flex-wrap justify-content-between align-items-center mb-2 px-1 gap-2">';
    $html .= '<div class="small fw-bold text-dark d-flex align-items-center gap-2">';
    $html .= '<span class="badge bg-success text-white px-2 py-1"><i class="bi bi-diagram-3-fill me-1"></i> Step ' . $currentStepNum . ' of ' . $totalSteps . '</span>';
    $html .= '<span class="fs-6">' . e($steps[$currentStepKey]['label'] ?? $steps[$currentStepKey]['title']) . '</span>';
    $html .= '</div>';
    
    $html .= '<div class="small fw-semibold text-muted d-flex align-items-center gap-2">';
    $html .= '<span>Application Progress:</span>';
    $html .= '<span class="badge ' . ($stepsInfo['percentage'] >= 100 ? 'bg-success' : 'bg-primary') . '">' . $stepsInfo['percentage'] . '% Completed</span>';
    $html .= '</div>';
    $html .= '</div>';

    // Progress bar
    $html .= '<div class="progress mb-3" style="height: 6px;">';
    $html .= '<div class="progress-bar bg-success" role="progressbar" style="width: ' . $stepsInfo['percentage'] . '%;" aria-valuenow="' . $stepsInfo['percentage'] . '" aria-valuemin="0" aria-valuemax="100"></div>';
    $html .= '</div>';

    // Steps Navigation Circles
    $html .= '<div class="steps-nav">';
    foreach ($steps as $key => $s) {
        $isActive = ($key === $currentStepKey);
        $isCompleted = !empty($s['completed']);
        $stepClass = $isActive ? 'active' : ($isCompleted ? 'completed' : 'pending');

        $linkTag = 'div';
        $linkAttr = '';
        if ($isCompleted || $isActive) {
            $linkTag = 'a';
            $linkAttr = ' href="' . e($s['url']) . '" title="' . e($s['title']) . ' (Click to view/edit)" class="step-item ' . $stepClass . '"';
        } else {
            $linkAttr = ' class="step-item ' . $stepClass . '"';
        }

        $html .= "<{$linkTag}{$linkAttr}>";
        $html .= '<div class="step-circle">';
        if ($isCompleted) {
            $html .= '<i class="bi bi-check-lg"></i>';
        } else {
            $html .= $s['step_num'];
        }
        $html .= '</div>';
        $html .= '<span class="step-label d-none d-md-block">' . e($s['title']) . '</span>';
        $html .= '<span class="step-label d-md-none">' . $s['step_num'] . '</span>';
        $html .= "</{$linkTag}>";
    }
    $html .= '</div>';

    // Step Status Notification bar if already completed
    if (!empty($steps[$currentStepKey]['completed']) && $currentStepKey !== 'review') {
        $html .= '<div class="mt-3 pt-2 border-top d-flex flex-wrap justify-content-between align-items-center small gap-2">';
        $html .= '<span class="text-success fw-semibold"><i class="bi bi-check-circle-fill me-1"></i> This section has been completed.</span>';
        if (!empty($stepsInfo['next_step_url']) && $stepsInfo['next_step_key'] !== $currentStepKey) {
            $html .= '<a href="' . e($stepsInfo['next_step_url']) . '" class="btn btn-sm btn-outline-success py-1 px-3 fw-semibold">Next Incomplete Step: ' . e($stepsInfo['next_step_title']) . ' <i class="bi bi-arrow-right ms-1"></i></a>';
        }
        $html .= '</div>';
    }

    $html .= '</div>';

    return $html;
}


