<?php
require '../../db.php';
require '../../aws/aws-autoloader.php';

if (!isset($_GET['ac_id'])) {
    die("Invalid account ID.");
}

$accountId = htmlspecialchars($_GET['ac_id']);

// Retrieve the parent's AWS credentials from the local database.
// Adjust the query and field names as needed.
$stmt = $pdo->prepare("SELECT aws_key, aws_secret FROM accounts WHERE account_id = ?");
$stmt->execute([$accountId]);
echo $parentAccount = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$parentAccount) {
    die("Parent account not found.");
}

$aws_key = $parentAccount['aws_key'];
$aws_secret = $parentAccount['aws_secret'];

use Aws\Organizations\OrganizationsClient;
use Aws\Exception\AwsException;

try {
    //Before creating org check from table if. value is. zero or not if zero then show ID Not Found
    $stmt = $pdo->prepare("SELECT total_ids FROM organizations");
    $stmt->execute();
    $availble_ids =  $stmt->fetchColumn();
    if ($availble_ids < 1) {
        echo "Error creating organization: id not found";
    } else {
        $orgClient = new OrganizationsClient([
            'version' => 'latest',
            'region'  => 'us-east-1', // Organizations endpoints are global; region is required.
            'credentials' => [
                'key'    => $aws_key,
                'secret' => $aws_secret,
            ]
        ]);

        // Create an organization.
        // Note: If an organization already exists, this call may fail.
        $result = $orgClient->createOrganization([
            'FeatureSet' => 'ALL' // or use 'CONSOLIDATED_BILLING' if preferred.
        ]);
        // Decrement IDs 
        $pdo->prepare("UPDATE organizations SET total_ids = total_ids - 1 ")->execute();
        echo "Organization created successfully. Organization ID: " . $result['Organization']['Id'];
    }
} catch (AwsException $e) {
    echo "Error creating organization: " . $e->getAwsErrorMessage();
}
