<?php
require_once __DIR__ . '/config/database.php';
$stmt = $pdo->query("SELECT al.*, a.program_id, p.name as prog_name, p.education_level FROM allocations al JOIN applications a ON al.application_id = a.id JOIN programs p ON a.program_id = p.id");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
