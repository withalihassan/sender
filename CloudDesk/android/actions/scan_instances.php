<?php
// scan_instances.php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require '../../../db.php';
require '../../../aws/aws-autoloader.php';

use Aws\Ec2\Ec2Client;
use Aws\Exception\AwsException;

header('Content-Type: application/json');

if (!isset($_POST['account_id'], $_POST['region'])) {
    echo json_encode(['success' => false, 'message' => 'Missing parameters.']);
    exit;
}

$account_id = (int) $_POST['account_id'];
$region     = trim($_POST['region']);

if ($account_id <= 0 || $region === '') {
    echo json_encode(['success' => false, 'message' => 'Invalid account or region.']);
    exit;
}

// Fetch AWS credentials from accounts table
$stmt = $pdo->prepare("SELECT aws_key, aws_secret FROM accounts WHERE id = ?");
$stmt->execute([$account_id]);
$account = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$account) {
    echo json_encode(['success' => false, 'message' => 'AWS account not found.']);
    exit;
}

$ec2Client = new Ec2Client([
    'version'     => 'latest',
    'region'      => $region,
    'credentials' => [
        'key'    => $account['aws_key'],
        'secret' => $account['aws_secret'],
    ],
]);

try {
    $insertedCount = 0;
    $updatedCount  = 0;
    $foundCount    = 0;

    // Paginator makes sure we read all instances, not just the first page.
    $paginator = $ec2Client->getPaginator('DescribeInstances');

    foreach ($paginator as $page) {
        if (empty($page['Reservations']) || !is_array($page['Reservations'])) {
            continue;
        }

        foreach ($page['Reservations'] as $reservation) {
            if (empty($reservation['Instances']) || !is_array($reservation['Instances'])) {
                continue;
            }

            foreach ($reservation['Instances'] as $instance) {
                $foundCount++;

                $instanceId   = $instance['InstanceId'] ?? null;
                $instanceType = $instance['InstanceType'] ?? null;
                $state        = $instance['State']['Name'] ?? null;
                $publicIp     = $instance['PublicIpAddress'] ?? null;

                if (!$instanceId) {
                    continue;
                }

                $launchedAt = date('Y-m-d H:i:s');
                if (isset($instance['LaunchTime']) && $instance['LaunchTime'] instanceof DateTimeInterface) {
                    $launchedAt = $instance['LaunchTime']->format('Y-m-d H:i:s');
                }

                // Check existing row
                $checkStmt = $pdo->prepare("
                    SELECT id
                    FROM launched_desks
                    WHERE parent_id = ? AND instance_id = ?
                    LIMIT 1
                ");
                $checkStmt->execute([$account_id, $instanceId]);
                $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

                if ($existing) {
                    $updateStmt = $pdo->prepare("
                        UPDATE launched_desks
                        SET region_name = ?,
                            type = ?,
                            state = ?,
                            public_ip = ?,
                            password = ?,
                            launched_at = ?,
                            key_name = NULL,
                            key_material = NULL
                        WHERE parent_id = ? AND instance_id = ?
                    ");

                    $updateStmt->execute([
                        $region,
                        $instanceType,
                        $state,
                        $publicIp,
                        $instanceId,
                        $launchedAt,
                        $account_id,
                        $instanceId
                    ]);

                    $updatedCount++;
                } else {
                    $insertStmt = $pdo->prepare("
                        INSERT INTO launched_desks
                        (key_name, key_material, parent_id, instance_id, region_name, type, state, public_ip, password, launched_at)
                        VALUES
                        (NULL, NULL, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");

                    $insertStmt->execute([
                        $account_id,
                        $instanceId,
                        $region,
                        $instanceType,
                        $state,
                        $publicIp,
                        $instanceId,
                        $launchedAt
                    ]);

                    $insertedCount++;
                }
            }
        }
    }

    if ($foundCount === 0) {
        echo json_encode([
            'success' => false,
            'message' => "No instances found in region {$region}. Please verify the AWS credentials belong to the same account and that the instance really exists in this region."
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'message' => "Scan complete. {$insertedCount} new instance(s) inserted, {$updatedCount} existing instance(s) updated."
    ]);
    exit;

} catch (AwsException $e) {
    error_log("AWS Exception in scan_instances.php: " . $e->getMessage());

    echo json_encode([
        'success' => false,
        'message' => 'AWS error: ' . $e->getAwsErrorMessage() ?: $e->getMessage()
    ]);
    exit;

} catch (Exception $e) {
    error_log("General Exception in scan_instances.php: " . $e->getMessage());

    echo json_encode([
        'success' => false,
        'message' => 'Unexpected error: ' . $e->getMessage()
    ]);
    exit;
}