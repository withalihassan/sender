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
    $cookieFile = tempnam(sys_get_temp_dir(), 'amazon_mail_cookie_');

    try {
        $page = mail_http_request($url, 'GET', [], $cookieFile);
        $button = mail_find_target_button($page['body']);

        if (!$button) {
            mail_update_button_click($pdo, $eventId, 'Button Not Found', mail_button_debug_summary($page));
            return;
        }

        $click = mail_build_button_click_request($button, $page['url']);

        if (!$click) {
            mail_update_button_click($pdo, $eventId, 'Button Found', 'Button found, but no server-side form action was available to submit.');
            return;
        }

        mail_http_request($click['url'], $click['method'], $click['fields'], $page['cookie_file']);
        mail_update_button_click($pdo, $eventId, 'Clicked Done', null);
    } catch (Exception $e) {
        mail_update_button_click($pdo, $eventId, 'Click Failed', $e->getMessage());
    } finally {
        if ($cookieFile && file_exists($cookieFile)) {
            unlink($cookieFile);
        }
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

function mail_http_request($url, $method = 'GET', $fields = [], $cookieFile = null)
{
    $cookieFile = $cookieFile ?: tempnam(sys_get_temp_dir(), 'amazon_mail_cookie_');
    $ch = curl_init($url);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36');

    if (strtoupper($method) === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    }

    $body = curl_exec($ch);
    $error = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $url;
    curl_close($ch);

    if ($body === false || $error !== '') {
        throw new Exception('Page load failed: ' . $error);
    }

    if ($code < 200 || $code >= 400) {
        throw new Exception('Page load returned HTTP ' . $code);
    }

    return ['body' => $body, 'url' => $finalUrl, 'cookie_file' => $cookieFile];
}

function mail_find_target_button($html)
{
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    $nodes = $xpath->query('//button[@data-testid="send-sms-message-button"]');

    if ($nodes && $nodes->length > 0) {
        return $nodes->item(0);
    }

    $nodes = $xpath->query('//button[@type="submit" and (.//*[normalize-space(.) = "SMS text"] or normalize-space(.) = "SMS text")]');

    if ($nodes && $nodes->length > 0) {
        return $nodes->item(0);
    }

    return null;
}

function mail_button_debug_summary($page)
{
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML($page['body']);
    $xpath = new DOMXPath($dom);
    $titleNode = $xpath->query('//title')->item(0);
    $title = $titleNode ? trim($titleNode->textContent) : '';
    $buttons = [];

    foreach ($xpath->query('//button') as $button) {
        $text = trim(preg_replace('/\s+/', ' ', $button->textContent));
        $testId = trim($button->getAttribute('data-testid'));

        if ($testId !== '' || $text !== '') {
            $buttons[] = ($testId !== '' ? 'data-testid=' . $testId : 'no-testid') . ($text !== '' ? ' text=' . $text : '');
        }

        if (count($buttons) >= 5) {
            break;
        }
    }

    $parts = ['Target SMS button was not found in loaded page source.'];

    if (!empty($page['url'])) {
        $parts[] = 'Final URL: ' . $page['url'];
    }

    if ($title !== '') {
        $parts[] = 'Title: ' . $title;
    }

    if ($buttons) {
        $parts[] = 'Buttons found: ' . implode(' | ', $buttons);
    } else {
        $parts[] = 'Buttons found: none';
    }

    return implode(' ', $parts);
}

function mail_build_button_click_request($button, $pageUrl)
{
    $form = $button;

    while ($form && strtolower($form->nodeName) !== 'form') {
        $form = $form->parentNode;
    }

    if (!$form || strtolower($form->nodeName) !== 'form') {
        $formAction = $button->getAttribute('formaction');

        if ($formAction === '') {
            return null;
        }

        return [
            'url' => mail_absolute_url($formAction, $pageUrl),
            'method' => strtoupper($button->getAttribute('formmethod') ?: 'GET'),
            'fields' => [],
        ];
    }

    $method = strtoupper($button->getAttribute('formmethod') ?: $form->getAttribute('method') ?: 'GET');
    $action = $button->getAttribute('formaction') ?: $form->getAttribute('action') ?: $pageUrl;
    $fields = mail_form_fields($form);

    if ($button->getAttribute('name') !== '') {
        $fields[$button->getAttribute('name')] = $button->getAttribute('value');
    }

    $targetUrl = mail_absolute_url($action, $pageUrl);

    if ($method === 'GET' && $fields) {
        $targetUrl .= (strpos($targetUrl, '?') === false ? '?' : '&') . http_build_query($fields);
        $fields = [];
    }

    return ['url' => $targetUrl, 'method' => $method, 'fields' => $fields];
}

function mail_form_fields($form)
{
    $fields = [];

    foreach ($form->getElementsByTagName('input') as $input) {
        $name = $input->getAttribute('name');

        if ($name === '') {
            continue;
        }

        $type = strtolower($input->getAttribute('type'));

        if (in_array($type, ['submit', 'button', 'reset', 'file'], true)) {
            continue;
        }

        if (in_array($type, ['checkbox', 'radio'], true) && !$input->hasAttribute('checked')) {
            continue;
        }

        $fields[$name] = $input->getAttribute('value');
    }

    return $fields;
}

function mail_absolute_url($url, $base)
{
    if (preg_match('/^https?:\/\//i', $url)) {
        return $url;
    }

    $parts = parse_url($base);
    $scheme = $parts['scheme'] ?? 'https';
    $host = $parts['host'] ?? '';
    $port = isset($parts['port']) ? ':' . $parts['port'] : '';

    if (strpos($url, '//') === 0) {
        return $scheme . ':' . $url;
    }

    if (strpos($url, '/') === 0) {
        return $scheme . '://' . $host . $port . $url;
    }

    $path = $parts['path'] ?? '/';
    $dir = rtrim(substr($path, 0, strrpos($path, '/') ?: 0), '/');

    return $scheme . '://' . $host . $port . $dir . '/' . $url;
}
?>
