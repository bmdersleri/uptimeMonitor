<?php

declare(strict_types=1);

final class TelegramClient
{
    /**
     * @return array{status: string, error: string|null}
     */
    public function sendMessage(string $token, string $chatId, string $message): array
    {
        if ($token === '' || $chatId === '') {
            return ['status' => 'failed', 'error' => 'Telegram config missing'];
        }
        if (!function_exists('curl_init')) {
            return ['status' => 'failed', 'error' => 'PHP curl extension is not available'];
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->buildEndpoint($token),
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'chat_id' => $chatId,
                'text' => $message,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 12,
        ]);

        $body = curl_exec($ch);
        $error = $body === false ? curl_error($ch) : null;
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $ok = $error === null && $httpCode >= 200 && $httpCode < 300;

        return [
            'status' => $ok ? 'sent' : 'failed',
            'error' => $ok ? null : ($error ?: 'Telegram HTTP ' . $httpCode),
        ];
    }

    public function buildEndpoint(string $token): string
    {
        return 'https://api.telegram.org/bot' . $token . '/sendMessage';
    }
}
