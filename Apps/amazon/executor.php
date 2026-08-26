<?php
require 'includes/auth.php';
require 'includes/database.php';

ensure_amazon_tables($pdo);

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT id, aws_account_id, aws_key FROM accounts WHERE id = ?");
$stmt->execute([$id]);
$account = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$account) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AWS Executor</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Arial, sans-serif; color: #1f2937; background: #eef2f7; }
        .topbar {
            background: #232f3e; color: #fff; padding: 18px 28px;
            display: flex; justify-content: space-between; align-items: center; gap: 16px;
        }
        .brand { font-size: 22px; font-weight: 700; }
        .brand span { color: #ff9900; }
        .topbar a { color: #fff; text-decoration: none; margin-left: 14px; }
        .wrap { max-width: 1200px; margin: 28px auto; padding: 0 18px; }
        .account-strip {
            background: #fff; border: 1px solid #d8dee8; border-radius: 8px;
            padding: 16px 20px; margin-bottom: 22px; display: flex; gap: 24px; flex-wrap: wrap;
        }
        .grid { display: grid; grid-template-columns: 1fr 1.25fr; gap: 22px; align-items: start; }
        .card {
            background: #fff; border: 1px solid #d8dee8; border-radius: 8px;
            padding: 22px; box-shadow: 0 8px 22px rgba(15, 23, 42, 0.06);
        }
        h1, h2 { margin: 0 0 18px; }
        h1 { font-size: 24px; }
        h2 { font-size: 20px; }
        label { display: block; margin-bottom: 7px; font-weight: 700; font-size: 14px; }
        input, textarea {
            width: 100%; padding: 12px; border: 1px solid #c9d2df;
            border-radius: 6px; font-size: 15px; margin-bottom: 16px;
        }
        textarea { min-height: 160px; resize: vertical; }
        input:focus, textarea:focus {
            outline: none; border-color: #ff9900; box-shadow: 0 0 0 3px rgba(255, 153, 0, 0.18);
        }
        button {
            border: 0; border-radius: 6px; padding: 12px 16px;
            font-weight: 700; cursor: pointer; font-size: 14px;
        }
        .primary { background: #ff9900; color: #111827; }
        .danger { background: #dc2626; color: #fff; }
        .muted { background: #64748b; color: #fff; }
        .buttons { display: flex; gap: 10px; flex-wrap: wrap; }
        .status-box {
            margin-top: 18px; padding: 14px; border-radius: 8px;
            background: #f8fafc; border: 1px solid #e2e8f0;
        }
        .status-row { display: flex; justify-content: space-between; gap: 14px; padding: 7px 0; border-bottom: 1px solid #e5e7eb; }
        .status-row:last-child { border-bottom: 0; }
        .mail-card { min-height: 360px; }
        .mail-stats {
            display: grid; grid-template-columns: 1fr; gap: 10px; margin-bottom: 18px;
            padding: 14px; border-radius: 8px; background: #f8fafc; border: 1px solid #e2e8f0;
        }
        .mail-stats div { display: flex; justify-content: space-between; gap: 14px; }
        .mail-table-wrap { overflow-x: auto; }
        .mail-table { width: 100%; border-collapse: collapse; min-width: 760px; }
        .mail-table th, .mail-table td {
            padding: 12px; border-bottom: 1px solid #e5e7eb; text-align: left;
            vertical-align: top;
        }
        .mail-table th { background: #f8fafc; font-size: 13px; }
        .mail-meta { white-space: nowrap; font-size: 13px; }
        .mail-body {
            max-width: 360px; max-height: 220px; overflow: auto; margin: 0;
            white-space: pre-wrap; overflow-wrap: anywhere; font-family: Arial, sans-serif;
            font-size: 13px; line-height: 1.45; color: #111827;
        }
        .message { margin-top: 14px; font-weight: 700; color: #991b1b; }
        @media (max-width: 820px) {
            .topbar { flex-direction: column; align-items: flex-start; }
            .grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="topbar">
        <div class="brand">amazon<span>.</span> Executor</div>
        <div>
            <?php echo htmlspecialchars($_SESSION['username']); ?>
            <a href="index.php">Dashboard</a>
            <a href="logout.php">Logout</a>
        </div>
    </div>

    <main class="wrap">
        <div class="account-strip">
            <div><strong>Database ID:</strong> <?php echo (int) $account['id']; ?></div>
            <div><strong>AWS Account ID:</strong> <?php echo htmlspecialchars($account['aws_account_id']); ?></div>
            <div><strong>Access Key:</strong> <?php echo htmlspecialchars(mask_value($account['aws_key'])); ?></div>
        </div>

        <div class="grid">
            <section class="card">
                <h1>Number Updation</h1>
                <form id="processForm">
                    <input type="hidden" name="account_id" value="<?php echo (int) $account['id']; ?>">

                    <label>Email</label>
                    <input type="email" name="email" id="emailInput" required>

                    <label>Phone Numbers</label>
                    <textarea name="numbers" required placeholder="+923116128008&#10;+923001234567&#10;+923451234567"></textarea>

                    <div class="buttons">
                        <button type="submit" class="primary">Start Process</button>
                        <button type="button" class="danger" id="stopBtn">Stop Process</button>
                    </div>
                </form>

                <div class="status-box">
                    <div class="status-row"><strong>Status</strong><span id="status">Idle</span></div>
                    <div class="status-row"><strong>Current phone</strong><span id="currentPhone"></span></div>
                    <div class="status-row"><strong>Progress</strong><span id="progress">0 / 0</span></div>
                </div>
                <div id="message" class="message"></div>
            </section>

            <section class="card mail-card">
                <h2>Mail Execution</h2>
                <div class="buttons">
                    <button type="button" class="primary" id="mailStartBtn">Start Mail Execution</button>
                </div>

                <div class="mail-stats">
                    <div><strong>Status:</strong><span id="mailStatus">Idle</span></div>
                    <div><strong>Emails Processed:</strong><span id="mailProcessed">0</span></div>
                    <div><strong>Last Email:</strong><span id="mailLastEmail"></span></div>
                    <div><strong>Current Operation:</strong><span id="mailOperation">Idle</span></div>
                </div>

                <div class="mail-table-wrap">
                    <table class="mail-table">
                        <thead>
                            <tr>
                                <th>Received</th>
                                <th>From</th>
                                <th>To</th>
                                <th>Subject</th>
                                <th>Complete Email</th>
                            </tr>
                        </thead>
                        <tbody id="mailEventsBody"></tbody>
                    </table>
                </div>
                <div id="mailMessage" class="message"></div>
            </section>
        </div>
    </main>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script>
        const accountId = <?php echo (int) $account['id']; ?>;
        const message = $('#message');

        function updateStatus() {
            $.post('ajax/process_status.php', { account_id: accountId }, function (res) {
                if (!res.success) {
                    message.text(res.message || 'Unable to load status.');
                    return;
                }

                $('#status').text(res.job.status);
                $('#currentPhone').text(res.job.current_phone);
                $('#progress').text(res.job.progress);
                message.text(res.job.message || '');
            }, 'json');
        }

        $('#processForm').on('submit', function (e) {
            e.preventDefault();
            message.text('Starting process...');

            $.post('ajax/start_process.php', $(this).serialize(), function (res) {
                message.text(res.message);
                updateStatus();
            }, 'json').fail(function () {
                message.text('Request failed. Please try again.');
            });
        });

        $('#stopBtn').on('click', function () {
            $.post('ajax/stop_process.php', { account_id: accountId }, function (res) {
                message.text(res.message);
                updateStatus();
            }, 'json');
        });

        function renderMailEvents(events) {
            $('#mailEventsBody').empty();

            events.forEach(function (event) {
                const body = event.email_text || event.email_json || '';

                $('#mailEventsBody').append(
                    '<tr data-event-id="' + event.id + '">' +
                    '<td class="mail-meta">' + $('<div>').text(event.created_at || '').html() + '</td>' +
                    '<td>' + $('<div>').text(event.sender || '').html() + '</td>' +
                    '<td>' + $('<div>').text(event.recipient || '').html() + '</td>' +
                    '<td>' + $('<div>').text(event.subject || '').html() + '</td>' +
                    '<td><pre class="mail-body">' + $('<div>').text(body).html() + '</pre></td>' +
                    '</tr>'
                );
            });
        }

        function updateMailStatus() {
            $.post('ajax/mail_status.php', { account_id: accountId }, function (res) {
                if (!res.success) {
                    $('#mailMessage').text(res.message || 'Unable to load mail status.');
                    return;
                }

                $('#mailStatus').text(res.run.status);
                $('#mailProcessed').text(res.run.emails_processed);
                $('#mailLastEmail').text(res.run.last_email);
                $('#mailOperation').text(res.run.current_operation);
                $('#mailMessage').text(res.run.error_message || '');

                if (res.run.is_polling) {
                    $('#mailStartBtn').prop('disabled', true).text('Email Polling Started');
                } else {
                    $('#mailStartBtn').prop('disabled', false).text('Start Mail Execution');
                }
            }, 'json');
        }

        function loadMailEvents() {
            $.post('ajax/mail_events.php', { account_id: accountId }, function (res) {
                if (res.success) {
                    renderMailEvents(res.events);
                }
            }, 'json');
        }

        $('#mailStartBtn').on('click', function () {
            const email = $('#emailInput').val().trim();

            if (!email) {
                $('#mailMessage').text('Enter an email in the Number Updation email field first.');
                return;
            }

            $('#mailStartBtn').prop('disabled', true).text('Starting...');
            $.post('ajax/mail_start.php', { account_id: accountId, email: email }, function (res) {
                $('#mailMessage').text(res.message);
                updateMailStatus();
            }, 'json').fail(function () {
                $('#mailMessage').text('Request failed. Please try again.');
                $('#mailStartBtn').prop('disabled', false).text('Start Mail Execution');
            });
        });

        updateStatus();
        updateMailStatus();
        loadMailEvents();
        setInterval(updateStatus, 10000);
        setInterval(function () {
            updateMailStatus();
            loadMailEvents();
        }, 2000);
    </script>
</body>
</html>
