<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

final class BatchTrackingLinkScanner extends LinkScanner
{
    /** @var array<int, int> */
    public $batchSizes = [];

    protected function fetchHtml(string $url): array
    {
        return [
            'ok' => true,
            'status_code' => 200,
            'error_type' => null,
            'error_message' => null,
            'html' => implode('', [
                '<a href="/about.html">About</a>',
                '<img src="/assets/logo.png" alt="Logo">',
                '<script src="/assets/app.js"></script>',
            ]),
        ];
    }

    /**
     * @param array<int, string> $urls
     * @return array<string, array<string, mixed>>
     */
    protected function checkResources(array $urls): array
    {
        $this->batchSizes[] = count($urls);

        $results = [];
        foreach ($urls as $url) {
            $results[$url] = [
                'ok' => true,
                'status_code' => 200,
                'error_type' => null,
                'error_message' => null,
            ];
        }

        return $results;
    }
}

final class ManyLinkBatchTrackingScanner extends LinkScanner
{
    /** @var array<int, int> */
    public $batchSizes = [];

    protected function fetchHtml(string $url): array
    {
        $html = '';
        for ($i = 1; $i <= 12; $i++) {
            $html .= '<a href="/page-' . $i . '.html">Page ' . $i . '</a>';
        }
        $html .= '<a href="/page-1.html">Duplicate Page 1</a>';

        return [
            'ok' => true,
            'status_code' => 200,
            'error_type' => null,
            'error_message' => null,
            'html' => $html,
        ];
    }

    /**
     * @param array<int, string> $urls
     * @return array<string, array<string, mixed>>
     */
    protected function checkResources(array $urls): array
    {
        $this->batchSizes[] = count($urls);

        $results = [];
        foreach ($urls as $url) {
            $results[$url] = [
                'ok' => true,
                'status_code' => 200,
                'error_type' => null,
                'error_message' => null,
            ];
        }

        return $results;
    }
}

function assert_true_batch(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$scanner = new BatchTrackingLinkScanner(3);
$result = $scanner->scan('https://example.com/index.html', 0, 20);

assert_true_batch(count((array) $result['discovered']) === 3, 'The scanner should discover three resources');
assert_true_batch($scanner->batchSizes === [3], 'Resources from one page should be checked as one batch');

$manyScanner = new ManyLinkBatchTrackingScanner(3);
$manyResult = $manyScanner->scan('https://example.com/index.html', 0, 20);
assert_true_batch(count((array) $manyResult['discovered']) === 12, 'Duplicate URLs on one page should be reported once');
assert_true_batch($manyScanner->batchSizes === [10, 2], 'Large pages should be checked in smaller batches for live progress');

echo "LinkScannerBatchCheckTest OK\n";
