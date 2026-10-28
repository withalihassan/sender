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

if (empty($_SESSION['phone_update_token'])) {
    $_SESSION['phone_update_token'] = bin2hex(random_bytes(32));
}

$message = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phone = trim($_POST['phone'] ?? '');
    $token = $_POST['token'] ?? '';

    try {
        if (!hash_equals($_SESSION['phone_update_token'], $token)) {
            throw new RuntimeException('Invalid form token. Refresh the page.');
        }

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
body { font-family: Arial, sans-serif; margin: 20px; }
.card { max-width: 400px; }
label { display: block; margin-bottom: 6px; }
input, button { box-sizing: border-box; width: 100%; padding: 10px; margin-bottom: 12px; }
.message { color: <?= $success ? 'green' : 'red' ?>; }
</style>
</head>
<body>
<div class="card">
    <h2>Update Phone Number</h2>

    <form method="post">
        <input
            type="hidden"
            name="token"
            value="<?= htmlspecialchars(
                $_SESSION['phone_update_token'],
                ENT_QUOTES,
                'UTF-8'
            ) ?>"
        >

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
