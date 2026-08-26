<?php
if (PHP_SAPI !== 'cli') {
    exit;
}

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/smtpdev_client.php';
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

function event_for_message($pdo, $messageId)
{
    $stmt = $pdo->prepare("SELECT id, account_id, email_text FROM mail_execution_events WHERE message_id = ?");
    $stmt->execute([$messageId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
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

            $existingEvent = event_for_message($pdo, $messageId);

            if ($existingEvent && (int) $existingEvent['account_id'] !== (int) $run['account_id']) {
                continue;
            }

            if ($existingEvent && trim((string) $existingEvent['email_text']) !== '') {
                continue;
            }

            $detail = smtpdev_message_detail($smtpAccountId, $mailboxId, $messageId);
            $fullMessage = array_merge($message, $detail);
            $text = mail_text_from_message($fullMessage);
            $sender = mail_sender_from_message($fullMessage);
            $recipient = mail_recipient_from_message($fullMessage);
            $subject = mail_subject_from_message($fullMessage);
            $json = json_encode($fullMessage, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if ($existingEvent) {
                $stmt = $pdo->prepare("
                    UPDATE mail_execution_events
                    SET run_id = ?, email_address = ?, sender = ?, recipient = ?, subject = ?,
                        email_text = ?, email_json = ?, email_received = 1, status = 'Email Received',
                        error_message = NULL, updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([
                    $runId,
                    $run['email_address'],
                    $sender,
                    $recipient,
                    $subject,
                    $text,
                    $json,
                    $existingEvent['id'],
                ]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO mail_execution_events
                    (run_id, account_id, email_address, message_id, sender, recipient, subject, email_text, email_json, email_received, status, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 'Email Received', NOW(), NOW())
                ");
                $stmt->execute([
                    $runId,
                    $run['account_id'],
                    $run['email_address'],
                    $messageId,
                    $sender,
                    $recipient,
                    $subject,
                    $text,
                    $json,
                ]);
            }

            $pdo->prepare("
                UPDATE mail_execution_runs
                SET status = 'Email Received', emails_processed = emails_processed + ?,
                    last_email_at = NOW(), current_operation = 'Email received and displayed', error_message = NULL, updated_at = NOW()
                WHERE id = ?
            ")->execute([$existingEvent ? 0 : 1, $runId]);
        }
    } catch (Exception $e) {
        update_run($pdo, $runId, 'Error', 'Worker error', $e->getMessage());
    }

    sleep(1);
}
?>
