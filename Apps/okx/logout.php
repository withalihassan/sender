<?php
// logout.php
session_start();
$_SESSION = [];
if (ini_get("session.use_cookies")) {
    setcookie(session_name(), '', time() - 42000, '/');
}
session_destroy();
header('Location: login.php');
exit;
