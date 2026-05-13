<?php

declare(strict_types=1);

class LinkScanner
{
    /** @var int */
    private $timeoutSeconds;

    /** @var int */
    private $requestTimeoutSeconds;

    /** @var int */
    private $concurrency;

    /** @var int */
    private $batchSize;

    /** @var array<string, array<string, mixed>> */
    private $resourceCache = [];

    public function __construct(int $timeoutSeconds = 10)
    {
        $this->timeoutSeconds = max(3, $timeoutSeconds);
        $this->requestTimeoutSeconds = max(2, (int) config('DEFAULT_LINK_SCAN_REQUEST_TIMEOUT_SECONDS', (string) $this->timeoutSeconds));
        $this->concurrency = max(1, min(10, (int) config('DEFAULT_LINK_SCAN_CONCURRENCY', '5')));
        $this->batchSize = max(1, min(20, (int) config('LINK_SCAN_BATCH_SIZE', (string) $this->concurrency)));
    }

    /**
     * @return array<string, mixed>
     */
    public function scan(string $baseUrl, int $maxDepth = 3, int $maxUrls = 120, $onProgress = null): array
    {
        $maxDepth = max(0, $maxDepth);
        $maxUrls = max(10, $maxUrls);

        $baseHost = (string) parse_url($baseUrl, PHP_URL_HOST);
        if ($baseHost === '') {
            return [
                'total_urls' => 0,
                'checked_urls' => 0,
                'broken_urls' => 0,
                'discovered' => [],
                'broken' => [],
            ];
        }

        $queue = [
            ['url' => $baseUrl, 'depth' => 0, 'source' => $baseUrl],
        ];
        $visitedPages = [];
        $discovered = [];
        $broken = [];
        $pageCount = 0;
        $checkedCount = 0;
        $brokenCount = 0;

        while ($queue !== [] && $pageCount < $maxUrls) {
            $item = array_shift($queue);
            if (!is_array($item)) {
                continue;
            }

            $currentUrl = (string) ($item['url'] ?? '');
            $depth = (int) ($item['depth'] ?? 0);

            if ($currentUrl === '' || isset($visitedPages[$currentUrl])) {
                continue;
            }

            $visitedPages[$currentUrl] = true;
            $pageCount++;
            if ($onProgress !== null) {
                $onProgress([
                    'type' => 'page_start',
                    'current_url' => $currentUrl,
                    'depth' => $depth,
                    'page_count' => $pageCount,
                    'checked_count' => $checkedCount,
                    'broken_count' => $brokenCount,
                ]);
            }

            if ($onProgress !== null) {
                $onProgress([
                    'type' => 'page_fetch_start',
                    'current_url' => $currentUrl,
                    'depth' => $depth,
                    'page_count' => $pageCount,
                    'checked_count' => $checkedCount,
                    'broken_count' => $brokenCount,
                    'queue_size' => count($queue),
                ]);
            }

            $pageFetch = $this->fetchHtml($currentUrl);
            if ($onProgress !== null) {
                $onProgress([
                    'type' => 'page_fetch_done',
                    'current_url' => $currentUrl,
                    'depth' => $depth,
                    'page_count' => $pageCount,
                    'checked_count' => $checkedCount,
                    'broken_count' => $brokenCount,
                    'queue_size' => count($queue),
                    'ok' => (bool) ($pageFetch['ok'] ?? false),
                    'status_code' => (int) ($pageFetch['status_code'] ?? 0),
                    'error_message' => (string) ($pageFetch['error_message'] ?? ''),
                ]);
            }
            if (!$pageFetch['ok']) {
                $broken[] = [
                    'source_url' => $currentUrl,
                    'target_url' => $currentUrl,
                    'link_type' => 'page',
                    'status_code' => (int) $pageFetch['status_code'],
                    'error_type' => $pageFetch['error_type'],
                    'error_message' => $pageFetch['error_message'],
                ];
                $brokenCount++;
                $checkedCount++;
                if ($onProgress !== null) {
                    $onProgress([
                        'type' => 'resource_checked',
                        'source_url' => $currentUrl,
                        'target_url' => $currentUrl,
                        'ok' => false,
                        'status_code' => (int) $pageFetch['status_code'],
                        'error_message' => (string) ($pageFetch['error_message'] ?? ''),
                        'page_count' => $pageCount,
                        'checked_count' => $checkedCount,
                        'broken_count' => $brokenCount,
                    ]);
                }
                continue;
            }

            $links = $this->extractResources((string) $pageFetch['html']);
            $candidates = [];
            $candidateKeys = [];
            foreach ($links as $lnk) {
                $rawTarget = (string) ($lnk['url'] ?? '');
                $linkType = (string) ($lnk['type'] ?? 'other');
                $normalized = $this->normalizeUrl($rawTarget, $currentUrl);

                if ($normalized === null) {
                    continue;
                }

                if (!$this->isInternal($normalized, $baseHost)) {
                    continue;
                }

                $candidateKey = $linkType . '|' . $normalized;
                if (isset($candidateKeys[$candidateKey])) {
                    continue;
                }
                $candidateKeys[$candidateKey] = true;

                $candidates[] = [
                    'url' => $normalized,
                    'type' => $linkType,
                ];
            }

            if ($onProgress !== null) {
                $onProgress([
                    'type' => 'page_resources_found',
                    'current_url' => $currentUrl,
                    'depth' => $depth,
                    'page_count' => $pageCount,
                    'resource_count' => count($candidates),
                    'checked_count' => $checkedCount,
                    'broken_count' => $brokenCount,
                ]);
            }

            $candidateChunks = array_chunk($candidates, $this->batchSize);
            $batchTotal = count($candidateChunks);
            foreach ($candidateChunks as $batchIndex => $candidateChunk) {
                $resourceUrls = [];
                foreach ($candidateChunk as $candidate) {
                    $resourceUrls[(string) $candidate['url']] = (string) $candidate['url'];
                }

                if ($onProgress !== null) {
                    $firstCandidate = reset($candidateChunk);
                    $onProgress([
                        'type' => 'resource_batch_start',
                        'current_url' => $currentUrl,
                        'target_url' => is_array($firstCandidate) ? (string) ($firstCandidate['url'] ?? '') : '',
                        'depth' => $depth,
                        'page_count' => $pageCount,
                        'checked_count' => $checkedCount,
                        'broken_count' => $brokenCount,
                        'resource_count' => count($candidateChunk),
                        'batch_index' => $batchIndex + 1,
                        'batch_total' => $batchTotal,
                        'queue_size' => count($queue),
                    ]);
                }

                $checks = $this->checkResources(array_values($resourceUrls));
                foreach ($candidateChunk as $candidate) {
                    $normalized = (string) $candidate['url'];
                    $linkType = (string) $candidate['type'];
                    $check = $checks[$normalized] ?? $this->checkResource($normalized);
                    $statusName = $check['ok'] ? 'ok' : 'broken';
                    $checkedCount++;

                    $discovered[] = [
                        'source_url' => $currentUrl,
                        'target_url' => $normalized,
                        'link_type' => $linkType,
                        'last_status_code' => (int) $check['status_code'],
                        'last_status' => $statusName,
                        'last_error' => $check['error_message'],
                    ];

                    if (!$check['ok']) {
                        $broken[] = [
                            'source_url' => $currentUrl,
                            'target_url' => $normalized,
                            'link_type' => $linkType,
                            'status_code' => (int) $check['status_code'],
                            'error_type' => $check['error_type'],
                            'error_message' => $check['error_message'],
                        ];
                        $brokenCount++;
                    }

                    if ($onProgress !== null) {
                        $onProgress([
                            'type' => 'resource_checked',
                            'source_url' => $currentUrl,
                            'target_url' => $normalized,
                            'ok' => (bool) $check['ok'],
                            'status_code' => (int) $check['status_code'],
                            'error_message' => (string) ($check['error_message'] ?? ''),
                            'page_count' => $pageCount,
                            'checked_count' => $checkedCount,
                            'broken_count' => $brokenCount,
                        ]);
                    }

                    if ($linkType === 'page' && $depth < $maxDepth && !isset($visitedPages[$normalized])) {
                        $queue[] = [
                            'url' => $normalized,
                            'depth' => $depth + 1,
                            'source' => $currentUrl,
                        ];
                    }
                }

                if ($onProgress !== null) {
                    $onProgress([
                        'type' => 'resource_batch_done',
                        'current_url' => $currentUrl,
                        'depth' => $depth,
                        'page_count' => $pageCount,
                        'checked_count' => $checkedCount,
                        'broken_count' => $brokenCount,
                        'resource_count' => count($candidateChunk),
                        'batch_index' => $batchIndex + 1,
                        'batch_total' => $batchTotal,
                        'queue_size' => count($queue),
                    ]);
                }
            }
        }

        return [
            'total_urls' => count($visitedPages),
            'checked_urls' => count($this->resourceCache),
            'broken_urls' => count($broken),
            'discovered' => $discovered,
            'broken' => $broken,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function fetchHtml(string $url): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_USERAGENT => 'EkontUptimeMonitorLinkScanner/0.1',
            CURLOPT_SSL_VERIFYPEER => ((string) config('HTTP_SSL_VERIFY', 'true')) === 'true' ? 1 : 0,
            CURLOPT_SSL_VERIFYHOST => ((string) config('HTTP_SSL_VERIFY', 'true')) === 'true' ? 2 : 0,
        ]);

        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = $body === false ? (curl_error($ch) ?: 'curl error') : null;
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($error !== null) {
            return [
                'ok' => false,
                'status_code' => $httpCode,
                'error_type' => 'network',
                'error_message' => $error,
                'html' => '',
            ];
        }

        if ($httpCode >= 400 || $httpCode === 0) {
            return [
                'ok' => false,
                'status_code' => $httpCode,
                'error_type' => 'http',
                'error_message' => 'HTTP ' . $httpCode,
                'html' => '',
            ];
        }

        if (stripos($contentType, 'text/html') === false && stripos($contentType, 'application/xhtml+xml') === false) {
            return [
                'ok' => true,
                'status_code' => $httpCode,
                'error_type' => null,
                'error_message' => null,
                'html' => '',
            ];
        }

        return [
            'ok' => true,
            'status_code' => $httpCode,
            'error_type' => null,
            'error_message' => null,
            'html' => is_string($body) ? $body : '',
        ];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function extractResources(string $html): array
    {
        if ($html === '') {
            return [];
        }

        $resources = [];
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $loaded = $dom->loadHTML($html);
        libxml_clear_errors();

        if (!$loaded) {
            return [];
        }

        $map = [
            'a' => ['href', 'page'],
            'img' => ['src', 'image'],
            'script' => ['src', 'js'],
            'link' => ['href', 'css'],
            'iframe' => ['src', 'iframe'],
        ];

        foreach ($map as $tag => $def) {
            $attr = $def[0];
            $type = $def[1];
            $nodes = $dom->getElementsByTagName($tag);
            foreach ($nodes as $node) {
                $url = trim((string) $node->getAttribute($attr));
                if ($url === '') {
                    continue;
                }
                $resources[] = [
                    'url' => $url,
                    'type' => $type,
                ];
            }
        }

        return $resources;
    }

    private function normalizeUrl(string $rawUrl, string $currentUrl): ?string
    {
        $rawUrl = trim($rawUrl);
        if ($rawUrl === '') {
            return null;
        }

        if (preg_match('/[\x00-\x1F\x7F\s]/u', $rawUrl)) {
            return null;
        }

        $lower = strtolower($rawUrl);
        if (
            strpos($lower, 'mailto:') === 0 ||
            strpos($lower, 'tel:') === 0 ||
            strpos($lower, 'javascript:') === 0 ||
            strpos($lower, 'data:') === 0 ||
            strpos($lower, 'about:') === 0 ||
            strpos($lower, 'whatsapp:') === 0 ||
            strpos($lower, 'intent:') === 0 ||
            strpos($lower, 'tg:') === 0 ||
            strpos($lower, 'viber:') === 0 ||
            strpos($lower, 'skype:') === 0 ||
            strpos($rawUrl, '#') === 0
        ) {
            return null;
        }

        if (preg_match('#^https?://#i', $rawUrl)) {
            $normalized = $this->stripFragment($rawUrl);
            return $this->shouldSkipNormalizedUrl($normalized) ? null : $normalized;
        }

        if (strpos($rawUrl, '//') === 0) {
            $scheme = (string) parse_url($currentUrl, PHP_URL_SCHEME);
            if ($scheme === '') {
                $scheme = 'https';
            }
            $normalized = $this->stripFragment($scheme . ':' . $rawUrl);
            return $this->shouldSkipNormalizedUrl($normalized) ? null : $normalized;
        }

        $base = parse_url($currentUrl);
        if (!is_array($base)) {
            return null;
        }

        $scheme = (string) ($base['scheme'] ?? 'https');
        $host = (string) ($base['host'] ?? '');
        if ($host === '') {
            return null;
        }

        if (strpos($rawUrl, '/') === 0) {
            $normalized = $this->stripFragment($scheme . '://' . $host . $rawUrl);
            return $this->shouldSkipNormalizedUrl($normalized) ? null : $normalized;
        }

        $path = (string) ($base['path'] ?? '/');
        $dir = rtrim(str_replace('\\', '/', dirname($path)), '/');
        if ($dir === '' || $dir === '.') {
            $dir = '';
        }

        $normalized = $this->stripFragment($scheme . '://' . $host . $dir . '/' . $rawUrl);
        return $this->shouldSkipNormalizedUrl($normalized) ? null : $normalized;
    }

    private function stripFragment(string $url): string
    {
        $parts = explode('#', $url, 2);
        return $parts[0];
    }

    private function shouldSkipNormalizedUrl(string $url): bool
    {
        $lower = strtolower($url);
        if (strpos($lower, 'about:blank') !== false) {
            return true;
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return true;
        }

        $path = strtolower((string) ($parts['path'] ?? ''));
        if (strpos($path, '/whatsapp/send') !== false || strpos($path, '/whatsapp//send') !== false) {
            return true;
        }

        $query = (string) ($parts['query'] ?? '');
        if ($query !== '') {
            if (strlen($query) > 700) {
                return true;
            }

            if (preg_match('/(?:^|&)(text|message)=/i', $query) === 1 && preg_match('/https?:\\/\\//i', $query) === 1) {
                return true;
            }
        }

        return false;
    }

    private function isInternal(string $url, string $baseHost): bool
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        return $host !== '' && strtolower($host) === strtolower($baseHost);
    }

    /**
     * @param array<int, string> $urls
     * @return array<string, array<string, mixed>>
     */
    protected function checkResources(array $urls): array
    {
        $results = [];
        $pending = [];

        foreach ($urls as $url) {
            $url = (string) $url;
            if ($url === '') {
                continue;
            }

            if (isset($this->resourceCache[$url])) {
                $results[$url] = $this->resourceCache[$url];
                continue;
            }

            $pending[$url] = $url;
        }

        if ($pending === []) {
            return $results;
        }

        $checkResourceMethod = new ReflectionMethod($this, 'checkResource');
        if ($checkResourceMethod->getDeclaringClass()->getName() !== __CLASS__) {
            foreach ($pending as $url) {
                $result = $this->checkResource($url);
                $this->resourceCache[$url] = $result;
                $results[$url] = $result;
            }

            return $results;
        }

        if ($this->concurrency <= 1 || count($pending) === 1 || !function_exists('curl_multi_init')) {
            foreach ($pending as $url) {
                $result = $this->headOrGet($url);
                $this->resourceCache[$url] = $result;
                $results[$url] = $result;
            }

            return $results;
        }

        $headResults = $this->multiRequest(array_values($pending), true);
        $fallbackUrls = [];
        foreach ($headResults as $url => $result) {
            if ($result['ok'] || $result['status_code'] >= 200) {
                $this->resourceCache[$url] = $result;
                $results[$url] = $result;
                continue;
            }

            $fallbackUrls[] = $url;
        }

        foreach ($this->multiRequest($fallbackUrls, false) as $url => $result) {
            $this->resourceCache[$url] = $result;
            $results[$url] = $result;
        }

        return $results;
    }

    /**
     * @return array<string, mixed>
     */
    protected function checkResource(string $url): array
    {
        if (isset($this->resourceCache[$url])) {
            return $this->resourceCache[$url];
        }

        $result = $this->headOrGet($url);
        $this->resourceCache[$url] = $result;
        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function headOrGet(string $url): array
    {
        $head = $this->request($url, true);
        if ($head['ok'] || $head['status_code'] >= 200) {
            return $head;
        }
        return $this->request($url, false);
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $url, bool $headOnly): array
    {
        $ch = $this->createCurlHandle($url, $headOnly);
        $body = curl_exec($ch);
        $result = $this->buildRequestResult($ch, $body === false);
        curl_close($ch);

        return $result;
    }

    /**
     * @param array<int, string> $urls
     * @return array<string, array<string, mixed>>
     */
    private function multiRequest(array $urls, bool $headOnly): array
    {
        $results = [];
        $chunks = array_chunk($urls, $this->concurrency);

        foreach ($chunks as $chunk) {
            $multi = curl_multi_init();
            $handles = [];

            foreach ($chunk as $index => $url) {
                $ch = $this->createCurlHandle($url, $headOnly);
                $handles[$index] = [
                    'handle' => $ch,
                    'url' => $url,
                ];
                curl_multi_add_handle($multi, $ch);
            }

            $running = null;
            do {
                $status = curl_multi_exec($multi, $running);
                if ($running > 0) {
                    $selected = curl_multi_select($multi, 1.0);
                    if ($selected === -1) {
                        usleep(10000);
                    }
                }
            } while ($running > 0 && $status === CURLM_OK);

            foreach ($handles as $entry) {
                $ch = $entry['handle'];
                $url = (string) $entry['url'];
                $results[$url] = $this->buildRequestResult($ch, false);
                curl_multi_remove_handle($multi, $ch);
                curl_close($ch);
            }

            curl_multi_close($multi);
        }

        return $results;
    }

    /**
     * @return resource
     */
    private function createCurlHandle(string $url, bool $headOnly)
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => min(3, $this->requestTimeoutSeconds),
            CURLOPT_TIMEOUT => $this->requestTimeoutSeconds,
            CURLOPT_USERAGENT => 'EkontUptimeMonitorLinkScanner/0.1',
            CURLOPT_NOBODY => $headOnly ? 1 : 0,
            CURLOPT_SSL_VERIFYPEER => ((string) config('HTTP_SSL_VERIFY', 'true')) === 'true' ? 1 : 0,
            CURLOPT_SSL_VERIFYHOST => ((string) config('HTTP_SSL_VERIFY', 'true')) === 'true' ? 2 : 0,
        ]);

        return $ch;
    }

    /**
     * @param resource $ch
     * @return array<string, mixed>
     */
    private function buildRequestResult($ch, bool $execFailed): array
    {
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = $execFailed || curl_errno($ch) !== 0 ? (curl_error($ch) ?: 'curl error') : null;

        if ($error !== null) {
            return [
                'ok' => false,
                'status_code' => $httpCode,
                'error_type' => 'network',
                'error_message' => $error,
            ];
        }

        if ($httpCode === 0 || $httpCode >= 400) {
            return [
                'ok' => false,
                'status_code' => $httpCode,
                'error_type' => 'http',
                'error_message' => 'HTTP ' . $httpCode,
            ];
        }

        return [
            'ok' => true,
            'status_code' => $httpCode,
            'error_type' => null,
            'error_message' => null,
        ];
    }
}
