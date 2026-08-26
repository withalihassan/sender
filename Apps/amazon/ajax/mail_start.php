<?php
session_start();

require '../includes/json.php';

if (!isset($_SESSION['user_id'])) {
    json_response(['success' => false, 'message' => 'Please login again.']);
}

require '../includes/database.php';
require '../mail/mail_config.php';
require '../mail/mail_poll_service.php';

ensure_amazon_tables($pdo);

$accountId = (int) ($_POST['account_id'] ?? 0);
$email = trim($_POST['email'] ?? '');

if ($accountId <= 0 || $email === '') {
    json_response(['success' => false, 'message' => 'Enter an email address first.']);
}

if (smtpdev_token() === '') {
    json_response(['success' => false, 'message' => 'SMTPDev token is not configured on the server.']);
}

$stmt = $pdo->prepare("SELECT id FROM accounts WHERE id = ?");
$stmt->execute([$accountId]);

if (!$stmt->fetch()) {
    json_response(['success' => false, 'message' => 'AWS account not found.']);
}

$running = $pdo->prepare("
    SELECT id FROM mail_execution_runs
    WHERE account_id = ?
      AND status IN ('Running', 'Email Received')
      AND stop_requested = 0
    LIMIT 1
");
$running->execute([$accountId]);

if ($running->fetch()) {
    json_response(['success' => false, 'message' => 'Mail Execution is already running.']);
}

$stmt = $pdo->prepare("
    INSERT INTO mail_execution_runs
    (account_id, email_address, status, current_operation, created_at, updated_at)
    VALUES (?, ?, 'Running', 'Starting worker', NOW(), NOW())
");
$stmt->execute([$accountId, $email]);
$runId = (int) $pdo->lastInsertId();

try {
    mail_poll_once($pdo, $runId);
} catch (Exception $e) {
    $pdo->prepare("
        UPDATE mail_execution_runs
        SET status = 'Error', current_operation = 'Polling error', error_message = ?, updated_at = NOW()
        WHERE id = ?
    ")->execute([$e->getMessage(), $runId]);

    json_response(['success' => false, 'message' => 'Email polling error: ' . $e->getMessage()]);
}

json_response(['success' => true, 'message' => 'Email polling is started.']);
?>
