<?php
// ============================================================
//  AUTH HANDLER — Sign In & Register
//  Water Management System
// ============================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'db.php';

$data   = json_decode(file_get_contents('php://input'), true);
$action = $data['action'] ?? '';

// ── SIGN IN ──────────────────────────────────────────────────
if ($action === 'signin') {
    $name        = trim($data['name'] ?? '');
    $customer_id = trim($data['customer_id'] ?? '');

    if (!$name || !$customer_id) {
        echo json_encode(['success' => false, 'message' => 'Please fill in all fields.']);
        exit;
    }

    $stmt = $pdo->prepare(
        "SELECT Customer_ID, Name, Status FROM Customer
         WHERE Customer_ID = ? AND Name = ?"
    );
    $stmt->execute([$customer_id, $name]);
    $customer = $stmt->fetch();

    if (!$customer) {
        echo json_encode(['success' => false, 'message' => 'Invalid name or Customer ID.']);
        exit;
    }

    if ($customer['Status'] !== 'Active') {
        echo json_encode(['success' => false, 'message' => 'Your account is inactive. Contact support.']);
        exit;
    }

    echo json_encode([
        'success'  => true,
        'message'  => 'Welcome back, ' . $customer['Name'] . '!',
        'customer' => [
            'id'   => $customer['Customer_ID'],
            'name' => $customer['Name'],
        ]
    ]);
    exit;
}

// ── REGISTER ─────────────────────────────────────────────────
if ($action === 'register') {
    $name    = trim($data['name']    ?? '');
    $address = trim($data['address'] ?? '');
    $income  = trim($data['income']  ?? '0');
    $phone   = trim($data['phone']   ?? '');

    if (!$name || !$address || !$phone) {
        echo json_encode(['success' => false, 'message' => 'Please fill in all required fields.']);
        exit;
    }

    // Check if name already exists
    $stmt = $pdo->prepare("SELECT Customer_ID FROM Customer WHERE Name = ?");
    $stmt->execute([$name]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'A customer with this name already exists.']);
        exit;
    }

    // Insert new customer
    $stmt = $pdo->prepare(
        "INSERT INTO Customer (Name, Date, Address, Income, Status)
         VALUES (?, CURDATE(), ?, ?, 'Active')"
    );
    $stmt->execute([$name, $address, $income]);
    $new_id = $pdo->lastInsertId();

    // Insert phone number into Number table
    $stmt = $pdo->prepare("INSERT INTO Number (Customer_ID, Number) VALUES (?, ?)");
    $stmt->execute([$new_id, $phone]);

    echo json_encode([
        'success'     => true,
        'message'     => 'Registration successful! Your Customer ID is ' . $new_id . '. Please save it for sign in.',
        'customer_id' => $new_id,
    ]);
    exit;
}

// ── UNKNOWN ACTION ────────────────────────────────────────────
echo json_encode(['success' => false, 'message' => 'Unknown action.']);
