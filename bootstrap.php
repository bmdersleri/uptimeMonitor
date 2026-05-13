<?php

declare(strict_types=1);

require_once __DIR__ . '/app/Config.php';
require_once __DIR__ . '/app/Database.php';
require_once __DIR__ . '/app/Repositories/UserRepository.php';
require_once __DIR__ . '/app/Repositories/MonitorRepository.php';
require_once __DIR__ . '/app/Repositories/IncidentRepository.php';
require_once __DIR__ . '/app/Repositories/BrokenLinkRepository.php';
require_once __DIR__ . '/app/Repositories/BrokenLinkIgnoreRuleRepository.php';
require_once __DIR__ . '/app/Repositories/LinkScanRepository.php';
require_once __DIR__ . '/app/Repositories/NotificationRetryRepository.php';
require_once __DIR__ . '/app/Services/AuthService.php';
require_once __DIR__ . '/app/Services/LinkScanner.php';
require_once __DIR__ . '/app/Services/LinkScanProcessLauncher.php';
require_once __DIR__ . '/app/Services/LinkScanRunner.php';
require_once __DIR__ . '/app/Services/LinkScanResetter.php';
require_once __DIR__ . '/app/Services/Notifier.php';
require_once __DIR__ . '/app/Services/UptimeChecker.php';

date_default_timezone_set((string) config('APP_TIMEZONE', 'UTC'));

if ((string) config('APP_DEBUG', 'false') === 'true') {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function app_base_path(): string
{
    $base = trim((string) config('APP_BASE_PATH', ''));
    if ($base === '' || $base === '/') {
        return '';
    }

    if ($base[0] !== '/') {
        $base = '/' . $base;
    }

    return rtrim($base, '/');
}

/**
 * @param array<string, scalar|null> $query
 */
function url_for(string $path = '/', array $query = []): string
{
    if ($path === '') {
        $path = '/';
    }
    if ($path[0] !== '/') {
        $path = '/' . $path;
    }

    $url = app_base_path() . $path;
    if ($url === '') {
        $url = '/';
    }

    if ($query !== []) {
        $url .= '?' . http_build_query($query);
    }

    return $url;
}

/**
 * @param array<string, scalar|null> $query
 */
function redirect_to(string $path, array $query = []): void
{
    header('Location: ' . url_for($path, $query));
    exit;
}

if (PHP_SAPI !== 'cli') {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function auth(): AuthService
{
    static $instance = null;
    if ($instance instanceof AuthService) {
        return $instance;
    }

    $pdo = Database::connection();
    $instance = new AuthService(new UserRepository($pdo));
    return $instance;
}

function require_auth(?string $redirectPath = null): void
{
    if ($redirectPath === null) {
        $redirectPath = url_for('/login.php');
    }
    auth()->requireAuth($redirectPath);
}

/**
 * @return array<string, mixed>|null
 */
function current_user(): ?array
{
    return auth()->user();
}
