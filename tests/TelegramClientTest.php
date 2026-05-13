<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

function assert_true_telegram(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$client = new TelegramClient();

assert_true_telegram(
    $client->buildEndpoint('123456:ABC-def_ghi') === 'https://api.telegram.org/bot123456:ABC-def_ghi/sendMessage',
    'Telegram token should not be URL-encoded in the endpoint'
);

$result = $client->sendMessage('', '', 'test');
assert_true_telegram(($result['status'] ?? '') === 'failed', 'Missing Telegram config should fail');
assert_true_telegram(($result['error'] ?? '') === 'Telegram config missing', 'Missing Telegram config error should be explicit');

echo "TelegramClientTest OK\n";
