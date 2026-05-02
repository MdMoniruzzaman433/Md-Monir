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
        "SELECT Customer_ID, Name, Status FROM customer
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
    $c_type  = trim($data['c_type']  ?? 'Residential');

    if (!$name || !$address || !$phone) {
        echo json_encode(['success' => false, 'message' => 'Please fill in all required fields.']);
        exit;
    }

    // Check if name already exists
    $stmt = $pdo->prepare("SELECT Customer_ID FROM customer WHERE Name = ?");
    $stmt->execute([$name]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'A customer with this name already exists.']);
        exit;
    }

    // Wrap all 4 inserts in a transaction — all succeed or all fail
    $pdo->beginTransaction();
    try {
        // 1. Insert Customer
        $stmt = $pdo->prepare(
            "INSERT INTO customer (Name, Date, Address, Income, Status)
             VALUES (?, CURDATE(), ?, ?, 'Active')"
        );
        $stmt->execute([$name, $address, $income]);
        $new_id = $pdo->lastInsertId();

        // 2. Insert phone number into Number table
        $stmt = $pdo->prepare("INSERT INTO number (Customer_ID, Number) VALUES (?, ?)");
        $stmt->execute([$new_id, $phone]);

        // 3. Create a Connection for this customer
        $stmt = $pdo->prepare(
            "INSERT INTO connection (Customer_ID, c_type, c_status, c_date)
             VALUES (?, ?, 'Active', CURDATE())"
        );
        $stmt->execute([$new_id, $c_type]);
        $conn_id = $pdo->lastInsertId();

        // 4. Create a Meter linked to the Connection
        $stmt = $pdo->prepare(
            "INSERT INTO meter (Connection_ID, m_type, m_status)
             VALUES (?, 'Digital', 'Working')"
        );
        $stmt->execute([$conn_id]);
        $meter_id = $pdo->lastInsertId();

        $pdo->commit();

        echo json_encode([
            'success'       => true,
            'message'       => 'Registration successful! Your Customer ID is ' . $new_id . '. Please save it for sign in.',
            'customer_id'   => $new_id,
            'connection_id' => $conn_id,
            'meter_id'      => $meter_id,
        ]);

    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Registration failed: ' . $e->getMessage()]);
    }
    exit;
}

// ── UNKNOWN ACTION ────────────────────────────────────────────
echo json_encode(['success' => false, 'message' => 'Unknown action.']);
