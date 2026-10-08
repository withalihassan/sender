<?php
// File: my_db.php

// Database connection settings
define('DB_HOST', 'database-1.cj8e4u0u2aoh.ap-south-1.rds.amazonaws.com');
define('DB_USER', 'admin');
define('DB_PASS', 'iRPIhAfKKsAIedz3UiRw');
define('DB_NAME', 'manage_tencent');

// Create connection
$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// Check connection
if ($mysqli->connect_errno) {
    die("Failed to connect to MySQL: (" . $mysqli->connect_errno . ") " . $mysqli->connect_error);
}
?>