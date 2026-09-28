# E-Bursary Management System

A web-based Electronic Bursary Management System built with PHP, MySQL/MariaDB, Apache/XAMPP, Bootstrap 5, HTML, CSS, and JavaScript. Designed following official County and Government bursary management standards with a green and white visual identity.

---

## 🌟 Key Features

### Applicant Portal
- **Unified Account Registration & Authentication**: Secure registration and unified sign-in routing.
- **Personal Information Management**: Comprehensive identification, contact, and residency profiles.
- **Dynamic Education Module**: Conditional form handling for Primary School, High School (Boarding/Day), and University/College/TVET.
- **Parent / Guardian Details**: Tracking guardian identity, occupation, and contact (mandatory for minors).
- **Supporting Document Verification**: Upload portal for passport photos, national IDs, fee structures, and admission proof with upload validation (PDF, JPG, PNG).
- **Application Workflow & Drafts**: Step-by-step bursary application with ability to save progress as draft.
- **Pre-submission Review**: Comprehensive review checklist verifying all required documents and details before submission.
- **Status & Financial Tracking**: Live tracking of application review status, committee comments, approved amount, allocation records, and disbursement vouchers.
- **In-App Notifications**: Real-time updates for submissions, status updates, and disbursements.

### Administrator Portal
- **Executive Dashboard**: Real-time KPI metrics (total applicants, pending reviews, approvals, allocations, disbursements).
- **Applicant Directory**: Search and filter applicants with complete dossier views.
- **Application Review System**: In-depth application review, decision actions (Approve with amount, Reject with reason, Under Review), and notes.
- **Bursary Programs Management**: Create and configure funding schemes, target education levels, award limits, and application deadlines.
- **Fund Allocations**: Allocate committee-approved bursary awards to students.
- **Document Verification Queue**: Central queue for verifying or rejecting applicant documents with feedback.
- **Disbursements Management**: Issue and track cheque, EFT, and bank transfers, automatically updating allocation statuses.
- **Reports & Analytics**: High-level and granular reports by education level, program, and regional intake.
- **System Settings**: Global application intake toggle (Open/Closed) and application deadline management.

---

## 🛠️ Technology Stack
- **Backend**: PHP 8.x (Vanilla PHP, PDO Prepared Statements)
- **Database**: MySQL / MariaDB (`bursary_db`)
- **Frontend**: Bootstrap 5.3, Bootstrap Icons, Custom CSS, Vanilla JavaScript
- **Web Server**: Apache (XAMPP environment)

---

## 🚀 Installation & Setup

1. **Clone the Repository**:
   ```bash
   git clone <repository-url>
   ```
   Place the project folder inside your web server directory (e.g., `C:\xampp\htdocs\E-bursary`).

2. **Start Services**:
   - Start **Apache** and **MySQL** in your XAMPP Control Panel.

3. **Import Database Schema & Seed Data**:
   - Open **phpMyAdmin** (`http://localhost/phpmyadmin`).
   - Create a new database named `bursary_db`.
   - Import `database/schema.sql`.
   - (Optional) Import `database/seed.sql` to populate sample programs and system settings.

4. **Verify Database Configuration**:
   - Check `config/database.php`:
     ```php
     $host = "localhost";
     $dbname = "bursary_db";
     $username = "root";
     $password = "";
     ```

5. **Launch the Application**:
   - Open your browser and navigate to:
     ```
     http://localhost/E-bursary/
     ```

---

## 🔑 Default Administrator Account
- **Username**: `admin`
- **Password**: `admin123`
- **Role**: `super_admin`
- *(To recreate or reset the admin account, run `create-admin.php`)*

---

## 🔒 Security Measures
- **Tenant Isolation**: Strict role and session checks prevent applicants from viewing or modifying other applicants' data.
- **SQL Injection Prevention**: All queries use PDO prepared statements.
- **XSS Mitigation**: Contextual HTML entity escaping (`e()`).
- **CSRF Protection**: Form token generation and validation.
- **Secure File Uploads**: Extension whitelisting, MIME type verification, unique sanitized filenames, and `.htaccess` script execution prevention.
