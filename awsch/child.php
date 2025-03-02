<?php

require './aws/aws-autoloader.php'; // Include the AWS SDK autoloader

use Aws\Iam\IamClient;
use Aws\Sts\StsClient;
use Aws\Credentials\Credentials;
use Aws\Exception\AwsException;

// Master account credentials
$masterCredentials = new Credentials('AKIAVIOZGAUZBOI7OPNH', 'M1yf2O7ZXRmvL3Vqf+lV3dTWtFMEJfnPy3/3dPfR');

// Define the child account role ARN
$roleArn = 'arn:aws:iam::CHILD_ACCOUNT_ID:role/OrganizationAccountAccessRole';

try {
    // Step 1: Assume role in child account
    $stsClient = new StsClient([
        'region' => 'us-east-1',
        'version' => 'latest',
        'credentials' => $masterCredentials,
    ]);

    $assumedRole = $stsClient->assumeRole([
        'RoleArn' => $roleArn,
        'RoleSessionName' => 'CreateIAMUserSession',
    ]);

    $temporaryCredentials = new Credentials(
        $assumedRole['Credentials']['AccessKeyId'],
        $assumedRole['Credentials']['SecretAccessKey'],
        $assumedRole['Credentials']['SessionToken']
    );

    echo "Assumed role successfully. Temporary credentials obtained.\n";

    // Step 2: Create an IAM user in the child account
    $iamClient = new IamClient([
        'region' => 'us-east-1',
        'version' => 'latest',
        'credentials' => $temporaryCredentials,
    ]);

    $username = 'ChildAccountIAMUser';

    $createUserResponse = $iamClient->createUser([
        'UserName' => $username,
    ]);

    echo "IAM user '$username' created successfully in the child account.\n";

    // Step 3: Create access keys for the IAM user
    $createAccessKeyResponse = $iamClient->createAccessKey([
        'UserName' => $username,
    ]);

    $accessKey = $createAccessKeyResponse['AccessKey'];

    echo "Access keys created for user '$username':\n";
    echo "Access Key ID: " . $accessKey['AccessKeyId'] . "\n";
    echo "Secret Access Key: " . $accessKey['SecretAccessKey'] . "\n";

    // Store these keys securely; do not expose them in your code or logs
} catch (AwsException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>