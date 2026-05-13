<?php

declare(strict_types=1);

final class MonitorRepository
{
    /** @var PDO */
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        $sql = "SELECT * FROM monitors ORDER BY id DESC";
        return $this->pdo->query($sql)->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $sql = "SELECT * FROM monitors WHERE id = :id LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function dueForCheck(DateTimeImmutable $now): array
    {
        $this->ensureArchivedAtColumn();

        $sql = "
            SELECT *
            FROM monitors
            WHERE is_active = 1
              AND archived_at IS NULL
              AND (next_check_at IS NULL OR next_check_at <= :now)
            ORDER BY id ASC
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['now' => $now->format('Y-m-d H:i:s')]);
        return $stmt->fetchAll();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): void
    {
        $createdAt = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $sql = "
            INSERT INTO monitors (
                name,
                url,
                expected_status,
                interval_seconds,
                timeout_seconds,
                response_warning_ms,
                fail_threshold,
                recovery_threshold,
                is_active,
                link_scan_enabled,
                link_scan_interval_seconds,
                link_scan_max_depth,
                link_scan_max_urls,
                current_status,
                next_check_at,
                next_link_scan_at,
                created_at
            ) VALUES (
                :name,
                :url,
                :expected_status,
                :interval_seconds,
                :timeout_seconds,
                :response_warning_ms,
                :fail_threshold,
                :recovery_threshold,
                :is_active,
                :link_scan_enabled,
                :link_scan_interval_seconds,
                :link_scan_max_depth,
                :link_scan_max_urls,
                'unknown',
                :next_check_at,
                :next_link_scan_at,
                :created_at
            )
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'name' => (string) $data['name'],
            'url' => (string) $data['url'],
            'expected_status' => (string) $data['expected_status'],
            'interval_seconds' => (int) $data['interval_seconds'],
            'timeout_seconds' => (int) $data['timeout_seconds'],
            'response_warning_ms' => (int) $data['response_warning_ms'],
            'fail_threshold' => (int) $data['fail_threshold'],
            'recovery_threshold' => (int) $data['recovery_threshold'],
            'is_active' => (int) $data['is_active'],
            'link_scan_enabled' => isset($data['link_scan_enabled']) ? (int) $data['link_scan_enabled'] : 1,
            'link_scan_interval_seconds' => isset($data['link_scan_interval_seconds']) ? (int) $data['link_scan_interval_seconds'] : 21600,
            'link_scan_max_depth' => isset($data['link_scan_max_depth']) ? (int) $data['link_scan_max_depth'] : 3,
            'link_scan_max_urls' => isset($data['link_scan_max_urls']) ? (int) $data['link_scan_max_urls'] : 120,
            'next_check_at' => $data['next_check_at'] ?? null,
            'next_link_scan_at' => $data['next_link_scan_at'] ?? null,
            'created_at' => $createdAt,
        ]);
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

        foreach ($fields as $key => $value) {
            $sets[] = $key . ' = :' . $key;
            $params[$key] = $value;
        }

        $params['updated_at'] = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $sql = 'UPDATE monitors SET ' . implode(', ', $sets) . ', updated_at = :updated_at WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    public function setActiveById(int $id, bool $active): void
    {
        $this->ensureArchivedAtColumn();

        $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $sql = "
            UPDATE monitors
            SET
                is_active = :is_active,
                next_check_at = :next_check_at,
                next_link_scan_at = CASE
                    WHEN :is_active = 1 AND COALESCE(link_scan_enabled, 1) = 1 THEN :next_link_scan_at
                    ELSE NULL
                END,
                updated_at = :updated_at
            WHERE id = :id
              AND archived_at IS NULL
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'is_active' => $active ? 1 : 0,
            'next_check_at' => $active ? $now : null,
            'next_link_scan_at' => $active ? $now : null,
            'updated_at' => $now,
            'id' => $id,
        ]);
    }

    public function archiveById(int $id): void
    {
        $this->ensureArchivedAtColumn();

        $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $sql = "
            UPDATE monitors
            SET
                is_active = 0,
                archived_at = :archived_at,
                next_check_at = NULL,
                next_link_scan_at = NULL,
                updated_at = :updated_at
            WHERE id = :id
              AND archived_at IS NULL
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'archived_at' => $now,
            'updated_at' => $now,
            'id' => $id,
        ]);
    }

    public function restoreById(int $id): void
    {
        $this->ensureArchivedAtColumn();

        $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $sql = "
            UPDATE monitors
            SET archived_at = NULL, updated_at = :updated_at
            WHERE id = :id
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'updated_at' => $now,
            'id' => $id,
        ]);
    }

    public function deleteById(int $id): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM monitors WHERE id = :id");
        $stmt->execute(['id' => $id]);
    }

    /**
     * @return array<string, int>
     */
    public function statusCounts(): array
    {
        $sql = "
            SELECT
                SUM(CASE WHEN current_status = 'up' THEN 1 ELSE 0 END) AS up_count,
                SUM(CASE WHEN current_status = 'down' THEN 1 ELSE 0 END) AS down_count,
                SUM(CASE WHEN current_status = 'degraded' THEN 1 ELSE 0 END) AS degraded_count,
                COUNT(*) AS total_count
            FROM monitors
            WHERE is_active = 1
        ";

        $row = $this->pdo->query($sql)->fetch();
        if (!is_array($row)) {
            return ['up_count' => 0, 'down_count' => 0, 'degraded_count' => 0, 'total_count' => 0];
        }

        return [
            'up_count' => (int) ($row['up_count'] ?? 0),
            'down_count' => (int) ($row['down_count'] ?? 0),
            'degraded_count' => (int) ($row['degraded_count'] ?? 0),
            'total_count' => (int) ($row['total_count'] ?? 0),
        ];
    }

    /**
     * @return array<string, float|int|string|null>
     */
    public function dashboardSummary(int $windowHours = 24): array
    {
        $baseStats = $this->statusCounts();
        $cutoff = (new DateTimeImmutable('now'))
            ->sub(new DateInterval('PT' . max(1, $windowHours) . 'H'))
            ->format('Y-m-d H:i:s');

        $openIncidentSql = "
            SELECT COUNT(*) AS open_incidents
            FROM incidents i
            INNER JOIN monitors m ON m.id = i.monitor_id
            WHERE i.resolved_at IS NULL
              AND m.is_active = 1
        ";
        $openIncidentRow = $this->pdo->query($openIncidentSql)->fetch();
        $openIncidents = is_array($openIncidentRow) ? (int) ($openIncidentRow['open_incidents'] ?? 0) : 0;

        $activeBrokenLinks = 0;
        try {
            $brokenSql = "
                SELECT COUNT(*) AS active_broken_links
                FROM broken_links b
                INNER JOIN monitors m ON m.id = b.monitor_id
                WHERE b.resolved_at IS NULL
                  AND m.is_active = 1
            ";
            $brokenRow = $this->pdo->query($brokenSql)->fetch();
            $activeBrokenLinks = is_array($brokenRow) ? (int) ($brokenRow['active_broken_links'] ?? 0) : 0;
        } catch (Exception $e) {
            $activeBrokenLinks = 0;
        }

        $checksSql = "
            SELECT
                SUM(CASE WHEN c.status = 'up' THEN 1 ELSE 0 END) AS up_checks,
                COUNT(*) AS total_checks,
                AVG(c.response_time_ms) AS avg_response_ms,
                MAX(c.checked_at) AS last_check_at
            FROM checks c
            INNER JOIN monitors m ON m.id = c.monitor_id
            WHERE m.is_active = 1
              AND c.checked_at >= :cutoff
        ";
        $stmt = $this->pdo->prepare($checksSql);
        $stmt->execute(['cutoff' => $cutoff]);
        $checksRow = $stmt->fetch();

        $totalChecks = is_array($checksRow) ? (int) ($checksRow['total_checks'] ?? 0) : 0;
        $upChecks = is_array($checksRow) ? (int) ($checksRow['up_checks'] ?? 0) : 0;
        $avgResponse = is_array($checksRow) && $checksRow['avg_response_ms'] !== null
            ? round((float) $checksRow['avg_response_ms'])
            : null;
        $lastCheckAt = is_array($checksRow) ? ($checksRow['last_check_at'] ?? null) : null;

        $uptime24h = 0.0;
        if ($totalChecks > 0) {
            $uptime24h = round(($upChecks / $totalChecks) * 100, 2);
        }

        return [
            'total_count' => $baseStats['total_count'],
            'up_count' => $baseStats['up_count'],
            'down_count' => $baseStats['down_count'],
            'degraded_count' => $baseStats['degraded_count'],
            'open_incidents' => $openIncidents,
            'active_broken_links' => $activeBrokenLinks,
            'checks_24h' => $totalChecks,
            'uptime_24h_percent' => $uptime24h,
            'avg_response_24h_ms' => $avgResponse,
            'last_check_at' => $lastCheckAt,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function dashboardMonitors(int $windowHours = 24, int $limit = 200): array
    {
        $this->ensureArchivedAtColumn();

        $cutoff = (new DateTimeImmutable('now'))
            ->sub(new DateInterval('PT' . max(1, $windowHours) . 'H'))
            ->format('Y-m-d H:i:s');

        $sql = "
            SELECT
                m.*,
                (
                    SELECT c.checked_at
                    FROM checks c
                    WHERE c.monitor_id = m.id
                    ORDER BY c.id DESC
                    LIMIT 1
                ) AS last_check_time,
                (
                    SELECT c.status
                    FROM checks c
                    WHERE c.monitor_id = m.id
                    ORDER BY c.id DESC
                    LIMIT 1
                ) AS last_check_status,
                (
                    SELECT c.http_code
                    FROM checks c
                    WHERE c.monitor_id = m.id
                    ORDER BY c.id DESC
                    LIMIT 1
                ) AS last_http_code,
                (
                    SELECT c.response_time_ms
                    FROM checks c
                    WHERE c.monitor_id = m.id
                    ORDER BY c.id DESC
                    LIMIT 1
                ) AS last_response_ms,
                (
                    SELECT c.error_message
                    FROM checks c
                    WHERE c.monitor_id = m.id
                    ORDER BY c.id DESC
                    LIMIT 1
                ) AS last_error_message,
                (
                    SELECT ROUND(100.0 * SUM(CASE WHEN c.status = 'up' THEN 1 ELSE 0 END) / COUNT(*), 2)
                    FROM checks c
                    WHERE c.monitor_id = m.id
                      AND c.checked_at >= :cutoff
                ) AS uptime_24h_percent,
                (
                    SELECT COUNT(*)
                    FROM incidents i
                    WHERE i.monitor_id = m.id
                      AND i.resolved_at IS NULL
                ) AS open_incident_count
            FROM monitors m
            WHERE m.archived_at IS NULL
            ORDER BY
                m.is_active DESC,
                CASE m.current_status
                    WHEN 'down' THEN 0
                    WHEN 'degraded' THEN 1
                    WHEN 'up' THEN 2
                    ELSE 3
                END,
                m.id DESC
            LIMIT :limit
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':cutoff', $cutoff);
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function archivedMonitors(int $windowHours = 24, int $limit = 200): array
    {
        $this->ensureArchivedAtColumn();

        $cutoff = (new DateTimeImmutable('now'))
            ->sub(new DateInterval('PT' . max(1, $windowHours) . 'H'))
            ->format('Y-m-d H:i:s');

        $sql = "
            SELECT
                m.*,
                (
                    SELECT c.checked_at
                    FROM checks c
                    WHERE c.monitor_id = m.id
                    ORDER BY c.id DESC
                    LIMIT 1
                ) AS last_check_time,
                (
                    SELECT c.http_code
                    FROM checks c
                    WHERE c.monitor_id = m.id
                    ORDER BY c.id DESC
                    LIMIT 1
                ) AS last_http_code,
                (
                    SELECT c.response_time_ms
                    FROM checks c
                    WHERE c.monitor_id = m.id
                    ORDER BY c.id DESC
                    LIMIT 1
                ) AS last_response_ms,
                (
                    SELECT c.error_message
                    FROM checks c
                    WHERE c.monitor_id = m.id
                    ORDER BY c.id DESC
                    LIMIT 1
                ) AS last_error_message,
                (
                    SELECT ROUND(100.0 * SUM(CASE WHEN c.status = 'up' THEN 1 ELSE 0 END) / COUNT(*), 2)
                    FROM checks c
                    WHERE c.monitor_id = m.id
                      AND c.checked_at >= :cutoff
                ) AS uptime_24h_percent,
                (
                    SELECT COUNT(*)
                    FROM incidents i
                    WHERE i.monitor_id = m.id
                      AND i.resolved_at IS NULL
                ) AS open_incident_count
            FROM monitors
            m
            WHERE m.archived_at IS NOT NULL
            ORDER BY m.archived_at DESC, m.id DESC
            LIMIT :limit
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':cutoff', $cutoff);
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    private function ensureArchivedAtColumn(): void
    {
        if ($this->columnExists('monitors', 'archived_at')) {
            return;
        }

        $this->pdo->exec('ALTER TABLE monitors ADD COLUMN archived_at TEXT NULL');
    }

    private function columnExists(string $table, string $column): bool
    {
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $this->pdo->query('PRAGMA table_info(' . $table . ')');
            $rows = $stmt ? $stmt->fetchAll() : [];
            foreach ($rows as $row) {
                if ((string) ($row['name'] ?? '') === $column) {
                    return true;
                }
            }
            return false;
        }

        $stmt = $this->pdo->prepare("
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

    /**
     * @return array<int, array<string, mixed>>
     */
    public function criticalMonitors(int $limit = 6): array
    {
        $sql = "
            SELECT id, name, url, current_status, last_check_at, response_warning_ms
            FROM monitors
            WHERE is_active = 1
              AND current_status IN ('down', 'degraded')
            ORDER BY
                CASE current_status
                    WHEN 'down' THEN 0
                    WHEN 'degraded' THEN 1
                    ELSE 2
                END,
                last_check_at DESC
            LIMIT :limit
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recentChecks(int $monitorId, int $limit = 50): array
    {
        $sql = "
            SELECT id, checked_at, status, http_code, response_time_ms, error_message, final_url
            FROM checks
            WHERE monitor_id = :monitor_id
            ORDER BY id DESC
            LIMIT :limit
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':monitor_id', $monitorId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function uptimeSeriesByHour(int $monitorId, int $hours = 24): array
    {
        $cutoff = (new DateTimeImmutable('now'))
            ->sub(new DateInterval('PT' . max(1, $hours) . 'H'))
            ->format('Y-m-d H:i:s');

        $sql = "
            SELECT
                strftime('%Y-%m-%d %H:00:00', checked_at) AS bucket,
                ROUND(100.0 * SUM(CASE WHEN status = 'up' THEN 1 ELSE 0 END) / COUNT(*), 2) AS uptime_percent,
                ROUND(AVG(response_time_ms), 0) AS avg_response_ms,
                COUNT(*) AS sample_count
            FROM checks
            WHERE monitor_id = :monitor_id
              AND checked_at >= :cutoff
            GROUP BY strftime('%Y-%m-%d %H:00:00', checked_at)
            ORDER BY bucket ASC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'monitor_id' => $monitorId,
            'cutoff' => $cutoff,
        ]);
        return $stmt->fetchAll();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function uptimeSeriesByDay(int $monitorId, int $days = 7): array
    {
        $cutoff = (new DateTimeImmutable('now'))
            ->sub(new DateInterval('P' . max(1, $days - 1) . 'D'))
            ->setTime(0, 0, 0)
            ->format('Y-m-d H:i:s');

        $sql = "
            SELECT
                strftime('%Y-%m-%d', checked_at) AS bucket_day,
                ROUND(100.0 * SUM(CASE WHEN status = 'up' THEN 1 ELSE 0 END) / COUNT(*), 2) AS uptime_percent,
                ROUND(AVG(response_time_ms), 0) AS avg_response_ms,
                COUNT(*) AS sample_count
            FROM checks
            WHERE monitor_id = :monitor_id
              AND checked_at >= :cutoff
            GROUP BY strftime('%Y-%m-%d', checked_at)
            ORDER BY bucket_day ASC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'monitor_id' => $monitorId,
            'cutoff' => $cutoff,
        ]);
        return $stmt->fetchAll();
    }

    /**
     * @return array<string, mixed>
     */
    public function monitorHealthSummary(int $monitorId): array
    {
        $cutoff24h = (new DateTimeImmutable('now'))
            ->sub(new DateInterval('PT24H'))
            ->format('Y-m-d H:i:s');
        $cutoff7d = (new DateTimeImmutable('now'))
            ->sub(new DateInterval('P7D'))
            ->format('Y-m-d H:i:s');

        $sql24 = "
            SELECT
                COUNT(*) AS total_checks,
                SUM(CASE WHEN status = 'up' THEN 1 ELSE 0 END) AS up_checks,
                ROUND(AVG(response_time_ms), 0) AS avg_response_ms
            FROM checks
            WHERE monitor_id = :monitor_id
              AND checked_at >= :cutoff
        ";

        $stmt24 = $this->pdo->prepare($sql24);
        $stmt24->execute([
            'monitor_id' => $monitorId,
            'cutoff' => $cutoff24h,
        ]);
        $row24 = $stmt24->fetch();

        $stmt7 = $this->pdo->prepare($sql24);
        $stmt7->execute([
            'monitor_id' => $monitorId,
            'cutoff' => $cutoff7d,
        ]);
        $row7 = $stmt7->fetch();

        $incSql = "
            SELECT
                SUM(CASE WHEN resolved_at IS NULL THEN 1 ELSE 0 END) AS open_incidents,
                SUM(CASE WHEN started_at >= :cutoff7d THEN 1 ELSE 0 END) AS incidents_7d
            FROM incidents
            WHERE monitor_id = :monitor_id
        ";
        $incStmt = $this->pdo->prepare($incSql);
        $incStmt->execute([
            'monitor_id' => $monitorId,
            'cutoff7d' => $cutoff7d,
        ]);
        $incRow = $incStmt->fetch();

        $total24 = is_array($row24) ? (int) ($row24['total_checks'] ?? 0) : 0;
        $up24 = is_array($row24) ? (int) ($row24['up_checks'] ?? 0) : 0;
        $total7 = is_array($row7) ? (int) ($row7['total_checks'] ?? 0) : 0;
        $up7 = is_array($row7) ? (int) ($row7['up_checks'] ?? 0) : 0;

        return [
            'checks_24h' => $total24,
            'checks_7d' => $total7,
            'uptime_24h_percent' => $total24 > 0 ? round(($up24 / $total24) * 100, 2) : 0.0,
            'uptime_7d_percent' => $total7 > 0 ? round(($up7 / $total7) * 100, 2) : 0.0,
            'avg_response_24h_ms' => is_array($row24) && $row24['avg_response_ms'] !== null ? (int) $row24['avg_response_ms'] : null,
            'avg_response_7d_ms' => is_array($row7) && $row7['avg_response_ms'] !== null ? (int) $row7['avg_response_ms'] : null,
            'open_incidents' => is_array($incRow) ? (int) ($incRow['open_incidents'] ?? 0) : 0,
            'incidents_7d' => is_array($incRow) ? (int) ($incRow['incidents_7d'] ?? 0) : 0,
        ];
    }
}
