<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

function assert_true_mail(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$client = new MailClient();
$message = $client->composeMessage(
    'noreply@example.com',
    'Uptime Monitor',
    'ops@example.com',
    'Subject Line',
    "Plain body",
    '<p>HTML body</p>'
);

assert_true_mail(in_array('From: Uptime Monitor <noreply@example.com>', $message['headers'], true), 'From header should be present');
assert_true_mail(in_array('To: ops@example.com', $message['headers'], true), 'To header should be present');
assert_true_mail(in_array('Subject: Subject Line', $message['headers'], true), 'Subject header should be present');
assert_true_mail(strpos($message['body'], 'Content-Type: text/html; charset=UTF-8') !== false, 'HTML part should be present');
assert_true_mail(strpos($message['body'], 'Plain body') !== false, 'Plain body should be present');

echo "MailClientTest OK\n";
