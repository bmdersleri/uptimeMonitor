<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../app/MonitorLifecycleActions.php';
require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_to('/index.php');
}

$monitorId = (int) ($_POST['monitor_id'] ?? 0);
$action = (string) ($_POST['action'] ?? '');
$returnView = (string) ($_POST['return_view'] ?? '');

if ($monitorId < 1) {
    redirect_to('/index.php');
}

$pdo = Database::connection();
$repo = new MonitorRepository($pdo);

switch ($action) {
    case 'activate':
        monitor_lifecycle_set_active($pdo, $repo, $monitorId, true);
        redirect_to('/index.php');
        break;

    case 'deactivate':
        monitor_lifecycle_set_active($pdo, $repo, $monitorId, false);
        redirect_to('/index.php');
        break;

    case 'archive':
        monitor_lifecycle_archive($pdo, $repo, $monitorId);
        redirect_to('/index.php');
        break;

    case 'restore':
        monitor_lifecycle_restore($pdo, $repo, $monitorId);
        redirect_to('/index.php');
        break;

    case 'delete':
        monitor_lifecycle_delete($pdo, $repo, $monitorId);
        if ($returnView === 'archived') {
            redirect_to('/index.php', ['view' => 'archived']);
        }
        redirect_to('/index.php');
        break;

    default:
        redirect_to('/index.php');
}
