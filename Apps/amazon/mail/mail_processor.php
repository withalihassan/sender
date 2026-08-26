<?php
function mail_text_from_message($message)
{
    $parts = [];

    foreach (['subject', 'text', 'body', 'plain', 'plainText', 'content', 'message', 'snippet'] as $key) {
        if (!empty($message[$key]) && is_string($message[$key])) {
            $parts[] = $message[$key];
        }
    }

    if (!empty($message['html']) && is_string($message['html'])) {
        $parts[] = html_entity_decode(strip_tags($message['html']), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $parts[] = $message['html'];
    }

    return implode("\n", $parts);
}

function extract_verification_url($text)
{
    if (!preg_match_all('/https?:\/\/[^\s"\'<>]+/i', $text, $matches)) {
        return null;
    }

    foreach ($matches[0] as $url) {
        $cleanUrl = rtrim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'), '.,);]');

        if (is_mock_verification_url($cleanUrl)) {
            return $cleanUrl;
        }
    }

    return null;
}
?>
