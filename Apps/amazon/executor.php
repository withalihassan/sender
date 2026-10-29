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
    header('Location: index.php');
    exit;
}

$message = '';
$results = [];
$phoneText = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phoneText = trim($_POST['phones'] ?? '');
    $phones = array_values(array_filter(
        array_map('trim', preg_split('/\R/', $phoneText)),
        static fn($phone) => $phone !== ''
    ));

    foreach ($phones as &$phone) {
        if (substr($phone, 0, 1) !== '+') {
            $phone = '+' . $phone;
        }
    }
    unset($phone);

    if (!$phones) {
        $message = 'Enter at least one phone number.';
    } else {
        $invalidPhone = null;
        foreach ($phones as $phone) {
            if (!preg_match('/^\+[1-9]\d{1,14}$/', $phone)) {
                $invalidPhone = $phone;
                break;
            }
        }

        if ($invalidPhone !== null) {
            $message = 'Invalid phone number: ' . $invalidPhone;
        } else {
            session_write_close();

            try {
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

                $index = 0;
                while ($index < count($phones)) {
                    $phone = $phones[$index];
                    $contact['PhoneNumber'] = $phone;

                    try {
                        $client->putContactInformation(['ContactInformation' => $contact]);
                        $results[] = ['phone' => $phone, 'success' => true, 'message' => 'Updated'];
                    } catch (\Throwable $e) {
                        $error = $e instanceof \Aws\Exception\AwsException
                            ? ($e->getAwsErrorMessage() ?: $e->getMessage())
                            : $e->getMessage();
                        $results[] = ['phone' => $phone, 'success' => false, 'message' => $error];
                    }

                    $index++;
                }
            } catch (\Throwable $e) {
                $message = $e instanceof \Aws\Exception\AwsException
                    ? ($e->getAwsErrorMessage() ?: 'AWS request failed. Check IAM permissions.')
                    : $e->getMessage();
            }
        }
    }
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
        .message { margin-top: 12px; color: #b91c1c; }
        .result { margin: 6px 0; }
    </style>
</head>
<body>
    <main class="card">
        <h2>Update Phone Numbers</h2>
        <p>Enter one international phone number per line.</p>
        <form method="post">
            <label for="phones">Phone numbers</label>
            <textarea id="phones" name="phones" placeholder="923001234567&#10;+923009876543" required><?= htmlspecialchars($phoneText, ENT_QUOTES, 'UTF-8') ?></textarea>
            <button type="submit">Start Updates</button>
        </form>

        <?php if ($message !== ''): ?>
            <p class="message"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>

        <?php foreach ($results as $result): ?>
            <p class="result">
                <?= htmlspecialchars($result['phone'], ENT_QUOTES, 'UTF-8') ?>:
                <?= htmlspecialchars($result['message'], ENT_QUOTES, 'UTF-8') ?>
            </p>
        <?php endforeach; ?>
    </main>
</body>
</html>
