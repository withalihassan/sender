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
    'us-east-1' => 'ami-0e3c2921641a4a215',
    'us-east-2' => 'ami-0c8eb251138004df2'
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
