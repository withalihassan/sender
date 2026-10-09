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
$email = trim($_POST['email'] ?? '');
$numbersText = trim($_POST['numbers'] ?? '');
$delaySeconds = max(1, (int) ($_POST['delay_seconds'] ?? 300));

if ($accountId <= 0 || $email === '' || $numbersText === '') {
    json_response(['success' => false, 'message' => 'Email and phone numbers are required.']);
}

$numbers = array_values(array_filter(array_map('trim', preg_split('/\R/', $numbersText))));

if (!$numbers) {
    json_response(['success' => false, 'message' => 'Enter at least one phone number.']);
}

$stmt = $pdo->prepare("SELECT * FROM accounts WHERE id = ?");
$stmt->execute([$accountId]);
$account = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$account) {
    json_response(['success' => false, 'message' => 'AWS account not found.']);
}

$running = $pdo->prepare("SELECT id FROM number_update_jobs WHERE account_id = ? AND status = 'Running' LIMIT 1");
$running->execute([$accountId]);

if ($running->fetch()) {
    json_response(['success' => false, 'message' => 'A process is already running for this account.']);
}

try {
    $orgAccount = find_org_account_by_email($account, $email);

    if (!$orgAccount) {
        json_response(['success' => false, 'message' => 'No AWS Organization member account found for this email.']);
    }

    $stmt = $pdo->prepare("
        INSERT INTO number_update_jobs
        (account_id, email, target_aws_account_id, numbers, total_numbers, delay_seconds, status, message, next_run_at, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, 'Running', 'Process started.', NULL, NOW(), NOW())
    ");
    $stmt->execute([
        $accountId,
        $email,
        $orgAccount['Id'],
        json_encode($numbers),
        count($numbers),
        $delaySeconds,
    ]);

    $job = latest_job($pdo, $accountId);
    $job = advance_job_if_ready($pdo, $job, $account);
    $status = public_job_status($job);

    json_response([
        'success' => $status['status'] === 'Running',
        'message' => $status['message'] ?: 'Process started.',
        'job' => $status,
    ]);
} catch (Exception $e) {
    json_response(['success' => false, 'message' => 'AWS error: ' . aws_error_message($e)]);
}
?>
