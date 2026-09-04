<?php
function mail_process_pending_button_clicks($pdo, $accountId)
{
    $stmt = $pdo->prepare("
        SELECT id, verification_url
        FROM mail_execution_events
        WHERE account_id = ?
          AND verification_url IS NOT NULL
          AND verification_url <> ''
          AND (
              button_click_status IS NULL
              OR button_click_status = ''
              OR button_click_status IN ('Pending', 'Button Not Found', 'Button Found', 'Click Failed')
          )
        ORDER BY id ASC
        LIMIT 5
    ");
    $stmt->execute([(int) $accountId]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $event) {
        mail_click_event_button($pdo, (int) $event['id'], $event['verification_url']);
    }
}

function mail_click_event_button($pdo, $eventId, $url)
{
    mail_update_button_click($pdo, $eventId, 'Processing', null);

    try {
        $result = mail_browser_click_sms_button($eventId, $url);

        if (!empty($result['success'])) {
            mail_update_button_click($pdo, $eventId, 'Clicked Done', null);
            return;
        }

        mail_update_button_click($pdo, $eventId, 'Button Not Found', $result['message'] ?? 'Browser could not click SMS button.');
    } catch (Exception $e) {
        mail_update_button_click($pdo, $eventId, 'Click Failed', $e->getMessage());
    }
}

function mail_update_button_click($pdo, $eventId, $status, $error)
{
    $stmt = $pdo->prepare("
        UPDATE mail_execution_events
        SET button_clicked = ?, button_click_status = ?, button_click_error = ?, updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$status === 'Clicked Done' ? 1 : 0, $status, $error, $eventId]);
}

function mail_loaded_html_relative_path($eventId)
{
    $name = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $eventId);
    return 'data/mail-loaded-pages/event-' . $name . '.html';
}

function mail_loaded_html_full_path($eventId)
{
    return __DIR__ . '/../' . mail_loaded_html_relative_path($eventId);
}

function mail_save_loaded_html($eventId, $html)
{
    $path = mail_loaded_html_full_path($eventId);
    $dir = dirname($path);

    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    file_put_contents($path, $html);
}

function mail_browser_click_sms_button($eventId, $url)
{
    $node = mail_find_node_binary();
    $script = __DIR__ . '/click_sms_button.js';

    if ($node === '') {
        return [
            'success' => false,
            'message' => 'Node.js was not found. Install node on the server or set NODE_PATH to the full node binary path.',
        ];
    }

    if (!is_file($script)) {
        throw new Exception('Browser click script is missing.');
    }

    $command = escapeshellarg($node) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($url) . ' ' . escapeshellarg((string) $eventId);
    $descriptors = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes);

    if (!is_resource($process)) {
        throw new Exception('Unable to start browser click process.');
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

        if (time() - $started > 45) {
            proc_terminate($process);
            throw new Exception('Browser click timed out waiting for the SMS button.');
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
        'message' => 'Browser click failed with exit code ' . $exitCode . '. ' . trim($stdout . ' ' . $stderr),
    ];
}

function mail_find_node_binary()
{
    $candidates = [
        getenv('NODE_PATH') ?: '',
        '/usr/local/bin/node',
        '/usr/bin/node',
        '/bin/node',
        '/snap/bin/node',
        '/usr/bin/nodejs',
        '/bin/nodejs',
    ];

    foreach ($candidates as $candidate) {
        if ($candidate !== '' && is_file($candidate) && is_executable($candidate)) {
            return $candidate;
        }
    }

    $path = trim((string) shell_exec('command -v node 2>/dev/null'));

    if ($path !== '' && is_file($path) && is_executable($path)) {
        return $path;
    }

    $path = trim((string) shell_exec('command -v nodejs 2>/dev/null'));

    if ($path !== '' && is_file($path) && is_executable($path)) {
        return $path;
    }

    return '';
}
?>
