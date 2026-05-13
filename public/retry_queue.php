<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_auth();

$pdo = Database::connection();
$repo = new NotificationRetryRepository($pdo);
$notifier = new Notifier($pdo);
$user = current_user();
$brandTitle = (string) config('app.brand.title', config('APP_NAME', 'Uptime Monitor'));

$status = isset($_GET['status']) ? (string) $_GET['status'] : 'all';
if (!in_array($status, ['all', 'pending', 'retrying', 'failed', 'sent'], true)) {
    $status = 'all';
}
$channel = isset($_GET['channel']) ? (string) $_GET['channel'] : 'all';
if (!in_array($channel, ['all', 'email', 'telegram'], true)) {
    $channel = 'all';
}

$flash = null;
$loadError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? (string) $_POST['action'] : '';
    $selectedIds = isset($_POST['queue_ids']) && is_array($_POST['queue_ids']) ? $_POST['queue_ids'] : [];

    if ($action === 'retry_now') {
        $queueId = isset($_POST['queue_id']) ? (int) $_POST['queue_id'] : 0;
        if ($queueId > 0) {
            $prepared = $repo->markRetryNow($queueId);
            if ($prepared) {
                $ok = $notifier->processRetryQueueById($queueId);
                $flash = $ok ? 'Retry başarılı.' : 'Retry denendi, başarısızsa kuyrukta kalır.';
            } else {
                $flash = 'Retry için kayıt bulunamadı veya uygun değil.';
            }
        }
    }

    if ($action === 'retry_selected') {
        $ids = [];
        foreach ($selectedIds as $idRaw) {
            $id = (int) $idRaw;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        $ids = array_values(array_unique($ids));

        if ($ids === []) {
            $flash = 'Toplu retry için en az bir kayıt seçin.';
        } else {
            $okCount = 0;
            $processedCount = 0;
            foreach ($ids as $id) {
                if ($repo->markRetryNow($id)) {
                    $processedCount++;
                    if ($notifier->processRetryQueueById($id)) {
                        $okCount++;
                    }
                }
            }
            $flash = "Toplu retry tamamlandı. Hazırlanan: {$processedCount}, başarılı: {$okCount}.";
        }
    }

    if ($action === 'retry_listed_eligible') {
        try {
            $currentRows = $repo->list([
                'status' => $status,
                'channel' => $channel,
            ], 300);
            $eligibleIds = [];
            foreach ($currentRows as $row) {
                $st = (string) ($row['status'] ?? '');
                if (in_array($st, ['pending', 'retrying', 'failed'], true)) {
                    $eligibleIds[] = (int) $row['id'];
                }
            }
            $eligibleIds = array_values(array_unique($eligibleIds));

            $okCount = 0;
            $processedCount = 0;
            foreach ($eligibleIds as $id) {
                if ($repo->markRetryNow($id)) {
                    $processedCount++;
                    if ($notifier->processRetryQueueById($id)) {
                        $okCount++;
                    }
                }
            }
            $flash = "Listelenen uygun kayıtlar için retry tamamlandı. Hazırlanan: {$processedCount}, başarılı: {$okCount}.";
        } catch (Exception $e) {
            $flash = 'Toplu retry sırasında hata oluştu.';
        }
    }
}

$rows = [];
$counts = [
    'pending_count' => 0,
    'retrying_count' => 0,
    'failed_count' => 0,
    'sent_count' => 0,
];
try {
    $rows = $repo->list([
        'status' => $status,
        'channel' => $channel,
    ], 300);
    $counts = $repo->counts();
} catch (Exception $e) {
    $loadError = $e->getMessage();
}
?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notification Retry Queue • <?= e($brandTitle); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --accent:#0ea5e9;
            --danger:#ef4444;
            --success:#10b981;
            --warning:#f59e0b;
            --text:#0f172a;
            --muted:#475569;
            --card:rgba(255,255,255,0.84);
            --line:rgba(148,163,184,0.35);
            --bg1:#f8fafc;
            --bg2:#ecfeff;
        }
        html[data-theme="dark"] {
            --text:#e2e8f0;
            --muted:#94a3b8;
            --card:rgba(15,23,42,0.84);
            --line:rgba(71,85,105,0.5);
            --bg1:#0b1220;
            --bg2:#111827;
        }
        * { box-sizing:border-box; }
        body {
            margin:0;
            min-height:100vh;
            color:var(--text);
            font-family:"IBM Plex Sans","Segoe UI",sans-serif;
            background:
                radial-gradient(circle at 8% 10%, rgba(14,165,233,0.16), transparent 32%),
                radial-gradient(circle at 92% 18%, rgba(16,185,129,0.14), transparent 30%),
                linear-gradient(160deg, var(--bg1), var(--bg2));
        }
        .shell { width:min(1200px,94vw); margin:24px auto 36px; }
        .topbar {
            background:var(--card);
            border:1px solid var(--line);
            border-radius:22px;
            padding:18px 20px;
            display:flex;
            gap:16px;
            justify-content:space-between;
            align-items:center;
            flex-wrap:wrap;
            backdrop-filter:blur(8px);
            box-shadow:0 14px 40px rgba(15,23,42,0.06);
        }
        .title {
            margin:0;
            font-family:"Space Grotesk",sans-serif;
            font-size:clamp(1.15rem,2.7vw,1.85rem);
        }
        .subtitle { margin:4px 0 0; color:var(--muted); font-size:0.93rem; }
        .actions { display:flex; gap:10px; flex-wrap:wrap; }
        .btn, .btn-inline {
            text-decoration:none;
            border-radius:12px;
            font-weight:700;
            border:1px solid var(--line);
            cursor:pointer;
            color:var(--text);
            background:#ffffffc9;
            font:inherit;
        }
        .btn { padding:10px 14px; font-size:0.92rem; }
        .btn-inline { padding:8px 11px; font-size:0.84rem; }
        .btn-primary, .btn-inline {
            color:#fff;
            border-color:transparent;
            background:linear-gradient(135deg,var(--accent),#0284c7);
            box-shadow:0 8px 20px rgba(14,165,233,0.22);
        }
        html[data-theme="dark"] .btn {
            color:#e2e8f0;
            border-color:rgba(148,163,184,0.34);
            background:rgba(30,41,59,0.92);
        }
        html[data-theme="dark"] .btn-primary,
        html[data-theme="dark"] .btn-inline {
            color:#fff;
            border-color:transparent;
            background:linear-gradient(135deg,#0ea5e9,#0284c7);
        }
        .grid { margin-top:14px; display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px; }
        .card, .panel {
            background:var(--card);
            border:1px solid var(--line);
            border-radius:16px;
            padding:14px;
            box-shadow:0 10px 26px rgba(15,23,42,0.05);
        }
        .panel { margin-top:12px; overflow:hidden; }
        .k { color:var(--muted); font-size:0.82rem; }
        .v { margin-top:6px; font-size:1.5rem; font-weight:700; font-family:"Space Grotesk",sans-serif; }
        .v-warning { color:var(--warning); }
        .v-accent { color:var(--accent); }
        .v-danger { color:var(--danger); }
        .v-success { color:var(--success); }
        .filters { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:10px; align-items:end; }
        label { display:block; margin:0 0 6px; color:var(--muted); font-size:0.82rem; font-weight:700; }
        select {
            width:100%;
            padding:10px 11px;
            border:1px solid var(--line);
            border-radius:11px;
            background:rgba(255,255,255,0.9);
            color:var(--text);
            font:inherit;
        }
        html[data-theme="dark"] select {
            background:rgba(15,23,42,0.75);
            color:#e2e8f0;
        }
        .flash {
            padding:10px 12px;
            border-radius:12px;
            background:rgba(236,254,255,0.82);
            border:1px solid #bae6fd;
            color:#0c4a6e;
            margin-top:12px;
        }
        html[data-theme="dark"] .flash { color:#cffafe; background:rgba(8,47,73,0.62); border-color:rgba(56,189,248,0.34); }
        table { width:100%; border-collapse:collapse; font-size:0.9rem; }
        th, td { border-bottom:1px solid var(--line); padding:11px 12px; text-align:left; vertical-align:top; }
        th { font-size:0.76rem; text-transform:uppercase; color:#334155; letter-spacing:0.45px; background:rgba(148,163,184,0.08); }
        html[data-theme="dark"] th { color:#cbd5e1; background:rgba(148,163,184,0.12); }
        tbody tr:hover { background:rgba(14,165,233,0.07); }
        .badge { display:inline-flex; padding:4px 9px; border-radius:999px; font-size:0.75rem; font-weight:800; letter-spacing:0.25px; }
        .pending { color:#78350f; background:rgba(245,158,11,0.16); border:1px solid rgba(245,158,11,0.28); }
        .retrying { color:#1d4ed8; background:rgba(59,130,246,0.18); border:1px solid rgba(59,130,246,0.28); }
        .failed { color:#7f1d1d; background:rgba(239,68,68,0.16); border:1px solid rgba(239,68,68,0.28); }
        .sent { color:#065f46; background:rgba(16,185,129,0.18); border:1px solid rgba(16,185,129,0.28); }
        html[data-theme="dark"] .pending { color:#fde68a; background:rgba(120,53,15,0.68); }
        html[data-theme="dark"] .retrying { color:#bfdbfe; background:rgba(30,64,175,0.55); }
        html[data-theme="dark"] .failed { color:#fecaca; background:rgba(127,29,29,0.68); }
        html[data-theme="dark"] .sent { color:#bbf7d0; background:rgba(6,95,70,0.55); }
        .mono { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:0.78rem; word-break:break-all; }
        .muted { color:var(--muted); }
        .bulk-actions { margin:0 0 10px 0; display:flex; gap:8px; flex-wrap:wrap; }
        .alert {
            margin-bottom:10px;
            padding:10px;
            border:1px solid rgba(239,68,68,0.32);
            background:rgba(254,226,226,0.72);
            color:#7f1d1d;
            border-radius:12px;
        }
        html[data-theme="dark"] .alert { color:#fecaca; background:rgba(127,29,29,0.5); }
        @media (max-width: 1050px) { .grid { grid-template-columns:repeat(2,minmax(0,1fr)); } .filters { grid-template-columns:1fr; } }
        @media (max-width: 740px) { .grid { grid-template-columns:1fr; } th:nth-child(7),td:nth-child(7) { display:none; } }
    </style>
</head>
<body>
    <div class="shell">
        <header class="topbar">
            <div>
                <h1 class="title">Notification Retry Queue</h1>
                <p class="subtitle">Başarısız bildirimleri izleyin ve yeniden deneyin</p>
            </div>
            <div class="actions">
                <span class="btn"><?= e((string) ($user['email'] ?? '-')); ?></span>
                <a class="btn" href="<?= e(url_for('/index.php')); ?>">Dashboard</a>
                <a class="btn" href="<?= e(url_for('/link_scans.php')); ?>">Link Scans</a>
                <a class="btn" href="<?= e(url_for('/broken_links.php')); ?>">Broken Links</a>
                <a class="btn" href="<?= e(url_for('/notifications.php')); ?>">Notifications</a>
                <a class="btn" href="<?= e(url_for('/health.php')); ?>">Health</a>
                <button class="btn" id="theme-toggle" type="button">Tema</button>
                <a class="btn" href="<?= e(url_for('/logout.php')); ?>">Çıkış</a>
            </div>
        </header>

        <?php if ($flash !== null): ?>
            <div class="flash"><?= e($flash); ?></div>
        <?php endif; ?>

        <section class="grid">
            <article class="card"><div class="k">Pending</div><div class="v v-warning"><?= (int) $counts['pending_count']; ?></div></article>
            <article class="card"><div class="k">Retrying</div><div class="v v-accent"><?= (int) $counts['retrying_count']; ?></div></article>
            <article class="card"><div class="k">Failed</div><div class="v v-danger"><?= (int) $counts['failed_count']; ?></div></article>
            <article class="card"><div class="k">Sent</div><div class="v v-success"><?= (int) $counts['sent_count']; ?></div></article>
        </section>

        <section class="panel">
            <?php if ($loadError !== null): ?>
                <div class="alert">
                    Retry queue tablosu hazır değil. `php database/migrate_sqlite.php` çalıştırın.
                </div>
            <?php endif; ?>
            <form method="get" class="filters">
                <div>
                    <label>Status</label>
                    <select name="status">
                        <option value="all" <?= $status === 'all' ? 'selected' : ''; ?>>All</option>
                        <option value="pending" <?= $status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="retrying" <?= $status === 'retrying' ? 'selected' : ''; ?>>Retrying</option>
                        <option value="failed" <?= $status === 'failed' ? 'selected' : ''; ?>>Failed</option>
                        <option value="sent" <?= $status === 'sent' ? 'selected' : ''; ?>>Sent</option>
                    </select>
                </div>
                <div>
                    <label>Channel</label>
                    <select name="channel">
                        <option value="all" <?= $channel === 'all' ? 'selected' : ''; ?>>All</option>
                        <option value="email" <?= $channel === 'email' ? 'selected' : ''; ?>>Email</option>
                        <option value="telegram" <?= $channel === 'telegram' ? 'selected' : ''; ?>>Telegram</option>
                    </select>
                </div>
                <div>
                    <button class="btn btn-primary" type="submit">Filtrele</button>
                </div>
            </form>
        </section>

        <section class="panel">
            <div class="bulk-actions">
                <button type="submit" form="bulk-retry-form" class="btn-inline">Seçilileri Retry Et</button>
            </div>
            <form class="bulk-actions" method="post" action="<?= e(url_for('/retry_queue.php', ['status' => $status, 'channel' => $channel])); ?>">
                <input type="hidden" name="action" value="retry_listed_eligible">
                <button type="submit" class="btn-inline">Listelenen Uygunları Retry Et</button>
            </form>
            <table>
                <thead>
                    <tr>
                        <th>Seç</th>
                        <th>ID</th>
                        <th>Channel</th>
                        <th>Status</th>
                        <th>Attempts</th>
                        <th>Next Attempt</th>
                        <th>Last Error</th>
                        <th>Payload</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                        <?php $st = (string) $r['status']; ?>
                        <tr>
                            <td>
                                <?php if (in_array($st, ['pending', 'retrying', 'failed'], true)): ?>
                                    <input type="checkbox" form="bulk-retry-form" name="queue_ids[]" value="<?= (int) $r['id']; ?>">
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td>#<?= (int) $r['id']; ?></td>
                            <td><?= e(strtoupper((string) $r['channel'])); ?></td>
                            <td><span class="badge <?= e($st); ?>"><?= e(strtoupper($st)); ?></span></td>
                            <td><?= (int) $r['attempt_count']; ?>/<?= (int) $r['max_attempts']; ?></td>
                            <td><?= e((string) $r['next_attempt_at']); ?></td>
                            <td class="muted"><?= e((string) ($r['last_error'] ?? '-')); ?></td>
                            <td class="mono"><?= e((string) $r['payload_json']); ?></td>
                            <td>
                                <?php if (in_array($st, ['pending', 'retrying', 'failed'], true)): ?>
                                    <form method="post" style="margin:0;">
                                        <input type="hidden" name="action" value="retry_now">
                                        <input type="hidden" name="queue_id" value="<?= (int) $r['id']; ?>">
                                        <button class="btn-inline" type="submit">Retry now</button>
                                    </form>
                                <?php else: ?>
                                    <span class="muted">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($rows === []): ?>
                        <tr><td colspan="9">Kuyruk kaydı bulunamadı.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <form id="bulk-retry-form" method="post" action="<?= e(url_for('/retry_queue.php', ['status' => $status, 'channel' => $channel])); ?>" style="display:none;">
                <input type="hidden" name="action" value="retry_selected">
            </form>
        </section>
    </div>
    <script>
        (function () {
            var key = 'ui-theme';
            var root = document.documentElement;
            var btn = document.getElementById('theme-toggle');
            if (!btn) { return; }
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
