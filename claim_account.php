<?php
include 'db.php';
include './session.php';

if (!isset($_POST['id']) || !isset($_POST['claim_type'])) {
    die('Invalid request.');
}

$id = $_POST['id'];
$claim_type = strtolower(trim($_POST['claim_type']));

// Validate claim type
if ($claim_type !== 'full' && $claim_type !== 'half') {
    die('Invalid claim type.');
}

// Check if the account has passed quality check
$stmt = $pdo->prepare("SELECT ac_state FROM accounts WHERE id = ?");
$stmt->execute([$id]);
$account = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$account || $account['ac_state'] !== 'orphan') {
    die('Account cannot be claimed.');
}

// Update the account: set state to claimed, record the claimer, and store the claim type (worth_type)
$stmt = $pdo->prepare("UPDATE accounts SET ac_state = 'claimed', claimed_by = ?, worth_type = ? WHERE id = ?");
if ($stmt->execute([$session_id, $claim_type, $id])) {
    echo "Account claimed successfully as " . htmlspecialchars($claim_type) . ".";
} else {
    echo "Failed to claim account.";
}
?>
