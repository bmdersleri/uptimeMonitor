<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

if (auth()->check()) {
    redirect_to('/index.php');
}

$users = new UserRepository(Database::connection());
if (!$users->hasAnyUser()) {
    $query = [];
    $setupToken = trim((string) ($_GET['token'] ?? ''));
    if ($setupToken !== '') {
        $query['token'] = $setupToken;
    }
    redirect_to('/setup_admin.php', $query);
}

$errors = [];
$oldEmail = '';
$info = '';

if ((string) ($_GET['setup'] ?? '') === 'closed') {
    $info = 'Ilk admin kurulum sayfasi devre disi. Normal giris ekranini kullanin.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $oldEmail = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($oldEmail === '' || $password === '') {
        $errors[] = 'Email ve şifre zorunludur.';
    } else {
        if (auth()->attemptLogin($oldEmail, $password)) {
            redirect_to('/index.php');
        }
        $errors[] = 'Giriş başarısız. Bilgileri kontrol edin.';
    }
}
?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Giriş • <?= e((string) config('app.brand.title', 'Uptime Monitor')); ?></title>
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            font-family: "Segoe UI", Arial, sans-serif;
            background: linear-gradient(140deg, #f0f9ff, #eff6ff);
        }
        .card {
            width: min(420px, 92vw);
            background: #fff;
            border: 1px solid #dbeafe;
            border-radius: 16px;
            padding: 22px;
            box-shadow: 0 20px 40px rgba(15, 23, 42, 0.08);
        }
        h1 { margin: 0 0 6px; font-size: 1.25rem; }
        p { margin: 0 0 14px; color: #475569; font-size: 0.92rem; }
        label { display: block; margin-top: 10px; font-weight: 600; }
        input {
            width: 100%;
            margin-top: 4px;
            padding: 10px;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            box-sizing: border-box;
        }
        .error {
            margin-top: 10px;
            background: #fee2e2;
            color: #7f1d1d;
            border: 1px solid #fecaca;
            border-radius: 10px;
            padding: 10px;
            font-size: 0.9rem;
        }
        .info {
            margin-top: 10px;
            background: #ecfeff;
            color: #155e75;
            border: 1px solid #a5f3fc;
            border-radius: 10px;
            padding: 10px;
            font-size: 0.9rem;
        }
        button {
            margin-top: 14px;
            width: 100%;
            padding: 10px;
            border: 0;
            border-radius: 10px;
            color: #fff;
            font-weight: 700;
            background: linear-gradient(135deg, #0ea5e9, #0284c7);
            cursor: pointer;
        }
    </style>
</head>
<body>
    <form method="post" class="card">
        <h1>Admin Girişi</h1>
        <p><?= e((string) config('app.brand.title', 'Uptime Monitor')); ?></p>

        <?php foreach ($errors as $error): ?>
            <div class="error"><?= e($error); ?></div>
        <?php endforeach; ?>
        <?php if ($info !== ''): ?>
            <div class="info"><?= e($info); ?></div>
        <?php endif; ?>

        <label for="email">Email</label>
        <input id="email" name="email" type="email" value="<?= e($oldEmail); ?>" required>

        <label for="password">Şifre</label>
        <input id="password" name="password" type="password" required>

        <button type="submit">Giriş Yap</button>
    </form>
</body>
</html>
