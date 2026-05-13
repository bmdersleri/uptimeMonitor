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
if ($monitorId < 1) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Geçersiz monitör']);
    exit;
}

$maxDepth = isset($_POST['max_depth']) ? max(1, (int) $_POST['max_depth']) : null;

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

@ignore_user_abort(true);
@set_time_limit(0);

$pdo = Database::connection();
$runner = new LinkScanRunner($pdo);

if (function_exists('fastcgi_finish_request')) {
    try {
        $scanRepo = new LinkScanRepository($pdo);
        $scanRepo->closeStaleRunningJobs((int) config('DEFAULT_LINK_SCAN_STALE_AFTER_MINUTES', '60'));

        $stmt = $pdo->prepare("SELECT * FROM monitors WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $monitorId]);
        $monitor = $stmt->fetch();
        if (!is_array($monitor)) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'message' => 'Monitör bulunamadı.', 'job_id' => null]);
            exit;
        }
        if ((int) ($monitor['is_active'] ?? 0) !== 1) {
            http_response_code(409);
            echo json_encode(['ok' => false, 'message' => 'Monitör pasif durumda.', 'job_id' => null]);
            exit;
        }
        if ((int) ($monitor['link_scan_enabled'] ?? 1) !== 1) {
            http_response_code(409);
            echo json_encode(['ok' => false, 'message' => 'Bu monitörde link scan kapalı.', 'job_id' => null]);
            exit;
        }
        $runningJob = $scanRepo->findAnyRunningWithMonitor();
        if ($runningJob !== null) {
            $runningMonitorId = (int) ($runningJob['monitor_id'] ?? 0);
            http_response_code(409);
            echo json_encode([
                'ok' => false,
                'message' => $runningMonitorId === $monitorId
                    ? 'Bu monitör için zaten çalışan bir scan job var.'
                    : 'Başka bir link scan job çalışıyor. Aynı anda tek job çalıştırılabilir.',
                'job_id' => (int) ($runningJob['id'] ?? 0),
            ]);
            exit;
        }

        echo json_encode([
            'ok' => true,
            'message' => 'Scan başlatıldı. Canlı durum panelinden takip edebilirsiniz.',
            'job_id' => null,
        ]);
        fastcgi_finish_request();

        $runner->runMonitorById($monitorId, 'manual', $maxDepth);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => $e->getMessage(), 'job_id' => null]);
        exit;
    }
}

$result = $runner->runMonitorById($monitorId, 'manual', $maxDepth);

echo json_encode([
    'ok' => (bool) ($result['ok'] ?? false),
    'message' => (string) ($result['message'] ?? ''),
    'job_id' => isset($result['job_id']) ? (int) $result['job_id'] : null,
]);
