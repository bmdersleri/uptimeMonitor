<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

function assert_true_config(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$tempPath = tempnam(sys_get_temp_dir(), 'uptime_env_');
if ($tempPath === false) {
    throw new RuntimeException('Temp env file could not be created');
}

$original = <<<ENV
# comment
APP_NAME=Uptime Monitor
NOTIFY_EMAIL_ENABLED=false
NOTIFY_EMAIL_TO=old@example.com
NOTIFY_TELEGRAM_ENABLED=false
TELEGRAM_BOT_TOKEN=old-token
TELEGRAM_DEFAULT_CHAT_ID=old-chat
ENV;

file_put_contents($tempPath, $original . PHP_EOL);

try {
    config_write_env_file($tempPath, [
        'NOTIFY_EMAIL_ENABLED' => 'true',
        'NOTIFY_EMAIL_TO' => 'ops@example.com',
        'NOTIFY_TELEGRAM_ENABLED' => 'true',
        'TELEGRAM_BOT_TOKEN' => 'new-token',
        'TELEGRAM_DEFAULT_CHAT_ID' => '987654321',
    ]);

    $content = file_get_contents($tempPath);
    assert_true_config(is_string($content), 'Updated env file should be readable');
    assert_true_config(strpos($content, '# comment') !== false, 'Comments should be preserved');
    assert_true_config(strpos($content, 'NOTIFY_EMAIL_ENABLED=true') !== false, 'Email enabled flag should be updated');
    assert_true_config(strpos($content, 'NOTIFY_EMAIL_TO=ops@example.com') !== false, 'Email target should be updated');
    assert_true_config(strpos($content, 'NOTIFY_TELEGRAM_ENABLED=true') !== false, 'Telegram enabled flag should be updated');
    assert_true_config(strpos($content, 'TELEGRAM_BOT_TOKEN=new-token') !== false, 'Telegram token should be updated');
    assert_true_config(strpos($content, 'TELEGRAM_DEFAULT_CHAT_ID=987654321') !== false, 'Telegram chat id should be updated');
} finally {
    @unlink($tempPath);
}

echo "ConfigEnvWriteTest OK\n";
