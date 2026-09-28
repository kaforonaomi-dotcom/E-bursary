<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if ($_SESSION['role'] === 'super_admin') {
    header('Location: admin/dashboard.php');
    exit;
}

if ($_SESSION['role'] === 'applicant') {
    header('Location: applicant/dashboard.php');
    exit;
}

header('Location: login.php');
exit;