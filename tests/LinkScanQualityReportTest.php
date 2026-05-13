<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

function assert_true_scan_quality(bool $condition, string $message): void
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
    INSERT INTO monitors (name, url, expected_status, is_active)
    VALUES ('Quality', 'https://example.com', '200', 1)
");
$monitorId = (int) $pdo->lastInsertId();
$pdo->prepare("
    INSERT INTO link_scan_jobs (monitor_id, started_at, finished_at, status, total_urls, checked_urls, broken_urls, duration_seconds, created_at)
    VALUES (:monitor_id, datetime('now', '-5 minutes'), datetime('now'), 'completed', 7, 20, 4, 12, datetime('now', '-5 minutes'))
")->execute(['monitor_id' => $monitorId]);
$jobId = (int) $pdo->lastInsertId();

$items = [
    ['https://example.com/a', 'https://example.com/missing.png', 404],
    ['https://example.com/a', 'https://example.com/missing.png', 404],
    ['https://example.com/b', 'https://example.com/dead.js', 500],
    ['https://example.com/b', 'https://example.com/dead.css', 404],
];
foreach ($items as $item) {
    $pdo->prepare("
        INSERT INTO broken_links (
            monitor_id, source_url, target_url, status_code, error_type, error_message,
            first_detected_at, last_detected_at, occurrence_count
        ) VALUES (
            :monitor_id, :source_url, :target_url, :status_code, 'http', 'broken',
            datetime('now', '-2 minutes'), datetime('now', '-1 minutes'), 1
        )
    ")->execute([
        'monitor_id' => $monitorId,
        'source_url' => $item[0],
        'target_url' => $item[1],
        'status_code' => $item[2],
    ]);
}

$repo = new LinkScanRepository($pdo);
$quality = $repo->qualityReportForJob($jobId);

assert_true_scan_quality((int) $quality['job']['id'] === $jobId, 'Quality report should include the job');
assert_true_scan_quality((int) $quality['job']['checked_urls'] === 20, 'Quality report should include checked URL count');
assert_true_scan_quality(count($quality['top_targets']) === 3, 'Quality report should group broken targets');
assert_true_scan_quality((string) $quality['top_targets'][0]['target_url'] === 'https://example.com/missing.png', 'Most frequent broken target should be first');
assert_true_scan_quality((int) $quality['top_targets'][0]['hit_count'] === 2, 'Most frequent target should have hit count');
assert_true_scan_quality((string) $quality['top_sources'][0]['source_url'] === 'https://example.com/b', 'Most problematic source page should be first');

echo "LinkScanQualityReportTest OK\n";
