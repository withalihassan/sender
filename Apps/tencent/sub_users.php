<?php
// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Include navigation
include __DIR__ . "/navbar.php";

// Include database connection (expects $mysqli)
require_once __DIR__ . '/my_db.php';

// Include Tencent Cloud PHP SDK auto-loader
require_once __DIR__ . '/sdk/TCloudAutoLoader.php';

use TencentCloud\Common\Credential;
use TencentCloud\Common\Exception\TencentCloudSDKException;
use TencentCloud\Cam\V20190116\CamClient;
use TencentCloud\Cam\V20190116\Models\AddUserRequest;
use TencentCloud\Cam\V20190116\Models\AttachUserPolicyRequest;
use TencentCloud\Cam\V20190116\Models\ListUsersRequest;
use TencentCloud\Cam\V20190116\Models\DeleteUserRequest;
use TencentCloud\Cam\V20190116\Models\UpdateUserRequest;
use TencentCloud\Cam\V20190116\Models\ListPoliciesRequest;

// Helper: generate random password with at least one uppercase, lowercase, digit, special
function generateRandomPassword($length = 12)
{
    $upper = chr(rand(65, 90));
    $lower = chr(rand(97, 122));
    $digit = chr(rand(48, 57));
    $specials = '!@#$%^&*()';
    $special = $specials[rand(0, strlen($specials) - 1)];
    $remaining = '';
    $all = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789' . $specials;
    for ($i = 4; $i < $length; $i++) {
        $remaining .= $all[rand(0, strlen($all) - 1)];
    }
    $password = str_shuffle($upper . $lower . $digit . $special . $remaining);
    return $password;
}

$keyid = intval($_GET['keyid'] ?? 0);
if ($keyid <= 0) die('Invalid API Key ID.');
$parent_ac_id = $_GET['acid'] ?? '';

// Load Tencent credentials
$stmt = $mysqli->prepare("SELECT secret_id, secret_key FROM api_keys WHERE id = ?");
$stmt->bind_param('i', $keyid);
$stmt->execute();
$stmt->bind_result($secretId, $secretKey);
if (!$stmt->fetch()) die('API key not found.');
$stmt->close();

// Init Tencent client
$cred = new Credential($secretId, $secretKey);
$client = new CamClient($cred, 'ap-guangzhou');

$feedback = [];

// Handle POST actions including AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    header('Content-Type: ' . (isset($_POST['ajax']) ? 'application/json' : 'text/html'));
    try {
        switch ($_POST['action']) {
            case 'fetch_users':
                $req = new ListUsersRequest();
                $resp = $client->ListUsers($req);
                foreach ($resp->Data ?? [] as $user) {
                    $uin = $user->Uin;
                    $chk = $mysqli->prepare("SELECT 1 FROM sub_users WHERE key_id=? AND uin=? LIMIT 1");
                    $chk->bind_param('ii', $keyid, $uin);
                    $chk->execute();
                    $chk->store_result();
                    if ($chk->num_rows) {
                        $chk->close();
                        continue;
                    }
                    $chk->close();

                    $phone = '+' . $user->CountryCode . $user->PhoneNum;
                    $link = "https://www.tencentcloud.com/login/subAccount/{$parent_ac_id}?type=subAccount&username=" . urlencode($user->Name);
                    $ins = $mysqli->prepare(
                        "INSERT INTO sub_users (key_id, uin, name, email, phone, login_link, console_password, user_type, created_at)
                         VALUES (?, ?, ?, ?, ?, ?, '', 'sub_user', NOW())"
                    );
                    $ins->bind_param('iissss', $keyid, $uin, $user->Name, $user->Email, $phone, $link);
                    $ins->execute();
                    $ins->close();
                }
                $feedback[] = 'Fetched users.';
                break;

            case 'create_user':
                $username = trim($_POST['username']);
                $phoneRaw = trim($_POST['phone']);
                $email = trim($_POST['email']);
                if (!$username || !$phoneRaw || !$email) throw new Exception('All fields required');
                if (!preg_match('/^(\d{1,3})(\d{4,14})$/', $phoneRaw, $m)) throw new Exception('Invalid phone');
                [, $cc, $pn] = $m;
                $password = generateRandomPassword();
                // Create in Tencent
                $req = new AddUserRequest();
                $req->Name = $username;
                $req->PhoneNum = $pn;
                $req->Email = $email;
                $req->CountryCode = $cc;
                $req->ConsoleLogin = 1;
                $req->Password = $password;
                $req->NeedResetPassword = 0;
                $resp = $client->AddUser($req);
                $uin = $resp->Uin;
                // Insert DB
                $phone = '+' . $cc . $pn;
                $link = "https://www.tencentcloud.com/login/subAccount/{$parent_ac_id}?type=subAccount&username=" . urlencode($username);
                $ins = $mysqli->prepare(
                    "INSERT INTO sub_users (key_id, uin, name, email, phone, login_link, console_password, user_type, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 'sub_user', NOW())"
                );
                $ins->bind_param('iisssss', $keyid, $uin, $username, $email, $phone, $link, $password);
                $ins->execute();
                $ins->close();
                $feedback[] = "Created $username";
                break;

            case 'auto_create':
                $base = trim($_POST['base_name']);
                $count = max(0, intval($_POST['count']));
                $email = trim($_POST['auto_email']);
                $lines = preg_split('/\r?\n/', trim($_POST['auto_numbers']), -1, PREG_SPLIT_NO_EMPTY);
                $phones = array_filter($lines, fn($l) => preg_match('/^\d+$/', $l));
                if (!$base || $count < 1 || !$email || empty($phones)) throw new Exception('Missing auto-create data');
                $num = count($phones);
                for ($i = 1; $i <= $count; $i++) {
                    $name = $base . $i;
                    preg_match('/^(\d{1,3})(\d{4,14})$/', $phones[($i - 1) % $num], $m);
                    [, $cc, $pn] = $m;
                    $password = generateRandomPassword();
                    $req = new AddUserRequest();
                    $req->Name = $name;
                    $req->PhoneNum = $pn;
                    $req->Email = $email;
                    $req->CountryCode = $cc;
                    $req->ConsoleLogin = 1;
                    $req->Password = $password;
                    $req->NeedResetPassword = 0;
                    $resp = $client->AddUser($req);
                    $uin = $resp->Uin;
                    $phone = '+' . $cc . $pn;
                    $link = "https://www.tencentcloud.com/login/subAccount/{$parent_ac_id}?type=subAccount&username=" . urlencode($name);
                    $ins = $mysqli->prepare(
                        "INSERT INTO sub_users (key_id, uin, name, email, phone, login_link, console_password, user_type, created_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, 'sub_user', NOW())"
                    );
                    $ins->bind_param('iisssss', $keyid, $uin, $name, $email, $phone, $link, $password);
                    $ins->execute();
                    $ins->close();
                    sleep(2);
                }
                $feedback[] = "Auto-created $count";
                break;

            case 'update_numbers':
                $lines = preg_split('/\r?\n/', trim($_POST['numbers']), -1, PREG_SPLIT_NO_EMPTY);
                $nums = array_filter($lines, fn($l) => preg_match('/^\d+$/', $l));
                if (empty($nums)) throw new Exception('No phones');
                $res = $mysqli->query("SELECT id, uin, name FROM sub_users WHERE key_id=$keyid ORDER BY created_at ASC");
                $i = 0;
                $n = count($nums);
                while ($r = $res->fetch_assoc()) {
                    preg_match('/^(\d{1,3})(\d{4,14})$/', $nums[$i % $n], $m);
                    [, $cc, $pn] = $m;
                    $phone = '+' . $cc . $pn;
                    try {
                        $uReq = new UpdateUserRequest();
                        $uReq->SubUin = intval($r['uin']);
                        $uReq->Name = $r['name'];
                        $uReq->PhoneNum = $pn;
                        $uReq->CountryCode = $cc;
                        $client->UpdateUser($uReq);
                    } catch (TencentCloudSDKException $e) {
                        $feedback[] = "Error UIN {$r['uin']}: " . $e->getMessage();
                    }
                    $upd = $mysqli->prepare("UPDATE sub_users SET phone=? WHERE id=?");
                    $upd->bind_param('si', $phone, $r['id']);
                    $upd->execute();
                    $upd->close();
                    $i++;
                    sleep(2);
                }
                $feedback[] = "Updated $i";
                break;

            case 'update_phone':
                // AJAX single phone update
                $id = intval($_POST['id']);
                $raw = trim($_POST['phone']);
                if (!preg_match('/^(\d{1,3})(\d{4,14})$/', $raw, $m)) throw new Exception('Invalid phone');
                [$_, $cc, $pn] = $m;
                // fetch user
                $row = $mysqli->query("SELECT uin, name FROM sub_users WHERE id=$id AND key_id=$keyid")->fetch_assoc();
                if (!$row) throw new Exception('User not found');
                // update cloud
                $uReq = new UpdateUserRequest();
                $uReq->SubUin = intval($row['uin']);
                $uReq->Name = $row['name'];
                $uReq->PhoneNum = $pn;
                $uReq->CountryCode = $cc;
                $client->UpdateUser($uReq);
                // update DB
                $phone = '+' . $cc . $pn;
                $mysqli->query("UPDATE sub_users SET phone='" . $mysqli->real_escape_string($phone) . "' WHERE id=$id");
                echo json_encode(['success' => true, 'phone' => $phone]);
                exit;

            case 'update_email':
                // AJAX single email update
                $id   = intval($_POST['id']);
                $raw  = trim($_POST['email']);
                if (!filter_var($raw, FILTER_VALIDATE_EMAIL)) {
                    throw new Exception('Invalid email format');
                }
                // ensure it belongs to this key
                $stmt = $mysqli->prepare("UPDATE sub_users SET email=? WHERE id=? AND key_id=?");
                $stmt->bind_param('sii', $raw, $id, $keyid);
                $stmt->execute();
                if ($stmt->affected_rows === 0) {
                    throw new Exception('Update failed');
                }
                echo json_encode(['success' => true, 'email' => $raw]);
                exit;

            case 'attach_policy':
                $uin = intval($_POST['uin']);
                $pName = 'AdministratorAccess';
                $lReq = new ListPoliciesRequest();
                $lReq->Rp = 200;
                $lReq->Page = 1;
                $lReq->Keyword = $pName;
                $lReq->Scope = 'All';
                $lResp = $client->ListPolicies($lReq);
                $pid = null;
                foreach ($lResp->List as $p) if ($p->PolicyName === $pName) $pid = $p->PolicyId;
                if (!$pid) throw new Exception('Policy not found');
                $aReq = new AttachUserPolicyRequest();
                $aReq->PolicyId = $pid;
                $aReq->AttachUin = $uin;
                $aReq->Detach = false;
                $client->AttachUserPolicy($aReq);
                $feedback[] = "Attached to $uin";
                break;

            case 'delete_user':
                $dbId = intval($_POST['db_id']);
                $row = $mysqli->query("SELECT uin FROM sub_users WHERE id=$dbId AND key_id=$keyid")->fetch_assoc();
                if ($row) {
                    $dReq = new DeleteUserRequest();
                    $dReq->SubUin = intval($row['uin']);
                    $client->DeleteUser($dReq);
                    $mysqli->query("DELETE FROM sub_users WHERE id=$dbId AND key_id=$keyid");
                    $feedback[] = "Deleted {$row['uin']}";
                }
                break;

            default:
                throw new Exception('Unknown');
        }
        if (!isset($_POST['ajax'])) {
            // reload page
            echo '<script>window.location.href=window.location.href;</script>';
        }
    } catch (Exception $e) {
        if (isset($_POST['ajax'])) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
        $feedback[] = 'Error: ' . htmlspecialchars($e->getMessage());
    }
}

// Fetch for display
$result = $mysqli->query(
    "SELECT id, uin, name, email, phone, login_link, console_password FROM sub_users WHERE key_id=$keyid ORDER BY created_at DESC"
);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>User Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.4/css/jquery.dataTables.min.css" rel="stylesheet">
</head>

<body class="p-4">
    <div class="container">
        <h1 class="mb-4">Manage Users for Key ID <?php echo htmlspecialchars($keyid); ?></h1>
        <?php foreach ($feedback as $msg): ?>
            <div class="alert alert-info"><?php echo $msg; ?></div>
        <?php endforeach; ?>

        <div class="mb-4">
            <form method="POST" class="d-inline">
                <input type="hidden" name="action" value="fetch_users">
                <button class="btn btn-secondary">Fetch From Tencent</button>
            </form>
        </div>

        <div class="card mb-4">
            <div class="card-header">Create / Update / Auto-Create</div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <form method="POST" class="row g-3">
                            <input type="hidden" name="action" value="create_user">
                            <div class="col-12"><label>Username</label><input name="username" class="form-control" required></div>
                            <div class="col-12"><label>Phone</label>
                                <div class="input-group"><span class="input-group-text">+</span><input name="phone" class="form-control" required></div>
                            </div>
                            <div class="col-12"><label>Email</label><input type="email" name="email" class="form-control" required></div>
                            <div class="col-12 text-end"><button class="btn btn-primary">Add User</button></div>
                        </form>
                    </div>
                    <div class="col-md-4">
                        <form method="POST" onsubmit="this.querySelector('button').innerText='Updating…';">
                            <input type="hidden" name="action" value="update_numbers">
                            <label>Batch Phones</label><textarea name="numbers" class="form-control mb-2" rows="5"></textarea>
                            <div class="text-end"><button class="btn btn-warning">Update Phones</button></div>
                        </form>
                    </div>
                    <div class="col-md-4">
                        <form method="POST" onsubmit="this.querySelector('button').innerText='Creating…';">
                            <input type="hidden" name="action" value="auto_create">
                            <div class="mb-2"><label>Base Name</label><input name="base_name" class="form-control" placeholder="Ava" required></div>
                            <div class="mb-2"><label>Count</label><input type="number" name="count" class="form-control" min="1" required></div>
                            <div class="mb-2"><label>Email</label><input type="email" name="auto_email" class="form-control" required></div>
                            <div class="mb-2"><label>Phones (one per line)</label><textarea name="auto_numbers" class="form-control" rows="4"></textarea></div>
                            <div class="text-end"><button class="btn btn-success">Auto Create</button></div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Stored Users</div>
            <div class="card-body">
                <table id="subTable" class="table table-striped display" style="width:100%">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>UIN</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Login</th>
                            <th>Password</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody><?php while ($r = $result->fetch_assoc()): ?>
                            <tr>
                                <td><?= htmlspecialchars($r['id']) ?></td>
                                <td><?= htmlspecialchars($r['uin']) ?></td>
                                <td><?= htmlspecialchars($r['name']) ?></td>
                                <td><input type="email" class="form-control editable-email" data-id="<?= htmlspecialchars($r['id']) ?>" value="<?= htmlspecialchars($r['email']) ?>"></td>
                                <td><input type="text" class="form-control editable-phone" data-id="<?= htmlspecialchars($r['id']) ?>" value="<?= htmlspecialchars(preg_replace('/^\+/', '', $r['phone'])) ?>"></td>
                                <td><a href="<?= htmlspecialchars($r['login_link']) ?>" target="_blank">Login</a></td>
                                <td><?= htmlspecialchars($r['console_password']) ?></td>
                                <td>
                                    <form method="POST" class="d-inline"><input type="hidden" name="action" value="attach_policy"><input type="hidden" name="uin" value="<?= htmlspecialchars($r['uin']) ?>"><button class="btn btn-sm btn-outline-primary">Attach</button></form>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Delete?');"><input type="hidden" name="action" value="delete_user"><input type="hidden" name="db_id" value="<?= htmlspecialchars($r['id']) ?>"><button class="btn btn-sm btn-outline-danger">Delete</button></form>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>

    <script>
        $(document).ready(function() {
            // Initialize DataTable
            $('#subTable').DataTable({
                paging: true,
                pageLength: 10,
                lengthChange: false,
                searching: false
            });

            // Delegate change handler for phone AND email inputs on ALL pages
            $('#subTable')
                // Phone updates
                .on('change', '.editable-phone', function() {
                    const input = $(this);
                    const id = input.data('id');
                    const raw = input.val();
                    input.prop('disabled', true);

                    $.post('', {
                        action: 'update_phone',
                        ajax: 1,
                        id: id,
                        phone: raw
                    }, function(res) {
                        if (res.success) {
                            // write back the cleaned phone (no leading +)
                            input.val(res.phone.replace(/^\+/, ''));
                        } else {
                            alert('Error updating phone: ' + res.error);
                        }
                        input.prop('disabled', false);
                    }, 'json');
                })

                // Email updates
                .on('change', '.editable-email', function() {
                    const input = $(this);
                    const id = input.data('id');
                    const raw = input.val();
                    input.prop('disabled', true);

                    $.post('', {
                        action: 'update_email',
                        ajax: 1,
                        id: id,
                        email: raw
                    }, function(res) {
                        if (!res.success) {
                            alert('Error updating email: ' + res.error);
                        }
                        input.prop('disabled', false);
                    }, 'json');
                });
        });
    </script>

</body>

</html>