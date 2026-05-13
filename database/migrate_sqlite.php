<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

if ((string) config('DB_DRIVER', 'sqlite') !== 'sqlite') {
    fwrite(STDERR, "[migrate] Bu script sqlite icin tasarlandi.\n");
    exit(1);
}

$pdo = Database::connection();
$schema = file_get_contents(__DIR__ . '/schema.sql');
if ($schema === false) {
    fwrite(STDERR, "[migrate] schema.sql okunamadi.\n");
    exit(1);
}

$pdo->exec($schema);

/**
 * @return bool
 */
function column_exists(PDO $pdo, string $table, string $column)
{
    $stmt = $pdo->query("PRAGMA table_info(" . $table . ")");
    $rows = $stmt ? $stmt->fetchAll() : [];
    foreach ($rows as $row) {
        if ((string) ($row['name'] ?? '') === $column) {
            return true;
        }
    }
    return false;
}

$monitorColumns = [
    'link_scan_enabled' => "ALTER TABLE monitors ADD COLUMN link_scan_enabled INTEGER NOT NULL DEFAULT 1",
    'link_scan_interval_seconds' => "ALTER TABLE monitors ADD COLUMN link_scan_interval_seconds INTEGER NOT NULL DEFAULT 21600",
    'link_scan_max_depth' => "ALTER TABLE monitors ADD COLUMN link_scan_max_depth INTEGER NOT NULL DEFAULT 3",
    'link_scan_max_urls' => "ALTER TABLE monitors ADD COLUMN link_scan_max_urls INTEGER NOT NULL DEFAULT 120",
    'last_link_scan_at' => "ALTER TABLE monitors ADD COLUMN last_link_scan_at TEXT NULL",
    'next_link_scan_at' => "ALTER TABLE monitors ADD COLUMN next_link_scan_at TEXT NULL",
    'archived_at' => "ALTER TABLE monitors ADD COLUMN archived_at TEXT NULL",
];

foreach ($monitorColumns as $col => $sql) {
    if (!column_exists($pdo, 'monitors', $col)) {
        $pdo->exec($sql);
        echo "[migrate] monitors.$col eklendi\n";
    }
}

$brokenLinkColumns = [
    'ignored_at' => "ALTER TABLE broken_links ADD COLUMN ignored_at TEXT NULL",
    'ignored_reason' => "ALTER TABLE broken_links ADD COLUMN ignored_reason TEXT NULL",
];

foreach ($brokenLinkColumns as $col => $sql) {
    if (!column_exists($pdo, 'broken_links', $col)) {
        $pdo->exec($sql);
        echo "[migrate] broken_links.$col eklendi\n";
    }
}

$pdo->exec("
    CREATE TABLE IF NOT EXISTS report_runs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        report_type TEXT NOT NULL CHECK (report_type IN ('daily','weekly')),
        period_start TEXT NOT NULL,
        period_end TEXT NOT NULL,
        subject TEXT NOT NULL,
        body TEXT NOT NULL,
        html_body TEXT NULL,
        email_status TEXT NOT NULL DEFAULT 'skipped',
        telegram_status TEXT NOT NULL DEFAULT 'skipped',
        email_error TEXT NULL,
        telegram_error TEXT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        sent_at TEXT NULL
    )
");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_report_runs_type_time ON report_runs (report_type, created_at)");

if (!column_exists($pdo, 'report_runs', 'html_body')) {
    $pdo->exec("ALTER TABLE report_runs ADD COLUMN html_body TEXT NULL");
    echo "[migrate] report_runs.html_body eklendi\n";
}

echo "[migrate] SQLite migration tamam.\n";
