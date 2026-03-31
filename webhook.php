<?php

$output = [];
$return = 0;

exec('/bin/bash /usr/local/bin/sender-update.sh 2>&1', $output, $return);

http_response_code($return === 0 ? 200 : 500);
echo implode("\n", $output);