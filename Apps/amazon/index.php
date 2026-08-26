<?php
require 'includes/auth.php';
require 'includes/database.php';

ensure_amazon_tables($pdo);

$stmt = $pdo->query("SELECT id, aws_account_id, aws_key, aws_secret, created_at FROM accounts ORDER BY id DESC");
$accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Amazon AWS Dashboard</title>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Arial, sans-serif; color: #1f2937; background: #eef2f7; }
        .topbar {
            background: #232f3e; color: #fff; padding: 18px 28px;
            display: flex; justify-content: space-between; align-items: center; gap: 16px;
        }
        .brand { font-size: 22px; font-weight: 700; }
        .brand span { color: #ff9900; }
        .topbar a { color: #fff; text-decoration: none; }
        .wrap { max-width: 1200px; margin: 28px auto; padding: 0 18px; }
        .card {
            background: #fff; border: 1px solid #d8dee8; border-radius: 8px;
            padding: 22px; box-shadow: 0 8px 22px rgba(15, 23, 42, 0.06); margin-bottom: 22px;
        }
        h1, h2 { margin: 0 0 18px; }
        h1 { font-size: 26px; }
        h2 { font-size: 20px; }
        .grid { display: grid; grid-template-columns: 1fr 1fr auto; gap: 14px; align-items: end; }
        label { display: block; margin-bottom: 7px; font-weight: 700; font-size: 14px; }
        input {
            width: 100%; padding: 12px; border: 1px solid #c9d2df;
            border-radius: 6px; font-size: 15px;
        }
        input:focus { outline: none; border-color: #ff9900; box-shadow: 0 0 0 3px rgba(255, 153, 0, 0.18); }
        button, .btn {
            border: 0; border-radius: 6px; padding: 12px 16px; font-weight: 700;
            cursor: pointer; text-decoration: none; display: inline-block; font-size: 14px;
        }
        .primary { background: #ff9900; color: #111827; }
        .danger { background: #dc2626; color: #fff; }
        .dark { background: #232f3e; color: #fff; }
        .message {
            display: none; padding: 12px; margin-top: 14px; border-radius: 6px; font-weight: 700;
        }
        .success { background: #dcfce7; color: #166534; }
        .error { background: #fee2e2; color: #991b1b; }
        table.dataTable { width: 100% !important; }
        .actions { display: flex; gap: 8px; flex-wrap: wrap; }
        @media (max-width: 760px) {
            .topbar { flex-direction: column; align-items: flex-start; }
            .grid { grid-template-columns: 1fr; }
            .wrap { margin-top: 18px; }
        }
    </style>
</head>
<body>
    <div class="topbar">
        <div class="brand">amazon<span>.</span> AWS Dashboard</div>
        <div>
            <?php echo htmlspecialchars($_SESSION['username']); ?> |
            <a href="logout.php">Logout</a>
        </div>
    </div>

    <main class="wrap">
        <div class="card">
            <h1>Add AWS Account</h1>
            <form id="addAccountForm">
                <div class="grid">
                    <div>
                        <label>AWS Access Key</label>
                        <input type="text" name="aws_key" autocomplete="off" required>
                    </div>
                    <div>
                        <label>AWS Secret Key</label>
                        <input type="password" name="aws_secret" autocomplete="off" required>
                    </div>
                    <button class="primary" type="submit">Add Account</button>
                </div>
            </form>
            <div id="message" class="message"></div>
        </div>

        <div class="card">
            <h2>Saved Accounts</h2>
            <table id="accountsTable" class="display">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>AWS Account ID</th>
                        <th>Access Key</th>
                        <th>Secret Key</th>
                        <th>Created At</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($accounts as $account): ?>
                        <tr>
                            <td><?php echo (int) $account['id']; ?></td>
                            <td><?php echo htmlspecialchars($account['aws_account_id']); ?></td>
                            <td><?php echo htmlspecialchars(mask_value($account['aws_key'])); ?></td>
                            <td><?php echo htmlspecialchars(mask_value($account['aws_secret'])); ?></td>
                            <td><?php echo htmlspecialchars($account['created_at']); ?></td>
                            <td>
                                <div class="actions">
                                    <a class="btn dark" href="executor.php?id=<?php echo (int) $account['id']; ?>">Open</a>
                                    <button class="danger delete-btn" data-id="<?php echo (int) $account['id']; ?>">Delete</button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </main>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
    <script>
        const table = $('#accountsTable').DataTable();
        const message = $('#message');

        function showMessage(text, type) {
            message.removeClass('success error').addClass(type).text(text).show();
        }

        $('#addAccountForm').on('submit', function (e) {
            e.preventDefault();

            $.post('ajax/add_account.php', $(this).serialize(), function (res) {
                if (!res.success) {
                    showMessage(res.message, 'error');
                    return;
                }

                const actions = '<div class="actions">' +
                    '<a class="btn dark" href="executor.php?id=' + res.account.id + '">Open</a>' +
                    '<button class="danger delete-btn" data-id="' + res.account.id + '">Delete</button>' +
                    '</div>';

                table.row.add([
                    res.account.id,
                    res.account.aws_account_id,
                    res.account.aws_key,
                    res.account.aws_secret,
                    res.account.created_at,
                    actions
                ]).draw(false);

                $('#addAccountForm')[0].reset();
                showMessage(res.message, 'success');
            }, 'json').fail(function () {
                showMessage('Request failed. Please try again.', 'error');
            });
        });

        $(document).on('click', '.delete-btn', function () {
            if (!confirm('Delete this AWS account?')) {
                return;
            }

            const id = $(this).data('id');
            const button = $(this);

            $.post('ajax/delete_account.php', { id: id }, function (res) {
                if (!res.success) {
                    showMessage(res.message, 'error');
                    return;
                }

                table.row(button.closest('tr')).remove().draw(false);
                showMessage(res.message, 'success');
            }, 'json').fail(function () {
                showMessage('Request failed. Please try again.', 'error');
            });
        });
    </script>
</body>
</html>
