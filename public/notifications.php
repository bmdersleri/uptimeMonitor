<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_auth();

function mask_value(string $value, int $left = 4, int $right = 3): string
{
    if ($value === '') {
        return '-';
    }
    if (strlen($value) <= ($left + $right)) {
        return str_repeat('*', strlen($value));
    }
    return substr($value, 0, $left) . str_repeat('*', 6) . substr($value, -$right);
}

function notification_retry_counts(PDO $pdo): array
{
    try {
        $row = $pdo->query("
            SELECT
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
                SUM(CASE WHEN status = 'retrying' THEN 1 ELSE 0 END) AS retrying_count,
                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS sent_count,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_count
            FROM notification_retry_queue
        ")->fetch();
        return is_array($row) ? $row : [];
    } catch (Throwable $e) {
        return [];
    }
}

function recent_notification_logs(PDO $pdo): array
{
    try {
        return $pdo->query("
            SELECT l.*, m.name AS monitor_name
            FROM notification_logs l
            LEFT JOIN monitors m ON m.id = l.monitor_id
            ORDER BY l.id DESC
            LIMIT 30
        ")->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function send_test_email(): array
{
    $to = (string) config('NOTIFY_EMAIL_TO', '');
    if ((string) config('NOTIFY_EMAIL_ENABLED', 'false') !== 'true' || $to === '') {
        return ['ok' => false, 'message' => 'Email channel is disabled or NOTIFY_EMAIL_TO is empty.'];
    }

    $subject = (string) config('notifications.subject_prefix', '[Uptime]') . ' TEST';
    $message = "Uptime Monitor test email\nTime: " . (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
    $ok = @mail($to, $subject, $message, "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8");
    return ['ok' => $ok, 'message' => $ok ? 'Test email sent.' : 'mail() returned false.'];
}

function send_test_telegram(): array
{
    $token = (string) config('TELEGRAM_BOT_TOKEN', '');
    $chatId = (string) config('TELEGRAM_DEFAULT_CHAT_ID', '');
    if ((string) config('NOTIFY_TELEGRAM_ENABLED', 'false') !== 'true' || $token === '' || $chatId === '') {
        return ['ok' => false, 'message' => 'Telegram channel is disabled or token/chat id is empty.'];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'message' => 'PHP curl extension is not available.'];
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://api.telegram.org/bot' . rawurlencode($token) . '/sendMessage',
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'chat_id' => $chatId,
            'text' => "Uptime Monitor Telegram test\nTime: " . (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 12,
    ]);
    $body = curl_exec($ch);
    $error = $body === false ? curl_error($ch) : null;
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $ok = $error === null && $http >= 200 && $http < 300;
    return ['ok' => $ok, 'message' => $ok ? 'Test Telegram message sent.' : ($error ?: 'Telegram HTTP ' . $http)];
}

$pdo = Database::connection();
$user = current_user();
$brandTitle = (string) config('app.brand.title', config('APP_NAME', 'Uptime Monitor'));
$notice = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'test_email') {
        $notice = send_test_email();
    } elseif ($action === 'test_telegram') {
        $notice = send_test_telegram();
    }
}

$retryCounts = notification_retry_counts($pdo);
$logs = recent_notification_logs($pdo);
?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications • <?= e($brandTitle); ?></title>
    <style>
        :root { --accent:#0ea5e9; --danger:#ef4444; --success:#10b981; --text:#0f172a; --muted:#475569; --card:rgba(255,255,255,0.86); --line:rgba(148,163,184,0.35); --bg1:#f8fafc; --bg2:#ecfeff; }
        html[data-theme="dark"] { --text:#e2e8f0; --muted:#94a3b8; --card:rgba(15,23,42,0.84); --line:rgba(71,85,105,0.5); --bg1:#0b1220; --bg2:#111827; }
        *{box-sizing:border-box} body{margin:0;min-height:100vh;color:var(--text);font-family:"IBM Plex Sans","Segoe UI",Arial,sans-serif;background:radial-gradient(circle at 8% 10%,rgba(14,165,233,.16),transparent 32%),radial-gradient(circle at 92% 18%,rgba(16,185,129,.14),transparent 30%),linear-gradient(160deg,var(--bg1),var(--bg2));}
        .shell{width:min(1200px,94vw);margin:24px auto 36px}.topbar,.card,.panel{background:var(--card);border:1px solid var(--line);border-radius:16px;backdrop-filter:blur(8px);box-shadow:0 10px 26px rgba(15,23,42,.05)}.topbar{padding:18px 20px;display:flex;justify-content:space-between;gap:14px;align-items:center;flex-wrap:wrap}.title{margin:0;font-size:1.65rem}.subtitle{margin:5px 0 0;color:var(--muted)}.actions{display:flex;gap:8px;flex-wrap:wrap}.btn{border:1px solid var(--line);border-radius:11px;background:rgba(255,255,255,.76);color:var(--text);padding:9px 12px;text-decoration:none;font-weight:700;cursor:pointer}html[data-theme="dark"] .btn{background:rgba(30,41,59,.92);color:#e2e8f0}.btn-primary{color:#fff;border-color:transparent;background:linear-gradient(135deg,var(--accent),#0284c7)}.grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-top:14px}.card,.panel{padding:14px}.panel{margin-top:12px;overflow:hidden}.k{color:var(--muted);font-size:.82rem}.v{margin-top:6px;font-size:1.45rem;font-weight:800}.ok{color:var(--success)}.bad{color:var(--danger)}table{width:100%;border-collapse:collapse;font-size:.9rem}th,td{border-bottom:1px solid var(--line);padding:10px;text-align:left;vertical-align:top}th{font-size:.75rem;text-transform:uppercase;color:var(--muted);background:rgba(148,163,184,.08)}.mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.8rem;word-break:break-all}.notice{margin-top:12px;border-radius:12px;padding:10px}.notice.ok-bg{background:rgba(16,185,129,.14);border:1px solid rgba(16,185,129,.32);color:#065f46}.notice.err-bg{background:rgba(239,68,68,.14);border:1px solid rgba(239,68,68,.32);color:#7f1d1d}html[data-theme="dark"] .notice.ok-bg{color:#bbf7d0}html[data-theme="dark"] .notice.err-bg{color:#fecaca}.channel-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:12px}.muted{color:var(--muted)}@media(max-width:900px){.grid,.channel-grid{grid-template-columns:1fr}}
    </style>
</head>
<body>
<div class="shell">
    <header class="topbar">
        <div><h1 class="title">Notifications</h1><p class="subtitle">Channel settings, test sends, and recent delivery history.</p></div>
        <div class="actions">
            <span class="btn"><?= e((string) ($user['email'] ?? '-')); ?></span>
            <a class="btn" href="<?= e(url_for('/index.php')); ?>">Dashboard</a>
            <a class="btn" href="<?= e(url_for('/broken_links.php')); ?>">Broken Links</a>
            <a class="btn" href="<?= e(url_for('/link_scans.php')); ?>">Link Scans</a>
            <a class="btn" href="<?= e(url_for('/health.php')); ?>">Health</a>
            <button class="btn" id="theme-toggle" type="button">Tema</button>
            <a class="btn" href="<?= e(url_for('/logout.php')); ?>">Çıkış</a>
        </div>
    </header>
    <?php if (is_array($notice)): ?><div class="notice <?= $notice['ok'] ? 'ok-bg' : 'err-bg'; ?>"><?= e((string) $notice['message']); ?></div><?php endif; ?>
    <section class="channel-grid">
        <article class="panel">
            <h2>Email</h2>
            <p class="muted">Enabled: <strong><?= e((string) config('NOTIFY_EMAIL_ENABLED', 'false')); ?></strong></p>
            <p class="muted">To: <strong><?= e(mask_value((string) config('NOTIFY_EMAIL_TO', ''), 3, 8)); ?></strong></p>
            <form method="post"><input type="hidden" name="action" value="test_email"><button class="btn btn-primary" type="submit">Send Test Email</button></form>
        </article>
        <article class="panel">
            <h2>Telegram</h2>
            <p class="muted">Enabled: <strong><?= e((string) config('NOTIFY_TELEGRAM_ENABLED', 'false')); ?></strong></p>
            <p class="muted">Bot token: <strong><?= e(mask_value((string) config('TELEGRAM_BOT_TOKEN', ''))); ?></strong></p>
            <p class="muted">Chat ID: <strong><?= e(mask_value((string) config('TELEGRAM_DEFAULT_CHAT_ID', ''), 3, 3)); ?></strong></p>
            <form method="post"><input type="hidden" name="action" value="test_telegram"><button class="btn btn-primary" type="submit">Send Test Telegram</button></form>
        </article>
    </section>
    <section class="grid">
        <article class="card"><div class="k">Pending</div><div class="v"><?= (int) ($retryCounts['pending_count'] ?? 0); ?></div></article>
        <article class="card"><div class="k">Retrying</div><div class="v"><?= (int) ($retryCounts['retrying_count'] ?? 0); ?></div></article>
        <article class="card"><div class="k">Sent</div><div class="v ok"><?= (int) ($retryCounts['sent_count'] ?? 0); ?></div></article>
        <article class="card"><div class="k">Failed</div><div class="v bad"><?= (int) ($retryCounts['failed_count'] ?? 0); ?></div></article>
    </section>
    <section class="panel">
        <h2>Recent Notification Logs</h2>
        <table><thead><tr><th>Time</th><th>Monitor</th><th>Event</th><th>Channel</th><th>Status</th><th>Error</th></tr></thead><tbody>
        <?php foreach ($logs as $log): ?>
            <tr><td><?= e((string) $log['created_at']); ?></td><td><?= e((string) ($log['monitor_name'] ?? '-')); ?></td><td><?= e((string) $log['event_type']); ?></td><td><?= e((string) $log['channel']); ?></td><td><?= e((string) $log['status']); ?></td><td class="mono"><?= e((string) ($log['error_message'] ?? '-')); ?></td></tr>
        <?php endforeach; ?>
        <?php if ($logs === []): ?><tr><td colspan="6">Notification log kaydı yok.</td></tr><?php endif; ?>
        </tbody></table>
    </section>
</div>
<script>
(function(){var key='ui-theme',root=document.documentElement,btn=document.getElementById('theme-toggle');if(!btn){return;}var saved=localStorage.getItem(key),pref=window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches,theme=saved||(pref?'dark':'light');root.setAttribute('data-theme',theme);btn.textContent=theme==='dark'?'Light':'Dark';btn.addEventListener('click',function(){theme=root.getAttribute('data-theme')==='dark'?'light':'dark';root.setAttribute('data-theme',theme);localStorage.setItem(key,theme);btn.textContent=theme==='dark'?'Light':'Dark';});})();
</script>
</body>
</html>
