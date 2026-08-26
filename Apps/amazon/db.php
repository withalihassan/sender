<?php
// db.php

if (isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] === '54.151.244.24') {
    $host = 'localhost';
    $username = 'root';
} else {
    $host = '54.151.244.24';
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
