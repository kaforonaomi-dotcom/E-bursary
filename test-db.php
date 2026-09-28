<?php

require_once __DIR__ . '/config/database.php';

try {
    $stmt = $pdo->query("SELECT DATABASE() AS database_name");
    $result = $stmt->fetch();

    echo "<h2>Database connection successful!</h2>";
    echo "<p>Connected to: " . htmlspecialchars($result['database_name']) . "</p>";

} catch (PDOException $e) {
    echo "<h2>Database connection failed.</h2>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
}
