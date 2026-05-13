<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

$service = new ReportService(Database::connection());
$result = $service->createAndSend('daily');
$delivery = $result['delivery'];

echo '[daily-report] id=' . (int) $result['id']
    . ' email=' . (string) $delivery['email_status']
    . ' telegram=' . (string) $delivery['telegram_status']
    . "\n";
