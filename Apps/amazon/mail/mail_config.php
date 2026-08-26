<?php
function load_cli_env_from_htaccess()
{
    static $loaded = false;

    if ($loaded || PHP_SAPI !== 'cli') {
        return;
    }

    $loaded = true;
    $path = __DIR__ . '/../.htaccess';

    if (!is_readable($path)) {
        return;
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
        if (preg_match('/^SetEnv\s+(SMTPDEV_TOKEN|SMTPDEV_BASE|MOCK_VERIFY_ALLOWED_HOSTS|MOCK_VERIFY_BUTTON_SELECTOR|MOCK_VERIFY_SECOND_BUTTON_SELECTOR|MOCK_VERIFY_BUTTON_TEXT|MOCK_VERIFY_SECOND_BUTTON_TEXT)\s+(.+)$/', trim($line), $match)) {
            putenv($match[1] . '=' . $match[2]);
        }
    }
}

function smtpdev_token()
{
    load_cli_env_from_htaccess();
    return getenv('SMTPDEV_TOKEN') ?: '';
}

function smtpdev_base_url()
{
    load_cli_env_from_htaccess();
    return getenv('SMTPDEV_BASE') ?: 'https://api.smtp.dev';
}

function mock_allowed_hosts()
{
    load_cli_env_from_htaccess();
    $hosts = getenv('MOCK_VERIFY_ALLOWED_HOSTS') ?: 'localhost,127.0.0.1,::1';
    return array_filter(array_map('trim', explode(',', $hosts)));
}

function mock_button_selector()
{
    load_cli_env_from_htaccess();
    return getenv('MOCK_VERIFY_BUTTON_SELECTOR') ?: '#emailVerificationUrl';
}

function mock_second_button_selector()
{
    load_cli_env_from_htaccess();
    return getenv('MOCK_VERIFY_SECOND_BUTTON_SELECTOR') ?: '';
}

function mock_button_text()
{
    load_cli_env_from_htaccess();
    return getenv('MOCK_VERIFY_BUTTON_TEXT') ?: 'Verify email';
}

function mock_second_button_text()
{
    load_cli_env_from_htaccess();
    return getenv('MOCK_VERIFY_SECOND_BUTTON_TEXT') ?: '';
}
?>
