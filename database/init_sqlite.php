<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

if ((string) config('DB_DRIVER', '') !== 'sqlite') {
    fwrite(STDERR, "[db] DB_DRIVER sqlite degil. .env ayarini kontrol edin.\n");
    exit(1);
}

$pdo = Database::connection();
$schemaPath = __DIR__ . '/schema.sql';
$schema = file_get_contents($schemaPath);

if ($schema === false) {
    fwrite(STDERR, "[db] schema.sql okunamadi.\n");
    exit(1);
}

$pdo->exec($schema);
echo "[db] SQLite schema hazir.\n";
