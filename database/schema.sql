-- ============================================================
-- E-BURSARY MANAGEMENT SYSTEM
-- Database Schema
-- ============================================================

-- ------------------------------------------------------------
-- USERS
-- One login for both applicants and administrators
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NULL UNIQUE,
    email VARCHAR(150) NULL UNIQUE,
    password VARCHAR(255) NOT NULL,

    role ENUM('applicant', 'super_admin') NOT NULL DEFAULT 'applicant',

    is_active TINYINT(1) NOT NULL DEFAULT 1,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ------------------------------------------------------------
-- APPLICANT PROFILES
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS applicants (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id INT UNSIGNED NOT NULL UNIQUE,

    first_name VARCHAR(100) NOT NULL,
    middle_name VARCHAR(100) NULL,
    last_name VARCHAR(100) NOT NULL,
    other_name VARCHAR(100) NULL,

    id_number VARCHAR(50) NULL UNIQUE,
    date_of_birth DATE NULL,
    gender ENUM('Male', 'Female', 'Other') NULL,

    phone VARCHAR(30) NULL,
    alternative_phone VARCHAR(30) NULL,
    email VARCHAR(150) NULL,

    county VARCHAR(100) NULL,
    sub_county VARCHAR(100) NULL,
    ward VARCHAR(100) NULL,
    physical_address TEXT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_applicant_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ------------------------------------------------------------
-- EDUCATION INFORMATION
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS education (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    applicant_id INT UNSIGNED NOT NULL,

    education_level ENUM(
        'primary',
        'high_school',
        'university'
    ) NOT NULL,

    institution_name VARCHAR(200) NOT NULL,
    institution_type VARCHAR(100) NULL,

    campus VARCHAR(150) NULL,

    county VARCHAR(100) NULL,
    sub_county VARCHAR(100) NULL,
    ward VARCHAR(100) NULL,

    course_programme VARCHAR(200) NULL,
    department VARCHAR(200) NULL,

    class_grade VARCHAR(100) NULL,
    admission_number VARCHAR(100) NULL,
    registration_number VARCHAR(100) NULL,

    year_of_study VARCHAR(50) NULL,
    semester VARCHAR(50) NULL,

    academic_year VARCHAR(50) NULL,

    study_mode ENUM(
        'full_time',
        'part_time',
        'distance'
    ) NULL,

    boarding_type ENUM(
        'boarding',
        'day'
    ) NULL,

    expected_graduation_year YEAR NULL,

    school_contact VARCHAR(100) NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_education_applicant
        FOREIGN KEY (applicant_id)
        REFERENCES applicants(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ------------------------------------------------------------
-- GUARDIAN / PARENT INFORMATION
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS guardians (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    applicant_id INT UNSIGNED NOT NULL,

    first_name VARCHAR(100) NOT NULL,
    middle_name VARCHAR(100) NULL,
    last_name VARCHAR(100) NOT NULL,

    relationship VARCHAR(100) NOT NULL,
    other_relationship VARCHAR(100) NULL,

    phone VARCHAR(30) NULL,
    alternative_phone VARCHAR(30) NULL,

    id_number VARCHAR(50) NULL,
    email VARCHAR(150) NULL,

    occupation VARCHAR(150) NULL,
    employer_business VARCHAR(200) NULL,

    county VARCHAR(100) NULL,
    sub_county VARCHAR(100) NULL,
    ward VARCHAR(100) NULL,
    physical_address TEXT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_guardian_applicant
        FOREIGN KEY (applicant_id)
        REFERENCES applicants(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ------------------------------------------------------------
-- BURSARY PROGRAMS
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS programs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    name VARCHAR(200) NOT NULL,
    description TEXT NULL,

    education_level ENUM(
        'primary',
        'high_school',
        'university',
        'all'
    ) NOT NULL DEFAULT 'all',

    maximum_amount DECIMAL(12,2) NULL,
    total_budget DECIMAL(14,2) NOT NULL DEFAULT 0.00,

    application_open_date DATE NULL,
    application_close_date DATE NULL,

    status ENUM(
        'active',
        'inactive',
        'closed'
    ) NOT NULL DEFAULT 'active',

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ------------------------------------------------------------
-- APPLICATIONS
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS applications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    applicant_id INT UNSIGNED NOT NULL,
    program_id INT UNSIGNED NULL,

    application_number VARCHAR(50) NOT NULL UNIQUE,

    requested_amount DECIMAL(12,2) NULL,

    financial_need TEXT NULL,
    reason_for_application TEXT NULL,

    status ENUM(
        'draft',
        'submitted',
        'pending',
        'under_review',
        'approved',
        'rejected'
    ) NOT NULL DEFAULT 'draft',

    submitted_at DATETIME NULL,
    reviewed_at DATETIME NULL,

    rejection_reason TEXT NULL,
    admin_comment TEXT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_application_applicant
        FOREIGN KEY (applicant_id)
        REFERENCES applicants(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_application_program
        FOREIGN KEY (program_id)
        REFERENCES programs(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ------------------------------------------------------------
-- DOCUMENTS
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS documents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    applicant_id INT UNSIGNED NOT NULL,
    application_id INT UNSIGNED NULL,

    document_type ENUM(
        'passport_photo',
        'identification',
        'fee_structure',
        'school_details',
        'admission_proof',
        'guardian_identification',
        'supporting_document'
    ) NOT NULL,

    original_filename VARCHAR(255) NOT NULL,
    stored_filename VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,

    file_type VARCHAR(100) NULL,
    file_size INT UNSIGNED NULL,

    status ENUM(
        'not_submitted',
        'submitted',
        'under_review',
        'verified',
        'rejected'
    ) NOT NULL DEFAULT 'submitted',

    admin_comment TEXT NULL,

    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_document_applicant
        FOREIGN KEY (applicant_id)
        REFERENCES applicants(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_document_application
        FOREIGN KEY (application_id)
        REFERENCES applications(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ------------------------------------------------------------
-- ALLOCATIONS
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS allocations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    application_id INT UNSIGNED NOT NULL,

    approved_amount DECIMAL(12,2) NOT NULL,

    allocation_date DATE NULL,

    academic_year VARCHAR(50) NULL,

    status ENUM(
        'pending',
        'approved',
        'partially_disbursed',
        'fully_disbursed'
    ) NOT NULL DEFAULT 'pending',

    notes TEXT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_allocation_application
        FOREIGN KEY (application_id)
        REFERENCES applications(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ------------------------------------------------------------
-- DISBURSEMENTS
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS disbursements (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    allocation_id INT UNSIGNED NOT NULL,

    amount DECIMAL(12,2) NOT NULL,

    disbursement_date DATE NULL,

    payment_reference VARCHAR(100) NULL,

    payment_method VARCHAR(100) NULL,

    status ENUM(
        'pending',
        'processing',
        'completed',
        'failed'
    ) NOT NULL DEFAULT 'pending',

    notes TEXT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_disbursement_allocation
        FOREIGN KEY (allocation_id)
        REFERENCES allocations(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ------------------------------------------------------------
-- SYSTEM SETTINGS
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT NULL,

    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ------------------------------------------------------------
-- NOTIFICATIONS
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS notifications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id INT UNSIGNED NOT NULL,

    title VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,

    is_read TINYINT(1) NOT NULL DEFAULT 0,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_notification_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ------------------------------------------------------------
-- DEFAULT SYSTEM SETTINGS
-- ------------------------------------------------------------

INSERT IGNORE INTO settings
    (setting_key, setting_value)
VALUES
    ('application_status', 'open'),
    ('application_deadline', NULL),
    ('system_name', 'E-Bursary Management System');