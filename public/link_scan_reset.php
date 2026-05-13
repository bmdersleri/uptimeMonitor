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

try {
    $resetter = new LinkScanResetter(Database::connection());
    $result = $resetter->resetAll();

    echo json_encode([
        'ok' => true,
        'message' => 'Tüm link scan jobları ve tarama sonuçları sıfırlandı.',
        'result' => $result,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'Link scan verileri sıfırlanamadı: ' . $e->getMessage(),
    ]);
}
