<?php
// AKIAUUCUFIA523DWMHWU
// fg485bWijuaz/9gvzziSEGr7QTssyJzXiYuQjjYs
// aws sts assume-role --role-arn arn:aws:iam::061051248547:role/OrganizationAccountAccessRole --role-session-name "CreateIAMUserSession" --region us-east-1
error_reporting(E_ALL);
ini_set('display_errors', 1);

require './aws/aws-autoloader.php';

// AWS SDK Clients
use Aws\Organizations\OrganizationsClient;
use Aws\Sts\StsClient;
use Aws\Iam\IamClient;
use Aws\Exception\AwsException;

// AWS Parent Account Credentials
$accessKey = 'AKIAVIOZGAD2R23VINWI';  // Replace with actual access key
$secretKey = 'jK+NCbqKWHjgsz5jYDf3em7HNFMiy8QsbaulUUYc';  // Replace with actual secret key
$region = 'us-east-1'; // AWS Region

// Initialize AWS Clients
$orgClient = new OrganizationsClient([
    'version' => 'latest',
    'region'  => $region,
    'credentials' => [
        'key'    => $accessKey,
        'secret' => $secretKey,
    ]
]);

$stsClient = new StsClient([
    'version' => 'latest',
    'region'  => $region,
    'credentials' => [
        'key'    => $accessKey,
        'secret' => $secretKey,
    ]
]);

$iamClient = new IamClient([
    'version' => 'latest',
    'region'  => $region,
    'credentials' => [
        'key'    => $accessKey,
        'secret' => $secretKey,
    ]
]);

// Error Handling Function
function displayError($message)
{
    echo json_encode(["status" => "error", "message" => $message]);
    exit;
}

// Process Step 1: Create Child Account
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Step 1: Create a Child Account
        $response = $orgClient->createAccount([
            'Email' => 'magicore@fashiontodo.com', // Use a unique email for each account
            'AccountName' => 'magicore',         // Name of the child account
            'RoleName' => 'OrganizationAccountAccessRole', // Default role in child account
            'IamUserAccessToBilling' => 'ALLOW',        // Allow IAM user to access billing info
        ]);
        
        // Retrieve the request ID for tracking status
        $createAccountRequestId = $response['CreateAccountStatus']['Id'];

        // Step 2: Wait for Account Creation to Complete
        while (true) {
            $statusResponse = $orgClient->describeCreateAccountStatus([
                'CreateAccountRequestId' => $createAccountRequestId,
            ]);
            $status = $statusResponse['CreateAccountStatus']['State'];

            if ($status == 'SUCCEEDED') {
                $childAccountId = $statusResponse['CreateAccountStatus']['AccountId'];
                break;
            } elseif ($status == 'FAILED') {
                displayError("Account creation failed.");
            }
            sleep(10); // Check status every 10 seconds
        }

        // Step 3: Assume Role in Child Account
        $assumedRole = $stsClient->assumeRole([
            'RoleArn' => "arn:aws:iam::$childAccountId:role/OrganizationAccountAccessRole",
            'RoleSessionName' => 'CreateIAMUserSession',
        ]);

        $credentials = $assumedRole['Credentials'];

        // Step 4: Create IAM User in Child Account
        $iamClient = new IamClient([
            'version' => 'latest',
            'region'  => $region,
            'credentials' => [
                'key'    => $credentials['AccessKeyId'],
                'secret' => $credentials['SecretAccessKey'],
            ]
        ]);

        // Create IAM user in child account
        $iamUserResponse = $iamClient->createUser([
            'UserName' => 'NewIAMUser',
        ]);

        // Create IAM access keys for the new user
        $accessKeyResponse = $iamClient->createAccessKey([
            'UserName' => 'NewIAMUser',
        ]);

        $accessKey = $accessKeyResponse['AccessKey']['AccessKeyId'];
        $secretKey = $accessKeyResponse['AccessKey']['SecretAccessKey'];

        // Return the result as JSON
        echo json_encode([
            "status" => "success",
            "childAccountId" => $childAccountId,
            "accessKey" => $accessKey,
            "secretKey" => $secretKey
        ]);
    } catch (AwsException $e) {
        displayError("AWS Error: {$e->getMessage()}");
    } catch (Exception $e) {
        displayError("General Error: {$e->getMessage()}");
    }

    exit;
} else {
    // Display HTML and "Start Process" Button
    echo '<html><head><link rel="stylesheet" href="styles.css"></head><body>';
    echo '<h1>Create AWS Child Account</h1>';
    echo '<button id="startBtn">Start Process</button>';
    echo '<div id="progress">Click the button to start the process.</div>';
    echo '<div id="output"></div>';

    echo '<script>
            document.getElementById("startBtn").addEventListener("click", function() {
                document.getElementById("progress").innerText = "Step 1: Creating Child Account...";
                var xhr = new XMLHttpRequest();
                xhr.open("POST", "create_child_account.php", true);
                xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
                xhr.onreadystatechange = function() {
                    if (xhr.readyState === 4 && xhr.status === 200) {
                        var response = JSON.parse(xhr.responseText);
                        if (response.status === "success") {
                            document.getElementById("progress").innerText = "Process Complete!";
                            document.getElementById("output").innerHTML = "<div>Child Account ID: " + response.childAccountId + "</div>" +
                                                                              "<div>Access Key: " + response.accessKey + "</div>" +
                                                                              "<div>Secret Key: " + response.secretKey + "</div>";
                        } else {
                            document.getElementById("progress").innerText = "Error: " + response.message;
                        }
                    }
                };
                xhr.send();
            });
          </script>';
    echo '</body></html>';
}
?>
