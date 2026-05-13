<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

$pdo = Database::connection();
$notifier = new Notifier($pdo);
$limit = (int) config('NOTIFY_RETRY_BATCH_SIZE', 30);
$result = $notifier->processRetryQueue($limit);

echo "[retry] processed={$result['processed']} sent={$result['sent']} failed={$result['failed']}\n";
