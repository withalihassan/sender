<?php
function mail_process_pending_browser_automation($pdo, $accountId)
{
    $stmt = $pdo->prepare("
        SELECT id, verification_url
        FROM mail_execution_events
        WHERE account_id = ?
          AND sender = 'recover-mfa-no-reply@verify.signin.aws'
          AND verification_url IS NOT NULL
          AND verification_url <> ''
          AND button_clicked = 0
          AND (browser_automation_status IS NULL OR browser_automation_status = '' OR browser_automation_status = 'Pending')
        ORDER BY id ASC
        LIMIT 1
    ");
    $stmt->execute([(int) $accountId]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $event) {
        mail_run_browser_automation_for_event($pdo, (int) $event['id'], $event['verification_url']);
    }
}

function mail_run_browser_automation_for_event($pdo, $eventId, $url)
{
    mail_update_browser_automation($pdo, $eventId, 'Running', 0, null);

    try {
        $result = mail_execute_playwright_click($url);

        if (!empty($result['success'])) {
            mail_update_browser_automation($pdo, $eventId, 'Completed', 1, $result['message'] ?? null);
            return;
        }

        mail_update_browser_automation($pdo, $eventId, 'Failed', 0, $result['message'] ?? 'Browser automation failed.');
    } catch (Exception $e) {
        mail_update_browser_automation($pdo, $eventId, 'Failed', 0, $e->getMessage());
    }
}

function mail_update_browser_automation($pdo, $eventId, $status, $clicked, $error)
{
    $stmt = $pdo->prepare("
        UPDATE mail_execution_events
        SET browser_automation_status = ?, button_clicked = ?, browser_automation_error = ?, updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$status, (int) $clicked, $error, $eventId]);
}

function mail_execute_playwright_click($url)
{
    $node = mail_find_node_binary();
    $script = __DIR__ . '/playwright_click_button.js';

    if ($node === '') {
        return ['success' => false, 'message' => 'Node.js was not found.'];
    }

    if (!is_file($script)) {
        return ['success' => false, 'message' => 'Playwright click script is missing.'];
    }

    $command = escapeshellarg($node) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($url);
    $descriptors = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes, dirname(__DIR__, 3));

    if (!is_resource($process)) {
        throw new Exception('Unable to start Playwright process.');
    }

    $started = time();
    $stdout = '';
    $stderr = '';

    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    while (true) {
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        $status = proc_get_status($process);

        if (!$status['running']) {
            break;
        }

        if (time() - $started > 90) {
            proc_terminate($process);
            throw new Exception('Playwright timed out waiting for the SMS button.');
        }

        usleep(200000);
    }

    foreach ($pipes as $pipe) {
        fclose($pipe);
    }

    $exitCode = proc_close($process);
    $json = json_decode(trim($stdout), true);

    if (is_array($json)) {
        return $json;
    }

    return [
        'success' => false,
        'message' => 'Playwright failed with exit code ' . $exitCode . '. ' . trim($stdout . ' ' . $stderr),
    ];
}

function mail_find_node_binary()
{
    $candidates = [
        getenv('NODE_PATH') ?: '',
        getenv('NODE_BINARY') ?: '',
        '/usr/local/bin/node',
        '/usr/bin/node',
        '/bin/node',
        '/snap/bin/node',
        '/usr/bin/nodejs',
        '/bin/nodejs',
        '/usr/local/bin/nodejs',
    ];

    foreach ($candidates as $candidate) {
        if ($candidate !== '' && (is_file($candidate) || is_link($candidate))) {
            return $candidate;
        }
    }

    foreach (['node', 'nodejs'] as $binary) {
        $path = trim((string) shell_exec('command -v ' . $binary . ' 2>/dev/null'));

        if ($path !== '' && (is_file($path) || is_link($path))) {
            return $path;
        }
    }

    return '';
}
?>
