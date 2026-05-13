<?php

declare(strict_types=1);

final class UptimeChecker
{
    /** @var bool */
    private $sslVerify;

    public function __construct()
    {
        $this->sslVerify = ((string) config('HTTP_SSL_VERIFY', 'true')) === 'true';
    }

    /**
     * @param int[] $expectedStatusCodes
     * @return array{
     *     status:string,
     *     http_code:int,
     *     response_time_ms:int,
     *     error_message:string|null,
     *     final_url:string
     * }
     */
    public function check(
        string $url,
        int $timeoutSeconds,
        int $responseWarningMs,
        array $expectedStatusCodes
    ): array {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => $this->sslVerify ? 1 : 0,
            CURLOPT_SSL_VERIFYHOST => $this->sslVerify ? 2 : 0,
            CURLOPT_USERAGENT => 'EkontUptimeMonitor/0.1',
            CURLOPT_NOBODY => false,
        ]);

        $body = curl_exec($ch);

        $curlError = null;
        if ($body === false) {
            $curlError = curl_error($ch) ?: 'Unknown cURL error';
        }

        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $responseTimeMs = (int) round(((float) curl_getinfo($ch, CURLINFO_TOTAL_TIME)) * 1000);
        $finalUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        $status = 'down';
        if ($curlError !== null) {
            $status = 'down';
        } elseif (in_array($httpCode, $expectedStatusCodes, true)) {
            $status = $responseTimeMs > $responseWarningMs ? 'degraded' : 'up';
        } else {
            $status = 'down';
        }

        return [
            'status' => $status,
            'http_code' => $httpCode,
            'response_time_ms' => $responseTimeMs,
            'error_message' => $curlError,
            'final_url' => $finalUrl !== '' ? $finalUrl : $url,
        ];
    }
}
