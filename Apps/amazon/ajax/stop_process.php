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
    UPDATE number_update_jobs
    SET stop_requested = 1, status = 'Stopped', message = 'Process stopped.', updated_at = NOW()
    WHERE account_id = ? AND status = 'Running'
");
$stmt->execute([$accountId]);

json_response(['success' => true, 'message' => 'Stop request saved.']);
?>
