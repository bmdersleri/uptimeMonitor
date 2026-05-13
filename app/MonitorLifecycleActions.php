<?php

declare(strict_types=1);

function monitor_lifecycle_now(): string
{
    return (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
}

function monitor_lifecycle_column_exists(PDO $pdo, string $table, string $column): bool
{
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $stmt = $pdo->query('PRAGMA table_info(' . $table . ')');
        $rows = $stmt ? $stmt->fetchAll() : [];
        foreach ($rows as $row) {
            if ((string) ($row['name'] ?? '') === $column) {
                return true;
            }
        }
        return false;
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS c
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
          AND COLUMN_NAME = :column_name
    ");
    $stmt->execute([
        'table_name' => $table,
        'column_name' => $column,
    ]);
    $row = $stmt->fetch();
    return is_array($row) && (int) ($row['c'] ?? 0) > 0;
}

function monitor_lifecycle_ensure_schema(PDO $pdo): void
{
    if (monitor_lifecycle_column_exists($pdo, 'monitors', 'archived_at')) {
        return;
    }

    $pdo->exec('ALTER TABLE monitors ADD COLUMN archived_at TEXT NULL');
}

function monitor_lifecycle_set_active(PDO $pdo, object $repo, int $monitorId, bool $active): void
{
    if (method_exists($repo, 'setActiveById')) {
        $repo->setActiveById($monitorId, $active);
        return;
    }

    $now = monitor_lifecycle_now();
    if (method_exists($repo, 'updateById')) {
        $repo->updateById($monitorId, [
            'is_active' => $active ? 1 : 0,
            'next_check_at' => $active ? $now : null,
            'next_link_scan_at' => $active ? $now : null,
        ]);
        return;
    }

    $stmt = $pdo->prepare("
        UPDATE monitors
        SET is_active = :is_active,
            next_check_at = :next_check_at,
            next_link_scan_at = :next_link_scan_at,
            updated_at = :updated_at
        WHERE id = :id
    ");
    $stmt->execute([
        'is_active' => $active ? 1 : 0,
        'next_check_at' => $active ? $now : null,
        'next_link_scan_at' => $active ? $now : null,
        'updated_at' => $now,
        'id' => $monitorId,
    ]);
}

function monitor_lifecycle_archive(PDO $pdo, object $repo, int $monitorId): void
{
    monitor_lifecycle_ensure_schema($pdo);

    if (method_exists($repo, 'archiveById')) {
        $repo->archiveById($monitorId);
        return;
    }

    $now = monitor_lifecycle_now();
    $stmt = $pdo->prepare("
        UPDATE monitors
        SET is_active = 0,
            archived_at = :archived_at,
            next_check_at = NULL,
            next_link_scan_at = NULL,
            updated_at = :updated_at
        WHERE id = :id
    ");
    $stmt->execute([
        'archived_at' => $now,
        'updated_at' => $now,
        'id' => $monitorId,
    ]);
}

function monitor_lifecycle_restore(PDO $pdo, object $repo, int $monitorId): void
{
    monitor_lifecycle_ensure_schema($pdo);

    if (method_exists($repo, 'restoreById')) {
        $repo->restoreById($monitorId);
        return;
    }

    $stmt = $pdo->prepare("
        UPDATE monitors
        SET archived_at = NULL,
            updated_at = :updated_at
        WHERE id = :id
    ");
    $stmt->execute([
        'updated_at' => monitor_lifecycle_now(),
        'id' => $monitorId,
    ]);
}

function monitor_lifecycle_delete(PDO $pdo, object $repo, int $monitorId): void
{
    if (method_exists($repo, 'deleteById')) {
        $repo->deleteById($monitorId);
        return;
    }

    $stmt = $pdo->prepare('DELETE FROM monitors WHERE id = :id');
    $stmt->execute(['id' => $monitorId]);
}
