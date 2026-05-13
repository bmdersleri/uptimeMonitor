<?php

declare(strict_types=1);

final class ReportService
{
    /** @var PDO */
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureReportRunsTable();
        $this->ensureCompatibilityColumns();
    }

    /**
     * @return array<string, mixed>
     */
    public function generate(string $type, ?DateTimeImmutable $now = null): array
    {
        $type = $type === 'weekly' ? 'weekly' : 'daily';
        $now = $now ?: new DateTimeImmutable('now');
        $start = $type === 'weekly' ? $now->sub(new DateInterval('P7D')) : $now->sub(new DateInterval('P1D'));

        $periodStart = $start->format('Y-m-d H:i:s');
        $periodEnd = $now->format('Y-m-d H:i:s');
        $summary = $this->summary($periodStart, $periodEnd);
        $monitors = $this->monitorRows($periodStart);

        $subject = (string) config('notifications.subject_prefix', '[Uptime]')
            . ' '
            . ($type === 'weekly' ? 'WEEKLY REPORT' : 'DAILY REPORT')
            . ' '
            . $now->format('Y-m-d');

        $report = [
            'type' => $type,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'subject' => $subject,
            'summary' => $summary,
            'monitors' => $monitors,
        ];
        $report['body'] = $this->renderText($report);

        return $report;
    }

    /**
     * @return array<string, mixed>
     */
    public function createAndSend(string $type, ?DateTimeImmutable $now = null): array
    {
        $report = $this->generate($type, $now);
        $delivery = $this->sendChannels((string) $report['subject'], (string) $report['body']);
        $id = $this->insertRun($report, $delivery);

        return [
            'id' => $id,
            'report' => $report,
            'delivery' => $delivery,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function resend(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT * FROM report_runs WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }

        $delivery = $this->sendChannels((string) $row['subject'], (string) $row['body']);
        $this->updateRunDelivery($id, $delivery);
        $row['email_status'] = $delivery['email_status'];
        $row['telegram_status'] = $delivery['telegram_status'];
        $row['email_error'] = $delivery['email_error'];
        $row['telegram_error'] = $delivery['telegram_error'];

        return [
            'id' => $id,
            'report' => $row,
            'delivery' => $delivery,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recentRuns(int $limit = 30): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM report_runs ORDER BY id DESC LIMIT :limit');
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(string $periodStart, string $periodEnd): array
    {
        $status = $this->fetchOne("
            SELECT
                COUNT(*) AS total_monitors,
                SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active_monitors,
                SUM(CASE WHEN is_active = 1 AND current_status = 'up' THEN 1 ELSE 0 END) AS up_monitors,
                SUM(CASE WHEN is_active = 1 AND current_status = 'down' THEN 1 ELSE 0 END) AS down_monitors,
                SUM(CASE WHEN is_active = 1 AND current_status = 'degraded' THEN 1 ELSE 0 END) AS degraded_monitors
            FROM monitors
            WHERE archived_at IS NULL
        ");

        $checks = $this->fetchOne("
            SELECT
                COUNT(*) AS total_checks,
                SUM(CASE WHEN status = 'up' THEN 1 ELSE 0 END) AS up_checks,
                SUM(CASE WHEN status = 'down' THEN 1 ELSE 0 END) AS down_checks,
                SUM(CASE WHEN status = 'degraded' THEN 1 ELSE 0 END) AS degraded_checks,
                ROUND(AVG(response_time_ms), 0) AS avg_response_ms
            FROM checks
            WHERE checked_at >= :period_start
              AND checked_at <= :period_end
        ", ['period_start' => $periodStart, 'period_end' => $periodEnd]);

        $incident = $this->fetchOne("
            SELECT
                COUNT(*) AS incident_count,
                SUM(CASE WHEN resolved_at IS NULL THEN 1 ELSE 0 END) AS open_incidents,
                SUM(COALESCE(duration_seconds, CAST(strftime('%s', :period_end) - strftime('%s', started_at) AS INTEGER))) AS downtime_seconds
            FROM incidents
            WHERE started_at >= :period_start
              AND started_at <= :period_end
        ", ['period_start' => $periodStart, 'period_end' => $periodEnd]);

        $broken = $this->fetchOne("
            SELECT
                SUM(CASE WHEN resolved_at IS NULL AND ignored_at IS NULL THEN 1 ELSE 0 END) AS active_broken_links,
                SUM(CASE WHEN first_detected_at >= :period_start AND first_detected_at <= :period_end THEN 1 ELSE 0 END) AS new_broken_links,
                SUM(CASE WHEN resolved_at >= :period_start AND resolved_at <= :period_end THEN 1 ELSE 0 END) AS resolved_broken_links
            FROM broken_links
        ", ['period_start' => $periodStart, 'period_end' => $periodEnd]);

        $scan = $this->fetchOne("
            SELECT
                COUNT(*) AS link_scan_jobs,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_link_scans,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_link_scans,
                SUM(CASE WHEN status = 'running' THEN 1 ELSE 0 END) AS running_link_scans
            FROM link_scan_jobs
            WHERE started_at >= :period_start
              AND started_at <= :period_end
        ", ['period_start' => $periodStart, 'period_end' => $periodEnd]);

        $slowest = $this->fetchOne("
            SELECT m.name, ROUND(AVG(c.response_time_ms), 0) AS avg_response_ms
            FROM checks c
            INNER JOIN monitors m ON m.id = c.monitor_id
            WHERE c.checked_at >= :period_start
              AND c.checked_at <= :period_end
              AND c.response_time_ms IS NOT NULL
            GROUP BY m.id, m.name
            ORDER BY AVG(c.response_time_ms) DESC
            LIMIT 1
        ", ['period_start' => $periodStart, 'period_end' => $periodEnd]);

        $totalChecks = (int) ($checks['total_checks'] ?? 0);
        $upChecks = (int) ($checks['up_checks'] ?? 0);

        return [
            'total_monitors' => (int) ($status['total_monitors'] ?? 0),
            'active_monitors' => (int) ($status['active_monitors'] ?? 0),
            'up_monitors' => (int) ($status['up_monitors'] ?? 0),
            'down_monitors' => (int) ($status['down_monitors'] ?? 0),
            'degraded_monitors' => (int) ($status['degraded_monitors'] ?? 0),
            'total_checks' => $totalChecks,
            'up_checks' => $upChecks,
            'down_checks' => (int) ($checks['down_checks'] ?? 0),
            'degraded_checks' => (int) ($checks['degraded_checks'] ?? 0),
            'uptime_percent' => $totalChecks > 0 ? round(($upChecks / $totalChecks) * 100, 2) : 0.0,
            'avg_response_ms' => isset($checks['avg_response_ms']) && $checks['avg_response_ms'] !== null ? (int) $checks['avg_response_ms'] : null,
            'incident_count' => (int) ($incident['incident_count'] ?? 0),
            'open_incidents' => (int) ($incident['open_incidents'] ?? 0),
            'downtime_seconds' => (int) ($incident['downtime_seconds'] ?? 0),
            'active_broken_links' => (int) ($broken['active_broken_links'] ?? 0),
            'new_broken_links' => (int) ($broken['new_broken_links'] ?? 0),
            'resolved_broken_links' => (int) ($broken['resolved_broken_links'] ?? 0),
            'link_scan_jobs' => (int) ($scan['link_scan_jobs'] ?? 0),
            'completed_link_scans' => (int) ($scan['completed_link_scans'] ?? 0),
            'failed_link_scans' => (int) ($scan['failed_link_scans'] ?? 0),
            'running_link_scans' => (int) ($scan['running_link_scans'] ?? 0),
            'slowest_monitor' => (string) ($slowest['name'] ?? '-'),
            'slowest_avg_response_ms' => isset($slowest['avg_response_ms']) && $slowest['avg_response_ms'] !== null ? (int) $slowest['avg_response_ms'] : null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function monitorRows(string $periodStart): array
    {
        $stmt = $this->pdo->prepare("
            SELECT
                m.id,
                m.name,
                m.url,
                m.current_status,
                COUNT(c.id) AS total_checks,
                SUM(CASE WHEN c.status = 'up' THEN 1 ELSE 0 END) AS up_checks,
                ROUND(AVG(c.response_time_ms), 0) AS avg_response_ms,
                (
                    SELECT COUNT(*)
                    FROM incidents i
                    WHERE i.monitor_id = m.id
                      AND i.resolved_at IS NULL
                ) AS open_incidents,
                (
                    SELECT COUNT(*)
                    FROM broken_links b
                    WHERE b.monitor_id = m.id
                      AND b.resolved_at IS NULL
                      AND b.ignored_at IS NULL
                ) AS active_broken_links
            FROM monitors m
            LEFT JOIN checks c ON c.monitor_id = m.id AND c.checked_at >= :period_start
            WHERE m.is_active = 1
              AND m.archived_at IS NULL
            GROUP BY m.id
            ORDER BY
                CASE m.current_status
                    WHEN 'down' THEN 0
                    WHEN 'degraded' THEN 1
                    WHEN 'up' THEN 2
                    ELSE 3
                END,
                active_broken_links DESC,
                m.id ASC
            LIMIT 12
        ");
        $stmt->execute(['period_start' => $periodStart]);
        return $stmt->fetchAll();
    }

    /**
     * @param array<string, mixed> $report
     */
    private function renderText(array $report): string
    {
        $summary = $report['summary'];
        $typeLabel = $report['type'] === 'weekly' ? 'Haftalik Operasyon Raporu' : 'Gunluk Operasyon Raporu';
        $lines = [];
        $lines[] = $typeLabel;
        $lines[] = 'Period: ' . (string) $report['period_start'] . ' - ' . (string) $report['period_end'];
        $lines[] = '';
        $lines[] = 'Summary';
        $lines[] = '- Active monitors: ' . (int) $summary['active_monitors'] . ' / ' . (int) $summary['total_monitors'];
        $lines[] = '- Current status: UP ' . (int) $summary['up_monitors'] . ', DOWN ' . (int) $summary['down_monitors'] . ', DEGRADED ' . (int) $summary['degraded_monitors'];
        $lines[] = '- Uptime: ' . number_format((float) $summary['uptime_percent'], 2) . '% from ' . (int) $summary['total_checks'] . ' checks';
        $lines[] = '- Average response: ' . ($summary['avg_response_ms'] !== null ? (int) $summary['avg_response_ms'] . ' ms' : '-');
        $lines[] = '- Slowest average: ' . (string) $summary['slowest_monitor'] . ' (' . ($summary['slowest_avg_response_ms'] !== null ? (int) $summary['slowest_avg_response_ms'] . ' ms' : '-') . ')';
        $lines[] = '- Incidents: ' . (int) $summary['incident_count'] . ' new, ' . (int) $summary['open_incidents'] . ' open, downtime ' . $this->formatDuration((int) $summary['downtime_seconds']);
        $lines[] = '- Broken links: ' . (int) $summary['active_broken_links'] . ' active, ' . (int) $summary['new_broken_links'] . ' new, ' . (int) $summary['resolved_broken_links'] . ' resolved';
        $lines[] = '- Link scans: ' . (int) $summary['completed_link_scans'] . ' completed, ' . (int) $summary['failed_link_scans'] . ' failed, ' . (int) $summary['running_link_scans'] . ' running';
        $lines[] = '';
        $lines[] = 'Monitor Highlights';

        foreach ((array) $report['monitors'] as $row) {
            $total = (int) ($row['total_checks'] ?? 0);
            $up = (int) ($row['up_checks'] ?? 0);
            $uptime = $total > 0 ? round(($up / $total) * 100, 2) : 0.0;
            $lines[] = '- ' . (string) $row['name']
                . ': ' . strtoupper((string) $row['current_status'])
                . ', uptime ' . number_format($uptime, 2) . '%'
                . ', avg ' . (($row['avg_response_ms'] ?? null) !== null ? (int) $row['avg_response_ms'] . ' ms' : '-')
                . ', open incidents ' . (int) ($row['open_incidents'] ?? 0)
                . ', active broken ' . (int) ($row['active_broken_links'] ?? 0);
        }

        if ((array) $report['monitors'] === []) {
            $lines[] = '- No active monitors.';
        }

        $appUrl = (string) config('APP_URL', '');
        if ($appUrl !== '') {
            $lines[] = '';
            $lines[] = 'Dashboard: ' . rtrim($appUrl, '/') . '/index.php';
            $lines[] = 'Reports: ' . rtrim($appUrl, '/') . '/reports.php';
        }

        return implode("\n", $lines);
    }

    /**
     * @return array<string, string|null>
     */
    private function sendChannels(string $subject, string $body): array
    {
        $email = $this->sendEmail($subject, $body);
        $telegram = $this->sendTelegram($body);

        return [
            'email_status' => $email['status'],
            'telegram_status' => $telegram['status'],
            'email_error' => $email['error'],
            'telegram_error' => $telegram['error'],
        ];
    }

    /**
     * @return array{status: string, error: string|null}
     */
    private function sendEmail(string $subject, string $body): array
    {
        if ((string) config('NOTIFY_EMAIL_ENABLED', 'false') !== 'true') {
            return ['status' => 'disabled', 'error' => null];
        }

        $to = (string) config('NOTIFY_EMAIL_TO', '');
        if ($to === '') {
            return ['status' => 'failed', 'error' => 'NOTIFY_EMAIL_TO not set'];
        }

        $ok = @mail($to, $subject, $body, "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8");
        return ['status' => $ok ? 'sent' : 'failed', 'error' => $ok ? null : 'mail() returned false'];
    }

    /**
     * @return array{status: string, error: string|null}
     */
    private function sendTelegram(string $body): array
    {
        if ((string) config('NOTIFY_TELEGRAM_ENABLED', 'false') !== 'true') {
            return ['status' => 'disabled', 'error' => null];
        }

        $token = (string) config('TELEGRAM_BOT_TOKEN', '');
        $chatId = (string) config('TELEGRAM_DEFAULT_CHAT_ID', '');
        if ($token === '' || $chatId === '') {
            return ['status' => 'failed', 'error' => 'Telegram config missing'];
        }
        if (!function_exists('curl_init')) {
            return ['status' => 'failed', 'error' => 'PHP curl extension is not available'];
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://api.telegram.org/bot' . rawurlencode($token) . '/sendMessage',
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'chat_id' => $chatId,
                'text' => $body,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 12,
        ]);
        $response = curl_exec($ch);
        $error = $response === false ? curl_error($ch) : null;
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $ok = $error === null && $httpCode >= 200 && $httpCode < 300;
        return ['status' => $ok ? 'sent' : 'failed', 'error' => $ok ? null : ($error ?: 'Telegram HTTP ' . $httpCode)];
    }

    /**
     * @param array<string, mixed> $report
     * @param array<string, string|null> $delivery
     */
    private function insertRun(array $report, array $delivery): int
    {
        $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare("
            INSERT INTO report_runs (
                report_type, period_start, period_end, subject, body,
                email_status, telegram_status, email_error, telegram_error,
                created_at, sent_at
            ) VALUES (
                :report_type, :period_start, :period_end, :subject, :body,
                :email_status, :telegram_status, :email_error, :telegram_error,
                :created_at, :sent_at
            )
        ");
        $stmt->execute([
            'report_type' => (string) $report['type'],
            'period_start' => (string) $report['period_start'],
            'period_end' => (string) $report['period_end'],
            'subject' => (string) $report['subject'],
            'body' => (string) $report['body'],
            'email_status' => (string) $delivery['email_status'],
            'telegram_status' => (string) $delivery['telegram_status'],
            'email_error' => $delivery['email_error'],
            'telegram_error' => $delivery['telegram_error'],
            'created_at' => $now,
            'sent_at' => $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, string|null> $delivery
     */
    private function updateRunDelivery(int $id, array $delivery): void
    {
        $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare("
            UPDATE report_runs
            SET email_status = :email_status,
                telegram_status = :telegram_status,
                email_error = :email_error,
                telegram_error = :telegram_error,
                sent_at = :sent_at
            WHERE id = :id
        ");
        $stmt->execute([
            'email_status' => (string) $delivery['email_status'],
            'telegram_status' => (string) $delivery['telegram_status'],
            'email_error' => $delivery['email_error'],
            'telegram_error' => $delivery['telegram_error'],
            'sent_at' => $now,
            'id' => $id,
        ]);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function fetchOne(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return is_array($row) ? $row : [];
    }

    private function formatDuration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        if ($seconds < 60) {
            return $seconds . 's';
        }
        $minutes = intdiv($seconds, 60);
        if ($minutes < 60) {
            return $minutes . 'm ' . ($seconds % 60) . 's';
        }
        $hours = intdiv($minutes, 60);
        return $hours . 'h ' . ($minutes % 60) . 'm';
    }

    private function ensureReportRunsTable(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS report_runs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                report_type TEXT NOT NULL CHECK (report_type IN ('daily','weekly')),
                period_start TEXT NOT NULL,
                period_end TEXT NOT NULL,
                subject TEXT NOT NULL,
                body TEXT NOT NULL,
                email_status TEXT NOT NULL DEFAULT 'skipped',
                telegram_status TEXT NOT NULL DEFAULT 'skipped',
                email_error TEXT NULL,
                telegram_error TEXT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                sent_at TEXT NULL
            )
        ");
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_report_runs_type_time ON report_runs (report_type, created_at)');
    }

    private function ensureCompatibilityColumns(): void
    {
        if (!$this->columnExists('monitors', 'archived_at')) {
            $this->pdo->exec('ALTER TABLE monitors ADD COLUMN archived_at TEXT NULL');
        }
        if (!$this->columnExists('broken_links', 'ignored_at')) {
            $this->pdo->exec('ALTER TABLE broken_links ADD COLUMN ignored_at TEXT NULL');
        }
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
}
