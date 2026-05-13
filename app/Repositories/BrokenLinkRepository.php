<?php

declare(strict_types=1);

final class BrokenLinkRepository
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
    public function listWithMonitor(array $filters, int $limit = 200, int $offset = 0): array
    {
        $query = $this->buildFilter($filters);
        $where = $query['where'];
        $params = $query['params'];

        $sql = "
            SELECT
                b.*,
                m.name AS monitor_name,
                m.url AS monitor_url
            FROM broken_links b
            INNER JOIN monitors m ON m.id = b.monitor_id
        ";
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY b.last_detected_at DESC, b.id DESC LIMIT :limit OFFSET :offset';

        $stmt = $this->pdo->prepare($sql);
        $this->bindFilterParams($stmt, $params);
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
        $query = $this->buildFilter($filters);
        $where = $query['where'];
        $params = $query['params'];

        $sql = "
            SELECT COUNT(*) AS total
            FROM broken_links b
            INNER JOIN monitors m ON m.id = b.monitor_id
        ";
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $stmt = $this->pdo->prepare($sql);
        $this->bindFilterParams($stmt, $params);
        $stmt->execute();
        $row = $stmt->fetch();
        return is_array($row) ? (int) ($row['total'] ?? 0) : 0;
    }

    public function deleteResolvedOlderThan(int $olderThanDays): int
    {
        if ($olderThanDays <= 0) {
            $stmt = $this->pdo->prepare("DELETE FROM broken_links WHERE resolved_at IS NOT NULL");
            $stmt->execute();
            return $stmt->rowCount();
        }

        $threshold = (new DateTimeImmutable('now'))
            ->sub(new DateInterval('P' . max(1, $olderThanDays) . 'D'))
            ->format('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare("
            DELETE FROM broken_links
            WHERE resolved_at IS NOT NULL
              AND resolved_at <= :threshold
        ");
        $stmt->execute(['threshold' => $threshold]);
        return $stmt->rowCount();
    }

    /**
     * @return array<string, int>
     */
    public function quickCounts(): array
    {
        $sql = "
            SELECT
                SUM(CASE WHEN resolved_at IS NULL THEN 1 ELSE 0 END) AS active_count,
                SUM(CASE WHEN resolved_at IS NOT NULL THEN 1 ELSE 0 END) AS resolved_count
            FROM broken_links
        ";
        $row = $this->pdo->query($sql)->fetch();
        return [
            'active_count' => is_array($row) ? (int) ($row['active_count'] ?? 0) : 0,
            'resolved_count' => is_array($row) ? (int) ($row['resolved_count'] ?? 0) : 0,
        ];
    }

    /**
     * @return array<string, int>
     */
    public function summaryForMonitor(int $monitorId): array
    {
        $sql = "
            SELECT
                SUM(CASE WHEN resolved_at IS NULL THEN 1 ELSE 0 END) AS active_count,
                SUM(CASE WHEN resolved_at IS NOT NULL THEN 1 ELSE 0 END) AS resolved_count
            FROM broken_links
            WHERE monitor_id = :monitor_id
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['monitor_id' => $monitorId]);
        $row = $stmt->fetch();

        return [
            'active_count' => is_array($row) ? (int) ($row['active_count'] ?? 0) : 0,
            'resolved_count' => is_array($row) ? (int) ($row['resolved_count'] ?? 0) : 0,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function activeForMonitor(int $monitorId, int $limit = 8): array
    {
        $sql = "
            SELECT *
            FROM broken_links
            WHERE monitor_id = :monitor_id
              AND resolved_at IS NULL
            ORDER BY last_detected_at DESC
            LIMIT :limit
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':monitor_id', $monitorId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{where: array<int, string>, params: array<string, mixed>}
     */
    private function buildFilter(array $filters): array
    {
        $where = [];
        $params = [];

        if (isset($filters['monitor_id']) && (int) $filters['monitor_id'] > 0) {
            $where[] = 'b.monitor_id = :monitor_id';
            $params['monitor_id'] = (int) $filters['monitor_id'];
        }

        $status = isset($filters['status']) ? (string) $filters['status'] : 'active';
        if ($status === 'resolved') {
            $where[] = 'b.resolved_at IS NOT NULL';
        } else {
            $where[] = 'b.resolved_at IS NULL';
        }

        if (isset($filters['code']) && (string) $filters['code'] !== '') {
            $where[] = 'b.status_code = :status_code';
            $params['status_code'] = (int) $filters['code'];
        }

        if (isset($filters['q']) && trim((string) $filters['q']) !== '') {
            $where[] = '(b.target_url LIKE :q OR b.source_url LIKE :q OR b.error_message LIKE :q)';
            $params['q'] = '%' . trim((string) $filters['q']) . '%';
        }

        return ['where' => $where, 'params' => $params];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function bindFilterParams(PDOStatement $stmt, array $params): void
    {
        foreach ($params as $key => $value) {
            if ($key === 'status_code' || $key === 'monitor_id') {
                $stmt->bindValue(':' . $key, (int) $value, PDO::PARAM_INT);
            } else {
                $stmt->bindValue(':' . $key, (string) $value);
            }
        }
    }
}
