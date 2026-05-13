<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

if (isset($_SERVER['REQUEST_METHOD'])) {
    http_response_code(403);
    exit(1);
}

$monitorId = isset($argv[1]) ? (int) $argv[1] : 0;
$maxDepth = isset($argv[2]) ? max(1, (int) $argv[2]) : null;

if ($monitorId < 1) {
    manual_link_scan_stderr('Missing or invalid monitor id.');
    exit(1);
}

@set_time_limit(0);

try {
    manual_link_scan_stderr(sprintf(
        'Starting manual link scan monitor_id=%d max_depth=%s sapi=%s',
        $monitorId,
        $maxDepth !== null ? (string) $maxDepth : 'default',
        PHP_SAPI
    ));

    $pdo = Database::connection();
    $runner = new LinkScanRunner($pdo);
    $result = $runner->runMonitorById($monitorId, 'manual', $maxDepth);

    if ((bool) ($result['ok'] ?? false)) {
        manual_link_scan_stderr((string) ($result['log_line'] ?? 'Manual link scan completed.'));
        exit(0);
    }

    manual_link_scan_stderr((string) ($result['message'] ?? 'Manual link scan failed.'));
    exit(1);
} catch (Throwable $e) {
    manual_link_scan_stderr(get_class($e) . ': ' . $e->getMessage());
    exit(1);
}

function manual_link_scan_stderr(string $message): void
{
    $line = '[' . (new DateTimeImmutable('now'))->format('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;

    if (defined('STDERR')) {
        fwrite(STDERR, $line);
        return;
    }

    $stream = @fopen('php://stderr', 'wb');
    if ($stream !== false) {
        fwrite($stream, $line);
        fclose($stream);
    }
}
