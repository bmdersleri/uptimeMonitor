<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

final class CancelingLinkScanner extends LinkScanner
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

            $liveDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'link_scan_live';
            $jobFiles = glob($liveDir . DIRECTORY_SEPARATOR . 'job_*.json');
            if (is_array($jobFiles) && $jobFiles !== []) {
                $jobFile = $jobFiles[0];
                $jobId = (int) preg_replace('/[^0-9]/', '', basename($jobFile));
                if ($jobId > 0) {
                    LinkScanRunner::requestCancelForJob($jobId);
                }
            }

            $onProgress([
                'type' => 'resource_checked',
                'source_url' => $baseUrl,
                'target_url' => $baseUrl . '/next',
                'ok' => true,
                'status_code' => 200,
                'error_message' => '',
                'page_count' => 1,
                'checked_count' => 1,
                'broken_count' => 0,
            ]);
        }

        return [
            'total_urls' => 1,
            'checked_urls' => 1,
            'broken_urls' => 0,
            'discovered' => [],
            'broken' => [],
        ];
    }
}

function assert_true_cancel(bool $condition, string $message): void
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
    VALUES ('Cancelable', 'https://example.com', '200', 1, 1, 3, 120, datetime('now'))
");
$monitorId = (int) $pdo->lastInsertId();

$runner = new LinkScanRunner($pdo, new CancelingLinkScanner(3), new Notifier($pdo));
$result = $runner->runMonitorById($monitorId, 'manual');

assert_true_cancel((bool) $result['ok'] === false, 'Canceled scan should return ok=false');
assert_true_cancel((string) $result['message'] === 'Canceled by user', 'Canceled scan should report user cancellation');

$job = $pdo->query('SELECT * FROM link_scan_jobs ORDER BY id DESC LIMIT 1')->fetch();
assert_true_cancel(is_array($job), 'Canceled scan should persist a job');
assert_true_cancel((string) $job['status'] === 'failed', 'Canceled scan should close job as failed because schema has no canceled status');
assert_true_cancel((string) $job['error_message'] === 'Canceled by user', 'Canceled scan should store cancellation reason');
assert_true_cancel($job['finished_at'] !== null, 'Canceled scan should set finished_at');

echo "LinkScanRunnerCancelTest OK\n";
