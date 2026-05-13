<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

$pdo = Database::connection();
$runner = new LinkScanRunner($pdo);
$batchSize = (int) config('LINK_SCAN_BATCH_SIZE', 5);

$logs = $runner->runDueMonitors(max(1, $batchSize));
foreach ($logs as $line) {
    echo $line . "\n";
}
