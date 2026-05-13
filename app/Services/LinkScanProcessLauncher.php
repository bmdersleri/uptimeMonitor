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

    public function __construct(callable $executor = null, ?string $osFamily = null, ?string $phpBinary = null, ?string $projectRoot = null)
    {
        $this->executor = $executor;
        $this->osFamily = $osFamily ?? PHP_OS_FAMILY;
        $this->phpBinary = $phpBinary ?? (defined('PHP_BINARY') && PHP_BINARY !== '' ? PHP_BINARY : 'php');
        $this->projectRoot = $projectRoot ?? dirname(__DIR__, 2);
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
        if (strcasecmp($this->osFamily, 'Windows') === 0) {
            $command = 'cmd /c start /B "" ' . $baseCommand . ' > NUL 2>&1';
        } else {
            $command = 'nohup ' . $baseCommand . ' >/dev/null 2>&1 &';
        }

        return $this->runCommand($command);
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
