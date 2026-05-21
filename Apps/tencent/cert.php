<?php
// index.php - Tencent Cloud API Key Manager with STS identity lookup

// 1. Display all errors for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Include dependencies
require_once __DIR__ . '/my_db.php';
require_once __DIR__ . '/sdk/TCloudAutoLoader.php';
include __DIR__ . '/navbar.php';

// Enable mysqli exceptions for easier error handling
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

use TencentCloud\Common\Credential;
use TencentCloud\Common\Exception\TencentCloudSDKException;
use TencentCloud\Sts\V20180813\StsClient;
use TencentCloud\Sts\V20180813\Models\GetCallerIdentityRequest;

$message = '';
$messageClass = 'info';
$current_user = $_SESSION['user_id'];

// Handle deletion of API key
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['deleteKey'])) {
    try {
        $deleteId = intval($_POST['deleteKey']);
        $stmt = $mysqli->prepare(
            'DELETE FROM api_keys WHERE id = ? AND by_user = ?'
        );
        $stmt->bind_param('ii', $deleteId, $current_user);
        $stmt->execute();
        $stmt->close();
        $message = 'API key deleted successfully.';
        $messageClass = 'success';
    } catch (Exception $e) {
        $message = 'Error deleting API key: ' . htmlspecialchars($e->getMessage());
        $messageClass = 'danger';
    }
}

// Handle form submission for adding API keys
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['secretId']) && isset($_POST['secretKey'])) {
    $secretId  = trim($_POST['secretId']);
    $secretKey = trim($_POST['secretKey']);

    if ($secretId === '' || $secretKey === '') {
        $message = 'Both Secret ID and Secret Key are required.';
        $messageClass = 'danger';
    } else {
        try {
            // Initialize Tencent Cloud STS client to get AccountId
            $cred = new Credential($secretId, $secretKey);
            $stsClient = new StsClient($cred, 'ap-guangzhou');
            $callerReq = new GetCallerIdentityRequest();
            $callerResp = $stsClient->GetCallerIdentity($callerReq);
            $accountId = $callerResp->AccountId;

            // Insert into database using prepared statement
            $stmt = $mysqli->prepare(
                'INSERT INTO api_keys (by_user, account_id, secret_id, secret_key) VALUES (?, ?, ?, ?)'
            );
            $stmt->bind_param('isss', $current_user, $accountId, $secretId, $secretKey);
            $stmt->execute();
            $stmt->close();

            $message = 'API keys saved successfully for Account ID ' . htmlspecialchars($accountId) . '.';
            $messageClass = 'success';
        } catch (TencentCloudSDKException $e) {
            $message = 'Tencent SDK Error: ' . htmlspecialchars($e->getMessage());
            $messageClass = 'danger';
        } catch (mysqli_sql_exception $e) {
            if ($e->getCode() === 1062) {
                $message = 'Duplicate Secret ID found. Please use a different Secret ID.';
                $messageClass = 'warning';
            } else {
                $message = 'Database Error: ' . htmlspecialchars($e->getMessage());
                $messageClass = 'danger';
            }
        } catch (Exception $e) {
            $message = 'Error: ' . htmlspecialchars($e->getMessage());
            $messageClass = 'danger';
        }
    }
}

// Fetch all API keys for current user along with sub-user count
$query = 
    "SELECT ak.id, ak.account_id, ak.secret_id, ak.secret_key, ak.created_at, " .
    "(SELECT COUNT(*) FROM sub_users su WHERE su.key_id = ak.id) AS sub_count " .
    "FROM api_keys ak " .
    "WHERE ak.by_user = '" . $mysqli->real_escape_string($current_user) . "' " .
    "ORDER BY ak.created_at DESC";

$result = $mysqli->query($query); $mysqli->query($query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>API Key Manager</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- DataTables CSS -->
    <link href="https://cdn.datatables.net/1.13.4/css/jquery.dataTables.min.css" rel="stylesheet">
</head>
<body class="p-4">
<div class="container">
    <h1 class="mb-4">Tencent Cloud API Key Manager User: <?php echo htmlspecialchars($_SESSION['user_id']); ?></h1>
    <?php if ($message): ?>
        <div class="alert alert-<?= $messageClass ?>"><?= $message ?></div>
    <?php endif; ?>

    <div class="card mb-5">
        <div class="card-header">Add API Keys</div>
        <div class="card-body">
            <form method="post" class="row g-3">
                <div class="col-md-6">
                    <label for="secretId" class="form-label">Secret ID</label>
                    <input type="text" name="secretId" id="secretId" class="form-control" required>
                </div>
                <div class="col-md-6">
                    <label for="secretKey" class="form-label">Secret Key</label>
                    <input type="text" name="secretKey" id="secretKey" class="form-control" required>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary">Save Keys</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header">Stored API Keys</div>
        <div class="card-body">
            <table id="keysTable" class="table table-striped" style="width:100%">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Account ID</th>
                        <th>Secret ID</th>
                        <th>Secret Key</th>
                        <th>Sub-Users</th>
                        <th>Created At</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($row = $result->fetch_assoc()): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['id']) ?></td>
                        <td><?= htmlspecialchars($row['account_id']) ?></td>
                        <td><?= htmlspecialchars($row['secret_id']) ?></td>
                        <td><?= htmlspecialchars($row['secret_key']) ?></td>
                        <td><?= htmlspecialchars($row['sub_count']) ?></td>
                        <td><?= date('j F g:i a', strtotime($row['created_at'])) ?></td>
                        <td>
                            <a href="./msg_sub.php?keyid=<?= htmlspecialchars($row['id']) ?>&userid=<?= $current_user ?>&acid=<?= htmlspecialchars($row['account_id']) ?>" target="_blank" class="btn btn-sm btn-info">Crack sub</a>
                            <form method="post" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this API key?');">
                                <input type="hidden" name="deleteKey" value="<?= htmlspecialchars($row['id']) ?>">
                                <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- jQuery -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- DataTables JS -->
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script>
    $(document).ready(function() {
        $('#keysTable').DataTable({ paging: true, pageLength: 10, lengthChange: false, searching: false });
    });
</script>
</body>
</html>
