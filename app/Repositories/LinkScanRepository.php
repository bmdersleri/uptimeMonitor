<?php

declare(strict_types=1);

final class LinkScanRepository
{
    /** @var PDO */
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listWithMonitor(array $filters, int $limit = 300, int $offset = 0): array
    {
        $query = $this->buildListFilter($filters);
        $where = $query['where'];
        $params = $query['params'];

        $sql = "
            SELECT
                j.*,
                m.name AS monitor_name,
                m.url AS monitor_url
            FROM link_scan_jobs j
            INNER JOIN monitors m ON m.id = j.monitor_id
        ";
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY j.id DESC LIMIT :limit OFFSET :offset';

        $stmt = $this->pdo->prepare($sql);
        $this->bindListParams($stmt, $params);
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function countWithMonitor(array $filters): int
    {
        $query = $this->buildListFilter($filters);
        $where = $query['where'];
        $params = $query['params'];

        $sql = "
            SELECT COUNT(*) AS total
            FROM link_scan_jobs j
            INNER JOIN monitors m ON m.id = j.monitor_id
        ";
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $stmt = $this->pdo->prepare($sql);
        $this->bindListParams($stmt, $params);
        $stmt->execute();
        $row = $stmt->fetch();
        return is_array($row) ? (int) ($row['total'] ?? 0) : 0;
    }

    /**
     * @return array<string, int>
     */
    public function quickCounts(): array
    {
        $sql = "
            SELECT
                SUM(CASE WHEN status = 'running' THEN 1 ELSE 0 END) AS running_count,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_count,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_count
            FROM link_scan_jobs
        ";
        $row = $this->pdo->query($sql)->fetch();
        return [
            'running_count' => is_array($row) ? (int) ($row['running_count'] ?? 0) : 0,
            'completed_count' => is_array($row) ? (int) ($row['completed_count'] ?? 0) : 0,
            'failed_count' => is_array($row) ? (int) ($row['failed_count'] ?? 0) : 0,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function latestForMonitor(int $monitorId): ?array
    {
        $sql = "
            SELECT *
            FROM link_scan_jobs
            WHERE monitor_id = :monitor_id
            ORDER BY id DESC
            LIMIT 1
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['monitor_id' => $monitorId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function latestWithMonitorByMonitorId(int $monitorId): ?array
    {
        $sql = "
            SELECT j.*, m.name AS monitor_name, m.url AS monitor_url
            FROM link_scan_jobs j
            INNER JOIN monitors m ON m.id = j.monitor_id
            WHERE j.monitor_id = :monitor_id
            ORDER BY j.id DESC
            LIMIT 1
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['monitor_id' => $monitorId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findRunningWithMonitor(int $monitorId = 0): ?array
    {
        $sql = "
            SELECT j.*, m.name AS monitor_name, m.url AS monitor_url
            FROM link_scan_jobs j
            INNER JOIN monitors m ON m.id = j.monitor_id
            WHERE j.status = 'running'
        ";
        $params = [];
        if ($monitorId > 0) {
            $sql .= " AND j.monitor_id = :monitor_id";
            $params['monitor_id'] = $monitorId;
        }
        $sql .= " ORDER BY j.id DESC LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v, PDO::PARAM_INT);
        }
        $stmt->execute();
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findAnyRunningWithMonitor(): ?array
    {
        return $this->findRunningWithMonitor(0);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findWithMonitor(int $jobId): ?array
    {
        $sql = "
            SELECT j.*, m.name AS monitor_name, m.url AS monitor_url
            FROM link_scan_jobs j
            INNER JOIN monitors m ON m.id = j.monitor_id
            WHERE j.id = :id
            LIMIT 1
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $jobId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public function cancelRunningJob(int $jobId, string $message = 'Canceled by user'): bool
    {
        if ($jobId < 1) {
            return false;
        }

        $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare("
            UPDATE link_scan_jobs
            SET
                finished_at = :finished_at,
                status = 'failed',
                duration_seconds = MAX(0, CAST(strftime('%s', :finished_at) AS INTEGER) - CAST(strftime('%s', started_at) AS INTEGER)),
                error_message = :error_message
            WHERE id = :id
              AND status = 'running'
        ");
        $stmt->execute([
            'finished_at' => $now,
            'error_message' => $message,
            'id' => $jobId,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function deleteJob(int $jobId): bool
    {
        if ($jobId < 1) {
            return false;
        }

        $stmt = $this->pdo->prepare("DELETE FROM link_scan_jobs WHERE id = :id");
        $stmt->execute(['id' => $jobId]);
        return $stmt->rowCount() > 0;
    }

    public function closeStaleRunningJobs(int $staleMinutes): int
    {
        $staleMinutes = max(10, $staleMinutes);
        $now = new DateTimeImmutable('now');
        $threshold = $now->sub(new DateInterval('PT' . $staleMinutes . 'M'))->format('Y-m-d H:i:s');
        $finishedAt = $now->format('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare("
            UPDATE link_scan_jobs
            SET
                finished_at = :finished_at,
                status = 'failed',
                duration_seconds = MAX(0, CAST(strftime('%s', :finished_at) AS INTEGER) - CAST(strftime('%s', started_at) AS INTEGER)),
                error_message = 'Marked stale automatically'
            WHERE status = 'running'
              AND started_at <= :threshold
        ");
        $stmt->execute([
            'finished_at' => $finishedAt,
            'threshold' => $threshold,
        ]);

        return $stmt->rowCount();
    }

    public function deleteOldFinishedJobs(int $olderThanDays): int
    {
        if ($olderThanDays <= 0) {
            $stmt = $this->pdo->prepare("DELETE FROM link_scan_jobs WHERE status <> 'running'");
            $stmt->execute();
            return $stmt->rowCount();
        }

        $threshold = (new DateTimeImmutable('now'))
            ->sub(new DateInterval('P' . max(1, $olderThanDays) . 'D'))
            ->format('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare("
            DELETE FROM link_scan_jobs
            WHERE status <> 'running'
              AND COALESCE(finished_at, started_at) <= :threshold
        ");
        $stmt->execute(['threshold' => $threshold]);
        return $stmt->rowCount();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function brokenTargetsForJob(int $jobId, int $limit = 80): array
    {
        $sql = "
            SELECT
                b.id,
                b.source_url,
                b.target_url,
                b.status_code,
                b.error_type,
                b.error_message,
                b.occurrence_count,
                b.last_detected_at,
                b.resolved_at
            FROM broken_links b
            INNER JOIN link_scan_jobs j ON j.monitor_id = b.monitor_id
            WHERE j.id = :job_id
              AND b.last_detected_at >= j.started_at
              AND b.last_detected_at <= COALESCE(j.finished_at, datetime('now'))
            ORDER BY b.last_detected_at DESC, b.id DESC
            LIMIT :limit
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':job_id', $jobId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * @return array{job: array<string, mixed>|null, top_targets: array<int, array<string, mixed>>, top_sources: array<int, array<string, mixed>>, status_codes: array<int, array<string, mixed>>}
     */
    public function qualityReportForJob(int $jobId): array
    {
        $job = $this->findWithMonitor($jobId);
        if ($job === null) {
            return [
                'job' => null,
                'top_targets' => [],
                'top_sources' => [],
                'status_codes' => [],
            ];
        }

        return [
            'job' => $job,
            'top_targets' => $this->qualityRows($jobId, 'target_url'),
            'top_sources' => $this->qualitySourceRows($jobId),
            'status_codes' => $this->qualityStatusCodes($jobId),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{where: array<int, string>, params: array<string, mixed>}
     */
    private function buildListFilter(array $filters): array
    {
        $where = [];
        $params = [];

        if (isset($filters['monitor_id']) && (int) $filters['monitor_id'] > 0) {
            $where[] = 'j.monitor_id = :monitor_id';
            $params['monitor_id'] = (int) $filters['monitor_id'];
        }

        if (isset($filters['status']) && (string) $filters['status'] !== '' && (string) $filters['status'] !== 'all') {
            $where[] = 'j.status = :status';
            $params['status'] = (string) $filters['status'];
        }

        $days = isset($filters['days']) ? (int) $filters['days'] : 0;
        if ($days > 0) {
            $startedAfter = (new DateTimeImmutable('now'))
                ->sub(new DateInterval('P' . min(3650, $days) . 'D'))
                ->format('Y-m-d H:i:s');
            $where[] = 'j.started_at >= :started_after';
            $params['started_after'] = $startedAfter;
        }

        return [
            'where' => $where,
            'params' => $params,
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function bindListParams(PDOStatement $stmt, array $params): void
    {
        foreach ($params as $key => $value) {
            if ($key === 'monitor_id') {
                $stmt->bindValue(':' . $key, (int) $value, PDO::PARAM_INT);
            } else {
                $stmt->bindValue(':' . $key, (string) $value);
            }
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function qualityRows(int $jobId, string $groupColumn): array
    {
        $selectColumn = $groupColumn === 'source_url' ? 'source_url' : 'target_url';
        $sql = "
            SELECT
                b.{$selectColumn},
                b.status_code,
                COUNT(*) AS hit_count,
                MAX(b.last_detected_at) AS last_detected_at
            FROM broken_links b
            INNER JOIN link_scan_jobs j ON j.monitor_id = b.monitor_id
            WHERE j.id = :job_id
              AND b.last_detected_at >= j.started_at
              AND b.last_detected_at <= COALESCE(j.finished_at, datetime('now'))
              AND b.ignored_at IS NULL
            GROUP BY b.{$selectColumn}, b.status_code
            ORDER BY hit_count DESC, b.{$selectColumn} ASC
            LIMIT 10
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['job_id' => $jobId]);
        return $stmt->fetchAll();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function qualityStatusCodes(int $jobId): array
    {
        $sql = "
            SELECT
                COALESCE(b.status_code, 0) AS status_code,
                COUNT(*) AS hit_count
            FROM broken_links b
            INNER JOIN link_scan_jobs j ON j.monitor_id = b.monitor_id
            WHERE j.id = :job_id
              AND b.last_detected_at >= j.started_at
              AND b.last_detected_at <= COALESCE(j.finished_at, datetime('now'))
              AND b.ignored_at IS NULL
            GROUP BY COALESCE(b.status_code, 0)
            ORDER BY hit_count DESC, status_code ASC
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['job_id' => $jobId]);
        return $stmt->fetchAll();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function qualitySourceRows(int $jobId): array
    {
        $sql = "
            SELECT
                b.source_url,
                COUNT(DISTINCT b.target_url) AS hit_count,
                MAX(b.last_detected_at) AS last_detected_at
            FROM broken_links b
            INNER JOIN link_scan_jobs j ON j.monitor_id = b.monitor_id
            WHERE j.id = :job_id
              AND b.last_detected_at >= j.started_at
              AND b.last_detected_at <= COALESCE(j.finished_at, datetime('now'))
              AND b.ignored_at IS NULL
            GROUP BY b.source_url
            ORDER BY hit_count DESC, b.source_url ASC
            LIMIT 10
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['job_id' => $jobId]);
        return $stmt->fetchAll();
    }
}
