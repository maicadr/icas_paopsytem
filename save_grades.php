<?php
include "db.php";

$id = $_POST['enrollment_id'];
$p = $_POST['prelim'];
$m = $_POST['midterm'];
$f = $_POST['final'];
$avg = $_POST['average'];

$status = ($avg >= 75) ? "Passed" : "Failed";

$stmt = $conn->prepare("
UPDATE grades 
SET prelim=?, midterm=?, final=?, average=?, status=? 
WHERE enrollment_id=?
");

$stmt->bind_param("ddddsi",$p,$m,$f,$avg,$status,$id);
$stmt->execute();

header("Location: admin_dashboard.php");
?>