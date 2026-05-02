<?php
// ============================================================
//  DATABASE CONNECTION — Water Management System
//  Edit the values below to match your phpMyAdmin setup
// ============================================================

$host     = 'localhost';
$db_name  = 'water management system';      // ← change to your database name in phpMyAdmin
$username = 'root';           // ← default XAMPP/WAMP username
$password = '';               // ← default XAMPP/WAMP password (usually empty)

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$db_name;charset=utf8",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}
?>
