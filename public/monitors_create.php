<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_auth();

$pdo = Database::connection();
$monitorRepo = new MonitorRepository($pdo);
$user = current_user();

/**
 * @return array{value:string,ints:array<int,int>}
 */
function normalize_expected_status(string $raw): array
{
    $parts = explode(',', $raw);
    $codes = [];
    foreach ($parts as $part) {
        $value = (int) trim($part);
        if ($value >= 100 && $value <= 599) {
            $codes[$value] = $value;
        }
    }

    if ($codes === []) {
        $codes = [200 => 200, 301 => 301, 302 => 302];
    }

    $ints = array_values($codes);
    sort($ints);

    return [
        'value' => implode(',', $ints),
        'ints' => $ints,
    ];
}

$errors = [];
$old = [
    'name' => '',
    'url' => '',
    'expected_status' => '200,301,302',
    'interval_seconds' => (string) config('DEFAULT_UPTIME_INTERVAL_SECONDS', '300'),
    'timeout_seconds' => (string) config('DEFAULT_TIMEOUT_SECONDS', '10'),
    'response_warning_ms' => (string) config('DEFAULT_RESPONSE_WARNING_MS', '3000'),
    'fail_threshold' => (string) config('DEFAULT_FAIL_THRESHOLD', '3'),
    'recovery_threshold' => (string) config('DEFAULT_RECOVERY_THRESHOLD', '2'),
    'is_active' => '1',
    'link_scan_enabled' => '1',
    'link_scan_interval_seconds' => (string) config('DEFAULT_LINK_SCAN_INTERVAL_SECONDS', '21600'),
    'link_scan_max_depth' => (string) config('DEFAULT_LINK_SCAN_MAX_DEPTH', '3'),
    'link_scan_max_urls' => (string) config('DEFAULT_LINK_SCAN_MAX_URLS', '120'),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old = [
        'name' => trim((string) ($_POST['name'] ?? '')),
        'url' => trim((string) ($_POST['url'] ?? '')),
        'expected_status' => trim((string) ($_POST['expected_status'] ?? '200,301,302')),
        'interval_seconds' => trim((string) ($_POST['interval_seconds'] ?? '300')),
        'timeout_seconds' => trim((string) ($_POST['timeout_seconds'] ?? '10')),
        'response_warning_ms' => trim((string) ($_POST['response_warning_ms'] ?? '3000')),
        'fail_threshold' => trim((string) ($_POST['fail_threshold'] ?? '3')),
        'recovery_threshold' => trim((string) ($_POST['recovery_threshold'] ?? '2')),
        'is_active' => (string) ($_POST['is_active'] ?? '0'),
        'link_scan_enabled' => (string) ($_POST['link_scan_enabled'] ?? '0'),
        'link_scan_interval_seconds' => trim((string) ($_POST['link_scan_interval_seconds'] ?? '21600')),
        'link_scan_max_depth' => trim((string) ($_POST['link_scan_max_depth'] ?? '3')),
        'link_scan_max_urls' => trim((string) ($_POST['link_scan_max_urls'] ?? '120')),
    ];

    if ($old['name'] === '') {
        $errors[] = 'Ad zorunludur.';
    }

    if (!filter_var($old['url'], FILTER_VALIDATE_URL)) {
        $errors[] = 'Geçerli bir URL girin.';
    }

    $expected = normalize_expected_status($old['expected_status']);
    $old['expected_status'] = $expected['value'];

    if ($errors === []) {
        $nextCheck = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $nextLinkScan = ($old['is_active'] === '1' && $old['link_scan_enabled'] === '1')
            ? $nextCheck
            : null;
        $monitorRepo->create([
            'name' => $old['name'],
            'url' => $old['url'],
            'expected_status' => $old['expected_status'],
            'interval_seconds' => max((int) $old['interval_seconds'], 30),
            'timeout_seconds' => max((int) $old['timeout_seconds'], 3),
            'response_warning_ms' => max((int) $old['response_warning_ms'], 100),
            'fail_threshold' => max((int) $old['fail_threshold'], 1),
            'recovery_threshold' => max((int) $old['recovery_threshold'], 1),
            'is_active' => $old['is_active'] === '1' ? 1 : 0,
            'link_scan_enabled' => $old['link_scan_enabled'] === '1' ? 1 : 0,
            'link_scan_interval_seconds' => max((int) $old['link_scan_interval_seconds'], 300),
            'link_scan_max_depth' => max((int) $old['link_scan_max_depth'], 1),
            'link_scan_max_urls' => max((int) $old['link_scan_max_urls'], 10),
            'next_check_at' => $nextCheck,
            'next_link_scan_at' => $nextLinkScan,
        ]);

        redirect_to('/index.php');
    }
}
?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monitör Ekle • Uptime</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --text: #0f172a;
            --muted: #475569;
            --line: rgba(148, 163, 184, 0.36);
            --card: rgba(255, 255, 255, 0.9);
            --accent: #0284c7;
            --accent-soft: #e0f2fe;
            --danger-bg: #fee2e2;
            --danger-text: #7f1d1d;
            --danger-line: #fecaca;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: "IBM Plex Sans", "Segoe UI", sans-serif;
            color: var(--text);
            background:
                radial-gradient(circle at 5% 8%, rgba(14, 165, 233, 0.14), transparent 30%),
                radial-gradient(circle at 94% 15%, rgba(16, 185, 129, 0.12), transparent 28%),
                linear-gradient(160deg, #f8fafc, #ecfeff);
        }
        .shell {
            width: min(980px, 94vw);
            margin: 24px auto 36px;
        }
        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 12px;
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 18px;
            padding: 16px;
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.06);
        }
        .title {
            margin: 0;
            font-family: "Space Grotesk", sans-serif;
            font-size: clamp(1.2rem, 2.5vw, 1.8rem);
        }
        .subtitle {
            margin: 4px 0 0;
            color: var(--muted);
            font-size: 0.92rem;
        }
        .actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .btn {
            text-decoration: none;
            border-radius: 11px;
            padding: 10px 14px;
            font-weight: 600;
            font-size: 0.9rem;
            border: 1px solid var(--line);
            background: #fff;
            color: var(--text);
        }
        .btn-primary {
            border-color: transparent;
            color: #fff;
            background: linear-gradient(135deg, #0ea5e9, var(--accent));
            box-shadow: 0 8px 20px rgba(2, 132, 199, 0.24);
        }
        .card {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 18px;
            padding: 18px;
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.06);
        }
        .section-title {
            margin: 0;
            font-size: 1.05rem;
            font-weight: 700;
        }
        .section-subtitle {
            margin: 6px 0 0;
            color: var(--muted);
            font-size: 0.88rem;
        }
        label {
            display: block;
            margin: 12px 0 6px;
            font-weight: 600;
            font-size: 0.9rem;
        }
        input, select {
            width: 100%;
            padding: 10px 11px;
            border-radius: 11px;
            border: 1px solid #cbd5e1;
            background: #fff;
            font-size: 0.95rem;
            color: var(--text);
        }
        input:focus, select:focus {
            outline: 2px solid #bae6fd;
            outline-offset: 1px;
            border-color: #38bdf8;
        }
        .grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }
        .errors {
            background: var(--danger-bg);
            color: var(--danger-text);
            border: 1px solid var(--danger-line);
            padding: 11px;
            border-radius: 11px;
            margin-bottom: 12px;
        }
        .hint {
            margin-top: 6px;
            color: var(--muted);
            font-size: 0.79rem;
        }
        .subcard {
            margin-top: 12px;
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 12px;
            background: #ffffffcc;
        }
        .subcard h3 {
            margin: 0 0 6px;
            font-size: 0.96rem;
        }
        .toggle {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 12px;
            background: var(--accent-soft);
            border: 1px solid #bae6fd;
            border-radius: 10px;
            padding: 8px 10px;
            font-size: 0.9rem;
            color: #0c4a6e;
        }
        .submit {
            margin-top: 16px;
            width: 100%;
            border: 0;
            border-radius: 12px;
            padding: 12px 14px;
            font-size: 0.95rem;
            font-weight: 700;
            color: #fff;
            cursor: pointer;
            background: linear-gradient(135deg, #0ea5e9, var(--accent));
            box-shadow: 0 10px 22px rgba(2, 132, 199, 0.26);
        }
        @media (max-width: 760px) {
            .grid { grid-template-columns: 1fr; }
            .shell { width: min(96vw, 96vw); margin-top: 14px; }
        }
    </style>
</head>
<body>
    <div class="shell">
        <header class="topbar">
            <div>
                <h1 class="title">Monitör Ekle</h1>
                <p class="subtitle">Yeni servis izleme kaydı oluşturun ve eşik değerlerini belirleyin.</p>
            </div>
            <div class="actions">
                <span class="btn"><?= e((string) ($user['email'] ?? '-')); ?></span>
                <a class="btn" href="<?= e(url_for('/index.php')); ?>">← Dashboard</a>
                <a class="btn" href="<?= e(url_for('/logout.php')); ?>">Çıkış</a>
            </div>
        </header>

        <section class="card">
            <h2 class="section-title">Temel Ayarlar</h2>
            <p class="section-subtitle">Bu ayarlar monitörün kontrol sıklığını ve incident davranışını belirler.</p>

            <?php if ($errors !== []): ?>
                <div class="errors">
                    <?php foreach ($errors as $error): ?>
                        <div><?= e($error); ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="post">
                <div class="grid">
                    <div>
                        <label for="name">Monitör Adı</label>
                        <input id="name" name="name" value="<?= e($old['name']); ?>" placeholder="Örn: API Gateway" required>
                    </div>
                    <div>
                        <label for="url">URL</label>
                        <input id="url" name="url" value="<?= e($old['url']); ?>" placeholder="https://example.com/health" required>
                    </div>
                </div>

                <label for="expected_status">Beklenen HTTP Kodları</label>
                <input id="expected_status" name="expected_status" value="<?= e($old['expected_status']); ?>" placeholder="200,301,302">
                <div class="hint">Virgülle ayırın. Örn: <strong>200,204,301</strong></div>

                <div class="grid">
                    <div>
                        <label for="interval_seconds">Kontrol Aralığı (sn)</label>
                        <input id="interval_seconds" name="interval_seconds" type="number" value="<?= e($old['interval_seconds']); ?>" min="30">
                    </div>
                    <div>
                        <label for="timeout_seconds">Timeout (sn)</label>
                        <input id="timeout_seconds" name="timeout_seconds" type="number" value="<?= e($old['timeout_seconds']); ?>" min="3">
                    </div>
                </div>

                <div class="grid">
                    <div>
                        <label for="response_warning_ms">Yavaşlık Eşiği (ms)</label>
                        <input id="response_warning_ms" name="response_warning_ms" type="number" value="<?= e($old['response_warning_ms']); ?>" min="100">
                    </div>
                    <div>
                        <label for="fail_threshold">Fail Threshold</label>
                        <input id="fail_threshold" name="fail_threshold" type="number" value="<?= e($old['fail_threshold']); ?>" min="1">
                    </div>
                </div>

                <div class="grid">
                    <div>
                        <label for="recovery_threshold">Recovery Threshold</label>
                        <input id="recovery_threshold" name="recovery_threshold" type="number" value="<?= e($old['recovery_threshold']); ?>" min="1">
                    </div>
                    <div>
                        <label for="is_active">Durum</label>
                        <select id="is_active" name="is_active">
                            <option value="1" <?= $old['is_active'] === '1' ? 'selected' : ''; ?>>Aktif</option>
                            <option value="0" <?= $old['is_active'] === '0' ? 'selected' : ''; ?>>Pasif</option>
                        </select>
                    </div>
                </div>

                <div class="subcard">
                    <h3>Link Scan Ayarları</h3>
                    <div class="grid">
                        <div>
                            <label for="link_scan_enabled">Link Scan</label>
                            <select id="link_scan_enabled" name="link_scan_enabled">
                                <option value="1" <?= $old['link_scan_enabled'] === '1' ? 'selected' : ''; ?>>Aktif</option>
                                <option value="0" <?= $old['link_scan_enabled'] === '0' ? 'selected' : ''; ?>>Pasif</option>
                            </select>
                        </div>
                        <div>
                            <label for="link_scan_interval_seconds">Scan Aralığı (sn)</label>
                            <input id="link_scan_interval_seconds" name="link_scan_interval_seconds" type="number" min="300" value="<?= e($old['link_scan_interval_seconds']); ?>">
                        </div>
                    </div>

                    <div class="grid">
                        <div>
                            <label for="link_scan_max_depth">Max Derinlik</label>
                            <input id="link_scan_max_depth" name="link_scan_max_depth" type="number" min="1" value="<?= e($old['link_scan_max_depth']); ?>">
                        </div>
                        <div>
                            <label for="link_scan_max_urls">Max URL</label>
                            <input id="link_scan_max_urls" name="link_scan_max_urls" type="number" min="10" value="<?= e($old['link_scan_max_urls']); ?>">
                        </div>
                    </div>
                </div>

                <div class="toggle">
                    <span>Not:</span>
                    <span>Kaydettikten sonra bir sonraki cron turunda kontrol başlar.</span>
                </div>

                <button class="submit" type="submit">Monitörü Kaydet</button>
            </form>
        </section>
    </div>
</body>
</html>
