<?php
// ============================================================
//  GENERATE BILL / BILL MANAGEMENT — Water Management System
//  Actions: get_bills, mark_paid, add_penalty, get_bill_detail
// ============================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'db.php';

define('PENALTY_AMOUNT', 50.00);  // flat penalty for overdue bills

$data   = json_decode(file_get_contents('php://input'), true);
$action = $data['action'] ?? '';


// ================================================================
// ACTION: GET ALL BILLS (with filters)
// ================================================================
if ($action === 'get_bills') {
    $status_filter = $data['status'] ?? 'all'; // all / Paid / Unpaid / Overdue

    $where = '';
    $params = [];
    if ($status_filter !== 'all') {
        $where = 'WHERE b.payment_status = ?';
        $params[] = $status_filter;
    }

    $stmt = $pdo->prepare(
        "SELECT
            b.Bill_ID,
            b.bill_date,
            b.fixed_bill,
            b.tax,
            b.penalty,
            (b.fixed_bill + b.tax + b.penalty) AS total_payable,
            b.payment_status,
            b.payment_date,
            cu.Customer_ID,
            cu.Name    AS customer_name,
            cu.Income,
            r.r_prev,
            r.r_current,
            (r.r_current - r.r_prev) AS units_consumed,
            r.r_status AS reading_status,
            m.Meter_ID
         FROM bill b
         JOIN customer cu ON b.Customer_ID = cu.Customer_ID
         JOIN reading  r  ON b.Reading_ID  = r.Reading_ID
         JOIN meter    m  ON r.Meter_ID    = m.Meter_ID
         $where
         ORDER BY b.bill_date DESC"
    );
    $stmt->execute($params);
    $bills = $stmt->fetchAll();

    // Summary stats
    $total_billed    = array_sum(array_column($bills, 'total_payable'));
    $total_collected = 0;
    $total_overdue   = 0;
    foreach ($bills as $b) {
        if ($b['payment_status'] === 'Paid')    $total_collected += $b['total_payable'];
        if ($b['payment_status'] === 'Overdue') $total_overdue   += $b['total_payable'];
    }

    echo json_encode([
        'success'         => true,
        'bills'           => $bills,
        'summary'         => [
            'total_bills'     => count($bills),
            'total_billed'    => round($total_billed, 2),
            'total_collected' => round($total_collected, 2),
            'total_overdue'   => round($total_overdue, 2),
        ]
    ]);
    exit;
}


// ================================================================
// ACTION: MARK BILL AS PAID
// ================================================================
if ($action === 'mark_paid') {
    $bill_id     = intval($data['bill_id'] ?? 0);
    $payment_date = $data['payment_date'] ?? date('Y-m-d');

    if (!$bill_id) {
        echo json_encode(['success' => false, 'message' => 'Invalid bill ID.']);
        exit;
    }

    // Check bill exists and is not already paid
    $stmt = $pdo->prepare("SELECT payment_status FROM bill WHERE Bill_ID = ?");
    $stmt->execute([$bill_id]);
    $bill = $stmt->fetch();

    if (!$bill) {
        echo json_encode(['success' => false, 'message' => 'Bill not found.']);
        exit;
    }
    if ($bill['payment_status'] === 'Paid') {
        echo json_encode(['success' => false, 'message' => 'Bill is already marked as paid.']);
        exit;
    }

    $stmt = $pdo->prepare(
        "UPDATE bill
         SET payment_status = 'Paid', payment_date = ?
         WHERE Bill_ID = ?"
    );
    $stmt->execute([$payment_date, $bill_id]);

    echo json_encode(['success' => true, 'message' => "Bill #$bill_id marked as Paid."]);
    exit;
}


// ================================================================
// ACTION: MARK OVERDUE + ADD PENALTY
// ================================================================
if ($action === 'add_penalty') {
    $bill_id = intval($data['bill_id'] ?? 0);

    if (!$bill_id) {
        echo json_encode(['success' => false, 'message' => 'Invalid bill ID.']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT payment_status, penalty FROM bill WHERE Bill_ID = ?");
    $stmt->execute([$bill_id]);
    $bill = $stmt->fetch();

    if (!$bill) {
        echo json_encode(['success' => false, 'message' => 'Bill not found.']);
        exit;
    }
    if ($bill['payment_status'] === 'Paid') {
        echo json_encode(['success' => false, 'message' => 'Cannot add penalty to a paid bill.']);
        exit;
    }

    $new_penalty = floatval($bill['penalty']) + PENALTY_AMOUNT;

    $stmt = $pdo->prepare(
        "UPDATE bill
         SET payment_status = 'Overdue', penalty = ?
         WHERE Bill_ID = ?"
    );
    $stmt->execute([$new_penalty, $bill_id]);

    echo json_encode([
        'success' => true,
        'message' => "Bill #$bill_id marked Overdue. Penalty added: ৳" . PENALTY_AMOUNT . ". Total penalty: ৳$new_penalty."
    ]);
    exit;
}


// ================================================================
// ACTION: BULK MARK OVERDUE (all unpaid bills past due date)
// ================================================================
if ($action === 'bulk_overdue') {
    // Any unpaid bill older than 30 days from bill_date is overdue
    $stmt = $pdo->prepare(
        "UPDATE bill
         SET payment_status = 'Overdue',
             penalty = penalty + ?
         WHERE payment_status = 'Unpaid'
           AND bill_date < DATE_SUB(CURDATE(), INTERVAL 30 DAY)"
    );
    $stmt->execute([PENALTY_AMOUNT]);
    $affected = $stmt->rowCount();

    echo json_encode([
        'success' => true,
        'message' => "$affected bill(s) marked as Overdue with penalty of ৳" . PENALTY_AMOUNT . " each."
    ]);
    exit;
}


// ================================================================
// ACTION: GET SINGLE BILL DETAIL
// ================================================================
if ($action === 'get_bill_detail') {
    $bill_id = intval($data['bill_id'] ?? 0);
    if (!$bill_id) {
        echo json_encode(['success' => false, 'message' => 'Invalid bill ID.']);
        exit;
    }

    $stmt = $pdo->prepare(
        "SELECT
            b.*,
            (b.fixed_bill + b.tax + b.penalty) AS total_payable,
            cu.Name    AS customer_name,
            cu.Address AS customer_address,
            cu.Income,
            r.r_prev, r.r_current, r.r_date AS reading_date,
            (r.r_current - r.r_prev) AS units_consumed,
            r.r_status AS reading_status,
            m.Meter_ID, m.m_type
         FROM bill b
         JOIN customer cu ON b.Customer_ID = cu.Customer_ID
         JOIN reading  r  ON b.Reading_ID  = r.Reading_ID
         JOIN meter    m  ON r.Meter_ID    = m.Meter_ID
         WHERE b.Bill_ID = ?"
    );
    $stmt->execute([$bill_id]);
    $bill = $stmt->fetch();

    if (!$bill) {
        echo json_encode(['success' => false, 'message' => 'Bill not found.']);
        exit;
    }

    // Check if subsidy was applied
    $bill['subsidy_applied'] = ($bill['Income'] > 0 && $bill['Income'] < 30000);

    echo json_encode(['success' => true, 'bill' => $bill]);
    exit;
}


// ── UNKNOWN ACTION ─────────────────────────────────────────────
echo json_encode(['success' => false, 'message' => 'Unknown action.']);
?>
