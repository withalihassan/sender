<?php
// db.php

// Determine the database host based on the URL used to access the site.
if (isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] === '98.83.29.45') {
    // When accessed via http://98.83.29.45, use 'localhost' for the DB connection.
    $host = 'localhost';
    $username = 'root';
} else {
    // Otherwise, use the remote IP address.
    $host = '98.83.29.45';
    $username = 'remoteuser';
}

$dbname   = 'sp_sender';
$password = 'Tech@#009';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
?>
