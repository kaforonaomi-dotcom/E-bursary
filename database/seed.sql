-- ============================================================
-- E-BURSARY MANAGEMENT SYSTEM
-- Seed Data for Bursary Programs and System Settings
-- ============================================================

-- Ensure default system settings
INSERT INTO settings (setting_key, setting_value)
VALUES
    ('application_status', 'open'),
    ('application_deadline', DATE_ADD(CURDATE(), INTERVAL 60 DAY)),
    ('system_name', 'County E-Bursary Management System')
ON DUPLICATE KEY UPDATE
    setting_value = VALUES(setting_value);

-- Sample Bursary Programs
INSERT INTO programs (name, description, education_level, maximum_amount, application_open_date, application_close_date, status, created_at)
VALUES
    (
        'High School Tuition & Boarding Bursary 2026',
        'Direct tuition assistance for secondary school students in boarding and day secondary schools to prevent school dropouts.',
        'high_school',
        25000.00,
        CURDATE(),
        DATE_ADD(CURDATE(), INTERVAL 45 DAY),
        'active',
        NOW()
    ),
    (
        'University & TVET Higher Education Fund 2026',
        'Higher education bursary for undergraduate students in public and chartered private universities, national polytechnics, and TVET colleges.',
        'university',
        40000.00,
        CURDATE(),
        DATE_ADD(CURDATE(), INTERVAL 60 DAY),
        'active',
        NOW()
    ),
    (
        'Primary Special Needs & Vulnerable Pupils Fund',
        'Welfare and educational material support for orphaned, vulnerable, and special needs pupils in primary schools.',
        'primary',
        15000.00,
        CURDATE(),
        DATE_ADD(CURDATE(), INTERVAL 90 DAY),
        'active',
        NOW()
    )
ON DUPLICATE KEY UPDATE name = VALUES(name);

