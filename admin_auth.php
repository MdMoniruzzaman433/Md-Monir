<?php
// ============================================================
//  ADMIN AUTH — Water Management System
//  Simple hardcoded admin credentials (no Admin table needed)
//  Change username/password below before deployment
// ============================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// ── Admin credentials — change these ─────────────────────────
define('ADMIN_USERNAME', 'admin');
define('ADMIN_PASSWORD', 'admin123');

$data   = json_decode(file_get_contents('php://input'), true);
$action = $data['action'] ?? '';

if ($action === 'login') {
    $username = trim($data['username'] ?? '');
    $password = $data['password'] ?? '';

    if ($username === ADMIN_USERNAME && $password === ADMIN_PASSWORD) {
        echo json_encode(['success' => true, 'message' => 'Login successful.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid username or password.']);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action.']);
?>
