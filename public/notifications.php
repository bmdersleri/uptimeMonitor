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
    $client = new TelegramClient();
    $result = $client->sendMessage($token, $chatId, "Uptime Monitor Telegram test\nTime: " . (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'));
    $ok = ($result['status'] ?? '') === 'sent';
    return ['ok' => $ok, 'message' => $ok ? 'Test Telegram message sent.' : (string) ($result['error'] ?? 'Telegram send failed')];
}

function notifications_csrf_token(): string
{
    if (!isset($_SESSION['notifications_csrf'])) {
        try {
            $_SESSION['notifications_csrf'] = bin2hex(random_bytes(16));
        } catch (Throwable $exception) {
            $_SESSION['notifications_csrf'] = sha1(uniqid('notifications', true));
        }
    }

    return (string) $_SESSION['notifications_csrf'];
}

function notifications_flash_notice(?array $notice = null): ?array
{
    if ($notice !== null) {
        $_SESSION['notifications_flash_notice'] = $notice;
        return null;
    }

    if (!isset($_SESSION['notifications_flash_notice']) || !is_array($_SESSION['notifications_flash_notice'])) {
        return null;
    }

    $flash = $_SESSION['notifications_flash_notice'];
    unset($_SESSION['notifications_flash_notice']);
    return $flash;
}

/**
 * @return array<string, string>
 */
function notifications_current_settings(): array
{
    return [
        'notify_email_enabled' => strtolower(trim((string) config('NOTIFY_EMAIL_ENABLED', 'false'))) === 'true' ? 'true' : 'false',
        'notify_email_to' => (string) config('NOTIFY_EMAIL_TO', ''),
        'notify_telegram_enabled' => strtolower(trim((string) config('NOTIFY_TELEGRAM_ENABLED', 'false'))) === 'true' ? 'true' : 'false',
        'telegram_bot_token' => (string) config('TELEGRAM_BOT_TOKEN', ''),
        'telegram_default_chat_id' => (string) config('TELEGRAM_DEFAULT_CHAT_ID', ''),
    ];
}

$pdo = Database::connection();
$user = current_user();
$brandTitle = (string) config('app.brand.title', config('APP_NAME', 'Uptime Monitor'));
$notice = notifications_flash_notice();
$settings = notifications_current_settings();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $currentSettings = notifications_current_settings();
    $postedSettings = [
        'notify_email_enabled' => isset($_POST['notify_email_enabled']) ? 'true' : 'false',
        'notify_email_to' => trim((string) ($_POST['notify_email_to'] ?? '')),
        'notify_telegram_enabled' => isset($_POST['notify_telegram_enabled']) ? 'true' : 'false',
        'telegram_bot_token' => trim((string) ($_POST['telegram_bot_token'] ?? '')),
        'telegram_default_chat_id' => trim((string) ($_POST['telegram_default_chat_id'] ?? '')),
    ];

    if ($action === 'save_settings') {
        $settings = $postedSettings;
        $saveValues = [
            'notify_email_enabled' => $settings['notify_email_enabled'],
            'notify_email_to' => $settings['notify_email_to'] !== '' ? $settings['notify_email_to'] : $currentSettings['notify_email_to'],
            'notify_telegram_enabled' => $settings['notify_telegram_enabled'],
            'telegram_bot_token' => $settings['telegram_bot_token'] !== '' ? $settings['telegram_bot_token'] : $currentSettings['telegram_bot_token'],
            'telegram_default_chat_id' => $settings['telegram_default_chat_id'] !== '' ? $settings['telegram_default_chat_id'] : $currentSettings['telegram_default_chat_id'],
        ];
        $errors = [];

        if ($saveValues['notify_email_enabled'] === 'true' && $saveValues['notify_email_to'] === '') {
            $errors[] = 'Email etkinse alici adresi zorunludur.';
        }
        if ($saveValues['notify_email_to'] !== '' && filter_var($saveValues['notify_email_to'], FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'Gecerli bir email adresi girin.';
        }
        if ($saveValues['notify_telegram_enabled'] === 'true') {
            if ($saveValues['telegram_bot_token'] === '') {
                $errors[] = 'Telegram etkinse bot token zorunludur.';
            }
            if ($saveValues['telegram_default_chat_id'] === '') {
                $errors[] = 'Telegram etkinse chat id zorunludur.';
            }
        }

        if ($errors === []) {
            try {
                config_update_env_values([
                    'NOTIFY_EMAIL_ENABLED' => $saveValues['notify_email_enabled'],
                    'NOTIFY_EMAIL_TO' => $saveValues['notify_email_to'],
                    'NOTIFY_TELEGRAM_ENABLED' => $saveValues['notify_telegram_enabled'],
                    'TELEGRAM_BOT_TOKEN' => $saveValues['telegram_bot_token'],
                    'TELEGRAM_DEFAULT_CHAT_ID' => $saveValues['telegram_default_chat_id'],
                ]);
                notifications_flash_notice(['ok' => true, 'message' => 'Notification settings saved.']);
                redirect_to('/notifications.php');
            } catch (Throwable $exception) {
                $notice = ['ok' => false, 'message' => $exception->getMessage()];
            }
        } else {
            $notice = ['ok' => false, 'message' => implode(' ', $errors)];
        }
    } elseif ($action === 'test_email') {
        $notice = send_test_email();
    } elseif ($action === 'test_telegram') {
        $notice = send_test_telegram();
    }

    $settings = $postedSettings + $settings;
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
        .shell{width:min(1200px,94vw);margin:24px auto 36px}.topbar,.card,.panel{background:var(--card);border:1px solid var(--line);border-radius:16px;backdrop-filter:blur(8px);box-shadow:0 10px 26px rgba(15,23,42,.05)}.topbar{padding:18px 20px;display:flex;justify-content:space-between;gap:14px;align-items:center;flex-wrap:wrap}.title{margin:0;font-size:1.65rem}.subtitle{margin:5px 0 0;color:var(--muted)}.actions{display:flex;gap:8px;flex-wrap:wrap}.btn{border:1px solid var(--line);border-radius:11px;background:rgba(255,255,255,.76);color:var(--text);padding:9px 12px;text-decoration:none;font-weight:700;cursor:pointer}html[data-theme="dark"] .btn{background:rgba(30,41,59,.92);color:#e2e8f0}.btn-primary{color:#fff;border-color:transparent;background:linear-gradient(135deg,var(--accent),#0284c7)}.grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-top:14px}.card,.panel{padding:14px}.panel{margin-top:12px;overflow:hidden}.panel-head{display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap}.panel-title{margin:0;font-size:1.12rem}.muted{color:var(--muted)}.k{color:var(--muted);font-size:.82rem}.v{margin-top:6px;font-size:1.45rem;font-weight:800}.ok{color:var(--success)}.bad{color:var(--danger)}table{width:100%;border-collapse:collapse;font-size:.9rem}th,td{border-bottom:1px solid var(--line);padding:10px;text-align:left;vertical-align:top}th{font-size:.75rem;text-transform:uppercase;color:var(--muted);background:rgba(148,163,184,.08)}.mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.8rem;word-break:break-all}.notice{margin-top:12px;border-radius:12px;padding:10px}.notice.ok-bg{background:rgba(16,185,129,.14);border:1px solid rgba(16,185,129,.32);color:#065f46}.notice.err-bg{background:rgba(239,68,68,.14);border:1px solid rgba(239,68,68,.32);color:#7f1d1d}html[data-theme="dark"] .notice.ok-bg{color:#bbf7d0}html[data-theme="dark"] .notice.err-bg{color:#fecaca}.channel-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:12px}.settings-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.field label{display:block;margin:0 0 6px;font-size:.84rem;font-weight:700;color:var(--muted)}.field input[type="text"],.field input[type="email"]{width:100%;padding:10px 12px;border:1px solid var(--line);border-radius:10px;background:rgba(255,255,255,.82);color:var(--text)}html[data-theme="dark"] .field input[type="text"],html[data-theme="dark"] .field input[type="email"]{background:rgba(15,23,42,.82)}.field input[type="text"]:focus,.field input[type="email"]:focus{outline:2px solid rgba(14,165,233,.25);border-color:var(--accent)}.switch-row{display:flex;align-items:center;gap:10px;padding:10px 0 4px}.switch-row input{width:16px;height:16px}.help{margin-top:5px;font-size:.82rem;color:var(--muted);line-height:1.4}.form-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}.setting-summary{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-top:12px}.setting-pill{border:1px solid var(--line);border-radius:12px;padding:10px 12px;background:rgba(148,163,184,.06)}.setting-pill .label{display:block;font-size:.76rem;color:var(--muted);text-transform:uppercase;letter-spacing:.02em}.setting-pill .value{display:block;margin-top:4px;font-weight:800;word-break:break-word}.settings-intro{margin:8px 0 0;color:var(--muted);line-height:1.5}.settings-header{display:flex;justify-content:space-between;gap:10px;align-items:flex-start;flex-wrap:wrap}.settings-actions{display:flex;gap:8px;flex-wrap:wrap}@media(max-width:900px){.grid,.channel-grid,.settings-grid,.setting-summary{grid-template-columns:1fr}}
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
            <a class="btn" href="<?= e(url_for('/reports.php')); ?>">Reports</a>
            <a class="btn" href="<?= e(url_for('/health.php')); ?>">Health</a>
            <button class="btn" id="theme-toggle" type="button">Tema</button>
            <a class="btn" href="<?= e(url_for('/logout.php')); ?>">Çıkış</a>
        </div>
    </header>
    <?php if (is_array($notice)): ?><div class="notice <?= $notice['ok'] ? 'ok-bg' : 'err-bg'; ?>"><?= e((string) $notice['message']); ?></div><?php endif; ?>

    <section class="panel">
        <div class="settings-header">
            <div>
                <h2 class="panel-title">Notification Settings</h2>
                <p class="settings-intro">Buradan email alici adresini ve Telegram bot bilgilerini girip kaydedebilirsiniz. Bu alanlar `.env` dosyasina yazilir ve bildirim sistemi ile raporlar ayni degerleri kullanir.</p>
            </div>
            <div class="settings-actions">
                <span class="btn">Email + Telegram</span>
                <span class="btn">Kayitli Ayarlar</span>
            </div>
        </div>
        <div class="setting-summary">
            <div class="setting-pill"><span class="label">Email durumu</span><span class="value"><?= e((string) config('NOTIFY_EMAIL_ENABLED', 'false')); ?></span></div>
            <div class="setting-pill"><span class="label">Telegram durumu</span><span class="value"><?= e((string) config('NOTIFY_TELEGRAM_ENABLED', 'false')); ?></span></div>
            <div class="setting-pill"><span class="label">Kaydedilen hedef</span><span class="value"><?= e((string) config('NOTIFY_EMAIL_TO', '')); ?></span></div>
        </div>
        <form method="post">
            <input type="hidden" name="action" value="save_settings">
            <input type="hidden" name="csrf_token" value="<?= e(notifications_csrf_token()); ?>">
            <div class="settings-grid">
                <div class="field">
                    <label for="notify_email_to">Email alici adresi</label>
                    <input id="notify_email_to" name="notify_email_to" type="email" value="<?= e((string) $settings['notify_email_to']); ?>" placeholder="ops@example.com">
                    <div class="help">Email etkinse bu alan zorunludur. Bos birakirsaniz mevcut deger korunur.</div>
                </div>
                <div class="field">
                    <label for="telegram_default_chat_id">Telegram chat id</label>
                    <input id="telegram_default_chat_id" name="telegram_default_chat_id" type="text" value="<?= e((string) $settings['telegram_default_chat_id']); ?>" placeholder="123456789">
                    <div class="help">Telegram etkinse bu alan zorunludur. Bos birakirsaniz mevcut deger korunur.</div>
                </div>
                <div class="field">
                    <div class="switch-row">
                        <input id="notify_email_enabled" name="notify_email_enabled" type="checkbox" value="1" <?= $settings['notify_email_enabled'] === 'true' ? 'checked' : ''; ?>>
                        <label for="notify_email_enabled" style="margin:0;color:var(--text);">Email bildirimi aktif et</label>
                    </div>
                    <div class="help">Kapaliysa rapor ve alarm emaili gonderilmez.</div>
                </div>
                <div class="field">
                    <div class="switch-row">
                        <input id="notify_telegram_enabled" name="notify_telegram_enabled" type="checkbox" value="1" <?= $settings['notify_telegram_enabled'] === 'true' ? 'checked' : ''; ?>>
                        <label for="notify_telegram_enabled" style="margin:0;color:var(--text);">Telegram bildirimi aktif et</label>
                    </div>
                    <div class="help">Kapaliysa Telegram testleri ve bildirimleri durur.</div>
                </div>
                <div class="field" style="grid-column:1 / -1;">
                    <label for="telegram_bot_token">Telegram bot token</label>
                    <input id="telegram_bot_token" name="telegram_bot_token" type="text" value="<?= e((string) $settings['telegram_bot_token']); ?>" placeholder="123456:ABCDEF...">
                    <div class="help">Bos birakirsaniz mevcut deger korunur. Token degisti ise yeni degeri buraya girin.</div>
                </div>
            </div>
            <div class="form-actions">
                <button class="btn btn-primary" type="submit">Ayarları Kaydet</button>
                <span class="muted">Degisiklikler sonraki isteklerde aktif olur.</span>
            </div>
        </form>
    </section>

    <section class="channel-grid">
        <article class="panel">
            <h2>Email</h2>
            <p class="muted">Enabled: <strong><?= e((string) config('NOTIFY_EMAIL_ENABLED', 'false')); ?></strong></p>
            <p class="muted">To: <strong><?= e(mask_value((string) config('NOTIFY_EMAIL_TO', ''), 3, 8)); ?></strong></p>
            <form method="post">
                <input type="hidden" name="action" value="test_email">
                <input type="hidden" name="csrf_token" value="<?= e(notifications_csrf_token()); ?>">
                <button class="btn btn-primary" type="submit">Send Test Email</button>
            </form>
        </article>
        <article class="panel">
            <h2>Telegram</h2>
            <p class="muted">Enabled: <strong><?= e((string) config('NOTIFY_TELEGRAM_ENABLED', 'false')); ?></strong></p>
            <p class="muted">Bot token: <strong><?= e(mask_value((string) config('TELEGRAM_BOT_TOKEN', ''))); ?></strong></p>
            <p class="muted">Chat ID: <strong><?= e(mask_value((string) config('TELEGRAM_DEFAULT_CHAT_ID', ''), 3, 3)); ?></strong></p>
            <form method="post">
                <input type="hidden" name="action" value="test_telegram">
                <input type="hidden" name="csrf_token" value="<?= e(notifications_csrf_token()); ?>">
                <button class="btn btn-primary" type="submit">Send Test Telegram</button>
            </form>
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
