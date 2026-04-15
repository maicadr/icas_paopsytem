<?php

$host = "localhost";
$user = "root";
$pass = "";
$dbname = "system";

$conn = new mysqli($host,$user,$pass,$dbname);

// ✅ Better error reporting
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if($conn->connect_error){
    die("Connection failed: ".$conn->connect_error);
}

// ✅ Optional but recommended
$conn->set_charset("utf8mb4");

?>