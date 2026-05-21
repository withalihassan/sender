<?php
// open_sender.php

// 1) Autoloader and common includes
require_once __DIR__ . '/sdk/TCloudAutoLoader.php';
include __DIR__ . '/navbar.php';
require_once __DIR__ . '/my_db.php';

use TencentCloud\Common\Credential;
use TencentCloud\Common\Exception\TencentCloudSDKException;
use TencentCloud\Ccc\V20200210\CccClient;
use TencentCloud\Ccc\V20200210\Models\CreateCCCInstanceRequest;

// 2) Fetch and validate key ID
$keyid = intval($_GET['keyyid'] ?? 0);
if ($keyid <= 0) {
    die('Invalid key ID');
}

// 3) Look up SecretId and SecretKey
$stmt = $mysqli->prepare("SELECT secret_id, secret_key FROM api_keys WHERE id = ?");
$stmt->bind_param('i', $keyid);
$stmt->execute();
$stmt->bind_result($secretId, $secretKey);
if (!$stmt->fetch()) {
    die('API key not found');
}
$stmt->close();

// 4) Collect your input variables
//    These could also come from a form POST—here we're hard-coding for demo.
$applicationName = 'My TCCC Application';         // <-- set your desired name
$serviceType     = 'STANDARD_VOICE';               // <-- e.g. STANDARD_VOICE
// (See Console → Contact Center → Add Application for other types)

// 5) Initialize the CCC client
try {
    $cred    = new Credential($secretId, $secretKey);
    $client  = new CccClient($cred, 'ap-guangzhou', [
        'profile' => [
            'httpProfile' => [
                'endpoint' => 'ccc.tencentcloudapi.com'
            ]
        ]
    ]);

    // 6) Build and send the CreateCCCInstance request
    $req = new CreateCCCInstanceRequest();
    $req->SdkAppName       = $applicationName;
    $req->SdkAppServiceType = $serviceType;

    $resp = $client->CreateCCCInstance($req);

    // 7) Read and display the returned SdkAppId
    echo "<h2>Application Created Successfully!</h2>";
    echo "<p><strong>TCCC SDKAppId:</strong> " . htmlspecialchars($resp->SdkAppId) . "</p>";

} catch (TencentCloudSDKException $e) {
    // Handle any errors from the SDK
    echo '<h2>Error creating application:</h2>';
    echo '<pre>' . htmlspecialchars($e->getMessage()) . '</pre>';
}
