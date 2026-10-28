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
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phone = trim($_POST['phone'] ?? '');

    try {
        // Release the PHP session lock before making network requests to AWS.
        session_write_close();

        if (!preg_match('/^\+[1-9]\d{1,14}$/', $phone)) {
            throw new RuntimeException(
                'Enter a valid international number, for example +923001234567.'
            );
        }

        // Credentials are read from the existing database record.
        $accessKey = $account['aws_key'] ?? '';
        $secretKey = $account['aws_secret'] ?? '';

        if (!$accessKey || !$secretKey) {
            throw new RuntimeException(
                'AWS access key or secret key is missing from the database record.'
            );
        }

        // The repository keeps the AWS SDK at the project root: /aws.
        $sdkAutoloader = __DIR__ . '/../../aws/aws-autoloader.php';

        if (!is_file($sdkAutoloader)) {
            throw new RuntimeException(
                'AWS SDK autoloader not found at: ' . $sdkAutoloader
            );
        }

        require_once $sdkAutoloader;

        if (!class_exists('Aws\\Account\\AccountClient')) {
            throw new RuntimeException(
                'AWS SDK loaded, but Aws\\Account\\AccountClient is unavailable.'
            );
        }

        $config = [
            'version' => 'latest',
            'region' => 'us-east-1',
            'credentials' => [
                'key'    => $accessKey,
                'secret' => $secretKey,
            ],
        ];

        $client = new \Aws\Account\AccountClient($config);

        // Saved credentials belong to this account, so omit AccountId.
        // This avoids an extra STS request and preserves standalone account support.
        $result = $client->getContactInformation([]);
        $contact = $result['ContactInformation'] ?? [];

        foreach (
            ['AddressLine1', 'City', 'CountryCode', 'FullName', 'PostalCode']
            as $field
        ) {
            if (empty($contact[$field])) {
                throw new RuntimeException(
                    "AWS contact information is missing: $field"
                );
            }
        }

        // Change only the phone number.
        $contact['PhoneNumber'] = $phone;

        $client->putContactInformation(['ContactInformation' => $contact]);

        $message = 'Phone number update submitted successfully.';
        $success = true;

    } catch (\Throwable $e) {
        $message = $e instanceof \Aws\Exception\AwsException
            ? ($e->getAwsErrorMessage() ?: 'AWS request failed. Check IAM permissions.')
            : $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Update AWS Phone Number</title>
<style>
* { box-sizing: border-box; }

body {
    margin: 0;
    min-height: 100vh;
    display: grid;
    place-items: center;
    padding: 20px;
    font-family: Arial, sans-serif;
    background: #f3f5f8;
    color: #172033;
}

.card {
    width: 100%;
    max-width: 420px;
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 26px;
}

h2 { margin: 0 0 22px; }

label {
    display: block;
    margin-bottom: 8px;
    font-weight: bold;
}

input {
    width: 100%;
    padding: 13px;
    border: 1px solid #cbd5e1;
    border-radius: 7px;
    font-size: 16px;
    margin-bottom: 14px;
}

button {
    width: 100%;
    padding: 13px;
    border: 0;
    border-radius: 7px;
    background: #ff9900;
    color: #111827;
    font-weight: bold;
    font-size: 15px;
    cursor: pointer;
}

button:disabled {
    opacity: .6;
    cursor: not-allowed;
}

.message {
    margin-top: 16px;
    font-size: 14px;
    overflow-wrap: anywhere;
    color: <?= $success ? '#15803d' : '#b91c1c' ?>;
}
</style>
</head>
<body>
<div class="card">
    <h2>Update Phone Number</h2>

    <form method="post">
        <label for="phone">New phone number</label>
        <input
            type="tel"
            id="phone"
            name="phone"
            placeholder="+923001234567"
            pattern="\+[1-9][0-9]{1,14}"
            required
        >

        <button type="submit">Update Phone Number</button>
    </form>

    <?php if ($message !== ''): ?>
        <div class="message">
            <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
