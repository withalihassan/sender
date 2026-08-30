<?php
function mail_text_from_message($message)
{
    $parts = [];

    foreach (['subject', 'intro', 'text', 'body', 'plain', 'plainText', 'content', 'message', 'snippet'] as $key) {
        mail_collect_text_parts($message[$key] ?? null, $parts, false);
    }

    mail_collect_text_parts($message['html'] ?? null, $parts, true);

    $parts = array_values(array_filter(array_map('trim', $parts)));
    return implode("\n\n", array_unique($parts));
}

function mail_html_from_message($message)
{
    $parts = [];
    mail_collect_raw_parts($message['html'] ?? null, $parts);
    return implode("\n", array_filter(array_map('trim', $parts)));
}

function mail_collect_raw_parts($value, &$parts)
{
    if (is_string($value) && trim($value) !== '') {
        $parts[] = $value;
        return;
    }

    if (!is_array($value)) {
        return;
    }

    foreach ($value as $item) {
        mail_collect_raw_parts($item, $parts);
    }
}

function mail_verification_url_from_message($message)
{
    $html = mail_html_from_message($message);

    if ($html !== '') {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML($html);
        $xpath = new DOMXPath($dom);
        $node = $xpath->query('//*[@id="emailVerificationUrl"]')->item(0);

        if ($node && $node->getAttribute('href') !== '') {
            return html_entity_decode($node->getAttribute('href'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
    }

    $source = $html . "\n" . mail_text_from_message($message);

    if (preg_match('/https:\/\/signin\.aws\.amazon\.com\/noMfa\?[^"\'<>\s]+/i', $source, $match)) {
        return html_entity_decode(rtrim($match[0], '.,);]'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    return '';
}

function mail_collect_text_parts($value, &$parts, $isHtml)
{
    if (is_string($value) && trim($value) !== '') {
        $parts[] = $isHtml
            ? html_entity_decode(trim(strip_tags($value)), ENT_QUOTES | ENT_HTML5, 'UTF-8')
            : html_entity_decode(trim($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return;
    }

    if (!is_array($value)) {
        return;
    }

    foreach ($value as $item) {
        mail_collect_text_parts($item, $parts, $isHtml);
    }
}

function mail_value_from_message($message, $keys)
{
    foreach ($keys as $key) {
        if (!empty($message[$key]) && is_string($message[$key])) {
            return $message[$key];
        }

        if (!empty($message[$key]['address']) && is_string($message[$key]['address'])) {
            return $message[$key]['address'];
        }

        if (!empty($message[$key]['email']) && is_string($message[$key]['email'])) {
            return $message[$key]['email'];
        }

        if (!empty($message[$key]) && is_array($message[$key])) {
            $first = reset($message[$key]);

            if (is_string($first)) {
                return $first;
            }

            if (is_array($first)) {
                if (!empty($first['address'])) {
                    return $first['address'];
                }

                if (!empty($first['email'])) {
                    return $first['email'];
                }
            }
        }
    }

    return '';
}

function mail_subject_from_message($message)
{
    return mail_value_from_message($message, ['subject', 'Subject']);
}

function mail_sender_from_message($message)
{
    return mail_value_from_message($message, ['from', 'sender', 'mailFrom', 'envelopeFrom']);
}

function mail_recipient_from_message($message)
{
    return mail_value_from_message($message, ['to', 'recipient', 'recipients', 'mailTo', 'envelopeTo']);
}
?>
