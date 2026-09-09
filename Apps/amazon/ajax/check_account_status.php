<?php
session_start();

require '../includes/json.php';

if (!isset($_SESSION['user_id'])) {
    json_response(['success' => false, 'status' => 'Suspended', 'message' => 'Please login again.']);
}

require '../includes/database.php';
require '../includes/aws.php';

ensure_amazon_tables($pdo);

$id = (int) ($_POST['id'] ?? 0);

if ($id <= 0) {
    json_response(['success' => false, 'status' => 'Suspended', 'message' => 'Invalid account ID.']);
}

$stmt = $pdo->prepare("SELECT id, aws_account_id, aws_key, aws_secret FROM accounts WHERE id = ? AND by_user = ?");
$stmt->execute([$id, (int) $_SESSION['user_id']]);
$account = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$account) {
    json_response(['success' => false, 'status' => 'Suspended', 'message' => 'Account was not found.']);
}

$result = check_aws_account_status($account);

json_response([
    'success' => true,
    'status' => $result['status'],
    'message' => $result['message'],
]);
?>
