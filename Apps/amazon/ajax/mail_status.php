<?php
session_start();

require '../includes/json.php';

if (!isset($_SESSION['user_id'])) {
    json_response(['success' => false, 'message' => 'Please login again.']);
}

require '../includes/database.php';
require '../mail/mail_poll_service.php';

ensure_amazon_tables($pdo);

$accountId = (int) ($_POST['account_id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM mail_execution_runs WHERE account_id = ? ORDER BY id DESC LIMIT 1");
$stmt->execute([$accountId]);
$run = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$run) {
    json_response([
        'success' => true,
        'run' => [
            'status' => 'Idle',
            'emails_processed' => 0,
            'last_email' => '',
            'current_operation' => 'Idle',
            'error_message' => '',
            'is_polling' => false,
        ],
    ]);
}

$isPolling = in_array($run['status'], ['Running', 'Email Received'], true) && (int) $run['stop_requested'] === 0;

if ($isPolling) {
    try {
        mail_poll_once($pdo, (int) $run['id']);
    } catch (Exception $e) {
        mail_update_run($pdo, (int) $run['id'], 'Running', 'Temporary mail polling issue - retrying', $e->getMessage());
    }

    $stmt->execute([$accountId]);
    $run = $stmt->fetch(PDO::FETCH_ASSOC);
    $isPolling = in_array($run['status'], ['Running', 'Email Received'], true) && (int) $run['stop_requested'] === 0;
}

json_response([
    'success' => true,
    'run' => [
        'id' => (int) $run['id'],
        'status' => $run['status'],
        'emails_processed' => (int) $run['emails_processed'],
        'last_email' => $run['last_email_at'] ? date('H:i:s', strtotime($run['last_email_at'])) : '',
        'current_operation' => $run['current_operation'] ?: '',
        'error_message' => $run['error_message'] ?: '',
        'is_polling' => $isPolling,
    ],
]);
?>
