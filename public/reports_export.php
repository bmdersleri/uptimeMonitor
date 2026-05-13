<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_auth();

$pdo = Database::connection();
$service = new ReportService($pdo);
$type = (string) ($_GET['type'] ?? 'all');
$runs = $service->exportRuns($type);

$safeType = $type === 'daily' || $type === 'weekly' ? $type : 'all';
$filename = 'report-runs-' . $safeType . '-' . (new DateTimeImmutable('now'))->format('Ymd-His') . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('X-Content-Type-Options: nosniff');

$out = fopen('php://output', 'wb');
if ($out === false) {
    http_response_code(500);
    exit;
}

fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, [
    'id',
    'report_type',
    'period_start',
    'period_end',
    'subject',
    'email_status',
    'telegram_status',
    'created_at',
    'sent_at',
    'email_error',
    'telegram_error',
]);

foreach ($runs as $run) {
    fputcsv($out, [
        (int) ($run['id'] ?? 0),
        (string) ($run['report_type'] ?? ''),
        (string) ($run['period_start'] ?? ''),
        (string) ($run['period_end'] ?? ''),
        (string) ($run['subject'] ?? ''),
        (string) ($run['email_status'] ?? ''),
        (string) ($run['telegram_status'] ?? ''),
        (string) ($run['created_at'] ?? ''),
        (string) ($run['sent_at'] ?? ''),
        (string) ($run['email_error'] ?? ''),
        (string) ($run['telegram_error'] ?? ''),
    ]);
}

fclose($out);
