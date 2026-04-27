<?php
// ============================================================
//  DASHBOARD DATA FETCHER — Water Management System
// ============================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'db.php';

$data        = json_decode(file_get_contents('php://input'), true);
$customer_id = intval($data['customer_id'] ?? 0);

if (!$customer_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid customer ID.']);
    exit;
}

// ── Customer basic info ───────────────────────────────────────
$stmt = $pdo->prepare(
    "SELECT Customer_ID, Name, Address, Income, Status
     FROM Customer
     WHERE Customer_ID = ?"
);
$stmt->execute([$customer_id]);
$customer = $stmt->fetch();

if (!$customer) {
    echo json_encode(['success' => false, 'message' => 'Customer not found.']);
    exit;
}

// ── Phone numbers ─────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT Number FROM Number WHERE Customer_ID = ?");
$stmt->execute([$customer_id]);
$phones = $stmt->fetchAll(PDO::FETCH_COLUMN);

// ── Meter info via Connection ─────────────────────────────────
$stmt = $pdo->prepare(
    "SELECT m.Meter_ID, m.m_type, m.m_status,
            c.Connection_ID, c.c_type, c.c_status, c.c_date
     FROM Connection c
     JOIN Meter m ON m.Connection_ID = c.Connection_ID
     WHERE c.Customer_ID = ?
     ORDER BY c.c_date DESC"
);
$stmt->execute([$customer_id]);
$meters = $stmt->fetchAll();

// ── Bills ─────────────────────────────────────────────────────
$stmt = $pdo->prepare(
    "SELECT b.Bill_ID, b.bill_date, b.fixed_bill, b.tax, b.penalty,
            (b.fixed_bill + b.tax + b.penalty) AS total_payable,
            b.payment_status, b.payment_date,
            r.r_prev, r.r_current,
            (r.r_current - r.r_prev) AS units_consumed
     FROM Bill b
     JOIN Reading r ON b.Reading_ID = r.Reading_ID
     WHERE b.Customer_ID = ?
     ORDER BY b.bill_date DESC"
);
$stmt->execute([$customer_id]);
$bills = $stmt->fetchAll();

echo json_encode([
    'success'  => true,
    'customer' => $customer,
    'phones'   => $phones,
    'meters'   => $meters,
    'bills'    => $bills,
]);
?>
