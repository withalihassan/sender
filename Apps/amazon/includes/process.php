<?php
require_once __DIR__ . '/aws.php';

function latest_job($pdo, $accountId)
{
    $stmt = $pdo->prepare("SELECT * FROM number_update_jobs WHERE account_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$accountId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function public_job_status($job)
{
    if (!$job) {
        return [
            'status' => 'Idle',
            'current_phone' => '',
            'progress' => '0 / 0',
            'message' => '',
        ];
    }

    return [
        'status' => $job['status'],
        'current_phone' => $job['current_phone'] ?: '',
        'progress' => (int) $job['current_index'] . ' / ' . (int) $job['total_numbers'],
        'message' => $job['message'] ?: '',
    ];
}

function advance_job_if_ready($pdo, $job, $account)
{
    if (!$job || $job['status'] !== 'Running') {
        return $job;
    }

    if ((int) $job['stop_requested'] === 1) {
        $stmt = $pdo->prepare("UPDATE number_update_jobs SET status = 'Stopped', updated_at = NOW() WHERE id = ?");
        $stmt->execute([$job['id']]);
        return latest_job($pdo, $job['account_id']);
    }

    if ($job['next_run_at'] && strtotime($job['next_run_at']) > time()) {
        return $job;
    }

    $numbers = json_decode($job['numbers'], true) ?: [];
    $index = (int) $job['current_index'];

    if (!isset($numbers[$index])) {
        $stmt = $pdo->prepare("UPDATE number_update_jobs SET status = 'Completed', updated_at = NOW() WHERE id = ?");
        $stmt->execute([$job['id']]);
        return latest_job($pdo, $job['account_id']);
    }

    $phone = $numbers[$index];

    try {
        update_account_phone($account, $job['target_aws_account_id'], $phone);

        $nextIndex = $index + 1;
        $done = $nextIndex >= count($numbers);
        $status = $done ? 'Completed' : 'Running';
        $nextRunAt = $done ? null : date('Y-m-d H:i:s', time() + 300);

        $stmt = $pdo->prepare("
            UPDATE number_update_jobs
            SET current_index = ?, current_phone = ?, status = ?, message = ?, next_run_at = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$nextIndex, $phone, $status, 'Phone updated successfully.', $nextRunAt, $job['id']]);
    } catch (Exception $e) {
        $stmt = $pdo->prepare("
            UPDATE number_update_jobs
            SET status = 'Error', current_phone = ?, message = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$phone, aws_error_message($e), $job['id']]);
    }

    return latest_job($pdo, $job['account_id']);
}
?>
