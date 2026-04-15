<?php
include "db.php";

$id = $_POST['id'];

$conn->query("UPDATE activities SET completed = NOT completed WHERE id=$id");

header("Location: admin_dashboard.php");