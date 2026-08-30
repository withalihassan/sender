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

$pdo->beginTransaction();

$stop = $pdo->prepare("
    UPDATE mail_execution_runs
    SET stop_requested = 1, status = 'Stopped', current_operation = 'Flushed', updated_at = NOW()
    WHERE account_id = ?
");
$stop->execute([$accountId]);

$events = $pdo->prepare("DELETE FROM mail_execution_events WHERE account_id = ?");
$events->execute([$accountId]);

$runs = $pdo->prepare("DELETE FROM mail_execution_runs WHERE account_id = ?");
$runs->execute([$accountId]);

$pdo->commit();

json_response(['success' => true, 'message' => 'Mail Execution data flushed.']);
?>
