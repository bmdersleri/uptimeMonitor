<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

function assert_true_report(bool $condition, string $message): void
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
    INSERT INTO monitors (name, url, expected_status, is_active, current_status, archived_at)
    VALUES
        ('Alpha', 'https://alpha.test', '200', 1, 'up', NULL),
        ('Beta', 'https://beta.test', '200', 1, 'down', NULL),
        ('Old', 'https://old.test', '200', 0, 'unknown', '2026-05-12 00:00:00')
");

$alphaId = 1;
$betaId = 2;

$checkInsert = $pdo->prepare("
    INSERT INTO checks (monitor_id, checked_at, status, http_code, response_time_ms)
    VALUES (:monitor_id, :checked_at, :status, :http_code, :response_time_ms)
");
$checkInsert->execute(['monitor_id' => $alphaId, 'checked_at' => '2026-05-13 09:00:00', 'status' => 'up', 'http_code' => 200, 'response_time_ms' => 100]);
$checkInsert->execute(['monitor_id' => $alphaId, 'checked_at' => '2026-05-13 10:00:00', 'status' => 'up', 'http_code' => 200, 'response_time_ms' => 120]);
$checkInsert->execute(['monitor_id' => $betaId, 'checked_at' => '2026-05-13 09:00:00', 'status' => 'down', 'http_code' => 500, 'response_time_ms' => 900]);
$checkInsert->execute(['monitor_id' => $betaId, 'checked_at' => '2026-05-12 13:00:00', 'status' => 'up', 'http_code' => 200, 'response_time_ms' => 300]);

$pdo->prepare("
    INSERT INTO incidents (monitor_id, started_at, resolved_at, duration_seconds, reason, last_error)
    VALUES (:monitor_id, '2026-05-13 08:00:00', '2026-05-13 08:05:00', 300, 'down', 'HTTP 500')
")->execute(['monitor_id' => $betaId]);

$pdo->prepare("
    INSERT INTO broken_links (monitor_id, source_url, target_url, status_code, first_detected_at, last_detected_at, resolved_at)
    VALUES (:monitor_id, 'https://beta.test', 'https://beta.test/missing', 404, '2026-05-13 07:00:00', '2026-05-13 07:00:00', NULL)
")->execute(['monitor_id' => $betaId]);
$pdo->prepare("
    INSERT INTO broken_links (monitor_id, source_url, target_url, status_code, first_detected_at, last_detected_at, resolved_at)
    VALUES (:monitor_id, 'https://alpha.test', 'https://alpha.test/fixed', 404, '2026-05-12 07:00:00', '2026-05-13 06:00:00', '2026-05-13 06:10:00')
")->execute(['monitor_id' => $alphaId]);

$pdo->prepare("
    INSERT INTO link_scan_jobs (monitor_id, started_at, finished_at, status, total_urls, checked_urls, broken_urls, created_at)
    VALUES (:monitor_id, '2026-05-13 07:30:00', '2026-05-13 07:31:00', 'completed', 4, 4, 1, '2026-05-13 07:30:00')
")->execute(['monitor_id' => $betaId]);

$service = new ReportService($pdo);
$report = $service->generate('daily', new DateTimeImmutable('2026-05-13 12:00:00'));
$summary = $report['summary'];

assert_true_report((int) $summary['total_monitors'] === 2, 'Archived monitors should be excluded from total monitors');
assert_true_report((int) $summary['active_monitors'] === 2, 'Active monitor count should be 2');
assert_true_report((int) $summary['up_monitors'] === 1, 'One monitor should be up');
assert_true_report((int) $summary['down_monitors'] === 1, 'One monitor should be down');
assert_true_report((int) $summary['total_checks'] === 4, 'Daily report should include checks from the last 24 hours');
assert_true_report((float) $summary['uptime_percent'] === 75.0, 'Uptime percentage should be 75');
assert_true_report((int) $summary['incident_count'] === 1, 'Incident count should be 1');
assert_true_report((int) $summary['downtime_seconds'] === 300, 'Downtime seconds should be 300');
assert_true_report((int) $summary['active_broken_links'] === 1, 'Active broken links should be 1');
assert_true_report((int) $summary['new_broken_links'] === 1, 'New broken links should count links first detected in period');
assert_true_report((int) $summary['resolved_broken_links'] === 1, 'Resolved broken links should be 1');
assert_true_report((int) $summary['completed_link_scans'] === 1, 'Completed link scans should be 1');
assert_true_report(strpos((string) $report['body'], 'Daily Operational Report') !== false, 'Report body should include daily title');
assert_true_report(strpos((string) $report['html_body'], '<table') !== false, 'Report HTML should include tables');
assert_true_report(strpos((string) $report['body'], 'Beta') !== false, 'Report body should include monitor highlights');

$result = $service->createAndSend('daily', new DateTimeImmutable('2026-05-13 12:00:00'));
assert_true_report((int) $result['id'] === 1, 'Report run should be stored');
assert_true_report((int) $pdo->query('SELECT COUNT(*) FROM report_runs')->fetchColumn() === 1, 'report_runs should have one row');

echo "ReportServiceTest OK\n";
