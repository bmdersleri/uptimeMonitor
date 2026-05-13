<?php

declare(strict_types=1);

final class IncidentRepository
{
    /** @var PDO */
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function openIncidentForMonitor(int $monitorId): ?array
    {
        $sql = "
            SELECT *
            FROM incidents
            WHERE monitor_id = :monitor_id
              AND resolved_at IS NULL
            ORDER BY id DESC
            LIMIT 1
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['monitor_id' => $monitorId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function open(int $monitorId, string $reason, string $lastError): void
    {
        $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $sql = "
            INSERT INTO incidents (
                monitor_id,
                started_at,
                reason,
                last_error,
                created_at
            ) VALUES (
                :monitor_id,
                :started_at,
                :reason,
                :last_error,
                :created_at
            )
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'monitor_id' => $monitorId,
            'started_at' => $now,
            'reason' => $reason,
            'last_error' => $lastError,
            'created_at' => $now,
        ]);
    }

    public function closeOpenIncident(int $monitorId): void
    {
        $openIncident = $this->openIncidentForMonitor($monitorId);
        if ($openIncident === null) {
            return;
        }

        $startedAt = new DateTimeImmutable((string) $openIncident['started_at']);
        $now = new DateTimeImmutable('now');
        $duration = $now->getTimestamp() - $startedAt->getTimestamp();

        $sql = "
            UPDATE incidents
            SET
                resolved_at = :resolved_at,
                duration_seconds = :duration_seconds,
                updated_at = :updated_at
            WHERE id = :id
        ";
        $nowString = $now->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'resolved_at' => $nowString,
            'duration_seconds' => max($duration, 0),
            'updated_at' => $nowString,
            'id' => (int) $openIncident['id'],
        ]);
    }

    public function markOpenNotificationSent(int $incidentId): void
    {
        $sql = "UPDATE incidents SET notification_sent = 1, updated_at = :updated_at WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'updated_at' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
            'id' => $incidentId,
        ]);
    }

    public function markRecoveryNotificationSent(int $incidentId): void
    {
        $sql = "UPDATE incidents SET recovery_notification_sent = 1, updated_at = :updated_at WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'updated_at' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
            'id' => $incidentId,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function latestResolvedForMonitor(int $monitorId): ?array
    {
        $sql = "
            SELECT *
            FROM incidents
            WHERE monitor_id = :monitor_id
              AND resolved_at IS NOT NULL
            ORDER BY id DESC
            LIMIT 1
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['monitor_id' => $monitorId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recentForMonitor(int $monitorId, int $limit = 20): array
    {
        $sql = "
            SELECT *
            FROM incidents
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
}
