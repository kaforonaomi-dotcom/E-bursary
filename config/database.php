<?php

$host = "127.0.0.1";
$dbname = "bursary_db";
$username = "root";
$password = "";

// Check port 3307 (XAMPP MySQL when 3306 is occupied by MySQL 8.0) then fallback to 3306
$ports = [3307, 3306];
$pdo = null;
$lastException = null;

foreach ($ports as $p) {
    try {
        $pdo = new PDO(
            "mysql:host=$host;port=$p;dbname=$dbname;charset=utf8mb4",
            $username,
            $password
        );
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        break;
    } catch (PDOException $e) {
        $lastException = $e;
    }
}

if (!$pdo) {
    die("Database connection failed: " . ($lastException ? $lastException->getMessage() : "Unknown error"));
}
