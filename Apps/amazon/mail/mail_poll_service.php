<?php
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/smtpdev_client.php';
require_once __DIR__ . '/mail_processor.php';
require_once __DIR__ . '/mail_button_clicker.php';

function mail_get_run($pdo, $runId)
{
    $stmt = $pdo->prepare("SELECT * FROM mail_execution_runs WHERE id = ?");
    $stmt->execute([$runId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function mail_update_run($pdo, $runId, $status, $operation, $error = null)
{
    $stmt = $pdo->prepare("
        UPDATE mail_execution_runs
        SET status = ?, current_operation = ?, error_message = ?, updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$status, $operation, $error, $runId]);
}

function mail_event_for_message($pdo, $messageId)
{
    $stmt = $pdo->prepare("SELECT id, account_id, subject, email_text, verification_url FROM mail_execution_events WHERE message_id = ?");
    $stmt->execute([$messageId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function mail_poll_once($pdo, $runId)
{
    $run = mail_get_run($pdo, $runId);

    if (!$run || (int) $run['stop_requested'] === 1 || $run['status'] === 'Stopped') {
        return 0;
    }

    mail_update_run($pdo, $runId, 'Running', 'Checking inbox for new email');

    [$smtpAccountId, $mailboxId, $messages] = smtpdev_messages($run['email_address']);
    $inserted = 0;

    foreach ($messages as $message) {
        $messageId = $message['id'] ?? $message['_id'] ?? md5(json_encode($message));
        $existingEvent = mail_event_for_message($pdo, $messageId);
        $messageTime = strtotime($message['date'] ?? $message['createdAt'] ?? $message['created_at'] ?? '');
        $runTime = strtotime($run['created_at'] ?? '');

        if (!$existingEvent && $messageTime && $runTime && $messageTime < $runTime) {
            continue;
        }

        if ($existingEvent && (int) $existingEvent['account_id'] !== (int) $run['account_id']) {
            continue;
        }

        if ($existingEvent) {
            $existingText = trim((string) $existingEvent['email_text']);
            $existingSubject = trim((string) $existingEvent['subject']);
            $existingUrl = trim((string) $existingEvent['verification_url']);

            if ($existingText !== '' && $existingText !== $existingSubject && $existingUrl !== '' && strlen($existingText) > 100) {
                continue;
            }
        }

        $detail = smtpdev_message_detail($smtpAccountId, $mailboxId, $messageId);
        $fullMessage = array_merge($message, $detail);
        $detailTime = strtotime($fullMessage['date'] ?? $fullMessage['createdAt'] ?? $fullMessage['created_at'] ?? '');

        if (!$existingEvent && !$messageTime && $detailTime && $runTime && $detailTime < $runTime) {
            continue;
        }

        $text = mail_text_from_message($fullMessage);
        $sender = mail_sender_from_message($fullMessage);
        $recipient = mail_recipient_from_message($fullMessage);
        $subject = mail_subject_from_message($fullMessage);
        $verificationUrl = mail_verification_url_from_message($fullMessage);
        $json = json_encode($fullMessage, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($existingEvent) {
            $existingText = trim((string) $existingEvent['email_text']);
            $existingSubject = trim((string) ($existingEvent['subject'] ?: $subject));
            $existingUrl = trim((string) $existingEvent['verification_url']);

            if ($existingText !== '' && $existingText !== $existingSubject && $existingUrl !== '' && strlen($existingText) >= strlen($text)) {
                continue;
            }
        }

        if ($existingEvent) {
            $stmt = $pdo->prepare("
                UPDATE mail_execution_events
                SET run_id = ?, email_address = ?, sender = ?, recipient = ?, subject = ?,
                    verification_url = ?, email_text = ?, email_json = ?, email_received = 1, status = 'Email Received',
                    error_message = NULL, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                $runId,
                $run['email_address'],
                $sender,
                $recipient,
                $subject,
                $verificationUrl,
                $text,
                $json,
                $existingEvent['id'],
            ]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO mail_execution_events
                (run_id, account_id, email_address, message_id, sender, recipient, subject, verification_url, email_text, email_json, email_received, status, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 'Email Received', NOW(), NOW())
            ");
            $stmt->execute([
                $runId,
                $run['account_id'],
                $run['email_address'],
                $messageId,
                $sender,
                $recipient,
                $subject,
                $verificationUrl,
                $text,
                $json,
            ]);
            $inserted++;
        }
    }

    if ($inserted > 0) {
        $stmt = $pdo->prepare("
            UPDATE mail_execution_runs
            SET status = 'Email Received', emails_processed = emails_processed + ?,
                last_email_at = NOW(), current_operation = 'Email received and displayed',
                error_message = NULL, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$inserted, $runId]);
    } else {
        mail_update_run($pdo, $runId, 'Running', 'Waiting for new email');
    }

    mail_process_pending_button_clicks($pdo, (int) $run['account_id']);

    return $inserted;
}
?>
