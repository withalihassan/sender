<?php
// launch_instance_minimal.php
// Minimal, working launcher: creates keypair, security group, launches Windows instance, stores record (if db.php provides $pdo).
// PUT this file in your actions/ folder. Adjust paths to aws-autoloader and db.php if needed.

header('Content-Type: application/json; charset=utf-8');

// load DB if available (user's db.php should define $pdo as PDO)
$dbPath = __DIR__ . '/../../../db.php';
if (file_exists($dbPath)) {
    include $dbPath; // safe include; db.php should set $pdo
}

require_once __DIR__ . '/../../../aws/aws-autoloader.php';
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
    'us-east-1'       => 'ami-09ec5d80b0e576a78', // US East (N. Virginia)
    'us-east-2'       => 'ami-07de89e0cd068bd80', // US East (Ohio)
    'us-west-1'       => 'ami-0b4354a4d5336ce0b', // US West (N. California)
    'us-west-2'       => 'ami-0bac335b53ba08153', // US West (Oregon)
    'af-south-1'      => 'ami-03dd2b4e8be173da5', // Africa (Cape Town)
    'ap-east-1'       => 'ami-063ccb701a6d2e650', // Asia Pacific (Hong Kong)
    'ap-south-2'      => 'ami-0543ae7dd1adef201', // Asia Pacific (Hyderabad)
    'ap-southeast-3'  => 'ami-046aa7832f9149e31', // Asia Pacific (Jakarta)
    'ap-southeast-5'  => 'ami-0b81d6994d4a574ed', // Asia Pacific (Malaysia)
    'ap-southeast-4'  => 'ami-0cc57c19548b66fc3', // Asia Pacific (Melbourne)
    'ap-south-1'      => 'ami-048c00151532eafad', // Asia Pacific (Mumbai)
    // 'ap-southeast-6'  => 'ami-0102cad94e0404f5b', // Asia Pacific (New Zealand)
    'ap-northeast-3'  => 'ami-0db66eca453873832', // Asia Pacific (Osaka)
    'ap-northeast-2'  => 'ami-0ccea1899a767b0e6', // Asia Pacific (Seoul)
    'ap-southeast-1'  => 'ami-093413e677f1e9b91', // Asia Pacific (Singapore)
    'ap-southeast-2'  => 'ami-06d84fd314b86df64', // Asia Pacific (Sydney)
    'ap-east-2'       => 'ami-0c730f669c50cb2a7', // Asia Pacific (Taipei)
    'ap-southeast-7'  => 'ami-0c848b7356eb7b210', // Asia Pacific (Thailand)
    'ap-northeast-1'  => 'ami-06ab2146725802eb3', // Asia Pacific (Tokyo)
    'ca-central-1'    => 'ami-005d5cb9af0226eb8', // Canada (Central)
    'ca-west-1'       => 'ami-0c311e8dd87058e24', // Canada West (Calgary)
    'eu-central-1'    => 'ami-0cccec5140600f7bf', // Europe (Frankfurt)
    'eu-west-1'       => 'ami-000a79d692310a0a8', // Europe (Ireland)
    'eu-west-2'       => 'ami-0e5692e91f097f68b', // Europe (London)
    'eu-south-1'      => 'ami-0a1e53a1bcc8694d4', // Europe (Milan)
    'eu-west-3'       => 'ami-08f5ca7bc73f61aaa', // Europe (Paris)
    'eu-south-2'      => 'ami-0b20211c559e98a4a', // Europe (Spain)
    'eu-north-1'      => 'ami-083fac1d6b94e2d5c', // Europe (Stockholm)
    'eu-central-2'    => 'ami-0e8edff30c053acea', // Europe (Zurich)
    'mx-central-1'    => 'ami-030c3433d7c18e1ef', // Mexico (Central)
    'me-south-1'      => 'ami-0e15597ac9d07aae1', // Middle East (Bahrain)
    'me-central-1'    => 'ami-0e3ee148731f57b2b', // Middle East (UAE)
    'il-central-1'    => 'ami-01ee2e355e0269f97', // Israel (Tel Aviv)
    'sa-east-1'       => 'ami-0e6bc76d1e541849e', // South America (São Paulo)
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
    // if (!is_dir($dir) && !mkdir($dir, 0700, true)) throw new Exception('Failed to create keys dir');
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

    // set root device name and force root EBS volume to 100 GB
    $rootDevice = '/dev/sda1';
    $blockDeviceMappings = [
        [
            'DeviceName' => $rootDevice,
            'Ebs' => [
                'VolumeSize' => 100,
                'VolumeType' => 'gp3',
                'DeleteOnTermination' => true
            ]
        ]
    ];

    $run = $ec2->runInstances([
        'ImageId' => $amiId,
        'InstanceType' => $instanceType,
        'MinCount' => 1,
        'MaxCount' => 1,
        'KeyName' => $keyName,
        'SecurityGroupIds' => [$sgId],
        'BlockDeviceMappings' => $blockDeviceMappings,
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
