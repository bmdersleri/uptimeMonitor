<?php

declare(strict_types=1);

final class MailClient
{
    /**
     * @return array{status: string, error: string|null, transport: string}
     */
    public function sendConfigured(string $to, string $subject, string $plainBody, ?string $htmlBody = null): array
    {
        $smtpHost = trim((string) config('SMTP_HOST', ''));
        if ($smtpHost !== '') {
            return $this->sendSmtpConfigured($to, $subject, $plainBody, $htmlBody);
        }

        return $this->sendPhpMailConfigured($to, $subject, $plainBody, $htmlBody);
    }

    /**
     * @return array{headers: array<int, string>, body: string}
     */
    public function composeMessage(string $fromEmail, string $fromName, string $to, string $subject, string $plainBody, ?string $htmlBody = null): array
    {
        $headers = [
            'From: ' . $this->formatAddress($fromEmail, $fromName),
            'To: ' . $to,
            'Subject: ' . $subject,
            'MIME-Version: 1.0',
        ];

        if (is_string($htmlBody) && $htmlBody !== '') {
            $boundary = '=_uptime_mail_' . bin2hex(random_bytes(12));
            $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
            $body = "--{$boundary}\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: 8bit\r\n\r\n"
                . $plainBody . "\r\n"
                . "--{$boundary}\r\n"
                . "Content-Type: text/html; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: 8bit\r\n\r\n"
                . $htmlBody . "\r\n"
                . "--{$boundary}--";
            return ['headers' => $headers, 'body' => $body];
        }

        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: 8bit';

        return ['headers' => $headers, 'body' => $plainBody];
    }

    /**
     * @return array{status: string, error: string|null, transport: string}
     */
    private function sendPhpMailConfigured(string $to, string $subject, string $plainBody, ?string $htmlBody): array
    {
        $fromEmail = $this->resolveFromEmail();
        $fromName = (string) config('NOTIFY_EMAIL_FROM_NAME', (string) config('APP_NAME', 'Uptime Monitor'));
        $message = $this->composeMessage($fromEmail, $fromName, $to, $subject, $plainBody, $htmlBody);
        $headers = array_values(array_filter($message['headers'], static function (string $header): bool {
            return strpos($header, 'To: ') !== 0 && strpos($header, 'Subject: ') !== 0;
        }));

        $ok = @mail($to, $subject, $message['body'], implode("\r\n", $headers));

        return [
            'status' => $ok ? 'sent' : 'failed',
            'error' => $ok ? null : 'mail() returned false; configure SMTP_HOST in Notifications',
            'transport' => 'mail',
        ];
    }

    /**
     * @return array{status: string, error: string|null, transport: string}
     */
    private function sendSmtpConfigured(string $to, string $subject, string $plainBody, ?string $htmlBody): array
    {
        $smtpHost = trim((string) config('SMTP_HOST', ''));
        $smtpPort = (int) config('SMTP_PORT', 587);
        $smtpEncryption = strtolower(trim((string) config('SMTP_ENCRYPTION', 'tls')));
        $smtpTimeout = max(5, (int) config('SMTP_TIMEOUT_SECONDS', 15));
        $smtpUser = (string) config('SMTP_USERNAME', '');
        $smtpPass = (string) config('SMTP_PASSWORD', '');
        $fromEmail = $this->resolveFromEmail();
        $fromName = (string) config('NOTIFY_EMAIL_FROM_NAME', (string) config('APP_NAME', 'Uptime Monitor'));
        $message = $this->composeMessage($fromEmail, $fromName, $to, $subject, $plainBody, $htmlBody);
        $payload = implode("\r\n", $message['headers']) . "\r\n\r\n" . $message['body'];

        $remoteTarget = $smtpEncryption === 'ssl'
            ? 'ssl://' . $smtpHost . ':' . $smtpPort
            : $smtpHost . ':' . $smtpPort;

        $socket = @stream_socket_client($remoteTarget, $errno, $errstr, $smtpTimeout, STREAM_CLIENT_CONNECT);
        if (!is_resource($socket)) {
            return [
                'status' => 'failed',
                'error' => 'SMTP connect failed: ' . ($errstr !== '' ? $errstr : 'errno ' . $errno),
                'transport' => 'smtp',
            ];
        }

        stream_set_timeout($socket, $smtpTimeout);

        $greeting = $this->readResponse($socket);
        if (!$this->isExpected($greeting['code'], [220])) {
            fclose($socket);
            return ['status' => 'failed', 'error' => 'SMTP greeting rejected: ' . $greeting['message'], 'transport' => 'smtp'];
        }

        $heloHost = $this->smtpHeloHost();
        $ehlo = $this->sendCommand($socket, 'EHLO ' . $heloHost);
        if (!$this->isExpected($ehlo['code'], [250])) {
            $helo = $this->sendCommand($socket, 'HELO ' . $heloHost);
            if (!$this->isExpected($helo['code'], [250])) {
                fclose($socket);
                return ['status' => 'failed', 'error' => 'SMTP EHLO/HELO failed: ' . $helo['message'], 'transport' => 'smtp'];
            }
        }

        if ($smtpEncryption === 'tls') {
            $startTls = $this->sendCommand($socket, 'STARTTLS');
            if (!$this->isExpected($startTls['code'], [220])) {
                fclose($socket);
                return ['status' => 'failed', 'error' => 'SMTP STARTTLS failed: ' . $startTls['message'], 'transport' => 'smtp'];
            }

            $cryptoMethod = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (!stream_socket_enable_crypto($socket, true, $cryptoMethod)) {
                fclose($socket);
                return ['status' => 'failed', 'error' => 'SMTP TLS negotiation failed', 'transport' => 'smtp'];
            }

            $ehlo = $this->sendCommand($socket, 'EHLO ' . $heloHost);
            if (!$this->isExpected($ehlo['code'], [250])) {
                fclose($socket);
                return ['status' => 'failed', 'error' => 'SMTP EHLO after STARTTLS failed: ' . $ehlo['message'], 'transport' => 'smtp'];
            }
        }

        if ($smtpUser !== '') {
            $auth = $this->sendCommand($socket, 'AUTH LOGIN');
            if (!$this->isExpected($auth['code'], [334])) {
                fclose($socket);
                return ['status' => 'failed', 'error' => 'SMTP AUTH LOGIN failed: ' . $auth['message'], 'transport' => 'smtp'];
            }

            $user = $this->sendCommand($socket, base64_encode($smtpUser));
            if (!$this->isExpected($user['code'], [334])) {
                fclose($socket);
                return ['status' => 'failed', 'error' => 'SMTP username rejected: ' . $user['message'], 'transport' => 'smtp'];
            }

            $pass = $this->sendCommand($socket, base64_encode($smtpPass));
            if (!$this->isExpected($pass['code'], [235])) {
                fclose($socket);
                return ['status' => 'failed', 'error' => 'SMTP password rejected: ' . $pass['message'], 'transport' => 'smtp'];
            }
        }

        $mailFrom = $this->sendCommand($socket, 'MAIL FROM:<' . $fromEmail . '>');
        if (!$this->isExpected($mailFrom['code'], [250])) {
            fclose($socket);
            return ['status' => 'failed', 'error' => 'SMTP MAIL FROM rejected: ' . $mailFrom['message'], 'transport' => 'smtp'];
        }

        $rcptTo = $this->sendCommand($socket, 'RCPT TO:<' . $to . '>');
        if (!$this->isExpected($rcptTo['code'], [250, 251])) {
            fclose($socket);
            return ['status' => 'failed', 'error' => 'SMTP RCPT TO rejected: ' . $rcptTo['message'], 'transport' => 'smtp'];
        }

        $data = $this->sendCommand($socket, 'DATA');
        if (!$this->isExpected($data['code'], [354])) {
            fclose($socket);
            return ['status' => 'failed', 'error' => 'SMTP DATA rejected: ' . $data['message'], 'transport' => 'smtp'];
        }

        $messageBody = $this->dotStuff($payload);
        fwrite($socket, $messageBody . "\r\n.\r\n");
        $final = $this->readResponse($socket);

        $quit = $this->sendCommand($socket, 'QUIT');
        fclose($socket);

        if (!$this->isExpected($final['code'], [250])) {
            return ['status' => 'failed', 'error' => 'SMTP delivery failed: ' . $final['message'], 'transport' => 'smtp'];
        }

        if (!$this->isExpected($quit['code'], [221, 250])) {
            return ['status' => 'sent', 'error' => null, 'transport' => 'smtp'];
        }

        return ['status' => 'sent', 'error' => null, 'transport' => 'smtp'];
    }

    private function resolveFromEmail(): string
    {
        $from = trim((string) config('NOTIFY_EMAIL_FROM', ''));
        if ($from !== '') {
            return $from;
        }

        $user = trim((string) config('SMTP_USERNAME', ''));
        if ($user !== '' && filter_var($user, FILTER_VALIDATE_EMAIL) !== false) {
            return $user;
        }

        $fallback = trim((string) config('NOTIFY_EMAIL_TO', ''));
        if ($fallback !== '' && filter_var($fallback, FILTER_VALIDATE_EMAIL) !== false) {
            return $fallback;
        }

        return 'noreply@localhost';
    }

    private function smtpHeloHost(): string
    {
        $host = parse_url((string) config('APP_URL', ''), PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            return $host;
        }

        $server = (string) ($_SERVER['SERVER_NAME'] ?? '');
        if ($server !== '') {
            return $server;
        }

        $hostname = gethostname();
        if (is_string($hostname) && $hostname !== '') {
            return $hostname;
        }

        return 'localhost';
    }

    /**
     * @return array{code: int, message: string}
     */
    private function sendCommand($socket, string $command): array
    {
        fwrite($socket, $command . "\r\n");
        return $this->readResponse($socket);
    }

    /**
     * @return array{code: int, message: string}
     */
    private function readResponse($socket): array
    {
        $lines = [];
        $code = 0;

        while (!feof($socket)) {
            $line = fgets($socket, 515);
            if ($line === false) {
                break;
            }

            $line = rtrim($line, "\r\n");
            $lines[] = $line;
            if (preg_match('/^(\d{3})([ -])(.*)$/', $line, $matches) === 1) {
                $code = (int) $matches[1];
                if ($matches[2] === ' ') {
                    break;
                }
            }
        }

        return [
            'code' => $code,
            'message' => implode(' | ', $lines),
        ];
    }

    /**
     * @param array<int, int> $expectedCodes
     */
    private function isExpected(int $code, array $expectedCodes): bool
    {
        return in_array($code, $expectedCodes, true);
    }

    private function dotStuff(string $payload): string
    {
        $payload = str_replace(["\r\n.", "\n."], ["\r\n..", "\n.."], $payload);
        if ($payload !== '' && $payload[0] === '.') {
            $payload = '.' . $payload;
        }
        return $payload;
    }

    private function formatAddress(string $email, string $name): string
    {
        $email = trim($email);
        $name = trim($name);
        if ($name === '') {
            return $email;
        }
        return $name . ' <' . $email . '>';
    }
}
