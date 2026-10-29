<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/db.php';

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare(
    'SELECT aws_key, aws_secret FROM accounts WHERE id = ? AND by_user = ? LIMIT 1'
);
$stmt->execute([$id, (int) $_SESSION['user_id']]);
$account = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$account) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Account not found.']);
        exit;
    }
    header('Location: index.php');
    exit;
}

$phoneText = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    session_write_close();

    try {
        $phone = trim($_POST['phone'] ?? '');
        if (substr($phone, 0, 1) !== '+') {
            $phone = '+' . $phone;
        }
        if (!preg_match('/^\+[1-9]\d{1,14}$/', $phone)) {
            throw new RuntimeException('Enter a valid international phone number.');
        }

        $sdkAutoloader = __DIR__ . '/../../aws/aws-autoloader.php';
        if (!is_file($sdkAutoloader)) {
            throw new RuntimeException('AWS SDK autoloader was not found.');
        }
        require_once $sdkAutoloader;

        if (!class_exists('Aws\\Account\\AccountClient')) {
            throw new RuntimeException('AWS Account client is unavailable.');
        }

        $client = new \Aws\Account\AccountClient([
            'version' => 'latest',
            'region' => 'us-east-1',
            'credentials' => [
                'key' => $account['aws_key'],
                'secret' => $account['aws_secret'],
            ],
        ]);

        $result = $client->getContactInformation([]);
        $contact = $result['ContactInformation'] ?? [];
        foreach (['AddressLine1', 'City', 'CountryCode', 'FullName', 'PostalCode'] as $field) {
            if (empty($contact[$field])) {
                throw new RuntimeException("AWS contact information is missing: $field");
            }
        }

        $contact['PhoneNumber'] = $phone;
        $client->putContactInformation(['ContactInformation' => $contact]);
        echo json_encode(['success' => true, 'message' => 'Updated ' . $phone]);
    } catch (\Throwable $e) {
        $message = $e instanceof \Aws\Exception\AwsException
            ? ($e->getAwsErrorMessage() ?: 'AWS request failed. Check IAM permissions.')
            : $e->getMessage();
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $message]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Update AWS Phone Numbers</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 16px; box-sizing: border-box; font: 16px Arial, sans-serif; background: #f4f6f8; }
        .card { width: 100%; max-width: 440px; padding: 24px; box-sizing: border-box; background: #fff; border: 1px solid #ddd; border-radius: 8px; }
        textarea, button { width: 100%; box-sizing: border-box; padding: 11px; margin-top: 8px; font: inherit; }
        textarea { min-height: 180px; resize: vertical; }
        button { border: 0; border-radius: 4px; background: #ff9900; cursor: pointer; font-weight: bold; }
        button:disabled { opacity: .6; cursor: wait; }
        #status { margin-top: 12px; min-height: 20px; }
        #countdown { display: block; color: #666; font-size: 12px; }
        .result { margin: 6px 0; }
    </style>
</head>
<body>
    <main class="card">
        <h2>Update Phone Numbers</h2>
        <p>Enter one international phone number per line. Updates run 50 seconds apart.</p>
        <form id="phoneForm">
            <label for="phones">Phone numbers</label>
            <textarea id="phones" name="phones" placeholder="923001234567&#10;+923009876543" required><?= htmlspecialchars($phoneText, ENT_QUOTES, 'UTF-8') ?></textarea>
            <button id="startButton" type="submit">Start Updates</button>
        </form>
        <div id="status" role="status" aria-live="polite"></div>
        <small id="countdown" aria-live="polite"></small>
        <div id="results"></div>
    </main>

    <script>
        const form = document.getElementById('phoneForm');
        const textarea = document.getElementById('phones');
        const button = document.getElementById('startButton');
        const status = document.getElementById('status');
        const countdown = document.getElementById('countdown');
        const results = document.getElementById('results');
        const delaySeconds = 50;
        let controller = null;
        let running = false;

        const wait = (milliseconds) => new Promise(resolve => setTimeout(resolve, milliseconds));

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (running) return;

            const phones = textarea.value
                .split(/\r?\n/)
                .map(phone => phone.trim())
                .filter(Boolean)
                .map(phone => phone.startsWith('+') ? phone : '+' + phone);

            if (!phones.length) {
                status.textContent = 'Enter at least one phone number.';
                return;
            }

            const invalidPhone = phones.find(phone => !/^\+[1-9]\d{1,14}$/.test(phone));
            if (invalidPhone) {
                status.textContent = 'Invalid phone number: ' + invalidPhone;
                return;
            }

            running = true;
            controller = new AbortController();
            textarea.disabled = true;
            button.disabled = true;
            status.textContent = '';
            countdown.textContent = '';
            results.replaceChildren();
            let completed = 0;

            try {
                while (completed < phones.length) {
                    status.textContent = 'Updating ' + (completed + 1) + ' of ' + phones.length + ': ' + phones[completed];
                    const response = await fetch(window.location.href, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({ phone: phones[completed] }),
                        signal: controller.signal
                    });
                    const result = await response.json();
                    if (!response.ok || !result.success) {
                        throw new Error(result.message || 'Update failed.');
                    }

                    const row = document.createElement('p');
                    row.className = 'result';
                    row.textContent = result.message;
                    results.appendChild(row);
                    completed++;

                    if (completed < phones.length) {
                        for (let seconds = delaySeconds; seconds > 0; seconds--) {
                            countdown.textContent = 'Next number in ' + seconds + ' seconds';
                            await wait(1000);
                        }
                    }
                }
                status.textContent = 'Finished. Updated ' + completed + ' phone number(s).';
            } catch (error) {
                if (error.name !== 'AbortError') {
                    status.textContent = 'Stopped after ' + completed + ' update(s): ' + error.message;
                }
            } finally {
                running = false;
                controller = null;
                textarea.disabled = false;
                button.disabled = false;
                countdown.textContent = '';
            }
        });

        window.addEventListener('pagehide', () => {
            if (controller) controller.abort();
        });
    </script>
</body>
</html>
