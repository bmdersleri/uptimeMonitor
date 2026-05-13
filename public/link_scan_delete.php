<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_auth();

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
    exit;
}

$jobId = (int) ($_POST['job_id'] ?? 0);
if ($jobId < 1) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Geçersiz job ID.']);
    exit;
}

$pdo = Database::connection();
$scanRepo = new LinkScanRepository($pdo);
$job = $scanRepo->findWithMonitor($jobId);
if (!is_array($job)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'message' => 'Job bulunamadı.']);
    exit;
}

$isRunning = (string) ($job['status'] ?? '') === 'running';
if ($isRunning) {
    LinkScanRunner::requestCancelForJob($jobId);
    $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
    $cancelStmt = $pdo->prepare("
        UPDATE link_scan_jobs
        SET
            finished_at = :finished_at,
            status = 'failed',
            duration_seconds = MAX(0, CAST(strftime('%s', :finished_at) AS INTEGER) - CAST(strftime('%s', started_at) AS INTEGER)),
            error_message = :error_message
        WHERE id = :id
          AND status = 'running'
    ");
    $cancelStmt->execute([
        'finished_at' => $now,
        'error_message' => 'Deleted by user',
        'id' => $jobId,
    ]);
}

$deleteStmt = $pdo->prepare("DELETE FROM link_scan_jobs WHERE id = :id");
$deleteStmt->execute(['id' => $jobId]);
if ($deleteStmt->rowCount() < 1) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Job silinemedi.']);
    exit;
}

$livePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'link_scan_live' . DIRECTORY_SEPARATOR . 'job_' . $jobId . '.json';
if (is_file($livePath)) {
    @unlink($livePath);
}

$cancelPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'link_scan_live' . DIRECTORY_SEPARATOR . 'job_' . $jobId . '.cancel';
if (!$isRunning && is_file($cancelPath)) {
    @unlink($cancelPath);
}

echo json_encode([
    'ok' => true,
    'message' => 'Job silindi.',
    'job_id' => $jobId,
]);
