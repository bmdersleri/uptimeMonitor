<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

final class VerboseLiveStateScanner extends LinkScanner
{
    public function scan(string $baseUrl, int $maxDepth = 3, int $maxUrls = 120, $onProgress = null): array
    {
        if ($onProgress !== null) {
            $onProgress([
                'type' => 'page_start',
                'current_url' => $baseUrl,
                'depth' => 0,
                'page_count' => 1,
                'checked_count' => 0,
                'broken_count' => 0,
            ]);
            $onProgress([
                'type' => 'page_fetch_start',
                'current_url' => $baseUrl,
                'depth' => 0,
                'page_count' => 1,
                'checked_count' => 0,
                'broken_count' => 0,
                'queue_size' => 0,
            ]);
            $onProgress([
                'type' => 'page_fetch_done',
                'current_url' => $baseUrl,
                'depth' => 0,
                'page_count' => 1,
                'checked_count' => 0,
                'broken_count' => 0,
                'queue_size' => 0,
                'ok' => true,
                'status_code' => 200,
                'error_message' => '',
            ]);
            $onProgress([
                'type' => 'page_resources_found',
                'current_url' => $baseUrl,
                'depth' => 0,
                'page_count' => 1,
                'resource_count' => 205,
                'checked_count' => 0,
                'broken_count' => 0,
            ]);

            for ($i = 1; $i <= 205; $i++) {
                if ($i === 1) {
                    $onProgress([
                        'type' => 'resource_batch_start',
                        'current_url' => $baseUrl,
                        'target_url' => $baseUrl . '/resource-' . $i . '.png',
                        'depth' => 0,
                        'page_count' => 1,
                        'checked_count' => 0,
                        'broken_count' => 0,
                        'resource_count' => 5,
                        'batch_index' => 1,
                        'batch_total' => 41,
                        'queue_size' => 0,
                    ]);
                }
                $onProgress([
                    'type' => 'resource_checked',
                    'source_url' => $baseUrl,
                    'target_url' => $baseUrl . '/resource-' . $i . '.png',
                    'ok' => true,
                    'status_code' => 200,
                    'error_message' => '',
                    'page_count' => 1,
                    'checked_count' => $i,
                    'broken_count' => 0,
                ]);
            }

            $onProgress([
                'type' => 'resource_batch_done',
                'current_url' => $baseUrl,
                'depth' => 0,
                'page_count' => 1,
                'checked_count' => 205,
                'broken_count' => 0,
                'resource_count' => 5,
                'batch_index' => 41,
                'batch_total' => 41,
                'queue_size' => 0,
            ]);
        }

        return [
            'total_urls' => 1,
            'checked_urls' => 205,
            'broken_urls' => 0,
            'discovered' => [],
            'broken' => [],
        ];
    }
}

function assert_true_live_state(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$liveDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'link_scan_live';
if (is_dir($liveDir)) {
    $files = glob($liveDir . DIRECTORY_SEPARATOR . 'job_*');
    if (is_array($files)) {
        foreach ($files as $file) {
            @unlink($file);
        }
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
    VALUES ('Live State', 'https://example.com', '200', 1, 1, 3, 120, datetime('now'))
");

$runner = new LinkScanRunner($pdo, new VerboseLiveStateScanner(3), new Notifier($pdo));
$result = $runner->runMonitorById((int) $pdo->lastInsertId(), 'manual');

assert_true_live_state((bool) $result['ok'] === true, 'Verbose live-state scan should complete');

$jobId = (int) ($result['job_id'] ?? 0);
$livePath = $liveDir . DIRECTORY_SEPARATOR . 'job_' . $jobId . '.json';
assert_true_live_state(is_file($livePath), 'Live-state file should exist');
$json = file_get_contents($livePath);
assert_true_live_state(is_string($json), 'Live-state file should be readable');
$state = json_decode($json, true);
assert_true_live_state(is_array($state), 'Live-state JSON should decode');
assert_true_live_state((int) ($state['pages_crawled'] ?? 0) === 1, 'Live state should separate crawled pages from check targets');
assert_true_live_state((int) ($state['estimated_checks'] ?? 0) === 205, 'Live state should expose estimated check target count');
assert_true_live_state(count((array) ($state['recent'] ?? [])) === 200, 'Live state should keep the latest 200 checked links');
assert_true_live_state((string) ($state['phase'] ?? '') === 'completed', 'Completed live state should expose a final phase');
assert_true_live_state((string) ($state['heartbeat_at'] ?? '') !== '', 'Live state should expose heartbeat time');
assert_true_live_state((string) ($state['last_progress_at'] ?? '') !== '', 'Live state should expose last progress time');
assert_true_live_state((int) ($state['progress_percent'] ?? 0) === 100, 'Completed live state should expose 100 percent progress');

$fresh = LinkScanRunner::enrichLiveStateForStatus([
    'status' => 'running',
    'heartbeat_at' => '2026-05-13 12:00:00',
    'checked_urls' => 10,
    'estimated_checks' => 20,
], ['status' => 'running'], new DateTimeImmutable('2026-05-13 12:00:10'));
assert_true_live_state(is_array($fresh), 'Fresh status live state should be returned');
assert_true_live_state((bool) ($fresh['stalled'] ?? true) === false, 'Fresh live state should not be stalled');
assert_true_live_state((int) ($fresh['progress_percent'] ?? 0) === 50, 'Status live state should include progress percent');

$stalled = LinkScanRunner::enrichLiveStateForStatus([
    'status' => 'running',
    'heartbeat_at' => '2026-05-13 12:00:00',
    'checked_urls' => 10,
    'estimated_checks' => 20,
], ['status' => 'running'], new DateTimeImmutable('2026-05-13 12:00:25'));
assert_true_live_state((bool) ($stalled['stalled'] ?? false) === true, 'Live state should become stalled after the warning threshold');
assert_true_live_state((bool) ($stalled['needs_attention'] ?? true) === false, 'Live state should not need attention before the strong threshold');

$attention = LinkScanRunner::enrichLiveStateForStatus([
    'status' => 'running',
    'heartbeat_at' => '2026-05-13 12:00:00',
    'checked_urls' => 10,
    'estimated_checks' => 20,
], ['status' => 'running'], new DateTimeImmutable('2026-05-13 12:01:05'));
assert_true_live_state((bool) ($attention['needs_attention'] ?? false) === true, 'Live state should need attention after the strong threshold');

echo "LinkScanRunnerLiveStateTest OK\n";
