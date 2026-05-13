<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

$service = new ReportService(Database::connection());
$result = $service->createAndSend('weekly');
$delivery = $result['delivery'];

echo '[weekly-report] id=' . (int) $result['id']
    . ' email=' . (string) $delivery['email_status']
    . ' telegram=' . (string) $delivery['telegram_status']
    . "\n";
