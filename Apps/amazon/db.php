<?php
// db.php

if (isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] === '98.83.29.45') {
    $host = 'localhost';
    $username = 'root';
} else {
    $host = 'database-1.ct22ws4u0c7g.me-central-1.rds.amazonaws.com';
    $username = 'admin';
}

$dbname   = 'manage_amazon';
$password = 'sLoGMCVfEo4TpMGOEm18';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
?>
