<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/Repositories/MonitorRepository.php';

function test_pdo(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $schema = file_get_contents(__DIR__ . '/../database/schema.sql');
    if ($schema === false) {
        throw new RuntimeException('schema.sql okunamadi');
    }
    $pdo->exec($schema);
    return $pdo;
}

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function seed_monitor(PDO $pdo, string $name = 'API', int $active = 1): int
{
    $stmt = $pdo->prepare("
        INSERT INTO monitors (name, url, is_active, next_check_at, next_link_scan_at)
        VALUES (:name, :url, :is_active, '2026-05-12 10:00:00', '2026-05-12 10:00:00')
    ");
    $stmt->execute([
        'name' => $name,
        'url' => 'https://example.com/' . strtolower($name),
        'is_active' => $active,
    ]);
    return (int) $pdo->lastInsertId();
}

$pdo = test_pdo();
$repo = new MonitorRepository($pdo);

$createdAt = (new DateTimeImmutable('2026-05-12 12:00:00'))->format('Y-m-d H:i:s');
$repo->create([
    'name' => 'No Link Scan',
    'url' => 'https://example.com/health',
    'expected_status' => '200',
    'interval_seconds' => 300,
    'timeout_seconds' => 10,
    'response_warning_ms' => 3000,
    'fail_threshold' => 3,
    'recovery_threshold' => 2,
    'is_active' => 1,
    'link_scan_enabled' => 0,
    'link_scan_interval_seconds' => 21600,
    'link_scan_max_depth' => 1,
    'link_scan_max_urls' => 50,
    'next_check_at' => $createdAt,
    'next_link_scan_at' => null,
]);
$created = $repo->findById((int) $pdo->lastInsertId());
assert_true(is_array($created), 'Olusturulan monitor bulunmali');
assert_true((int) $created['link_scan_enabled'] === 0, 'Yeni monitor link scan kapali kaydedilebilmeli');
assert_true($created['next_link_scan_at'] === null, 'Link scan kapaliyken sonraki scan zamani bos olmali');

$archiveId = seed_monitor($pdo, 'ArchiveMe');
$repo->archiveById($archiveId);
$archived = $repo->findById($archiveId);
assert_true(is_array($archived), 'Arsivlenen monitor kaydi kalmali');
assert_true((int) $archived['is_active'] === 0, 'Arsivleme monitoru pasife almali');
assert_true($archived['archived_at'] !== null, 'Arsivleme archived_at degeri yazmali');
assert_true($archived['next_check_at'] === null, 'Arsivleme sonraki uptime kontrolunu temizlemeli');
assert_true($archived['next_link_scan_at'] === null, 'Arsivleme sonraki link scan kontrolunu temizlemeli');

$archivedRows = $repo->archivedMonitors(20);
assert_true(count($archivedRows) === 1 && (int) $archivedRows[0]['id'] === $archiveId, 'Arsiv listesi arsivlenen monitoru gostermeli');

$repo->restoreById($archiveId);
$restored = $repo->findById($archiveId);
assert_true(is_array($restored), 'Geri alinan monitor bulunmali');
assert_true($restored['archived_at'] === null, 'Geri alma archived_at degerini temizlemeli');
assert_true((int) $restored['is_active'] === 0, 'Geri alma monitoru otomatik aktif etmemeli');

$repo->setActiveById($archiveId, true);
$active = $repo->findById($archiveId);
assert_true(is_array($active) && (int) $active['is_active'] === 1, 'Monitor tekrar aktif edilebilmeli');
assert_true($active['next_check_at'] !== null, 'Aktif etme sonraki uptime kontrolunu zamanlamali');

$deleteId = seed_monitor($pdo, 'DeleteMe');
$pdo->prepare("INSERT INTO checks (monitor_id, checked_at, status) VALUES (:monitor_id, '2026-05-12 10:00:00', 'up')")
    ->execute(['monitor_id' => $deleteId]);
$repo->deleteById($deleteId);
assert_true($repo->findById($deleteId) === null, 'Kalici silme monitor kaydini kaldirmali');
$remainingChecks = (int) $pdo->query('SELECT COUNT(*) AS c FROM checks')->fetch()['c'];
assert_true($remainingChecks === 0, 'Kalici silme iliskili check kayitlarini temizlemeli');

echo "MonitorRepositoryActionsTest OK\n";
