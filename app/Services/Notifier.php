<?php

declare(strict_types=1);

final class Notifier
{
    /** @var PDO */
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function sendIncidentOpened(array $monitor, array $result, int $incidentId): bool
    {
        $subject = (string) config('notifications.subject_prefix', '[Uptime]')
            . ' '
            . (string) config('notifications.events.incident_opened', 'DOWN')
            . ': '
            . (string) $monitor['name'];
        $message = "Monitor DOWN\n"
            . 'Monitor: ' . (string) $monitor['name'] . "\n"
            . 'URL: ' . (string) $monitor['url'] . "\n"
            . 'Status: ' . (string) $result['status'] . "\n"
            . 'HTTP: ' . (string) $result['http_code'] . "\n"
            . 'Response: ' . (string) $result['response_time_ms'] . " ms\n"
            . 'Error: ' . (string) ($result['error_message'] ?? '-') . "\n"
            . 'Time: ' . (new DateTimeImmutable('now'))->format('Y-m-d H:i:s') . "\n"
            . 'Incident ID: #' . $incidentId;

        return $this->sendAllChannels($subject, $message, (int) $monitor['id'], $incidentId, 'incident_opened');
    }

    public function sendIncidentRecovered(array $monitor, array $result, int $incidentId): bool
    {
        $subject = (string) config('notifications.subject_prefix', '[Uptime]')
            . ' '
            . (string) config('notifications.events.incident_recovered', 'RECOVERED')
            . ': '
            . (string) $monitor['name'];
        $message = "Monitor RECOVERED\n"
            . 'Monitor: ' . (string) $monitor['name'] . "\n"
            . 'URL: ' . (string) $monitor['url'] . "\n"
            . 'Status: ' . (string) $result['status'] . "\n"
            . 'HTTP: ' . (string) $result['http_code'] . "\n"
            . 'Response: ' . (string) $result['response_time_ms'] . " ms\n"
            . 'Time: ' . (new DateTimeImmutable('now'))->format('Y-m-d H:i:s') . "\n"
            . 'Incident ID: #' . $incidentId;

        return $this->sendAllChannels($subject, $message, (int) $monitor['id'], $incidentId, 'incident_recovered');
    }

    /**
     * @param array<int, array<string, mixed>> $brokenItems
     */
    public function sendBrokenLinkSummary(array $monitor, array $brokenItems, int $jobId): bool
    {
        if ($brokenItems === []) {
            return false;
        }

        $prefix = (string) config('notifications.subject_prefix', '[Uptime]');
        $event = (string) config('notifications.events.broken_link_summary', 'BROKEN LINKS');
        $subject = $prefix . ' ' . $event . ': ' . (string) $monitor['name'];

        $lines = [];
        $lines[] = "Broken Link Scan Summary";
        $lines[] = 'Monitor: ' . (string) $monitor['name'];
        $lines[] = 'URL: ' . (string) $monitor['url'];
        $lines[] = 'New/unsent broken resources: ' . count($brokenItems);
        $lines[] = 'Scan Job ID: #' . $jobId;
        $lines[] = 'Time: ' . (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $lines[] = '';
        $lines[] = 'Examples:';

        $maxExamples = (int) config('notifications.broken_links.max_examples', 8);
        $i = 0;
        foreach ($brokenItems as $item) {
            $i++;
            if ($i > $maxExamples) {
                break;
            }
            $lines[] = $i . '. ' . (string) ($item['target_url'] ?? '-')
                . ' (' . (string) ($item['status_code'] ?? '-') . ')'
                . ' from ' . (string) ($item['source_url'] ?? '-');
        }

        return $this->sendAllChannels($subject, implode("\n", $lines), (int) $monitor['id'], null, 'broken_link_summary');
    }

    public function processRetryQueue(int $limit = 30): array
    {
        $limit = max(1, $limit);
        $now = new DateTimeImmutable('now');
        try {
            $sql = "
                SELECT *
                FROM notification_retry_queue
                WHERE status IN ('pending','retrying')
                  AND next_attempt_at <= :now
                  AND attempt_count < max_attempts
                ORDER BY id ASC
                LIMIT :limit
            ";
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':now', $now->format('Y-m-d H:i:s'));
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll();
        } catch (Exception $e) {
            return [
                'processed' => 0,
                'sent' => 0,
                'failed' => 0,
            ];
        }

        $processed = 0;
        $sent = 0;
        $failed = 0;

        foreach ($rows as $row) {
            $processed++;
            $result = $this->processRetryRow($row, $now);
            if ($result) {
                $sent++;
            } else {
                $failed++;
            }
        }

        return [
            'processed' => $processed,
            'sent' => $sent,
            'failed' => $failed,
        ];
    }

    public function processRetryQueueById(int $id): bool
    {
        if ($id < 1) {
            return false;
        }
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM notification_retry_queue WHERE id = :id LIMIT 1");
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch();
            if (!is_array($row)) {
                return false;
            }
            return $this->processRetryRow($row, new DateTimeImmutable('now'));
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function processRetryRow(array $row, DateTimeImmutable $now): bool
    {
        $maxAttempts = (int) config('notifications.retry.max_attempts', 5);
        $baseDelay = (int) config('notifications.retry.base_delay_minutes', 5);

        $payload = json_decode((string) ($row['payload_json'] ?? '{}'), true);
        if (!is_array($payload)) {
            $this->failQueueItem((int) $row['id'], 'Invalid payload JSON', (int) $row['attempt_count'] + 1);
            return false;
        }

        $channel = (string) ($row['channel'] ?? '');
        $subject = (string) ($payload['subject'] ?? '');
        $message = (string) ($payload['message'] ?? '');
        $monitorId = (int) ($payload['monitor_id'] ?? 0);
        $incidentId = isset($payload['incident_id']) && $payload['incident_id'] !== null ? (int) $payload['incident_id'] : null;
        $eventType = (string) ($payload['event_type'] ?? 'retry_event');

        $ok = false;
        if ($channel === 'email') {
            $ok = $this->sendEmail($subject, $message, $monitorId, $incidentId, $eventType, false);
        } elseif ($channel === 'telegram') {
            $ok = $this->sendTelegram($message, $monitorId, $incidentId, $eventType, false);
        } else {
            $this->failQueueItem((int) $row['id'], 'Unknown channel', (int) $row['attempt_count'] + 1);
            return false;
        }

        if ($ok) {
            $this->markQueueSent((int) $row['id']);
            return true;
        }

        $attempt = (int) $row['attempt_count'] + 1;
        $delay = $baseDelay * (int) pow(2, max(0, $attempt - 1));
        $nextAt = $now->add(new DateInterval('PT' . max(1, $delay) . 'M'))->format('Y-m-d H:i:s');

        $status = $attempt >= max(1, $maxAttempts) ? 'failed' : 'retrying';
        $update = $this->pdo->prepare("
            UPDATE notification_retry_queue
            SET attempt_count = :attempt_count,
                status = :status,
                next_attempt_at = :next_attempt_at,
                updated_at = :updated_at,
                last_error = :last_error
            WHERE id = :id
        ");
        $update->execute([
            'attempt_count' => $attempt,
            'status' => $status,
            'next_attempt_at' => $nextAt,
            'updated_at' => $now->format('Y-m-d H:i:s'),
            'last_error' => 'Retry failed',
            'id' => (int) $row['id'],
        ]);

        return false;
    }

    private function sendAllChannels(string $subject, string $message, int $monitorId, ?int $incidentId, string $eventType): bool
    {
        $enabled = 0;
        $successful = 0;

        if (((string) config('NOTIFY_EMAIL_ENABLED', 'false')) === 'true') {
            $enabled++;
            if ($this->sendEmail($subject, $message, $monitorId, $incidentId, $eventType, true)) {
                $successful++;
            }
        }

        if (((string) config('NOTIFY_TELEGRAM_ENABLED', 'false')) === 'true') {
            $enabled++;
            if ($this->sendTelegram($message, $monitorId, $incidentId, $eventType, true)) {
                $successful++;
            }
        }

        return $enabled > 0 && $successful > 0;
    }

    private function sendEmail(
        string $subject,
        string $message,
        int $monitorId,
        ?int $incidentId,
        string $eventType,
        bool $queueOnFail
    ): bool {
        $to = (string) config('NOTIFY_EMAIL_TO', '');
        if ($to === '') {
            $error = 'NOTIFY_EMAIL_TO not set';
            $this->logNotification($monitorId, $incidentId, $eventType, 'email', 'failed', $error);
            if ($queueOnFail) {
                $this->enqueueRetry('email', $subject, $message, $monitorId, $incidentId, $eventType, $error);
            }
            return false;
        }

        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
        ];

        $ok = @mail($to, $subject, $message, implode("\r\n", $headers));
        $error = $ok ? null : 'mail() returned false';
        $this->logNotification($monitorId, $incidentId, $eventType, 'email', $ok ? 'sent' : 'failed', $error);

        if (!$ok && $queueOnFail) {
            $this->enqueueRetry('email', $subject, $message, $monitorId, $incidentId, $eventType, $error);
        }

        return $ok;
    }

    private function sendTelegram(
        string $message,
        int $monitorId,
        ?int $incidentId,
        string $eventType,
        bool $queueOnFail
    ): bool {
        $token = (string) config('TELEGRAM_BOT_TOKEN', '');
        $chatId = (string) config('TELEGRAM_DEFAULT_CHAT_ID', '');
        $subject = (string) config('notifications.subject_prefix', '[Uptime]') . ' ' . strtoupper($eventType);

        if ($token === '' || $chatId === '') {
            $error = 'Telegram config missing';
            $this->logNotification($monitorId, $incidentId, $eventType, 'telegram', 'failed', $error);
            if ($queueOnFail) {
                $this->enqueueRetry('telegram', $subject, $message, $monitorId, $incidentId, $eventType, $error);
            }
            return false;
        }

        $endpoint = 'https://api.telegram.org/bot' . rawurlencode($token) . '/sendMessage';
        $payload = http_build_query([
            'chat_id' => $chatId,
            'text' => $message,
        ]);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $endpoint,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 12,
        ]);

        $body = curl_exec($ch);
        $curlError = $body === false ? (curl_error($ch) ?: 'curl error') : null;
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $ok = $curlError === null && $httpCode >= 200 && $httpCode < 300;
        $details = $ok ? null : ($curlError !== null ? $curlError : ('HTTP ' . $httpCode));

        $this->logNotification($monitorId, $incidentId, $eventType, 'telegram', $ok ? 'sent' : 'failed', $details);
        if (!$ok && $queueOnFail) {
            $this->enqueueRetry('telegram', $subject, $message, $monitorId, $incidentId, $eventType, $details);
        }

        return $ok;
    }

    private function enqueueRetry(
        string $channel,
        string $subject,
        string $message,
        int $monitorId,
        ?int $incidentId,
        string $eventType,
        ?string $lastError
    ): void {
        $maxAttempts = (int) config('notifications.retry.max_attempts', 5);
        $baseDelay = (int) config('notifications.retry.base_delay_minutes', 5);
        $now = new DateTimeImmutable('now');
        $next = $now->add(new DateInterval('PT' . max(1, $baseDelay) . 'M'))->format('Y-m-d H:i:s');

        $payload = json_encode([
            'subject' => $subject,
            'message' => $message,
            'monitor_id' => $monitorId,
            'incident_id' => $incidentId,
            'event_type' => $eventType,
        ]);
        if (!is_string($payload)) {
            return;
        }

        $sql = "
            INSERT INTO notification_retry_queue (
                channel, payload_json, attempt_count, max_attempts, status, next_attempt_at, last_error, created_at, updated_at
            ) VALUES (
                :channel, :payload_json, 0, :max_attempts, 'pending', :next_attempt_at, :last_error, :created_at, :updated_at
            )
        ";
        try {
            $stmt = $this->pdo->prepare($sql);
            $ts = $now->format('Y-m-d H:i:s');
            $stmt->execute([
                'channel' => $channel,
                'payload_json' => $payload,
                'max_attempts' => max(1, $maxAttempts),
                'next_attempt_at' => $next,
                'last_error' => $lastError,
                'created_at' => $ts,
                'updated_at' => $ts,
            ]);
        } catch (Exception $e) {
            // Retry queue table may not exist yet on old deployments.
        }
    }

    private function markQueueSent(int $id): void
    {
        $ts = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $sql = "
            UPDATE notification_retry_queue
            SET status = 'sent', sent_at = :sent_at, updated_at = :updated_at
            WHERE id = :id
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'sent_at' => $ts,
            'updated_at' => $ts,
            'id' => $id,
        ]);
    }

    private function failQueueItem(int $id, string $error, int $attempt): void
    {
        $ts = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $sql = "
            UPDATE notification_retry_queue
            SET attempt_count = :attempt_count, status = 'failed', last_error = :last_error, updated_at = :updated_at
            WHERE id = :id
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'attempt_count' => $attempt,
            'last_error' => $error,
            'updated_at' => $ts,
            'id' => $id,
        ]);
    }

    private function logNotification(
        int $monitorId,
        ?int $incidentId,
        string $eventType,
        string $channel,
        string $status,
        ?string $errorMessage
    ): void {
        $sql = "
            INSERT INTO notification_logs (
                monitor_id,
                incident_id,
                event_type,
                channel,
                status,
                error_message,
                created_at
            ) VALUES (
                :monitor_id,
                :incident_id,
                :event_type,
                :channel,
                :status,
                :error_message,
                :created_at
            )
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'monitor_id' => $monitorId,
            'incident_id' => $incidentId,
            'event_type' => $eventType,
            'channel' => $channel,
            'status' => $status,
            'error_message' => $errorMessage,
            'created_at' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
        ]);
    }
}
