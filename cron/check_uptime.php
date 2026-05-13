<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

$pdo = Database::connection();
$monitorRepo = new MonitorRepository($pdo);
$incidentRepo = new IncidentRepository($pdo);
$notifier = new Notifier($pdo);
$checker = new UptimeChecker();
$now = new DateTimeImmutable('now');

$monitors = $monitorRepo->dueForCheck($now);
if ($monitors === []) {
    echo "[uptime] no monitors due\n";
    exit(0);
}

foreach ($monitors as $monitor) {
    $monitorId = (int) $monitor['id'];
    $expectedCodes = array_map(
        static function ($v) {
            return (int) trim((string) $v);
        },
        explode(',', (string) ($monitor['expected_status'] ?? '200,301,302'))
    );

    $result = $checker->check(
        (string) $monitor['url'],
        (int) $monitor['timeout_seconds'],
        (int) $monitor['response_warning_ms'],
        $expectedCodes
    );

    $insertCheck = $pdo->prepare("
        INSERT INTO checks (
            monitor_id,
            checked_at,
            status,
            http_code,
            response_time_ms,
            error_message,
            final_url
        ) VALUES (
            :monitor_id,
            :checked_at,
            :status,
            :http_code,
            :response_time_ms,
            :error_message,
            :final_url
        )
    ");
    $insertCheck->execute([
        'monitor_id' => $monitorId,
        'checked_at' => $now->format('Y-m-d H:i:s'),
        'status' => $result['status'],
        'http_code' => $result['http_code'],
        'response_time_ms' => $result['response_time_ms'],
        'error_message' => $result['error_message'],
        'final_url' => $result['final_url'],
    ]);

    $isFailure = $result['status'] === 'down';
    $newFailures = $isFailure ? ((int) $monitor['consecutive_failures'] + 1) : 0;
    $newSuccesses = $isFailure ? 0 : ((int) $monitor['consecutive_successes'] + 1);

    $nextCheck = $now->add(new DateInterval('PT' . (int) $monitor['interval_seconds'] . 'S'))->format('Y-m-d H:i:s');

    $monitorRepo->updateById($monitorId, [
        'current_status' => $result['status'],
        'consecutive_failures' => $newFailures,
        'consecutive_successes' => $newSuccesses,
        'last_check_at' => $now->format('Y-m-d H:i:s'),
        'next_check_at' => $nextCheck,
    ]);

    $openIncident = $incidentRepo->openIncidentForMonitor($monitorId);
    $failThreshold = (int) $monitor['fail_threshold'];
    $recoveryThreshold = (int) $monitor['recovery_threshold'];

    if ($isFailure && $newFailures >= $failThreshold && $openIncident === null) {
        $incidentRepo->open(
            $monitorId,
            'Monitor threshold reached',
            $result['error_message'] ?? ('HTTP ' . $result['http_code'])
        );

        $openedIncident = $incidentRepo->openIncidentForMonitor($monitorId);
        if ($openedIncident !== null && (int) ($openedIncident['notification_sent'] ?? 0) === 0) {
            $sent = $notifier->sendIncidentOpened($monitor, $result, (int) $openedIncident['id']);
            if ($sent) {
                $incidentRepo->markOpenNotificationSent((int) $openedIncident['id']);
            }
        }

        echo "[uptime] incident opened for monitor #{$monitorId}\n";
    }

    if (!$isFailure && $openIncident !== null && $newSuccesses >= $recoveryThreshold) {
        $recoveredIncidentId = (int) $openIncident['id'];
        $incidentRepo->closeOpenIncident($monitorId);

        if ((int) ($openIncident['recovery_notification_sent'] ?? 0) === 0) {
            $sent = $notifier->sendIncidentRecovered($monitor, $result, $recoveredIncidentId);
            if ($sent) {
                $incidentRepo->markRecoveryNotificationSent($recoveredIncidentId);
            }
        }

        echo "[uptime] incident closed for monitor #{$monitorId}\n";
    }

    echo sprintf(
        "[uptime] monitor #%d %s -> %s (%dms, http:%d)\n",
        $monitorId,
        (string) $monitor['url'],
        $result['status'],
        $result['response_time_ms'],
        $result['http_code']
    );
}
