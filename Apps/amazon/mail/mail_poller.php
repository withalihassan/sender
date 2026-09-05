<?php
if (PHP_SAPI !== 'cli') {
    exit;
}

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/mail_poll_service.php';

ensure_amazon_tables($pdo);

$runId = (int) ($argv[1] ?? 0);

if ($runId <= 0) {
    exit;
}

while (true) {
    $run = mail_get_run($pdo, $runId);

    if (!$run || (int) $run['stop_requested'] === 1 || $run['status'] === 'Stopped') {
        mail_update_run($pdo, $runId, 'Stopped', 'Stopped');
        exit;
    }

    try {
        mail_poll_once($pdo, $runId);
    } catch (Exception $e) {
        mail_update_run($pdo, $runId, 'Running', 'Temporary mail polling issue - retrying', $e->getMessage());
    }

    sleep(1);
}
?>
