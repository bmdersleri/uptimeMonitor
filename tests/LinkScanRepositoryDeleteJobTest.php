<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

function assert_true_delete_job(bool $condition, string $message): void
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
    INSERT INTO monitors (name, url, expected_status, is_active, link_scan_enabled, next_link_scan_at)
    VALUES ('Delete Job', 'https://example.com', '200', 1, 1, datetime('now'))
");
$monitorId = (int) $pdo->lastInsertId();
$pdo->prepare("
    INSERT INTO link_scan_jobs (monitor_id, started_at, finished_at, status, checked_urls, broken_urls, created_at)
    VALUES (:monitor_id, datetime('now', '-10 minutes'), datetime('now', '-9 minutes'), 'completed', 12, 1, datetime('now', '-10 minutes'))
")->execute(['monitor_id' => $monitorId]);
$completedJobId = (int) $pdo->lastInsertId();
$pdo->prepare("
    INSERT INTO link_scan_jobs (monitor_id, started_at, status, checked_urls, broken_urls, created_at)
    VALUES (:monitor_id, datetime('now', '-5 minutes'), 'running', 4, 0, datetime('now', '-5 minutes'))
")->execute(['monitor_id' => $monitorId]);
$runningJobId = (int) $pdo->lastInsertId();

$repo = new LinkScanRepository($pdo);

assert_true_delete_job($repo->deleteJob($completedJobId) === true, 'Completed job should be deleted');
assert_true_delete_job($repo->findWithMonitor($completedJobId) === null, 'Deleted completed job should not be found');
assert_true_delete_job($repo->deleteJob($runningJobId) === true, 'Running job row should be deletable');
assert_true_delete_job($repo->findRunningWithMonitor($monitorId) === null, 'Deleted running job should no longer block scans');
assert_true_delete_job($repo->deleteJob(9999) === false, 'Deleting a missing job should report false');

echo "LinkScanRepositoryDeleteJobTest OK\n";
