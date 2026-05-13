<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

function assert_true_cancel_stale(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA foreign_keys = ON');
$schema = file_get_contents(__DIR__ . '/../database/schema.sql');
if ($schema === false) {
    throw new RuntimeException('schema.sql okunamadi');
}
$pdo->exec($schema);
$pdo->exec("
    INSERT INTO monitors (name, url, expected_status, is_active, link_scan_enabled, link_scan_max_depth, link_scan_max_urls, next_link_scan_at)
    VALUES ('Stale Job', 'https://example.com', '200', 1, 1, 3, 120, datetime('now'))
");
$monitorId = (int) $pdo->lastInsertId();
$pdo->prepare("
    INSERT INTO link_scan_jobs (monitor_id, started_at, status, checked_urls, broken_urls, created_at)
    VALUES (:monitor_id, datetime('now', '-15 minutes'), 'running', 42, 3, datetime('now', '-15 minutes'))
")->execute(['monitor_id' => $monitorId]);
$jobId = (int) $pdo->lastInsertId();

$repo = new LinkScanRepository($pdo);
$closed = $repo->cancelRunningJob($jobId, 'Canceled by user');

assert_true_cancel_stale($closed === true, 'Cancel should close a running job');

$job = $pdo->query('SELECT * FROM link_scan_jobs WHERE id = ' . $jobId)->fetch();
assert_true_cancel_stale(is_array($job), 'Job should still exist');
assert_true_cancel_stale((string) $job['status'] === 'failed', 'Canceled stale job should be marked failed');
assert_true_cancel_stale((string) $job['error_message'] === 'Canceled by user', 'Cancel reason should be stored');
assert_true_cancel_stale($job['finished_at'] !== null, 'Canceled stale job should have finished_at');
assert_true_cancel_stale($job['duration_seconds'] !== null, 'Canceled stale job should have duration');
assert_true_cancel_stale($repo->findRunningWithMonitor($monitorId) === null, 'Canceled stale job should no longer block new scans');

echo "LinkScanCancelStaleJobTest OK\n";
