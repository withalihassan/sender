<?php
require_once __DIR__ . '/mail_config.php';

function is_mock_verification_url($url)
{
    $host = parse_url($url, PHP_URL_HOST);

    if (!$host || in_array($host, ['amazon.com', 'aws.amazon.com'], true) || str_ends_with($host, '.amazon.com')) {
        return false;
    }

    return in_array($host, mock_allowed_hosts(), true);
}

function http_fetch_page($url, $method = 'GET')
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_USERAGENT, 'amazon-mock-browser/1.0');

    $body = curl_exec($ch);
    $error = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($error || $code < 200 || $code >= 400) {
        throw new Exception('Mock webpage could not be opened.');
    }

    return $body;
}

function find_test_button_action($html, $baseUrl)
{
    $selector = mock_button_selector();

    if (!preg_match('/^\[data-testid=["\']([^"\']+)["\']\]$/', $selector, $match)) {
        throw new Exception('Only data-testid selectors are supported by this test browser.');
    }

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    $nodes = $xpath->query('//*[@data-testid="' . $match[1] . '"]');

    if (!$nodes || $nodes->length === 0) {
        throw new Exception('Mock test button was not found.');
    }

    $node = $nodes->item(0);
    $href = $node->getAttribute('href');

    if ($href !== '') {
        return ['url' => absolute_url($href, $baseUrl), 'method' => 'GET'];
    }

    $form = $node;
    while ($form && strtolower($form->nodeName) !== 'form') {
        $form = $form->parentNode;
    }

    if ($form && strtolower($form->nodeName) === 'form') {
        $action = $form->getAttribute('action') ?: $baseUrl;
        $method = strtoupper($form->getAttribute('method') ?: 'GET');
        return ['url' => absolute_url($action, $baseUrl), 'method' => $method === 'POST' ? 'POST' : 'GET'];
    }

    throw new Exception('Mock test button has no clickable action.');
}

function absolute_url($url, $baseUrl)
{
    if (preg_match('/^https?:\/\//i', $url)) {
        return $url;
    }

    $parts = parse_url($baseUrl);
    $scheme = $parts['scheme'] ?? 'http';
    $host = $parts['host'] ?? '';
    $port = isset($parts['port']) ? ':' . $parts['port'] : '';

    if (str_starts_with($url, '/')) {
        return $scheme . '://' . $host . $port . $url;
    }

    $path = isset($parts['path']) ? dirname($parts['path']) : '';
    return $scheme . '://' . $host . $port . rtrim($path, '/') . '/' . $url;
}

function open_mock_page_and_click_button($url)
{
    if (!is_mock_verification_url($url)) {
        throw new Exception('Only configured mock verification URLs are allowed.');
    }

    $html = http_fetch_page($url);
    $action = find_test_button_action($html, $url);

    if (!is_mock_verification_url($action['url'])) {
        throw new Exception('Mock button action is not allowed.');
    }

    http_fetch_page($action['url'], $action['method']);
}
?>
