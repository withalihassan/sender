<?php
session_start();

require '../includes/json.php';

if (!isset($_SESSION['user_id'])) {
    json_response(['success' => false, 'message' => 'Please login again.']);
}

require '../includes/database.php';
require '../mail/mail_processor.php';

ensure_amazon_tables($pdo);

$accountId = (int) ($_POST['account_id'] ?? 0);

$missing = $pdo->prepare("
    SELECT id, email_json
    FROM mail_execution_events
    WHERE account_id = ?
      AND sender = 'recover-mfa-no-reply@verify.signin.aws'
      AND (verification_url IS NULL OR verification_url = '')
");
$missing->execute([$accountId]);
$update = $pdo->prepare("UPDATE mail_execution_events SET verification_url = ?, updated_at = NOW() WHERE id = ?");

foreach ($missing->fetchAll(PDO::FETCH_ASSOC) as $event) {
    $message = json_decode($event['email_json'] ?: '', true);

    if (!is_array($message)) {
        continue;
    }

    $url = mail_verification_url_from_message($message);

    if ($url !== '') {
        $update->execute([$url, $event['id']]);
    }
}

$stmt = $pdo->prepare("
    SELECT id, verification_url, created_at
    FROM mail_execution_events
    WHERE account_id = ?
      AND sender = 'recover-mfa-no-reply@verify.signin.aws'
    ORDER BY id ASC
");
$stmt->execute([$accountId]);

json_response(['success' => true, 'events' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
?>
