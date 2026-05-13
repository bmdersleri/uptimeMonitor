<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

function assert_true_process_launcher(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$tempRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'uptime_launcher_' . bin2hex(random_bytes(4));
$cronDir = $tempRoot . DIRECTORY_SEPARATOR . 'cron';
if (!mkdir($cronDir, 0775, true) && !is_dir($cronDir)) {
    throw new RuntimeException('Temp cron dir olusturulamadi');
}

$scriptPath = $cronDir . DIRECTORY_SEPARATOR . 'run_manual_link_scan.php';
file_put_contents($scriptPath, "<?php\n");

$linuxCommand = '';
$linuxLauncher = new LinkScanProcessLauncher(
    function (string $command) use (&$linuxCommand): bool {
        $linuxCommand = $command;
        return true;
    },
    'Linux',
    '/usr/bin/php',
    $tempRoot
);

assert_true_process_launcher($linuxLauncher->launchManualScan(6, 2) === true, 'Linux detached launch should report success');
assert_true_process_launcher(strpos($linuxCommand, 'nohup ') === 0, 'Linux command should use nohup');
assert_true_process_launcher(strpos($linuxCommand, 'UPTIME_MANUAL_SCAN=1') !== false, 'Linux command should include worker guard env');
assert_true_process_launcher(strpos($linuxCommand, 'UPTIME_MONITOR_ID=') !== false, 'Linux command should include monitor env');
assert_true_process_launcher(strpos($linuxCommand, 'UPTIME_MAX_DEPTH=') !== false, 'Linux command should include depth env');
assert_true_process_launcher(strpos($linuxCommand, '/usr/bin/php') !== false, 'Linux command should include PHP binary');
assert_true_process_launcher(strpos($linuxCommand, 'run_manual_link_scan.php') !== false, 'Linux command should include worker script');
assert_true_process_launcher(strpos($linuxCommand, '"6"') !== false, 'Linux command should include monitor id');
assert_true_process_launcher(strpos($linuxCommand, '"2"') !== false, 'Linux command should include depth override');

$windowsCommand = '';
$windowsLauncher = new LinkScanProcessLauncher(
    function (string $command) use (&$windowsCommand): bool {
        $windowsCommand = $command;
        return true;
    },
    'Windows',
    'php.exe',
    $tempRoot
);

assert_true_process_launcher($windowsLauncher->launchManualScan(9, null) === true, 'Windows detached launch should report success');
assert_true_process_launcher(strpos($windowsCommand, 'cmd /c start /B "" ') === 0, 'Windows command should use start /B');
assert_true_process_launcher(strpos($windowsCommand, 'UPTIME_MANUAL_SCAN=1') !== false, 'Windows command should include worker guard env');
assert_true_process_launcher(strpos($windowsCommand, 'UPTIME_MONITOR_ID=9') !== false, 'Windows command should include monitor env');
assert_true_process_launcher(strpos($windowsCommand, 'php.exe') !== false, 'Windows command should include PHP binary');
assert_true_process_launcher(strpos($windowsCommand, '"9"') !== false || strpos($windowsCommand, "'9'") !== false, 'Windows command should include monitor id');

unlink($scriptPath);
@rmdir($cronDir);
@rmdir($tempRoot);

echo "LinkScanProcessLauncherTest OK\n";
