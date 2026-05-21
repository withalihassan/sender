<?php
// cert_execute.php — Certificate Management, Fetch & Automation

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once __DIR__ . '/my_db.php';
require_once __DIR__ . '/sdk/TCloudAutoLoader.php';

use TencentCloud\Common\Credential;
use TencentCloud\Common\Exception\TencentCloudSDKException;
use TencentCloud\Ssl\V20191205\SslClient;
use TencentCloud\Ssl\V20191205\Models\ApplyCertificateRequest;
use TencentCloud\Ssl\V20191205\Models\CancelCertificateRequest;
use TencentCloud\Ssl\V20191205\Models\DescribeCertificatesRequest;

// 1) Ensure session + GET params
if (!isset($_SESSION['user_id'], $_GET['keyid'], $_GET['userid'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');
        echo json_encode(['status'=>'error','message'=>'Unauthorized']);
        exit;
    }
    header('Location: login.php');
    exit;
}

$currentUser = (int)$_SESSION['user_id'];
$paramUser   = (int)$_GET['userid'];
$keyId       = (int)$_GET['keyid'];
if ($currentUser !== $paramUser) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');
        echo json_encode(['status'=>'error','message'=>'Access denied']);
        exit;
    }
    die('Access denied.');
}

// 2) Load API creds
$stmt = $mysqli->prepare(
    'SELECT secret_id, secret_key, account_id 
       FROM api_keys 
      WHERE id=? AND by_user=?'
);
$stmt->bind_param('ii', $keyId, $currentUser);
$stmt->execute();
$stmt->bind_result($secretId, $secretKey, $accountId);
if (!$stmt->fetch()) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');
        echo json_encode(['status'=>'error','message'=>'Invalid API key']);
        exit;
    }
    die('Invalid API key.');
}
$stmt->close();

// === AJAX endpoint =========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_start();
    header('Content-Type: application/json; charset=utf-8');
    $logs = [];

    try {
        $cred   = new Credential($secretId, $secretKey);
        $client = new SslClient($cred, 'ap-guangzhou');
        $action = $_POST['action'] ?? '';

        switch ($action) {
            case 'apply':
                $domain = trim($_POST['domain'] ?? '');
                if (!$domain) throw new Exception('Domain is required.');
                $req             = new ApplyCertificateRequest();
                $req->DomainName = $domain;
                $req->DvAuthMethod = "DNS";
                $resp            = $client->ApplyCertificate($req);
                $cid             = $resp->CertificateId;
                $ins             = $mysqli->prepare(
                    'INSERT INTO certificates (key_id,domain,certificate_id,status,created_at)
                     VALUES (?,?,?,?,NOW())'
                );
                $status          = 'PENDING';
                $ins->bind_param('isss', $keyId, $domain, $cid, $status);
                $ins->execute();
                $ins->close();
                $logs[] = "Applied: {$domain} → {$cid}";
                break;

            case 'bulkApply':
                for ($i = 1; $i <= 40; $i++) {
                    $s    = bin2hex(random_bytes(4));
                    $dom  = "domain{$s}.com";
                    $r    = new ApplyCertificateRequest();
                    $r->DomainName  = $dom;
                    $r->DvAuthMethod = "DNS";
                    $r2   = $client->ApplyCertificate($r);
                    $cid  = $r2->CertificateId;
                    $ins2 = $mysqli->prepare(
                        'INSERT INTO certificates (key_id,domain,certificate_id,status,created_at)
                         VALUES (?,?,?,?,NOW())'
                    );
                    $st   = 'PENDING';
                    $ins2->bind_param('isss', $keyId, $dom, $cid, $st);
                    $ins2->execute();
                    $ins2->close();
                    $logs[] = "[{$i}] {$dom} → {$cid}";
                }
                break;

            case 'fetch':
                $rq   = new DescribeCertificatesRequest();
                $rq->Offset = 0;
                $rq->Limit  = 100;
                $res  = $client->DescribeCertificates($rq);
                foreach ($res->CertificateSet as $c) {
                    $did = $c->CertificateId;
                    $chk = $mysqli->prepare(
                        'SELECT 1 FROM certificates WHERE certificate_id=? AND key_id=?'
                    );
                    $chk->bind_param('si', $did, $keyId);
                    $chk->execute();
                    $chk->store_result();
                    if ($chk->num_rows === 0) {
                        $insF = $mysqli->prepare(
                            'INSERT INTO certificates (key_id,domain,certificate_id,status,created_at)
                             VALUES (?,?,?,?,NOW())'
                        );
                        $insF->bind_param('isss', $keyId, $c->Domain, $did, $c->Status);
                        $insF->execute();
                        $insF->close();
                        $logs[] = "Fetched: {$c->Domain} → {$did} ({$c->Status})";
                    }
                    $chk->close();
                }
                break;

            case 'deleteCert':
                $cid = trim($_POST['certId'] ?? '');
                if (!$cid) throw new Exception('Certificate ID is required.');
                $delReq = new CancelCertificateRequest();
                $delReq->CertificateId = $cid;
                $client->CancelCertificate($delReq);
                $del = $mysqli->prepare(
                    'DELETE FROM certificates WHERE certificate_id=? AND key_id=?'
                );
                $del->bind_param('si', $cid, $keyId);
                $del->execute();
                $del->close();
                $logs[] = "Deleted: {$cid}";
                break;

            case 'bulkDelete':
                $q = $mysqli->prepare(
                    'SELECT certificate_id FROM certificates WHERE key_id=?'
                );
                $q->bind_param('i', $keyId);
                $q->execute();
                $q->bind_result($cid);
                while ($q->fetch()) {
                    $rd = new CancelCertificateRequest();
                    $rd->CertificateId = $cid;
                    $client->CancelCertificate($rd);
                    $logs[] = "Deleted: {$cid}";
                }
                $q->close();
                $dq = $mysqli->prepare(
                    'DELETE FROM certificates WHERE key_id=?'
                );
                $dq->bind_param('i', $keyId);
                $dq->execute();
                break;

            default:
                throw new Exception("Unknown action: {$action}");
        }

        ob_clean();
        echo json_encode(['status'=>'success','logs'=>$logs]);
    } catch (TencentCloudSDKException | Exception $e) {
        ob_clean();
        echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
    }
    exit;
}

// === HTML rendering ========================================================
$stmt2 = $mysqli->prepare(
    'SELECT domain,certificate_id,status,created_at 
       FROM certificates 
      WHERE key_id=? 
      ORDER BY created_at DESC'
);
$stmt2->bind_param('i', $keyId);
$stmt2->execute();
$result = $stmt2->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Certificates (Account <?= htmlspecialchars($accountId) ?>)</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="p-4">
  <div class="container">
    <h1>Certificates (Account <?= htmlspecialchars($accountId) ?>)</h1>
    <div class="mb-3">
      <input type="text" id="domainInput" class="form-control w-50 d-inline-block me-2" placeholder="Enter domain">
      <button id="applyBtn"   class="btn btn-success me-2">Apply Single</button>
      <button id="bulkApply"  class="btn btn-primary me-2">Bulk Apply (40)</button>
      <button id="fetchBtn"   class="btn btn-info me-2">Fetch Existing</button>
      <button id="bulkDelete" class="btn btn-danger">Bulk Delete All</button>
    </div>
    <h5>Live Logs</h5>
    <div id="logs" class="border p-2 mb-4" style="height:200px;overflow:auto;background:#f8f9fa;"></div>
    <table class="table table-striped">
      <thead>
        <tr>
          <th>Domain</th><th>Cert ID</th><th>Status</th><th>Created At</th><th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php while ($row = $result->fetch_assoc()): ?>
        <tr>
          <td><?= htmlspecialchars($row['domain']) ?></td>
          <td><?= htmlspecialchars($row['certificate_id']) ?></td>
          <td><?= htmlspecialchars($row['status']) ?></td>
          <td><?= date('d M Y h:i A', strtotime($row['created_at'])) ?></td>
          <td>
            <button class="btn btn-sm btn-danger deleteCert" data-id="<?= htmlspecialchars($row['certificate_id']) ?>">
              Delete
            </button>
          </td>
        </tr>
        <?php endwhile; ?>
      </tbody>
    </table>
  </div>
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script>
  $(function(){
    // Use the full current URL (including keyid & userid)
    const endpoint = window.location.href;

    function appendLog(msg, isError=false) {
      const el = $('<div>').text(msg);
      if (isError) el.addClass('text-danger');
      $('#logs').append(el).scrollTop($('#logs')[0].scrollHeight);
    }

    function call(action, data={}) {
      appendLog('Sending: ' + action + '...');
      return $.ajax({
        type: 'POST',
        url: endpoint,
        data: Object.assign({action}, data),
        dataType: 'json'
      });
    }

    function handle(deferred) {
      deferred
        .done(res => {
          if (res.status === 'success') {
            res.logs.forEach(l => appendLog(l));
            // reload table so changes show up
            setTimeout(() => location.reload(), 800);
          } else {
            appendLog('Error: ' + res.message, true);
          }
        })
        .fail((_, stat) => appendLog('AJAX error: ' + stat, true));
    }

    $('#applyBtn').click(() => {
      const d = $('#domainInput').val().trim();
      if (!d) return alert('Please enter a domain.');
      handle(call('apply', {domain: d}));
    });
    $('#bulkApply').click(() => {
      if (!confirm('Apply 40 random domains?')) return;
      handle(call('bulkApply'));
    });
    $('#fetchBtn').click(() => {
      handle(call('fetch'));
    });
    $('#bulkDelete').click(() => {
      if (!confirm('Delete ALL certificates?')) return;
      handle(call('bulkDelete'));
    });
    $(document).on('click', '.deleteCert', function(){
      const id = $(this).data('id');
      if (!confirm('Delete certificate ' + id + '?')) return;
      handle(call('deleteCert', {certId: id}));
    });
  });
  </script>
</body>
</html>
