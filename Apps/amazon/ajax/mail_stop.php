<?php
session_start();

require '../includes/json.php';

if (!isset($_SESSION['user_id'])) {
    json_response(['success' => false, 'message' => 'Please login again.']);
}

require '../includes/database.php';

ensure_amazon_tables($pdo);

$accountId = (int) ($_POST['account_id'] ?? 0);

if ($accountId <= 0) {
    json_response(['success' => false, 'message' => 'Invalid account ID.']);
}

$stmt = $pdo->prepare("
    UPDATE mail_execution_runs
    SET stop_requested = 1, status = 'Stopped', current_operation = 'Stopped', updated_at = NOW()
    WHERE account_id = ? AND status IN ('Running', 'Email Received', 'Error')
");
$stmt->execute([$accountId]);

json_response(['success' => true, 'message' => 'Mail Execution stopped.']);
?>
