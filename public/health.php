<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_auth();

function health_check(bool $ok, string $label, string $detail): array
{
    return ['ok' => $ok, 'label' => $label, 'detail' => $detail];
}

function log_age(string $path): string
{
    if (!is_file($path)) {
        return 'missing';
    }
    $mtime = filemtime($path);
    if ($mtime === false) {
        return 'unreadable';
    }
    $minutes = (int) floor((time() - $mtime) / 60);
    return date('Y-m-d H:i:s', $mtime) . ' (' . $minutes . ' min ago)';
}

$pdo = Database::connection();
$user = current_user();
$brandTitle = (string) config('app.brand.title', config('APP_NAME', 'Uptime Monitor'));
$root = dirname(__DIR__);
$checks = [];
$checks[] = health_check(true, 'PHP', PHP_VERSION);
$checks[] = health_check(extension_loaded('pdo_sqlite'), 'pdo_sqlite extension', extension_loaded('pdo_sqlite') ? 'loaded' : 'missing');
$checks[] = health_check(extension_loaded('curl'), 'curl extension', extension_loaded('curl') ? 'loaded' : 'missing');
$checks[] = health_check(extension_loaded('dom'), 'dom extension', extension_loaded('dom') ? 'loaded' : 'missing');
$checks[] = health_check(is_writable($root . '/storage'), 'storage writable', $root . '/storage');
$checks[] = health_check(is_writable($root . '/storage/logs'), 'logs writable', $root . '/storage/logs');
$checks[] = health_check(is_writable($root . '/storage/link_scan_live'), 'live scan dir writable', $root . '/storage/link_scan_live');

try {
    $pdo->query('SELECT 1');
    $checks[] = health_check(true, 'Database connection', (string) config('DB_PATH', $root . '/database/uptime.sqlite'));
} catch (Throwable $e) {
    $checks[] = health_check(false, 'Database connection', $e->getMessage());
}

$jobCounts = ['running' => 0, 'stale' => 0, 'failed' => 0];
try {
    $row = $pdo->query("
        SELECT
            SUM(CASE WHEN status = 'running' THEN 1 ELSE 0 END) AS running,
            SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed,
            SUM(CASE WHEN status = 'running' AND started_at <= datetime('now', '-60 minutes') THEN 1 ELSE 0 END) AS stale
        FROM link_scan_jobs
    ")->fetch();
    $jobCounts = is_array($row) ? $row : $jobCounts;
} catch (Throwable $e) {
}

$retryCounts = ['pending' => 0, 'retrying' => 0, 'failed' => 0];
try {
    $row = $pdo->query("
        SELECT
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN status = 'retrying' THEN 1 ELSE 0 END) AS retrying,
            SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed
        FROM notification_retry_queue
    ")->fetch();
    $retryCounts = is_array($row) ? $row : $retryCounts;
} catch (Throwable $e) {
}

$logs = [
    'cron.log' => log_age($root . '/storage/logs/cron.log'),
    'link_scan.log' => log_age($root . '/storage/logs/link_scan.log'),
    'retry_notifications.log' => log_age($root . '/storage/logs/retry_notifications.log'),
    'daily_report.log' => log_age($root . '/storage/logs/daily_report.log'),
    'weekly_report.log' => log_age($root . '/storage/logs/weekly_report.log'),
];
?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Health • <?= e($brandTitle); ?></title>
    <style>
        :root{--accent:#0ea5e9;--danger:#ef4444;--success:#10b981;--warning:#f59e0b;--text:#0f172a;--muted:#475569;--card:rgba(255,255,255,.86);--line:rgba(148,163,184,.35);--bg1:#f8fafc;--bg2:#ecfeff}html[data-theme="dark"]{--text:#e2e8f0;--muted:#94a3b8;--card:rgba(15,23,42,.84);--line:rgba(71,85,105,.5);--bg1:#0b1220;--bg2:#111827}*{box-sizing:border-box}body{margin:0;min-height:100vh;color:var(--text);font-family:"IBM Plex Sans","Segoe UI",Arial,sans-serif;background:radial-gradient(circle at 8% 10%,rgba(14,165,233,.16),transparent 32%),radial-gradient(circle at 92% 18%,rgba(16,185,129,.14),transparent 30%),linear-gradient(160deg,var(--bg1),var(--bg2))}.shell{width:min(1200px,94vw);margin:24px auto 36px}.topbar,.card,.panel{background:var(--card);border:1px solid var(--line);border-radius:16px;backdrop-filter:blur(8px);box-shadow:0 10px 26px rgba(15,23,42,.05)}.topbar{padding:18px 20px;display:flex;justify-content:space-between;gap:14px;align-items:center;flex-wrap:wrap}.title{margin:0;font-size:1.65rem}.subtitle{margin:5px 0 0;color:var(--muted)}.actions{display:flex;gap:8px;flex-wrap:wrap}.btn{border:1px solid var(--line);border-radius:11px;background:rgba(255,255,255,.76);color:var(--text);padding:9px 12px;text-decoration:none;font-weight:700;cursor:pointer}html[data-theme="dark"] .btn{background:rgba(30,41,59,.92);color:#e2e8f0}.grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-top:14px}.card,.panel{padding:14px}.panel{margin-top:12px;overflow:hidden}.k{color:var(--muted);font-size:.82rem}.v{margin-top:6px;font-size:1.45rem;font-weight:800}.ok{color:var(--success)}.bad{color:var(--danger)}.warn{color:var(--warning)}table{width:100%;border-collapse:collapse;font-size:.9rem}th,td{border-bottom:1px solid var(--line);padding:10px;text-align:left;vertical-align:top}th{font-size:.75rem;text-transform:uppercase;color:var(--muted);background:rgba(148,163,184,.08)}.badge{display:inline-flex;border-radius:999px;padding:4px 9px;font-weight:800;font-size:.75rem}.badge.ok{background:rgba(16,185,129,.16)}.badge.bad{background:rgba(239,68,68,.16)}.mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.8rem;word-break:break-all}.muted{color:var(--muted)}@media(max-width:900px){.grid{grid-template-columns:1fr}}
    </style>
</head>
<body>
<div class="shell">
    <header class="topbar">
        <div><h1 class="title">System Health</h1><p class="subtitle">Runtime, cron, queue, and storage health checks.</p></div>
        <div class="actions">
            <span class="btn"><?= e((string) ($user['email'] ?? '-')); ?></span>
            <a class="btn" href="<?= e(url_for('/index.php')); ?>">Dashboard</a>
            <a class="btn" href="<?= e(url_for('/broken_links.php')); ?>">Broken Links</a>
            <a class="btn" href="<?= e(url_for('/link_scans.php')); ?>">Link Scans</a>
            <a class="btn" href="<?= e(url_for('/reports.php')); ?>">Reports</a>
            <a class="btn" href="<?= e(url_for('/notifications.php')); ?>">Notifications</a>
            <button class="btn" id="theme-toggle" type="button">Tema</button>
            <a class="btn" href="<?= e(url_for('/logout.php')); ?>">Çıkış</a>
        </div>
    </header>
    <section class="grid">
        <article class="card"><div class="k">Running Link Jobs</div><div class="v"><?= (int) ($jobCounts['running'] ?? 0); ?></div></article>
        <article class="card"><div class="k">Stale Link Jobs</div><div class="v <?= (int) ($jobCounts['stale'] ?? 0) > 0 ? 'bad' : 'ok'; ?>"><?= (int) ($jobCounts['stale'] ?? 0); ?></div></article>
        <article class="card"><div class="k">Retry Problems</div><div class="v <?= (int) ($retryCounts['failed'] ?? 0) > 0 ? 'bad' : 'ok'; ?>"><?= (int) ($retryCounts['failed'] ?? 0); ?></div></article>
    </section>
    <section class="panel">
        <h2>Checks</h2>
        <table><thead><tr><th>Status</th><th>Check</th><th>Detail</th></tr></thead><tbody>
        <?php foreach ($checks as $check): ?>
            <tr><td><span class="badge <?= $check['ok'] ? 'ok' : 'bad'; ?>"><?= $check['ok'] ? 'OK' : 'FAIL'; ?></span></td><td><?= e($check['label']); ?></td><td class="mono"><?= e($check['detail']); ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
    </section>
    <section class="panel">
        <h2>Cron Logs</h2>
        <table><thead><tr><th>Log</th><th>Last Write</th></tr></thead><tbody>
        <?php foreach ($logs as $name => $age): ?><tr><td><?= e($name); ?></td><td class="mono"><?= e($age); ?></td></tr><?php endforeach; ?>
        </tbody></table>
    </section>
    <section class="panel">
        <h2>Runtime Summary</h2>
        <table><tbody>
            <tr><td>APP_URL</td><td class="mono"><?= e((string) config('APP_URL', '')); ?></td></tr>
            <tr><td>APP_BASE_PATH</td><td class="mono"><?= e((string) config('APP_BASE_PATH', '')); ?></td></tr>
            <tr><td>Link scan concurrency</td><td><?= (int) config('DEFAULT_LINK_SCAN_CONCURRENCY', 5); ?></td></tr>
            <tr><td>Retry queue pending/retrying</td><td><?= (int) ($retryCounts['pending'] ?? 0); ?> / <?= (int) ($retryCounts['retrying'] ?? 0); ?></td></tr>
        </tbody></table>
    </section>
</div>
<script>
(function(){var key='ui-theme',root=document.documentElement,btn=document.getElementById('theme-toggle');if(!btn){return;}var saved=localStorage.getItem(key),pref=window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches,theme=saved||(pref?'dark':'light');root.setAttribute('data-theme',theme);btn.textContent=theme==='dark'?'Light':'Dark';btn.addEventListener('click',function(){theme=root.getAttribute('data-theme')==='dark'?'light':'dark';root.setAttribute('data-theme',theme);localStorage.setItem(key,theme);btn.textContent=theme==='dark'?'Light':'Dark';});})();
</script>
</body>
</html>
