<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

function assert_true_scan_mgmt(bool $condition, string $message): void
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
    VALUES ('Managed Jobs', 'https://example.com', '200', 1, 1, datetime('now'))
");
$monitorId = (int) $pdo->lastInsertId();

for ($i = 1; $i <= 6; $i++) {
    $daysAgo = $i <= 3 ? $i : 40 + $i;
    $pdo->prepare("
        INSERT INTO link_scan_jobs (monitor_id, started_at, finished_at, status, checked_urls, broken_urls, created_at)
        VALUES (:monitor_id, datetime('now', :started_mod), datetime('now', :finished_mod), 'completed', :checked, 0, datetime('now', :started_mod))
    ")->execute([
        'monitor_id' => $monitorId,
        'started_mod' => '-' . $daysAgo . ' days',
        'finished_mod' => '-' . $daysAgo . ' days',
        'checked' => $i,
    ]);
}

$pdo->prepare("
    INSERT INTO link_scan_jobs (monitor_id, started_at, status, checked_urls, broken_urls, created_at)
    VALUES (:monitor_id, datetime('now', '-2 hours'), 'running', 9, 1, datetime('now', '-2 hours'))
")->execute(['monitor_id' => $monitorId]);
$staleJobId = (int) $pdo->lastInsertId();

$repo = new LinkScanRepository($pdo);

$pageOne = $repo->listWithMonitor(['monitor_id' => $monitorId, 'days' => 7], 2, 0);
$pageTwo = $repo->listWithMonitor(['monitor_id' => $monitorId, 'days' => 7], 2, 2);

assert_true_scan_mgmt(count($pageOne) === 2, 'First filtered page should contain two rows');
assert_true_scan_mgmt(count($pageTwo) === 2, 'Second filtered page should contain remaining recent row plus running row');
assert_true_scan_mgmt($repo->countWithMonitor(['monitor_id' => $monitorId, 'days' => 7]) === 4, 'Date filter should count recent jobs only');

$closed = $repo->closeStaleRunningJobs(60);
assert_true_scan_mgmt($closed === 1, 'One stale running job should be closed');
$staleJob = $pdo->query('SELECT * FROM link_scan_jobs WHERE id = ' . $staleJobId)->fetch();
assert_true_scan_mgmt(is_array($staleJob) && (string) $staleJob['status'] === 'failed', 'Stale job should be marked failed');
assert_true_scan_mgmt((string) $staleJob['error_message'] === 'Marked stale automatically', 'Stale reason should be stored');

$deleted = $repo->deleteOldFinishedJobs(30);
assert_true_scan_mgmt($deleted === 3, 'Three old completed jobs should be deleted');
assert_true_scan_mgmt($repo->countWithMonitor(['monitor_id' => $monitorId]) === 4, 'Recent jobs and stale failed job should remain');

echo "LinkScanRepositoryManagementTest OK\n";
