<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/MonitorLifecycleActions.php';

final class LegacyMonitorRepositoryDouble
{
    /** @var PDO */
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function updateById(int $id, array $fields): void
    {
        if ($fields === []) {
            return;
        }

        $sets = [];
        $params = ['id' => $id];
        foreach ($fields as $field => $value) {
            $sets[] = $field . ' = :' . $field;
            $params[$field] = $value;
        }

        $sql = 'UPDATE monitors SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }
}

function assert_true_lifecycle(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec("
    CREATE TABLE monitors (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        is_active INTEGER NOT NULL DEFAULT 1,
        link_scan_enabled INTEGER NOT NULL DEFAULT 1,
        next_check_at TEXT NULL,
        next_link_scan_at TEXT NULL,
        updated_at TEXT NULL
    )
");
$pdo->exec("
    INSERT INTO monitors (name, is_active, link_scan_enabled, next_check_at, next_link_scan_at)
    VALUES ('Legacy Monitor', 1, 1, '2026-05-12 10:00:00', '2026-05-12 10:00:00')
");
$monitorId = (int) $pdo->lastInsertId();
$legacyRepo = new LegacyMonitorRepositoryDouble($pdo);

monitor_lifecycle_set_active($pdo, $legacyRepo, $monitorId, false);
$inactive = $pdo->query('SELECT * FROM monitors WHERE id = ' . $monitorId)->fetch();
assert_true_lifecycle(is_array($inactive), 'Monitor should exist after deactivate');
assert_true_lifecycle((int) $inactive['is_active'] === 0, 'Fallback deactivate should set is_active to 0');
assert_true_lifecycle($inactive['next_check_at'] === null, 'Fallback deactivate should clear next_check_at');

monitor_lifecycle_archive($pdo, $legacyRepo, $monitorId);
$archived = $pdo->query('SELECT * FROM monitors WHERE id = ' . $monitorId)->fetch();
assert_true_lifecycle(is_array($archived), 'Monitor should exist after archive');
assert_true_lifecycle(array_key_exists('archived_at', $archived), 'Fallback archive should ensure archived_at column exists');
assert_true_lifecycle($archived['archived_at'] !== null, 'Fallback archive should set archived_at');
assert_true_lifecycle($archived['next_link_scan_at'] === null, 'Fallback archive should clear next_link_scan_at');

monitor_lifecycle_restore($pdo, $legacyRepo, $monitorId);
$restored = $pdo->query('SELECT * FROM monitors WHERE id = ' . $monitorId)->fetch();
assert_true_lifecycle(is_array($restored) && $restored['archived_at'] === null, 'Fallback restore should clear archived_at');

monitor_lifecycle_delete($pdo, $legacyRepo, $monitorId);
$count = (int) $pdo->query('SELECT COUNT(*) AS c FROM monitors')->fetch()['c'];
assert_true_lifecycle($count === 0, 'Fallback delete should remove the monitor');

echo "MonitorLifecycleActionsCompatibilityTest OK\n";
