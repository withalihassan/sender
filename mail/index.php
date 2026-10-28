
<?php
session_start();

/*
 * index.php
 * Microsoft Graph OAuth2 inbox viewer
 *
 * Fill in the OAuth settings below.
 * Never publish this file without HTTPS and authentication.
 */

$config = [
    'email'         => 'RoseanneChriston88@outlook.com',
    'client_id'     => 'M.C527_BL2.0.U.MsaArtifacts.-CtgdRjbMBFI1wNWM6FA98vcxPgJl1dQ7FyiSb53g8!4ucyuECNXTnflRohcWVp*YMobMgHLs3mpdSzJlXGpO6OdEcyQeVgTaj0llZx2x5qUbW5gU0dUY!VvF5S7xmkOT7APpBhRrTrJdQPEEjykkh2F4ONCvVc8GZFUq0eLStRb2yIIpCj6hGHNfkQ93xzJ0ONPHmz!Jn5JrCenJCTsIUgyhXgFr9POLTbiIpJE7DQt4LnaG8We1n7aIXvRwmACM1e61Dg70M9bQbNoz!XdHRf3AbkPaHlNpvRoAi4pkhhHCvs2rSTWk8ZNNvNfW3yUcAzhtnr0!knvfPGf0ojyS7dT7RoYvrOPwhZDbdNlaRRtqxLcrIj*qZNEg6aodqD56m5IxywPHgQIbK77eMM0KsjNNz0XZXhIl9nXjIpOizFTDMY0T6jkUGLN3Gtw0UfU3HQ$$',
    'client_secret' => 'YOUR_CLIENT_SECRET', // Only if required by your OAuth flow
    'refresh_token' => 'YOUR_REFRESH_TOKEN',
    'tenant'        => 'consumers', // Outlook.com personal accounts
];

function h($value): string {
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function requestJson(string $url, array $headers = [], ?array $post = null): array {
    $ch = curl_init($url);

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 25,
    ];

    if ($post !== null) {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = http_build_query($post);
    }

    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException('Connection failed: ' . $error);
    }

    $data = json_decode($response, true);

    if ($status < 200 || $status >= 300) {
        $message = $data['error_description']
            ?? $data['error']['message']
            ?? $data['error']
            ?? 'HTTP ' . $status;

        throw new RuntimeException((string)$message);
    }

    return is_array($data) ? $data : [];
}

function graph(string $path, string $token): array {
    return requestJson(
        'https://graph.microsoft.com/v1.0/' . $path,
        [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
            'Prefer: outlook.body-content-type="text"',
        ]
    );
}

$error = '';
$emails = [];
$selected = null;

try {
    if (
        str_starts_with($config['client_id'], 'YOUR_') ||
        str_starts_with($config['refresh_token'], 'YOUR_')
    ) {
        throw new RuntimeException(
            'Configure your Microsoft OAuth client ID and refresh token first.'
        );
    }

    // Exchange refresh token for a short-lived access token.
    $tokenData = requestJson(
        'https://login.microsoftonline.com/' .
        rawurlencode($config['tenant']) .
        '/oauth2/v2.0/token',
        ['Content-Type: application/x-www-form-urlencoded'],
        [
            'client_id' => $config['client_id'],
            'grant_type' => 'refresh_token',
            'refresh_token' => $config['refresh_token'],
            'scope' => 'https://graph.microsoft.com/Mail.Read offline_access',
            ...($config['client_secret'] !== ''
                ? ['client_secret' => $config['client_secret']]
                : []),
        ]
    );

    $accessToken = $tokenData['access_token'] ?? '';

    if ($accessToken === '') {
        throw new RuntimeException('Microsoft did not return an access token.');
    }

    // Get the latest 25 inbox messages.
    $result = graph(
        'me/mailFolders/inbox/messages?' .
        rawurlencode('$top') . '=25&' .
        rawurlencode('$orderby') . '=' .
        rawurlencode('receivedDateTime desc') . '&' .
        rawurlencode('$select') . '=' .
        rawurlencode('id,subject,from,receivedDateTime,bodyPreview,isRead'),
        $accessToken
    );

    $emails = $result['value'] ?? [];

    // Open a message only if it belongs to the fetched inbox list.
    if (isset($_GET['id']) && is_string($_GET['id'])) {
        foreach ($emails as $email) {
            if (isset($email['id']) &&
                hash_equals($email['id'], $_GET['id'])) {
                $selected = graph(
                    'me/messages/' . rawurlencode($email['id']) .
                    '?$select=subject,from,receivedDateTime,body',
                    $accessToken
                );
                break;
            }
        }

        if (!$selected) {
            $error = 'Message not found in the latest inbox results.';
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Outlook Inbox</title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#f3f6fb;color:#172033;font:15px Arial,sans-serif}
main{max-width:950px;margin:35px auto;padding:16px}
.card{background:white;padding:22px;border-radius:14px;margin-bottom:18px;
box-shadow:0 4px 18px #1c2c4410}
h1{margin-top:0}
button{background:#1769e0;color:white;border:0;border-radius:8px;
padding:11px 16px;cursor:pointer}
a{color:#1769e0;text-decoration:none;overflow-wrap:anywhere}
.item{padding:17px 0;border-bottom:1px solid #e8edf4}
.subject{font-weight:bold;margin-bottom:8px}
.muted{color:#667085;font-size:13px}
.preview{margin:8px 0;overflow-wrap:anywhere}
.error{color:#a52222;background:#fff0f0;padding:14px;border-radius:9px;
overflow-wrap:anywhere}
.body{white-space:pre-wrap;overflow-wrap:anywhere;line-height:1.65}
</style>
</head>
<body>
<main>
<section class="card">
<h1>Outlook Inbox</h1>
<p class="muted">
<?= h($config['email']) ?> · Read-only email viewer
</p>
<button onclick="location.href='index.php'">Refresh inbox</button>
</section>

<?php if ($error !== ''): ?>
<section class="card error">
<strong>Notice:</strong> <?= h($error) ?>
</section>
<?php endif; ?>

<?php if ($selected): ?>
<section class="card">
<p><a href="index.php">&larr; Back to inbox</a></p>
<h2><?= h($selected['subject'] ?? '(No subject)') ?></h2>
<p class="muted">
<?= h($selected['from']['emailAddress']['address'] ?? '') ?>
</p>
<p class="muted"><?= h($selected['receivedDateTime'] ?? '') ?></p>
<hr>
<div class="body"><?= h($selected['body']['content'] ?? '') ?></div>
</section>
<?php elseif (!$error): ?>
<section class="card">
<h2>Received emails (<?= count($emails) ?>)</h2>

<?php if (!$emails): ?>
<p>Your inbox has no messages to display.</p>
<?php endif; ?>

<?php foreach ($emails as $email): ?>
<div class="item">
<div class="subject">
<a href="?id=<?= rawurlencode($email['id'] ?? '') ?>">
<?= h($email['subject'] ?? '(No subject)') ?>
</a>
</div>
<div><?= h($email['from']['emailAddress']['address'] ?? 'Unknown sender') ?></div>
<div class="preview"><?= h($email['bodyPreview'] ?? '') ?></div>
<div class="muted">
<?= h($email['receivedDateTime'] ?? '') ?>
<?= empty($email['isRead']) ? ' · Unread' : '' ?>
</div>
</div>
<?php endforeach; ?>
</section>
<?php endif; ?>
</main>
</body>
</html>
