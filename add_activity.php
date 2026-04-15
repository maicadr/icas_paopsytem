<?php
include "db.php";

$name = $_POST['activity_name'];
$enroll = $_POST['enrollment_id'];

$stmt = $conn->prepare("INSERT INTO activities(activity_name,enrollment_id,completed) VALUES(?,?,0)");
$stmt->bind_param("si",$name,$enroll);
$stmt->execute();

header("Location: admin_dashboard.php");