<?php
session_start();

require '../includes/json.php';

if (!isset($_SESSION['user_id'])) {
    json_response(['success' => false, 'message' => 'Please login again.']);
}

require '../includes/database.php';

ensure_amazon_tables($pdo);

$accountId = (int) ($_POST['account_id'] ?? 0);
$stmt = $pdo->prepare("
    SELECT id, sender, recipient, subject, email_text, email_json, status, error_message, created_at
    FROM mail_execution_events
    WHERE account_id = ?
    ORDER BY id ASC
");
$stmt->execute([$accountId]);

json_response(['success' => true, 'events' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
?>
