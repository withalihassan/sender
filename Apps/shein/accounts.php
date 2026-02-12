<?php
require_once __DIR__ . '/db.php';

header('X-Content-Type-Options: nosniff');

$range_id = isset($_GET['rid']) ? (int)$_GET['rid'] : 0;
$user_id  = isset($_GET['uid']) ? (int)$_GET['uid'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'delete_account') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare("DELETE FROM `accounts` WHERE id = ?");
            $stmt->execute([$id]);
        }
        header('Location: ' . $_SERVER['PHP_SELF'] . '?rid=' . $range_id . '&uid=' . $user_id);
        exit;
    }

    // AJAX update
    if ($action === 'update_account' && (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')) {
        $allowed_fields = ['email', 'spot_id', 'profile_id'];
        $id    = (int)($_POST['id'] ?? 0);
        $field = $_POST['field'] ?? '';
        $value = $_POST['value'] ?? '';

        if ($id <= 0 || !in_array($field, $allowed_fields, true)) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
            exit;
        }

        if ($field === 'email') {
            if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => 'Invalid email address']);
                exit;
            }
        }

        $sql = "UPDATE `accounts` SET {$field} = ? WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        try {
            $stmt->execute([$value, $id]);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => true]);
            exit;
        } catch (Exception $e) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'DB error']);
            exit;
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Accounts — Manage</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css" rel="stylesheet">
    <style>
        body { background: #f6f8fa; padding: 20px; }
        .card { box-shadow: 0 6px 18px rgba(0,0,0,.04); }
        .input-inline { min-width: 220px; display: inline-block; }
    </style>
</head>
<body>
<div class="container">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3 class="mb-0">Accounts</h3>
        <div class="d-flex gap-2">
            <a href="add_account.php" class="btn btn-primary">Make Account Ready</a>
            <a href="new_auto_add.php?rid=<?php echo $range_id; ?>&uid=<?php echo $user_id; ?>" target="_blank" class="btn btn-success">Add Bulk Account</a>
        </div>
    </div>

    <div class="card">
        <div class="card-body p-0">
            <table id="accountsTable" class="table table-striped mb-0">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Email</th>
                        <th>PSW</th>
                        <th>Spot ID</th>
                        <th>Profile ID</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    try {
                        $stmt = $pdo->prepare("SELECT id, email, spot_id, profile_id, created_at FROM `accounts` WHERE by_user = ? ORDER BY id DESC");
                        $stmt->execute([$user_id]);

                        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                            $id = (int)$r['id'];
                            $email = $r['email'] ?? '';
                            $spot  = $r['spot_id'] ?? '';
                            $profile = $r['profile_id'] ?? '';
                            $createdRaw = $r['created_at'] ?? '';
                            $createdTs = is_numeric($createdRaw) ? (int)$createdRaw : strtotime($createdRaw);
                            $created = $createdTs ? htmlspecialchars(strtolower(date('j M', $createdTs))) : '';
                            ?>
                            <tr data-id="<?php echo $id; ?>">
                                <td><?php echo $id; ?></td>

                                <td>
                                    <input type="text"
                                           class="form-control form-control-sm editable input-inline"
                                           data-field="email"
                                           value="<?php echo htmlspecialchars($email); ?>"
                                           data-id="<?php echo $id; ?>">
                                </td>

                                <td>@Smsng#860</td>

                                <td>
                                    <input type="text"
                                           class="form-control form-control-sm editable input-inline"
                                           data-field="spot_id"
                                           value="<?php echo htmlspecialchars($spot); ?>"
                                           data-id="<?php echo $id; ?>">
                                </td>

                                <td>
                                    <input type="text"
                                           class="form-control form-control-sm editable input-inline"
                                           data-field="profile_id"
                                           value="<?php echo htmlspecialchars($profile); ?>"
                                           data-id="<?php echo $id; ?>">
                                </td>

                                <td><?php echo $created; ?></td>

                                <td class="align-middle">
                                    <form style="display:inline" method="post" onsubmit="return confirm('Delete account ID <?php echo $id; ?>? This is permanent.');">
                                        <input type="hidden" name="action" value="delete_account">
                                        <input type="hidden" name="id" value="<?php echo $id; ?>">
                                        <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                                    </form>
                                </td>
                            </tr>
                            <?php
                        }
                    } catch (Exception $e) {
                        echo '<tr><td colspan="7" class="text-danger">Error: ' . htmlspecialchars($e->getMessage()) . '</td></tr>';
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
        order: [[0, 'desc']],
        columnDefs: [
            { orderable: false, targets: 6 }
        ]
    });

    $(document).on('keydown', '.editable', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            $(this).blur();
        }
    });

    $(document).on('blur', '.editable', function() {
        var $input = $(this);
        var id = $input.data('id');
        var field = $input.data('field');
        var value = $input.val();

        if (!id || !field) return;

        if (field === 'email' && value.length > 0) {
            var emailRE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRE.test(value)) {
                // invalid email: do not send update
                return;
            }
        }

        $input.prop('disabled', true);

        $.ajax({
            url: '',
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'update_account',
                id: id,
                field: field,
                value: value
            },
            complete: function() {
                $input.prop('disabled', false);
            }
            // intentionally no success/error UI feedback
        });
    });
});
</script>
</body>
</html>
