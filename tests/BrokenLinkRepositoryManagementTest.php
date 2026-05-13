<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

function assert_true_broken_mgmt(bool $condition, string $message): void
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
    VALUES ('Broken Mgmt', 'https://example.com', '200', 1)
");
$monitorId = (int) $pdo->lastInsertId();

for ($i = 1; $i <= 5; $i++) {
    $pdo->prepare("
        INSERT INTO broken_links (
            monitor_id, source_url, target_url, status_code, error_type, error_message,
            first_detected_at, last_detected_at, occurrence_count, resolved_at
        ) VALUES (
            :monitor_id, :source_url, :target_url, 404, 'http', 'HTTP 404',
            datetime('now', :days), datetime('now', :days), :occurrence_count, NULL
        )
    ")->execute([
        'monitor_id' => $monitorId,
        'source_url' => 'https://example.com/source-' . $i,
        'target_url' => 'https://example.com/missing-' . $i,
        'days' => '-' . $i . ' days',
        'occurrence_count' => $i,
    ]);
}

for ($i = 1; $i <= 3; $i++) {
    $pdo->prepare("
        INSERT INTO broken_links (
            monitor_id, source_url, target_url, status_code, error_type, error_message,
            first_detected_at, last_detected_at, occurrence_count, resolved_at
        ) VALUES (
            :monitor_id, :source_url, :target_url, 500, 'http', 'HTTP 500',
            datetime('now', '-40 days'), datetime('now', '-40 days'), 1, datetime('now', '-35 days')
        )
    ")->execute([
        'monitor_id' => $monitorId,
        'source_url' => 'https://example.com/old-source-' . $i,
        'target_url' => 'https://example.com/old-resolved-' . $i,
    ]);
}

$repo = new BrokenLinkRepository($pdo);
$pageOne = $repo->listWithMonitor(['status' => 'active', 'monitor_id' => $monitorId], 2, 0);
$pageTwo = $repo->listWithMonitor(['status' => 'active', 'monitor_id' => $monitorId], 2, 2);

assert_true_broken_mgmt(count($pageOne) === 2, 'First page should contain two active rows');
assert_true_broken_mgmt(count($pageTwo) === 2, 'Second page should contain two active rows');
assert_true_broken_mgmt($repo->countWithMonitor(['status' => 'active', 'monitor_id' => $monitorId]) === 5, 'Active count should match filter');
assert_true_broken_mgmt($repo->countWithMonitor(['status' => 'resolved', 'monitor_id' => $monitorId]) === 3, 'Resolved count should match filter');

$deleted = $repo->deleteResolvedOlderThan(30);
assert_true_broken_mgmt($deleted === 3, 'Old resolved rows should be deleted');
assert_true_broken_mgmt($repo->countWithMonitor(['status' => 'resolved', 'monitor_id' => $monitorId]) === 0, 'Resolved rows should be gone');
assert_true_broken_mgmt($repo->countWithMonitor(['status' => 'active', 'monitor_id' => $monitorId]) === 5, 'Active rows should remain');

echo "BrokenLinkRepositoryManagementTest OK\n";
