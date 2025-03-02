<?php
require './aws/aws-autoloader.php';  // Include the AWS SDK autoloader

use Aws\Ec2\Ec2Client;
use Aws\Credentials\Credentials;
use Aws\Exception\AwsException;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // AWS Access and Secret Key (replace with your actual keys)
    $awsAccessKey = 'AKIA2S2Y4QVQELTQL65E';
    $awsSecretKey = 'nPi6w+WvYjJwqkJajlHdMV0MRRc83NsGmHhhSe1b';

    // List of regions with corresponding AMI IDs for Ubuntu (modify according to your needs)
    $regions = [
        'us-east-1' => 'ami-xxxxxxxx', 
        'us-west-1' => 'ami-yyyyyyyy',
        'us-west-2' => 'ami-zzzzzzzz',
        'eu-west-1' => 'ami-aaaaaaaa',
        'ap-south-1' => 'ami-bbbbbbbb',
        // Add all regions here with their specific AMI IDs
    ];

    $keyName = 'your-key-pair';  // Your EC2 key pair name
    $shFileUrl = 'https://example.com/path/to/your/script.sh';  // URL to your .sh script

    // AWS SDK credentials
    $credentials = new Credentials($awsAccessKey, $awsSecretKey);

    // Initialize an array to store instance IDs
    $instanceIds = [];

    // Launch EC2 instances asynchronously for each region
    foreach ($regions as $region => $amiId) {
        try {
            // Create EC2 client for each region
            $ec2Client = new Ec2Client([
                'region' => $region,
                'version' => 'latest',
                'credentials' => $credentials
            ]);

            // Read the .sh file content and encode as base64
            $userData = base64_encode(file_get_contents($shFileUrl));

            // Launch EC2 instance asynchronously (without waiting for response)
            $result = $ec2Client->runInstances([
                'ImageId' => $amiId,
                'InstanceType' => 't2.micro',  // Modify the instance type as needed
                'MinCount' => 1,
                'MaxCount' => 1,
                'KeyName' => $keyName,
                'UserData' => $userData,
                'TagSpecifications' => [
                    [
                        'ResourceType' => 'instance',
                        'Tags' => [
                            [
                                'Key' => 'Name',
                                'Value' => 'MyUbuntuInstance-' . $region
                            ]
                        ]
                    ]
                ],
            ]);

            // Store the instance ID of the launched instance
            $instanceIds[] = $result['Instances'][0]['InstanceId'];
        } catch (AwsException $e) {
            echo "Error launching instance in region: $region\n";
            echo "Error message: " . $e->getMessage() . "\n";
        }
    }

    // Wait for instances to be running
    $allRunning = false;
    while (!$allRunning) {
        $allRunning = true;
        foreach ($instanceIds as $instanceId) {
            // Check the status of each instance
            $ec2Client = new Ec2Client([
                'region' => 'us-east-1', // You can use any region to get status (no specific region needed)
                'version' => 'latest',
                'credentials' => $credentials
            ]);
            $instanceStatus = $ec2Client->describeInstances([
                'InstanceIds' => [$instanceId]
            ]);

            $state = $instanceStatus['Reservations'][0]['Instances'][0]['State']['Name'];
            if ($state !== 'running') {
                $allRunning = false;
                break;
            }
        }
        sleep(10); // Wait for 10 seconds before checking again
    }

    echo "<script>alert('All instances are running!');</script>";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Launch EC2 Instances</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            text-align: center;
            padding: 50px;
            background-color: #f4f4f9;
        }
        button {
            background-color: #4CAF50;
            color: white;
            border: none;
            padding: 15px 32px;
            text-align: center;
            font-size: 16px;
            cursor: pointer;
            border-radius: 5px;
        }
        button:hover {
            background-color: #45a049;
        }
        .message {
            font-size: 20px;
            margin-top: 20px;
        }
        table {
            width: 80%;
            margin: 20px auto;
            border-collapse: collapse;
        }
        table, th, td {
            border: 1px solid #ddd;
            padding: 8px;
        }
        th {
            background-color: #4CAF50;
            color: white;
        }
        tr:nth-child(even) {
            background-color: #f2f2f2;
        }
    </style>
</head>
<body>

<h1>Launch EC2 Instances Across Regions</h1>
<p>Click the button below to launch EC2 instances across different AWS regions. This will trigger the instance creation process.</p>

<form method="POST">
    <button type="submit">Launch All Instances</button>
</form>

<?php if (isset($instanceIds) && !empty($instanceIds)): ?>
    <h2>List of Running Instances</h2>
    <table>
        <tr>
            <th>Instance ID</th>
            <th>Region</th>
            <th>Status</th>
        </tr>
        <?php
        // Fetch and display the instance status
        foreach ($instanceIds as $instanceId):
            $ec2Client = new Ec2Client([
                'region' => 'us-east-1', // You can use any region for status check
                'version' => 'latest',
                'credentials' => $credentials
            ]);
            $instanceStatus = $ec2Client->describeInstances([
                'InstanceIds' => [$instanceId]
            ]);

            $state = $instanceStatus['Reservations'][0]['Instances'][0]['State']['Name'];
            $region = $instanceStatus['Reservations'][0]['Instances'][0]['Placement']['AvailabilityZone'];
        ?>
        <tr>
            <td><?php echo $instanceId; ?></td>
            <td><?php echo $region; ?></td>
            <td><?php echo $state; ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>

</body>
</html>
