<?php

$repoDir = '/var/www/html';
$repoUrl = 'https://github.com/withalihassan/sender.git';
$branch = 'main';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $output = [];
    $returnVar = 0;

    // Change to repo directory
    if (!chdir($repoDir)) {
        http_response_code(500);
        echo "Cannot access $repoDir";
        exit;
    }

    // Mark directory as safe for git
    exec("git config --global --add safe.directory '$repoDir'");

    // Check if .git exists
    if (!is_dir("$repoDir/.git")) {
        // Not a git repo - initialize and clone
        exec("git init", $output, $returnVar);
        exec("git remote add origin '$repoUrl'", $output, $returnVar);
        exec("git fetch origin '$branch'", $output, $returnVar);
        exec("git checkout -t origin/$branch", $output, $returnVar);
    } else {
        // Git repo exists - pull latest changes
        exec("git pull origin '$branch'", $output, $returnVar);
    }

    // Log the git status to deploy.txt
    $statusOutput = [];
    exec("git status", $statusOutput);
    $logEntry = "---- Deployment executed on " . date('Y-m-d H:i:s') . " ----\n" .
                implode("\n", $statusOutput) . "\n" .
                "-------------------------------------------\n";

    file_put_contents("$repoDir/deploy.txt", $logEntry, FILE_APPEND);

    if ($returnVar === 0) {
        echo "Deployment successful.\n\n";
        echo implode("\n", $output);
    } else {
        http_response_code(500);
        echo "Deployment failed.\n\n";
        echo implode("\n", $output);
    }
}
?>
