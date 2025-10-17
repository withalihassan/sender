<?php
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_account') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        $stmt = $pdo->prepare("DELETE FROM `accounts` WHERE id = ?");
        $stmt->execute([$id]);
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}
$range_id = $_GET["rid"];
$user_id = $_GET["uid"];
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>Accounts — Manage</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css" rel="stylesheet">
    <style>
        body {
            background: #f6f8fa;
            padding: 20px
        }

        .card {
            box-shadow: 0 6px 18px rgba(0, 0, 0, .04)
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h3 class="mb-0">Accounts</h3>
            <div><a href="add_account.php" class="btn btn-primary">Make Account Ready</a></div>
            <div><a href="new_auto_add.php?rid=<?php echo $range_id; ?>&uid=<?php echo $user_id; ?>" class="btn btn-success">Add Bulk Account</a></div>
        </div>

        <div class="card">
            <div class="card-body p-0">
                <table id="accountsTable" class="table table-striped mb-0">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Email</th>
                            <th>PSW</th>
                            <th>Phone</th>
                            <th>Country</th>
                            <th>Status</th>
                            <th>Last Used</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        try {
                            $range_id = $_GET["rid"];
                            $user_id = $_GET["uid"];

                            $stmt = $pdo->prepare("SELECT id,email,email_otp,num_code,phone,phone_otp,account_psw,country,ac_status,ac_last_used,created_at FROM `accounts` Where by_user='$user_id' AND range_id='$range_id' ORDER BY id DESC");
                            $stmt->execute();
                            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                                $ac_ready_status = (!empty($r['email_otp']) && !empty($r['phone_otp']))
                                    ? "<span class='badge bg-success text-white'>Ready</span>"
                                    : "<span class='badge bg-warning text-dark'>Pending</span>";

                                $id = (int)$r['id'];
                                echo '<tr>';
                                echo '<td>' . $id . '</td>';
                                echo '<td>' . htmlspecialchars($r['email']) . '</td>';
                                echo '<td>' . htmlspecialchars($r['account_psw']) . '</td>';
                                echo '<td>' . htmlspecialchars(trim(($r['num_code'] ?? '') . ' ' . ($r['phone'] ?? ''))) . '</td>';
                                echo '<td>' . htmlspecialchars($r['country']) . '</td>';
                                echo '<td>' . $ac_ready_status . '</td>';
                                echo '<td>' . htmlspecialchars($r['ac_last_used']) . '</td>';
                                echo '<td>' . htmlspecialchars(strtolower(date('j M', is_numeric($r['created_at']) ? (int)$r['created_at'] : strtotime($r['created_at'])))) . '</td>';
                                echo '<td class="align-middle">
      <a class="btn btn-sm btn-outline-secondary" href="edit_account.php?id=' . $id . '">Edit</a>
      <form style="display:inline" method="post" onsubmit="return confirm(\'Delete account ID ' . $id . '? This is permanent.\')">
        <input type="hidden" name="action" value="delete_account">
        <input type="hidden" name="id" value="' . $id . '">
        <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
      </form>
    </td>';
                                echo '</tr>';
                            }
                        } catch (Exception $e) {
                            echo '<tr><td colspan="8" class="text-danger">Error: ' . htmlspecialchars($e->getMessage()) . '</td></tr>';
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script>
        $(function() {
            $('#accountsTable').DataTable({
                pageLength: 10,
                lengthMenu: [10, 25, 50, 100],
                order: [
                    [0, 'desc']
                ],
                columnDefs: [{
                    orderable: false,
                    targets: 7
                }]
            });
        });
    </script>
</body>

</html>