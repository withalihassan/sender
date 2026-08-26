<?php
if (PHP_SAPI !== 'cli') {
    exit;
}

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/smtpdev_client.php';
require_once __DIR__ . '/browser_automation.php';
require_once __DIR__ . '/mail_processor.php';

ensure_amazon_tables($pdo);

$runId = (int) ($argv[1] ?? 0);

if ($runId <= 0) {
    exit;
}

function get_run($pdo, $runId)
{
    $stmt = $pdo->prepare("SELECT * FROM mail_execution_runs WHERE id = ?");
    $stmt->execute([$runId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function update_run($pdo, $runId, $status, $operation, $error = null)
{
    $stmt = $pdo->prepare("
        UPDATE mail_execution_runs
        SET status = ?, current_operation = ?, error_message = ?, updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$status, $operation, $error, $runId]);
}

function update_event($pdo, $eventId, $fields)
{
    $sets = [];
    $values = [];

    foreach ($fields as $field => $value) {
        $sets[] = $field . ' = ?';
        $values[] = $value;
    }

    $sets[] = 'updated_at = NOW()';
    $values[] = $eventId;

    $stmt = $pdo->prepare("UPDATE mail_execution_events SET " . implode(', ', $sets) . " WHERE id = ?");
    $stmt->execute($values);
}

function event_exists($pdo, $messageId)
{
    $stmt = $pdo->prepare("SELECT id FROM mail_execution_events WHERE message_id = ?");
    $stmt->execute([$messageId]);
    return $stmt->fetchColumn();
}

while (true) {
    $run = get_run($pdo, $runId);

    if (!$run || (int) $run['stop_requested'] === 1 || $run['status'] === 'Stopped') {
        update_run($pdo, $runId, 'Stopped', 'Stopped');
        exit;
    }

    try {
        update_run($pdo, $runId, 'Running', 'Waiting for new email');
        [$smtpAccountId, $mailboxId, $messages] = smtpdev_messages($run['email_address']);

        foreach ($messages as $message) {
            $run = get_run($pdo, $runId);

            if (!$run || (int) $run['stop_requested'] === 1) {
                update_run($pdo, $runId, 'Stopped', 'Stopped');
                exit;
            }

            $messageId = $message['id'] ?? $message['_id'] ?? md5(json_encode($message));

            if (event_exists($pdo, $messageId)) {
                continue;
            }

            $stmt = $pdo->prepare("
                INSERT INTO mail_execution_events
                (run_id, account_id, email_address, message_id, email_received, status, created_at, updated_at)
                VALUES (?, ?, ?, ?, 1, 'Email Received', NOW(), NOW())
            ");
            $stmt->execute([$runId, $run['account_id'], $run['email_address'], $messageId]);
            $eventId = $pdo->lastInsertId();

            $pdo->prepare("
                UPDATE mail_execution_runs
                SET status = 'Email Received', emails_processed = emails_processed + 1,
                    last_email_at = NOW(), current_operation = 'Email received', updated_at = NOW()
                WHERE id = ?
            ")->execute([$runId]);

            $detail = smtpdev_message_detail($smtpAccountId, $mailboxId, $messageId);
            $text = mail_text_from_message(array_merge($message, $detail));
            $url = extract_verification_url($text);

            if (!$url) {
                update_event($pdo, $eventId, [
                    'status' => 'Error',
                    'error_message' => 'No authorized mock verification URL found.',
                ]);
                update_run($pdo, $runId, 'Error', 'No authorized mock verification URL found.', 'No authorized mock verification URL found.');
                continue;
            }

            $html = http_fetch_page($url);
            update_event($pdo, $eventId, [
                'webpage_opened' => 1,
                'status' => 'Webpage Opened',
            ]);
            update_run($pdo, $runId, 'Webpage Opened', 'Clicking first mock button');

            $action = find_test_button_action($html, $url, mock_button_selector(), mock_button_text());
            if (!is_mock_verification_url($action['url'])) {
                throw new Exception('First mock button action is not allowed.');
            }
            $secondHtml = http_fetch_page($action['url'], $action['method'], $action['fields']);

            update_event($pdo, $eventId, [
                'verification_clicked' => 1,
                'status' => 'Verification Clicked',
            ]);
            update_run($pdo, $runId, 'Verification Clicked', 'Clicking second mock button');

            $secondSelector = mock_second_button_selector();

            if ($secondSelector !== '' || mock_second_button_text() !== '') {
                $secondAction = find_test_button_action($secondHtml, $action['url'], $secondSelector, mock_second_button_text());
                if (!is_mock_verification_url($secondAction['url'])) {
                    throw new Exception('Second mock button action is not allowed.');
                }
                http_fetch_page($secondAction['url'], $secondAction['method'], $secondAction['fields']);
            }

            update_event($pdo, $eventId, [
                'button_clicked' => 1,
                'status' => 'Second Button Clicked',
            ]);
            update_run($pdo, $runId, 'Button Clicked', 'Waiting for new email');
        }
    } catch (Exception $e) {
        update_run($pdo, $runId, 'Error', 'Worker error', $e->getMessage());
    }

    sleep(1);
}
?>
