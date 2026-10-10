<?php
require_once __DIR__ . '/aws.php';

function latest_job($pdo, $accountId)
{
    $stmt = $pdo->prepare("SELECT * FROM number_update_jobs WHERE account_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$accountId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function job_by_id($pdo, $jobId)
{
    $stmt = $pdo->prepare("SELECT * FROM number_update_jobs WHERE id = ?");
    $stmt->execute([$jobId]);
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

    $lockName = 'number_update_job_' . (int) $job['id'];
    $stmt = $pdo->prepare("SELECT GET_LOCK(?, 0) AS acquired");
    $stmt->execute([$lockName]);

    if ((int) $stmt->fetchColumn() !== 1) {
        return latest_job($pdo, $job['account_id']);
    }

    try {
        $job = job_by_id($pdo, $job['id']);

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
        $totalNumbers = count($numbers);
        $index = (int) $job['current_index'];
        $delaySeconds = max(1, (int) ($job['delay_seconds'] ?? 300));

        if ($totalNumbers === 0) {
            $stmt = $pdo->prepare("UPDATE number_update_jobs SET status = 'Error', message = 'No phone numbers found.', updated_at = NOW() WHERE id = ?");
            $stmt->execute([$job['id']]);
            return latest_job($pdo, $job['account_id']);
        }

        if (!isset($numbers[$index])) {
            $index = 0;
        }

        $phone = $numbers[$index];
        $updateResult = try_update_account_phone($account, $job['target_aws_account_id'], $phone);
        $nextIndex = ($index + 1) % $totalNumbers;
        $nextRunAt = date('Y-m-d H:i:s', time() + $delaySeconds);
        $message = $updateResult['success']
            ? 'Phone update request sent.'
            : 'AWS response ignored. Moving to next number. Last AWS message: ' . $updateResult['message'];

        $stmt = $pdo->prepare("
            UPDATE number_update_jobs
            SET current_index = ?, current_phone = ?, status = ?, message = ?, next_run_at = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$nextIndex, $phone, 'Running', $message, $nextRunAt, $job['id']]);

        return latest_job($pdo, $job['account_id']);
    } finally {
        $stmt = $pdo->prepare("SELECT RELEASE_LOCK(?)");
        $stmt->execute([$lockName]);
    }
}
?>
