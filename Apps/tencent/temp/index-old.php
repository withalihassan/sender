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
use TencentCloud\Ssl\V20191205\Models\DescribeCertificatesRequest;
use TencentCloud\Ssl\V20191205\Models\DeleteCertificateRequest;

// 1) Validate session and parameters
if (!isset($_SESSION['user_id'], $_GET['keyid'], $_GET['userid'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
        exit;
    }
    header('Location: login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$keyId  = (int) $_GET['keyid'];
$paramUser = (int) $_GET['userid'];
if ($userId !== $paramUser) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Access denied']);
        exit;
    }
    die('Access denied');
}

// 2) Load credentials from database
$stmt = $mysqli->prepare(
    'SELECT secret_id, secret_key, account_id FROM api_keys WHERE id = ? AND by_user = ?'
);
$stmt->bind_param('ii', $keyId, $userId);
$stmt->execute();
$stmt->bind_result($secretId, $secretKey, $accountId);
if (!$stmt->fetch()) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Invalid API key']);
        exit;
    }
    die('Invalid API key');
}
$stmt->close();

// 3) Handle AJAX actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ini_set('display_errors', 0);
    header('Content-Type: application/json; charset=utf-8');
    $logs = [];
    try {
        $cred = new Credential($secretId, $secretKey);
        $client = new SslClient($cred, 'ap-guangzhou');
        $action = $_POST['action'] ?? '';

        switch ($action) {
            case 'apply':
                $domain = trim($_POST['domain'] ?? '');
                if (!$domain) throw new Exception('Domain is required');
                $req = new ApplyCertificateRequest();
                $req->DomainName = $domain;
                $req->DvAuthMethod = 'DNS';
                $resp = $client->ApplyCertificate($req);
                $cid = $resp->CertificateId;

                $ins = $mysqli->prepare(
                    'INSERT INTO certificates (key_id, domain, certificate_id, status, created_at) VALUES (?, ?, ?, ?, NOW())'
                );
                $status = 'PENDING';
                $ins->bind_param('isss', $keyId, $domain, $cid, $status);
                $ins->execute();
                $ins->close();

                $logs[] = "Applied: {$domain} → {$cid}";
                break;

            case 'bulkApply':
                for ($i = 1; $i <= 40; $i++) {
                    $random = bin2hex(random_bytes(4));
                    $dom = "domain{$random}.com";
                    $req = new ApplyCertificateRequest();
                    $req->DomainName = $dom;
                    $req->DvAuthMethod = 'DNS';
                    $resp = $client->ApplyCertificate($req);
                    $cid = $resp->CertificateId;

                    $ins = $mysqli->prepare(
                        'INSERT INTO certificates (key_id, domain, certificate_id, status, created_at) VALUES (?, ?, ?, ?, NOW())'
                    );
                    $status = 'PENDING';
                    $ins->bind_param('isss', $keyId, $dom, $cid, $status);
                    $ins->execute();
                    $ins->close();

                    $logs[] = "[{$i}] {$dom} → {$cid}";
                }
                break;

            case 'fetch':
                $req = new DescribeCertificatesRequest();
                $req->Offset = 0;
                $req->Limit = 100;
                $resp = $client->DescribeCertificates($req);
                foreach ($resp->CertificateSet as $cert) {
                    $cid = $cert->CertificateId;
                    $chk = $mysqli->prepare(
                        'SELECT 1 FROM certificates WHERE certificate_id = ? AND key_id = ?'
                    );
                    $chk->bind_param('si', $cid, $keyId);
                    $chk->execute();
                    $chk->store_result();
                    if ($chk->num_rows === 0) {
                        $ins = $mysqli->prepare(
                            'INSERT INTO certificates (key_id, domain, certificate_id, status, created_at) VALUES (?, ?, ?, ?, NOW())'
                        );
                        $ins->bind_param('isss', $keyId, $cert->Domain, $cid, $cert->Status);
                        $ins->execute();
                        $ins->close();
                        $logs[] = "Fetched: {$cert->Domain} → {$cid} ({$cert->Status})";
                    }
                    $chk->close();
                }
                break;

            case 'deleteCert':
                $cid = trim($_POST['certId'] ?? '');
                if (!$cid) throw new Exception('Certificate ID is required');

                $delReq = new DeleteCertificateRequest();
                $delReq->CertificateId = $cid;
                $client->DeleteCertificate($delReq);

                $del = $mysqli->prepare(
                    'DELETE FROM certificates WHERE certificate_id = ? AND key_id = ?'
                );
                $del->bind_param('si', $cid, $keyId);
                $del->execute();
                $del->close();

                $logs[] = "Deleted: {$cid}";
                break;

            case 'bulkDelete':
                $query = $mysqli->prepare(
                    'SELECT certificate_id FROM certificates WHERE key_id = ?'
                );
                $query->bind_param('i', $keyId);
                $query->execute();
                $query->bind_result($cid);
                while ($query->fetch()) {
                    $delReq = new DeleteCertificateRequest();
                    $delReq->CertificateId = $cid;
                    $client->DeleteCertificate($delReq);
                    $logs[] = "Deleted: {$cid}";
                }
                $query->close();

                $delAll = $mysqli->prepare(
                    'DELETE FROM certificates WHERE key_id = ?'
                );
                $delAll->bind_param('i', $keyId);
                $delAll->execute();
                $delAll->close();
                break;

            default:
                throw new Exception("Unknown action: {$action}");
        }

        echo json_encode(['status' => 'success', 'logs' => $logs]);
    } catch (TencentCloudSDKException | Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Render HTML page
$stmt = $mysqli->prepare(
    'SELECT domain, certificate_id, status, created_at FROM certificates WHERE key_id = ? ORDER BY created_at DESC'
);
$stmt->bind_param('i', $keyId);
$stmt->execute();
$result = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Certificates (Account <?= htmlspecialchars($accountId) ?>)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="p-4">
    <div class="container">
        <h1>Certificates (Account <?= htmlspecialchars($accountId) ?>)</h1>
        <div class="mb-3">
            <input type="text" id="domainInput" class="form-control w-50 d-inline-block me-2" placeholder="Enter domain">
            <button type="button" id="applyBtn" class="btn btn-success me-2">Apply Single</button>
            <button type="button" id="bulkApply" class="btn btn-primary me-2">Bulk Apply (40)</button>
            <button type="button" id="fetchBtn" class="btn btn-info me-2">Fetch Existing</button>
            <button type="button" id="bulkDelete" class="btn btn-danger">Bulk Delete All</button>
        </div>
        <h5>Live Logs</h5>
        <div id="logs" class="border p-2 mb-4" style="height:200px;overflow:auto;background:#f8f9fa;"></div>
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>Domain</th>
                    <th>Cert ID</th>
                    <th>Status</th>
                    <th>Created At</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($row = $result->fetch_assoc()): ?>
                <tr>
                    <td><?= htmlspecialchars($row['domain']) ?></td>
                    <td><?= htmlspecialchars($row['certificate_id']) ?></td>
                    <td><?= htmlspecialchars($row['status']) ?></td>
                    <td><?= date('d M Y h:i A', strtotime($row['created_at'])) ?></td>
                    <td><button type="button" class="btn btn-sm btn-danger deleteCert" data-id="<?= htmlspecialchars($row['certificate_id']) ?>">Delete</button></td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
    $(function() {
        const endpoint = window.location.origin + window.location.pathname + window.location.search;
        function appendLog(msg, isError = false) {
            const el = $('<div>').text(msg);
            if (isError) el.addClass('text-danger');
            $('#logs').append(el).scrollTop($('#logs')[0].scrollHeight);
        }
        function call(action, data = {}) {
            appendLog('Sending: ' + action + '...');
            return $.ajax({ type: 'POST', url: endpoint, data: { action, ...data }, dataType: 'json' });
        }
        function handle(deferred) {
            deferred.done(res => {
                if (res.status === 'success') {
                    res.logs.forEach(l => appendLog(l));
                    setTimeout(() => location.reload(), 800);
                } else {
                    appendLog('Error: ' + res.message, true);
                }
            }).fail((_, stat) => appendLog('AJAX error: ' + stat, true));
        }
        $('#applyBtn').click(() => {
            const d = $('#domainInput').val().trim(); if (!d) return alert('Please enter a domain.');
            handle(call('apply', { domain: d }));
        });
        $('#bulkApply').click(() => { if (confirm('Apply 40 random domains?')) handle(call('bulkApply')); });
        $('#fetchBtn').click(() => handle(call('fetch')));
        $('#bulkDelete').click(() => { if (confirm('Delete ALL certificates?')) handle(call('bulkDelete')); });
        $(document).on('click', '.deleteCert', function() {
            const id = $(this).data('id'); if (confirm('Delete certificate ' + id + '?')) handle(call('deleteCert', { certId: id }));
        });
    });
    </script>
</body>
</html>
