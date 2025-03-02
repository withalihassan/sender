<?php
// include "./nav.php";
error_reporting(E_ALL);
ini_set('display_errors', 1);
require './aws/aws-autoloader.php'; // Include the AWS SDK autoloader

use Aws\Ec2\Ec2Client;
use Aws\Credentials\Credentials;
use Aws\Exception\AwsException;

// Database connection
require './db_connect.php';

// Fetch child_id and parent_id from the URL
$child_id = isset($_GET['child_id']) ? $_GET['child_id'] : null;
$parent_id = isset($_GET['parent_id']) ? $_GET['parent_id'] : null;

// Ensure both child_id and parent_id are present
if ($child_id && $parent_id) {
    // Prepare the SQL query to fetch account keys
    $sql = "SELECT `aws_access_key`, `aws_secret_key`
            FROM `child_accounts`
            WHERE `account_id` = :child_id AND `parent_id` = :parent_id";

    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':child_id', $child_id, PDO::PARAM_INT);
    $stmt->bindParam(':parent_id', $parent_id, PDO::PARAM_INT);

    // Execute the query
    $stmt->execute();

    // Fetch the result
    $accountData = $stmt->fetch(PDO::FETCH_ASSOC);

    // Check if data exists
    if ($accountData) {
        // Check if the AWS access and secret keys are available
        if (!empty($accountData['aws_access_key']) && !empty($accountData['aws_secret_key'])) {
            // Assign the keys to the variables
            $awsAccessKey = $accountData['aws_access_key'];
            $awsSecretKey = $accountData['aws_secret_key'];
            echo '<div class="response success">Child Account is ready to use</div>';
        } else {
            // Keys are not available
            echo '<div class="response error">You must first set up the account and open it</div>';
            $awsAccessKey = NULL;
            $awsSecretKey = NULL;
        }
    } else {
        echo "No account found for the given child_id and parent_id.";
    }
} else {
    echo "Child ID and Parent ID are required.";
}

// AWS Access and Secret Key
// $awsAccessKey = 'AKIATX3PHSZSPHF2R2FR';
// $awsSecretKey = '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';

// List of regions with corresponding AMI IDs for Ubuntu
$regions = [
    'us-east-1' => 'ami-0e2c8caa4b6378d8c',
    'us-east-2' => 'ami-036841078a4b68e14',
    'us-west-1' => 'ami-0657605d763ac72a8',
    'us-west-2' => 'ami-05d38da78ce859165',
    ///space
    'ap-south-1' => 'ami-053b12d3152c0cc71',
    // 'ap-northeast-3' => 'ami-0b40dea19b4538863',
    'ap-northeast-2' => 'ami-0dc44556af6f78a7b',
    'ap-southeast-1' => 'ami-06650ca7ed78ff6fa',
    'ap-southeast-2' => 'ami-003f5a76758516d1e',
    'ap-northeast-1' => 'ami-003f5a76758516d1e',
    ///space
    'ca-central-1' => 'ami-0bee12a638c7a8942',
    ///space
    'eu-central-1' => 'ami-0a628e1e89aaedf80',
    'eu-west-1' => 'ami-0e9085e60087ce171',
    'eu-west-2' => 'ami-05c172c7f0d3aed00',
    'eu-west-3' => 'ami-09be70e689bddcef5',
    'eu-north-1' => 'ami-09be70e689bddcef5',
    ///space
    'sa-east-1' => 'ami-015f3596bb2ef1aaa',
];
// $keyName = 'your-key-pair'; // Your EC2 key pair name
$shFileUrl = 'https://s3.eu-north-1.amazonaws.com/insoftstudio.com/auto-start-process.sh'; // URL to your .sh script

// AWS SDK credentials
$credentials = new Credentials($awsAccessKey, $awsSecretKey);

$instanceIds = [];
$runningInstances = [];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'];

    if ($action === 'launch_one' || $action === 'launch_all') {
        $instanceType = $_POST['instance_type'];
        $launchType = $_POST['launch_type'];

        if ($action === 'launch_one') {
            $region = $_POST['region'];
            $amiId = $regions[$region];
            launchInstance($region, $amiId, $instanceType, $launchType);
        } else {
            foreach ($regions as $region => $amiId) {
                launchInstance($region, $amiId, $instanceType, $launchType);
            }
        }
    } elseif ($action === 'terminate') {
        $region = $_POST['region'];
        $instanceId = $_POST['instance_id'];
        terminateInstance($region, $instanceId);
    }
}

function launchInstance($region, $amiId, $instanceType, $launchType) {
    global $credentials, $keyName, $shFileUrl, $instanceIds;

    try {
        $ec2Client = new Ec2Client([
            'region' => $region,
            'version' => 'latest',
            'credentials' => $credentials,
        ]);

        $userData = base64_encode(file_get_contents($shFileUrl));

        $params = [
            'ImageId' => $amiId,
            'InstanceType' => $instanceType,
            'MinCount' => 1,
            'MaxCount' => 1,
            'UserData' => $userData,
            'TagSpecifications' => [
                [
                    'ResourceType' => 'instance',
                    'Tags' => [
                        [
                            'Key' => 'Name',
                            'Value' => 'MyUbuntuInstance-' . $region,
                        ],
                    ],
                ],
            ],
        ];

        if ($launchType === 'spot') {
            $params['InstanceMarketOptions'] = [
                'MarketType' => 'spot',
            ];
        }

        $result = $ec2Client->runInstances($params);

        $instanceIds[] = [
            'InstanceId' => $result['Instances'][0]['InstanceId'],
            'Region' => $region,
        ];
    } catch (AwsException $e) {
        echo "Error launching instance in region: $region<br>";
        echo "Error message: " . $e->getMessage() . "<br>";
    }
}

function terminateInstance($region, $instanceId) {
    global $credentials;

    try {
        $ec2Client = new Ec2Client([
            'region' => $region,
            'version' => 'latest',
            'credentials' => $credentials,
        ]);

        $ec2Client->terminateInstances([
            'InstanceIds' => [$instanceId],
        ]);
    } catch (AwsException $e) {
        echo "Error terminating instance: $instanceId<br>";
        echo "Error message: " . $e->getMessage() . "<br>";
    }
}

// Fetch running instances
foreach ($regions as $region => $amiId) {
    $ec2Client = new Ec2Client([
        'region' => $region,
        'version' => 'latest',
        'credentials' => $credentials,
    ]);

    $reservations = $ec2Client->describeInstances([
        'Filters' => [
            [
                'Name' => 'instance-state-name',
                'Values' => ['running', 'pending'],
            ],
        ],
    ])['Reservations'];

    foreach ($reservations as $reservation) {
        foreach ($reservation['Instances'] as $instance) {
            $runningInstances[] = [
                'InstanceId' => $instance['InstanceId'],
                'Region' => $region,
                'State' => $instance['State']['Name'],
                'InstanceType' => $instance['InstanceType'],
            ];
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage EC2 Instances</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            text-align: center;
            background-color: #f4f4f9;
            padding: 20px;
        }
        select, button, input {
            padding: 10px;
            font-size: 16px;
            margin: 10px;
        }
        table {
            width: 90%;
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
        button {
            cursor: pointer;
            background-color: #4CAF50;
            color: white;
            border: none;
        }
        button:hover {
            background-color: #45a049;
        }
    </style>
</head>
<body>

<h1>Manage EC2 Instances</h1>

<form method="POST">
    <label for="region">Select Region:</label>
    <select name="region" id="region">
        <?php foreach ($regions as $region => $amiId): ?>
            <option value="<?php echo $region; ?>"><?php echo $region; ?></option>
        <?php endforeach; ?>
    </select>
    <label for="instance_type">Instance Type:</label>
    <select name="instance_type" id="instance_type">
        <option value="c7i.xlarge">c7i.xlarge</option>
        <option value="c7a.xlarge">c7a.xlarge</option>
        <option value="c7a.4xlarge">c7a.4xlarge</option>
        <option value="c7a.4xlarge">c7a.4xlarge</option>
        <option value="c7a.8xlarge">c7a.8xlarge</option>
        <option value="c7i.8xlarge">c7i.8xlarge</option>
        <!-- <option value="t2.micro">t2.micro</option>
        <option value="t3.micro">t3.micro</option>
        <option value="t3.medium">t3.medium</option> -->
    </select>
    <label for="launch_type">Launch Type:</label>
    <select name="launch_type" id="launch_type">
        <option value="ondemand">On-Demand</option>
        <option value="spot">Spot</option>
    </select>
    <button type="submit" name="action" value="launch_one">Launch in Selected Region</button>
    <button type="submit" name="action" value="launch_all">Launch in All Regions</button>
</form>

<?php if (!empty($runningInstances)): ?>
    <h2>Running Instances</h2>
    <table>
        <tr>
            <th>Instance ID</th>
            <th>Region</th>
            <th>Instance Size</th>
            <th>Status</th>
            <th>Action</th>
        </tr>
        <?php foreach ($runningInstances as $instance): ?>
            <tr>
                <td><?php echo $instance['InstanceId']; ?></td>
                <td><?php echo $instance['Region']; ?></td>
                <td><?php echo $instance['InstanceType']; ?></td>
                <td><?php echo $instance['State']; ?></td>
                <td>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="region" value="<?php echo $instance['Region']; ?>">
                        <input type="hidden" name="instance_id" value="<?php echo $instance['InstanceId']; ?>">
                        <button type="submit" name="action" value="terminate">Terminate</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>

</body>
</html>
