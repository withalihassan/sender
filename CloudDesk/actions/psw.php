<?php
// minimal get-ec2-windows-password.php
require '../../aws/aws-autoloader.php';

use Aws\Ec2\Ec2Client;

// --- fill these manually ---
$awsKey           = 'AKIATUVNDLQWGBYICKJS';
$awsSecret        = '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';
$region           = 'me-central-1';
$instanceId       = 'i-090ee3ec40501a0e5';
$privateKeyPem    = "-----BEGIN RSA PRIVATE KEY-----
MIIEowIBAAKCAQEAu59h9Pi6S0E9azcYY7Rfdxb8/TJUTW4UO3bETL7J8vyi6185
1cQ1A0YVwGeAvMHaGqYGU0+pCtIdi4yPkywB7luyyMTZaT9H/DTsb11ivicAWY+d
fCEcaoVAvb7rTFTQmjhBqzni4dgXGXenS2MPlpft7iGZuPbMdiQ8YOLVOtWEP8WM
+6Uqo2QFVquUJeN74f7T0KDRAc1hvGjCSK2bSVcQIpyGyqELqFJBWiAW8hpkHTe4
t6+qCLsVDn1xDbpT4foEq9kH5SOgngFhqx6GVjZmU6lFrpSvBWPCfpjPGng+Y0U/
vJc8eOgIw2pTuY/vUgiXJ4S2RUJTWHRHum6AbwIDAQABAoIBAFY4b0apSssshvIP
VpjzGe/bU5VznIQcsxWEhb8S6jFM4f2yPBy9VvNTMMnDhsi3eOhIJZ4BfJhpaIRp
qs0iKx0DbMyQkaypFQaUt5aR3r+top6FvgR+HtXguJi59N2WGGrWsW7jYh0RNcRR
VULymgZFeWS2cjMqz1j3W/vWIdEv5mOJ8gZMwokWsAsXy5ejKIjMHdcy44iI+/TD
WJaBmnWKV8hT0hmYFYYE4hf4edGafN/WfKQW5Zcq/R78y/mR3tlfeBOYLgRuEXwo
TDWoku8sm382ZOZE91QM7CXVe2BQRmmHvsFwzQU04YD1UC7EAL3ZVzC4YFmtiksm
IC/dbBkCgYEA4ExuFvpxU49ZbAASbirsbeQJbjZ0xkWpEro8kn+M3BckvtN0kk/P
w5a7pF7GfMlN3mkz556QZkkU0CaHcrUK2eFhR5auhsE3BOKGEY5xTpOqiyV38hJh
WlVnJYkxwilrRD8jQ08Z82r2GBhG+bHf1/bdiKKocBj7EM9e/zLCVO0CgYEA1iPx
XnUE8i/Iut1nbMqrTLCTbUZ0vwuy+uJjSlU+SQioaGyLaXkFvDKDu7ya9j29omfQ
8perFA/i49e8GV+3Z1ek8OhiiE8uUu9TfrXcOi9Efc62B87yn1GkH+FnnkeLAvul
IgfqfeZpXJRm0y616wU7C5PQ4h3p+qkbtxEBO0sCgYBOhnOEV+mU93q29M9/AkgD
sPIcQ7ReNcUbaVgLcdw+sVuL8zu8fXSfZQYMZaHXziIU23/wdvLW3H8M4HBLGFbU
MLN9/KLdSoeYjjWhr9y7RbdPP67ecNDkb0HNQlrJPvbuzavqxKaxMaE2jklK4Zsc
YLDuRQPzOsuq9u6rKXofgQKBgQCH7vUPYvUq0A56IIXA1755xjUvzuPZSpHpFMC2
tPn+3pIZB55P69UqLF7XU9iCq5qvd3t6I7Ej4RnETHRJHyuLXGWFz96MbMcZOnck
HkmYXdz6h7ehqUr2u5qV6j4eiYfC8v9WZPQDy7niXQoQ0LwGXqGmrcSRZS/cQHEp
eo/vlQKBgGpHftHghdIjlHS5uzwwV5XyMx8vvugCeSR6Gc03VKQe+z3yBCVsS19i
Shr6cnj7ST1bwPZw3LjNd17r9GK6o/qbZufW5kaN1LfH0WmBg9p9ZpzARI2eoJ2f
loiSIBQ8MxAcnBJNEj3bkrgATEeGADSryAcoZMmIozzMgC8HJGFO
-----END RSA PRIVATE KEY-----";
$privateKeyPass   = ''; // if your PEM is encrypted, put the passphrase here
// ----------------------------

$ec2 = new Ec2Client([
    'version'     => 'latest',
    'region'      => $region,
    'credentials' => ['key' => $awsKey, 'secret' => $awsSecret],
]);

try {
    $res = $ec2->getPasswordData(['InstanceId' => $instanceId]);
} catch (Aws\Exception\AwsException $e) {
    exit("AWS error: " . $e->getMessage() . PHP_EOL);
}

$pwdData = (string) ($res['PasswordData'] ?? '');

if ($pwdData === '') {
    exit("No password data returned (Windows may not be ready or instance/key mismatch)." . PHP_EOL);
}

$encrypted = base64_decode($pwdData);
if ($encrypted === false) {
    exit("Failed to base64-decode password data." . PHP_EOL);
}

$key = openssl_pkey_get_private($privateKeyPem, $privateKeyPass);
if ($key === false) {
    exit("Failed to load private key (check PEM / passphrase)." . PHP_EOL);
}

$decrypted = '';
$ok = openssl_private_decrypt($encrypted, $decrypted, $key, OPENSSL_PKCS1_PADDING);

if (! $ok) {
    exit("RSA decryption failed (wrong key/format or padding mismatch)." . PHP_EOL);
}

// result is the Windows Administrator password
echo "Decrypted Windows password: " . $decrypted . PHP_EOL;
