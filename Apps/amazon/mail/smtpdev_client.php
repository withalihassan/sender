<?php
require_once __DIR__ . '/mail_config.php';

function smtpdev_request($method, $path)
{
    $token = smtpdev_token();

    if ($token === '') {
        throw new Exception('SMTPDev token is not configured.');
    }

    $ch = curl_init(rtrim(smtpdev_base_url(), '/') . $path);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/json',
        'Content-Type: application/json',
        'User-Agent: amazon-mail-worker/1.0',
        'X-API-KEY: ' . $token,
    ]);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    $body = curl_exec($ch);
    $error = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($error) {
        throw new Exception('SMTPDev request failed.');
    }

    $json = json_decode($body, true);

    if ($code < 200 || $code >= 300 || !is_array($json)) {
        throw new Exception('SMTPDev returned an invalid response.');
    }

    return $json;
}

function smtpdev_find_account($email)
{
    $json = smtpdev_request('GET', '/accounts?address=' . urlencode($email) . '&page=1');
    $items = $json['member'] ?? $json['items'] ?? $json['hydra:member'] ?? $json;

    foreach ($items as $account) {
        if (($account['address'] ?? '') && strcasecmp($account['address'], $email) === 0) {
            return $account;
        }
    }

    return null;
}

function smtpdev_mailbox_id($account)
{
    if (!empty($account['mailboxes'][0]['id'])) {
        return $account['mailboxes'][0]['id'];
    }

    if (!empty($account['mailbox'][0]['id'])) {
        return $account['mailbox'][0]['id'];
    }

    if (!empty($account['id'])) {
        $fresh = smtpdev_request('GET', '/accounts/' . urlencode($account['id']));
        return $fresh['mailboxes'][0]['id'] ?? null;
    }

    return null;
}

function smtpdev_messages($email)
{
    $account = smtpdev_find_account($email);

    if (!$account || empty($account['id'])) {
        throw new Exception('SMTPDev mailbox was not found for this email.');
    }

    $mailboxId = smtpdev_mailbox_id($account);

    if (!$mailboxId) {
        throw new Exception('SMTPDev mailbox ID was not found.');
    }

    $json = smtpdev_request(
        'GET',
        '/accounts/' . urlencode($account['id']) . '/mailboxes/' . urlencode($mailboxId) . '/messages?page=1'
    );

    return [$account['id'], $mailboxId, $json['member'] ?? $json['items'] ?? $json['hydra:member'] ?? $json];
}

function smtpdev_message_detail($accountId, $mailboxId, $messageId)
{
    return smtpdev_request(
        'GET',
        '/accounts/' . urlencode($accountId) . '/mailboxes/' . urlencode($mailboxId) . '/messages/' . urlencode($messageId)
    );
}
?>
