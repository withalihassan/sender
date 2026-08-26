<?php
session_start();

require '../includes/json.php';

if (!isset($_SESSION['user_id'])) {
    json_response(['success' => false, 'message' => 'Please login again.']);
}

require '../includes/database.php';
require '../includes/aws.php';

ensure_amazon_tables($pdo);

$awsKey = trim($_POST['aws_key'] ?? '');
$awsSecret = trim($_POST['aws_secret'] ?? '');

if ($awsKey === '' || $awsSecret === '') {
    json_response(['success' => false, 'message' => 'AWS Access Key and Secret Key are required.']);
}

try {
    $awsAccountId = get_aws_account_id($awsKey, $awsSecret);

    $check = $pdo->prepare("SELECT id FROM accounts WHERE aws_account_id = ?");
    $check->execute([$awsAccountId]);

    if ($check->fetch()) {
        json_response(['success' => false, 'message' => 'This AWS Account ID is already saved.']);
    }

    $createdAt = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("
        INSERT INTO accounts (aws_account_id, aws_key, aws_secret, created_at)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$awsAccountId, $awsKey, $awsSecret, $createdAt]);

    json_response([
        'success' => true,
        'message' => 'AWS account added successfully.',
        'account' => [
            'id' => $pdo->lastInsertId(),
            'aws_account_id' => $awsAccountId,
            'aws_key' => mask_value($awsKey),
            'aws_secret' => mask_value($awsSecret),
            'created_at' => $createdAt,
        ],
    ]);
} catch (Exception $e) {
    json_response(['success' => false, 'message' => 'Invalid AWS credentials: ' . aws_error_message($e)]);
}
?>
