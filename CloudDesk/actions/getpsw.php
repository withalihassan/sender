<?php
header('Content-Type: application/json; charset=utf-8');

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!$input) $input = $_POST ?? null;
if (!$input) { http_response_code(400); echo json_encode(['status'=>'error','message'=>'no input received']); exit; }

// required params
$awsAccessKey = $input['awsAccessKey'] ?? null;
$awsSecretKey = $input['awsSecretKey'] ?? null;
$instanceId   = $input['instance_id'] ?? null;
$region       = $input['region'] ?? null;
$parent_id    = $input['parent_id'] ?? null;
$id           = $input['id'] ?? null;

if (!$awsAccessKey || !$awsSecretKey || !$instanceId || !$region) {
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>'missing required parameters (awsAccessKey/awsSecretKey/instance_id/region)']);
    exit;
}

// load DB and AWS SDK
require_once __DIR__ . '/../../db.php';                      // your DB connection file
require_once __DIR__ . '/../../aws/aws-autoloader.php';      // AWS PHP SDK
use Aws\Ec2\Ec2Client;
use Aws\Exception\AwsException;

// Helper: find DB connection object (PDO or mysqli)
function getDbHandle() {
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) return ['type'=>'pdo','handle'=>$GLOBALS['pdo']];
    if (isset($GLOBALS['db']) && $GLOBALS['db'] instanceof PDO) return ['type'=>'pdo','handle'=>$GLOBALS['db']];
    if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) return ['type'=>'mysqli','handle'=>$GLOBALS['conn']];
    if (isset($GLOBALS['mysqli']) && $GLOBALS['mysqli'] instanceof mysqli) return ['type'=>'mysqli','handle'=>$GLOBALS['mysqli']];
    if (isset($GLOBALS['link']) && $GLOBALS['link'] instanceof mysqli) return ['type'=>'mysqli','handle'=>$GLOBALS['link']];
    return null;
}

$dbHandleInfo = getDbHandle();
if (!$dbHandleInfo) {
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>'database connection not found. Ensure db.php defines $pdo (PDO) or $conn/$mysqli (mysqli).']);
    exit;
}

// 1) fetch key_material for this instance (or by id/parent_id)
$keyMaterial = null;
try {
    $sql = "SELECT `id`,`key_material` FROM `launched_desks` WHERE instance_id = :instance_id OR id = :id OR parent_id = :parent_id LIMIT 1";
    if ($dbHandleInfo['type'] === 'pdo') {
        $pdo = $dbHandleInfo['handle'];
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':instance_id'=>$instanceId, ':id'=>$id, ':parent_id'=>$parent_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) $keyMaterial = $row['key_material'];
        $rowId = $row['id'] ?? null;
    } else { // mysqli
        $mysqli = $dbHandleInfo['handle'];
        // use prepared stmt
        $stmt = $mysqli->prepare("SELECT `id`,`key_material` FROM `launched_desks` WHERE instance_id = ? OR id = ? OR parent_id = ? LIMIT 1");
        $stmt->bind_param('sss', $instanceId, $id, $parent_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        if ($row) $keyMaterial = $row['key_material'];
        $rowId = $row['id'] ?? null;
        $stmt->close();
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>'db query failed','error'=>$e->getMessage()]);
    exit;
}

if (empty($keyMaterial)) {
    http_response_code(404);
    echo json_encode(['status'=>'error','message'=>'key_material not found for this instance/id/parent_id']);
    exit;
}

// 2) create EC2 client and request password data
try {
    $ec2 = new Ec2Client([
        'version'     => 'latest',
        'region'      => $region,
        'credentials' => [
            'key'    => $awsAccessKey,
            'secret' => $awsSecretKey,
        ],
        'http' => ['verify' => false] // optional, remove in production if not needed
    ]);

    $resp = $ec2->getPasswordData(['InstanceId' => $instanceId]);
    $pwdData = $resp->get('PasswordData') ?? '';
} catch (AwsException $e) {
    http_response_code(500);
    echo json_encode([
        'status'=>'error',
        'message'=>'AWS GetPasswordData failed',
        'aws_error' => $e->getAwsErrorMessage() ?? $e->getMessage()
    ]);
    exit;
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>'AWS call failed','error'=>$e->getMessage()]);
    exit;
}

if (empty($pwdData)) {
    http_response_code(409);
    echo json_encode(['status'=>'error','message'=>'password data is empty. Instance might not be ready or instance may not be Windows. Try again later.']);
    exit;
}

// 3) decrypt using key_material
$privateKey = openssl_pkey_get_private($keyMaterial);
if ($privateKey === false) {
    // try to add PEM wrappers if missing (best-effort)
    $wrapped = $keyMaterial;
    if (strpos($wrapped, 'BEGIN') === false) {
        $wrapped = "-----BEGIN RSA PRIVATE KEY-----\n" .
                   chunk_split(trim($wrapped), 64, "\n") .
                   "-----END RSA PRIVATE KEY-----\n";
        $privateKey = openssl_pkey_get_private($wrapped);
    }
}

if ($privateKey === false) {
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>'invalid private key (key_material). openssl could not parse it.']);
    exit;
}

$encrypted = base64_decode($pwdData);
$decrypted = '';
$ok = @openssl_private_decrypt($encrypted, $decrypted, $privateKey);
openssl_free_key($privateKey);

if (!$ok || $decrypted === '') {
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>'decryption failed. The key may not match the EC2 keypair or key format is unsupported.']);
    exit;
}

// 4) update DB password column for the matched row
$updateSuccess = false;
try {
    $updateSql = "UPDATE `launched_desks` SET `password` = :password WHERE id = :id OR instance_id = :instance_id LIMIT 1";
    if ($dbHandleInfo['type'] === 'pdo') {
        $pdo = $dbHandleInfo['handle'];
        $stmt = $pdo->prepare($updateSql);
        $updateSuccess = $stmt->execute([':password'=>$decrypted, ':id'=>$rowId ?? $id, ':instance_id'=>$instanceId]);
    } else {
        $mysqli = $dbHandleInfo['handle'];
        $stmt = $mysqli->prepare("UPDATE `launched_desks` SET `password` = ? WHERE id = ? OR instance_id = ? LIMIT 1");
        $idForUpdate = $rowId ?? $id;
        $stmt->bind_param('sss', $decrypted, $idForUpdate, $instanceId);
        $updateSuccess = $stmt->execute();
        $stmt->close();
    }
} catch (Exception $e) {
    // non-fatal: still return password but note DB update error
    echo json_encode([
        'status'=>'ok',
        'message'=>'password decrypted but DB update failed',
        'password'=>$decrypted,
        'db_error'=>$e->getMessage()
    ]);
    exit;
}

if (!$updateSuccess) {
    // still return password but indicate DB wasn't updated
    echo json_encode([
        'status'=>'ok',
        'message'=>'password decrypted but DB update did not affect any rows',
        'password'=>$decrypted
    ]);
    exit;
}

// success
echo json_encode([
    'message'=>'password retrieved Successfully',
    'password'=>$decrypted
]);
exit;
