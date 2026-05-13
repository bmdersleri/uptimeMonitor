<?php

declare(strict_types=1);

final class LinkScanRunner
{
    private const LIVE_RECENT_LIMIT = 200;

    /** @var PDO */
    private $pdo;

    /** @var LinkScanner */
    private $scanner;

    /** @var Notifier */
    private $notifier;

    public function __construct(PDO $pdo, ?LinkScanner $scanner = null, ?Notifier $notifier = null)
    {
        $this->pdo = $pdo;
        $this->scanner = $scanner instanceof LinkScanner ? $scanner : new LinkScanner((int) config('DEFAULT_TIMEOUT_SECONDS', 10));
        $this->notifier = $notifier instanceof Notifier ? $notifier : new Notifier($pdo);
    }

    public static function requestCancelForJob(int $jobId): bool
    {
        if ($jobId < 1) {
            return false;
        }

        $dir = self::liveStateDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $path = self::cancelPathForJob($jobId);
        return @file_put_contents($path, (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'), LOCK_EX) !== false;
    }

    /**
     * @return array<int, string>
     */
    public function runDueMonitors(int $batchSize = 5): array
    {
        $logs = [];
        $now = new DateTimeImmutable('now');
        $nowText = $now->format('Y-m-d H:i:s');

        try {
            $dueSql = "
                SELECT *
                FROM monitors
                WHERE is_active = 1
                  AND archived_at IS NULL
                  AND COALESCE(link_scan_enabled, 1) = 1
                  AND (next_link_scan_at IS NULL OR next_link_scan_at <= :now)
                ORDER BY id ASC
                LIMIT :limit
            ";
            $dueStmt = $this->pdo->prepare($dueSql);
            $dueStmt->bindValue(':now', $nowText);
            $dueStmt->bindValue(':limit', max(1, $batchSize), PDO::PARAM_INT);
            $dueStmt->execute();
            $monitors = $dueStmt->fetchAll();
        } catch (Exception $e) {
            $fallback = $this->pdo->query("SELECT * FROM monitors WHERE is_active = 1 ORDER BY id ASC LIMIT " . max(1, $batchSize));
            $monitors = $fallback ? $fallback->fetchAll() : [];
        }

        if (!is_array($monitors) || $monitors === []) {
            return ['[scan] no monitors due'];
        }

        foreach ($monitors as $monitor) {
            if (!is_array($monitor)) {
                continue;
            }

            $monitorId = (int) ($monitor['id'] ?? 0);
            if ($monitorId < 1) {
                continue;
            }

            if ($this->hasRunningJob($monitorId)) {
                $logs[] = "[scan] monitor #{$monitorId} skipped (running job exists)";
                continue;
            }

            $result = $this->runMonitor($monitor, 'cron');
            $logs[] = $result['log_line'];
        }

        return $logs;
    }

    /**
     * @return array<string, mixed>
     */
    public function runMonitorById(int $monitorId, string $trigger = 'manual', ?int $maxDepthOverride = null): array
    {
        if ($monitorId < 1) {
            return [
                'ok' => false,
                'message' => 'Geçersiz monitör ID.',
                'job_id' => null,
                'log_line' => '[scan] invalid monitor id',
            ];
        }

        $stmt = $this->pdo->prepare("SELECT * FROM monitors WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $monitorId]);
        $monitor = $stmt->fetch();
        if (!is_array($monitor)) {
            return [
                'ok' => false,
                'message' => 'Monitör bulunamadı.',
                'job_id' => null,
                'log_line' => "[scan] monitor #{$monitorId} not found",
            ];
        }

        if ((int) ($monitor['is_active'] ?? 0) !== 1) {
            return [
                'ok' => false,
                'message' => 'Monitör pasif durumda.',
                'job_id' => null,
                'log_line' => "[scan] monitor #{$monitorId} passive",
            ];
        }

        if ((int) ($monitor['link_scan_enabled'] ?? 1) !== 1) {
            return [
                'ok' => false,
                'message' => 'Bu monitörde link scan kapalı.',
                'job_id' => null,
                'log_line' => "[scan] monitor #{$monitorId} link scan disabled",
            ];
        }

        if ($this->hasRunningJob($monitorId)) {
            return [
                'ok' => false,
                'message' => 'Bu monitör için zaten çalışan bir scan job var.',
                'job_id' => null,
                'log_line' => "[scan] monitor #{$monitorId} skipped (running job exists)",
            ];
        }

        return $this->runMonitor($monitor, $trigger, $maxDepthOverride);
    }

    private function hasRunningJob(int $monitorId): bool
    {
        $this->closeStaleRunningJobs();

        $stmt = $this->pdo->prepare("
            SELECT id
            FROM link_scan_jobs
            WHERE monitor_id = :monitor_id
              AND status = 'running'
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute(['monitor_id' => $monitorId]);
        $row = $stmt->fetch();
        return is_array($row);
    }

    private function closeStaleRunningJobs(): void
    {
        $staleMinutes = max(10, (int) config('DEFAULT_LINK_SCAN_STALE_AFTER_MINUTES', '60'));
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
    }

    /**
     * @param array<string, mixed> $monitor
     * @return array<string, mixed>
     */
    private function runMonitor(array $monitor, string $trigger, ?int $maxDepthOverride = null): array
    {
        @set_time_limit(0);

        $monitorId = (int) ($monitor['id'] ?? 0);
        $startedAt = new DateTimeImmutable('now');
        $startedAtText = $startedAt->format('Y-m-d H:i:s');

        $jobInsert = $this->pdo->prepare("
            INSERT INTO link_scan_jobs (monitor_id, started_at, status, created_at)
            VALUES (:monitor_id, :started_at, 'running', :created_at)
        ");
        $jobInsert->execute([
            'monitor_id' => $monitorId,
            'started_at' => $startedAtText,
            'created_at' => $startedAtText,
        ]);
        $jobId = (int) $this->pdo->lastInsertId();
        $this->clearCancelRequest($jobId);

        $liveState = [
            'job_id' => $jobId,
            'monitor_id' => $monitorId,
            'status' => 'running',
            'trigger' => $trigger,
            'started_at' => $startedAtText,
            'updated_at' => $startedAtText,
            'checked_urls' => 0,
            'broken_urls' => 0,
            'total_urls' => 0,
            'pages_crawled' => 0,
            'estimated_checks' => 0,
            'recent_limit' => self::LIVE_RECENT_LIMIT,
            'current_source_url' => '',
            'current_target_url' => '',
            'current_status' => '',
            'recent' => [],
        ];
        $this->writeLiveState($jobId, $liveState);

        $runningUpdate = $this->pdo->prepare("
            UPDATE link_scan_jobs
            SET checked_urls = :checked_urls, broken_urls = :broken_urls
            WHERE id = :id
        ");

        $lastRunningWriteAt = microtime(true);

        try {
            $maxDepth = $maxDepthOverride !== null
                ? max(1, $maxDepthOverride)
                : (isset($monitor['link_scan_max_depth']) ? (int) $monitor['link_scan_max_depth'] : 3);
            $maxUrls = isset($monitor['link_scan_max_urls']) ? (int) $monitor['link_scan_max_urls'] : 120;

            $scan = $this->scanner->scan((string) $monitor['url'], $maxDepth, $maxUrls, function ($event) use (&$liveState, &$lastRunningWriteAt, $jobId, $runningUpdate): void {
                $this->throwIfCanceled($jobId);

                if (!is_array($event)) {
                    return;
                }

                $type = (string) ($event['type'] ?? '');
                if ($type === 'page_start') {
                    $liveState['pages_crawled'] = (int) ($event['page_count'] ?? 0);
                    $liveState['total_urls'] = (int) ($event['page_count'] ?? 0);
                    $liveState['current_source_url'] = (string) ($event['current_url'] ?? '');
                } elseif ($type === 'page_resources_found') {
                    $resourceCount = (int) ($event['resource_count'] ?? 0);
                    $checkedBeforePage = (int) ($event['checked_count'] ?? 0);
                    $liveState['pages_crawled'] = max((int) ($liveState['pages_crawled'] ?? 0), (int) ($event['page_count'] ?? 0));
                    $liveState['estimated_checks'] = max((int) ($liveState['estimated_checks'] ?? 0), $checkedBeforePage + $resourceCount);
                    $liveState['current_source_url'] = (string) ($event['current_url'] ?? '');
                    $liveState['current_target_url'] = $resourceCount > 0 ? 'Kaynaklar bulundu: ' . $resourceCount : '';
                    $liveState['current_status'] = 'discovering';
                } elseif ($type === 'resource_checked') {
                    $liveState['checked_urls'] = (int) ($event['checked_count'] ?? 0);
                    $liveState['broken_urls'] = (int) ($event['broken_count'] ?? 0);
                    $liveState['pages_crawled'] = max((int) ($liveState['pages_crawled'] ?? 0), (int) ($event['page_count'] ?? 0));
                    $liveState['estimated_checks'] = max((int) ($liveState['estimated_checks'] ?? 0), (int) ($event['checked_count'] ?? 0));
                    $liveState['current_source_url'] = (string) ($event['source_url'] ?? '');
                    $liveState['current_target_url'] = (string) ($event['target_url'] ?? '');
                    $liveState['current_status'] = ((bool) ($event['ok'] ?? false)) ? 'ok' : 'broken';

                    $recent = is_array($liveState['recent']) ? $liveState['recent'] : [];
                    $recent[] = [
                        'target_url' => (string) ($event['target_url'] ?? ''),
                        'source_url' => (string) ($event['source_url'] ?? ''),
                        'status' => $liveState['current_status'],
                        'status_code' => (int) ($event['status_code'] ?? 0),
                        'error_message' => (string) ($event['error_message'] ?? ''),
                        'checked_urls' => $liveState['checked_urls'],
                    ];
                    if (count($recent) > self::LIVE_RECENT_LIMIT) {
                        $recent = array_slice($recent, -self::LIVE_RECENT_LIMIT);
                    }
                    $liveState['recent'] = $recent;
                }

                $liveState['updated_at'] = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
                $this->writeLiveState($jobId, $liveState);
                $this->throwIfCanceled($jobId);

                $now = microtime(true);
                if (($now - $lastRunningWriteAt) >= 1.0) {
                    $runningUpdate->execute([
                        'checked_urls' => (int) $liveState['checked_urls'],
                        'broken_urls' => (int) $liveState['broken_urls'],
                        'id' => $jobId,
                    ]);
                    $lastRunningWriteAt = $now;
                }
            });

            $this->throwIfCanceled($jobId);

            $this->persistScanResult($monitor, $jobId, $scan, $startedAt, $startedAtText);

            $liveState['status'] = 'completed';
            $liveState['checked_urls'] = (int) $scan['checked_urls'];
            $liveState['broken_urls'] = (int) $scan['broken_urls'];
            $liveState['total_urls'] = (int) $scan['total_urls'];
            $liveState['pages_crawled'] = (int) $scan['total_urls'];
            $liveState['estimated_checks'] = (int) $scan['checked_urls'];
            $liveState['updated_at'] = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
            $this->writeLiveState($jobId, $liveState);

            return [
                'ok' => true,
                'message' => 'Scan tamamlandı.',
                'job_id' => $jobId,
                'log_line' => "[scan] monitor #{$monitorId} done, broken: " . (int) $scan['broken_urls'],
            ];
        } catch (Exception $e) {
            $isCanceled = $e->getMessage() === 'Canceled by user';
            $jobFail = $this->pdo->prepare("
                UPDATE link_scan_jobs
                SET finished_at = :finished_at, status = 'failed', error_message = :error_message
                WHERE id = :id
            ");
            $jobFail->execute([
                'finished_at' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
                'error_message' => $e->getMessage(),
                'id' => $jobId,
            ]);

            $liveState['status'] = 'failed';
            $liveState['current_status'] = $isCanceled ? 'canceled' : 'failed';
            $liveState['updated_at'] = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
            $recent = is_array($liveState['recent']) ? $liveState['recent'] : [];
            $recent[] = [
                'target_url' => (string) ($liveState['current_target_url'] ?? ''),
                'source_url' => (string) ($liveState['current_source_url'] ?? ''),
                'status' => 'failed',
                'status_code' => 0,
                'error_message' => $e->getMessage(),
                'checked_urls' => (int) ($liveState['checked_urls'] ?? 0),
            ];
            $liveState['recent'] = array_slice($recent, -self::LIVE_RECENT_LIMIT);
            $this->writeLiveState($jobId, $liveState);
            $this->clearCancelRequest($jobId);

            return [
                'ok' => false,
                'message' => $e->getMessage(),
                'job_id' => $jobId,
                'log_line' => "[scan] monitor #{$monitorId} failed: " . $e->getMessage(),
            ];
        }
    }

    /**
     * @param array<string, mixed> $monitor
     * @param array<string, mixed> $scan
     */
    private function persistScanResult(array $monitor, int $jobId, array $scan, DateTimeImmutable $startedAt, string $startedAtText): void
    {
        $monitorId = (int) $monitor['id'];
        $ignoreRepo = new BrokenLinkIgnoreRuleRepository($this->pdo);

        $discoveredInsert = $this->pdo->prepare("
            INSERT INTO discovered_links (
                monitor_id, source_url, target_url, link_type, last_checked_at, last_status_code, last_status, last_error, first_seen_at, last_seen_at
            ) VALUES (
                :monitor_id, :source_url, :target_url, :link_type, :last_checked_at, :last_status_code, :last_status, :last_error, :first_seen_at, :last_seen_at
            )
            ON CONFLICT(monitor_id, source_url, target_url) DO UPDATE SET
                link_type = excluded.link_type,
                last_checked_at = excluded.last_checked_at,
                last_status_code = excluded.last_status_code,
                last_status = excluded.last_status,
                last_error = excluded.last_error,
                last_seen_at = excluded.last_seen_at
        ");

        foreach ((array) $scan['discovered'] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $discoveredInsert->execute([
                'monitor_id' => $monitorId,
                'source_url' => (string) ($item['source_url'] ?? ''),
                'target_url' => (string) ($item['target_url'] ?? ''),
                'link_type' => (string) ($item['link_type'] ?? 'other'),
                'last_checked_at' => $startedAtText,
                'last_status_code' => $item['last_status_code'] ?? null,
                'last_status' => (string) ($item['last_status'] ?? 'unknown'),
                'last_error' => $item['last_error'] ?? null,
                'first_seen_at' => $startedAtText,
                'last_seen_at' => $startedAtText,
            ]);
        }

        $brokenTargets = [];
        $findOpenBroken = $this->pdo->prepare("
            SELECT id, occurrence_count
            FROM broken_links
            WHERE monitor_id = :monitor_id
              AND target_url = :target_url
              AND resolved_at IS NULL
            ORDER BY id DESC
            LIMIT 1
        ");
        $updateBroken = $this->pdo->prepare("
            UPDATE broken_links
            SET
                source_url = :source_url,
                status_code = :status_code,
                error_type = :error_type,
                error_message = :error_message,
                last_detected_at = :last_detected_at,
                occurrence_count = :occurrence_count
            WHERE id = :id
        ");
        $insertBroken = $this->pdo->prepare("
            INSERT INTO broken_links (
                monitor_id, source_url, target_url, status_code, error_type, error_message, first_detected_at, last_detected_at, occurrence_count
            ) VALUES (
                :monitor_id, :source_url, :target_url, :status_code, :error_type, :error_message, :first_detected_at, :last_detected_at, :occurrence_count
            )
        ");

        $candidateBrokenIds = [];
        foreach ((array) $scan['broken'] as $b) {
            if (!is_array($b)) {
                continue;
            }

            $targetUrl = (string) ($b['target_url'] ?? '');
            if ($targetUrl === '') {
                continue;
            }
            $sourceUrl = (string) ($b['source_url'] ?? '');
            if ($ignoreRepo->shouldIgnore($targetUrl, $sourceUrl)) {
                continue;
            }

            $brokenTargets[$targetUrl] = true;
            $findOpenBroken->execute([
                'monitor_id' => $monitorId,
                'target_url' => $targetUrl,
            ]);
            $open = $findOpenBroken->fetch();

            if (is_array($open)) {
                $openId = (int) $open['id'];
                $candidateBrokenIds[] = $openId;
                $updateBroken->execute([
                    'source_url' => $sourceUrl,
                    'status_code' => $b['status_code'] ?? null,
                    'error_type' => $b['error_type'] ?? null,
                    'error_message' => $b['error_message'] ?? null,
                    'last_detected_at' => $startedAtText,
                    'occurrence_count' => ((int) ($open['occurrence_count'] ?? 0)) + 1,
                    'id' => $openId,
                ]);
            } else {
                $insertBroken->execute([
                    'monitor_id' => $monitorId,
                    'source_url' => $sourceUrl,
                    'target_url' => $targetUrl,
                    'status_code' => $b['status_code'] ?? null,
                    'error_type' => $b['error_type'] ?? null,
                    'error_message' => $b['error_message'] ?? null,
                    'first_detected_at' => $startedAtText,
                    'last_detected_at' => $startedAtText,
                    'occurrence_count' => 1,
                ]);
                $candidateBrokenIds[] = (int) $this->pdo->lastInsertId();
            }
        }

        if ($brokenTargets === []) {
            $resolveAll = $this->pdo->prepare("
                UPDATE broken_links
                SET resolved_at = :resolved_at
                WHERE monitor_id = :monitor_id
                  AND resolved_at IS NULL
            ");
            $resolveAll->execute([
                'resolved_at' => $startedAtText,
                'monitor_id' => $monitorId,
            ]);
        } else {
            $keys = array_keys($brokenTargets);
            $placeholders = [];
            $params = [
                'resolved_at' => $startedAtText,
                'monitor_id' => $monitorId,
            ];

            foreach ($keys as $i => $target) {
                $ph = ':t' . $i;
                $placeholders[] = $ph;
                $params['t' . $i] = $target;
            }

            $resolveOthersSql = "
                UPDATE broken_links
                SET resolved_at = :resolved_at
                WHERE monitor_id = :monitor_id
                  AND resolved_at IS NULL
                  AND target_url NOT IN (" . implode(',', $placeholders) . ")
            ";
            $resolveOthers = $this->pdo->prepare($resolveOthersSql);
            $resolveOthers->execute($params);
        }

        $minOccurrence = (int) config('notifications.broken_links.min_occurrence_before_notify', 2);
        $sendCandidates = [];
        if ($candidateBrokenIds !== []) {
            $idPlaceholders = [];
            $idParams = [
                'monitor_id' => $monitorId,
                'min_occurrence' => max(1, $minOccurrence),
            ];

            foreach (array_values(array_unique($candidateBrokenIds)) as $idx => $brokenId) {
                $ph = ':id' . $idx;
                $idPlaceholders[] = $ph;
                $idParams['id' . $idx] = (int) $brokenId;
            }

            $notifySql = "
                SELECT id, source_url, target_url, status_code, occurrence_count, notification_sent
                FROM broken_links
                WHERE monitor_id = :monitor_id
                  AND resolved_at IS NULL
                  AND notification_sent = 0
                  AND occurrence_count >= :min_occurrence
                  AND id IN (" . implode(',', $idPlaceholders) . ")
                ORDER BY id ASC
            ";
            $notifyStmt = $this->pdo->prepare($notifySql);
            $notifyStmt->execute($idParams);
            $sendCandidates = $notifyStmt->fetchAll();
        }

        if (is_array($sendCandidates) && $sendCandidates !== []) {
            $sent = $this->notifier->sendBrokenLinkSummary($monitor, $sendCandidates, $jobId);
            if ($sent) {
                $markSql = "
                    UPDATE broken_links
                    SET notification_sent = 1
                    WHERE id = :id
                ";
                $markStmt = $this->pdo->prepare($markSql);
                foreach ($sendCandidates as $cand) {
                    if (!is_array($cand)) {
                        continue;
                    }
                    $markStmt->execute(['id' => (int) ($cand['id'] ?? 0)]);
                }
            }
        }

        $finishedAt = new DateTimeImmutable('now');
        $duration = $finishedAt->getTimestamp() - $startedAt->getTimestamp();

        $jobDone = $this->pdo->prepare("
            UPDATE link_scan_jobs
            SET
                finished_at = :finished_at,
                status = 'completed',
                total_urls = :total_urls,
                checked_urls = :checked_urls,
                broken_urls = :broken_urls,
                duration_seconds = :duration_seconds,
                error_message = NULL
            WHERE id = :id
        ");
        $jobDone->execute([
            'finished_at' => $finishedAt->format('Y-m-d H:i:s'),
            'total_urls' => (int) ($scan['total_urls'] ?? 0),
            'checked_urls' => (int) ($scan['checked_urls'] ?? 0),
            'broken_urls' => (int) ($scan['broken_urls'] ?? 0),
            'duration_seconds' => max(0, $duration),
            'id' => $jobId,
        ]);

        $interval = isset($monitor['link_scan_interval_seconds']) ? (int) $monitor['link_scan_interval_seconds'] : 21600;
        $nextScan = $finishedAt->add(new DateInterval('PT' . max(300, $interval) . 'S'))->format('Y-m-d H:i:s');
        $monUpdate = $this->pdo->prepare("
            UPDATE monitors
            SET last_link_scan_at = :last_link_scan_at, next_link_scan_at = :next_link_scan_at, updated_at = :updated_at
            WHERE id = :id
        ");
        $ts = $finishedAt->format('Y-m-d H:i:s');
        $monUpdate->execute([
            'last_link_scan_at' => $ts,
            'next_link_scan_at' => $nextScan,
            'updated_at' => $ts,
            'id' => $monitorId,
        ]);
    }

    private function writeLiveState(int $jobId, array $state): void
    {
        $dir = self::liveStateDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $path = $dir . DIRECTORY_SEPARATOR . 'job_' . $jobId . '.json';
        $json = json_encode($state);
        if (!is_string($json)) {
            return;
        }
        @file_put_contents($path, $json, LOCK_EX);
    }

    private function throwIfCanceled(int $jobId): void
    {
        if (is_file(self::cancelPathForJob($jobId))) {
            throw new RuntimeException('Canceled by user');
        }
    }

    private function clearCancelRequest(int $jobId): void
    {
        $path = self::cancelPathForJob($jobId);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private static function liveStateDir(): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'link_scan_live';
    }

    private static function cancelPathForJob(int $jobId): string
    {
        return self::liveStateDir() . DIRECTORY_SEPARATOR . 'job_' . $jobId . '.cancel';
    }
}
