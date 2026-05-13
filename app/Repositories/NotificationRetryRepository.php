<?php

declare(strict_types=1);

final class NotificationRetryRepository
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
    public function list(array $filters, int $limit = 300): array
    {
        $where = [];
        $params = [];

        if (isset($filters['status']) && (string) $filters['status'] !== '' && (string) $filters['status'] !== 'all') {
            $where[] = 'status = :status';
            $params['status'] = (string) $filters['status'];
        }
        if (isset($filters['channel']) && (string) $filters['channel'] !== '' && (string) $filters['channel'] !== 'all') {
            $where[] = 'channel = :channel';
            $params['channel'] = (string) $filters['channel'];
        }

        $sql = "SELECT * FROM notification_retry_queue";
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY id DESC LIMIT :limit';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, (string) $value);
        }
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        $sql = "
            SELECT
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
                SUM(CASE WHEN status = 'retrying' THEN 1 ELSE 0 END) AS retrying_count,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_count,
                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS sent_count
            FROM notification_retry_queue
        ";
        $row = $this->pdo->query($sql)->fetch();
        return [
            'pending_count' => is_array($row) ? (int) ($row['pending_count'] ?? 0) : 0,
            'retrying_count' => is_array($row) ? (int) ($row['retrying_count'] ?? 0) : 0,
            'failed_count' => is_array($row) ? (int) ($row['failed_count'] ?? 0) : 0,
            'sent_count' => is_array($row) ? (int) ($row['sent_count'] ?? 0) : 0,
        ];
    }

    public function markRetryNow(int $id): bool
    {
        $sql = "
            UPDATE notification_retry_queue
            SET status = 'pending',
                next_attempt_at = :next_attempt_at,
                updated_at = :updated_at
            WHERE id = :id
              AND status IN ('pending','retrying','failed')
        ";
        $ts = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'next_attempt_at' => $ts,
            'updated_at' => $ts,
            'id' => $id,
        ]);

        return $stmt->rowCount() > 0;
    }
}
