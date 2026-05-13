<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

$monitorId = isset($argv[1]) ? (int) $argv[1] : 0;
$maxDepth = isset($argv[2]) ? max(1, (int) $argv[2]) : null;

if ($monitorId < 1) {
    exit(1);
}

@set_time_limit(0);

$pdo = Database::connection();
$runner = new LinkScanRunner($pdo);
$result = $runner->runMonitorById($monitorId, 'manual', $maxDepth);

exit((bool) ($result['ok'] ?? false) ? 0 : 1);
