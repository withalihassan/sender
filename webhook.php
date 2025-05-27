<?php
// Execute deploy.sh and capture output
$output = shell_exec('sudo /bin/bash /var/www/html/deploy.sh 2>&1');

// Display output
echo "<pre>$output</pre>";
?>
