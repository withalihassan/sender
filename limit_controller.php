<?php
// Simple PHP script to fetch the JSON response and count rows by country code.
// Edit $CountryCode or the $params below as needed.

$CountryCode = '234'; // <-- change this as you like

// Base URL and params (we use http_build_query so dt1/dt2 are URL-encoded properly)
$baseUrl = 'http://147.135.212.197/crapi/st/viewstats';
$params = [
    'token'   => 'RlJVSTRSQlaCbnNHeJeNgmpQZlhZdlF3e2yKR4GTYFtakWpbWJJv',
    'dt1'     => '2025-12-28 11:05:00',
    'dt2'     => '2025-12-28 11:10:00',
    'records' => 2000
];
echo $url = $baseUrl . '?' . http_build_query($params);
echo "<br>";
// ---------- fetch the URL using cURL ----------
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);            // seconds
curl_setopt($ch, CURLOPT_USERAGENT, 'PHP-cURL/1.0');
$response = curl_exec($ch);

if ($response === false) {
    // network / cURL error
    $err = curl_error($ch);
    curl_close($ch);
    die("cURL error: $err\n");
}

$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    die("HTTP error: received status code $httpCode\nResponse body:\n$response\n");
}

// ---------- decode JSON ----------
$data = json_decode($response, true); // decode to PHP array

if (json_last_error() !== JSON_ERROR_NONE) {
    // if the response isn't valid JSON, stop and show the raw response for debugging
    $errMsg = json_last_error_msg();
    die("JSON decode error: $errMsg\nRaw response:\n$response\n");
}

// ---------- count rows where phone starts with $CountryCode ----------
$total = 0;
$cc = (string)$CountryCode;

foreach ($data as $row) {
    // Expect each row like: ["NOTICE", "2347020488601", "message text", "2025-12-28 11:07:47"]
    if (!isset($row[1])) continue;              // skip malformed rows
    $num = (string)$row[1];

    // Remove any leading non-digit characters (e.g. '+' or spaces) to normalize
    $num = preg_replace('/^\D+/', '', $num);

    // If phone starts with the country code, increment
    if (strpos($num, $cc) === 0) {
        $total++;
    }
}

// ---------- display result exactly as requested ----------
echo "total sms for {$cc} is = {$total}\n";
