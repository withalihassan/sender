<?php
include 'db.php';
include './session.php';

if (!isset($_POST['id'])) {
    die('Invalid request.');
}

$id = $_POST['id'];

// Check if the account has passed quality check
$stmt = $pdo->prepare("SELECT ac_state FROM accounts WHERE id = ?");
$stmt->execute([$id]);
$account = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$account || $account['ac_state'] !== 'orphan') {
    die('Account cannot be claimed.');
}

// Update the claimed_by field
$stmt = $pdo->prepare("UPDATE accounts SET ac_state='claimed' , claimed_by = ? WHERE id = ?");
if ($stmt->execute([$session_id, $id])) {
    echo "Account claimed successfully.";
} else {
    echo "Failed to claim account.";
}
?>
