<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_auth();

function link_scans_ensure_ignore_columns(PDO $pdo): void
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

/**
 * @param array<string, mixed> $filters
 * @return array{where: array<int, string>, params: array<string, mixed>}
 */
function link_scans_filter_sql(array $filters): array
{
    $where = [];
    $params = [];

    if (isset($filters['monitor_id']) && (int) $filters['monitor_id'] > 0) {
        $where[] = 'j.monitor_id = :monitor_id';
        $params['monitor_id'] = (int) $filters['monitor_id'];
    }

    if (isset($filters['status']) && (string) $filters['status'] !== '' && (string) $filters['status'] !== 'all') {
        $where[] = 'j.status = :status';
        $params['status'] = (string) $filters['status'];
    }

    $days = isset($filters['days']) ? (int) $filters['days'] : 0;
    if ($days > 0) {
        $params['started_after'] = (new DateTimeImmutable('now'))
            ->sub(new DateInterval('P' . min(3650, $days) . 'D'))
            ->format('Y-m-d H:i:s');
        $where[] = 'j.started_at >= :started_after';
    }

    return ['where' => $where, 'params' => $params];
}

/**
 * @param array<string, mixed> $params
 */
function link_scans_bind_params(PDOStatement $stmt, array $params): void
{
    foreach ($params as $key => $value) {
        if ($key === 'monitor_id') {
            $stmt->bindValue(':' . $key, (int) $value, PDO::PARAM_INT);
        } else {
            $stmt->bindValue(':' . $key, (string) $value);
        }
    }
}

/**
 * @param array<string, mixed> $filters
 */
function link_scans_count(PDO $pdo, array $filters): int
{
    $query = link_scans_filter_sql($filters);
    $sql = "
        SELECT COUNT(*) AS total
        FROM link_scan_jobs j
        INNER JOIN monitors m ON m.id = j.monitor_id
    ";
    if ($query['where'] !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $query['where']);
    }

    $stmt = $pdo->prepare($sql);
    link_scans_bind_params($stmt, $query['params']);
    $stmt->execute();
    $row = $stmt->fetch();
    return is_array($row) ? (int) ($row['total'] ?? 0) : 0;
}

/**
 * @param array<string, mixed> $filters
 * @return array<int, array<string, mixed>>
 */
function link_scans_list(PDO $pdo, array $filters, int $limit, int $offset): array
{
    $query = link_scans_filter_sql($filters);
    $sql = "
        SELECT
            j.*,
            m.name AS monitor_name,
            m.url AS monitor_url
        FROM link_scan_jobs j
        INNER JOIN monitors m ON m.id = j.monitor_id
    ";
    if ($query['where'] !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $query['where']);
    }
    $sql .= ' ORDER BY j.id DESC LIMIT :limit OFFSET :offset';

    $stmt = $pdo->prepare($sql);
    link_scans_bind_params($stmt, $query['params']);
    $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
    $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function link_scans_close_stale(PDO $pdo, int $staleMinutes): int
{
    $staleMinutes = max(10, $staleMinutes);
    $now = new DateTimeImmutable('now');
    $threshold = $now->sub(new DateInterval('PT' . $staleMinutes . 'M'))->format('Y-m-d H:i:s');
    $finishedAt = $now->format('Y-m-d H:i:s');

    $stmt = $pdo->prepare("
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

    return $stmt->rowCount();
}

$pdo = Database::connection();
$monitorRepo = new MonitorRepository($pdo);
$scanRepo = new LinkScanRepository($pdo);

$monitorId = isset($_GET['monitor_id']) ? (int) $_GET['monitor_id'] : 0;
$status = isset($_GET['status']) ? (string) $_GET['status'] : 'all';
if (!in_array($status, ['all', 'running', 'completed', 'failed'], true)) {
    $status = 'all';
}
$jobId = isset($_GET['job_id']) ? (int) $_GET['job_id'] : 0;
$daysFilter = isset($_GET['days']) ? (int) $_GET['days'] : 0;
if (!in_array($daysFilter, [0, 1, 7, 30, 90, 180, 365], true)) {
    $daysFilter = 0;
}
$perPage = isset($_GET['per_page']) ? (int) $_GET['per_page'] : 50;
if (!in_array($perPage, [25, 50, 100, 300], true)) {
    $perPage = 50;
}
$page = max(1, isset($_GET['page']) ? (int) $_GET['page'] : 1);
$offset = ($page - 1) * $perPage;

$rows = [];
$counts = ['running_count' => 0, 'completed_count' => 0, 'failed_count' => 0];
$totalRows = 0;
$totalPages = 1;
$errorMessage = null;
$selectedJob = null;
$selectedBrokenTargets = [];
$selectedQuality = ['top_targets' => [], 'top_sources' => [], 'status_codes' => []];
$runningJob = null;

try {
    link_scans_ensure_ignore_columns($pdo);
    link_scans_close_stale($pdo, (int) config('DEFAULT_LINK_SCAN_STALE_AFTER_MINUTES', '60'));
    $filters = [
        'monitor_id' => $monitorId,
        'status' => $status,
        'days' => $daysFilter,
    ];
    $totalRows = link_scans_count($pdo, $filters);
    $totalPages = max(1, (int) ceil($totalRows / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
        $offset = ($page - 1) * $perPage;
    }
    $rows = link_scans_list($pdo, $filters, $perPage, $offset);
    $counts = $scanRepo->quickCounts();
    $runningJob = $scanRepo->findRunningWithMonitor($monitorId);
} catch (Throwable $e) {
    $errorMessage = $e->getMessage();
}

if ($jobId > 0 && $errorMessage === null) {
    try {
        $selectedJob = $scanRepo->findWithMonitor($jobId);
        if ($selectedJob !== null) {
            $selectedBrokenTargets = $scanRepo->brokenTargetsForJob($jobId, 120);
            $selectedQuality = $scanRepo->qualityReportForJob($jobId);
        }
    } catch (Throwable $e) {
        $selectedJob = null;
    }
}

$monitors = $monitorRepo->all();
$manualDepth = (int) config('DEFAULT_LINK_SCAN_MAX_DEPTH', 3);
foreach ($monitors as $m) {
    if ($monitorId > 0 && (int) ($m['id'] ?? 0) === $monitorId) {
        $manualDepth = max(1, (int) ($m['link_scan_max_depth'] ?? $manualDepth));
        break;
    }
}
?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Link Scan Jobs</title>
    <style>
        :root {
            --bg1: #f8fafc;
            --bg2: #ecfeff;
            --text: #0f172a;
            --muted: #475569;
            --line: rgba(148, 163, 184, 0.32);
            --card: rgba(255, 255, 255, 0.88);
            --accent: #0284c7;
            --accent2: #06b6d4;
            --danger: #ef4444;
            --success: #16a34a;
            --warning: #f59e0b;
        }
        html[data-theme="dark"] {
            --bg1: #0b1220;
            --bg2: #111827;
            --text: #e2e8f0;
            --muted: #94a3b8;
            --line: rgba(71, 85, 105, 0.55);
            --card: rgba(15, 23, 42, 0.85);
            --accent: #38bdf8;
            --accent2: #22d3ee;
            --danger: #f87171;
            --success: #34d399;
            --warning: #fbbf24;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            color: var(--text);
            font-family: "IBM Plex Sans", "Segoe UI", Arial, sans-serif;
            background:
                radial-gradient(circle at 8% 10%, rgba(14, 165, 233, 0.16), transparent 30%),
                radial-gradient(circle at 93% 15%, rgba(16, 185, 129, 0.14), transparent 28%),
                linear-gradient(160deg, var(--bg1), var(--bg2));
        }
        .shell { width: min(1220px, 95vw); margin: 20px auto 26px; }
        .top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            padding: 16px;
            border: 1px solid var(--line);
            border-radius: 16px;
            background: var(--card);
            backdrop-filter: blur(6px);
        }
        .title { margin: 0; font-size: 1.4rem; font-weight: 700; }
        .subtitle { margin: 6px 0 0; color: var(--muted); font-size: 0.88rem; }
        .actions { display:flex; gap:8px; flex-wrap:wrap; }
        .btn {
            border: 1px solid var(--line);
            background: transparent;
            color: var(--text);
            border-radius: 10px;
            padding: 9px 12px;
            text-decoration: none;
            font-weight: 600;
            cursor: pointer;
        }
        .btn-primary {
            background: linear-gradient(135deg, var(--accent), var(--accent2));
            color: #fff;
            border-color: transparent;
        }
        .btn-danger {
            background: rgba(239, 68, 68, 0.12);
            border-color: rgba(239, 68, 68, 0.42);
            color: var(--danger);
        }
        .btn-small {
            padding: 6px 9px;
            border-radius: 8px;
            font-size: 0.78rem;
        }
        .btn[disabled] { opacity: 0.5; cursor: not-allowed; }
        .grid {
            margin-top: 12px;
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 10px;
        }
        .card {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 12px;
        }
        .k { color: var(--muted); font-size: 0.82rem; }
        .v { margin-top: 6px; font-size: 1.45rem; font-weight: 700; }
        .v-running { color: var(--accent); }
        .v-ok { color: var(--success); }
        .v-fail { color: var(--danger); }
        .panel {
            margin-top: 12px;
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 12px;
        }
        .panel h2 { margin: 0 0 10px; font-size: 1rem; }
        .filters {
            display: grid;
            grid-template-columns: 1.2fr 1fr 120px 120px 120px auto auto;
            gap: 8px;
            align-items: end;
        }
        .toolbar-row {
            margin-top: 10px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 8px;
        }
        .inline-tools {
            display: flex;
            align-items: end;
            flex-wrap: wrap;
            gap: 8px;
        }
        .inline-tools label { display:block; }
        .inline-tools select { min-width: 160px; }
        .pagination {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .pagination .btn[aria-disabled="true"] {
            opacity: 0.5;
            pointer-events: none;
        }
        select, input {
            width: 100%;
            border: 1px solid var(--line);
            background: transparent;
            color: var(--text);
            border-radius: 9px;
            padding: 9px 10px;
        }
        table { width:100%; border-collapse: collapse; font-size: 0.9rem; }
        th, td { border-bottom: 1px solid var(--line); padding: 10px; text-align:left; vertical-align: top; }
        th {
            font-size: 0.75rem;
            letter-spacing: 0.4px;
            text-transform: uppercase;
            color: var(--muted);
            background: rgba(148, 163, 184, 0.08);
        }
        .mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.8rem; word-break: break-all; }
        .muted { color: var(--muted); }
        .badge {
            padding: 4px 8px;
            border-radius: 999px;
            font-size: 0.74rem;
            font-weight: 700;
        }
        .running { color: #1d4ed8; background: rgba(59,130,246,0.2); }
        .completed { color: #065f46; background: rgba(16,185,129,0.18); }
        .failed { color: #7f1d1d; background: rgba(239,68,68,0.2); }
        .live-grid {
            display: grid;
            grid-template-columns: 1.1fr 1fr;
            gap: 10px;
        }
        .live-box {
            border: 1px dashed var(--line);
            border-radius: 12px;
            padding: 10px;
            min-height: 120px;
            transition: border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
        }
        .live-box.is-running {
            border-style: solid;
            border-color: rgba(6, 182, 212, 0.55);
            background: rgba(6, 182, 212, 0.06);
            box-shadow: 0 0 0 3px rgba(6, 182, 212, 0.08), 0 14px 26px rgba(2, 132, 199, 0.10);
        }
        .live-target {
            margin-top: 8px;
            font-size: 0.84rem;
            line-height: 1.45;
            word-break: break-all;
        }
        .live-status-line {
            margin-top: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 800;
        }
        .scan-dot {
            width: 11px;
            height: 11px;
            border-radius: 999px;
            background: var(--muted);
            display: inline-block;
        }
        .scan-dot.is-running {
            background: var(--accent2);
            box-shadow: 0 0 0 rgba(6, 182, 212, 0.55);
            animation: pulseDot 1.4s infinite;
        }
        .current-focus {
            margin-top: 10px;
            display: grid;
            gap: 8px;
        }
        .current-url {
            margin-top: 4px;
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 9px 10px;
            background: rgba(255,255,255,0.52);
            color: var(--text);
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: 0.86rem;
            font-weight: 700;
            line-height: 1.4;
            word-break: break-all;
        }
        .current-url.primary {
            border-color: rgba(6, 182, 212, 0.38);
            background: rgba(6, 182, 212, 0.11);
            font-size: 0.92rem;
        }
        .progress {
            margin-top: 12px;
            height: 18px;
            border-radius: 999px;
            background: rgba(148, 163, 184, 0.24);
            overflow: hidden;
            position: relative;
        }
        .progress > span {
            display: block;
            height: 100%;
            width: 0%;
            background: linear-gradient(90deg, var(--accent), var(--accent2));
            transition: width 0.25s ease;
        }
        .progress.is-running > span {
            background:
                repeating-linear-gradient(45deg, rgba(255,255,255,0.28) 0 10px, rgba(255,255,255,0.08) 10px 20px),
                linear-gradient(90deg, var(--accent), var(--accent2), #10b981);
            background-size: 42px 42px, 100% 100%;
            animation: progressMove 0.85s linear infinite;
        }
        .progress-label {
            margin-top: 8px;
            display: flex;
            justify-content: space-between;
            gap: 10px;
            align-items: center;
            font-size: 0.82rem;
        }
        .progress-label strong {
            color: var(--accent);
            font-size: 1rem;
        }
        @keyframes progressMove {
            from { background-position: 0 0, 0 0; }
            to { background-position: 42px 0, 0 0; }
        }
        @keyframes pulseDot {
            0% { box-shadow: 0 0 0 0 rgba(6, 182, 212, 0.55); }
            70% { box-shadow: 0 0 0 9px rgba(6, 182, 212, 0); }
            100% { box-shadow: 0 0 0 0 rgba(6, 182, 212, 0); }
        }
        .recent-list {
            margin: 0;
            padding-left: 18px;
            max-height: 220px;
            overflow: auto;
            font-size: 0.82rem;
        }
        .recent-list li { margin-bottom: 6px; }
        .quality-grid { display:grid; grid-template-columns:1fr 1fr 0.7fr; gap:10px; margin:12px 0; }
        .quality-box { border:1px solid var(--line); border-radius:12px; padding:10px; min-width:0; }
        .quality-box h3 { margin:0 0 8px; font-size:0.9rem; }
        .quality-list { margin:0; padding:0; list-style:none; display:grid; gap:7px; }
        .quality-list li { display:flex; justify-content:space-between; gap:10px; border-bottom:1px solid var(--line); padding-bottom:7px; }
        .quality-list li:last-child { border-bottom:0; padding-bottom:0; }
        .tiny { font-size: 0.78rem; }
        .notice {
            margin-top: 8px;
            border-radius: 10px;
            padding: 9px 10px;
            display: none;
        }
        .notice.ok { background: rgba(16,185,129,0.14); border: 1px solid rgba(16,185,129,0.3); color: #065f46; }
        .notice.err { background: rgba(239,68,68,0.14); border: 1px solid rgba(239,68,68,0.3); color: #7f1d1d; }
        @media (max-width: 1100px) {
            .filters { grid-template-columns: 1fr 1fr; }
            .live-grid { grid-template-columns: 1fr; }
            .quality-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 760px) {
            .grid { grid-template-columns: 1fr; }
            th:nth-child(2), td:nth-child(2),
            th:nth-child(5), td:nth-child(5) { display:none; }
        }
    </style>
</head>
<body>
    <div class="shell">
        <header class="top">
            <div>
                <h1 class="title">Link Scan Jobs</h1>
                <p class="subtitle">Elle tetikle, canlı izle, sayfa yenilemeden takip et.</p>
            </div>
            <div class="actions">
                <button class="btn" id="theme-toggle" type="button">Tema</button>
                <a class="btn" href="<?= e(url_for('/index.php')); ?>">Dashboard</a>
                <a class="btn" href="<?= e(url_for('/broken_links.php')); ?>">Broken Links</a>
                <a class="btn" href="<?= e(url_for('/notifications.php')); ?>">Notifications</a>
                <a class="btn" href="<?= e(url_for('/health.php')); ?>">Health</a>
                <a class="btn" href="<?= e(url_for('/logout.php')); ?>">Çıkış</a>
            </div>
        </header>

        <section class="grid">
            <article class="card"><div class="k">Running</div><div id="kpi-running" class="v v-running"><?= (int) $counts['running_count']; ?></div></article>
            <article class="card"><div class="k">Completed</div><div id="kpi-completed" class="v v-ok"><?= (int) $counts['completed_count']; ?></div></article>
            <article class="card"><div class="k">Failed</div><div id="kpi-failed" class="v v-fail"><?= (int) $counts['failed_count']; ?></div></article>
        </section>

        <section class="panel">
            <?php if ($errorMessage !== null): ?>
                <div style="margin-bottom:10px; padding:10px; border:1px solid #fecaca; background:#fff1f2; color:#9f1239; border-radius:8px;">
                    Link scan tabloları hazır değil. `php database/migrate_sqlite.php` çalıştırın.
                </div>
            <?php endif; ?>
            <form method="get" class="filters" id="filters-form">
                <input type="hidden" name="page" value="1">
                <div>
                    <label>Monitör</label>
                    <select name="monitor_id" id="monitor_id">
                        <option value="0">Hepsi</option>
                        <?php foreach ($monitors as $m): ?>
                            <option
                                value="<?= (int) $m['id']; ?>"
                                data-depth="<?= (int) ($m['link_scan_max_depth'] ?? config('DEFAULT_LINK_SCAN_MAX_DEPTH', 3)); ?>"
                                <?= $monitorId === (int) $m['id'] ? 'selected' : ''; ?>
                            >
                                <?= e((string) $m['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Durum</label>
                    <select name="status" id="status">
                        <option value="all" <?= $status === 'all' ? 'selected' : ''; ?>>All</option>
                        <option value="running" <?= $status === 'running' ? 'selected' : ''; ?>>Running</option>
                        <option value="completed" <?= $status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="failed" <?= $status === 'failed' ? 'selected' : ''; ?>>Failed</option>
                    </select>
                </div>
                <div>
                    <label>Gün</label>
                    <select name="days" id="days">
                        <option value="0" <?= $daysFilter === 0 ? 'selected' : ''; ?>>Tümü</option>
                        <option value="1" <?= $daysFilter === 1 ? 'selected' : ''; ?>>Son 1</option>
                        <option value="7" <?= $daysFilter === 7 ? 'selected' : ''; ?>>Son 7</option>
                        <option value="30" <?= $daysFilter === 30 ? 'selected' : ''; ?>>Son 30</option>
                        <option value="90" <?= $daysFilter === 90 ? 'selected' : ''; ?>>Son 90</option>
                    </select>
                </div>
                <div>
                    <label>Kayıt</label>
                    <select name="per_page" id="per_page">
                        <option value="25" <?= $perPage === 25 ? 'selected' : ''; ?>>25</option>
                        <option value="50" <?= $perPage === 50 ? 'selected' : ''; ?>>50</option>
                        <option value="100" <?= $perPage === 100 ? 'selected' : ''; ?>>100</option>
                        <option value="300" <?= $perPage === 300 ? 'selected' : ''; ?>>300</option>
                    </select>
                </div>
                <div>
                    <label>Depth</label>
                    <input id="manual_depth" type="number" min="1" max="10" value="<?= (int) $manualDepth; ?>">
                </div>
                <div><button class="btn" type="submit">Filtrele</button></div>
                <div><button class="btn btn-primary" type="button" id="scan-now-btn" <?= $monitorId < 1 ? 'disabled' : ''; ?>>Scan Now</button></div>
                <div><button class="btn" type="button" id="scan-stop-btn" disabled>Stop Scan</button></div>
            </form>
            <div id="run-notice" class="notice tiny"></div>
            <div class="toolbar-row">
                <div class="tiny muted">
                    <?= (int) $totalRows; ?> job bulundu • Sayfa <?= (int) $page; ?>/<?= (int) $totalPages; ?>
                </div>
                <div class="inline-tools">
                    <div>
                        <label>Toplu temizlik</label>
                        <select id="cleanup-retention">
                            <option value="30">30 günden eski tamamlanan/failed</option>
                            <option value="7">7 günden eski tamamlanan/failed</option>
                            <option value="90">90 günden eski tamamlanan/failed</option>
                            <option value="180">180 günden eski tamamlanan/failed</option>
                            <option value="365">365 günden eski tamamlanan/failed</option>
                            <option value="0">Tüm tamamlanan/failed joblar</option>
                        </select>
                    </div>
                    <button class="btn btn-danger" type="button" id="bulk-clean-btn">Temizle</button>
                </div>
            </div>
        </section>

        <section class="panel">
            <h2>Canlı Tarama Durumu</h2>
            <div class="live-grid">
                <div class="live-box" id="live-main-box">
                    <div class="k">Aktif Job</div>
                    <div id="live-job" class="v" style="font-size:1.05rem;">
                        <?php if (is_array($runningJob)): ?>
                            #<?= (int) $runningJob['id']; ?> (<?= e((string) $runningJob['monitor_name']); ?>)
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </div>
                    <div class="live-status-line">
                        <span class="scan-dot" id="live-dot"></span>
                        <span id="live-state">Beklemede</span>
                    </div>
                    <div class="current-focus">
                        <div>
                            <div class="k">Kaynak Sayfa</div>
                            <div class="current-url" id="live-source">-</div>
                        </div>
                        <div>
                            <div class="k">Üzerinde Çalışılan Link</div>
                            <div class="current-url primary" id="live-target">-</div>
                        </div>
                    </div>
                    <div class="progress" id="live-progress-shell"><span id="live-progress"></span></div>
                    <div class="progress-label">
                        <strong id="live-progress-percent">0%</strong>
                        <span class="muted">Canlı tarama ilerlemesi</span>
                    </div>
                    <div class="tiny muted" style="margin-top:6px;">
                        Checked: <span id="live-checked">0</span> • Broken: <span id="live-broken">0</span> • Taranan Sayfa: <span id="live-pages">0</span> • Hedef: <span id="live-estimated">0</span>
                    </div>
                </div>
                <div class="live-box">
                    <div class="k">Son 200 Taranan Link (Canlı)</div>
                    <div class="tiny muted" style="margin:4px 0 8px;">Gösterilen: <span id="live-recent-count">0</span></div>
                    <ol id="live-recent" class="recent-list"></ol>
                </div>
            </div>
        </section>

        <section class="panel">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Monitor</th>
                        <th>Status</th>
                        <th>Started</th>
                        <th>Finished</th>
                        <th>Total</th>
                        <th>Checked</th>
                        <th>Broken</th>
                        <th>Duration</th>
                        <th>Detail</th>
                        <th>Action</th>
                        <th>Error</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr data-job-row="<?= (int) $r['id']; ?>">
                            <td>#<?= (int) $r['id']; ?></td>
                            <td>
                                <a href="<?= e(url_for('/monitor_detail.php', ['id' => (int) $r['monitor_id']])); ?>" style="text-decoration:none;color:inherit;font-weight:600;">
                                    <?= e((string) $r['monitor_name']); ?>
                                </a>
                                <div class="mono muted"><?= e((string) $r['monitor_url']); ?></div>
                            </td>
                            <td><span class="badge <?= e((string) $r['status']); ?>"><?= e(strtoupper((string) $r['status'])); ?></span></td>
                            <td><?= e((string) $r['started_at']); ?></td>
                            <td><?= e((string) ($r['finished_at'] ?? '-')); ?></td>
                            <td><?= (int) $r['total_urls']; ?></td>
                            <td><?= (int) $r['checked_urls']; ?></td>
                            <td><?= (int) $r['broken_urls']; ?></td>
                            <td><?= $r['duration_seconds'] !== null ? (int) $r['duration_seconds'] . 's' : '-'; ?></td>
                            <td>
                                <a href="<?= e(url_for('/link_scans.php', ['monitor_id' => $monitorId, 'status' => $status, 'days' => $daysFilter, 'per_page' => $perPage, 'page' => $page, 'job_id' => (int) $r['id']])); ?>">Aç</a>
                            </td>
                            <td>
                                <button
                                    class="btn btn-danger btn-small js-delete-job"
                                    type="button"
                                    data-job-id="<?= (int) $r['id']; ?>"
                                    data-job-label="#<?= (int) $r['id']; ?> <?= e((string) $r['monitor_name']); ?>"
                                >Sil</button>
                            </td>
                            <td class="muted tiny"><?= e((string) ($r['error_message'] ?? '-')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($rows === []): ?>
                        <tr><td colspan="12">Kayıt bulunamadı.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <div class="toolbar-row">
                <div class="tiny muted">
                    <?= (int) $offset + ($rows === [] ? 0 : 1); ?>-<?= (int) min($offset + count($rows), $totalRows); ?> / <?= (int) $totalRows; ?>
                </div>
                <div class="pagination">
                    <?php
                    $basePageParams = [
                        'monitor_id' => $monitorId,
                        'status' => $status,
                        'days' => $daysFilter,
                        'per_page' => $perPage,
                    ];
                    ?>
                    <a class="btn" aria-disabled="<?= $page <= 1 ? 'true' : 'false'; ?>" href="<?= e(url_for('/link_scans.php', $basePageParams + ['page' => max(1, $page - 1)])); ?>">Önceki</a>
                    <span class="tiny muted">Sayfa <?= (int) $page; ?> / <?= (int) $totalPages; ?></span>
                    <a class="btn" aria-disabled="<?= $page >= $totalPages ? 'true' : 'false'; ?>" href="<?= e(url_for('/link_scans.php', $basePageParams + ['page' => min($totalPages, $page + 1)])); ?>">Sonraki</a>
                </div>
            </div>
        </section>

        <?php if ($selectedJob !== null): ?>
            <section class="panel">
                <h2>Job #<?= (int) $selectedJob['id']; ?> Detayı (<?= e((string) $selectedJob['monitor_name']); ?>)</h2>
                <p class="muted tiny">
                    Başlangıç: <?= e((string) $selectedJob['started_at']); ?> •
                    Bitiş: <?= e((string) ($selectedJob['finished_at'] ?? '-')); ?> •
                    Broken: <?= (int) $selectedJob['broken_urls']; ?>
                </p>
                <div class="quality-grid">
                    <div class="quality-box">
                        <h3>Top Broken Targets</h3>
                        <ul class="quality-list">
                            <?php foreach ((array) ($selectedQuality['top_targets'] ?? []) as $item): ?>
                                <li><span class="mono"><?= e((string) ($item['target_url'] ?? '-')); ?></span><strong><?= (int) ($item['hit_count'] ?? 0); ?></strong></li>
                            <?php endforeach; ?>
                            <?php if ((array) ($selectedQuality['top_targets'] ?? []) === []): ?>
                                <li><span class="muted">Kayıt yok</span><strong>0</strong></li>
                            <?php endif; ?>
                        </ul>
                    </div>
                    <div class="quality-box">
                        <h3>Problemli Kaynak Sayfalar</h3>
                        <ul class="quality-list">
                            <?php foreach ((array) ($selectedQuality['top_sources'] ?? []) as $item): ?>
                                <li><span class="mono"><?= e((string) ($item['source_url'] ?? '-')); ?></span><strong><?= (int) ($item['hit_count'] ?? 0); ?></strong></li>
                            <?php endforeach; ?>
                            <?php if ((array) ($selectedQuality['top_sources'] ?? []) === []): ?>
                                <li><span class="muted">Kayıt yok</span><strong>0</strong></li>
                            <?php endif; ?>
                        </ul>
                    </div>
                    <div class="quality-box">
                        <h3>Status Codes</h3>
                        <ul class="quality-list">
                            <?php foreach ((array) ($selectedQuality['status_codes'] ?? []) as $item): ?>
                                <li><span><?= (int) ($item['status_code'] ?? 0) ?: 'No code'; ?></span><strong><?= (int) ($item['hit_count'] ?? 0); ?></strong></li>
                            <?php endforeach; ?>
                            <?php if ((array) ($selectedQuality['status_codes'] ?? []) === []): ?>
                                <li><span class="muted">Kayıt yok</span><strong>0</strong></li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Target URL</th>
                            <th>Source URL</th>
                            <th>Code</th>
                            <th>Occur.</th>
                            <th>Detected</th>
                            <th>Status</th>
                            <th>Error</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($selectedBrokenTargets as $b): ?>
                            <?php $isResolved = ($b['resolved_at'] ?? null) !== null; ?>
                            <tr>
                                <td class="mono"><?= e((string) $b['target_url']); ?></td>
                                <td class="mono"><?= e((string) $b['source_url']); ?></td>
                                <td><?= $b['status_code'] !== null ? (int) $b['status_code'] : '-'; ?></td>
                                <td><?= (int) $b['occurrence_count']; ?></td>
                                <td><?= e((string) $b['last_detected_at']); ?></td>
                                <td><span class="badge <?= $isResolved ? 'completed' : 'failed'; ?>"><?= $isResolved ? 'RESOLVED' : 'ACTIVE'; ?></span></td>
                                <td class="muted"><?= e((string) ($b['error_message'] ?? '-')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($selectedBrokenTargets === []): ?>
                            <tr><td colspan="7">Bu job aralığında broken hedef kaydı bulunamadı.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </section>
        <?php endif; ?>
    </div>

    <script>
        (function () {
            var THEME_KEY = 'ui-theme';
            var root = document.documentElement;
            var toggle = document.getElementById('theme-toggle');
            var saved = localStorage.getItem(THEME_KEY);
            var prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
            var theme = saved || (prefersDark ? 'dark' : 'light');
            root.setAttribute('data-theme', theme);
            toggle.textContent = theme === 'dark' ? 'Light' : 'Dark';
            toggle.addEventListener('click', function () {
                theme = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
                root.setAttribute('data-theme', theme);
                localStorage.setItem(THEME_KEY, theme);
                toggle.textContent = theme === 'dark' ? 'Light' : 'Dark';
            });
        })();

        (function () {
            var scanBtn = document.getElementById('scan-now-btn');
            var monitorSelect = document.getElementById('monitor_id');
            var depthInput = document.getElementById('manual_depth');
            var notice = document.getElementById('run-notice');
            var statusUrlBase = <?= json_encode(url_for('/link_scan_status.php')); ?>;
            var runUrl = <?= json_encode(url_for('/link_scan_run.php')); ?>;
            var cancelUrl = <?= json_encode(url_for('/link_scan_cancel.php')); ?>;
            var deleteUrl = <?= json_encode(url_for('/link_scan_delete.php')); ?>;
            var deleteFallbackUrl = <?= json_encode(url_for('/public/link_scan_delete.php')); ?>;
            var bulkDeleteUrl = <?= json_encode(url_for('/link_scan_bulk_delete.php')); ?>;
            var bulkDeleteFallbackUrl = <?= json_encode(url_for('/public/link_scan_bulk_delete.php')); ?>;
            var stopBtn = document.getElementById('scan-stop-btn');
            var bulkCleanBtn = document.getElementById('bulk-clean-btn');
            var cleanupRetention = document.getElementById('cleanup-retention');
            var runningJobId = 0;
            var hadRunningJob = false;

            function setLiveRunning(isRunning) {
                var box = document.getElementById('live-main-box');
                var progressShell = document.getElementById('live-progress-shell');
                var dot = document.getElementById('live-dot');
                if (box) {
                    box.classList.toggle('is-running', isRunning);
                }
                if (progressShell) {
                    progressShell.classList.toggle('is-running', isRunning);
                }
                if (dot) {
                    dot.classList.toggle('is-running', isRunning);
                }
            }

            function selectedMonitorId() {
                return parseInt(monitorSelect && monitorSelect.value ? monitorSelect.value : '0', 10);
            }

            function refreshScanButtonState() {
                if (!scanBtn) { return; }
                scanBtn.disabled = !(selectedMonitorId() > 0) || runningJobId > 0;
            }

            function setNotice(type, text) {
                if (!notice) { return; }
                notice.className = 'notice tiny ' + (type === 'ok' ? 'ok' : 'err');
                notice.style.display = 'block';
                notice.textContent = text;
            }

            function escapeHtml(s) {
                return String(s).replace(/[&<>"']/g, function (m) {
                    return ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'})[m];
                });
            }

            function postJson(urls, body) {
                var index = 0;

                function tryNext(lastMessage) {
                    if (index >= urls.length) {
                        throw new Error(lastMessage || 'Sunucu JSON yanıtı döndürmedi.');
                    }

                    var url = urls[index++];
                    return fetch(url, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: body.toString()
                    })
                    .then(function (r) {
                        return r.text().then(function (text) {
                            var data = null;
                            try {
                                data = text ? JSON.parse(text) : null;
                            } catch (e) {
                                if (index < urls.length) {
                                    return tryNext('Endpoint JSON yerine farklı yanıt döndürdü.');
                                }
                                throw new Error('Endpoint JSON yerine farklı yanıt döndürdü. HTTP ' + r.status);
                            }

                            if (!r.ok) {
                                throw new Error((data && data.message) ? data.message : ('HTTP ' + r.status));
                            }

                            return data;
                        });
                    })
                    .catch(function (err) {
                        if (index < urls.length) {
                            return tryNext(err && err.message ? err.message : 'İstek başarısız.');
                        }
                        throw err;
                    });
                }

                return tryNext('');
            }

            function fetchStatusForMonitor(monitorId) {
                var mid = parseInt(String(monitorId || '0'), 10);
                var url = statusUrlBase + '?monitor_id=' + encodeURIComponent(mid > 0 ? String(mid) : '0');

                return fetch(url, { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (!data || data.ok !== true) {
                            return data || null;
                        }

                        document.getElementById('kpi-running').textContent = String((data.counts && data.counts.running_count) || 0);
                        document.getElementById('kpi-completed').textContent = String((data.counts && data.counts.completed_count) || 0);
                        document.getElementById('kpi-failed').textContent = String((data.counts && data.counts.failed_count) || 0);

                        var running = data.running || null;
                        var live = data.live || null;
                        runningJobId = running && running.id ? parseInt(running.id, 10) : 0;
                        if (stopBtn) {
                            stopBtn.disabled = !(runningJobId > 0);
                        }
                        refreshScanButtonState();

                        if (!running) {
                            if (hadRunningJob) {
                                hadRunningJob = false;
                                setNotice('ok', 'Scan tamamlandı. Liste güncelleniyor...');
                                window.setTimeout(function () { window.location.reload(); }, 1200);
                            }
                            document.getElementById('live-job').textContent = '-';
                            document.getElementById('live-source').textContent = '-';
                            document.getElementById('live-target').textContent = '-';
                            document.getElementById('live-state').textContent = 'Beklemede';
                            document.getElementById('live-checked').textContent = '0';
                            document.getElementById('live-broken').textContent = '0';
                            document.getElementById('live-pages').textContent = '0';
                            document.getElementById('live-estimated').textContent = '0';
                            document.getElementById('live-progress').style.width = '0%';
                            document.getElementById('live-progress-percent').textContent = '0%';
                            document.getElementById('live-recent').innerHTML = '';
                            document.getElementById('live-recent-count').textContent = '0';
                            setLiveRunning(false);
                            return data;
                        }

                        hadRunningJob = true;
                        setLiveRunning(true);
                        var monitorName = running.monitor_name || ('#' + running.monitor_id);
                        document.getElementById('live-job').textContent = '#' + running.id + ' (' + monitorName + ')';

                        var checked = 0;
                        var broken = 0;
                        var estimate = 0;
                        var pages = 0;
                        var source = '-';
                        var target = '-';
                        var state = running.status || 'running';
                        var recent = [];

                        if (live) {
                            checked = parseInt(live.checked_urls || 0, 10);
                            broken = parseInt(live.broken_urls || 0, 10);
                            estimate = parseInt(live.estimated_checks || live.total_urls || 0, 10);
                            pages = parseInt(live.pages_crawled || live.total_urls || 0, 10);
                            source = live.current_source_url || '-';
                            target = live.current_target_url || '-';
                            state = live.current_status || state;
                            recent = Array.isArray(live.recent) ? live.recent : [];
                        } else {
                            checked = parseInt(running.checked_urls || 0, 10);
                            broken = parseInt(running.broken_urls || 0, 10);
                            estimate = checked;
                            pages = parseInt(running.total_urls || 0, 10);
                        }

                        document.getElementById('live-source').textContent = source;
                        document.getElementById('live-target').textContent = target;
                        document.getElementById('live-state').textContent = String(state).toUpperCase();
                        document.getElementById('live-checked').textContent = String(checked);
                        document.getElementById('live-broken').textContent = String(broken);
                        document.getElementById('live-pages').textContent = String(pages);
                        document.getElementById('live-estimated').textContent = String(estimate);

                        var pct = 0;
                        if (estimate > 0) {
                            pct = Math.min(100, Math.round((checked / estimate) * 100));
                        }
                        document.getElementById('live-progress').style.width = pct + '%';
                        document.getElementById('live-progress-percent').textContent = pct + '%';

                        var html = '';
                        for (var i = recent.length - 1; i >= 0; i--) {
                            var r = recent[i] || {};
                            var st = r.status === 'broken' ? 'BROKEN' : 'OK';
                            html += '<li><strong>' + st + '</strong> • ' + escapeHtml(r.target_url || '-') + '</li>';
                        }
                        document.getElementById('live-recent').innerHTML = html;
                        document.getElementById('live-recent-count').textContent = String(recent.length);
                        return data;
                    })
                    .catch(function () { return null; });
            }

            function pollStatus() {
                return fetchStatusForMonitor(selectedMonitorId());
            }

            function confirmRunningAfterStart(monitorId) {
                return fetchStatusForMonitor(monitorId).then(function (data) {
                    var running = data && data.running ? data.running : null;
                    if (running && parseInt(running.monitor_id || '0', 10) === parseInt(String(monitorId), 10)) {
                        setNotice('ok', 'Scan başlatıldı ve arka planda devam ediyor. Canlı durum panelinden takip edebilirsiniz.');
                        return true;
                    }
                    return false;
                });
            }

            if (monitorSelect) {
                monitorSelect.addEventListener('change', function () {
                    var selected = parseInt(monitorSelect.value || '0', 10);
                    refreshScanButtonState();
                    if (depthInput && monitorSelect.selectedIndex >= 0) {
                        var selectedOption = monitorSelect.options[monitorSelect.selectedIndex];
                        var depth = parseInt(selectedOption.getAttribute('data-depth') || depthInput.value || '3', 10);
                        depthInput.value = String(Math.max(1, depth));
                    }
                });
            }

            if (scanBtn) {
                scanBtn.addEventListener('click', function () {
                    var monitorId = parseInt(monitorSelect.value || '0', 10);
                    if (!(monitorId > 0)) {
                        setNotice('err', 'Önce bir monitör seçin.');
                        return;
                    }

                    scanBtn.disabled = true;
                    setNotice('ok', 'Manuel link scan başlatılıyor...');
                    var body = new URLSearchParams();
                    body.append('monitor_id', String(monitorId));
                    if (depthInput) {
                        body.append('max_depth', String(Math.max(1, parseInt(depthInput.value || '3', 10))));
                    }

                    fetch(runUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: body.toString()
                    })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data && data.ok) {
                            setNotice('ok', data.message || 'Scan tamamlandı.');
                            pollStatus();
                        } else {
                            setNotice('err', (data && data.message) ? data.message : 'Scan başlatılamadı.');
                        }
                    })
                    .catch(function () {
                        return confirmRunningAfterStart(monitorId).then(function (isRunning) {
                            if (!isRunning) {
                                setNotice('err', 'İstek sırasında hata oluştu. Canlı durum panelinde çalışan job görünmüyorsa tekrar deneyin.');
                            }
                        });
                    })
                    .finally(function () {
                        refreshScanButtonState();
                    });
                });
            }

            if (stopBtn) {
                stopBtn.addEventListener('click', function () {
                    var monitorId = parseInt(monitorSelect.value || '0', 10);
                    var body = new URLSearchParams();
                    if (runningJobId > 0) {
                        body.append('job_id', String(runningJobId));
                    }
                    if (monitorId > 0) {
                        body.append('monitor_id', String(monitorId));
                    }

                    stopBtn.disabled = true;
                    setNotice('ok', 'Scan durdurma isteği gönderiliyor...');

                    fetch(cancelUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: body.toString()
                    })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data && data.ok) {
                            setNotice('ok', data.message || 'Scan durdurma isteği alındı.');
                        } else {
                            setNotice('err', (data && data.message) ? data.message : 'Scan durdurulamadı.');
                            stopBtn.disabled = false;
                        }
                    })
                    .catch(function () {
                        setNotice('err', 'Durdurma isteği sırasında hata oluştu.');
                        stopBtn.disabled = false;
                    });
                });
            }

            document.querySelectorAll('.js-delete-job').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var jobId = parseInt(btn.getAttribute('data-job-id') || '0', 10);
                    var label = btn.getAttribute('data-job-label') || ('#' + jobId);
                    if (!(jobId > 0)) {
                        return;
                    }

                    if (!window.confirm(label + ' job kaydı silinsin mi?')) {
                        return;
                    }

                    btn.disabled = true;
                    setNotice('ok', 'Job siliniyor...');

                    var body = new URLSearchParams();
                    body.append('job_id', String(jobId));

                    postJson([deleteUrl, deleteFallbackUrl], body)
                    .then(function (data) {
                        if (data && data.ok) {
                            setNotice('ok', data.message || 'Job silindi.');
                            window.location.reload();
                        } else {
                            setNotice('err', (data && data.message) ? data.message : 'Job silinemedi.');
                            btn.disabled = false;
                        }
                    })
                    .catch(function (err) {
                        var msg = err && err.message ? err.message : 'Job silme isteği sırasında hata oluştu.';
                        setNotice('err', msg);
                        btn.disabled = false;
                    });
                });
            });

            if (bulkCleanBtn && cleanupRetention) {
                bulkCleanBtn.addEventListener('click', function () {
                    var days = parseInt(cleanupRetention.value || '30', 10);
                    var label = days > 0 ? days + ' günden eski tamamlanan/failed joblar' : 'tüm tamamlanan/failed joblar';
                    if (!window.confirm(label + ' silinsin mi?')) {
                        return;
                    }

                    bulkCleanBtn.disabled = true;
                    setNotice('ok', 'Job kayıtları temizleniyor...');

                    var body = new URLSearchParams();
                    body.append('retention_days', String(days));

                    postJson([bulkDeleteUrl, bulkDeleteFallbackUrl], body)
                    .then(function (data) {
                        if (data && data.ok) {
                            setNotice('ok', data.message || 'Job kayıtları temizlendi.');
                            window.location.reload();
                        } else {
                            setNotice('err', (data && data.message) ? data.message : 'Job kayıtları temizlenemedi.');
                            bulkCleanBtn.disabled = false;
                        }
                    })
                    .catch(function (err) {
                        var msg = err && err.message ? err.message : 'Toplu temizlik isteği sırasında hata oluştu.';
                        setNotice('err', msg);
                        bulkCleanBtn.disabled = false;
                    });
                });
            }

            pollStatus();
            setInterval(pollStatus, 2000);
        })();
    </script>
</body>
</html>
