<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_auth();

$pdo = Database::connection();
$service = new ReportService($pdo);

function reports_export_normalize_date_filter(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$dt instanceof DateTimeImmutable || !is_array($errors) || ($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0) {
        return null;
    }

    return $dt->format('Y-m-d');
}

$type = (string) ($_GET['report_type'] ?? ($_GET['type'] ?? 'all'));
if ($type !== 'daily' && $type !== 'weekly') {
    $type = 'all';
}
$filters = [];
if ($type !== 'all') {
    $filters['report_type'] = $type;
}
$fromDate = reports_export_normalize_date_filter((string) ($_GET['from'] ?? ''));
if ($fromDate !== null) {
    $filters['from'] = $fromDate;
}
$toDate = reports_export_normalize_date_filter((string) ($_GET['to'] ?? ''));
if ($toDate !== null) {
    $filters['to'] = $toDate;
}

$runs = $service->exportRuns($type, $filters);

$filenameType = $type;
if ($fromDate !== null || $toDate !== null) {
    $filenameType .= '-filtered';
}
$filename = 'report-runs-' . $filenameType . '-' . (new DateTimeImmutable('now'))->format('Ymd-His') . '.csv';

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
