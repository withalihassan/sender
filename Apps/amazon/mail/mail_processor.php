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
