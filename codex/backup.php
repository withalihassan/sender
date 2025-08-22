<?php
$token = "http://147.135.212.197/crapi/st/viewstats?token=RlJVSTRSQlaCbnNHeJeNgmpQZlhZdlF3e2yKR4GTYFtakWpbWJJv";
$filternum = 94771934991; // change as needed
$url = $token . "&filternum=" . $filternum;
// get JSON string
$json = file_get_contents($url);

// decode into PHP array
$data = json_decode($json, true);

// check and extract
if (is_array($data) && isset($data[0][2]) && isset($data[0][3])) {
    $message = $data[0][2];   // the SMS text
    $rawTime = $data[0][3];   // original timestamp

    // format timestamp (Y-m-d H:i:s -> d-M-Y h:i A)
    $formattedTime = date("d-M-Y h:i A", strtotime($rawTime));

    echo "<span><b>Desired message:</b> " . htmlspecialchars($message) . "</span><br>";
    echo "<span><b>Message received at:</b> " . $formattedTime . "</span>";
} else {
    echo "No message found.";
}
