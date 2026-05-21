<?php
// msg_sub.php — Subscribe ALL sub_users as recipients & stream live logs

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once __DIR__ . '/my_db.php';
require_once __DIR__ . '/sdk/TCloudAutoLoader.php';

use TencentCloud\Common\Credential;
use TencentCloud\Common\Exception\TencentCloudSDKException;
use TencentCloud\Monitor\V20180724\MonitorClient;
use TencentCloud\Monitor\V20180724\Models\CreateAlarmNoticeRequest;
use TencentCloud\Monitor\V20180724\Models\ModifyAlarmNoticeRequest;
use TencentCloud\Monitor\V20180724\Models\DescribeAlarmNoticesRequest;

// Helper to send SSE data
function sendLog($msg) {
    echo "data: {$msg}\n\n";
    @ob_flush();
    @flush();
}

// 1) AUTH & PARAM CHECK
if (!isset($_SESSION['user_id'], $_GET['keyid'], $_GET['userid'])) {
    header('Location: login.php');
    exit;
}
$userId   = (int) $_SESSION['user_id'];
$keyId    = (int) $_GET['keyid'];
$paramUid = (int) $_GET['userid'];
if ($userId !== $paramUid) {
    die('Access denied');
}

// 2) LOAD MASTER CREDENTIALS
$stmt = $mysqli->prepare('SELECT secret_id, secret_key, account_id FROM api_keys WHERE id=? AND by_user=?');
$stmt->bind_param('ii', $keyId, $userId);
$stmt->execute();
$stmt->bind_result($secretId, $secretKey, $accountId);
if (!$stmt->fetch()) {
    die('Invalid API key');
}
$stmt->close();

// 3) STREAM MODE (SSE)
if (isset($_GET['stream'])) {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    ignore_user_abort(true);
    set_time_limit(0);

    sendLog('Starting subscription process...');
    sendLog('Loaded credentials for account ' . $accountId);

    try {
        // INIT CLIENT
        $cred   = new Credential($secretId, $secretKey);
        $client = new MonitorClient($cred, 'ap-guangzhou');
        sendLog('MonitorClient initialized');

        // FETCH SUB-USERS
        $res = $mysqli->query(
            "SELECT email, phone FROM sub_users WHERE key_id={$keyId} AND (email IS NOT NULL OR phone IS NOT NULL)"
        );
        $emails = [];
        $phones = [];
        while ($row = $res->fetch_assoc()) {
            if (!empty($row['email'])) $emails[] = $row['email'];
            if (!empty($row['phone'])) $phones[] = $row['phone'];
        }
        sendLog('Found ' . count($emails) . ' emails, ' . count($phones) . ' phones');
        if (empty($emails) && empty($phones)) {
            sendLog('ERROR: no contacts');
            sendLog('DONE');
            exit;
        }

        // CHECK EXISTING NOTICE
        $descReq = new DescribeAlarmNoticesRequest();
        $descReq->setModule('monitor');
        $descReq->setPageNumber(1);
        $descReq->setPageSize(200);
        $descReq->setOrder('DESC');
        $descReq->setFilters([
            ['Name' => 'NoticeName', 'Values' => ["CertExpiryNotice_{$accountId}"]]
        ]);
        $descRes = $client->DescribeAlarmNotices($descReq);
        $noticeId = $descRes->NoticeSet[0]['NoticeId'] ?? null;
        sendLog($noticeId ? "Existing notice: $noticeId" : "No existing notice");

        // BUILD RECEIVERS
        $receivers = [];
        if ($emails) {
            $receivers[] = [
                'ReceiverType'   => 'EMAIL',
                'Name'           => 'AllEmails',
                'NoticeWay'      => ['EMAIL'],
                'ReceiverIds'    => $emails,
                'StartTime'      => '00:00',
                'EndTime'        => '23:59',
                'NeedSendNotice' => true,
            ];
        }
        if ($phones) {
            $receivers[] = [
                'ReceiverType'   => 'SMS',
                'Name'           => 'AllPhones',
                'NoticeWay'      => ['SMS'],
                'ReceiverIds'    => $phones,
                'StartTime'      => '00:00',
                'EndTime'        => '23:59',
                'NeedSendNotice' => true,
            ];
        }
        sendLog('Prepared receiver lists');

        // CREATE or MODIFY
        if ($noticeId) {
            $modReq = new ModifyAlarmNoticeRequest();
            $modReq->setModule('monitor');
            $modReq->setNoticeId($noticeId);
            $modReq->setName("CertExpiryNotice_{$accountId}");
            $modReq->setNoticeType('ALARM');
            $modReq->setNoticeLanguage('en-US');
            $modReq->setNoticeReceivers($receivers);
            $modReq->setWebCallbacks([]);
            $client->ModifyAlarmNotice($modReq);
            sendLog('Notice updated');
        } else {
            $createReq = new CreateAlarmNoticeRequest();
            $createReq->setModule('monitor');
            $createReq->setName("CertExpiryNotice_{$accountId}");
            $createReq->setNoticeType('ALARM');
            $createReq->setNoticeLanguage('en-US');
            $createReq->setNoticeReceivers($receivers);
            $createReq->setWebCallbacks([]);
            $resp = $client->CreateAlarmNotice($createReq);
            $noticeId = $resp->NoticeId;
            sendLog('Notice created: ' . $noticeId);
        }

        sendLog('DONE');
        exit;

    } catch (TencentCloudSDKException $e) {
        sendLog('SDK ERROR: ' . $e->getMessage());
        sendLog('DONE');
        exit;
    } catch (Exception $e) {
        sendLog('ERROR: ' . $e->getMessage());
        sendLog('DONE');
        exit;
    }
}

// 4) VIEW
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Live Subscribe Logs</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    #logs { background: #f8f9fa; padding: 10px; height: 300px; overflow: auto; white-space: pre-wrap; }
  </style>
</head>
<body class="p-4">
  <div class="container">
    <h1>Account <?= htmlspecialchars($accountId) ?> — Live Subscribe Logs</h1>
    <button id="startBtn" class="btn btn-success mt-3">Start Subscription</button>
    <div id="logs" class="mt-3"></div>
  </div>
  <script>
    document.getElementById('startBtn').addEventListener('click', function() {
      this.disabled = true;
      const logs = document.getElementById('logs');
      const es = new EventSource(location.pathname + '?keyid=<?= $keyId ?>&userid=<?= $userId ?>&stream=1');
      es.onmessage = e => {
        logs.textContent += e.data + '\n';
        logs.scrollTop = logs.scrollHeight;
        if (e.data === 'DONE') {
          es.close();
          this.disabled = false;
        }
      };
      es.onerror = () => {
        logs.textContent += '[Connection error]\n';
        es.close();
        this.disabled = false;
      };
    });
  </script>
</body>
</html>
