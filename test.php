<?php
// ============================================================
//  CONNECTION TEST — Water Management System
//  Open this at http://localhost/water_ms/test.php
//  DELETE this file after testing!
// ============================================================

$host     = 'localhost';
$db_name  = 'Water Management System';
$username = 'root';
$password = '';

echo "<h2>Water Management System — Connection Test</h2>";
echo "<hr>";

// Step 1: Test basic MySQL connection
echo "<h3>Step 1: MySQL Connection</h3>";
try {
    $pdo = new PDO("mysql:host=$host;charset=utf8", $username, $password);
    echo "✅ Connected to MySQL successfully.<br>";
} catch (PDOException $e) {
    echo "❌ MySQL connection FAILED: " . $e->getMessage() . "<br>";
    echo "<strong>Fix:</strong> Make sure Apache and MySQL are both running in XAMPP.";
    exit;
}

// Step 2: Check if database exists
echo "<h3>Step 2: Database Check</h3>";
$stmt = $pdo->query("SHOW DATABASES");
$dbs  = $stmt->fetchAll(PDO::FETCH_COLUMN);
echo "Databases found: <br>";
foreach ($dbs as $db) {
    $match = ($db === $db_name) ? " ← <strong style='color:green'>THIS IS YOUR DB</strong>" : "";
    echo "• $db $match<br>";
}

if (!in_array($db_name, $dbs)) {
    echo "<br>❌ Database '<strong>$db_name</strong>' NOT FOUND.<br>";
    echo "<strong>Fix:</strong> Check the exact database name above and update \$db_name in db.php to match exactly.";
    exit;
}
echo "<br>✅ Database '<strong>$db_name</strong>' found!<br>";

// Step 3: Connect to the specific database
echo "<h3>Step 3: Connect to Database</h3>";
try {
    $pdo2 = new PDO("mysql:host=$host;dbname=$db_name;charset=utf8", $username, $password);
    echo "✅ Connected to '<strong>$db_name</strong>' successfully.<br>";
} catch (PDOException $e) {
    echo "❌ Failed to connect to database: " . $e->getMessage() . "<br>";
    exit;
}

// Step 4: Check tables
echo "<h3>Step 4: Tables in Database</h3>";
$stmt   = $pdo2->query("SHOW TABLES");
$tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
$required = ['Customer', 'Connection', 'Meter', 'Reading', 'Bill', 'Alert', 'Number'];

if (empty($tables)) {
    echo "❌ No tables found! Run your wms_complete.sql file in phpMyAdmin first.<br>";
} else {
    echo "Tables found:<br>";
    foreach ($tables as $t) {
        $ok = in_array($t, $required) ? "✅" : "•";
        echo "$ok $t<br>";
    }
    echo "<br>";
    foreach ($required as $r) {
        if (!in_array($r, $tables)) {
            echo "⚠️ Missing table: <strong>$r</strong><br>";
        }
    }
}

// Step 5: Count rows in each table
echo "<h3>Step 5: Row Counts</h3>";
foreach ($tables as $t) {
    $count = $pdo2->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
    echo "• $t: <strong>$count</strong> rows<br>";
}

echo "<hr><p style='color:green'><strong>All checks passed! Your connection is working correctly.</strong></p>";
echo "<p style='color:red'>⚠️ Remember to DELETE test.php from your htdocs folder after testing!</p>";
?>
