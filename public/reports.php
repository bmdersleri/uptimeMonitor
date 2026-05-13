<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_auth();

function report_status_class(string $status): string
{
    if ($status === 'sent') {
        return 'sent';
    }
    if ($status === 'failed') {
        return 'failed';
    }
    return 'skipped';
}

$pdo = Database::connection();
$service = new ReportService($pdo);
$user = current_user();
$brandTitle = (string) config('app.brand.title', config('APP_NAME', 'Uptime Monitor'));
$notice = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'send_daily') {
            $result = $service->createAndSend('daily');
            $notice = ['ok' => true, 'message' => 'Günlük rapor oluşturuldu. Rapor #' . (int) $result['id']];
        } elseif ($action === 'send_weekly') {
            $result = $service->createAndSend('weekly');
            $notice = ['ok' => true, 'message' => 'Haftalık rapor oluşturuldu. Rapor #' . (int) $result['id']];
        } elseif ($action === 'resend') {
            $id = (int) ($_POST['id'] ?? 0);
            $result = $service->resend($id);
            $notice = $result === null
                ? ['ok' => false, 'message' => 'Rapor bulunamadı.']
                : ['ok' => true, 'message' => 'Rapor #' . $id . ' yeniden gönderildi.'];
        }
    } catch (Throwable $e) {
        $notice = ['ok' => false, 'message' => $e->getMessage()];
    }
}

$previewDaily = $service->generate('daily');
$previewWeekly = $service->generate('weekly');
$runs = $service->recentRuns(40);
?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports • <?= e($brandTitle); ?></title>
    <style>
        :root { --accent:#0ea5e9; --danger:#ef4444; --success:#10b981; --warning:#f59e0b; --text:#0f172a; --muted:#475569; --card:rgba(255,255,255,0.88); --line:rgba(148,163,184,0.35); --bg1:#f8fafc; --bg2:#ecfeff; }
        html[data-theme="dark"] { --text:#e2e8f0; --muted:#94a3b8; --card:rgba(15,23,42,0.84); --line:rgba(71,85,105,0.5); --bg1:#0b1220; --bg2:#111827; }
        *{box-sizing:border-box} body{margin:0;min-height:100vh;color:var(--text);font-family:"IBM Plex Sans","Segoe UI",Arial,sans-serif;background:radial-gradient(circle at 8% 10%,rgba(14,165,233,.16),transparent 32%),radial-gradient(circle at 92% 18%,rgba(16,185,129,.14),transparent 30%),linear-gradient(160deg,var(--bg1),var(--bg2));}
        .shell{width:min(1220px,95vw);margin:24px auto 36px}.topbar,.card,.panel{background:var(--card);border:1px solid var(--line);border-radius:16px;backdrop-filter:blur(8px);box-shadow:0 10px 26px rgba(15,23,42,.05)}.topbar{padding:18px 20px;display:flex;justify-content:space-between;gap:14px;align-items:center;flex-wrap:wrap}.title{margin:0;font-size:1.65rem}.subtitle{margin:5px 0 0;color:var(--muted)}.actions{display:flex;gap:8px;flex-wrap:wrap}.btn{border:1px solid var(--line);border-radius:11px;background:rgba(255,255,255,.76);color:var(--text);padding:9px 12px;text-decoration:none;font-weight:700;cursor:pointer}html[data-theme="dark"] .btn{background:rgba(30,41,59,.92);color:#e2e8f0}.btn-primary{color:#fff;border-color:transparent;background:linear-gradient(135deg,var(--accent),#0284c7)}.btn-small{padding:6px 9px;border-radius:8px;font-size:.78rem}.grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:14px}.card,.panel{padding:14px}.panel{margin-top:12px;overflow:hidden}.panel-head{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap}.panel-actions{display:flex;gap:8px;flex-wrap:wrap}.k{color:var(--muted);font-size:.82rem}.v{margin-top:6px;font-size:1.35rem;font-weight:800}.muted{color:var(--muted)}.notice{margin-top:12px;border-radius:12px;padding:10px}.notice.ok{background:rgba(16,185,129,.14);border:1px solid rgba(16,185,129,.32);color:#065f46}.notice.err{background:rgba(239,68,68,.14);border:1px solid rgba(239,68,68,.32);color:#7f1d1d}html[data-theme="dark"] .notice.ok{color:#bbf7d0}html[data-theme="dark"] .notice.err{color:#fecaca}table{width:100%;border-collapse:collapse;font-size:.9rem}th,td{border-bottom:1px solid var(--line);padding:10px;text-align:left;vertical-align:top}th{font-size:.75rem;text-transform:uppercase;color:var(--muted);background:rgba(148,163,184,.08)}.badge{display:inline-flex;align-items:center;border-radius:999px;padding:4px 8px;font-size:.72rem;font-weight:800}.badge.sent{color:#065f46;background:rgba(16,185,129,.18)}.badge.failed{color:#7f1d1d;background:rgba(239,68,68,.18)}.badge.skipped{color:#92400e;background:rgba(245,158,11,.18)}.mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.8rem;white-space:pre-wrap;word-break:break-word}.report-preview{max-height:360px;overflow:auto;border:1px solid var(--line);border-radius:10px;padding:10px;background:rgba(148,163,184,.08)}.card-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}details summary{cursor:pointer;font-weight:700}@media(max-width:900px){.grid{grid-template-columns:1fr}}
    </style>
</head>
<body>
<div class="shell">
    <header class="topbar">
        <div><h1 class="title">Reports</h1><p class="subtitle">Günlük ve haftalık operasyon özetlerini email/Telegram ile gönder.</p></div>
        <div class="actions">
            <span class="btn"><?= e((string) ($user['email'] ?? '-')); ?></span>
            <a class="btn" href="<?= e(url_for('/index.php')); ?>">Dashboard</a>
            <a class="btn" href="<?= e(url_for('/broken_links.php')); ?>">Broken Links</a>
            <a class="btn" href="<?= e(url_for('/link_scans.php')); ?>">Link Scans</a>
            <a class="btn" href="<?= e(url_for('/notifications.php')); ?>">Notifications</a>
            <a class="btn" href="<?= e(url_for('/health.php')); ?>">Health</a>
            <button class="btn" id="theme-toggle" type="button">Tema</button>
            <a class="btn" href="<?= e(url_for('/logout.php')); ?>">Çıkış</a>
        </div>
    </header>

    <?php if (is_array($notice)): ?>
        <div class="notice <?= $notice['ok'] ? 'ok' : 'err'; ?>"><?= e((string) $notice['message']); ?></div>
    <?php endif; ?>

    <section class="grid">
        <article class="card">
            <div class="k">Günlük Rapor</div>
            <div class="v"><?= e((string) $previewDaily['period_start']); ?> - <?= e((string) $previewDaily['period_end']); ?></div>
            <p class="muted">Son 24 saatlik uptime, incident, response time, broken link ve link scan özeti.</p>
            <div class="card-actions">
                <form method="post"><input type="hidden" name="action" value="send_daily"><button class="btn btn-primary" type="submit">Günlük Raporu Gönder</button></form>
            </div>
            <details style="margin-top:10px;"><summary>Önizleme</summary><pre class="mono report-preview"><?= e((string) $previewDaily['body']); ?></pre></details>
        </article>
        <article class="card">
            <div class="k">Haftalık Rapor</div>
            <div class="v"><?= e((string) $previewWeekly['period_start']); ?> - <?= e((string) $previewWeekly['period_end']); ?></div>
            <p class="muted">Son 7 günlük uptime, incident, response time, broken link ve link scan özeti.</p>
            <div class="card-actions">
                <form method="post"><input type="hidden" name="action" value="send_weekly"><button class="btn btn-primary" type="submit">Haftalık Raporu Gönder</button></form>
            </div>
            <details style="margin-top:10px;"><summary>Önizleme</summary><pre class="mono report-preview"><?= e((string) $previewWeekly['body']); ?></pre></details>
        </article>
    </section>

    <section class="panel">
        <div class="panel-head">
            <h2>Rapor Geçmişi</h2>
            <div class="panel-actions">
                <a class="btn btn-small" href="<?= e(url_for('/reports_export.php', ['type' => 'all'])); ?>">CSV</a>
                <a class="btn btn-small" href="<?= e(url_for('/reports_export.php', ['type' => 'daily'])); ?>">Daily CSV</a>
                <a class="btn btn-small" href="<?= e(url_for('/reports_export.php', ['type' => 'weekly'])); ?>">Weekly CSV</a>
            </div>
        </div>
        <table>
            <thead><tr><th>ID</th><th>Type</th><th>Period</th><th>Email</th><th>Telegram</th><th>Created</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ($runs as $run): ?>
                <tr>
                    <td>#<?= (int) $run['id']; ?></td>
                    <td><?= e(strtoupper((string) $run['report_type'])); ?></td>
                    <td class="mono"><?= e((string) $run['period_start']); ?><br><?= e((string) $run['period_end']); ?></td>
                    <td><span class="badge <?= e(report_status_class((string) $run['email_status'])); ?>"><?= e((string) $run['email_status']); ?></span><div class="muted mono"><?= e((string) ($run['email_error'] ?? '')); ?></div></td>
                    <td><span class="badge <?= e(report_status_class((string) $run['telegram_status'])); ?>"><?= e((string) $run['telegram_status']); ?></span><div class="muted mono"><?= e((string) ($run['telegram_error'] ?? '')); ?></div></td>
                    <td><?= e((string) $run['created_at']); ?></td>
                    <td>
                        <form method="post">
                            <input type="hidden" name="action" value="resend">
                            <input type="hidden" name="id" value="<?= (int) $run['id']; ?>">
                            <button class="btn btn-small" type="submit">Tekrar Gönder</button>
                        </form>
                    </td>
                </tr>
                <tr>
                    <td colspan="7">
                        <details><summary>Rapor metni</summary><pre class="mono report-preview"><?= e((string) $run['body']); ?></pre></details>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($runs === []): ?><tr><td colspan="7">Henüz rapor oluşturulmadı.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </section>
</div>
<script>
(function(){var key='ui-theme',root=document.documentElement,btn=document.getElementById('theme-toggle');if(!btn){return;}var saved=localStorage.getItem(key),pref=window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches,theme=saved||(pref?'dark':'light');root.setAttribute('data-theme',theme);btn.textContent=theme==='dark'?'Light':'Dark';btn.addEventListener('click',function(){theme=root.getAttribute('data-theme')==='dark'?'light':'dark';root.setAttribute('data-theme',theme);localStorage.setItem(key,theme);btn.textContent=theme==='dark'?'Light':'Dark';});})();
</script>
</body>
</html>
