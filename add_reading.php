<?php
// ============================================================
//  ADD READING + GENERATE BILL — Water Management System
//  Handles: get_meters, get_readings, add_reading
//  Bill is auto-generated inside add_reading
// ============================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'db.php';

// ── BILLING SETTINGS ──────────────────────────────────────────
define('RATE_PER_UNIT',     5.00);   // BDT per unit consumed
define('TAX_RATE',          0.05);   // 5% tax
define('SUBSIDY_INCOME',   30000);   // income below this = subsidy
define('SUBSIDY_DISCOUNT',  0.10);   // 10% discount for low income
define('ABNORMAL_LIMIT',    500);    // units above this = abnormal
define('PENALTY_AMOUNT',   50.00);   // penalty for overdue (added separately)

$data   = json_decode(file_get_contents('php://input'), true);
$action = $data['action'] ?? '';


// ================================================================
// ACTION: GET ALL ACTIVE METERS WITH LAST READING
// ================================================================
if ($action === 'get_meters') {
    $stmt = $pdo->query(
        "SELECT
            m.Meter_ID,
            m.m_status,
            m.m_type,
            c.Connection_ID,
            c.c_type,
            c.Customer_ID,
            cu.Name   AS customer_name,
            cu.Income,
            -- last recorded reading for this meter
            (SELECT r.r_current
             FROM reading r
             WHERE r.Meter_ID = m.Meter_ID
             ORDER BY r.r_date DESC, r.Reading_ID DESC
             LIMIT 1
            ) AS last_reading
         FROM meter m
         JOIN connection c  ON m.Connection_ID = c.Connection_ID
         JOIN customer   cu ON c.Customer_ID   = cu.Customer_ID
         WHERE c.c_status = 'Active'
         ORDER BY m.Meter_ID"
    );
    $meters = $stmt->fetchAll();
    echo json_encode(['success' => true, 'meters' => $meters]);
    exit;
}


// ================================================================
// ACTION: GET RECENT READINGS (last 20)
// ================================================================
if ($action === 'get_readings') {
    $stmt = $pdo->query(
        "SELECT r.Reading_ID, r.Meter_ID, r.r_date, r.r_prev,
                r.r_current, r.r_status,
                cu.Name AS customer_name
         FROM reading r
         JOIN meter      m  ON r.Meter_ID      = m.Meter_ID
         JOIN connection cn ON m.Connection_ID = cn.Connection_ID
         JOIN customer   cu ON cn.Customer_ID  = cu.Customer_ID
         ORDER BY r.r_date DESC, r.Reading_ID DESC
         LIMIT 20"
    );
    $readings = $stmt->fetchAll();
    echo json_encode(['success' => true, 'readings' => $readings]);
    exit;
}


// ================================================================
// ACTION: ADD READING + AUTO-GENERATE BILL
// ================================================================
if ($action === 'add_reading') {
    $meter_id    = intval($data['meter_id']    ?? 0);
    $r_prev      = floatval($data['r_prev']    ?? 0);
    $r_current   = floatval($data['r_current'] ?? 0);
    $r_date      = $data['r_date'] ?? date('Y-m-d');
    $customer_id = intval($data['customer_id'] ?? 0);

    // ── Validate ──────────────────────────────────────────────
    if (!$meter_id || !$customer_id) {
        echo json_encode(['success' => false, 'message' => 'Invalid meter or customer.']);
        exit;
    }
    if ($r_current < $r_prev) {
        echo json_encode(['success' => false, 'message' => 'Current reading cannot be less than previous reading.']);
        exit;
    }

    // ── Get meter status ──────────────────────────────────────
    $stmt = $pdo->prepare("SELECT m_status FROM meter WHERE Meter_ID = ?");
    $stmt->execute([$meter_id]);
    $meter = $stmt->fetch();
    if (!$meter) {
        echo json_encode(['success' => false, 'message' => 'Meter not found.']);
        exit;
    }

    // ── Determine reading status ──────────────────────────────
    $consumption = $r_current - $r_prev;
    $r_status    = 'Normal';

    if ($meter['m_status'] === 'Faulty' || $consumption == 0) {
        $r_status = 'Faulty';
    } elseif ($consumption > ABNORMAL_LIMIT) {
        $r_status = 'Abnormal';
    }

    // ── Insert Reading ────────────────────────────────────────
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO reading (Meter_ID, r_date, r_prev, r_current, r_status)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([$meter_id, $r_date, $r_prev, $r_current, $r_status]);
        $reading_id = $pdo->lastInsertId();

        // ── Create alert if abnormal or faulty ────────────────
        if ($r_status === 'Faulty') {
            $a_type = ($consumption == 0) ? 'Missing Reading' : 'Faulty Meter';
            $stmt = $pdo->prepare(
                "INSERT INTO alert (Reading_ID, a_type, a_status, a_date)
                 VALUES (?, ?, 'Open', ?)"
            );
            $stmt->execute([$reading_id, $a_type, $r_date]);
        } elseif ($r_status === 'Abnormal') {
            $stmt = $pdo->prepare(
                "INSERT INTO alert (Reading_ID, a_type, a_status, a_date)
                 VALUES (?, 'Abnormal Usage', 'Open', ?)"
            );
            $stmt->execute([$reading_id, $r_date]);

            // Also check for possible leakage (> 3x average)
            $avg_stmt = $pdo->prepare(
                "SELECT AVG(r_current - r_prev) AS avg_c FROM reading
                 WHERE Meter_ID = ? AND r_status = 'Normal'"
            );
            $avg_stmt->execute([$meter_id]);
            $avg = $avg_stmt->fetchColumn();
            if ($avg && $consumption > 3 * $avg) {
                $stmt = $pdo->prepare(
                    "INSERT INTO alert (Reading_ID, a_type, a_status, a_date)
                     VALUES (?, 'Leakage', 'Open', ?)"
                );
                $stmt->execute([$reading_id, $r_date]);
            }
        }

        // ── Generate Bill (only if not faulty) ───────────────
        $bill_id = null;
        if ($r_status !== 'Faulty' && $consumption > 0) {

            // Get customer income for subsidy check
            $stmt = $pdo->prepare("SELECT Income FROM customer WHERE Customer_ID = ?");
            $stmt->execute([$customer_id]);
            $income = floatval($stmt->fetchColumn());

            // Calculate bill
            $fixed_bill = $consumption * RATE_PER_UNIT;

            // Apply subsidy discount for low income customers
            if ($income > 0 && $income < SUBSIDY_INCOME) {
                $fixed_bill = $fixed_bill * (1 - SUBSIDY_DISCOUNT);
            }

            $tax        = $fixed_bill * TAX_RATE;
            $penalty    = 0.00; // penalty added later if overdue
            $due_date   = date('Y-m-d', strtotime($r_date . ' +30 days'));

            $stmt = $pdo->prepare(
                "INSERT INTO bill
                    (Customer_ID, Reading_ID, bill_date, fixed_bill, tax, penalty, payment_status)
                 VALUES (?, ?, ?, ?, ?, ?, 'Unpaid')"
            );
            $stmt->execute([$customer_id, $reading_id, $r_date, $fixed_bill, $tax, $penalty]);
            $bill_id = $pdo->lastInsertId();
        }

        $pdo->commit();

        // ── Build response message ────────────────────────────
        $msg = "Reading saved successfully (status: $r_status).";
        if ($bill_id) {
            $total = ($r_status !== 'Faulty') ?
                round(($consumption * RATE_PER_UNIT * (($income ?? 0) < SUBSIDY_INCOME && ($income ?? 0) > 0 ? (1 - SUBSIDY_DISCOUNT) : 1)) * (1 + TAX_RATE), 2)
                : 0;
            $msg .= " Bill #$bill_id generated — Total: ৳$total.";
        } elseif ($r_status === 'Faulty') {
            $msg .= " No bill generated (faulty reading). Alert created.";
        }

        echo json_encode([
            'success'    => true,
            'message'    => $msg,
            'reading_id' => $reading_id,
            'bill_id'    => $bill_id,
            'r_status'   => $r_status,
        ]);

    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Error saving reading: ' . $e->getMessage()]);
    }
    exit;
}


// ── UNKNOWN ACTION ─────────────────────────────────────────────
echo json_encode(['success' => false, 'message' => 'Unknown action.']);
?>
