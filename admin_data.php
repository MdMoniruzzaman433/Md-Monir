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
    $stats['total_customers'] = $pdo->query("SELECT COUNT(*) FROM Customer")->fetchColumn();

    // Active connections
    $stats['active_connections'] = $pdo->query("SELECT COUNT(*) FROM Connection WHERE c_status='Active'")->fetchColumn();

    // Faulty meters
    $stats['faulty_meters'] = $pdo->query("SELECT COUNT(*) FROM Meter WHERE m_status='Faulty'")->fetchColumn();

    // Open alerts
    $stats['open_alerts'] = $pdo->query("SELECT COUNT(*) FROM Alert WHERE a_status='Open'")->fetchColumn();

    // Total billed this month
    $stats['billed_this_month'] = $pdo->query(
        "SELECT COALESCE(SUM(fixed_bill+tax+penalty),0) FROM Bill
         WHERE MONTH(bill_date)=MONTH(CURDATE()) AND YEAR(bill_date)=YEAR(CURDATE())"
    )->fetchColumn();

    // Total collected this month
    $stats['collected_this_month'] = $pdo->query(
        "SELECT COALESCE(SUM(fixed_bill+tax+penalty),0) FROM Bill
         WHERE payment_status='Paid'
         AND MONTH(payment_date)=MONTH(CURDATE()) AND YEAR(payment_date)=YEAR(CURDATE())"
    )->fetchColumn();

    // Total outstanding (unpaid + overdue)
    $stats['total_outstanding'] = $pdo->query(
        "SELECT COALESCE(SUM(fixed_bill+tax+penalty),0) FROM Bill WHERE payment_status IN('Unpaid','Overdue')"
    )->fetchColumn();

    // Unpaid bills count
    $stats['unpaid_count'] = $pdo->query("SELECT COUNT(*) FROM Bill WHERE payment_status='Unpaid'")->fetchColumn();

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
            (SELECT COUNT(*) FROM Bill b WHERE b.Customer_ID=cu.Customer_ID AND b.payment_status IN('Unpaid','Overdue')) AS unpaid_bills,
            (SELECT COALESCE(SUM(b.fixed_bill+b.tax+b.penalty),0) FROM Bill b WHERE b.Customer_ID=cu.Customer_ID AND b.payment_status IN('Unpaid','Overdue')) AS outstanding
         FROM Customer cu
         LEFT JOIN Connection c ON c.Customer_ID=cu.Customer_ID
         LEFT JOIN Meter m ON m.Connection_ID=c.Connection_ID
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
         FROM Alert a
         JOIN Reading    r  ON a.Reading_ID   = r.Reading_ID
         JOIN Meter      m  ON r.Meter_ID     = m.Meter_ID
         JOIN Connection cn ON m.Connection_ID = cn.Connection_ID
         JOIN Customer   cu ON cn.Customer_ID  = cu.Customer_ID
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

    $stmt = $pdo->prepare("UPDATE Alert SET a_status='Resolved' WHERE Alert_ID=?");
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
         FROM Bill
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
         FROM Reading r
         JOIN Meter      m  ON r.Meter_ID      = m.Meter_ID
         JOIN Connection cn ON m.Connection_ID = cn.Connection_ID
         JOIN Customer   cu ON cn.Customer_ID  = cu.Customer_ID
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
         FROM Alert a
         JOIN Reading    r  ON a.Reading_ID    = r.Reading_ID
         JOIN Meter      m  ON r.Meter_ID      = m.Meter_ID
         JOIN Connection cn ON m.Connection_ID = cn.Connection_ID
         JOIN Customer   cu ON cn.Customer_ID  = cu.Customer_ID
         WHERE a.a_type IN('Leakage','Abnormal Usage')
         GROUP BY cu.Address
         ORDER BY total_alerts DESC"
    )->fetchAll();

    // Bill status breakdown
    $bill_status = $pdo->query(
        "SELECT payment_status, COUNT(*) AS count,
                ROUND(SUM(fixed_bill+tax+penalty),2) AS total
         FROM Bill GROUP BY payment_status"
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


// ── UNKNOWN ACTION ─────────────────────────────────────────────
echo json_encode(['success' => false, 'message' => 'Unknown action.']);
?>
