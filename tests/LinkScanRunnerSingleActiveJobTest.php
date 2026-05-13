<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

final class ShouldNotRunWhenAnotherJobIsActiveScanner extends LinkScanner
{
    /** @var bool */
    public $called = false;

    public function scan(string $baseUrl, int $maxDepth = 3, int $maxUrls = 120, $onProgress = null): array
    {
        $this->called = true;
        return [
            'total_urls' => 0,
            'checked_urls' => 0,
            'broken_urls' => 0,
            'discovered' => [],
            'broken' => [],
        ];
    }
}

function assert_true_single_job(bool $condition, string $message): void
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
    VALUES
        ('Active Job', 'https://active.example.com', '200', 1, 1, 3, 120, datetime('now')),
        ('Blocked Job', 'https://blocked.example.com', '200', 1, 1, 3, 120, datetime('now'))
");
$activeMonitorId = 1;
$blockedMonitorId = 2;

$nowText = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
$pdo->prepare("
    INSERT INTO link_scan_jobs (monitor_id, started_at, status, created_at)
    VALUES (:monitor_id, :started_at, 'running', :created_at)
")->execute([
    'monitor_id' => $activeMonitorId,
    'started_at' => $nowText,
    'created_at' => $nowText,
]);
$runningJobId = (int) $pdo->lastInsertId();

$scanner = new ShouldNotRunWhenAnotherJobIsActiveScanner(3);
$runner = new LinkScanRunner($pdo, $scanner, new Notifier($pdo));
$result = $runner->runMonitorById($blockedMonitorId, 'manual', 3);

assert_true_single_job((bool) ($result['ok'] ?? true) === false, 'A second scan should be rejected while another job is running');
assert_true_single_job((int) ($result['job_id'] ?? 0) === $runningJobId, 'Rejected response should include the active running job id');
assert_true_single_job($scanner->called === false, 'Scanner should not run when another job is active');

echo "LinkScanRunnerSingleActiveJobTest OK\n";
