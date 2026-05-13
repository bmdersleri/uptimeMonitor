<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_auth();

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
    exit;
}

$retentionDays = (int) ($_POST['retention_days'] ?? 30);
$allowed = [0, 7, 30, 90, 180, 365];
if (!in_array($retentionDays, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Geçersiz temizlik aralığı.']);
    exit;
}

$pdo = Database::connection();
if ($retentionDays <= 0) {
    $stmt = $pdo->prepare("DELETE FROM broken_links WHERE resolved_at IS NOT NULL");
    $stmt->execute();
    $deleted = $stmt->rowCount();
} else {
    $threshold = (new DateTimeImmutable('now'))
        ->sub(new DateInterval('P' . max(1, $retentionDays) . 'D'))
        ->format('Y-m-d H:i:s');
    $stmt = $pdo->prepare("
        DELETE FROM broken_links
        WHERE resolved_at IS NOT NULL
          AND resolved_at <= :threshold
    ");
    $stmt->execute(['threshold' => $threshold]);
    $deleted = $stmt->rowCount();
}

echo json_encode([
    'ok' => true,
    'message' => $deleted . ' resolved broken link kaydı silindi.',
    'deleted' => $deleted,
]);
