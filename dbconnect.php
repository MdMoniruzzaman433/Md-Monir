<?php
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "Water Management System";

$conn = new mysqli($servername,$username,$password);

if($conn -> connect_error)
{
	die("Connection failed: " . $conn -> connect_error);
}
else
{
	echo "Connection Successful";
}
?>