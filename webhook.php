<?php
declare(strict_types=1);

$deployScript = '/var/www/sender/deploy.sh';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('POST only');
}

$event = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? '';
if ($event !== 'push') {
    http_response_code(200);
    exit('Ignored');
}

$payload = file_get_contents('php://input') ?: '';
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