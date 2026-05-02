-- ================================================================
--  WATER BILLING & USAGE TRACKER — CSE370 DBMS Project
--  Tables: Customer, Connection, Meter, Reading, Bill, Alert
-- ================================================================


-- ================================================================
-- SECTION 1: CREATE TABLES
-- ================================================================

CREATE TABLE Customer (
    Customer_ID   INT          PRIMARY KEY AUTO_INCREMENT,
    Name          VARCHAR(100) NOT NULL,
    Date          DATE         NOT NULL DEFAULT (CURRENT_DATE),  -- registration date
    Address       VARCHAR(255),
    Income        DECIMAL(12,2) DEFAULT 0.00,                    -- used for subsidy check
    Status        VARCHAR(20)  NOT NULL DEFAULT 'Active'         -- Active / Inactive
);

CREATE TABLE Connection (
    Connection_ID INT         PRIMARY KEY AUTO_INCREMENT,
    Customer_ID   INT         NOT NULL,
    c_type        VARCHAR(30) NOT NULL DEFAULT 'Residential',    -- Residential / Commercial
    c_status      VARCHAR(20) NOT NULL DEFAULT 'Active',         -- Active / Inactive
    c_date        DATE        NOT NULL DEFAULT (CURRENT_DATE),
    CONSTRAINT fk_conn_customer FOREIGN KEY (Customer_ID)
        REFERENCES Customer(Customer_ID) ON DELETE CASCADE
);

CREATE TABLE Meter (
    Meter_ID      INT         PRIMARY KEY AUTO_INCREMENT,
    Connection_ID INT         NOT NULL,
    m_type        VARCHAR(30) NOT NULL DEFAULT 'Digital',        -- Digital / Analog
    m_status      VARCHAR(20) NOT NULL DEFAULT 'Working',        -- Working / Faulty / Missing
    CONSTRAINT fk_meter_conn FOREIGN KEY (Connection_ID)
        REFERENCES Connection(Connection_ID) ON DELETE CASCADE
);

CREATE TABLE Reading (
    Reading_ID    INT          PRIMARY KEY AUTO_INCREMENT,
    Meter_ID      INT          NOT NULL,
    r_date        DATE         NOT NULL DEFAULT (CURRENT_DATE),
    r_prev        DECIMAL(10,2) NOT NULL DEFAULT 0.00,           -- previous meter reading
    r_current     DECIMAL(10,2) NOT NULL DEFAULT 0.00,           -- current meter reading
    r_status      VARCHAR(20)  NOT NULL DEFAULT 'Normal',        -- Normal / Abnormal / Faulty
    CONSTRAINT fk_reading_meter FOREIGN KEY (Meter_ID)
        REFERENCES Meter(Meter_ID) ON DELETE CASCADE
);

CREATE TABLE Bill (
    Bill_ID        INT          PRIMARY KEY AUTO_INCREMENT,
    Customer_ID    INT          NOT NULL,
    Reading_ID     INT          NOT NULL,
    bill_date      DATE         NOT NULL DEFAULT (CURRENT_DATE),
    fixed_bill     DECIMAL(10,2) NOT NULL DEFAULT 0.00,          -- base charge
    tax            DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    penalty        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    payment_date   DATE,
    payment_status VARCHAR(20)  NOT NULL DEFAULT 'Unpaid',       -- Paid / Unpaid / Overdue
    CONSTRAINT fk_bill_customer FOREIGN KEY (Customer_ID)
        REFERENCES Customer(Customer_ID) ON DELETE CASCADE,
    CONSTRAINT fk_bill_reading FOREIGN KEY (Reading_ID)
        REFERENCES Reading(Reading_ID) ON DELETE CASCADE
);

CREATE TABLE Number (
    Customer_ID  INT         NOT NULL,
    Number       VARCHAR(20) NOT NULL,
    PRIMARY KEY (Customer_ID, Number),
    CONSTRAINT fk_number_customer FOREIGN KEY (Customer_ID)
        REFERENCES Customer(Customer_ID) ON DELETE CASCADE
);

CREATE TABLE Alert (
    Alert_ID   INT         PRIMARY KEY AUTO_INCREMENT,
    Reading_ID INT         NOT NULL,
    a_type     VARCHAR(50) NOT NULL,       -- Leakage / Abnormal Usage / Faulty Meter / Missing Reading
    a_status   VARCHAR(20) NOT NULL DEFAULT 'Open',  -- Open / Resolved
    a_date     DATE        NOT NULL DEFAULT (CURRENT_DATE),
    CONSTRAINT fk_alert_reading FOREIGN KEY (Reading_ID)
        REFERENCES Reading(Reading_ID) ON DELETE CASCADE
);


-- ================================================================
-- SECTION 2: INSERT SAMPLE DATA
-- ================================================================

-- Customers (mix of income levels to test subsidy logic)
INSERT INTO Customer (Name, Date, Address, Income, Status) VALUES
('Rahim Uddin',   '2021-03-10', '12 Mirpur Road, Dhaka',      25000.00, 'Active'),
('Fatema Begum',  '2021-06-15', '5 Gulshan Ave, Dhaka',       80000.00, 'Active'),
('Karim Hossain', '2022-01-20', '88 Dhanmondi, Dhaka',        15000.00, 'Active'),
('Nasrin Akter',  '2022-08-05', '22 Uttara Sector 7, Dhaka',  60000.00, 'Inactive'),
('Jamal Ahmed',   '2023-02-28', '9 Motijheel, Dhaka',         95000.00, 'Active');

-- Connections
INSERT INTO Connection (Customer_ID, c_type, c_status, c_date) VALUES
(1, 'Residential', 'Active',   '2021-03-12'),
(2, 'Commercial',  'Active',   '2021-06-20'),
(3, 'Residential', 'Active',   '2022-01-25'),
(4, 'Residential', 'Inactive', '2022-08-10'),
(5, 'Commercial',  'Active',   '2023-03-01');

-- Meters
INSERT INTO Meter (Connection_ID, m_type, m_status) VALUES
(1, 'Digital', 'Working'),
(2, 'Digital', 'Working'),
(3, 'Analog',  'Faulty'),
(4, 'Digital', 'Working'),
(5, 'Digital', 'Working');

-- Readings
INSERT INTO Reading (Meter_ID, r_date, r_prev, r_current, r_status) VALUES
(1, '2024-01-31', 1000.00, 1120.00, 'Normal'),
(1, '2024-02-29', 1120.00, 1260.00, 'Normal'),
(2, '2024-01-31', 5000.00, 5400.00, 'Normal'),
(2, '2024-02-29', 5400.00, 5900.00, 'Normal'),
(3, '2024-01-31', 800.00,  800.00,  'Faulty'),   -- no change = faulty meter
(4, '2024-01-31', 300.00,  680.00,  'Abnormal'), -- spike = possible leak
(5, '2024-01-31', 9000.00, 9350.00, 'Normal'),
(5, '2024-02-29', 9350.00, 9720.00, 'Normal');

-- Bills (fixed_bill = consumption × rate; tax=5%; penalty for overdue)
INSERT INTO Bill (Customer_ID, Reading_ID, bill_date, fixed_bill, tax, penalty, payment_date, payment_status) VALUES
(1, 1, '2024-02-01', 600.00,  30.00,  0.00,  '2024-02-20', 'Paid'),
(1, 2, '2024-03-01', 700.00,  35.00,  0.00,  NULL,         'Unpaid'),
(2, 3, '2024-02-01', 2000.00, 100.00, 0.00,  '2024-02-15', 'Paid'),
(2, 4, '2024-03-01', 2500.00, 125.00, 0.00,  NULL,         'Unpaid'),
(3, 5, '2024-02-01', 0.00,    0.00,   0.00,  NULL,         'Unpaid'),  -- faulty, no charge yet
(4, 6, '2024-02-01', 1900.00, 95.00,  50.00, NULL,         'Overdue'),
(5, 7, '2024-02-01', 1750.00, 87.50,  0.00,  '2024-02-10', 'Paid'),
(5, 8, '2024-03-01', 1850.00, 92.50,  0.00,  NULL,         'Unpaid');

-- Phone Numbers
INSERT INTO Number (Customer_ID, Number) VALUES
(1, '01711111111'),
(2, '01722222222'),
(3, '01733333333'),
(4, '01744444444'),
(5, '01755555555');

-- Alerts (triggered from abnormal/faulty readings)
INSERT INTO Alert (Reading_ID, a_type, a_status, a_date) VALUES
(5, 'Faulty Meter',    'Open',     '2024-02-01'),
(6, 'Abnormal Usage',  'Open',     '2024-02-01'),
(6, 'Leakage',         'Open',     '2024-02-02');


-- ================================================================
-- SECTION 3: SQL QUERIES
-- ================================================================

-- ----- CUSTOMER MANAGEMENT -----

-- Q1: All active customers
SELECT Customer_ID, Name, Address, Income, Status
FROM Customer
WHERE Status = 'Active';

-- Q2: Low-income customers eligible for reduced bill (income < 30,000)
SELECT Customer_ID, Name, Address, Income
FROM Customer
WHERE Income < 30000 AND Status = 'Active'
ORDER BY Income;


-- ----- METER TRACKING -----

-- Q3: All faulty or problematic meters
SELECT m.Meter_ID, m.m_type, m.m_status,
       c.Connection_ID, cu.Name AS Customer_Name
FROM Meter m
JOIN Connection c  ON m.Connection_ID = c.Connection_ID
JOIN Customer  cu  ON c.Customer_ID   = cu.Customer_ID
WHERE m.m_status != 'Working';

-- Q4: Meters with missing or zero-change readings (possible fault)
SELECT r.Reading_ID, r.Meter_ID, r.r_date, r.r_prev, r.r_current
FROM Reading r
WHERE (r.r_current - r.r_prev) = 0;


-- ----- BILLING SYSTEM -----

-- Q5: Generate bill summary (total payable = fixed_bill + tax + penalty)
SELECT b.Bill_ID, cu.Name, b.bill_date,
       b.fixed_bill, b.tax, b.penalty,
       (b.fixed_bill + b.tax + b.penalty) AS total_payable,
       b.payment_status
FROM Bill b
JOIN Customer cu ON b.Customer_ID = cu.Customer_ID
ORDER BY b.bill_date DESC;

-- Q6: Apply reduced bill for low-income customers (10% discount)
SELECT b.Bill_ID, cu.Name, cu.Income,
       (b.fixed_bill + b.tax + b.penalty)                               AS original_total,
       ROUND((b.fixed_bill + b.tax + b.penalty) * 0.90, 2)             AS discounted_total
FROM Bill b
JOIN Customer cu ON b.Customer_ID = cu.Customer_ID
WHERE cu.Income < 30000 AND b.payment_status = 'Unpaid';

-- Q7: All unpaid and overdue bills with due customer info
SELECT b.Bill_ID, cu.Name, cu.Customer_ID,
       b.bill_date, b.payment_status,
       (b.fixed_bill + b.tax + b.penalty) AS amount_due
FROM Bill b
JOIN Customer cu ON b.Customer_ID = cu.Customer_ID
WHERE b.payment_status IN ('Unpaid', 'Overdue')
ORDER BY b.payment_status DESC, b.bill_date;


-- ----- PAYMENT RECORDS -----

-- Q8: All paid bills with payment date and method
SELECT b.Bill_ID, cu.Name, b.bill_date, b.payment_date,
       (b.fixed_bill + b.tax + b.penalty) AS amount_paid
FROM Bill b
JOIN Customer cu ON b.Customer_ID = cu.Customer_ID
WHERE b.payment_status = 'Paid'
ORDER BY b.payment_date DESC;

-- Q9: Outstanding dues per customer
SELECT cu.Customer_ID, cu.Name,
       COUNT(b.Bill_ID)                          AS unpaid_bills,
       SUM(b.fixed_bill + b.tax + b.penalty)     AS total_outstanding
FROM Bill b
JOIN Customer cu ON b.Customer_ID = cu.Customer_ID
WHERE b.payment_status IN ('Unpaid', 'Overdue')
GROUP BY cu.Customer_ID, cu.Name
ORDER BY total_outstanding DESC;


-- ----- LEAKAGE / ABNORMAL USAGE DETECTION -----

-- Q10: Readings flagged as Abnormal or Faulty
SELECT r.Reading_ID, r.Meter_ID, r.r_date,
       r.r_prev, r.r_current,
       (r.r_current - r.r_prev) AS consumption,
       r.r_status
FROM Reading r
WHERE r.r_status IN ('Abnormal', 'Faulty')
ORDER BY r.r_date DESC;

-- Q11: Detect unusually high consumption (> 2× customer's average)
SELECT r.Reading_ID, r.Meter_ID, r.r_date,
       (r.r_current - r.r_prev)                                    AS consumption,
       avg_data.avg_consumption,
       ROUND((r.r_current - r.r_prev) / avg_data.avg_consumption, 2) AS ratio
FROM Reading r
JOIN (
    SELECT Meter_ID,
           AVG(r_current - r_prev) AS avg_consumption
    FROM Reading
    GROUP BY Meter_ID
) avg_data ON r.Meter_ID = avg_data.Meter_ID
WHERE (r.r_current - r.r_prev) > 2 * avg_data.avg_consumption;

-- Q12: All open alerts with customer info
SELECT a.Alert_ID, a.a_type, a.a_status, a.a_date,
       r.Meter_ID, cu.Name AS Customer_Name
FROM Alert a
JOIN Reading    r  ON a.Reading_ID   = r.Reading_ID
JOIN Meter      m  ON r.Meter_ID     = m.Meter_ID
JOIN Connection cn ON m.Connection_ID = cn.Connection_ID
JOIN Customer   cu ON cn.Customer_ID  = cu.Customer_ID
WHERE a.a_status = 'Open'
ORDER BY a.a_date DESC;

-- Q13: Mark an alert as resolved (example: Alert_ID = 1)
UPDATE Alert
SET a_status = 'Resolved'
WHERE Alert_ID = 1;


-- ----- REPORTS & ANALYTICS -----

-- Q14: Monthly bill report (total billed per month)
SELECT DATE_FORMAT(bill_date, '%Y-%m')         AS month,
       COUNT(Bill_ID)                          AS total_bills,
       SUM(fixed_bill + tax + penalty)         AS total_billed,
       SUM(CASE WHEN payment_status = 'Paid'
                THEN fixed_bill + tax + penalty ELSE 0 END) AS total_collected
FROM Bill
GROUP BY month
ORDER BY month;

-- Q15: Top 5 consumers by total water usage
SELECT cu.Customer_ID, cu.Name,
       SUM(r.r_current - r.r_prev) AS total_consumption
FROM Reading r
JOIN Meter      m  ON r.Meter_ID      = m.Meter_ID
JOIN Connection cn ON m.Connection_ID = cn.Connection_ID
JOIN Customer   cu ON cn.Customer_ID  = cu.Customer_ID
GROUP BY cu.Customer_ID, cu.Name
ORDER BY total_consumption DESC
LIMIT 5;

-- Q16: Areas (addresses) with frequent abnormal usage
SELECT cu.Address,
       COUNT(a.Alert_ID) AS total_alerts
FROM Alert a
JOIN Reading    r  ON a.Reading_ID    = r.Reading_ID
JOIN Meter      m  ON r.Meter_ID      = m.Meter_ID
JOIN Connection cn ON m.Connection_ID = cn.Connection_ID
JOIN Customer   cu ON cn.Customer_ID  = cu.Customer_ID
WHERE a.a_type IN ('Abnormal Usage', 'Leakage')
GROUP BY cu.Address
ORDER BY total_alerts DESC;
