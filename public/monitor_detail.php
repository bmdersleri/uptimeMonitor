<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_auth();

function monitor_detail_ensure_ignore_columns(PDO $pdo): void
{
    try {
        $columns = $pdo->query("PRAGMA table_info(broken_links)")->fetchAll();
        $names = [];
        foreach ($columns as $column) {
            if (is_array($column) && isset($column['name'])) {
                $names[(string) $column['name']] = true;
            }
        }
        if (!isset($names['ignored_at'])) {
            $pdo->exec("ALTER TABLE broken_links ADD COLUMN ignored_at TEXT NULL");
        }
        if (!isset($names['ignored_reason'])) {
            $pdo->exec("ALTER TABLE broken_links ADD COLUMN ignored_reason TEXT NULL");
        }
    } catch (Throwable $e) {
    }
}

function monitor_status_class(string $status): string
{
    switch ($status) {
        case 'up':
            return 'up';
        case 'down':
            return 'down';
        case 'degraded':
            return 'degraded';
        default:
            return 'unknown';
    }
}

function monitor_status_label(string $status): string
{
    switch ($status) {
        case 'up':
            return 'UP';
        case 'down':
            return 'DOWN';
        case 'degraded':
            return 'DEGRADED';
        default:
            return 'UNKNOWN';
    }
}

$monitorId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($monitorId < 1) {
    redirect_to('/index.php');
}

$pdo = Database::connection();
$monitorRepo = new MonitorRepository($pdo);
$incidentRepo = new IncidentRepository($pdo);
$linkScanRepo = new LinkScanRepository($pdo);
$brokenRepo = new BrokenLinkRepository($pdo);
$monitor = $monitorRepo->findById($monitorId);

if ($monitor === null) {
    redirect_to('/index.php');
}

$health = $monitorRepo->monitorHealthSummary($monitorId);
$recentChecks = $monitorRepo->recentChecks($monitorId, 40);
$incidents = $incidentRepo->recentForMonitor($monitorId, 20);

$latestScan = null;
$latestQuality = ['top_targets' => [], 'top_sources' => [], 'status_codes' => []];
$brokenSummary = ['active_count' => 0, 'resolved_count' => 0];
$activeBrokenList = [];
try {
    monitor_detail_ensure_ignore_columns($pdo);
    $latestScan = $linkScanRepo->latestForMonitor($monitorId);
    if (is_array($latestScan) && (int) ($latestScan['id'] ?? 0) > 0) {
        $latestQuality = $linkScanRepo->qualityReportForJob((int) $latestScan['id']);
    }
    $brokenSummary = $brokenRepo->summaryForMonitor($monitorId);
    $activeBrokenList = $brokenRepo->activeForMonitor($monitorId, 8);
} catch (Exception $e) {
    $latestScan = null;
}

$hourRows = $monitorRepo->uptimeSeriesByHour($monitorId, 24);
$hourMap = [];
foreach ($hourRows as $row) {
    $hourMap[(string) $row['bucket']] = (float) $row['uptime_percent'];
}

$hourPoints = [];
$hourLabels = [];
$hourValues = [];
$hourStart = (new DateTimeImmutable('now'))->setTime((int) date('H'), 0, 0)->sub(new DateInterval('PT23H'));
for ($i = 0; $i < 24; $i++) {
    $t = $hourStart->add(new DateInterval('PT' . $i . 'H'));
    $bucket = $t->format('Y-m-d H:00:00');
    $hourLabels[] = $t->format('H:i');
    $value = array_key_exists($bucket, $hourMap) ? (float) $hourMap[$bucket] : 0.0;
    $hourValues[] = $value;
}

$hourCount = count($hourValues);
for ($i = 0; $i < $hourCount; $i++) {
    $x = $hourCount <= 1 ? 0 : ($i * 100.0 / ($hourCount - 1));
    $y = 100.0 - max(0.0, min(100.0, $hourValues[$i]));
    $hourPoints[] = number_format($x, 2, '.', '') . ',' . number_format($y, 2, '.', '');
}
$hourPolylinePoints = implode(' ', $hourPoints);

$dayRows = $monitorRepo->uptimeSeriesByDay($monitorId, 7);
$dayMap = [];
foreach ($dayRows as $row) {
    $dayMap[(string) $row['bucket_day']] = [
        'uptime' => (float) ($row['uptime_percent'] ?? 0),
        'avg_response' => $row['avg_response_ms'] !== null ? (int) $row['avg_response_ms'] : null,
        'samples' => (int) ($row['sample_count'] ?? 0),
    ];
}

$dayData = [];
$dayStart = (new DateTimeImmutable('now'))->setTime(0, 0, 0)->sub(new DateInterval('P6D'));
for ($i = 0; $i < 7; $i++) {
    $d = $dayStart->add(new DateInterval('P' . $i . 'D'));
    $key = $d->format('Y-m-d');
    $label = $d->format('d M');
    $uptime = 0.0;
    $avgResponse = null;
    $samples = 0;

    if (isset($dayMap[$key])) {
        $uptime = (float) $dayMap[$key]['uptime'];
        $avgResponse = $dayMap[$key]['avg_response'];
        $samples = (int) $dayMap[$key]['samples'];
    }

    $dayData[] = [
        'label' => $label,
        'uptime' => $uptime,
        'avg_response' => $avgResponse,
        'samples' => $samples,
    ];
}

$avgResponse24 = $health['avg_response_24h_ms'];
$avgResponse7 = $health['avg_response_7d_ms'];
$responseDelta = null;
if ($avgResponse24 !== null && $avgResponse7 !== null) {
    $responseDelta = (int) $avgResponse24 - (int) $avgResponse7;
}
?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e((string) $monitor['name']); ?> • Monitor Detail</title>
    <style>
        body { margin:0; background:#f8fafc; font-family:"Segoe UI", Arial, sans-serif; color:#0f172a; }
        .shell { width:min(1160px, 94vw); margin:20px auto; }
        .top { display:flex; justify-content:space-between; gap:10px; align-items:center; flex-wrap:wrap; }
        .title { margin:0; font-size:1.4rem; }
        .badge { padding:6px 10px; border-radius:999px; font-weight:700; font-size:0.78rem; }
        .up { color:#065f46; background:#d1fae5; }
        .down { color:#7f1d1d; background:#fee2e2; }
        .degraded { color:#78350f; background:#fef3c7; }
        .unknown { color:#334155; background:#e2e8f0; }
        .links a { margin-right:10px; color:#0369a1; text-decoration:none; }
        .grid { margin-top:12px; display:grid; grid-template-columns: repeat(4,minmax(0,1fr)); gap:10px; }
        .card { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:12px; }
        .k { color:#64748b; font-size:0.82rem; }
        .v { margin-top:6px; font-size:1.15rem; font-weight:700; }
        .panel { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:12px; margin-top:12px; }
        .panel h2 { margin:0 0 10px; font-size:1rem; }
        .chart-shell { border:1px solid #e2e8f0; border-radius:10px; padding:10px; background:#f8fafc; }
        .chart-labels { display:flex; justify-content:space-between; color:#64748b; font-size:0.75rem; margin-top:6px; }
        .day-row { margin-bottom:9px; }
        .day-head { display:flex; justify-content:space-between; margin-bottom:4px; font-size:0.82rem; }
        .bar { height:10px; border-radius:999px; background:#e2e8f0; overflow:hidden; }
        .bar > span { display:block; height:100%; background:linear-gradient(90deg,#22c55e,#06b6d4); }
        .muted { color:#64748b; }
        .mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size:0.82rem; }
        .delta-good { color:#047857; }
        .delta-bad { color:#b91c1c; }
        table { width:100%; border-collapse:collapse; font-size:0.9rem; }
        th, td { border-bottom:1px solid #e2e8f0; padding:10px; text-align:left; }
        th { font-size:0.76rem; text-transform:uppercase; color:#475569; background:#f8fafc; }
        .split { display:grid; grid-template-columns: 1.1fr 1fr; gap:12px; }
        .tiny { font-size:0.78rem; }
        .quality-grid { display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-top:10px; }
        .quality-box { border:1px solid #e2e8f0; border-radius:10px; padding:8px; background:#f8fafc; min-width:0; }
        .quality-box h3 { margin:0 0 6px; font-size:0.88rem; }
        .quality-box ul { margin:0; padding:0; list-style:none; display:grid; gap:6px; }
        .quality-box li { display:flex; justify-content:space-between; gap:8px; border-bottom:1px solid #e2e8f0; padding-bottom:5px; }
        .quality-box li:last-child { border-bottom:0; padding-bottom:0; }
        @media (max-width: 980px) { .grid { grid-template-columns: repeat(2,minmax(0,1fr)); } .split { grid-template-columns: 1fr; } }
        @media (max-width: 700px) { .grid { grid-template-columns: 1fr; } th:nth-child(5), td:nth-child(5) { display:none; } }
    </style>
</head>
<body>
    <div class="shell">
        <div class="top">
            <div>
                <h1 class="title"><?= e((string) $monitor['name']); ?></h1>
                <div class="muted mono"><?= e((string) $monitor['url']); ?></div>
            </div>
            <div>
                <span class="badge <?= e(monitor_status_class((string) $monitor['current_status'])); ?>">
                    <?= e(monitor_status_label((string) $monitor['current_status'])); ?>
                </span>
            </div>
        </div>
        <div class="links" style="margin-top:8px;">
            <a href="<?= e(url_for('/index.php')); ?>">← Dashboard</a>
            <a href="<?= e(url_for('/monitor_edit.php', ['id' => $monitorId])); ?>">Düzenle</a>
            <a href="<?= e(url_for('/broken_links.php', ['monitor_id' => $monitorId])); ?>">Broken Links</a>
            <a href="<?= e(url_for('/link_scans.php', ['monitor_id' => $monitorId])); ?>">Link Scans</a>
            <a href="<?= e(url_for('/reports.php')); ?>">Reports</a>
            <a href="<?= e(url_for('/notifications.php')); ?>">Notifications</a>
            <a href="<?= e(url_for('/health.php')); ?>">Health</a>
            <a href="<?= e(url_for('/logout.php')); ?>">Çıkış</a>
        </div>

        <section class="grid">
            <article class="card">
                <div class="k">24s Uptime</div>
                <div class="v"><?= number_format((float) $health['uptime_24h_percent'], 2); ?>%</div>
            </article>
            <article class="card">
                <div class="k">7g Uptime</div>
                <div class="v"><?= number_format((float) $health['uptime_7d_percent'], 2); ?>%</div>
            </article>
            <article class="card">
                <div class="k">Ort. Yanıt (24s / 7g)</div>
                <div class="v">
                    <?= $avgResponse24 !== null ? (int) $avgResponse24 . ' ms' : '-'; ?>
                    /
                    <?= $avgResponse7 !== null ? (int) $avgResponse7 . ' ms' : '-'; ?>
                </div>
            </article>
            <article class="card">
                <div class="k">Açık Incident / 7g Incident</div>
                <div class="v"><?= (int) $health['open_incidents']; ?> / <?= (int) $health['incidents_7d']; ?></div>
            </article>
            <article class="card">
                <div class="k">Checks (24s / 7g)</div>
                <div class="v"><?= (int) $health['checks_24h']; ?> / <?= (int) $health['checks_7d']; ?></div>
            </article>
            <article class="card">
                <div class="k">Fail Threshold</div>
                <div class="v"><?= (int) $monitor['fail_threshold']; ?></div>
            </article>
            <article class="card">
                <div class="k">Consecutive Failures</div>
                <div class="v"><?= (int) $monitor['consecutive_failures']; ?></div>
            </article>
            <article class="card">
                <div class="k">Response Delta (24s-7g)</div>
                <div class="v <?= $responseDelta !== null && $responseDelta <= 0 ? 'delta-good' : 'delta-bad'; ?>">
                    <?= $responseDelta === null ? '-' : ($responseDelta > 0 ? '+' : '') . $responseDelta . ' ms'; ?>
                </div>
            </article>
        </section>

        <section class="panel">
            <h2>Uptime Trend (Son 24 Saat)</h2>
            <div class="chart-shell">
                <svg viewBox="0 0 100 100" preserveAspectRatio="none" style="width:100%;height:180px;display:block;">
                    <line x1="0" y1="0" x2="0" y2="100" stroke="#e2e8f0" stroke-width="0.4"></line>
                    <line x1="0" y1="50" x2="100" y2="50" stroke="#e2e8f0" stroke-width="0.4"></line>
                    <line x1="0" y1="100" x2="100" y2="100" stroke="#e2e8f0" stroke-width="0.4"></line>
                    <polyline fill="none" stroke="#0ea5e9" stroke-width="1.8" points="<?= e($hourPolylinePoints); ?>"></polyline>
                </svg>
                <div class="chart-labels">
                    <span><?= e($hourLabels[0] ?? ''); ?></span>
                    <span><?= e($hourLabels[12] ?? ''); ?></span>
                    <span><?= e($hourLabels[23] ?? ''); ?></span>
                </div>
            </div>
        </section>

        <section class="split">
            <article class="panel">
                <h2>Son Link Scan Özeti</h2>
                <?php if ($latestScan !== null): ?>
                    <table>
                        <tbody>
                            <tr><th>Status</th><td><?= e(strtoupper((string) $latestScan['status'])); ?></td></tr>
                            <tr><th>Started</th><td><?= e((string) $latestScan['started_at']); ?></td></tr>
                            <tr><th>Finished</th><td><?= e((string) ($latestScan['finished_at'] ?? '-')); ?></td></tr>
                            <tr><th>Total URLs</th><td><?= (int) $latestScan['total_urls']; ?></td></tr>
                            <tr><th>Checked</th><td><?= (int) $latestScan['checked_urls']; ?></td></tr>
                            <tr><th>Broken</th><td><?= (int) $latestScan['broken_urls']; ?></td></tr>
                            <tr><th>Duration</th><td><?= $latestScan['duration_seconds'] !== null ? (int) $latestScan['duration_seconds'] . 's' : '-'; ?></td></tr>
                        </tbody>
                    </table>
                    <div class="quality-grid">
                        <div class="quality-box">
                            <h3>Top Broken Targets</h3>
                            <ul>
                                <?php foreach ((array) ($latestQuality['top_targets'] ?? []) as $item): ?>
                                    <li><span class="mono"><?= e((string) ($item['target_url'] ?? '-')); ?></span><strong><?= (int) ($item['hit_count'] ?? 0); ?></strong></li>
                                <?php endforeach; ?>
                                <?php if ((array) ($latestQuality['top_targets'] ?? []) === []): ?><li><span class="muted">Kayıt yok</span><strong>0</strong></li><?php endif; ?>
                            </ul>
                        </div>
                        <div class="quality-box">
                            <h3>Problemli Kaynaklar</h3>
                            <ul>
                                <?php foreach ((array) ($latestQuality['top_sources'] ?? []) as $item): ?>
                                    <li><span class="mono"><?= e((string) ($item['source_url'] ?? '-')); ?></span><strong><?= (int) ($item['hit_count'] ?? 0); ?></strong></li>
                                <?php endforeach; ?>
                                <?php if ((array) ($latestQuality['top_sources'] ?? []) === []): ?><li><span class="muted">Kayıt yok</span><strong>0</strong></li><?php endif; ?>
                            </ul>
                        </div>
                    </div>
                <?php else: ?>
                    <p class="muted">Bu monitör için henüz link scan kaydı yok.</p>
                <?php endif; ?>
                <p class="tiny muted"><a href="<?= e(url_for('/link_scans.php', ['monitor_id' => $monitorId])); ?>">Tüm link scan joblarını aç</a></p>
            </article>

            <article class="panel">
                <h2>Broken Resource Özeti</h2>
                <p class="muted">Aktif: <strong><?= (int) $brokenSummary['active_count']; ?></strong> • Resolved: <strong><?= (int) $brokenSummary['resolved_count']; ?></strong></p>
                <table>
                    <thead>
                        <tr>
                            <th>Target</th>
                            <th>Code</th>
                            <th>Occur.</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($activeBrokenList as $b): ?>
                            <tr>
                                <td class="mono"><?= e((string) $b['target_url']); ?></td>
                                <td><?= $b['status_code'] !== null ? (int) $b['status_code'] : '-'; ?></td>
                                <td><?= (int) $b['occurrence_count']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($activeBrokenList === []): ?>
                            <tr><td colspan="3">Aktif broken resource yok.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                <p class="tiny muted"><a href="<?= e(url_for('/broken_links.php', ['monitor_id' => $monitorId, 'status' => 'active'])); ?>">Aktif broken kayıtlarını aç</a></p>
            </article>
        </section>

        <section class="split">
            <article class="panel">
                <h2>7 Günlük Uptime Dağılımı</h2>
                <?php foreach ($dayData as $day): ?>
                    <div class="day-row">
                        <div class="day-head">
                            <span><?= e((string) $day['label']); ?></span>
                            <span><?= number_format((float) $day['uptime'], 2); ?>%</span>
                        </div>
                        <div class="bar">
                            <span style="width:<?= e((string) max(0.0, min(100.0, (float) $day['uptime']))); ?>%;"></span>
                        </div>
                        <div class="muted" style="font-size:0.75rem; margin-top:3px;">
                            avg response: <?= $day['avg_response'] !== null ? (int) $day['avg_response'] . ' ms' : '-'; ?> • samples: <?= (int) $day['samples']; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </article>

            <article class="panel">
                <h2>Hızlı Karşılaştırma</h2>
                <p class="muted">24 saatlik performansı 7 günlük baseline ile karşılaştır.</p>
                <table>
                    <tbody>
                        <tr>
                            <th>Uptime</th>
                            <td><?= number_format((float) $health['uptime_24h_percent'], 2); ?>%</td>
                            <td><?= number_format((float) $health['uptime_7d_percent'], 2); ?>%</td>
                        </tr>
                        <tr>
                            <th>Avg Response</th>
                            <td><?= $avgResponse24 !== null ? (int) $avgResponse24 . ' ms' : '-'; ?></td>
                            <td><?= $avgResponse7 !== null ? (int) $avgResponse7 . ' ms' : '-'; ?></td>
                        </tr>
                        <tr>
                            <th>Checks</th>
                            <td><?= (int) $health['checks_24h']; ?></td>
                            <td><?= (int) $health['checks_7d']; ?></td>
                        </tr>
                        <tr>
                            <th>Incidents</th>
                            <td><?= (int) $health['open_incidents']; ?> open</td>
                            <td><?= (int) $health['incidents_7d']; ?> in 7d</td>
                        </tr>
                    </tbody>
                </table>
                <p class="muted" style="margin-top:8px; font-size:0.82rem;">Sol sütun 24 saat, sağ sütun 7 gün referansı.</p>
            </article>
        </section>

        <section class="panel">
            <h2>Son Kontroller</h2>
            <table>
                <thead>
                    <tr>
                        <th>Zaman</th>
                        <th>Durum</th>
                        <th>HTTP</th>
                        <th>Yanıt</th>
                        <th>Hata</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentChecks as $check): ?>
                        <tr>
                            <td><?= e((string) $check['checked_at']); ?></td>
                            <td><span class="badge <?= e(monitor_status_class((string) $check['status'])); ?>"><?= e(monitor_status_label((string) $check['status'])); ?></span></td>
                            <td><?= (int) ($check['http_code'] ?? 0) > 0 ? (int) $check['http_code'] : '-'; ?></td>
                            <td><?= $check['response_time_ms'] !== null ? (int) $check['response_time_ms'] . ' ms' : '-'; ?></td>
                            <td class="muted"><?= e((string) ($check['error_message'] ?? '-')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($recentChecks === []): ?>
                        <tr><td colspan="5">Henüz check kaydı yok.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </section>

        <section class="panel">
            <h2>Incident Geçmişi</h2>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Başlangıç</th>
                        <th>Bitiş</th>
                        <th>Süre (sn)</th>
                        <th>Son Hata</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($incidents as $incident): ?>
                        <tr>
                            <td>#<?= (int) $incident['id']; ?></td>
                            <td><?= e((string) $incident['started_at']); ?></td>
                            <td><?= e((string) ($incident['resolved_at'] ?? '-')); ?></td>
                            <td><?= $incident['duration_seconds'] !== null ? (int) $incident['duration_seconds'] : '-'; ?></td>
                            <td class="muted"><?= e((string) ($incident['last_error'] ?? '-')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($incidents === []): ?>
                        <tr><td colspan="5">Henüz incident kaydı yok.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </section>
    </div>
</body>
</html>
