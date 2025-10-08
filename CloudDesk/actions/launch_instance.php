<?php
// launch_instance_minimal.php
// Minimal, working launcher: creates keypair, security group, launches Windows instance, stores record (if db.php provides $pdo).
// PUT this file in your actions/ folder. Adjust paths to aws-autoloader and db.php if needed.

header('Content-Type: application/json; charset=utf-8');

// load DB if available (user's db.php should define $pdo as PDO)
$dbPath = __DIR__ . '/../../db.php';
if (file_exists($dbPath)) {
    include $dbPath; // safe include; db.php should set $pdo
}

require_once __DIR__ . '/../../aws/aws-autoloader.php';
use Aws\Ec2\Ec2Client;
use Aws\Exception\AwsException;

function jsonExit($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_PRETTY_PRINT);
    exit;
}

$ak = trim((string)($_POST['aws_access_key'] ?? ''));
$sk = trim((string)($_POST['aws_secret_key'] ?? ''));
$region = trim((string)($_POST['region'] ?? ''));
$instanceType = trim((string)($_POST['instance_type'] ?? ''));
$parentId = trim((string)($_POST['parent_id'] ?? '')) ?: null;

if ($ak === '' || $sk === '' || $region === '' || $instanceType === '') {
    jsonExit(['status'=>'error','message'=>'Missing aws_access_key, aws_secret_key, region or instance_type'],400);
}

// minimal Windows AMI map - extend as needed
$amiMap = [
    'us-east-1'       => 'ami-0e3c2921641a4a215', // US East (N. Virginia)
    'us-east-2'       => 'ami-0c8eb251138004df2', // US East (Ohio)
    'us-west-1'       => 'ami-06bafac9975cc7e16', // US West (N. California)
    'us-west-2'       => 'ami-07f134e32cbbbfc98', // US West (Oregon)
    'af-south-1'      => 'ami-0501e0149581e140b', // Africa (Cape Town)
    'ap-east-1'       => 'ami-04bdb6fe044469c4f', // Asia Pacific (Hong Kong)
    'ap-south-2'      => 'ami-004145e85a6e82d2b', // Asia Pacific (Hyderabad)
    'ap-southeast-3'  => 'ami-0c65bc9f0105f4106', // Asia Pacific (Jakarta)
    'ap-southeast-5'  => 'ami-0d189ff67430cfdb5', // Asia Pacific (Malaysia)
    'ap-southeast-4'  => 'ami-030e2b85f3d418447', // Asia Pacific (Melbourne)
    'ap-south-1'      => 'ami-066eb5725566530f0', // Asia Pacific (Mumbai)
    'ap-southeast-6'  => 'ami-0102cad94e0404f5b', // Asia Pacific (New Zealand)
    'ap-northeast-3'  => 'ami-03c70bc5da4b4ccf0', // Asia Pacific (Osaka)
    'ap-northeast-2'  => 'ami-0c841091a6460aa6b', // Asia Pacific (Seoul)
    'ap-southeast-1'  => 'ami-0b7263fbb02972e76', // Asia Pacific (Singapore)
    'ap-southeast-2'  => 'ami-09bbc01c23bd07334', // Asia Pacific (Sydney)
    'ap-east-2'       => 'ami-06c10b7ddb9e84aa2', // Asia Pacific (Taipei)
    'ap-southeast-7'  => 'ami-0086e40e6c9f17dc3', // Asia Pacific (Thailand)
    'ap-northeast-1'  => 'ami-0910ccfe3edc72362', // Asia Pacific (Tokyo)
    'ca-central-1'    => 'ami-0700225503364abed', // Canada (Central)
    'ca-west-1'       => 'ami-03971f0cf63131dea', // Canada West (Calgary)
    'eu-central-1'    => 'ami-0e2ee5d195754bb30', // Europe (Frankfurt)
    'eu-west-1'       => 'ami-0bed3b2519c0c407f', // Europe (Ireland)
    'eu-west-2'       => 'ami-06973b278573729cc', // Europe (London)
    'eu-south-1'      => 'ami-0a9f4a51519bfe43f', // Europe (Milan)
    'eu-west-3'       => 'ami-03594cfdf106f0944', // Europe (Paris)
    'eu-south-2'      => 'ami-0e624339c4d94e162', // Europe (Spain)
    'eu-north-1'      => 'ami-090c5ada6abc8a801', // Europe (Stockholm)
    'eu-central-2'    => 'ami-0428c41506bbccbb8', // Europe (Zurich)
    'mx-central-1'    => 'ami-08899bc8da6335256', // Mexico (Central)
    'me-south-1'      => 'ami-0e15597ac9d07aae1', // Middle East (Bahrain)
    'me-central-1'    => 'ami-09d48a5089e395dc6', // Middle East (UAE)
    'il-central-1'    => 'ami-0fecaf8480f8f1762', // Israel (Tel Aviv)
    'sa-east-1'       => 'ami-0c0d47e26a00217ab', // South America (São Paulo)
];

if (!isset($amiMap[$region])) jsonExit(['status'=>'error','message'=>"No AMI configured for region {$region}"],400);
$amiId = $amiMap[$region];

try {
    $ec2 = new Ec2Client([
        'region' => $region,
        'version' => 'latest',
        'credentials' => [ 'key' => $ak, 'secret' => $sk ]
    ]);
} catch (Throwable $e) {
    jsonExit(['status'=>'error','message'=>'Failed to create EC2 client: '.$e->getMessage()],500);
}

// key name: letters/numbers/hyphen only
$keyName = 'desk-key-' . bin2hex(random_bytes(4)) . '-' . time();
$newKeyNAmee=$keyName.".pem";

try {
    // create key pair
    $createKey = $ec2->createKeyPair(['KeyName' => $keyName]);
    if (empty($createKey['KeyMaterial'])) throw new Exception('No KeyMaterial returned');
    $keyMaterial = $createKey['KeyMaterial'];

    // save pem
    $dir = __DIR__ . '/keys';
    if (!is_dir($dir) && !mkdir($dir, 0700, true)) throw new Exception('Failed to create keys dir');
    $pemPath = $dir . '/' . $keyName . '.pem';
    // if (file_put_contents($pemPath, $keyMaterial) === false) throw new Exception('Failed to write PEM');

    // create security group - IMPORTANT: name must NOT start with 'sg-'
    $sgName = 'desk-' . substr($keyName, -8);
    $sgDesc = 'Temporary SG for '.$keyName.' (open - testing only)';
    $createSg = $ec2->createSecurityGroup(['GroupName' => $sgName, 'Description' => $sgDesc]);
    $sgId = $createSg['GroupId'] ?? null;
    if (!$sgId) throw new Exception('Failed to create security group');

    // authorize ingress (open to all) - be careful in production!
    $ec2->authorizeSecurityGroupIngress([
        'GroupId' => $sgId,
        'IpPermissions' => [
            [
                'IpProtocol' => '-1',
                'IpRanges' => [['CidrIp' => '0.0.0.0/0']],
                'Ipv6Ranges' => [['CidrIpv6' => '::/0']]
            ]
        ]
    ]);

    // run instance
    $tagName = 'Desk-' . substr($keyName, -6);
    $run = $ec2->runInstances([
        'ImageId' => $amiId,
        'InstanceType' => $instanceType,
        'MinCount' => 1,
        'MaxCount' => 1,
        'KeyName' => $keyName,
        'SecurityGroupIds' => [$sgId],
        'TagSpecifications' => [[
            'ResourceType' => 'instance',
            'Tags' => [
                ['Key' => 'Name', 'Value' => $tagName],
                ['Key' => 'CreatedBy', 'Value' => 'web-ui']
            ]
        ]]
    ]);

    $instanceId = $run['Instances'][0]['InstanceId'] ?? null;
    if (!$instanceId) throw new Exception('No InstanceId returned');

    // wait until running (may take some time)
    try {
        $ec2->waitUntil('InstanceRunning', ['InstanceIds' => [$instanceId]]);
    } catch (AwsException $we) {
        // continue and fetch whatever info we can
    }

    // describe instance
    $desc = $ec2->describeInstances(['InstanceIds' => [$instanceId]]);
    $inst = $desc['Reservations'][0]['Instances'][0] ?? null;
    $publicIp = $inst['PublicIpAddress'] ?? null;
    $state = $inst['State']['Name'] ?? 'unknown';
    $launchedAt = date('Y-m-d H:i:s');

    // store to DB if $pdo exists and is PDO
    $dbMsg = 'DB skipped';
    if (isset($pdo) && $pdo instanceof PDO) {
        // create table if not exists
        $pdo->exec("CREATE TABLE IF NOT EXISTS launched_desks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            unique_id VARCHAR(64) NOT NULL UNIQUE,
            key_name VARCHAR(255),
            key_material LONGTEXT,
            parent_id VARCHAR(255),
            instance_id VARCHAR(100),
            region_name VARCHAR(64),
            type VARCHAR(64),
            state VARCHAR(64),
            public_ip VARCHAR(45),
            launched_at DATETIME
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $stmt = $pdo->prepare("INSERT INTO launched_desks
            (key_name, key_material, parent_id, instance_id, region_name, type, state, public_ip, launched_at)
            VALUES (:key_name, :key_material, :parent_id, :instance_id, :region_name, :type, :state, :public_ip, :launched_at)");
        $stmt->execute([
            ':key_name'=>$newKeyNAmee,
            ':key_material'=>$keyMaterial,
            ':parent_id'=>$parentId,
            ':instance_id'=>$instanceId,
            ':region_name'=>$region,
            ':type'=>$instanceType,
            ':state'=>$state,
            ':public_ip'=>$publicIp,
            ':launched_at'=>$launchedAt
        ]);
        $dbMsg = 'DB insert OK, id='.$pdo->lastInsertId();
    }

    $response = [
        'status'=>'ok',
        'message'=>'Instance launched',
        'instance_id'=>$instanceId,
        'instance_state'=>$state,
        'public_ip'=>$publicIp,
        'key_name'=>$keyName,
        'pem_path'=>$pemPath,
        'security_group_id'=>$sgId,
        'region'=>$region,
        'instance_type'=>$instanceType,
        'launched_at'=>$launchedAt,
        'db_message'=>$dbMsg
    ];

    jsonExit($response,200);

} catch (AwsException $ae) {
    $msg = $ae->getAwsErrorMessage() ?: $ae->getMessage();
    jsonExit(['status'=>'error','message'=>$msg],500);
} catch (Throwable $e) {
    jsonExit(['status'=>'error','message'=>$e->getMessage()],500);
}
