<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_auth();

header('Content-Type: application/json; charset=UTF-8');

$pdo = Database::connection();
$scanRepo = new LinkScanRepository($pdo);
$monitorId = isset($_GET['monitor_id']) ? (int) $_GET['monitor_id'] : 0;

$staleMinutes = max(10, (int) config('DEFAULT_LINK_SCAN_STALE_AFTER_MINUTES', '60'));
$now = new DateTimeImmutable('now');
$cleanupDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'link_scan_live';
if (!is_dir($cleanupDir)) {
    @mkdir($cleanupDir, 0775, true);
}
$cleanupMarker = $cleanupDir . DIRECTORY_SEPARATOR . '.stale_cleanup';
$cleanupDue = !is_file($cleanupMarker) || (time() - (int) @filemtime($cleanupMarker)) >= 60;
if ($cleanupDue) {
    @touch($cleanupMarker);
    $threshold = $now->sub(new DateInterval('PT' . $staleMinutes . 'M'))->format('Y-m-d H:i:s');
    $finishedAt = $now->format('Y-m-d H:i:s');
    try {
        $staleStmt = $pdo->prepare("
            UPDATE link_scan_jobs
            SET
                finished_at = :finished_at,
                status = 'failed',
                duration_seconds = MAX(0, CAST(strftime('%s', :finished_at) AS INTEGER) - CAST(strftime('%s', started_at) AS INTEGER)),
                error_message = 'Marked stale automatically'
            WHERE status = 'running'
              AND started_at <= :threshold
        ");
        $staleStmt->execute([
            'finished_at' => $finishedAt,
            'threshold' => $threshold,
        ]);
    } catch (Throwable $e) {
        // Status polling should not block live progress if SQLite is briefly busy.
    }
}

$counts = $scanRepo->quickCounts();
$running = $scanRepo->findRunningWithMonitor($monitorId);
$latest = $monitorId > 0 ? $scanRepo->latestWithMonitorByMonitorId($monitorId) : null;

if ($running === null && $latest !== null && (string) ($latest['status'] ?? '') === 'running') {
    $running = $latest;
}

$live = null;
if (is_array($running)) {
    $jobId = (int) ($running['id'] ?? 0);
    if ($jobId > 0) {
        $livePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'link_scan_live' . DIRECTORY_SEPARATOR . 'job_' . $jobId . '.json';
        if (is_file($livePath)) {
            $json = file_get_contents($livePath);
            if (is_string($json) && $json !== '') {
                $decoded = json_decode($json, true);
                if (is_array($decoded)) {
                    $live = LinkScanRunner::enrichLiveStateForStatus($decoded, $running, $now);
                }
            }
        }
    }
}

echo json_encode([
    'ok' => true,
    'time' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
    'counts' => $counts,
    'running' => $running,
    'latest' => $latest,
    'live' => $live,
]);
