<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

final class FakeDepthLinkScanner extends LinkScanner
{
    /** @var array<string, string> */
    private $pages;

    /**
     * @param array<string, string> $pages
     */
    public function __construct(array $pages)
    {
        parent::__construct(3);
        $this->pages = $pages;
    }

    protected function fetchHtml(string $url): array
    {
        if (!array_key_exists($url, $this->pages)) {
            return [
                'ok' => false,
                'status_code' => 404,
                'error_type' => 'http',
                'error_message' => 'HTTP 404',
                'html' => '',
            ];
        }

        return [
            'ok' => true,
            'status_code' => 200,
            'error_type' => null,
            'error_message' => null,
            'html' => $this->pages[$url],
        ];
    }

    protected function checkResource(string $url): array
    {
        if (strpos($url, '/missing-image.png') !== false) {
            return [
                'ok' => false,
                'status_code' => 404,
                'error_type' => 'http',
                'error_message' => 'HTTP 404',
            ];
        }

        return [
            'ok' => true,
            'status_code' => 200,
            'error_type' => null,
            'error_message' => null,
        ];
    }
}

function assert_true_depth(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pages = [
    'https://example.com/index.html' => '<a href="/level1.html">Level 1</a><a href="https://external.test/out.html">External</a>',
    'https://example.com/level1.html' => '<a href="/level2.html">Level 2</a>',
    'https://example.com/level2.html' => '<a href="/level3.html">Level 3</a>',
    'https://example.com/level3.html' => '<img src="/missing-image.png" alt="missing">',
];

$scannerDepth1 = new FakeDepthLinkScanner($pages);
$resultDepth1 = $scannerDepth1->scan('https://example.com/index.html', 1, 20);
$targetsDepth1 = array_map(static function (array $row): string {
    return (string) $row['target_url'];
}, (array) $resultDepth1['discovered']);

assert_true_depth(in_array('https://example.com/level1.html', $targetsDepth1, true), 'Depth 1 should discover level 1');
assert_true_depth(in_array('https://example.com/level2.html', $targetsDepth1, true), 'Depth 1 should check links on level 1');
assert_true_depth(!in_array('https://example.com/level3.html', $targetsDepth1, true), 'Depth 1 should not crawl level 2 links');
assert_true_depth(!in_array('https://external.test/out.html', $targetsDepth1, true), 'Scanner should ignore external hosts');

$scannerDepth3 = new FakeDepthLinkScanner($pages);
$resultDepth3 = $scannerDepth3->scan('https://example.com/index.html', 3, 20);
$targetsDepth3 = array_map(static function (array $row): string {
    return (string) $row['target_url'];
}, (array) $resultDepth3['discovered']);

assert_true_depth(in_array('https://example.com/level3.html', $targetsDepth3, true), 'Depth 3 should discover level 3');
assert_true_depth(in_array('https://example.com/missing-image.png', $targetsDepth3, true), 'Depth 3 should scan resources found on deeper pages');
assert_true_depth((int) $resultDepth3['broken_urls'] === 1, 'Depth 3 should report the missing image as broken');

echo "LinkScannerDepthTest OK\n";
