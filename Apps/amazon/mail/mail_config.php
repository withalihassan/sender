<?php
function smtpdev_token()
{
    return getenv('SMTPDEV_TOKEN') ?: '';
}

function smtpdev_base_url()
{
    return getenv('SMTPDEV_BASE') ?: 'https://api.smtp.dev';
}

function mock_allowed_hosts()
{
    $hosts = getenv('MOCK_VERIFY_ALLOWED_HOSTS') ?: 'localhost,127.0.0.1,::1';
    return array_filter(array_map('trim', explode(',', $hosts)));
}

function mock_button_selector()
{
    return getenv('MOCK_VERIFY_BUTTON_SELECTOR') ?: '[data-testid="mock-verify-button"]';
}
?>
