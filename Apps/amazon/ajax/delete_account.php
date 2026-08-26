<?php
session_start();

require '../includes/json.php';

if (!isset($_SESSION['user_id'])) {
    json_response(['success' => false, 'message' => 'Please login again.']);
}

require '../includes/database.php';

ensure_amazon_tables($pdo);

$id = (int) ($_POST['id'] ?? 0);

if ($id <= 0) {
    json_response(['success' => false, 'message' => 'Invalid account ID.']);
}

$stmt = $pdo->prepare("DELETE FROM accounts WHERE id = ?");
$stmt->execute([$id]);

json_response(['success' => true, 'message' => 'Account deleted successfully.']);
?>
