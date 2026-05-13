<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

function assert_true_reset(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$liveDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'uptime_link_scan_reset_' . bin2hex(random_bytes(4));
if (!mkdir($liveDir, 0775, true) && !is_dir($liveDir)) {
    throw new RuntimeException('Temp live dir olusturulamadi');
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
    VALUES ('Reset Monitor', 'https://example.com', '200', 1, 1, datetime('now'))
");
$monitorId = (int) $pdo->lastInsertId();

$pdo->exec("
    INSERT INTO broken_link_ignore_rules (pattern, match_type, scope, note, is_active)
    VALUES ('ignore-me', 'contains', 'target', 'must survive reset', 1)
");

$jobInsert = $pdo->prepare("
    INSERT INTO link_scan_jobs (monitor_id, started_at, finished_at, status, total_urls, checked_urls, broken_urls, created_at)
    VALUES (:monitor_id, :started_at, :finished_at, :status, :total_urls, :checked_urls, :broken_urls, :created_at)
");
$jobInsert->execute([
    'monitor_id' => $monitorId,
    'started_at' => '2026-05-13 10:00:00',
    'finished_at' => null,
    'status' => 'running',
    'total_urls' => 3,
    'checked_urls' => 2,
    'broken_urls' => 1,
    'created_at' => '2026-05-13 10:00:00',
]);
$runningJobId = (int) $pdo->lastInsertId();
$jobInsert->execute([
    'monitor_id' => $monitorId,
    'started_at' => '2026-05-13 09:00:00',
    'finished_at' => '2026-05-13 09:01:00',
    'status' => 'completed',
    'total_urls' => 1,
    'checked_urls' => 1,
    'broken_urls' => 0,
    'created_at' => '2026-05-13 09:00:00',
]);
$jobInsert->execute([
    'monitor_id' => $monitorId,
    'started_at' => '2026-05-13 08:00:00',
    'finished_at' => '2026-05-13 08:01:00',
    'status' => 'failed',
    'total_urls' => 1,
    'checked_urls' => 0,
    'broken_urls' => 0,
    'created_at' => '2026-05-13 08:00:00',
]);

$pdo->prepare("
    INSERT INTO discovered_links (monitor_id, source_url, target_url, link_type, last_checked_at, last_status, first_seen_at)
    VALUES (:monitor_id, 'https://example.com', 'https://example.com/a', 'page', '2026-05-13 10:00:00', 'ok', '2026-05-13 10:00:00')
")->execute(['monitor_id' => $monitorId]);
$pdo->prepare("
    INSERT INTO broken_links (monitor_id, source_url, target_url, status_code, error_type, error_message, first_detected_at, last_detected_at)
    VALUES (:monitor_id, 'https://example.com', 'https://example.com/missing', 404, 'http', 'Not Found', '2026-05-13 10:00:00', '2026-05-13 10:00:00')
")->execute(['monitor_id' => $monitorId]);

file_put_contents($liveDir . DIRECTORY_SEPARATOR . 'job_' . $runningJobId . '.json', '{}');
file_put_contents($liveDir . DIRECTORY_SEPARATOR . 'job_' . $runningJobId . '.cancel', 'old');
file_put_contents($liveDir . DIRECTORY_SEPARATOR . 'job_999.cancel', 'stale');

$resetter = new LinkScanResetter($pdo, $liveDir);
$result = $resetter->resetAll();

assert_true_reset($result['running_cancel_requested'] === 1, 'Exactly one running job should receive a cancel request');
assert_true_reset($result['running_closed'] === 1, 'Exactly one running job should be closed before deletion');
assert_true_reset($result['deleted_jobs'] === 3, 'All link scan jobs should be deleted');
assert_true_reset($result['deleted_discovered_links'] === 1, 'All discovered links should be deleted');
assert_true_reset($result['deleted_broken_links'] === 1, 'All broken links should be deleted');
assert_true_reset($result['deleted_live_files'] === 3, 'Live JSON and cancel files should be deleted');

assert_true_reset((int) $pdo->query('SELECT COUNT(*) FROM link_scan_jobs')->fetchColumn() === 0, 'link_scan_jobs should be empty');
assert_true_reset((int) $pdo->query('SELECT COUNT(*) FROM discovered_links')->fetchColumn() === 0, 'discovered_links should be empty');
assert_true_reset((int) $pdo->query('SELECT COUNT(*) FROM broken_links')->fetchColumn() === 0, 'broken_links should be empty');
assert_true_reset((int) $pdo->query('SELECT COUNT(*) FROM monitors')->fetchColumn() === 1, 'monitors should be preserved');
assert_true_reset((int) $pdo->query('SELECT COUNT(*) FROM broken_link_ignore_rules')->fetchColumn() === 1, 'ignore rules should be preserved');

$remainingLiveFiles = glob($liveDir . DIRECTORY_SEPARATOR . 'job_*');
assert_true_reset(is_array($remainingLiveFiles) && count($remainingLiveFiles) === 0, 'No job live files should remain');

@rmdir($liveDir);

echo "LinkScanResetterTest OK\n";
