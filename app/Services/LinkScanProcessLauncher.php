<?php

declare(strict_types=1);

final class LinkScanProcessLauncher
{
    /** @var callable|null */
    private $executor;

    /** @var string */
    private $osFamily;

    /** @var string */
    private $phpBinary;

    /** @var string */
    private $projectRoot;

    /** @var string */
    private $launchLogPath;

    public function __construct(callable $executor = null, ?string $osFamily = null, ?string $phpBinary = null, ?string $projectRoot = null, ?string $launchLogPath = null)
    {
        $this->executor = $executor;
        $this->osFamily = $osFamily ?? PHP_OS_FAMILY;
        $this->projectRoot = $projectRoot ?? dirname(__DIR__, 2);
        $this->phpBinary = $phpBinary ?? $this->detectPhpBinary();
        $this->launchLogPath = $launchLogPath ?? $this->projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'manual_link_scan_launch.log';
    }

    public function launchManualScan(int $monitorId, ?int $maxDepth = null): bool
    {
        if ($monitorId < 1) {
            return false;
        }

        $script = $this->projectRoot . DIRECTORY_SEPARATOR . 'cron' . DIRECTORY_SEPARATOR . 'run_manual_link_scan.php';
        if (!is_file($script)) {
            return false;
        }

        $parts = [
            escapeshellarg($this->phpBinary),
            escapeshellarg($script),
            escapeshellarg((string) $monitorId),
        ];
        if ($maxDepth !== null && $maxDepth > 0) {
            $parts[] = escapeshellarg((string) $maxDepth);
        }

        $baseCommand = implode(' ', $parts);
        $logFile = escapeshellarg($this->launchLogPath);
        if (strcasecmp($this->osFamily, 'Windows') === 0) {
            $command = 'cmd /c start /B "" ' . $baseCommand . ' >> ' . $logFile . ' 2>&1';
        } else {
            $command = 'nohup ' . $baseCommand . ' >> ' . $logFile . ' 2>&1 &';
        }

        return $this->runCommand($command);
    }

    private function detectPhpBinary(): string
    {
        $configured = trim((string) config('PHP_CLI_BINARY', ''));
        if ($configured !== '') {
            return $configured;
        }

        if (strcasecmp($this->osFamily, 'Windows') !== 0) {
            foreach (['/usr/bin/php', '/usr/local/bin/php', 'php'] as $candidate) {
                if ($candidate === 'php' || is_file($candidate)) {
                    return $candidate;
                }
            }
        }

        if (defined('PHP_BINARY') && PHP_BINARY !== '') {
            return PHP_BINARY;
        }

        return 'php';
    }

    private function runCommand(string $command): bool
    {
        if ($this->executor !== null) {
            return (bool) call_user_func($this->executor, $command);
        }

        if (!function_exists('exec')) {
            return false;
        }

        $output = [];
        $exitCode = 1;

        try {
            @exec($command, $output, $exitCode);
        } catch (Throwable $e) {
            return false;
        }

        return $exitCode === 0;
    }
}
