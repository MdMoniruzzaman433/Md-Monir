<?php
// ============================================================
//  ADMIN DATA — Water Management System
//  Actions: get_summary, get_customers, get_alerts,
//           get_reports, get_top_consumers, resolve_alert
// ============================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'db.php';

$data   = json_decode(file_get_contents('php://input'), true);
$action = $data['action'] ?? '';


// ================================================================
// ACTION: SUMMARY STATS (top cards)
// ================================================================
if ($action === 'get_summary') {
    $stats = [];

    // Total customers
    $stats['total_customers'] = $pdo->query("SELECT COUNT(*) FROM customer")->fetchColumn();

    // Active connections
    $stats['active_connections'] = $pdo->query("SELECT COUNT(*) FROM connection WHERE c_status='Active'")->fetchColumn();

    // Faulty meters
    $stats['faulty_meters'] = $pdo->query("SELECT COUNT(*) FROM meter WHERE m_status='Faulty'")->fetchColumn();

    // Open alerts
    $stats['open_alerts'] = $pdo->query("SELECT COUNT(*) FROM alert WHERE a_status='Open'")->fetchColumn();

    // Total billed this month
    $stats['billed_this_month'] = $pdo->query(
        "SELECT COALESCE(SUM(fixed_bill+tax+penalty),0) FROM bill
         WHERE MONTH(bill_date)=MONTH(CURDATE()) AND YEAR(bill_date)=YEAR(CURDATE())"
    )->fetchColumn();

    // Total collected this month
    $stats['collected_this_month'] = $pdo->query(
        "SELECT COALESCE(SUM(fixed_bill+tax+penalty),0) FROM bill
         WHERE payment_status='Paid'
         AND MONTH(payment_date)=MONTH(CURDATE()) AND YEAR(payment_date)=YEAR(CURDATE())"
    )->fetchColumn();

    // Total outstanding (unpaid + overdue)
    $stats['total_outstanding'] = $pdo->query(
        "SELECT COALESCE(SUM(fixed_bill+tax+penalty),0) FROM bill WHERE payment_status IN('Unpaid','Overdue')"
    )->fetchColumn();

    // Unpaid bills count
    $stats['unpaid_count'] = $pdo->query("SELECT COUNT(*) FROM bill WHERE payment_status='Unpaid'")->fetchColumn();

    echo json_encode(['success' => true, 'stats' => $stats]);
    exit;
}


// ================================================================
// ACTION: ALL CUSTOMERS + CONNECTION + METER INFO
// ================================================================
if ($action === 'get_customers') {
    $stmt = $pdo->query(
        "SELECT
            cu.Customer_ID, cu.Name, cu.Address, cu.Income, cu.Status,
            cu.Date AS reg_date,
            c.Connection_ID, c.c_type, c.c_status,
            m.Meter_ID, m.m_status, m.m_type,
            (SELECT COUNT(*) FROM bill b WHERE b.Customer_ID=cu.Customer_ID AND b.payment_status IN('Unpaid','Overdue')) AS unpaid_bills,
            (SELECT COALESCE(SUM(b.fixed_bill+b.tax+b.penalty),0) FROM bill b WHERE b.Customer_ID=cu.Customer_ID AND b.payment_status IN('Unpaid','Overdue')) AS outstanding
         FROM customer cu
         LEFT JOIN connection c ON c.Customer_ID=cu.Customer_ID
         LEFT JOIN meter m ON m.Connection_ID=c.Connection_ID
         ORDER BY cu.Customer_ID DESC"
    );
    $customers = $stmt->fetchAll();
    echo json_encode(['success' => true, 'customers' => $customers]);
    exit;
}


// ================================================================
// ACTION: ALL OPEN ALERTS
// ================================================================
if ($action === 'get_alerts') {
    $stmt = $pdo->query(
        "SELECT
            a.Alert_ID, a.a_type, a.a_status, a.a_date,
            r.Reading_ID, r.r_prev, r.r_current,
            (r.r_current - r.r_prev) AS consumption,
            r.r_status,
            m.Meter_ID, m.m_status,
            cu.Customer_ID, cu.Name AS customer_name, cu.Address
         FROM alert a
         JOIN reading    r  ON a.Reading_ID   = r.Reading_ID
         JOIN meter      m  ON r.Meter_ID     = m.Meter_ID
         JOIN connection cn ON m.Connection_ID = cn.Connection_ID
         JOIN customer   cu ON cn.Customer_ID  = cu.Customer_ID
         WHERE a.a_status = 'Open'
         ORDER BY a.a_date DESC"
    );
    $alerts = $stmt->fetchAll();
    echo json_encode(['success' => true, 'alerts' => $alerts]);
    exit;
}


// ================================================================
// ACTION: RESOLVE ALERT
// ================================================================
if ($action === 'resolve_alert') {
    $alert_id = intval($data['alert_id'] ?? 0);
    if (!$alert_id) { echo json_encode(['success' => false, 'message' => 'Invalid alert ID.']); exit; }

    $stmt = $pdo->prepare("UPDATE alert SET a_status='Resolved' WHERE Alert_ID=?");
    $stmt->execute([$alert_id]);
    echo json_encode(['success' => true, 'message' => "Alert #$alert_id resolved."]);
    exit;
}


// ================================================================
// ACTION: MONTHLY REPORT
// ================================================================
if ($action === 'get_reports') {
    // Monthly billing trend (last 6 months)
    $monthly = $pdo->query(
        "SELECT
            DATE_FORMAT(bill_date,'%b %Y') AS month,
            DATE_FORMAT(bill_date,'%Y-%m') AS month_sort,
            COUNT(*) AS total_bills,
            ROUND(SUM(fixed_bill+tax+penalty),2) AS total_billed,
            ROUND(SUM(CASE WHEN payment_status='Paid' THEN fixed_bill+tax+penalty ELSE 0 END),2) AS total_collected,
            ROUND(SUM(CASE WHEN payment_status IN('Unpaid','Overdue') THEN fixed_bill+tax+penalty ELSE 0 END),2) AS total_outstanding
         FROM bill
         WHERE bill_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
         GROUP BY month_sort, month
         ORDER BY month_sort"
    )->fetchAll();

    // Top 5 consumers by total water usage
    $top_consumers = $pdo->query(
        "SELECT
            cu.Customer_ID, cu.Name, cu.Address,
            ROUND(SUM(r.r_current - r.r_prev),2) AS total_units,
            COUNT(r.Reading_ID) AS total_readings
         FROM reading r
         JOIN meter      m  ON r.Meter_ID      = m.Meter_ID
         JOIN connection cn ON m.Connection_ID = cn.Connection_ID
         JOIN customer   cu ON cn.Customer_ID  = cu.Customer_ID
         WHERE r.r_status != 'Faulty'
         GROUP BY cu.Customer_ID, cu.Name, cu.Address
         ORDER BY total_units DESC
         LIMIT 5"
    )->fetchAll();

    // Areas with frequent abnormal usage
    $abnormal_areas = $pdo->query(
        "SELECT
            cu.Address,
            COUNT(a.Alert_ID) AS total_alerts,
            SUM(CASE WHEN a.a_type='Leakage' THEN 1 ELSE 0 END) AS leakage_alerts,
            SUM(CASE WHEN a.a_type='Abnormal Usage' THEN 1 ELSE 0 END) AS abnormal_alerts
         FROM alert a
         JOIN reading    r  ON a.Reading_ID    = r.Reading_ID
         JOIN meter      m  ON r.Meter_ID      = m.Meter_ID
         JOIN connection cn ON m.Connection_ID = cn.Connection_ID
         JOIN customer   cu ON cn.Customer_ID  = cu.Customer_ID
         WHERE a.a_type IN('Leakage','Abnormal Usage')
         GROUP BY cu.Address
         ORDER BY total_alerts DESC"
    )->fetchAll();

    // Bill status breakdown
    $bill_status = $pdo->query(
        "SELECT payment_status, COUNT(*) AS count,
                ROUND(SUM(fixed_bill+tax+penalty),2) AS total
         FROM bill GROUP BY payment_status"
    )->fetchAll();

    echo json_encode([
        'success'        => true,
        'monthly'        => $monthly,
        'top_consumers'  => $top_consumers,
        'abnormal_areas' => $abnormal_areas,
        'bill_status'    => $bill_status,
    ]);
    exit;
}


// ================================================================
// ACTION: GET OUTSTANDING DUES
// ================================================================
if ($action === 'get_dues') {

    // All unpaid + overdue bills with full details
    $stmt = $pdo->query(
        "SELECT
            b.Bill_ID,
            b.bill_date,
            b.fixed_bill,
            b.tax,
            b.penalty,
            (b.fixed_bill + b.tax + b.penalty) AS total_due,
            b.payment_status,
            cu.Customer_ID,
            cu.Name    AS customer_name,
            cu.Address,
            cu.Income,
            m.Meter_ID
         FROM bill b
         JOIN customer cu ON b.Customer_ID = cu.Customer_ID
         JOIN reading  r  ON b.Reading_ID  = r.Reading_ID
         JOIN meter    m  ON r.Meter_ID    = m.Meter_ID
         WHERE b.payment_status IN ('Unpaid', 'Overdue')
         ORDER BY b.payment_status DESC, b.bill_date ASC"
    );
    $dues = $stmt->fetchAll();

    // Per-customer summary of outstanding dues
    $stmt = $pdo->query(
        "SELECT
            cu.Customer_ID,
            cu.Name,
            cu.Address,
            cu.Income,
            (SELECT n.Number FROM number n WHERE n.Customer_ID = cu.Customer_ID LIMIT 1) AS phone,
            COUNT(b.Bill_ID) AS total_bills,
            SUM(CASE WHEN b.payment_status = 'Unpaid'  THEN 1 ELSE 0 END) AS unpaid_bills,
            SUM(CASE WHEN b.payment_status = 'Overdue' THEN 1 ELSE 0 END) AS overdue_bills,
            ROUND(SUM(b.fixed_bill + b.tax + b.penalty), 2) AS total_outstanding
         FROM bill b
         JOIN customer cu ON b.Customer_ID = cu.Customer_ID
         WHERE b.payment_status IN ('Unpaid', 'Overdue')
         GROUP BY cu.Customer_ID, cu.Name, cu.Address, cu.Income
         ORDER BY total_outstanding DESC"
    );
    $per_customer = $stmt->fetchAll();

    // Summary numbers
    $total_outstanding = array_sum(array_column($dues, 'total_due'));
    $unpaid_count      = count(array_filter($dues, fn($b) => $b['payment_status'] === 'Unpaid'));
    $overdue_count     = count(array_filter($dues, fn($b) => $b['payment_status'] === 'Overdue'));

    echo json_encode([
        'success'      => true,
        'dues'         => $dues,
        'per_customer' => $per_customer,
        'summary'      => [
            'total_outstanding'  => round($total_outstanding, 2),
            'unpaid_count'       => $unpaid_count,
            'overdue_count'      => $overdue_count,
            'customers_with_dues'=> count($per_customer),
        ]
    ]);
    exit;
}


// ================================================================
// ACTION: TOGGLE METER STATUS (Working <-> Inactive)
// Also updates the linked Connection status (Active <-> Inactive)
// ================================================================
if ($action === 'toggle_meter') {
    $meter_id   = intval($data['meter_id']   ?? 0);
    $new_status = trim($data['new_status']   ?? '');

    if (!$meter_id) {
        echo json_encode(['success' => false, 'message' => 'Invalid meter ID.']);
        exit;
    }

    // Only allow Working or Inactive
    if (!in_array($new_status, ['Working', 'Inactive'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid status value.']);
        exit;
    }

    // Check meter exists and get its Connection_ID
    $stmt = $pdo->prepare(
        "SELECT m.m_status, m.Connection_ID
         FROM meter m
         WHERE m.Meter_ID = ?"
    );
    $stmt->execute([$meter_id]);
    $meter = $stmt->fetch();

    if (!$meter) {
        echo json_encode(['success' => false, 'message' => 'Meter not found.']);
        exit;
    }

    // Connection status mirrors meter: Working = Active, Inactive = Inactive
    $conn_status = ($new_status === 'Working') ? 'Active' : 'Inactive';

    $pdo->beginTransaction();
    try {
        // Update Meter status
        $stmt = $pdo->prepare("UPDATE meter SET m_status = ? WHERE Meter_ID = ?");
        $stmt->execute([$new_status, $meter_id]);

        // Update linked Connection status
        $stmt = $pdo->prepare("UPDATE connection SET c_status = ? WHERE Connection_ID = ?");
        $stmt->execute([$conn_status, $meter['Connection_ID']]);

        $pdo->commit();

        $label = $new_status === 'Working' ? 'activated' : 'deactivated';
        echo json_encode([
            'success' => true,
            'message' => "Meter #$meter_id and its connection have been $label successfully."
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Update failed: ' . $e->getMessage()]);
    }
    exit;
}


// ── UNKNOWN ACTION ─────────────────────────────────────────────
echo json_encode(['success' => false, 'message' => 'Unknown action.']);
?>
