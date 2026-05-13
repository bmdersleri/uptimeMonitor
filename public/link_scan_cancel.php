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

$monitorId = (int) ($_POST['monitor_id'] ?? 0);
$jobId = (int) ($_POST['job_id'] ?? 0);

$scanRepo = new LinkScanRepository(Database::connection());
$running = null;

if ($jobId > 0) {
    $candidate = $scanRepo->findWithMonitor($jobId);
    if (is_array($candidate) && (string) ($candidate['status'] ?? '') === 'running') {
        $running = $candidate;
    }
} else {
    $running = $scanRepo->findRunningWithMonitor($monitorId);
}

if (!is_array($running)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'message' => 'Çalışan scan job bulunamadı.']);
    exit;
}

$runningJobId = (int) ($running['id'] ?? 0);
if ($runningJobId < 1 || !LinkScanRunner::requestCancelForJob($runningJobId)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Scan durdurma isteği kaydedilemedi.']);
    exit;
}

$scanRepo->cancelRunningJob($runningJobId, 'Canceled by user');

echo json_encode([
    'ok' => true,
    'message' => 'Scan durduruldu.',
    'job_id' => $runningJobId,
]);
