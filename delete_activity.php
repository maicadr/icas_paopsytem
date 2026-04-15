<?php
include "db.php";

$id = $_POST['id'];

$conn->query("DELETE FROM activities WHERE id=$id");

header("Location: admin_dashboard.php");