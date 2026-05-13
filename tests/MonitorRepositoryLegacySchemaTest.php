<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/Repositories/MonitorRepository.php';

function assert_true_legacy_schema(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function legacy_schema_column_exists(PDO $pdo, string $column): bool
{
    $stmt = $pdo->query('PRAGMA table_info(monitors)');
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    foreach ($rows as $row) {
        if ((string) ($row['name'] ?? '') === $column) {
            return true;
        }
    }
    return false;
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec("
    CREATE TABLE monitors (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        url TEXT NOT NULL,
        expected_status TEXT NOT NULL DEFAULT '200,301,302',
        interval_seconds INTEGER NOT NULL DEFAULT 300,
        timeout_seconds INTEGER NOT NULL DEFAULT 10,
        response_warning_ms INTEGER NOT NULL DEFAULT 3000,
        fail_threshold INTEGER NOT NULL DEFAULT 3,
        recovery_threshold INTEGER NOT NULL DEFAULT 2,
        is_active INTEGER NOT NULL DEFAULT 1,
        current_status TEXT NOT NULL DEFAULT 'unknown',
        consecutive_failures INTEGER NOT NULL DEFAULT 0,
        consecutive_successes INTEGER NOT NULL DEFAULT 0,
        last_check_at TEXT NULL,
        next_check_at TEXT NULL,
        link_scan_enabled INTEGER NOT NULL DEFAULT 1,
        link_scan_interval_seconds INTEGER NOT NULL DEFAULT 21600,
        link_scan_max_depth INTEGER NOT NULL DEFAULT 2,
        link_scan_max_urls INTEGER NOT NULL DEFAULT 120,
        last_link_scan_at TEXT NULL,
        next_link_scan_at TEXT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NULL
    )
");
$pdo->exec("
    CREATE TABLE checks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        monitor_id INTEGER NOT NULL,
        checked_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        status TEXT NOT NULL,
        http_code INTEGER NULL,
        response_time_ms INTEGER NULL,
        error_message TEXT NULL,
        final_url TEXT NULL
    )
");
$pdo->exec("
    CREATE TABLE incidents (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        monitor_id INTEGER NOT NULL,
        started_at TEXT NOT NULL,
        resolved_at TEXT NULL
    )
");
$pdo->exec("
    INSERT INTO monitors (name, url, is_active, current_status)
    VALUES ('Legacy Schema Monitor', 'https://example.com', 1, 'unknown')
");

assert_true_legacy_schema(!legacy_schema_column_exists($pdo, 'archived_at'), 'Test starts without archived_at column');

$repo = new MonitorRepository($pdo);
$rows = $repo->dashboardMonitors(24, 20);

assert_true_legacy_schema(count($rows) === 1, 'dashboardMonitors should work on legacy schema');
assert_true_legacy_schema(legacy_schema_column_exists($pdo, 'archived_at'), 'dashboardMonitors should add archived_at when missing');

$repo->archiveById((int) $rows[0]['id']);
$archivedRows = $repo->archivedMonitors(24, 20);
assert_true_legacy_schema(count($archivedRows) === 1, 'archivedMonitors should work after auto schema guard');

echo "MonitorRepositoryLegacySchemaTest OK\n";
