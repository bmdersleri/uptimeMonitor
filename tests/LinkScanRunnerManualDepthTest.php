<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

final class CapturingLinkScanner extends LinkScanner
{
    /** @var int|null */
    public $lastDepth = null;

    /** @var int|null */
    public $lastMaxUrls = null;

    public function scan(string $baseUrl, int $maxDepth = 2, int $maxUrls = 120, $onProgress = null): array
    {
        $this->lastDepth = $maxDepth;
        $this->lastMaxUrls = $maxUrls;

        if ($onProgress !== null) {
            $onProgress([
                'type' => 'page_start',
                'current_url' => $baseUrl,
                'depth' => 0,
                'page_count' => 1,
                'checked_count' => 0,
                'broken_count' => 0,
            ]);
        }

        return [
            'total_urls' => 1,
            'checked_urls' => 0,
            'broken_urls' => 0,
            'discovered' => [],
            'broken' => [],
        ];
    }
}

function assert_true_manual_depth(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA foreign_keys = ON');
$schema = file_get_contents(__DIR__ . '/../database/schema.sql');
if ($schema === false) {
    throw new RuntimeException('schema.sql okunamadi');
}
$pdo->exec($schema);

$pdo->prepare("
    INSERT INTO monitors (
        name, url, expected_status, is_active, link_scan_enabled, link_scan_max_depth, link_scan_max_urls, next_link_scan_at
    ) VALUES (
        'Manual Depth', 'https://example.com', '200', 1, 1, 3, 120, datetime('now')
    )
")->execute();
$monitorId = (int) $pdo->lastInsertId();

$scanner = new CapturingLinkScanner(3);
$runner = new LinkScanRunner($pdo, $scanner, new Notifier($pdo));
$result = $runner->runMonitorById($monitorId, 'manual', 5);

assert_true_manual_depth((bool) $result['ok'] === true, 'Manual scan should complete');
assert_true_manual_depth($scanner->lastDepth === 5, 'Manual scan depth override should be passed to scanner');
assert_true_manual_depth($scanner->lastMaxUrls === 120, 'Manual scan should keep monitor max URL setting');

$monitor = $pdo->query('SELECT link_scan_max_depth FROM monitors WHERE id = ' . $monitorId)->fetch();
assert_true_manual_depth(is_array($monitor) && (int) $monitor['link_scan_max_depth'] === 3, 'Manual scan depth override should not overwrite monitor defaults');

echo "LinkScanRunnerManualDepthTest OK\n";
