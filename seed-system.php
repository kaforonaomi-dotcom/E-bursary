<?php
/**
 * E-Bursary Management System - Database Seeder
 * Run from CLI: php seed-system.php
 * Or via browser: http://localhost/E-bursary/seed-system.php
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

$isCli = (php_sapi_name() === 'cli');

function outputMsg($msg, $isCli) {
    if ($isCli) {
        echo $msg . "\n";
    } else {
        echo htmlspecialchars($msg) . "<br>";
    }
}

outputMsg("============================================================", $isCli);
outputMsg("   E-BURSARY MANAGEMENT SYSTEM - SYSTEM SEEDER", $isCli);
outputMsg("============================================================", $isCli);

try {
    $pdo->beginTransaction();

    // 1. SETTINGS
    outputMsg("[1/5] Seeding System Settings...", $isCli);
    $settings = [
        'application_status'   => 'open',
        'application_deadline' => date('Y-m-d', strtotime('+60 days')),
        'system_name'          => 'County E-Bursary Management System'
    ];

    $stmtSet = $pdo->prepare("
        INSERT INTO settings (setting_key, setting_value)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
    ");
    foreach ($settings as $k => $v) {
        $stmtSet->execute([$k, $v]);
    }
    outputMsg("  -> Application Status: OPEN (Deadline: " . date('F d, Y', strtotime('+60 days')) . ")", $isCli);

    // 2. BURSARY PROGRAMS
    outputMsg("[2/5] Seeding Bursary Programs...", $isCli);
    $programs = [
        [
            'name'            => 'High School Tuition & Boarding Bursary 2026',
            'description'     => 'Direct tuition and boarding assistance for secondary school students in public and registered boarding and day schools.',
            'education_level' => 'high_school',
            'maximum_amount'  => 25000.00,
            'open_date'       => date('Y-m-d'),
            'close_date'      => date('Y-m-d', strtotime('+45 days')),
            'status'          => 'active'
        ],
        [
            'name'            => 'University & TVET Higher Education Fund 2026',
            'description'     => 'Higher education bursary for undergraduate students in public/chartered private universities, national polytechnics, and TVET colleges.',
            'education_level' => 'university',
            'maximum_amount'  => 40000.00,
            'open_date'       => date('Y-m-d'),
            'close_date'      => date('Y-m-d', strtotime('+60 days')),
            'status'          => 'active'
        ],
        [
            'name'            => 'Primary Special Needs & Vulnerable Pupils Fund',
            'description'     => 'Special needs and vulnerable learner assistance to ensure access to educational materials and basic necessities.',
            'education_level' => 'primary',
            'maximum_amount'  => 15000.00,
            'open_date'       => date('Y-m-d'),
            'close_date'      => date('Y-m-d', strtotime('+90 days')),
            'status'          => 'active'
        ]
    ];

    $progStmt = $pdo->prepare("
        INSERT INTO programs (name, description, education_level, maximum_amount, application_open_date, application_close_date, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            description = VALUES(description),
            maximum_amount = VALUES(maximum_amount),
            application_close_date = VALUES(application_close_date),
            status = 'active'
    ");

    foreach ($programs as $p) {
        // Check if exists by name
        $checkP = $pdo->prepare("SELECT id FROM programs WHERE name = ? LIMIT 1");
        $checkP->execute([$p['name']]);
        $existingProg = $checkP->fetch();

        if ($existingProg) {
            $updP = $pdo->prepare("UPDATE programs SET description = ?, education_level = ?, maximum_amount = ?, application_open_date = ?, application_close_date = ?, status = 'active' WHERE id = ?");
            $updP->execute([$p['description'], $p['education_level'], $p['maximum_amount'], $p['open_date'], $p['close_date'], $existingProg['id']]);
        } else {
            $progStmt->execute([
                $p['name'],
                $p['description'],
                $p['education_level'],
                $p['maximum_amount'],
                $p['open_date'],
                $p['close_date'],
                $p['status']
            ]);
        }
    }
    outputMsg("  -> 3 Active bursary programs configured (High School, University/TVET, Primary).", $isCli);

    // 3. SUPER ADMIN ACCOUNT
    outputMsg("[3/5] Seeding Administrator Account...", $isCli);
    $adminUser = 'admin';
    $adminEmail = 'admin@ebursary.test';
    $adminPass = 'admin123';
    $adminHash = password_hash($adminPass, PASSWORD_DEFAULT);

    $checkAdmin = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1");
    $checkAdmin->execute([$adminUser, $adminEmail]);
    $adminRow = $checkAdmin->fetch();

    if ($adminRow) {
        $updAdmin = $pdo->prepare("UPDATE users SET password = ?, role = 'super_admin', is_active = 1 WHERE id = ?");
        $updAdmin->execute([$adminHash, $adminRow['id']]);
        $adminId = $adminRow['id'];
    } else {
        $insAdmin = $pdo->prepare("INSERT INTO users (username, email, password, role, is_active, created_at) VALUES (?, ?, ?, 'super_admin', 1, NOW())");
        $insAdmin->execute([$adminUser, $adminEmail, $adminHash]);
        $adminId = (int)$pdo->lastInsertId();
    }
    outputMsg("  -> Admin: username 'admin', password 'admin123'", $isCli);

    // 4. TEST APPLICANT (IN-PROGRESS FLOW TESTER)
    outputMsg("[4/5] Seeding Demo In-Progress Applicant...", $isCli);
    $appUser = 'applicant';
    $appEmail = 'applicant@ebursary.test';
    $appPass = 'password123';
    $appHash = password_hash($appPass, PASSWORD_DEFAULT);

    $checkApp = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1");
    $checkApp->execute([$appUser, $appEmail]);
    $appUserRow = $checkApp->fetch();

    if ($appUserRow) {
        $updApp = $pdo->prepare("UPDATE users SET password = ?, role = 'applicant', is_active = 1 WHERE id = ?");
        $updApp->execute([$appHash, $appUserRow['id']]);
        $appUserId = $appUserRow['id'];
    } else {
        $insApp = $pdo->prepare("INSERT INTO users (username, email, password, role, is_active, created_at) VALUES (?, ?, ?, 'applicant', 1, NOW())");
        $insApp->execute([$appUser, $appEmail, $appHash]);
        $appUserId = (int)$pdo->lastInsertId();
    }

    // Applicant Profile
    $checkApplicantProfile = $pdo->prepare("SELECT id FROM applicants WHERE user_id = ? LIMIT 1");
    $checkApplicantProfile->execute([$appUserId]);
    $appProfile = $checkApplicantProfile->fetch();

    if ($appProfile) {
        $updProf = $pdo->prepare("
            UPDATE applicants SET
                first_name = 'Brian',
                middle_name = 'Kariuki',
                last_name = 'Mwangi',
                id_number = '38920145',
                date_of_birth = '2003-08-14',
                gender = 'Male',
                phone = '0712345678',
                email = ?,
                county = 'Nairobi',
                sub_county = 'Westlands',
                ward = 'Parklands',
                physical_address = 'Highridge, Estate Road House #14'
            WHERE id = ?
        ");
        $updProf->execute([$appEmail, $appProfile['id']]);
        $applicantId = $appProfile['id'];
    } else {
        $insProf = $pdo->prepare("
            INSERT INTO applicants (
                user_id, first_name, middle_name, last_name, id_number,
                date_of_birth, gender, phone, email, county, sub_county, ward, physical_address, created_at
            ) VALUES (
                ?, 'Brian', 'Kariuki', 'Mwangi', '38920145',
                '2003-08-14', 'Male', '0712345678', ?, 'Nairobi', 'Westlands', 'Parklands', 'Highridge, Estate Road House #14', NOW()
            )
        ");
        $insProf->execute([$appUserId, $appEmail]);
        $applicantId = (int)$pdo->lastInsertId();
    }

    // Pre-seed Education (Step 2 completed) so the user experiences the live continuation from Step 3
    $checkEdu = $pdo->prepare("SELECT id FROM education WHERE applicant_id = ? LIMIT 1");
    $checkEdu->execute([$applicantId]);
    $eduRow = $checkEdu->fetch();

    if ($eduRow) {
        $updEdu = $pdo->prepare("
            UPDATE education SET
                education_level = 'university',
                institution_name = 'University of Nairobi',
                institution_type = 'Public University',
                campus = 'Main Campus',
                course_programme = 'Bachelor of Science in Computer Science',
                department = 'School of Computing and Informatics',
                registration_number = 'P15/142850/2022',
                year_of_study = 'Year 3',
                semester = 'Semester 1',
                academic_year = '2025/2026',
                study_mode = 'full_time',
                expected_graduation_year = 2027
            WHERE id = ?
        ");
        $updEdu->execute([$eduRow['id']]);
    } else {
        $insEdu = $pdo->prepare("
            INSERT INTO education (
                applicant_id, education_level, institution_name, institution_type, campus,
                course_programme, department, registration_number, year_of_study, semester,
                academic_year, study_mode, expected_graduation_year, created_at
            ) VALUES (
                ?, 'university', 'University of Nairobi', 'Public University', 'Main Campus',
                'Bachelor of Science in Computer Science', 'School of Computing and Informatics', 'P15/142850/2022', 'Year 3', 'Semester 1',
                '2025/2026', 'full_time', 2027, NOW()
            )
        ");
        $insEdu->execute([$applicantId]);
    }

    // Remove old drafts for this test user so they can do the entire flow fresh
    $pdo->prepare("DELETE FROM applications WHERE applicant_id = ?")->execute([$applicantId]);
    $pdo->prepare("DELETE FROM guardians WHERE applicant_id = ?")->execute([$applicantId]);
    $pdo->prepare("DELETE FROM documents WHERE applicant_id = ?")->execute([$applicantId]);

    // Send a welcome notification
    createNotification(
        $pdo,
        $appUserId,
        'Welcome to E-Bursary Portal',
        'Welcome Brian Mwangi! Your personal profile and education info are ready. You can now proceed to guardian details and apply for bursaries.'
    );

    outputMsg("  -> Demo Applicant: username 'applicant', password 'password123'", $isCli);
    outputMsg("     (Profile & Education are filled -> Ready to test Guardian -> Request -> Uploads -> Submit!)", $isCli);

    // 5. UPDATE EXISTING APPLICANT PASSWORDS TO KNOWN VALUE
    outputMsg("[5/5] Syncing standard passwords for existing sample accounts...", $isCli);
    $stdHash = password_hash('password123', PASSWORD_DEFAULT);
    $pdo->prepare("UPDATE users SET password = ? WHERE role = 'applicant'")->execute([$stdHash]);
    outputMsg("  -> All applicant accounts reset to default password: 'password123'", $isCli);

    $pdo->commit();

    outputMsg("============================================================", $isCli);
    outputMsg("   DATABASE SEEDING COMPLETED SUCCESSFULLY!", $isCli);
    outputMsg("============================================================", $isCli);
    outputMsg("", $isCli);
    outputMsg("LOGIN CREDENTIALS:", $isCli);
    outputMsg("1. Administrator Portal:", $isCli);
    outputMsg("   - URL:      http://localhost/E-bursary/login.php", $isCli);
    outputMsg("   - Username: admin", $isCli);
    outputMsg("   - Password: admin123", $isCli);
    outputMsg("", $isCli);
    outputMsg("2. Demo Applicant (To test continuous step flow):", $isCli);
    outputMsg("   - URL:      http://localhost/E-bursary/login.php", $isCli);
    outputMsg("   - Username: applicant", $isCli);
    outputMsg("   - Password: password123", $isCli);
    outputMsg("   - Name:     Brian Mwangi (Steps 1 & 2 ready -> Continue Step 3)", $isCli);
    outputMsg("", $isCli);
    outputMsg("3. Existing Applicants (Submitted / Approved):", $isCli);
    outputMsg("   - Username: Win Kate   (Approved application)   | Password: password123", $isCli);
    outputMsg("   - Username: EN-31919   (Pending application)    | Password: password123", $isCli);
    outputMsg("   - Username: vic123     (Submitted application)  | Password: password123", $isCli);
    outputMsg("============================================================", $isCli);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    outputMsg("[ERROR] Seeding failed: " . $e->getMessage(), $isCli);
    exit(1);
}

