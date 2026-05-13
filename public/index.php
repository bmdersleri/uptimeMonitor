<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_auth();

/**
 * @param string|null $dateTime
 */
function humanize_time($dateTime): string
{
    if ($dateTime === null || $dateTime === '') {
        return '-';
    }

    try {
        $target = new DateTimeImmutable($dateTime);
        $now = new DateTimeImmutable('now');
        $diff = $now->getTimestamp() - $target->getTimestamp();
        $abs = abs($diff);

        if ($abs < 60) {
            return $diff >= 0 ? 'az once' : 'birazdan';
        }

        if ($abs < 3600) {
            $minutes = (int) floor($abs / 60);
            return $diff >= 0 ? $minutes . ' dk once' : $minutes . ' dk sonra';
        }

        if ($abs < 86400) {
            $hours = (int) floor($abs / 3600);
            return $diff >= 0 ? $hours . ' sa once' : $hours . ' sa sonra';
        }

        $days = (int) floor($abs / 86400);
        return $diff >= 0 ? $days . ' gun once' : $days . ' gun sonra';
    } catch (Exception $e) {
        return (string) $dateTime;
    }
}

function status_label(string $status): string
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

function status_class(string $status): string
{
    switch ($status) {
        case 'up':
            return 'status-up';
        case 'down':
            return 'status-down';
        case 'degraded':
            return 'status-degraded';
        default:
            return 'status-unknown';
    }
}

$pdo = Database::connection();
$monitorRepo = new MonitorRepository($pdo);
$user = current_user();
$windowHours = (int) config('dashboard.kpi_window_hours', 24);
$tableLimit = (int) config('dashboard.table.max_rows', 200);
$viewArchived = (string) ($_GET['view'] ?? '') === 'archived';

$summary = $monitorRepo->dashboardSummary($windowHours);
$criticalMonitors = $monitorRepo->criticalMonitors(6);
$monitors = $viewArchived
    ? $monitorRepo->archivedMonitors($windowHours, $tableLimit)
    : $monitorRepo->dashboardMonitors($windowHours, $tableLimit);

$linkScanSummary = [
    'running_count' => 0,
    'stale_count' => 0,
    'latest_status' => '-',
    'latest_started_at' => null,
    'latest_finished_at' => null,
    'latest_monitor_name' => null,
];
try {
    $staleMinutes = max(10, (int) config('DEFAULT_LINK_SCAN_STALE_AFTER_MINUTES', '60'));
    $staleThreshold = (new DateTimeImmutable('now'))->sub(new DateInterval('PT' . $staleMinutes . 'M'))->format('Y-m-d H:i:s');
    $linkScanSql = "
        SELECT
            SUM(CASE WHEN j.status = 'running' THEN 1 ELSE 0 END) AS running_count,
            SUM(CASE WHEN j.status = 'running' AND j.started_at <= :stale_threshold THEN 1 ELSE 0 END) AS stale_count
        FROM link_scan_jobs j
    ";
    $linkScanStmt = $pdo->prepare($linkScanSql);
    $linkScanStmt->execute(['stale_threshold' => $staleThreshold]);
    $linkScanRow = $linkScanStmt->fetch();
    if (is_array($linkScanRow)) {
        $linkScanSummary['running_count'] = (int) ($linkScanRow['running_count'] ?? 0);
        $linkScanSummary['stale_count'] = (int) ($linkScanRow['stale_count'] ?? 0);
    }

    $latestScanSql = "
        SELECT j.status, j.started_at, j.finished_at, m.name AS monitor_name
        FROM link_scan_jobs j
        INNER JOIN monitors m ON m.id = j.monitor_id
        ORDER BY j.id DESC
        LIMIT 1
    ";
    $latestScan = $pdo->query($latestScanSql)->fetch();
    if (is_array($latestScan)) {
        $linkScanSummary['latest_status'] = (string) ($latestScan['status'] ?? '-');
        $linkScanSummary['latest_started_at'] = $latestScan['started_at'] ?? null;
        $linkScanSummary['latest_finished_at'] = $latestScan['finished_at'] ?? null;
        $linkScanSummary['latest_monitor_name'] = $latestScan['monitor_name'] ?? null;
    }
} catch (Throwable $e) {
    $linkScanSummary = [
        'running_count' => 0,
        'stale_count' => 0,
        'latest_status' => '-',
        'latest_started_at' => null,
        'latest_finished_at' => null,
        'latest_monitor_name' => null,
    ];
}

$brandTitle = (string) config('app.brand.title', config('APP_NAME', 'Uptime Monitor'));
$brandSubtitle = (string) config('app.brand.subtitle', 'Monitoring Console');
$accentColor = (string) config('dashboard.theme.accent', '#0ea5e9');
$dangerColor = (string) config('dashboard.theme.danger', '#ef4444');
$warningColor = (string) config('dashboard.theme.warning', '#f59e0b');
$successColor = (string) config('dashboard.theme.success', '#10b981');
$panelColor = (string) config('dashboard.theme.panel', 'rgba(15, 23, 42, 0.66)');

$uptime24h = (float) ($summary['uptime_24h_percent'] ?? 0);
$avgResponse = $summary['avg_response_24h_ms'];
?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($brandTitle); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --accent: <?= e($accentColor); ?>;
            --danger: <?= e($dangerColor); ?>;
            --warning: <?= e($warningColor); ?>;
            --success: <?= e($successColor); ?>;
            --panel: <?= e($panelColor); ?>;
            --text: #0f172a;
            --muted: #475569;
            --card: rgba(255, 255, 255, 0.82);
            --line: rgba(148, 163, 184, 0.35);
            --bg1: #f8fafc;
            --bg2: #ecfeff;
        }
        html[data-theme="dark"] {
            --text: #e2e8f0;
            --muted: #94a3b8;
            --card: rgba(15, 23, 42, 0.82);
            --line: rgba(71, 85, 105, 0.45);
            --bg1: #0b1220;
            --bg2: #111827;
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            color: var(--text);
            font-family: "IBM Plex Sans", "Segoe UI", sans-serif;
            background:
                radial-gradient(circle at 8% 10%, rgba(14, 165, 233, 0.16), transparent 32%),
                radial-gradient(circle at 92% 18%, rgba(16, 185, 129, 0.14), transparent 30%),
                linear-gradient(160deg, var(--bg1), var(--bg2));
        }

        .shell {
            width: min(1200px, 94vw);
            margin: 24px auto 36px;
        }

        .topbar {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 22px;
            padding: 18px 20px;
            display: flex;
            gap: 16px;
            justify-content: space-between;
            align-items: center;
            backdrop-filter: blur(8px);
            box-shadow: 0 14px 40px rgba(15, 23, 42, 0.06);
        }

        .title {
            margin: 0;
            font-family: "Space Grotesk", sans-serif;
            font-size: clamp(1.15rem, 2.7vw, 1.85rem);
            letter-spacing: 0.2px;
        }

        .subtitle {
            margin: 4px 0 0;
            color: var(--muted);
            font-size: 0.93rem;
        }

        .actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn {
            text-decoration: none;
            padding: 10px 14px;
            border-radius: 12px;
            font-weight: 600;
            border: 1px solid transparent;
            font-size: 0.92rem;
            cursor: pointer;
        }

        .btn-primary {
            color: #fff;
            background: linear-gradient(135deg, var(--accent), #0284c7);
            box-shadow: 0 8px 20px rgba(14, 165, 233, 0.25);
        }

        .btn-ghost {
            color: var(--text);
            border-color: var(--line);
            background: #ffffffc9;
        }
        html[data-theme="dark"] .btn-ghost {
            color: #e2e8f0;
            border-color: rgba(148, 163, 184, 0.34);
            background: rgba(30, 41, 59, 0.92);
        }
        html[data-theme="dark"] .btn-ghost:hover {
            color: #ffffff;
            border-color: rgba(56, 189, 248, 0.42);
            background: rgba(51, 65, 85, 0.96);
        }

        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
            margin-top: 14px;
        }

        .kpi {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 16px;
            padding: 14px;
            box-shadow: 0 10px 26px rgba(15, 23, 42, 0.05);
        }

        .kpi .label {
            color: var(--muted);
            font-size: 0.82rem;
            margin-bottom: 8px;
        }

        .kpi .value {
            font-size: 1.5rem;
            font-weight: 700;
            font-family: "Space Grotesk", sans-serif;
        }

        .kpi .meta {
            margin-top: 6px;
            font-size: 0.81rem;
            color: var(--muted);
        }

        .value-success { color: var(--success); }
        .value-danger { color: var(--danger); }
        .value-warning { color: var(--warning); }
        .value-accent { color: var(--accent); }

        .main-grid {
            margin-top: 14px;
            display: grid;
            grid-template-columns: 1.15fr 2fr;
            gap: 12px;
        }

        .panel {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 16px;
            padding: 14px;
            box-shadow: 0 10px 26px rgba(15, 23, 42, 0.05);
            min-height: 120px;
        }

        .panel h2 {
            margin: 0 0 10px;
            font-size: 0.98rem;
            font-weight: 700;
            letter-spacing: 0.2px;
        }

        .chip-list {
            display: grid;
            gap: 8px;
        }

        .chip {
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 10px;
            background: rgba(255, 255, 255, 0.8);
            display: grid;
            gap: 5px;
        }
        html[data-theme="dark"] .chip {
            background: rgba(30, 41, 59, 0.86);
        }

        .chip .name {
            font-weight: 600;
            font-size: 0.92rem;
            overflow-wrap: anywhere;
        }

        .chip .url {
            color: var(--muted);
            font-size: 0.81rem;
            overflow-wrap: anywhere;
        }

        .progress-shell {
            background: #e2e8f0;
            border-radius: 999px;
            height: 8px;
            overflow: hidden;
        }

        .progress {
            height: 100%;
            background: linear-gradient(90deg, #22c55e, #06b6d4);
            width: <?= e((string) max(0.0, min(100.0, $uptime24h))); ?>%;
            transition: width 0.3s ease;
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 0.3px;
            text-transform: uppercase;
        }

        .status-up { color: #065f46; background: rgba(16, 185, 129, 0.18); border: 1px solid rgba(16, 185, 129, 0.28); }
        .status-down { color: #7f1d1d; background: rgba(239, 68, 68, 0.16); border: 1px solid rgba(239, 68, 68, 0.28); }
        .status-degraded { color: #78350f; background: rgba(245, 158, 11, 0.16); border: 1px solid rgba(245, 158, 11, 0.28); }
        .status-unknown { color: #334155; background: rgba(148, 163, 184, 0.18); border: 1px solid rgba(148, 163, 184, 0.3); }
        .status-passive { color: #334155; background: rgba(100, 116, 139, 0.15); border: 1px solid rgba(100, 116, 139, 0.28); }
        .status-scan { color: #075985; background: rgba(14, 165, 233, 0.14); border: 1px solid rgba(14, 165, 233, 0.26); }

        .table-wrap {
            margin-top: 12px;
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 10px 26px rgba(15, 23, 42, 0.05);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.91rem;
        }

        th, td {
            padding: 11px 12px;
            border-bottom: 1px solid var(--line);
            text-align: left;
            vertical-align: middle;
        }

        th {
            font-size: 0.76rem;
            color: #334155;
            text-transform: uppercase;
            letter-spacing: 0.45px;
            background: rgba(148, 163, 184, 0.08);
        }
        html[data-theme="dark"] th {
            color: #cbd5e1;
            background: rgba(148, 163, 184, 0.12);
        }

        tbody tr:hover {
            background: rgba(14, 165, 233, 0.07);
        }

        .mono {
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: 0.82rem;
        }

        .text-muted {
            color: var(--muted);
            font-size: 0.82rem;
        }

        .uptime-tag {
            font-weight: 700;
        }

        .error-mini {
            max-width: 240px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            color: #7f1d1d;
            font-size: 0.82rem;
        }
        .inline-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }
        .inline-actions form {
            margin: 0;
        }
        .btn-small {
            border: 1px solid var(--line);
            border-radius: 9px;
            background: #fff;
            color: var(--text);
            cursor: pointer;
            font: inherit;
            font-size: 0.78rem;
            font-weight: 700;
            padding: 6px 8px;
            white-space: nowrap;
        }
        html[data-theme="dark"] .btn-small {
            color: #e2e8f0;
            border-color: rgba(148, 163, 184, 0.36);
            background: rgba(30, 41, 59, 0.92);
        }
        html[data-theme="dark"] .btn-small:hover {
            color: #ffffff;
            border-color: rgba(56, 189, 248, 0.42);
            background: rgba(51, 65, 85, 0.96);
        }
        .btn-danger {
            color: #7f1d1d;
            border-color: rgba(239, 68, 68, 0.35);
            background: rgba(254, 226, 226, 0.76);
        }
        html[data-theme="dark"] .btn-danger {
            color: #fecaca;
            border-color: rgba(248, 113, 113, 0.48);
            background: rgba(127, 29, 29, 0.82);
        }
        html[data-theme="dark"] .btn-danger:hover {
            color: #ffffff;
            background: rgba(153, 27, 27, 0.92);
        }
        .btn-warning {
            color: #78350f;
            border-color: rgba(245, 158, 11, 0.35);
            background: rgba(254, 243, 199, 0.76);
        }
        html[data-theme="dark"] .btn-warning {
            color: #fde68a;
            border-color: rgba(251, 191, 36, 0.52);
            background: rgba(120, 53, 15, 0.84);
        }
        html[data-theme="dark"] .btn-warning:hover {
            color: #ffffff;
            background: rgba(146, 64, 14, 0.94);
        }

        @media (max-width: 1060px) {
            .kpi-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .main-grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 720px) {
            .shell { width: min(96vw, 96vw); margin-top: 14px; }
            .topbar { flex-direction: column; align-items: flex-start; border-radius: 16px; }
            .kpi-grid { grid-template-columns: 1fr; }
            table { font-size: 0.86rem; }
            th:nth-child(2), td:nth-child(2),
            th:nth-child(8), td:nth-child(8),
            th:nth-child(9), td:nth-child(9),
            th:nth-child(10), td:nth-child(10) {
                display: none;
            }
        }
    </style>
</head>
<body>
    <div class="shell">
        <header class="topbar">
            <div>
                <h1 class="title"><?= e($brandTitle); ?></h1>
                <p class="subtitle"><?= e($brandSubtitle); ?> • <?= e((string) $windowHours); ?> saatlik pencere</p>
            </div>
            <div class="actions">
                <span class="btn btn-ghost"><?= e((string) ($user['email'] ?? '')); ?></span>
                <a class="btn btn-primary" href="<?= e(url_for('/monitors_create.php')); ?>">+ Monitör Ekle</a>
                <?php if ($viewArchived): ?>
                    <a class="btn btn-ghost" href="<?= e(url_for('/index.php')); ?>">Aktif Liste</a>
                <?php else: ?>
                    <a class="btn btn-ghost" href="<?= e(url_for('/index.php', ['view' => 'archived'])); ?>">Arşiv</a>
                <?php endif; ?>
                <a class="btn btn-ghost" href="<?= e(url_for('/broken_links.php')); ?>">Broken Links</a>
                <a class="btn btn-ghost" href="<?= e(url_for('/link_scans.php')); ?>">Link Scans</a>
                <a class="btn btn-ghost" href="<?= e(url_for('/retry_queue.php')); ?>">Retry Queue</a>
                <a class="btn btn-ghost" href="<?= e(url_for('/notifications.php')); ?>">Notifications</a>
                <a class="btn btn-ghost" href="<?= e(url_for('/health.php')); ?>">Health</a>
                <a class="btn btn-ghost" href="<?= e(url_for('/index.php')); ?>">Yenile</a>
                <button class="btn btn-ghost" id="theme-toggle" type="button">Tema</button>
                <a class="btn btn-ghost" href="<?= e(url_for('/logout.php')); ?>">Çıkış</a>
            </div>
        </header>

        <section class="kpi-grid">
            <article class="kpi">
                <div class="label">Toplam Aktif Monitör</div>
                <div class="value value-accent"><?= (int) $summary['total_count']; ?></div>
                <div class="meta">Sistemde izlenen toplam servis</div>
            </article>
            <article class="kpi">
                <div class="label">UP</div>
                <div class="value value-success"><?= (int) $summary['up_count']; ?></div>
                <div class="meta">Sağlıklı servis sayısı</div>
            </article>
            <article class="kpi">
                <div class="label">DOWN</div>
                <div class="value value-danger"><?= (int) $summary['down_count']; ?></div>
                <div class="meta">Müdahale gerektiren servis</div>
            </article>
            <article class="kpi">
                <div class="label">DEGRADED</div>
                <div class="value value-warning"><?= (int) $summary['degraded_count']; ?></div>
                <div class="meta">Yavaş veya kararsız servis</div>
            </article>
            <article class="kpi">
                <div class="label"><?= e((string) $windowHours); ?> Saat Uptime</div>
                <div class="value"><?= number_format($uptime24h, 2); ?>%</div>
                <div class="meta"><?= (int) $summary['checks_24h']; ?> kontrol kaydı</div>
            </article>
            <article class="kpi">
                <div class="label">Ort. Yanit Suresi</div>
                <div class="value"><?= $avgResponse === null ? '-' : (int) $avgResponse . ' ms'; ?></div>
                <div class="meta">Aktif monitorlerin ortalamasi</div>
            </article>
            <article class="kpi">
                <div class="label">Acik Incident</div>
                <div class="value value-danger"><?= (int) $summary['open_incidents']; ?></div>
                <div class="meta">Henuz kapanmamis olay</div>
            </article>
            <article class="kpi">
                <div class="label">Aktif Broken Link</div>
                <div class="value value-danger"><?= (int) ($summary['active_broken_links'] ?? 0); ?></div>
                <div class="meta"><a href="<?= e(url_for('/broken_links.php')); ?>" style="color:inherit;">Detayları görüntüle</a></div>
            </article>
            <article class="kpi">
                <div class="label">Link Scan Job</div>
                <div class="value <?= (int) $linkScanSummary['stale_count'] > 0 ? 'value-danger' : ((int) $linkScanSummary['running_count'] > 0 ? 'value-warning' : 'value-success'); ?>">
                    <?= (int) $linkScanSummary['running_count']; ?> running
                </div>
                <div class="meta">
                    Son: <?= e(strtoupper((string) $linkScanSummary['latest_status'])); ?>
                    <?= $linkScanSummary['latest_monitor_name'] !== null ? '• ' . e((string) $linkScanSummary['latest_monitor_name']) : ''; ?>
                    <?php if ((int) $linkScanSummary['stale_count'] > 0): ?>
                        • <?= (int) $linkScanSummary['stale_count']; ?> takılı
                    <?php endif; ?>
                </div>
            </article>
            <article class="kpi">
                <div class="label">Son Kontrol</div>
                <div class="value" style="font-size:1.15rem;"><?= e(humanize_time($summary['last_check_at'] ?? null)); ?></div>
                <div class="meta"><?= e((string) ($summary['last_check_at'] ?? '-')); ?></div>
            </article>
        </section>

        <?php if ((int) $linkScanSummary['stale_count'] > 0): ?>
            <section class="panel" style="margin-top:14px; border-color:rgba(239,68,68,0.35);">
                <h2>Link Scan Uyarısı</h2>
                <p class="text-muted" style="margin:0;">
                    <?= (int) $linkScanSummary['stale_count']; ?> link scan job takılı görünüyor.
                    <a href="<?= e(url_for('/link_scans.php', ['status' => 'running'])); ?>" style="color:inherit;font-weight:700;">Link Scan Jobs ekranından kontrol edin.</a>
                </p>
            </section>
        <?php endif; ?>

        <section class="main-grid">
            <article class="panel">
                <h2>Kritik Monitörler</h2>
                <div class="chip-list">
                    <?php foreach ($criticalMonitors as $critical): ?>
                        <div class="chip">
                            <div>
                                <span class="status-pill <?= e(status_class((string) $critical['current_status'])); ?>">
                                    <?= e(status_label((string) $critical['current_status'])); ?>
                                </span>
                            </div>
                            <div class="name"><?= e((string) $critical['name']); ?></div>
                            <div class="url"><?= e((string) $critical['url']); ?></div>
                            <div class="text-muted">Son kontrol: <?= e(humanize_time((string) ($critical['last_check_at'] ?? ''))); ?></div>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($criticalMonitors === []): ?>
                        <div class="chip">
                            <div class="name">Kritik servis yok</div>
                            <div class="text-muted">Tebrikler, su an down/degraded monitor bulunmuyor.</div>
                        </div>
                    <?php endif; ?>
                </div>
            </article>

            <article class="panel">
                <h2>Genel Uptime Göstergesi</h2>
                <div class="progress-shell">
                    <div class="progress"></div>
                </div>
                <p class="text-muted" style="margin-top:10px;">
                    Son <?= e((string) $windowHours); ?> saatte sistem genelinde
                    <span class="uptime-tag"><?= number_format($uptime24h, 2); ?>%</span> uptime gözlemlendi.
                </p>
                <p class="text-muted">
                    Hedef: 99.90%+ • Kontrol adedi: <?= (int) $summary['checks_24h']; ?>
                </p>
            </article>
        </section>

        <section class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Monitör</th>
                        <th>URL</th>
                        <th>Aktiflik</th>
                        <th>Link Scan</th>
                        <th>Durum</th>
                        <th>Son HTTP</th>
                        <th>Son Yanit</th>
                        <th><?= e((string) $windowHours); ?>s Uptime</th>
                        <th>Hata Sayaci</th>
                        <th>Son Hata</th>
                        <th>Son Kontrol</th>
                        <th>İşlem</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($monitors as $monitor): ?>
                        <?php
                        $status = (string) ($monitor['current_status'] ?? 'unknown');
                        $uptime = $monitor['uptime_24h_percent'];
                        $isActive = (int) ($monitor['is_active'] ?? 0) === 1;
                        $linkScanEnabled = (int) ($monitor['link_scan_enabled'] ?? 1) === 1;
                        ?>
                        <tr>
                            <td>
                                <div style="font-weight:600;">
                                    <a href="<?= e(url_for('/monitor_detail.php', ['id' => (int) $monitor['id']])); ?>" style="color:inherit;text-decoration:none;">
                                        <?= e((string) $monitor['name']); ?>
                                    </a>
                                </div>
                                <div class="text-muted">
                                    incident: <?= (int) ($monitor['open_incident_count'] ?? 0); ?>
                                    •
                                    <a href="<?= e(url_for('/monitor_edit.php', ['id' => (int) $monitor['id']])); ?>" style="color:inherit;">düzenle</a>
                                </div>
                            </td>
                            <td class="mono"><?= e((string) $monitor['url']); ?></td>
                            <td>
                                <?php if ($isActive): ?>
                                    <span class="status-pill status-up">Aktif</span>
                                <?php else: ?>
                                    <span class="status-pill status-passive">Pasif</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($linkScanEnabled): ?>
                                    <span class="status-pill status-scan">Açık</span>
                                <?php else: ?>
                                    <span class="status-pill status-passive">Kapalı</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="status-pill <?= e(status_class($status)); ?>">
                                    <?= e(status_label($status)); ?>
                                </span>
                            </td>
                            <td><?= (int) ($monitor['last_http_code'] ?? 0) > 0 ? (int) $monitor['last_http_code'] : '-'; ?></td>
                            <td><?= $monitor['last_response_ms'] !== null ? (int) $monitor['last_response_ms'] . ' ms' : '-'; ?></td>
                            <td><?= $uptime !== null ? number_format((float) $uptime, 2) . '%' : '-'; ?></td>
                            <td><?= (int) $monitor['consecutive_failures']; ?>/<?= (int) $monitor['fail_threshold']; ?></td>
                            <td>
                                <?php $lastError = trim((string) ($monitor['last_error_message'] ?? '')); ?>
                                <?php if ($lastError === ''): ?>
                                    <span class="text-muted">-</span>
                                <?php else: ?>
                                    <span class="error-mini" title="<?= e($lastError); ?>"><?= e($lastError); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div><?= e((string) ($monitor['last_check_time'] ?? '-')); ?></div>
                                <div class="text-muted"><?= e(humanize_time((string) ($monitor['last_check_time'] ?? ''))); ?></div>
                            </td>
                            <td>
                                <div class="inline-actions">
                                    <?php if ($viewArchived): ?>
                                        <form method="post" action="<?= e(url_for('/monitor_action.php')); ?>">
                                            <input type="hidden" name="monitor_id" value="<?= (int) $monitor['id']; ?>">
                                            <input type="hidden" name="action" value="restore">
                                            <button class="btn-small" type="submit">Geri Al</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="post" action="<?= e(url_for('/monitor_action.php')); ?>">
                                            <input type="hidden" name="monitor_id" value="<?= (int) $monitor['id']; ?>">
                                            <input type="hidden" name="action" value="<?= $isActive ? 'deactivate' : 'activate'; ?>">
                                            <button class="btn-small" type="submit"><?= $isActive ? 'Pasifleştir' : 'Aktifleştir'; ?></button>
                                        </form>
                                        <form method="post" action="<?= e(url_for('/monitor_action.php')); ?>" onsubmit="return confirm('Bu monitör arşivlensin mi? Geçmiş kayıtlar korunur.');">
                                            <input type="hidden" name="monitor_id" value="<?= (int) $monitor['id']; ?>">
                                            <input type="hidden" name="action" value="archive">
                                            <button class="btn-small btn-warning" type="submit">Arşivle</button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="post" action="<?= e(url_for('/monitor_action.php')); ?>" onsubmit="return confirm('Bu monitör kalıcı olarak silinsin mi? İlişkili geçmiş kayıtlar da silinir.');">
                                        <input type="hidden" name="monitor_id" value="<?= (int) $monitor['id']; ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="return_view" value="<?= $viewArchived ? 'archived' : 'active'; ?>">
                                        <button class="btn-small btn-danger" type="submit">Sil</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($monitors === []): ?>
                        <tr>
                            <td colspan="12"><?= $viewArchived ? 'Arşivlenmiş monitör yok.' : 'Henüz monitör yok. “Monitör Ekle” ile başlayabilirsiniz.'; ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </section>
    </div>
    <script>
        (function () {
            var key = 'ui-theme';
            var root = document.documentElement;
            var btn = document.getElementById('theme-toggle');
            if (!btn) {
                return;
            }
            var saved = localStorage.getItem(key);
            var prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
            var theme = saved || (prefersDark ? 'dark' : 'light');
            root.setAttribute('data-theme', theme);
            btn.textContent = theme === 'dark' ? 'Light' : 'Dark';
            btn.addEventListener('click', function () {
                theme = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
                root.setAttribute('data-theme', theme);
                localStorage.setItem(key, theme);
                btn.textContent = theme === 'dark' ? 'Light' : 'Dark';
            });
        })();
    </script>
</body>
</html>
