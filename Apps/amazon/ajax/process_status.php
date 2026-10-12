<?php
session_start();

require '../includes/json.php';

if (!isset($_SESSION['user_id'])) {
    json_response(['success' => false, 'message' => 'Please login again.']);
}

require '../includes/database.php';
require '../includes/process.php';

ensure_amazon_tables($pdo);

$accountId = (int) ($_POST['account_id'] ?? 0);

if ($accountId <= 0) {
    json_response(['success' => false, 'message' => 'Invalid account ID.']);
}

$job = latest_job($pdo, $accountId);

json_response(['success' => true, 'job' => public_job_status($job)]);
?>
