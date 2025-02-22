<?php
// db.php
// $host     = 'localhost';
// $dbname   = 'sns_db';
// $username = 'root';
// $password = '';
$host     = 'srv1803.hstgr.io';
$dbname   = 'u671407910_sendko';
$username = 'u671407910_sendko';
$password = 'Ali@@0990';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
?>
