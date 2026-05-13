<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

if (PHP_SAPI !== 'cli') {
    echo "Bu script sadece CLI icin.\n";
    exit(1);
}

$name = (string) config('ADMIN_NAME', 'Admin');
$email = (string) config('ADMIN_EMAIL', '');
$password = (string) config('ADMIN_PASSWORD', '');

if ($email === '' || $password === '') {
    echo "Kullanim: ADMIN_EMAIL ve ADMIN_PASSWORD .env icinde tanimli olmali.\n";
    echo "Opsiyonel: ADMIN_NAME\n";
    exit(1);
}

$pdo = Database::connection();
$users = new UserRepository($pdo);

$existing = $users->findActiveByEmail($email);
if ($existing !== null) {
    echo "Aktif kullanici zaten var: {$email}\n";
    exit(0);
}

$id = $users->createAdmin($name, $email, $password);
echo "Admin kullanici olusturuldu. ID: {$id}\n";
