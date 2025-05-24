<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $output = [];
    $return_var = 0;

    exec('sudo ./var/www/html/deploy.sh 2>&1', $output, $return_var);

    if ($return_var === 0) {
        echo "Deploy script ran successfully:\n" . implode("\n", $output);
    } else {
        http_response_code(500);
        echo "Error running deploy script:\n" . implode("\n", $output);
    }
    exit;
}
?>
