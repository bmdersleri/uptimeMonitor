<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

/**
 * SQLite kullaniminda users tablosu yoksa schema.sql'den olusturur.
 */
function ensure_users_table_exists(PDO $pdo): void
{
    try {
        $pdo->query('SELECT 1 FROM users LIMIT 1');
        return;
    } catch (Throwable $exception) {
        $driver = strtolower((string) config('DB_DRIVER', 'sqlite'));
        if ($driver !== 'sqlite') {
            throw $exception;
        }
    }

    $schemaPath = __DIR__ . '/../database/schema.sql';
    if (!is_file($schemaPath)) {
        throw new RuntimeException('Schema dosyasi bulunamadi.');
    }

    $schema = file_get_contents($schemaPath);
    if ($schema === false || trim($schema) === '') {
        throw new RuntimeException('Schema dosyasi okunamadi.');
    }

    $pdo->exec($schema);
}

if (auth()->check()) {
    redirect_to('/index.php');
}

$isSetupEnabled = strtolower(trim((string) config('FIRST_ADMIN_SETUP_ENABLED', 'true'))) !== 'false';
if (!$isSetupEnabled) {
    http_response_code(403);
    echo 'Ilk admin kurulum sayfasi devre disi.';
    exit;
}

$errors = [];
$info = [];
$old = [
    'name' => '',
    'email' => '',
];

$requiredToken = trim((string) config('FIRST_ADMIN_SETUP_TOKEN', ''));
$providedToken = trim((string) ($_REQUEST['token'] ?? ''));

if ($requiredToken !== '' && !hash_equals($requiredToken, $providedToken)) {
    http_response_code(403);
    echo 'Kurulum anahtari gecersiz. Dogru token ile tekrar deneyin.';
    exit;
}

try {
    $pdo = Database::connection();
    ensure_users_table_exists($pdo);
    $users = new UserRepository($pdo);
} catch (Throwable $exception) {
    http_response_code(500);
    echo 'Veritabani hazirlanamadi: ' . e($exception->getMessage());
    exit;
}

if ($users->hasAnyUser()) {
    redirect_to('/login.php', ['setup' => 'closed']);
}

if (!isset($_SESSION['setup_admin_csrf'])) {
    try {
        $_SESSION['setup_admin_csrf'] = bin2hex(random_bytes(16));
    } catch (Throwable $exception) {
        $_SESSION['setup_admin_csrf'] = sha1(uniqid('setup', true));
    }
}

$csrfToken = (string) $_SESSION['setup_admin_csrf'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedCsrf = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals($csrfToken, $postedCsrf)) {
        $errors[] = 'Guvenlik dogrulamasi basarisiz. Sayfayi yenileyip tekrar deneyin.';
    }

    $old['name'] = trim((string) ($_POST['name'] ?? ''));
    $old['email'] = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

    if ($old['name'] === '') {
        $errors[] = 'Ad soyad zorunludur.';
    }

    if (filter_var($old['email'], FILTER_VALIDATE_EMAIL) === false) {
        $errors[] = 'Gecerli bir email girin.';
    }

    if (strlen($password) < 8) {
        $errors[] = 'Sifre en az 8 karakter olmali.';
    }

    if ($password !== $passwordConfirm) {
        $errors[] = 'Sifre tekrar alani eslesmiyor.';
    }

    if ($errors === []) {
        $existing = $users->findActiveByEmail($old['email']);
        if ($existing !== null) {
            $errors[] = 'Bu email ile aktif bir kullanici zaten var.';
        }
    }

    if ($errors === []) {
        $users->createAdmin($old['name'], $old['email'], $password);
        unset($_SESSION['setup_admin_csrf']);

        if (auth()->attemptLogin($old['email'], $password)) {
            redirect_to('/index.php');
        }

        $info[] = 'Admin olusturuldu. Simdi giris yapabilirsiniz.';
        redirect_to('/login.php');
    }
}
?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ilk Admin Kurulumu</title>
    <style>
        :root {
            --bg0: #f8fafc;
            --bg1: #ecfeff;
            --bg2: #eff6ff;
            --card: #ffffff;
            --line: #bfdbfe;
            --text: #0f172a;
            --muted: #475569;
            --brand: #0369a1;
            --brand2: #0ea5e9;
            --danger-bg: #fee2e2;
            --danger-line: #fecaca;
            --danger-text: #7f1d1d;
            --ok-bg: #dcfce7;
            --ok-line: #86efac;
            --ok-text: #14532d;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: "Segoe UI", Arial, sans-serif;
            color: var(--text);
            background:
                radial-gradient(circle at 5% 10%, #e0f2fe 0, transparent 35%),
                radial-gradient(circle at 92% 15%, #dbeafe 0, transparent 30%),
                linear-gradient(145deg, var(--bg1), var(--bg0), var(--bg2));
            display: grid;
            place-items: center;
            padding: 24px 14px;
        }

        .card {
            width: min(540px, 96vw);
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 18px;
            padding: 24px;
            box-shadow: 0 20px 45px rgba(15, 23, 42, 0.1);
        }

        h1 {
            margin: 0;
            font-size: 1.45rem;
        }

        .subtitle {
            margin: 6px 0 0;
            color: var(--muted);
            line-height: 1.45;
        }

        .box {
            margin-top: 14px;
            border-radius: 12px;
            padding: 10px 12px;
            font-size: 0.92rem;
        }

        .box.error {
            background: var(--danger-bg);
            border: 1px solid var(--danger-line);
            color: var(--danger-text);
        }

        .box.info {
            background: var(--ok-bg);
            border: 1px solid var(--ok-line);
            color: var(--ok-text);
        }

        label {
            display: block;
            margin-top: 12px;
            margin-bottom: 6px;
            font-weight: 600;
        }

        input {
            width: 100%;
            padding: 11px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            font-size: 0.95rem;
        }

        input:focus {
            border-color: var(--brand2);
            outline: 2px solid #bae6fd;
            outline-offset: 1px;
        }

        button {
            width: 100%;
            margin-top: 16px;
            padding: 12px;
            border: 0;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 700;
            color: #fff;
            background: linear-gradient(135deg, var(--brand2), var(--brand));
        }

        .tiny {
            margin-top: 12px;
            font-size: 0.86rem;
            color: var(--muted);
        }
    </style>
</head>
<body>
    <form method="post" class="card" autocomplete="off">
        <h1>Ilk Admin Kurulumu</h1>
        <p class="subtitle">
            Sisteminizde henuz admin kullanici yok. Bu ekran sadece ilk kurulumda acilir.
        </p>

        <?php foreach ($errors as $error): ?>
            <div class="box error"><?= e((string) $error); ?></div>
        <?php endforeach; ?>

        <?php foreach ($info as $line): ?>
            <div class="box info"><?= e((string) $line); ?></div>
        <?php endforeach; ?>

        <input type="hidden" name="csrf_token" value="<?= e($csrfToken); ?>">
        <?php if ($requiredToken !== ''): ?>
            <input type="hidden" name="token" value="<?= e($providedToken); ?>">
        <?php endif; ?>

        <label for="name">Ad Soyad</label>
        <input id="name" name="name" type="text" required value="<?= e($old['name']); ?>" placeholder="Orn: Site Yonetici">

        <label for="email">Email</label>
        <input id="email" name="email" type="email" required value="<?= e($old['email']); ?>" placeholder="orn: admin@domain.com">

        <label for="password">Sifre (en az 8 karakter)</label>
        <input id="password" name="password" type="password" required minlength="8">

        <label for="password_confirm">Sifre (tekrar)</label>
        <input id="password_confirm" name="password_confirm" type="password" required minlength="8">

        <button type="submit">Admin Hesabini Olustur</button>

        <div class="tiny">Kurulumdan sonra bu sayfa otomatik olarak kapanir ve giris ekrani kullanilir.</div>
    </form>
</body>
</html>
