<?php
// File: my_db.php

// Database connection settings
define('DB_HOST', '54.151.244.24');
define('DB_USER', 'admin');
define('DB_PASS', '3CFz8no5NSxCXiDOMz8g');
define('DB_NAME', 'manage_tencent');

// Create connection
$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// Check connection
if ($mysqli->connect_errno) {
    die("Failed to connect to MySQL: (" . $mysqli->connect_errno . ") " . $mysqli->connect_error);
}
?>