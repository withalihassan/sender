<?php
declare(strict_types=1);

$secret = getenv('5169879d6e2ce0d6be6de6c8dda689') ?: '';
$deployScript = '/var/www/sender/deploy.sh';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('POST only');
}

$payload = file_get_contents('php://input') ?: '';
$signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$event = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? '';

$expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);

if ($secret === '' || !hash_equals($expected, $signature)) {
    http_response_code(403);
    exit('Invalid signature');
}

if ($event !== 'push') {
    http_response_code(200);
    exit('Ignored');
}

$data = json_decode($payload, true);
if (!is_array($data) || ($data['ref'] ?? '') !== 'refs/heads/main') {
    http_response_code(200);
    exit('Ignored branch');
}

$output = [];
$returnCode = 0;
exec('/bin/bash ' . escapeshellarg($deployScript) . ' 2>&1', $output, $returnCode);

if ($returnCode !== 0) {
    http_response_code(500);
    echo implode("\n", $output);
    exit;
}

echo "OK";